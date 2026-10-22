<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Webhook\Template\TemplateException;
use ShibbirWeb\PeepServer\Webhook\Template\TemplateRenderer;

final class TemplateTest extends TestCase
{
    private const array VARIABLES = [
        'event' => 'delivered',
        'now' => ['_' => '2026-10-22T10:00:00.000Z', 'unix' => 1792663200, 'unix_float' => 1792663200.5],
        'mail' => ['to' => 'ada@example.test', 'subject' => 'Tom & "Jerry"', 'headers' => ['message-id' => '<m@x>']],
        'sms' => ['segments' => 2],
        'vars' => ['secret' => 'key'],
    ];

    public function testJsonLeavesKeepTypesAndAreEscapedForYou(): void
    {
        $renderer = new TemplateRenderer(self::VARIABLES);

        $json = $renderer->json('{"time": "{{ now.unix_float }}", "parts": "{{ sms.segments }}", "subject": "Re: {{ mail.subject }}", "when": "{{ now }}", "list": ["{{ event }}", 1, true]}');

        $this->assertSame(
            ['time' => 1792663200.5, 'parts' => 2, 'subject' => 'Re: Tom & "Jerry"', 'when' => '2026-10-22T10:00:00.000Z', 'list' => ['delivered', 1, true]],
            json_decode($json, true),
        );
        $this->assertSame([], $renderer->warnings);
    }

    public function testFunctionsAndConcatenation(): void
    {
        $renderer = new TemplateRenderer(self::VARIABLES);

        $this->assertSame(hash_hmac('sha256', '1792663200delivered', 'key'), $renderer->text('{{ hmac_sha256(vars.secret, now.unix ~ event) }}'));
        $this->assertSame('DELIVERED-ada%40example.test', $renderer->text("{{ upper(event) ~ '-' ~ url_encode(mail.to) }}"));
        $this->assertSame(base64_encode('hi'), $renderer->text('{{ base64("hi") }}'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{22}$/', $renderer->text('{{ random_hex(22) }}'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $renderer->text('{{ uuid() }}'));
        $this->assertSame('2026-10-22', $renderer->text("{{ date('Y-m-d', now.unix) }}"));
        $this->assertSame('<m@x>', $renderer->text('{{ mail.headers.message-id }}'));
    }

    public function testXmlValuesAreEscapedAndFormFieldsRendered(): void
    {
        $renderer = new TemplateRenderer(self::VARIABLES);

        $this->assertSame('<s>Tom &amp; &quot;Jerry&quot;</s>', $renderer->text('<s>{{ mail.subject }}</s>', xml: true));
        $this->assertSame(['Status' => 'delivered', 'Parts' => '2'], $renderer->form('{"Status": "{{ event }}", "Parts": "{{ sms.segments }}"}'));
    }

    public function testUnknownNamesBecomeEmptyAndAreReported(): void
    {
        $renderer = new TemplateRenderer(self::VARIABLES);

        $this->assertSame('[]', $renderer->text('[{{ mail.nope }}{{ shell_exec("ls") }}]'));
        $this->assertSame(['Unknown variable: mail.nope', 'Unknown function: shell_exec()'], $renderer->warnings);
    }

    public function testBrokenTemplatesSayWhatIsWrong(): void
    {
        foreach (['{{ event ', '{{ "open }}', '{{ event event }}', '{{ upper(event }}'] as $template) {
            try {
                new TemplateRenderer(self::VARIABLES)->text($template);
                $this->fail('Expected an error for ' . $template);
            } catch (TemplateException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(TemplateException::class);
        new TemplateRenderer(self::VARIABLES)->json('{not json');
    }
}
