<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use ShibbirWeb\PeepServer\Agent\CardQuery;
use ShibbirWeb\PeepServer\Agent\Report;
use ShibbirWeb\PeepServer\Agent\TextRenderer;
use ShibbirWeb\PeepServer\Card\Card;
use ShibbirWeb\PeepServer\Card\CardStore;
use ShibbirWeb\PeepServer\Card\Receiving;
use ShibbirWeb\PeepServer\Event\EventHub;
use ShibbirWeb\PeepServer\Http\JsonBody;
use ShibbirWeb\PeepServer\Http\Responses;
use ShibbirWeb\PeepServer\Inbox\Catcher;
use ShibbirWeb\PeepServer\Log\LogEntry;
use ShibbirWeb\PeepServer\Log\LogFiles;
use ShibbirWeb\PeepServer\Log\LogFolders;
use ShibbirWeb\PeepServer\Log\LogParser;
use ShibbirWeb\PeepServer\Mail\Mail;
use ShibbirWeb\PeepServer\Sms\Sms;
use ShibbirWeb\PeepServer\Switch\SwitchStore;
use ShibbirWeb\PeepServer\Version;
use ShibbirWeb\PeepServer\Webhook\Delivery;
use ShibbirWeb\PeepServer\Webhook\Rule;
use ShibbirWeb\PeepServer\Webhook\Variables;
use ShibbirWeb\PeepServer\Webhook\WebhookRunner;

/**
 * What AI agents and the `peep` command line read, as plain text (the default) or JSON
 * (format=json). Only this machine can use these paths (TokenGuard, HostGuard).
 *
 * GET  /_peep/agent/status         is the viewer up, projects and their counts, switches
 * GET  /_peep/agent/cards          cards, filtered by CardQuery
 * GET  /_peep/agent/report         a Markdown debug report of the same cards, mail and SMS
 * GET  /_peep/agent/mail           mail (id=... for one, with its body)
 * GET  /_peep/agent/sms            SMS
 * GET  /_peep/agent/logs           entries of a project's log file (file=, level=, since=, search=, limit=)
 * GET  /_peep/agent/webhooks       webhook rules (never their secrets) and the calls made (rule=, id=... for one in full)
 * POST /_peep/agent/webhooks/send  {"rule", "event", "mail" or "sms" (an id or "last"), "project"}: call the app now
 * POST /_peep/agent/clear          {"project": "shop" or null, "what": "cards" | "mail" | "sms" | "webhooks" | "all"}
 *
 * Agents send webhooks only through rules made in the viewer, so they never see or choose a
 * signing secret, and a rule calls only the URL the user gave it.
 */
