<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use ShibbirWeb\PeepServer\Http\RequestData;
use ShibbirWeb\PeepServer\Http\Responses;
use ShibbirWeb\PeepServer\Inbox\Catcher;
use ShibbirWeb\PeepServer\Sms\Sms;

/**
 * SMS endpoints:
 *
 * POST /_peep/sms                                   JSON or form: to, from, body, project, request_id, status_callback
 * POST /2010-04-01/Accounts/{sid}/Messages.json     Twilio: To, From or MessagingServiceSid, Body, MediaUrl, StatusCallback
 * POST /sms/json                                    Vonage: from, to, text, callback
 *
 * Each answers like the real API, so the app's code goes on as if the SMS was sent.
 */
final readonly class SmsController implements RequestHandler
{
    public function __construct(
        private Catcher $catcher,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $path = $request->getUri()->getPath();
        $fields = RequestData::fields($request);

        if ($fields === null) {
            return Responses::error(413, 'too_large', 'The request is too large.');
        }

        if (preg_match('#^/2010-04-01/Accounts/([A-Za-z0-9]+)/Messages\.json$#', $path, $matches) === 1) {
            return $this->twilio($request, $fields, $matches[1]);
        }

        if ($path === '/sms/json') {
            return $this->vonage($request, $fields);
        }

        $to = RequestData::string($fields, 'to');
        $body = RequestData::string($fields, 'body', 'text', 'message');

        if ($to === null || $body === null) {
            return Responses::error(400, 'invalid_sms', 'Send at least "to" and "body".');
        }

        $sms = Sms::create(
            project: RequestData::project($request, $fields),
            to: $to,
            from: RequestData::string($fields, 'from') ?? '',
            body: $body,
            source: in_array(RequestData::string($fields, 'source'), ['laravel', 'peep'], true) ? (string) RequestData::string($fields, 'source') : 'http',
            now: microtime(true),
            requestId: RequestData::string($fields, 'request_id'),
            statusCallback: RequestData::string($fields, 'status_callback'),
        );
        $this->catcher->sms($sms);

        return Responses::json(202, $sms->toArray());
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function twilio(Request $request, array $fields, string $accountSid): Response
    {
        $to = RequestData::string($fields, 'To');
        $body = RequestData::string($fields, 'Body') ?? '';
        $media = array_values(array_filter((array) ($fields['MediaUrl'] ?? []), is_string(...)));

        if ($to === null) {
            return Responses::json(400, ['code' => 21604, 'message' => "A 'To' phone number is required.", 'status' => 400]);
        }

        if ($body === '' && $media === []) {
            return Responses::json(400, ['code' => 21602, 'message' => 'Message body is required.', 'status' => 400]);
        }

        $from = RequestData::string($fields, 'From') ?? RequestData::string($fields, 'MessagingServiceSid') ?? '';
        $sms = Sms::create(
            project: RequestData::project($request),
            to: $to,
            from: $from,
            body: $body,
            source: 'twilio',
            now: microtime(true),
            statusCallback: RequestData::string($fields, 'StatusCallback'),
            media: $media,
        );
        $this->catcher->sms($sms);

        $date = gmdate(DATE_RFC2822, (int) $sms->receivedAt);

        return Responses::json(201, [
            'account_sid' => $accountSid,
            'api_version' => '2010-04-01',
            'body' => $body,
            'date_created' => $date,
            'date_sent' => null,
            'date_updated' => $date,
            'direction' => 'outbound-api',
            'error_code' => null,
            'error_message' => null,
            'from' => $from,
            'messaging_service_sid' => RequestData::string($fields, 'MessagingServiceSid'),
            'num_media' => (string) count($media),
            'num_segments' => (string) $sms->segments['segments'],
            'price' => null,
            'price_unit' => 'USD',
            'sid' => $sms->sid,
            'status' => 'queued',
            'subresource_uris' => ['media' => "/2010-04-01/Accounts/{$accountSid}/Messages/{$sms->sid}/Media.json"],
            'to' => $to,
            'uri' => "/2010-04-01/Accounts/{$accountSid}/Messages/{$sms->sid}.json",
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function vonage(Request $request, array $fields): Response
    {
        $to = RequestData::string($fields, 'to');
        $text = RequestData::string($fields, 'text') ?? '';

        if ($to === null) {
            return Responses::json(200, ['message-count' => '1', 'messages' => [['status' => '2', 'error-text' => 'Missing to param']]]);
        }

        $sms = Sms::create(
            project: RequestData::project($request),
            to: $to,
            from: RequestData::string($fields, 'from') ?? '',
            body: $text,
            source: 'vonage',
            now: microtime(true),
            statusCallback: RequestData::string($fields, 'callback'),
        );
        $this->catcher->sms($sms);

        return Responses::json(200, [
            'message-count' => (string) $sms->segments['segments'],
            'messages' => array_map(static fn(int $part): array => [
                'to' => $to,
                'message-id' => strtoupper(substr($sms->sid, 2, 16)) . ($part > 0 ? '-' . $part : ''),
                'status' => '0',
                'remaining-balance' => '10.00',
                'message-price' => '0.00',
                'network' => '00000',
            ], range(0, $sms->segments['segments'] - 1)),
        ]);
    }
}
