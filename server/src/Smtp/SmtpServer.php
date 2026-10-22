<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Smtp;

use Amp\Socket\InternetAddress;
use Amp\Socket\ServerSocket;
use Amp\Socket\Socket;
use Closure;
use Psr\Log\LoggerInterface;
use ShibbirWeb\PeepServer\Http\Guard\TokenGuard;
use Throwable;

use function Amp\async;
use function Amp\Socket\listen;

/**
 * Listens for SMTP and hands each finished message to a callback.
 */
final class SmtpServer
{
    private const int MAX_LINE_BYTES = 1024 * 1024;

    private const float IDLE_TIMEOUT_SECONDS = 300;

    private ?ServerSocket $socket = null;

    /**
     * @var array<int, Socket>
     */
    private array $clients = [];

    /**
     * @param Closure(CaughtMessage): void $onMessage
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly Closure $onMessage,
        private readonly LoggerInterface $logger,
        private readonly ?string $token = null,
    ) {}

    public function start(): void
    {
        $this->socket = listen($this->host . ':' . $this->port);

        async(function (): void {
            while ($this->socket !== null && ($client = $this->socket->accept()) !== null) {
                async(fn() => $this->serve($client));
            }
        })->ignore();
    }

    public function stop(): void
    {
        $this->socket?->close();
        $this->socket = null;

        foreach ($this->clients as $client) {
            $client->close();
        }

        $this->clients = [];
    }

    public function address(): string
    {
        return $this->host . ':' . $this->port;
    }

    private function serve(Socket $client): void
    {
        $this->clients[spl_object_id($client)] = $client;
        $remote = $client->getRemoteAddress();
        $local = ! $remote instanceof InternetAddress || TokenGuard::isLoopback($remote->getAddress());

        try {
            if (! $local && $this->token === null) {
                $client->write("554 Set a token in the Peep settings to accept mail from other machines\r\n");

                return;
            }

            $session = new SmtpSession(requiredPassword: $local ? null : $this->token);
            $client->write($session->greeting());
            $buffer = '';

            while (($chunk = $client->read(new \Amp\TimeoutCancellation(self::IDLE_TIMEOUT_SECONDS))) !== null) {
                $buffer .= $chunk;

                while (($end = strpos($buffer, "\n")) !== false) {
                    $line = rtrim(substr($buffer, 0, $end), "\r");
                    $buffer = substr($buffer, $end + 1);
                    $reply = $session->receive($line);

                    if ($reply->message !== null) {
                        ($this->onMessage)($reply->message);
                    }

                    if ($reply->text !== null) {
                        $client->write($reply->text);
                    }

                    if ($reply->close) {
                        return;
                    }
                }

                if (strlen($buffer) > self::MAX_LINE_BYTES) {
                    $client->write("500 Line too long\r\n");

                    return;
                }
            }
        } catch (Throwable $exception) {
            $this->logger->debug('SMTP connection ended: ' . $exception->getMessage());
        } finally {
            unset($this->clients[spl_object_id($client)]);
            $client->close();
        }
    }
}
