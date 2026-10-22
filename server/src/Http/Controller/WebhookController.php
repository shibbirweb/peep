<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use ShibbirWeb\PeepServer\Event\EventHub;
use ShibbirWeb\PeepServer\Event\ServerEvent;
use ShibbirWeb\PeepServer\Http\JsonBody;
use ShibbirWeb\PeepServer\Http\Responses;
use ShibbirWeb\PeepServer\Inbox\Catcher;
use ShibbirWeb\PeepServer\Webhook\InvalidRuleException;
use ShibbirWeb\PeepServer\Webhook\Presets;
use ShibbirWeb\PeepServer\Webhook\Rule;
use ShibbirWeb\PeepServer\Webhook\Variables;
use ShibbirWeb\PeepServer\Webhook\WebhookRunner;

/**
 * The webhook page (WH-13):
 *
 * GET  /_peep/ui/webhooks/presets                 provider presets
 * GET  /_peep/ui/webhooks/deliveries/{id}         one logged call with request and response
 * POST /_peep/ui/webhooks/rules                   {"rule": {...}}: create or update
 * POST /_peep/ui/webhooks/rules/{id}/delete
 * POST /_peep/ui/webhooks/rules/{id}/test         {"event": "delivered"}: sample data (WH-10)
 * POST /_peep/ui/webhooks/send                    {"rule_id", "mail_id" or "sms_id", "event"}: from the inbox (WH-07)
 * POST /_peep/ui/webhooks/deliveries/{id}/resend
 * POST /_peep/ui/webhooks/deliveries/clear
 * POST /_peep/ui/webhooks/import                  {"rules": [...], "replace": false} (WH-11)
 */
final readonly class WebhookController implements RequestHandler
{
    public function __construct(
        private WebhookRunner $runner,
        private Catcher $catcher,
        private EventHub $hub,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $path = substr($request->getUri()->getPath(), strlen('/_peep/ui/webhooks'));

        if ($request->getMethod() === 'GET') {
            if ($path === '/presets') {
                return Responses::json(200, ['presets' => Presets::all()]);
            }

            if (preg_match('#^/deliveries/([A-Za-z0-9_]+)$#', $path, $matches) === 1) {
                $delivery = $this->runner->find($matches[1]);

                return $delivery === null ? Responses::error(404, 'unknown_delivery', 'No such delivery.') : Responses::json(200, $delivery->toArray());
            }

            return Responses::error(404, 'not_found', 'Not found.');
        }

        $data = JsonBody::read($request);

        return match (true) {
            $path === '/rules' => $this->save($data),
            $path === '/send' => $this->send($data),
            $path === '/import' => $this->import($data),
            $path === '/deliveries/clear' => $this->clear(),
            preg_match('#^/rules/([A-Za-z0-9_-]+)/(delete|test)$#', $path, $matches) === 1 => $matches[2] === 'delete' ? $this->delete($matches[1]) : $this->test($matches[1], $data),
            preg_match('#^/deliveries/([A-Za-z0-9_]+)/resend$#', $path, $matches) === 1 => $this->resend($matches[1]),
            default => Responses::error(404, 'not_found', 'Not found.'),
        };
    }

    /**
     * @param array<mixed> $data
     */
    private function save(array $data): Response
    {
        try {
            $rule = Rule::fromArray(is_array($data['rule'] ?? null) ? $data['rule'] : []);
        } catch (InvalidRuleException $exception) {
            return Responses::json(422, ['error' => 'invalid_rule', 'field' => $exception->field, 'message' => $exception->getMessage()]);
        }

        $this->runner->rules->save($rule);
        $this->changed();

        return Responses::json(200, ['rule' => $rule->toArray()]);
    }

    private function delete(string $id): Response
    {
        if (! $this->runner->rules->delete($id)) {
            return Responses::error(404, 'unknown_rule', 'No such rule.');
        }

        $this->changed();

        return Responses::json(200, ['deleted' => $id]);
    }

    /**
     * @param array<mixed> $data
     */
    private function test(string $id, array $data): Response
    {
        $rule = $this->runner->rules->find($id);
        if ($rule === null) {
            return Responses::error(404, 'unknown_rule', 'No such rule.');
        }

        $event = is_string($data['event'] ?? null) && $data['event'] !== '' ? $data['event'] : $rule->steps[0]['event'];
        $delivery = $this->runner->deliver($rule, $event, Variables::sampleMail(), Variables::sampleSms());

        return Responses::json(200, $delivery->toArray());
    }

    /**
     * @param array<mixed> $data
     */
    private function send(array $data): Response
    {
        $rule = is_string($data['rule_id'] ?? null) ? $this->runner->rules->find($data['rule_id']) : null;
        if ($rule === null) {
            return Responses::error(404, 'unknown_rule', 'No such rule.');
        }

        $mail = is_string($data['mail_id'] ?? null) ? $this->catcher->mails->find($data['mail_id']) : null;
        $sms = is_string($data['sms_id'] ?? null) ? $this->catcher->sms->find($data['sms_id']) : null;
        if ($mail === null && $sms === null) {
            return Responses::error(404, 'unknown_message', 'The mail or SMS is no longer in the inbox.');
        }

        $event = is_string($data['event'] ?? null) && $data['event'] !== '' ? $data['event'] : null;
        $this->runner->schedule($rule, $mail, $sms, $event);

        return Responses::json(202, ['scheduled' => $event ?? array_column($rule->steps, 'event')]);
    }

    /**
     * @param array<mixed> $data
     */
    private function import(array $data): Response
    {
        $rules = [];

        foreach (is_array($data['rules'] ?? null) ? $data['rules'] : [] as $index => $rule) {
            try {
                $rules[] = Rule::fromArray(is_array($rule) ? $rule : []);
            } catch (InvalidRuleException $exception) {
                return Responses::json(422, ['error' => 'invalid_rule', 'field' => $exception->field, 'message' => 'Rule ' . ((int) $index + 1) . ': ' . $exception->getMessage()]);
            }
        }

        $this->runner->rules->import($rules, ($data['replace'] ?? false) === true);
        $this->changed();

        return Responses::json(200, ['imported' => count($rules)]);
    }

    private function resend(string $id): Response
    {
        $delivery = $this->runner->resend($id);

        return $delivery === null ? Responses::error(404, 'unknown_delivery', 'No such delivery, or it was never sent.') : Responses::json(200, $delivery->toArray());
    }

    private function clear(): Response
    {
        $this->runner->clear();

        return Responses::json(200, ['cleared' => true]);
    }

    private function changed(): void
    {
        $this->hub->publish([new ServerEvent('webhooks.changed', ['rules' => $this->runner->rules->toArray()])]);
    }
}
