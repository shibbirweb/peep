<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Sms;

use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Sms\Sms;
use ShibbirWeb\PeepServer\Sms\SmsSegments;
use ShibbirWeb\PeepServer\Sms\SmsStore;

final class SmsSegmentsTest extends TestCase
{
    public function testGsm7Limits(): void
    {
        $this->assertSame(['encoding' => 'GSM-7', 'length' => 160, 'segments' => 1, 'per_segment' => 160], SmsSegments::of(str_repeat('a', 160)));
        $this->assertSame(2, SmsSegments::of(str_repeat('a', 161))['segments']);
        $this->assertSame(3, SmsSegments::of(str_repeat('a', 307))['segments']);
        $this->assertSame(10, SmsSegments::of('Price: 5€')['length'], 'Extended characters count twice.');
        $this->assertSame('GSM-7', SmsSegments::of('Grüße àéü')['encoding']);
    }

    public function testOneCharacterOutsideGsm7MakesTheTextUcs2(): void
    {
        $this->assertSame(['encoding' => 'UCS-2', 'length' => 70, 'segments' => 1, 'per_segment' => 70], SmsSegments::of(str_repeat('ж', 70)));
        $this->assertSame(2, SmsSegments::of(str_repeat('ж', 71))['segments']);
        $this->assertSame(5, SmsSegments::of('Hi 😀')['length'], 'Emoji take two UTF-16 units.');
    }

    public function testThreadsAreMarkedReadPerNumber(): void
    {
        $store = new SmsStore();
        $store->add(Sms::create('shop', '+15550001', '+1555999', 'one', 'http', 1.0));
        $store->add(Sms::create('shop', '+15550001', '+1555999', 'two', 'http', 2.0));
        $store->add(Sms::create('shop', '+15550002', '+1555999', 'other', 'http', 3.0));

        $this->assertCount(2, $store->markThreadRead('shop', '+15550001'));
        $this->assertSame([false, true, true], array_map(static fn(Sms $sms): bool => $sms->read, $store->all()));
        $this->assertMatchesRegularExpression('/^SM[0-9a-f]{32}$/', $store->all()[0]->sid);
    }
}
