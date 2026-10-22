<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Mail;

use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Mail\MailParser;
use ShibbirWeb\PeepServer\Mail\MailStore;
use ShibbirWeb\PeepServer\Mail\MimeBuilder;

final class MailParserTest extends TestCase
{
    public function testABuiltMessageParsesBackWithEverything(): void
    {
        $raw = new MimeBuilder()->build(
            from: 'Shop <shop@app.test>',
            to: ['Ada <ada@example.test>'],
            subject: 'Your order ü',
            text: 'Plain body',
            html: '<p>HTML body</p>',
            cc: ['cc@example.test'],
            headers: ['X-Peep-Project' => 'shop', 'X-Peep-Request-Id' => 'req-1', "Bad\r\nHeader" => 'x'],
            attachments: [['filename' => 'invoice.pdf', 'content_type' => 'application/pdf', 'content' => '%PDF-1.4 fake']],
            messageId: 'abc@app.test',
        );

        $parser = new MailParser();
        $mail = $parser->parse($raw, null, ['ada@example.test', 'secret@example.test'], 'http', 10.0);

        $this->assertSame('shop', $mail->project, 'From the X-Peep-Project header.');
        $this->assertSame('req-1', $mail->requestId);
        $this->assertSame('abc@app.test', $mail->messageId);
        $this->assertSame('Shop <shop@app.test>', $mail->from);
        $this->assertSame(['Ada <ada@example.test>'], $mail->to);
        $this->assertSame(['cc@example.test'], $mail->cc);
        $this->assertSame(['secret@example.test'], $mail->bcc, 'RCPT addresses not in To or Cc are Bcc.');
        $this->assertSame('Your order ü', $mail->subject);
        $this->assertSame('Plain body', $mail->text);
        $this->assertSame('<p>HTML body</p>', $mail->html);
        $this->assertSame('invoice.pdf', $mail->attachments[0]['filename']);
        $this->assertSame(1, $mail->summary()['attachment_count']);
        $this->assertSame('%PDF-1.4 fake', $parser->attachment($mail, 0)['content'] ?? null);
        $this->assertStringNotContainsString('Bad', $raw, 'Invalid header names are dropped.');
    }

    public function testTheLoginProjectWinsOverTheHeader(): void
    {
        $raw = new MimeBuilder()->build('a@b.c', ['d@e.f'], 'S', 'T', null, headers: ['X-Peep-Project' => 'shop']);

        $this->assertSame('blog', new MailParser()->parse($raw, 'blog', [], 'smtp', 1.0)->project);
        $this->assertSame('default', new MailParser()->parse(new MimeBuilder()->build('a@b.c', ['d@e.f'], 'S', 'T', null), null, [], 'smtp', 1.0)->project);
    }

    public function testTheStoreDropsTheOldestAndTracksReadState(): void
    {
        $store = new MailStore(maxMails: 2);
        $parser = new MailParser();
        $mails = [];

        foreach (['one', 'two', 'three'] as $subject) {
            $mails[] = $mail = $parser->parse(new MimeBuilder()->build('a@b.c', ['d@e.f'], $subject, 'T', null), null, [], 'smtp', 1.0);
            $store->add($mail);
        }

        $this->assertSame(['three', 'two'], array_map(static fn($mail) => $mail->subject, $store->all()));
        $this->assertSame('mail.updated', $store->markRead($mails[2]->id)[0]->name ?? null);
        $this->assertSame([], $store->markRead($mails[2]->id), 'Already read: no event.');
        $this->assertSame('mail.removed', $store->delete($mails[1]->id)[0]->name ?? null);
        $store->clear();
        $this->assertSame([], $store->all());
    }
}
