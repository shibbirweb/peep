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
 * POST /_peep/agent/clear          {"project": "shop" or null, "what": "cards" | "mail" | "sms" | "all"}
 */
final readonly class AgentController implements RequestHandler
{
    private const int MAX_SCAN = 100000;

    private const int LOG_SCAN = 500;

    private const float LOG_WINDOW_SECONDS = 900.0;

    public function __construct(
        private CardStore $cards,
        private Catcher $catcher,
        private EventHub $hub,
        private Receiving $receiving,
        private SwitchStore $switches,
        private string $url,
        private ?LogFolders $logFolders = null,
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
            return $path === '/_peep/agent/clear'
                ? $this->clear(JsonBody::read($request))
                : Responses::error(404, 'not_found', 'Not found.');
        }

        return match ($path) {
            '/_peep/agent/status' => $this->status($now, $json),
            '/_peep/agent/cards' => $this->cardList($query, $renderer, $now, $json),
            '/_peep/agent/report' => self::text($this->report($query, $renderer), 'text/markdown'),
            '/_peep/agent/mail' => $this->mail($query, $renderer, $now, $json, is_string($parameters['id'] ?? null) ? $parameters['id'] : null),
            '/_peep/agent/sms' => $this->sms($query, $renderer, $now, $json),
            '/_peep/agent/logs' => $this->logs($query, $renderer, $now, $json, $parameters),
            default => Responses::error(404, 'not_found', 'Not found. Agent paths: status, cards, report, mail, sms, logs, clear.'),
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

        if ($json) {
            return Responses::json(200, [
                'app' => 'peep',
                'version' => Version::VERSION,
                'url' => $this->url,
                'now' => $now,
                'receiving' => ! $this->receiving->paused,
                'projects' => array_values($projects),
                'switches' => $this->switches->all(),
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

        if (! in_array($what, ['all', 'cards', 'mail', 'sms'], true)) {
            return Responses::error(400, 'invalid_request', 'Send {"project": "shop" or null, "what": "all" | "cards" | "mail" | "sms"}.');
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

        return Responses::json(200, ['cleared' => $what, 'project' => $project, 'now' => microtime(true)]);
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
