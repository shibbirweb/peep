<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Smtp;

use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Smtp\CaughtMessage;
use ShibbirWeb\PeepServer\Smtp\SmtpSession;

final class SmtpSessionTest extends TestCase
{
    public function testAFullConversationCatchesTheMessage(): void
    {
        $session = new SmtpSession();
        $message = null;
        $replies = [];

        foreach ([
            'EHLO app.test',
            'AUTH PLAIN ' . base64_encode("\0shop\0anything"),
            'MAIL FROM:<shop@app.test> SIZE=120',
            'RCPT TO:<ada@example.test>',
            'RCPT TO:<Hidden@Example.test>',
            'DATA',
            'Subject: Hi',
            '',
            '..leading dot',
            'body',
            '.',
            'QUIT',
        ] as $line) {
            $reply = $session->receive($line);
            $replies[] = substr((string) $reply->text, 0, 3);
            $message = $reply->message ?? $message;
        }

        $this->assertSame(['250', '235', '250', '250', '250', '354', '', '', '', '', '250', '221'], $replies);
        $this->assertInstanceOf(CaughtMessage::class, $message);
        $this->assertSame("Subject: Hi\r\n\r\n.leading dot\r\nbody\r\n", $message->raw, 'Dot-stuffing is undone.');
        $this->assertSame(['ada@example.test', 'hidden@example.test'], $message->recipients);
        $this->assertSame('shop', $message->username);
    }

    public function testAuthLoginInTwoSteps(): void
    {
        $session = new SmtpSession();

        $this->assertStringStartsWith('334', (string) $session->receive('AUTH LOGIN')->text);
        $this->assertStringStartsWith('334', (string) $session->receive(base64_encode('blog'))->text);
        $this->assertStringStartsWith('235', (string) $session->receive(base64_encode('secret'))->text);
    }

    public function testClientsOnOtherMachinesNeedTheTokenAsPassword(): void
    {
        $session = new SmtpSession(requiredPassword: 'the-token-0123456789');

        $this->assertStringStartsWith('530', (string) $session->receive('MAIL FROM:<a@b.c>')->text);
        $this->assertStringStartsWith('535', (string) $session->receive('AUTH PLAIN ' . base64_encode("\0shop\0wrong"))->text);
        $this->assertStringStartsWith('235', (string) $session->receive('AUTH PLAIN ' . base64_encode("\0shop\0the-token-0123456789"))->text);
        $this->assertStringStartsWith('250', (string) $session->receive('MAIL FROM:<a@b.c>')->text);
    }

    public function testCommandsOutOfOrderAreRefused(): void
    {
        $session = new SmtpSession();

        $this->assertStringStartsWith('503', (string) $session->receive('RCPT TO:<a@b.c>')->text);
        $this->assertStringStartsWith('503', (string) $session->receive('DATA')->text);
        $this->assertStringStartsWith('500', (string) $session->receive('HACK')->text);
        $this->assertStringStartsWith('454', (string) $session->receive('STARTTLS')->text);
    }

    public function testRsetStartsOver(): void
    {
        $session = new SmtpSession();
        $session->receive('MAIL FROM:<a@b.c>');
        $session->receive('RCPT TO:<x@y.z>');
        $session->receive('RSET');

        $this->assertStringStartsWith('503', (string) $session->receive('DATA')->text);
    }
}
