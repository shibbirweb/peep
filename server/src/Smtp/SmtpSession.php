<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Smtp;

/**
 * One SMTP conversation, line by line, without sockets (SmtpServer feeds it).
 *
 * Any login is accepted and its username becomes the project, unless the client is on
 * another machine: then the password must be the viewer's token. No STARTTLS.
 */
final class SmtpSession
{
    public const int MAX_MESSAGE_BYTES = 25 * 1024 * 1024;

    private const int MAX_RECIPIENTS = 1000;

    private string $state = 'command';

    private ?string $from = null;

    /**
     * @var list<string>
     */
    private array $recipients = [];

    private string $data = '';

    private bool $dataTooLarge = false;

    private ?string $username = null;

    private bool $authenticated = false;

    /**
     * @param string|null $requiredPassword The token, for clients on other machines; null for local ones.
     */
    public function __construct(
        private readonly string $hostname = 'peep',
        private readonly ?string $requiredPassword = null,
    ) {}

    public function greeting(): string
    {
        return "220 {$this->hostname} Peep ESMTP ready\r\n";
    }

    /**
     * @param string $line One line without its line ending.
     */
    public function receive(string $line): SmtpReply
    {
        return match ($this->state) {
            'data' => $this->dataLine($line),
            'auth_plain' => $this->authPlain($line),
            'auth_login_user' => $this->authLoginUser($line),
            'auth_login_password' => $this->authLoginPassword($line),
            default => $this->command($line),
        };
    }

    private function command(string $line): SmtpReply
    {
        $verb = strtoupper(strtok($line, ' ') ?: '');
        $argument = trim(substr($line, strlen($verb)));

        return match ($verb) {
            'EHLO' => SmtpReply::send(
                "250-{$this->hostname} Hello\r\n"
                . "250-AUTH PLAIN LOGIN\r\n"
                . "250-AUTH=PLAIN LOGIN\r\n"
                . '250-SIZE ' . self::MAX_MESSAGE_BYTES . "\r\n"
                . "250-8BITMIME\r\n"
                . "250 SMTPUTF8\r\n",
            ),
            'HELO' => SmtpReply::send("250 {$this->hostname}\r\n"),
            'AUTH' => $this->auth($argument),
            'MAIL' => $this->mailFrom($argument),
            'RCPT' => $this->rcptTo($argument),
            'DATA' => $this->startData(),
            'RSET' => $this->reset("250 OK\r\n"),
            'NOOP' => SmtpReply::send("250 OK\r\n"),
            'QUIT' => SmtpReply::close("221 Bye\r\n"),
            'STARTTLS' => SmtpReply::send("454 TLS is not available; Peep only listens on your machine\r\n"),
            'VRFY' => SmtpReply::send("252 Cannot verify, but will accept\r\n"),
            default => SmtpReply::send("500 Unknown command\r\n"),
        };
    }

    private function auth(string $argument): SmtpReply
    {
        [$mechanism, $initial] = array_pad(explode(' ', $argument, 2), 2, null);

        return match (strtoupper((string) $mechanism)) {
            'PLAIN' => $initial !== null && $initial !== '' ? $this->authPlain($initial) : $this->expect('auth_plain', "334 \r\n"),
            'LOGIN' => $initial !== null && $initial !== '' ? $this->authLoginUser($initial) : $this->expect('auth_login_user', "334 VXNlcm5hbWU6\r\n"),
            default => SmtpReply::send("504 Use AUTH PLAIN or LOGIN\r\n"),
        };
    }

    private function authPlain(string $line): SmtpReply
    {
        $this->state = 'command';
        $parts = explode("\0", (string) base64_decode($line, true));

        // authorization-id NUL username NUL password
        return $this->login($parts[1] ?? '', $parts[2] ?? '');
    }

    private function authLoginUser(string $line): SmtpReply
    {
        $this->username = (string) base64_decode($line, true);

        return $this->expect('auth_login_password', "334 UGFzc3dvcmQ6\r\n");
    }

    private function authLoginPassword(string $line): SmtpReply
    {
        $this->state = 'command';

        return $this->login((string) $this->username, (string) base64_decode($line, true));
    }

    private function login(string $username, string $password): SmtpReply
    {
        if ($this->requiredPassword !== null && ! hash_equals($this->requiredPassword, $password)) {
            return SmtpReply::send("535 Use the token from the Peep settings as the password\r\n");
        }

        $this->username = trim($username) !== '' ? trim($username) : null;
        $this->authenticated = true;

        return SmtpReply::send("235 Authenticated\r\n");
    }

    private function mailFrom(string $argument): SmtpReply
    {
        if ($this->requiredPassword !== null && ! $this->authenticated) {
            return SmtpReply::send("530 Log in first: clients on other machines need the token\r\n");
        }

        if (preg_match('/^FROM:\s*<([^>]*)>/i', $argument, $matches) !== 1) {
            return SmtpReply::send("501 Use MAIL FROM:<address>\r\n");
        }

        $this->from = $matches[1];
        $this->recipients = [];

        return SmtpReply::send("250 OK\r\n");
    }

    private function rcptTo(string $argument): SmtpReply
    {
        if ($this->from === null) {
            return SmtpReply::send("503 MAIL FROM first\r\n");
        }

        if (preg_match('/^TO:\s*<([^>]+)>/i', $argument, $matches) !== 1) {
            return SmtpReply::send("501 Use RCPT TO:<address>\r\n");
        }

        if (count($this->recipients) >= self::MAX_RECIPIENTS) {
            return SmtpReply::send("452 Too many recipients\r\n");
        }

        $this->recipients[] = strtolower($matches[1]);

        return SmtpReply::send("250 OK\r\n");
    }

    private function startData(): SmtpReply
    {
        if ($this->recipients === []) {
            return SmtpReply::send("503 RCPT TO first\r\n");
        }

        $this->data = '';
        $this->dataTooLarge = false;

        return $this->expect('data', "354 End data with <CR><LF>.<CR><LF>\r\n");
    }

    private function dataLine(string $line): SmtpReply
    {
        if ($line === '.') {
            $this->state = 'command';

            if ($this->dataTooLarge) {
                return $this->reset('552 Message larger than ' . self::MAX_MESSAGE_BYTES . " bytes\r\n");
            }

            $message = new CaughtMessage($this->data, $this->recipients, $this->username);
            $this->from = null;
            $this->recipients = [];
            $this->data = '';

            return SmtpReply::send("250 OK: caught by Peep\r\n", $message);
        }

        if ($this->dataTooLarge) {
            return SmtpReply::none();
        }

        // Dot-stuffing: a line starting with "." was sent with one more.
        $this->data .= (str_starts_with($line, '.') ? substr($line, 1) : $line) . "\r\n";

        if (strlen($this->data) > self::MAX_MESSAGE_BYTES) {
            $this->dataTooLarge = true;
            $this->data = '';
        }

        return SmtpReply::none();
    }

    private function reset(string $reply): SmtpReply
    {
        $this->state = 'command';
        $this->from = null;
        $this->recipients = [];
        $this->data = '';

        return SmtpReply::send($reply);
    }

    private function expect(string $state, string $reply): SmtpReply
    {
        $this->state = $state;

        return SmtpReply::send($reply);
    }
}
