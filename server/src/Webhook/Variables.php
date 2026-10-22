<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Webhook;

use ShibbirWeb\PeepServer\Mail\Mail;
use ShibbirWeb\PeepServer\Sms\Sms;

/**
 * What templates can use (WH-02): event, project, now, mail.*, sms.*, vars.*, and
 * signature.* (filled by the Mailgun signer).
 */
final class Variables
{
    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function build(Rule $rule, string $event, string $project, ?Mail $mail, ?Sms $sms, float $now): array
    {
        return [
            'event' => $event,
            'project' => $project,
            'rule' => ['id' => $rule->id, 'name' => $rule->name],
            'now' => [
                '_' => gmdate('Y-m-d\TH:i:s', (int) $now) . sprintf('.%03dZ', (int) (fmod($now, 1) * 1000)),
                'unix' => (int) $now,
                'unix_float' => round($now, 6),
                'ms' => (int) round($now * 1000),
            ],
            'mail' => $mail !== null ? self::mail($mail) : [],
            'sms' => $sms !== null ? self::sms($sms) : [],
            'vars' => $rule->vars,
            'signature' => [],
        ];
    }

    /**
     * Made-up data for the Test button (WH-10).
     */
    public static function sampleMail(): Mail
    {
        return new Mail(
            id: 'mail_sample',
            project: 'sample',
            requestId: null,
            messageId: 'sample.' . bin2hex(random_bytes(6)) . '@peep.local',
            from: 'Shop <shop@example.test>',
            to: ['Ada Lovelace <ada@example.test>'],
            cc: [],
            bcc: [],
            subject: 'Sample mail from the Peep webhook test',
            html: '<p>Sample</p>',
            text: 'Sample',
            headers: [],
            attachments: [],
            source: 'test',
            receivedAt: microtime(true),
            raw: '',
        );
    }

    public static function sampleSms(): Sms
    {
        return Sms::create('sample', '+15550100001', '+15550100999', 'Sample SMS from the Peep webhook test', 'test', microtime(true));
    }

    /**
     * @return array<string, mixed>
     */
    private static function mail(Mail $mail): array
    {
        $headers = [];
        foreach ($mail->headers as $header) {
            $headers[strtolower($header['name'])] = $header['value'];
        }

        return [
            'id' => $mail->id,
            'message_id' => trim($mail->messageId, '<>'),
            'from' => $mail->from,
            'from_email' => self::email($mail->from),
            'to' => implode(', ', $mail->to),
            'to_list' => $mail->to,
            'recipient' => self::email($mail->to[0] ?? ''),
            'cc' => implode(', ', $mail->cc),
            'subject' => $mail->subject,
            'text' => $mail->text ?? '',
            'headers' => $headers,
            'request_id' => $mail->requestId ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function sms(Sms $sms): array
    {
        return [
            'id' => $sms->id,
            'sid' => $sms->sid,
            // Vonage-style message id: 16 upper-case hex characters.
            'message_id' => strtoupper(substr($sms->sid, 2, 16)),
            'to' => $sms->to,
            'from' => $sms->from,
            'body' => $sms->body,
            'segments' => $sms->segments['segments'],
            'status_callback' => $sms->statusCallback ?? '',
            'request_id' => $sms->requestId ?? '',
        ];
    }

    private static function email(string $address): string
    {
        return preg_match('/<([^>]+)>\s*$/', $address, $matches) === 1 ? $matches[1] : trim($address);
    }
}
