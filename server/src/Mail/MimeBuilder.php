<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Mail;

/**
 * Builds raw MIME from separate fields, for mail that arrives as an API call (Mailgun,
 * generic JSON) instead of as a finished message.
 */
final class MimeBuilder
{
    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<string> $bcc
     * @param array<string, string> $headers Extra headers (Mailgun "h:" fields).
     * @param list<array{filename: string, content_type: string, content: string, inline?: bool}> $attachments
     */
    public function build(
        string $from,
        array $to,
        string $subject,
        ?string $text,
        ?string $html,
        array $cc = [],
        array $bcc = [],
        array $headers = [],
        array $attachments = [],
        ?string $messageId = null,
    ): string {
        $lines = [
            'From: ' . self::headerValue($from),
            'To: ' . self::headerValue(implode(', ', $to)),
        ];

        if ($cc !== []) {
            $lines[] = 'Cc: ' . self::headerValue(implode(', ', $cc));
        }

        if ($bcc !== []) {
            $lines[] = 'Bcc: ' . self::headerValue(implode(', ', $bcc));
        }

        $lines[] = 'Subject: ' . self::encodeWords($subject);
        $lines[] = 'Date: ' . date(DATE_RFC2822);
        $lines[] = 'Message-ID: <' . ($messageId ?? bin2hex(random_bytes(12)) . '@peep.local') . '>';
        $lines[] = 'MIME-Version: 1.0';

        foreach ($headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]+$/', $name) === 1) {
                $lines[] = $name . ': ' . self::headerValue($value);
            }
        }

        $body = self::alternative($text, $html);

        if ($attachments !== []) {
            $boundary = 'peep-mixed-' . bin2hex(random_bytes(8));
            $parts = [$body];

            foreach ($attachments as $attachment) {
                $name = str_replace(['"', "\r", "\n"], '', $attachment['filename']);
                $parts[] = [
                    'Content-Type: ' . self::headerValue($attachment['content_type']) . '; name="' . $name . '"',
                    'Content-Disposition: ' . (($attachment['inline'] ?? false) ? 'inline' : 'attachment') . '; filename="' . $name . '"',
                    'Content-Transfer-Encoding: base64',
                    '',
                    rtrim(chunk_split(base64_encode($attachment['content']), 76, "\r\n")),
                ];
            }

            $body = self::multipart('mixed', $boundary, $parts);
        }

        return implode("\r\n", [...$lines, ...$body]) . "\r\n";
    }

    /**
     * @return list<string>
     */
    private static function alternative(?string $text, ?string $html): array
    {
        $textPart = $text !== null ? self::textPart('plain', $text) : null;
        $htmlPart = $html !== null ? self::textPart('html', $html) : null;

        if ($textPart !== null && $htmlPart !== null) {
            return self::multipart('alternative', 'peep-alt-' . bin2hex(random_bytes(8)), [$textPart, $htmlPart]);
        }

        return $htmlPart ?? $textPart ?? self::textPart('plain', '');
    }

    /**
     * @return list<string>
     */
    private static function textPart(string $subtype, string $content): array
    {
        return [
            'Content-Type: text/' . $subtype . '; charset=utf-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode($content), 76, "\r\n")),
        ];
    }

    /**
     * @param list<list<string>> $parts Each part's headers, a blank line and its body.
     * @return list<string>
     */
    private static function multipart(string $subtype, string $boundary, array $parts): array
    {
        $lines = ['Content-Type: multipart/' . $subtype . '; boundary="' . $boundary . '"', ''];

        foreach ($parts as $part) {
            $lines[] = '--' . $boundary;
            array_push($lines, ...$part);
        }

        $lines[] = '--' . $boundary . '--';

        return $lines;
    }

    private static function headerValue(string $value): string
    {
        // A header value never contains a line break, so nothing can inject another header.
        $value = str_replace(["\r", "\n"], ' ', $value);

        return preg_match('/[^\x20-\x7E]/', $value) === 1 ? self::encodeWords($value) : $value;
    }

    private static function encodeWords(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);

        return preg_match('/[^\x20-\x7E]/', $value) === 1 ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }
}
