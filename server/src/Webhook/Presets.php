<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Webhook;

/**
 * Starting points for common provider webhooks (WH-04). The user edits them freely.
 */
final class Presets
{
    private function __construct() {}

    /**
     * @return list<array{id: string, name: string, description: string, events: list<string>, rule: array<string, mixed>}>
     */
    public static function all(): array
    {
        return [
            [
                'id' => 'mailgun',
                'name' => 'Mailgun events',
                'description' => 'Mailgun\'s webhook JSON with a valid signature for your webhook signing key.',
                'events' => ['accepted', 'delivered', 'opened', 'clicked', 'failed', 'complained', 'unsubscribed'],
                'rule' => [
                    'name' => 'Mailgun delivered',
                    'trigger' => 'mail.received',
                    'url' => 'http://localhost/webhooks/mailgun',
                    'method' => 'POST',
                    'format' => 'json',
                    'headers' => ['User-Agent' => 'mailgun/treq-2'],
                    'signature' => ['type' => 'mailgun', 'secret' => 'key-local-signing'],
                    'steps' => [['event' => 'accepted', 'delay_ms' => 0], ['event' => 'delivered', 'delay_ms' => 2000]],
                    'body' => self::json([
                        'signature' => [
                            'timestamp' => '{{ signature.timestamp }}',
                            'token' => '{{ signature.token }}',
                            'signature' => '{{ signature.signature }}',
                        ],
                        'event-data' => [
                            'event' => '{{ event }}',
                            'timestamp' => '{{ now.unix_float }}',
                            'id' => '{{ random_hex(22) }}',
                            'recipient' => '{{ mail.recipient }}',
                            'message' => [
                                'headers' => [
                                    'message-id' => '{{ mail.message_id }}',
                                    'from' => '{{ mail.from }}',
                                    'to' => '{{ mail.to }}',
                                    'subject' => '{{ mail.subject }}',
                                ],
                            ],
                        ],
                    ]),
                ],
            ],
            [
                'id' => 'twilio',
                'name' => 'Twilio status callback',
                'description' => 'Twilio\'s status callback form, signed with X-Twilio-Signature for your auth token. Uses the StatusCallback the app sent when there is one.',
                'events' => ['queued', 'sent', 'delivered', 'failed', 'undelivered'],
                'rule' => [
                    'name' => 'Twilio status',
                    'trigger' => 'sms.received',
                    'url' => 'http://localhost/webhooks/twilio/status',
                    'use_status_callback' => true,
                    'method' => 'POST',
                    'format' => 'form',
                    'headers' => ['User-Agent' => 'TwilioProxy/1.1'],
                    'signature' => ['type' => 'twilio', 'secret' => 'your_auth_token'],
                    'steps' => [['event' => 'queued', 'delay_ms' => 0], ['event' => 'sent', 'delay_ms' => 1000], ['event' => 'delivered', 'delay_ms' => 3000]],
                    'vars' => ['account_sid' => 'AC00000000000000000000000000000000'],
                    'body' => self::json([
                        'MessageSid' => '{{ sms.sid }}',
                        'SmsSid' => '{{ sms.sid }}',
                        'MessageStatus' => '{{ event }}',
                        'SmsStatus' => '{{ event }}',
                        'To' => '{{ sms.to }}',
                        'From' => '{{ sms.from }}',
                        'AccountSid' => '{{ vars.account_sid }}',
                        'ApiVersion' => '2010-04-01',
                    ]),
                ],
            ],
            [
                'id' => 'vonage',
                'name' => 'Vonage delivery receipt',
                'description' => 'Vonage\'s delivery receipt (DLR) as JSON.',
                'events' => ['delivered', 'expired', 'failed', 'rejected', 'accepted', 'buffered'],
                'rule' => [
                    'name' => 'Vonage delivery receipt',
                    'trigger' => 'sms.received',
                    'url' => 'http://localhost/webhooks/vonage/dlr',
                    'use_status_callback' => true,
                    'method' => 'POST',
                    'format' => 'json',
                    'steps' => [['event' => 'delivered', 'delay_ms' => 2000]],
                    'body' => self::json([
                        'msisdn' => '{{ sms.to }}',
                        'to' => '{{ sms.from }}',
                        'network-code' => '00000',
                        'messageId' => '{{ sms.message_id }}',
                        'price' => '0.00000000',
                        'status' => '{{ event }}',
                        'scts' => "{{ date('ymdHi') }}",
                        'err-code' => '0',
                        'message-timestamp' => "{{ date('Y-m-d H:i:s') }}",
                    ]),
                ],
            ],
            [
                'id' => 'generic',
                'name' => 'Generic JSON',
                'description' => 'A small JSON body to start from.',
                'events' => ['delivered'],
                'rule' => [
                    'name' => 'My webhook',
                    'trigger' => 'manual',
                    'url' => 'http://localhost/webhooks/test',
                    'method' => 'POST',
                    'format' => 'json',
                    'steps' => [['event' => 'delivered', 'delay_ms' => 0]],
                    'body' => self::json([
                        'id' => '{{ uuid() }}',
                        'event' => '{{ event }}',
                        'project' => '{{ project }}',
                        'sent_at' => '{{ now }}',
                    ]),
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function json(array $body): string
    {
        return (string) json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
