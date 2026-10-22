<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Mail;

use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\Message;

/**
 * Turns raw MIME into a Mail. Every way mail arrives (SMTP, HTTP, Mailgun API) ends here.
 */
final class MailParser
{
    public const string PROJECT_HEADER = 'X-Peep-Project';

    public const string REQUEST_HEADER = 'X-Peep-Request-Id';

    /**
     * @param string|null $project From the SMTP login or the request; null reads X-Peep-Project.
     * @param list<string> $recipients SMTP RCPT TO addresses: those not in To or Cc are Bcc.
     */
    public function parse(string $raw, ?string $project, array $recipients, string $source, float $now): Mail
    {
        $message = Message::from($raw, false);

        $to = self::addresses($message, 'to');
        $cc = self::addresses($message, 'cc');
        $visible = array_map(self::email(...), [...$to, ...$cc]);
        $bcc = [
            ...self::addresses($message, 'bcc'),
            ...array_values(array_filter($recipients, static fn(string $recipient): bool => ! in_array(strtolower($recipient), $visible, true))),
        ];

        $headers = [];
        foreach ($message->getAllHeaders() as $header) {
            $headers[] = ['name' => $header->getName(), 'value' => (string) $header->getRawValue()];
        }

        $attachments = [];
        foreach ($message->getAllAttachmentParts() as $index => $part) {
            $attachments[] = [
                'index' => $index,
                'filename' => $part->getFilename() ?? 'attachment-' . ($index + 1),
                'content_type' => $part->getContentType() ?? 'application/octet-stream',
                'size' => strlen((string) $part->getContent()),
                'inline' => strtolower((string) $part->getContentDisposition()) === 'inline',
            ];
        }

        $headerProject = trim((string) $message->getHeaderValue(self::PROJECT_HEADER));
        $requestId = trim((string) $message->getHeaderValue(self::REQUEST_HEADER));
        $messageId = trim((string) $message->getHeaderValue('message-id'));

        return new Mail(
            id: 'mail_' . bin2hex(random_bytes(8)),
            project: $project ?? ($headerProject !== '' ? $headerProject : 'default'),
            requestId: $requestId !== '' ? $requestId : null,
            messageId: $messageId !== '' ? $messageId : bin2hex(random_bytes(8)) . '@peep.local',
            from: implode(', ', self::addresses($message, 'from')),
            to: $to,
            cc: $cc,
            bcc: array_values(array_unique($bcc)),
            subject: (string) $message->getSubject(),
            html: $message->getHtmlContent(),
            text: $message->getTextContent(),
            headers: $headers,
            attachments: $attachments,
            source: $source,
            receivedAt: $now,
            raw: $raw,
        );
    }

    /**
     * @return array{filename: string, content_type: string, content: string}|null
     */
    public function attachment(Mail $mail, int $index): ?array
    {
        try {
            $part = Message::from($mail->raw(), false)->getAttachmentPart($index);
        } catch (Throwable) {
            return null;
        }

        if ($part === null) {
            return null;
        }

        return [
            'filename' => $part->getFilename() ?? 'attachment-' . ($index + 1),
            'content_type' => $part->getContentType() ?? 'application/octet-stream',
            'content' => (string) $part->getContent(),
        ];
    }

    /**
     * @return list<string> "Name <email>" or "email"
     */
    private static function addresses(IMessage $message, string $header): array
    {
        $value = $message->getHeader($header);
        if (! $value instanceof AddressHeader) {
            return [];
        }

        $addresses = [];
        foreach ($value->getAddresses() as $address) {
            $email = $address->getEmail();
            $name = trim($address->getName());
            $addresses[] = $name !== '' ? $name . ' <' . $email . '>' : $email;
        }

        return $addresses;
    }

    private static function email(string $address): string
    {
        return strtolower(preg_match('/<([^>]+)>$/', $address, $matches) === 1 ? $matches[1] : $address);
    }
}
