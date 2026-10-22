<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Webhook\InvalidRuleException;
use ShibbirWeb\PeepServer\Webhook\Presets;
use ShibbirWeb\PeepServer\Webhook\Rule;
use ShibbirWeb\PeepServer\Webhook\Signer;
use ShibbirWeb\PeepServer\Webhook\WebhookRunner;

final class RuleAndSignerTest extends TestCase
{
    public function testTwilioSignatureMatchesTwiliosOwnExample(): void
    {
        // From Twilio's "Validating requests are coming from Twilio" documentation.
        $this->assertSame('0/KCTR6DLpKmkAf8muzZqo1nDgQ=', Signer::twilio('12345', 'https://mycompany.com/myapp.php?foo=1&bar=2', [
            'CallSid' => 'CA1234567890ABCDE',
            'Caller' => '+12349013030',
            'Digits' => '1234',
            'From' => '+12349013030',
            'To' => '+18005551212',
        ]));
    }

    public function testMailgunSignatureIsWhatMailgunsVerificationExpects(): void
    {
        $signature = Signer::mailgun('key-local', 1792663200, 'abc');

        $this->assertSame(['timestamp' => '1792663200', 'token' => 'abc', 'signature' => hash_hmac('sha256', '1792663200abc', 'key-local')], $signature);
        $this->assertSame(50, strlen(Signer::mailgun('key')['token']));
    }

    public function testEveryPresetIsAValidRule(): void
    {
        foreach (Presets::all() as $preset) {
            $rule = Rule::fromArray($preset['rule']);
            $this->assertSame($preset['rule']['name'], $rule->name);
            $this->assertNotNull(json_decode($rule->body, true), $preset['id'] . ' body is JSON');
        }
    }

    public function testInvalidRulesNameTheField(): void
    {
        $valid = Presets::all()[3]['rule'];

        foreach ([
            'url' => ['url' => 'file:///etc/passwd'],
            'trigger' => ['trigger' => 'always'],
            'method' => ['method' => 'TRACE'],
            'headers' => ['headers' => ['Host' => 'evil.test']],
            'steps' => ['steps' => [['event' => 'x', 'delay_ms' => 999999999]]],
            'signature' => ['signature' => ['type' => 'custom', 'header' => '']],
        ] as $field => $change) {
            try {
                Rule::fromArray([...$valid, ...$change]);
                $this->fail('Expected an error for ' . $field);
            } catch (InvalidRuleException $exception) {
                $this->assertSame($field, $exception->field);
            }
        }
    }

    public function testRulesApplyByTriggerProjectAndRecipient(): void
    {
        $rule = Rule::fromArray([...Presets::all()[0]['rule'], 'project' => 'shop', 'filter' => ['to_contains' => '@example.test']]);

        $this->assertTrue($rule->appliesTo('mail.received', 'shop', 'Ada <ada@EXAMPLE.test>'));
        $this->assertFalse($rule->appliesTo('mail.received', 'blog', 'ada@example.test'));
        $this->assertFalse($rule->appliesTo('mail.received', 'shop', 'ada@other.test'));
        $this->assertFalse($rule->appliesTo('sms.received', 'shop', 'ada@example.test'));
    }

    public function testStatusCallbacksFromCaughtDataOnlyGoToLocalHosts(): void
    {
        foreach (['http://localhost/cb', 'http://127.0.0.1:8000/cb', 'https://shop.test/twilio', 'http://api.localhost/x'] as $url) {
            $this->assertTrue(WebhookRunner::isAllowedCallbackHost($url), $url);
        }

        foreach (['https://example.com/cb', 'http://169.254.169.254/latest', 'http://shop.test.evil.com/'] as $url) {
            $this->assertFalse(WebhookRunner::isAllowedCallbackHost($url), $url);
        }
    }
}
