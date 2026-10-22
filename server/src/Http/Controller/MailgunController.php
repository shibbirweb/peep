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
 * The Mailgun messages API, so apps that send through Mailgun's HTTP API work unchanged:
 *
 * POST /v3/{domain}/messages       fields from, to, cc, bcc, subject, text, html, h:*, v:*,
 *                                  files attachment and inline
 * POST /v3/{domain}/messages.mime  field to, file message (a finished MIME message)
 *
 * Any API key is accepted. The answer is Mailgun's: {"id": "<...>", "message": "Queued. Thank you."}.
 */
final readonly class MailgunController implements RequestHandler
{
    public function __construct(
        private Catcher $catcher,
        private MailParser $parser = new MailParser(),
        private MimeBuilder $builder = new MimeBuilder(),
    ) {}

    public function handleRequest(Request $request): Response
    {
        if (preg_match('#^/v3/([^/]+)/(messages|messages\.mime)$#', $request->getUri()->getPath(), $matches) !== 1) {
            return Responses::error(404, 'not_found', 'Not found.');
        }

        [, $domain, $endpoint] = $matches;
        $form = RequestData::form($request);

        if ($form === null) {
            return Responses::json(400, ['message' => 'Send the message as multipart/form-data or application/x-www-form-urlencoded.']);
        }

        $messageId = sprintf('%s.%s@%s', date('YmdHis'), bin2hex(random_bytes(8)), $domain);

        if ($endpoint === 'messages.mime') {
            $raw = $form->getFile('message')?->getContents() ?? $form->getValue('message');
            $recipients = RequestData::list(['to' => $form->getValueArray('to')], 'to');
        } else {
            $raw = $this->build($form, $messageId);
            $recipients = [];
        }

        if ($raw === null || $raw === '') {
            return Responses::json(400, ['message' => "'to' parameter is missing"]);
        }

        try {
            $mail = $this->parser->parse($raw, $this->project($request, $form->getValue('h:X-Peep-Project')), array_map(self::email(...), $recipients), 'mailgun', microtime(true));
        } catch (Throwable $exception) {
            return Responses::json(400, ['message' => 'The message could not be read: ' . $exception->getMessage()]);
        }

        $this->catcher->mail($mail);

        return Responses::json(200, ['id' => '<' . trim($mail->messageId, '<>') . '>', 'message' => 'Queued. Thank you.']);
    }

    private function build(\Amp\Http\Server\FormParser\Form $form, string $messageId): ?string
    {
        $to = RequestData::list(['to' => $form->getValueArray('to')], 'to');
        if ($to === []) {
            return null;
        }

        $headers = [];
        $variables = [];
        foreach ($form->getNames() as $name) {
            if (str_starts_with($name, 'h:')) {
                $headers[substr($name, 2)] = (string) $form->getValue($name);
            } elseif (str_starts_with($name, 'v:')) {
                $variables[substr($name, 2)] = (string) $form->getValue($name);
            }
        }

        if ($variables !== []) {
            $headers['X-Mailgun-Variables'] = (string) json_encode($variables, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $attachments = [];
        foreach (['attachment' => false, 'inline' => true] as $field => $inline) {
            foreach ($form->getFileArray($field) as $file) {
                $attachments[] = ['filename' => $file->getName(), 'content_type' => $file->getMimeType(), 'content' => $file->getContents(), 'inline' => $inline];
            }
        }

        return $this->builder->build(
            from: (string) $form->getValue('from'),
            to: $to,
            subject: (string) $form->getValue('subject'),
            text: $form->getValue('text'),
            html: $form->getValue('html'),
            cc: RequestData::list(['cc' => $form->getValueArray('cc')], 'cc'),
            bcc: RequestData::list(['bcc' => $form->getValueArray('bcc')], 'bcc'),
            headers: $headers,
            attachments: $attachments,
            messageId: $messageId,
        );
    }

    private function project(Request $request, ?string $field): ?string
    {
        $header = trim((string) $request->getHeader('x-peep-project'));

        return $header !== '' ? $header : ($field !== null && trim($field) !== '' ? trim($field) : null);
    }

    private static function email(string $address): string
    {
        return strtolower(preg_match('/<([^>]+)>$/', $address, $matches) === 1 ? $matches[1] : $address);
    }
}
