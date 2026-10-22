<?php

namespace ShibbirWeb\Peep\Tests\Unit\Api;

use DateTimeImmutable;
use DateTimeZone;
use ShibbirWeb\Peep\Tests\Fixture\VisibilityFixture;

final class FormatsTest extends ApiTestCase
{
    public function testSendingAgainReplacesTheValues(): void
    {
        $this->peep()->send('first')->send('second');

        $payloads = $this->payloads();
        $this->assertSame(['log', 'replace'], $this->types());
        $this->assertSame('second', $payloads[1]['content']['values'][0]['value']);
    }

    public function testPassReturnsTheValue(): void
    {
        $this->assertSame(42, $this->peep()->pass(42));
        $this->assertSame(42, $this->onlyPayload()['content']['values'][0]['value']);
    }

    public function testToJsonEncodesValues(): void
    {
        $this->peep()->toJson(['a' => 1.0, 'b' => '/x']);

        $this->assertSame("{\n    \"a\": 1.0,\n    \"b\": \"/x\"\n}", $this->onlyPayload()['content']['json']);
    }

    public function testXmlLinkSeparatorAndNotify(): void
    {
        $this->peep()->xml('<a><b/></a>')->link('https://example.com', 'Example')->url('https://example.org')->separator()->notify('Done');

        $payloads = $this->payloads();
        $this->assertSame(['xml', 'link', 'link', 'separator', 'notify'], $this->types());
        $this->assertSame(['xml' => '<a><b/></a>'], $payloads[0]['content']);
        $this->assertSame(['url' => 'https://example.com', 'label' => 'Example'], $payloads[1]['content']);
        $this->assertSame(['url' => 'https://example.org', 'label' => null], $payloads[2]['content']);
        $this->assertSame(['message' => 'Done'], $payloads[4]['content']);
    }

    public function testImageSources(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'peep-image-') . '.gif';
        $gif = base64_decode('R0lGODlhAQABAAAAACw=', true);
        file_put_contents($file, (string) $gif);

        try {
            $this->peep()
                ->image('https://example.com/a.png')
                ->image($file)
                ->image('data:image/png;base64,AAAA')
                ->image('R0lGODlhAQABAAAAACw=')
                ->image('not an image!');
        } finally {
            unlink($file);
        }

        $payloads = $this->payloads();
        $this->assertSame('https://example.com/a.png', $payloads[0]['content']['src']);
        $this->assertSame('data:image/gif;base64,R0lGODlhAQABAAAAACw=', $payloads[1]['content']['src']);
        $this->assertSame('data:image/png;base64,AAAA', $payloads[2]['content']['src']);
        $this->assertSame('data:image/png;base64,R0lGODlhAQABAAAAACw=', $payloads[3]['content']['src']);
        $this->assertSame('', $payloads[4]['content']['src']);
        $this->assertIsString($payloads[4]['content']['error']);
    }

    public function testFileContents(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'peep-file-');
        file_put_contents($file, "line 1\nline 2\n");

        try {
            $this->peep()->file($file)->file($file . '.missing');
        } finally {
            unlink($file);
        }

        $payloads = $this->payloads();
        $this->assertSame("line 1\nline 2\n", $payloads[0]['content']['contents']);
        $this->assertSame(14, $payloads[0]['content']['size']);
        $this->assertFalse($payloads[0]['content']['binary']);
        $this->assertNull($payloads[1]['content']['contents']);
        $this->assertIsString($payloads[1]['content']['error']);
    }

    public function testDatesAreShownWithASummary(): void
    {
        $this->peep()->date(new DateTimeImmutable('2026-10-22 16:30:00.5', new DateTimeZone('UTC')));

        $node = $this->onlyPayload()['content']['values'][0];
        $this->assertSame('2026-10-22 16:30:00.500000 +00:00', $node['summary']);
        $this->assertSame('timezone', $node['children'][1]['key']);
    }

    public function testRawSkipsCasters(): void
    {
        $this->peep()->raw(new DateTimeImmutable('2026-10-22'));

        $this->assertArrayNotHasKey('summary', $this->onlyPayload()['content']['values'][0]);
    }

    public function testClassName(): void
    {
        $this->peep()->className(new VisibilityFixture());

        $this->assertSame(VisibilityFixture::class, $this->onlyPayload()['content']['values'][0]['value']);
    }

    public function testInvadeShowsAndReturnsPrivateValues(): void
    {
        $object = new VisibilityFixture();

        // Same as ->invade($object)->hidden and ->hidden(), spelled out for static analysis.
        $this->assertSame('private value', $this->peep()->invade($object)->__get('hidden'));
        $this->assertSame('private value', $this->peep()->invade($object)->__call('hidden', []));

        $payloads = $this->payloads();
        $this->assertSame('private value', $payloads[0]['content']['values'][0]['value']);
        $this->assertSame(VisibilityFixture::class . '::$hidden', $payloads[1]['content']['label']);
        $this->assertSame(VisibilityFixture::class . '::hidden()', $payloads[3]['content']['label']);
        $this->assertSame(__FILE__, $payloads[0]['origin']['file']);
    }

    public function testPhpinfo(): void
    {
        $this->peep()->phpinfo('memory_limit', 'no.such.setting');
        $this->peep()->phpinfo();

        $payloads = $this->payloads();
        $this->assertSame('table', $payloads[0]['type']);
        $this->assertSame('memory_limit', $payloads[0]['content']['rows']['children'][0]['key']);
        $this->assertSame('(unknown setting)', $payloads[0]['content']['rows']['children'][1]['node']['value']);
        $this->assertSame(PHP_VERSION, $payloads[1]['content']['rows']['children'][0]['node']['value']);
    }

    public function testSendCustom(): void
    {
        $this->peep()->sendCustom('value', 'My label');

        $this->assertSame(['log', 'label'], $this->types());
    }

    public function testStylingUpdates(): void
    {
        $this->peep()->send('x')->hide()->expand()->expand(2, 'user', 'user.roles')->expandAll();

        $payloads = $this->payloads();
        $this->assertSame(['log', 'hide', 'expand', 'expand', 'expand'], $this->types());
        $this->assertSame(['level' => 3, 'keys' => [], 'all' => false], $payloads[2]['content']);
        $this->assertSame(['level' => 2, 'keys' => ['user', 'user.roles'], 'all' => false], $payloads[3]['content']);
        $this->assertSame(['level' => null, 'keys' => [], 'all' => true], $payloads[4]['content']);
        $this->assertNull($payloads[1]['origin']);
    }

    public function testScreenAndAppCommands(): void
    {
        $this->peep()->newScreen('Checkout')->clearScreen()->clearAll()->showApp()->hideApp();

        $this->assertSame(['new_screen', 'clear_screen', 'clear_all', 'show_app', 'hide_app'], $this->types());
        $this->assertSame(['title' => 'Checkout'], $this->payloads()[0]['content']);
    }

    public function testRemove(): void
    {
        $this->peep()->send('x')->removeIf(false)->removeWhen(function () {
            return true;
        });

        $this->assertSame(['log', 'remove'], $this->types());
    }
}
