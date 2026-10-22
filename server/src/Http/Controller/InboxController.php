<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use ShibbirWeb\PeepServer\Event\EventHub;
use ShibbirWeb\PeepServer\Http\JsonBody;
use ShibbirWeb\PeepServer\Http\Responses;
use ShibbirWeb\PeepServer\Inbox\Catcher;
use ShibbirWeb\PeepServer\Mail\MailParser;

/**
 * The inbox page's actions:
 *
 * GET  /_peep/ui/mail/{id}                      the mail with HTML, text, headers, attachments
 * GET  /_peep/ui/mail/{id}/source               the raw message, as text
 * GET  /_peep/ui/mail/{id}/attachments/{index}  an attachment, always as a download
 * POST /_peep/ui/mail/{id}/read                 {"read": true}
 * POST /_peep/ui/mail/{id}/delete
 * POST /_peep/ui/mail/clear                     {"project": "shop"} or null for all
 * POST /_peep/ui/sms/read                       {"project", "to"}: a thread was opened
 * POST /_peep/ui/sms/{id}/delete
 * POST /_peep/ui/sms/clear                      {"project": "shop"} or null for all
 */
final readonly class InboxController implements RequestHandler
{
    public function __construct(
        private Catcher $catcher,
        private EventHub $hub,
        private MailParser $parser = new MailParser(),
    ) {}

    public function handleRequest(Request $request): Response
    {
        $path = $request->getUri()->getPath();
        $post = $request->getMethod() === 'POST';

        if ($post && $path === '/_peep/ui/mail/clear') {
            return $this->publish($this->catcher->mails->clear(JsonBody::project(JsonBody::read($request))));
        }

        if ($post && $path === '/_peep/ui/sms/clear') {
            return $this->publish($this->catcher->sms->clear(JsonBody::project(JsonBody::read($request))));
        }

        if ($post && $path === '/_peep/ui/sms/read') {
            $data = JsonBody::read($request);
            $project = JsonBody::project($data);
            $to = is_string($data['to'] ?? null) ? $data['to'] : null;

            return $project === null || $to === null
                ? Responses::error(400, 'invalid_request', 'Send {"project", "to"}.')
                : $this->publish($this->catcher->sms->markThreadRead($project, $to));
        }

        if ($post && preg_match('#^/_peep/ui/sms/([A-Za-z0-9_]+)/delete$#', $path, $matches) === 1) {
            return $this->publish($this->catcher->sms->delete($matches[1]));
        }

        if (preg_match('#^/_peep/ui/mail/([A-Za-z0-9_]+)(?:/(source|read|delete|attachments/(\d+)))?$#', $path, $matches) !== 1) {
            return Responses::error(404, 'not_found', 'Not found.');
        }

        $mail = $this->catcher->mails->find($matches[1]);
        if ($mail === null) {
            return Responses::error(404, 'unknown_mail', 'No such mail; it may have been deleted.');
        }

        $action = $matches[2] ?? '';

        return match (true) {
            $post && $action === 'read' => $this->publish($this->catcher->mails->markRead($mail->id, (JsonBody::read($request)['read'] ?? true) !== false)),
            $post && $action === 'delete' => $this->publish($this->catcher->mails->delete($mail->id)),
            $post => Responses::error(405, 'method_not_allowed', 'Method not allowed.'),
            $action === 'source' => new Response(200, self::downloadHeaders('text/plain; charset=utf-8', null), $mail->raw()),
            str_starts_with($action, 'attachments/') => $this->attachment($mail, (int) ($matches[3] ?? 0)),
            default => Responses::json(200, $mail->toArray()),
        };
    }

    private function attachment(\ShibbirWeb\PeepServer\Mail\Mail $mail, int $index): Response
    {
        $attachment = $this->parser->attachment($mail, $index);

        if ($attachment === null) {
            return Responses::error(404, 'unknown_attachment', 'No such attachment.');
        }

        return new Response(200, self::downloadHeaders('application/octet-stream', $attachment['filename']), $attachment['content']);
    }

    /**
     * App content is never rendered on the viewer's origin: attachments are downloads,
     * and the source is plain text the browser must not sniff.
     *
     * @return array<non-empty-string, string>
     */
    private static function downloadHeaders(string $contentType, ?string $filename): array
    {
        $headers = [
            'content-type' => $contentType,
            'x-content-type-options' => 'nosniff',
            'content-security-policy' => "default-src 'none'; sandbox",
            'cache-control' => 'no-store',
        ];

        if ($filename !== null) {
            $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'attachment';
            $headers['content-disposition'] = 'attachment; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
        }

        return $headers;
    }

    /**
     * @param list<\ShibbirWeb\PeepServer\Event\ServerEvent> $events
     */
    private function publish(array $events): Response
    {
        $this->hub->publish($events);

        return Responses::json(200, ['ok' => true]);
    }
}
