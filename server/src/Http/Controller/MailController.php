<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use ShibbirWeb\PeepServer\Http\RequestData;
use ShibbirWeb\PeepServer\Http\Responses;
use ShibbirWeb\PeepServer\Inbox\Catcher;
use ShibbirWeb\PeepServer\Mail\MailParser;
use ShibbirWeb\PeepServer\Mail\MimeBuilder;
use Throwable;

/**
 * POST /_peep/mail: mail from any app or from Peep's Laravel transport.
 *
 * - Content-Type message/rfc822: the body is the raw message.
 * - JSON {"raw_base64": "..."}: the raw message, base64 (the Laravel transport).
 * - JSON {"from", "to", "subject", "text", "html", "cc", "bcc"}: built into a message.
 */
final readonly class MailController implements RequestHandler
{
    public function __construct(
        private Catcher $catcher,
        private MailParser $parser = new MailParser(),
        private MimeBuilder $builder = new MimeBuilder(),
    ) {}

    public function handleRequest(Request $request): Response
    {
        $isRaw = str_starts_with(strtolower((string) $request->getHeader('content-type')), 'message/rfc822');
        $fields = $isRaw ? [] : RequestData::fields($request);
        $raw = $isRaw ? RequestData::body($request) : self::raw($fields ?? [], $this->builder);

        if ($raw === null || $raw === '') {
            return Responses::error(400, 'invalid_mail', 'Send a raw message (message/rfc822), {"raw_base64": "..."}, or {"from", "to", "subject", "text" or "html"}.');
        }

        $header = trim((string) $request->getHeader('x-peep-project'));
        $project = $header !== '' ? $header : RequestData::string($fields ?? [], 'project');

        try {
            $mail = $this->parser->parse($raw, $project, [], 'http', microtime(true));
        } catch (Throwable $exception) {
            return Responses::error(400, 'invalid_mail', 'The message could not be read: ' . $exception->getMessage());
        }

        $this->catcher->mail($mail);

        return Responses::json(202, ['id' => $mail->id, 'message_id' => $mail->messageId]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function raw(array $fields, MimeBuilder $builder): ?string
    {
        $base64 = RequestData::string($fields, 'raw_base64');
        if ($base64 !== null) {
            $raw = base64_decode($base64, true);

            return $raw === false ? null : $raw;
        }

        $to = RequestData::list($fields, 'to');
        if ($to === []) {
            return null;
        }

        return $builder->build(
            from: RequestData::string($fields, 'from') ?? 'app@peep.local',
            to: $to,
            subject: RequestData::string($fields, 'subject') ?? '',
            text: RequestData::string($fields, 'text'),
            html: RequestData::string($fields, 'html'),
            cc: RequestData::list($fields, 'cc'),
            bcc: RequestData::list($fields, 'bcc'),
        );
    }
}