final readonly class AgentController implements RequestHandler
{
    private const int MAX_SCAN = 100000;

    private const int LOG_SCAN = 500;

    private const float LOG_WINDOW_SECONDS = 900.0;

    public const array CLEAR_WHAT = ['all', 'cards', 'mail', 'sms', 'webhooks'];

    public function __construct(
        private CardStore $cards,
        private Catcher $catcher,
        private EventHub $hub,
        private Receiving $receiving,
        private SwitchStore $switches,
        private string $url,
        private ?LogFolders $logFolders = null,
        private ?WebhookRunner $webhooks = null,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $now = microtime(true);
        $path = $request->getUri()->getPath();
        $queryString = $request->getUri()->getQuery();
        $query = CardQuery::fromQueryString($queryString, $now);
        parse_str($queryString, $parameters);
        $json = ($parameters['format'] ?? null) === 'json';
        $renderer = new TextRenderer($now, $query->base);

        if ($request->getMethod() === 'POST') {
            return match ($path) {
                '/_peep/agent/clear' => $this->clear(JsonBody::read($request)),
                '/_peep/agent/webhooks/send' => $this->sendWebhook(JsonBody::read($request), $renderer),
                default => Responses::error(404, 'not_found', 'Not found.'),
            };
        }

        return match ($path) {
            '/_peep/agent/status' => $this->status($now, $json),
            '/_peep/agent/cards' => $this->cardList($query, $renderer, $now, $json),
            '/_peep/agent/report' => self::text($this->report($query, $renderer), 'text/markdown'),
            '/_peep/agent/mail' => $this->mail($query, $renderer, $now, $json, is_string($parameters['id'] ?? null) ? $parameters['id'] : null),
            '/_peep/agent/sms' => $this->sms($query, $renderer, $now, $json),
            '/_peep/agent/logs' => $this->logs($query, $renderer, $now, $json, $parameters),
            '/_peep/agent/webhooks' => $this->webhookList($query, $renderer, $now, $json, $parameters),
            default => Responses::error(404, 'not_found', 'Not found. Agent paths: status, cards, report, mail, sms, logs, webhooks, webhooks/send, clear.'),
        };
    }

    private function status(float $now, bool $json): Response
    {
        $projects = [];
        foreach ($this->cards->projects() as $project) {
            $cards = $this->cards->cards($project, self::MAX_SCAN);
            $last = $cards === [] ? null : max(array_map(static fn(Card $card): float => $card->updatedAt, $cards));
            $projects[$project] = ['project' => $project, 'cards' => count($cards), 'last_card_at' => $last, 'mails' => 0, 'sms' => 0];
        }
        foreach ($this->catcher->mails->all() as $mail) {
            $projects[$mail->project] ??= ['project' => $mail->project, 'cards' => 0, 'last_card_at' => null, 'mails' => 0, 'sms' => 0];
            $projects[$mail->project]['mails']++;
        }
        foreach ($this->catcher->sms->all() as $sms) {
            $projects[$sms->project] ??= ['project' => $sms->project, 'cards' => 0, 'last_card_at' => null, 'mails' => 0, 'sms' => 0];
            $projects[$sms->project]['sms']++;
        }

        $deliveries = $this->webhooks?->deliveries() ?? [];
        $webhooks = [
            'rules' => count($this->webhooks?->rules->all() ?? []),
            'calls' => count($deliveries),
            'failed' => count(array_filter($deliveries, static fn(Delivery $delivery): bool => ! $delivery->ok())),
        ];

        if ($json) {
            return Responses::json(200, [
                'app' => 'peep',
                'version' => Version::VERSION,
                'url' => $this->url,
                'now' => $now,
                'receiving' => ! $this->receiving->paused,
                'projects' => array_values($projects),
                'switches' => $this->switches->all(),
                'webhooks' => $webhooks,
            ]);
        }

        $lines = ['Peep viewer ' . Version::VERSION . ' at ' . $this->url . ', ' . ($this->receiving->paused ? 'PAUSED: not receiving new cards (resume in the viewer)' : 'receiving')];
        if ($projects === []) {
            $lines[] = 'Nothing caught yet. Run the code that calls peep(), then ask again.';
        }
        foreach ($projects as $project) {
            $lines[] = '  ' . $project['project'] . ': ' . $project['cards'] . ' cards'
                . ($project['last_card_at'] !== null ? ' (last ' . TextRenderer::ago($now - $project['last_card_at']) . ')' : '')
                . ', ' . $project['mails'] . ' mail, ' . $project['sms'] . ' SMS';
        }
        foreach ($this->switches->all() as $project => $switches) {
            $lines[] = '  query switches for ' . $project . ': ' . implode(', ', array_map(
                static fn(string $name, string|int|null $value): string => $name . '=' . ($value ?? 'default'),
                array_keys($switches),
                $switches,
            ));
        }
        if ($webhooks['rules'] > 0) {
            $lines[] = '  webhooks: ' . $webhooks['rules'] . ' rules, ' . $webhooks['calls'] . ' calls made (' . $webhooks['failed'] . ' failed); see peep webhooks';
        }
        $lines[] = 'cursor: ' . sprintf('%.6F', $now);

        return self::text(implode("\n", $lines) . "\n");
    }

    private function cardList(CardQuery $query, TextRenderer $renderer, float $now, bool $json): Response
    {
        $cards = $query->cards($this->cards->cards($query->project, self::MAX_SCAN), $renderer);

        if ($json) {
            return Responses::json(200, [
                'now' => $now,
                'cards' => array_map(static fn(Card $card): array => [...$card->toArray(), 'text' => $renderer->card($card)], $cards),
            ]);
        }

        if ($cards === []) {
            return self::text("No cards match.\n");
        }

        return self::text(implode("\n\n", array_map($renderer->card(...), $cards)) . "\n");
    }

    private function report(CardQuery $query, TextRenderer $renderer): string
    {
        $all = new CardQuery($query->project, $query->since, $query->request, $query->card, $query->search, CardQuery::MAX_LIMIT, $query->base);

        $cards = $all->cards($this->cards->cards($query->project, self::MAX_SCAN), $renderer);

        return new Report($renderer)->render(
            $cards,
            $query->mails($this->catcher->mails->all()),
            $query->sms($this->catcher->sms->all()),
            $query,
            $this->logErrors($query, $cards, $renderer->now),
            $all->deliveries($this->webhooks?->deliveries() ?? []),
        );
    }

    /**
     * Error-level entries of each project's newest log file, written since the report's window
     * starts: "since", else the last `peep clear`, else the first card shown, else 15 minutes ago.
     *
     * @param list<Card> $cards
     * @return list<array{project: string, file: string, entry: LogEntry}>
     */
    private function logErrors(CardQuery $query, array $cards, float $now): array
    {
        if ($this->logFolders === null) {
            return [];
        }

        $errors = [];
        foreach ($this->logFolders->all() as $folder) {
            if ($query->project !== null && $folder['project'] !== $query->project) {
                continue;
            }

            $files = LogFiles::list($folder['path']) ?? [];
            $file = $files === [] ? null : LogFiles::resolve($folder['path'], $files[0]['name']);
            if ($file === null) {
                continue;
            }

            $from = $query->since ?? $this->logFolders->clearedAt($folder['project'])
                ?? ($cards === [] ? $now - self::LOG_WINDOW_SECONDS : min(array_map(static fn(Card $card): float => $card->createdAt, $cards)));

            foreach (LogFiles::before($file, null, self::LOG_SCAN, new LogParser($folder['timezone']))['entries'] as $entry) {
                if ($entry->isAtLeast('error') && ($entry->timestamp === null || $entry->timestamp >= $from)) {
                    $errors[] = ['project' => $folder['project'], 'file' => $files[0]['name'], 'entry' => $entry];
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $parameters
     */
    private function logs(CardQuery $query, TextRenderer $renderer, float $now, bool $json, array $parameters): Response
    {
        $folders = $this->logFolders?->all() ?? [];
        $folder = null;
        foreach ($folders as $candidate) {
            if ($query->project === null ? count($folders) === 1 : $candidate['project'] === $query->project) {
                $folder = $candidate;
            }
        }

        if ($folder === null) {
            $known = implode(', ', array_map(static fn(array $folder): string => $folder['project'], $folders));

            return self::text($folders === []
                ? "No project has a log folder yet. Send one card from the app (Laravel reports storage/logs), or choose a folder in the viewer's Logs tab.\n"
                : 'Say which project: --project=' . ($query->project === null ? 'NAME (one of ' . $known . ')' : 'NAME; "' . $query->project . '" has no log folder (known: ' . $known . ')') . ".\n", 'text/plain', 404);
        }

        $files = LogFiles::list($folder['path']);
        if ($files === null || $files === []) {
            return self::text(($files === null ? 'Cannot read ' : 'No .log files in ') . $folder['path'] . ".\n", 'text/plain', 404);
        }

        $name = is_string($parameters['file'] ?? null) && $parameters['file'] !== '' ? $parameters['file'] : $files[0]['name'];
        $file = LogFiles::resolve($folder['path'], $name);
        if ($file === null) {
            return self::text('No log file "' . $name . '". Files: ' . implode(', ', array_column($files, 'name')) . ".\n", 'text/plain', 404);
        }

        $level = is_string($parameters['level'] ?? null) && in_array(strtolower($parameters['level']), LogParser::LEVELS, true) ? strtolower($parameters['level']) : 'debug';
        $filtered = $query->since !== null || $query->search !== null || $level !== 'debug';
        $read = LogFiles::before($file, null, $filtered ? LogFiles::MAX_LIMIT : $query->limit, new LogParser($folder['timezone']));

        $entries = array_values(array_filter($read['entries'], static fn(LogEntry $entry): bool => $entry->isAtLeast($level)
            && ($query->since === null || $entry->timestamp === null || $entry->timestamp > $query->since)
            && ($query->search === null || mb_stripos($entry->raw, $query->search) !== false)));
        $entries = array_slice($entries, -$query->limit);

        if ($json) {
            return Responses::json(200, [
                'now' => $now,
                'project' => $folder['project'],
                'file' => $name,
                'files' => $files,
                'entries' => array_map(static fn(LogEntry $entry): array => $entry->toArray(), $entries),
            ]);
        }

        $head = $folder['project'] . ': ' . $folder['path'] . '/' . $name . ' (' . count($entries) . ' entries'
            . ($level !== 'debug' ? ', ' . $level . ' and above' : '') . '; other files: '
            . (count($files) > 1 ? implode(', ', array_slice(array_column(array_filter($files, static fn(array $file): bool => $file['name'] !== $name), 'name'), 0, 10)) : 'none') . ')';

        return self::text($head . "\n\n" . ($entries === [] ? "No entries match.\n" : implode("\n\n", array_map($renderer->logEntry(...), $entries)) . "\n"));
    }

    private function mail(CardQuery $query, TextRenderer $renderer, float $now, bool $json, ?string $mailId): Response
    {
        if ($mailId !== null) {
            $mail = $this->catcher->mails->find($mailId);
            if ($mail === null) {
                return Responses::error(404, 'unknown_mail', 'No such mail; it may have been deleted.');
            }

            return $json ? Responses::json(200, [...$mail->toArray(), 'now' => $now]) : self::text($renderer->mail($mail, true) . "\n");
        }

        $mails = $query->mails($this->catcher->mails->all());

        if ($json) {
            return Responses::json(200, ['now' => $now, 'mails' => array_map(static fn(Mail $mail): array => $mail->summary(), $mails)]);
        }

        return self::text($mails === [] ? "No mail matches.\n" : implode("\n\n", array_map(static fn(Mail $mail): string => $renderer->mail($mail, false), $mails)) . "\n");
    }

    private function sms(CardQuery $query, TextRenderer $renderer, float $now, bool $json): Response
    {
        $messages = $query->sms($this->catcher->sms->all());

        if ($json) {
            return Responses::json(200, ['now' => $now, 'sms' => array_map(static fn(Sms $sms): array => $sms->toArray(), $messages)]);
        }

        return self::text($messages === [] ? "No SMS matches.\n" : implode("\n\n", array_map($renderer->sms(...), $messages)) . "\n");
    }

    /**
     * @param array<mixed> $data
     */
    private function clear(array $data): Response
    {
        $project = JsonBody::project($data);
        $what = is_string($data['what'] ?? null) ? $data['what'] : 'all';

        if (! in_array($what, self::CLEAR_WHAT, true)) {
            return Responses::error(400, 'invalid_request', 'Send {"project": "shop" or null, "what": "all" | "cards" | "mail" | "sms" | "webhooks"}.');
        }

        if ($what === 'all' || $what === 'cards') {
            $this->hub->publish($this->cards->clear($project));
            $this->logFolders?->markCleared($project, microtime(true));
        }
        if ($what === 'all' || $what === 'mail') {
            $this->hub->publish($this->catcher->mails->clear($project));
        }
        if ($what === 'all' || $what === 'sms') {
            $this->hub->publish($this->catcher->sms->clear($project));
        }
        if ($what === 'all' || $what === 'webhooks') {
            $this->webhooks?->clear($project);
        }

        return Responses::json(200, ['cleared' => $what, 'project' => $project, 'now' => microtime(true)]);
    }

    /**
     * The rules an agent can send and the calls made, newest first; id= shows one call in full.
     *
     * @param array<mixed> $parameters
     */
    private function webhookList(CardQuery $query, TextRenderer $renderer, float $now, bool $json, array $parameters): Response
    {
        $runner = $this->webhooks;
        if ($runner === null) {
            return self::text("This viewer has no webhook simulator.\n", 'text/plain', 404);
        }

        $deliveryId = is_string($parameters['id'] ?? null) && $parameters['id'] !== '' ? $parameters['id'] : null;
        if ($deliveryId !== null) {
            $delivery = $runner->find($deliveryId);
            if ($delivery === null) {
                return self::text('No webhook call ' . $deliveryId . '; the viewer keeps the last ' . WebhookRunner::MAX_DELIVERIES . " calls. peep webhooks lists them.\n", 'text/plain', 404);
            }

            return $json ? Responses::json(200, [...self::publicDelivery($delivery), 'now' => $now]) : self::text($renderer->delivery($delivery, true) . "\n");
        }

        $ruleName = is_string($parameters['rule'] ?? null) && $parameters['rule'] !== '' ? $parameters['rule'] : null;
        $rules = array_values(array_filter($runner->rules->all(), static fn(Rule $rule): bool => ($query->project === null || $rule->project === '*' || $rule->project === $query->project)
            && ($ruleName === null || self::isRule($rule, $ruleName))));
        $ruleIds = array_map(static fn(Rule $rule): string => $rule->id, $rules);
        $deliveries = array_values(array_filter($query->deliveries($runner->deliveries()), static fn(Delivery $delivery): bool => $ruleName === null || in_array($delivery->ruleId, $ruleIds, true)));

        if ($json) {
            return Responses::json(200, [
                'now' => $now,
                'rules' => array_map(self::publicRule(...), $rules),
                'deliveries' => array_map(static fn(Delivery $delivery): array => $delivery->summary(), $deliveries),
            ]);
        }

        if ($runner->rules->all() === []) {
            return self::text("No webhook rules yet. The user creates them in the viewer's Webhooks tab (presets for Mailgun, Twilio and Vonage), because a rule holds the app's signing secret.\n");
        }

        $lines = ['Rules (' . count($rules) . '):', ''];
        foreach ($rules as $rule) {
            $lines[] = $renderer->rule($rule);
        }
        if ($rules === []) {
            $lines[] = $ruleName !== null ? 'No rule is called "' . $ruleName . '".' : 'No rule applies to project ' . $query->project . '.';
        }

        $lines[] = '';
        $lines[] = 'Calls made (' . count($deliveries) . ', newest first):';
        $lines[] = '';
        if ($deliveries === []) {
            $lines[] = 'None yet. Send one: peep send-webhook --rule=' . ($rules[0]->id ?? 'ID') . ' --event=' . ($rules[0]->steps[0]['event'] ?? 'EVENT') . ' (with --mail=last or --sms=last for a caught message).';
        }
        foreach ($deliveries as $delivery) {
            $lines[] = $renderer->delivery($delivery, false);
            $lines[] = '';
        }

        return self::text(rtrim(implode("\n", $lines)) . "\n");
    }

    /**
     * Calls the app with a rule: one event now (the answer comes back), or every step of the
     * rule on its schedule when no event is given. Uses a caught mail or SMS ("last" for the
     * newest), or sample data.
     *
     * @param array<mixed> $data
     */
    private function sendWebhook(array $data, TextRenderer $renderer): Response
    {
        $runner = $this->webhooks;
        if ($runner === null) {
            return self::text("This viewer has no webhook simulator.\n", 'text/plain', 404);
        }

        $project = JsonBody::project($data);
        $ruleName = is_string($data['rule'] ?? null) ? trim($data['rule']) : '';
        $known = implode(', ', array_map(static fn(Rule $rule): string => $rule->id . ' "' . $rule->name . '"', $runner->rules->all()));

        if ($ruleName === '') {
            return self::text('Say which rule: --rule=ID or name' . ($known !== '' ? ' (' . $known . ')' : '; none exist yet, the user creates them in the Webhooks tab') . ".\n", 'text/plain', 400);
        }

        $rules = array_values(array_filter($runner->rules->all(), static fn(Rule $rule): bool => self::isRule($rule, $ruleName)
            && ($project === null || $rule->project === '*' || $rule->project === $project)));
        if (count($rules) !== 1) {
            return self::text(($rules === [] ? 'No webhook rule "' . $ruleName . '"' . ($project !== null ? ' for project ' . $project : '') : 'Several rules are called "' . $ruleName . '"; use the id')
                . '. Rules: ' . ($known !== '' ? $known : 'none yet, the user creates them in the Webhooks tab') . ".\n", 'text/plain', $rules === [] ? 404 : 409);
        }
        $rule = $rules[0];

        $event = is_string($data['event'] ?? null) && $data['event'] !== '' ? $data['event'] : null;
        if ($event !== null && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $event) !== 1) {
            return self::text("An event is a name such as delivered or failed (letters, digits, \"_\", \"-\", \".\").\n", 'text/plain', 400);
        }

        $target = $project ?? ($rule->project !== '*' ? $rule->project : null);
        $mailId = is_string($data['mail'] ?? null) && $data['mail'] !== '' ? $data['mail'] : null;
        $smsId = is_string($data['sms'] ?? null) && $data['sms'] !== '' ? $data['sms'] : null;
        $mail = $mailId === null ? null : ($mailId === 'last' ? $this->lastMail($target) : $this->catcher->mails->find($mailId));
        $sms = $smsId === null ? null : ($smsId === 'last' ? $this->lastSms($target) : $this->catcher->sms->find($smsId));

        if ($mailId !== null && $mail === null) {
            return self::text('No mail "' . $mailId . '"' . ($target !== null ? ' in project ' . $target : '') . " in the inbox. peep mail lists the caught mail.\n", 'text/plain', 404);
        }
        if ($smsId !== null && $sms === null) {
            return self::text('No SMS "' . $smsId . '"' . ($target !== null ? ' in project ' . $target : '') . " in the inbox. peep sms lists the caught SMS.\n", 'text/plain', 404);
        }

        $sample = $mail === null && $sms === null;
        $sampleProject = $target ?? 'default';
        $mail ??= $sample ? Variables::sampleMail($sampleProject) : null;
        $sms ??= $sample ? Variables::sampleSms($sampleProject) : null;
        $with = $sample ? 'sample data' : ($mailId !== null ? 'mail ' . $mail?->id : 'SMS ' . $sms?->id);

        if ($event === null) {
            $runner->schedule($rule, $mail, $sms);
            $plan = array_map(static fn(array $step): string => $step['event'] . ($step['delay_ms'] > 0 ? ' after ' . $step['delay_ms'] . ' ms' : ' now'), $rule->steps);

            return self::text('Scheduled ' . count($rule->steps) . ' call' . (count($rule->steps) === 1 ? '' : 's') . ' of "' . $rule->name . '" with ' . $with . ': ' . implode(', ', $plan) . ".\n"
                . 'Read the answers when they are done: peep webhooks --rule=' . $rule->id . ' --since=' . sprintf('%.6F', $renderer->now) . "\n", 'text/plain', 202);
        }

        $delivery = $runner->deliver($rule, $event, $mail, $sms);

        return self::text('Sent "' . $rule->name . '" event ' . $event . ' with ' . $with . ".\n\n" . $renderer->delivery($delivery, true) . "\n", 'text/plain', $delivery->ok() ? 200 : 502);
    }

    private static function isRule(Rule $rule, string $idOrName): bool
    {
        return $rule->id === $idOrName || mb_strtolower($rule->name) === mb_strtolower($idOrName);
    }

    private function lastMail(?string $project): ?Mail
    {
        foreach ($this->catcher->mails->all() as $mail) {
            if ($project === null || $mail->project === $project) {
                return $mail;
            }
        }

        return null;
    }

    private function lastSms(?string $project): ?Sms
    {
        foreach ($this->catcher->sms->all() as $sms) {
            if ($project === null || $sms->project === $project) {
                return $sms;
            }
        }

        return null;
    }

    /**
     * A rule without its signing secret and with its variables' names only.
     *
     * @return array<string, mixed>
     */
    private static function publicRule(Rule $rule): array
    {
        $data = $rule->toArray();
        $data['signature'] = ['type' => $rule->signature['type'], 'secret_set' => $rule->signature['secret'] !== '', 'header' => $rule->signature['header']];
        $data['vars'] = array_keys($rule->vars);

        return $data;
    }

    /**
     * A call with credential-like request headers hidden, as the text shows it.
     *
     * @return array<string, mixed>
     */
    private static function publicDelivery(Delivery $delivery): array
    {
        return [...$delivery->toArray(), 'request' => [...$delivery->request, 'headers' => TextRenderer::hideSecretHeaders($delivery->request['headers'])]];
    }

    private static function text(string $text, string $type = 'text/plain', int $status = 200): Response
    {
        return new Response($status, [
            'content-type' => $type . '; charset=utf-8',
            'cache-control' => 'no-store',
            'x-content-type-options' => 'nosniff',
        ], $text);
    }
}
