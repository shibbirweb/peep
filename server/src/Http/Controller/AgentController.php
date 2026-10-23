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
 * POST /_peep/agent/clear          {"project": "shop" or null, "what": "cards" | "mail" | "sms" | "all"}
 */
final readonly class AgentController implements RequestHandler
{
    private const int MAX_SCAN = 100000;

    public function __construct(
        private CardStore $cards,
        private Catcher $catcher,
        private EventHub $hub,
        private Receiving $receiving,
        private SwitchStore $switches,
        private string $url,
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
            default => Responses::error(404, 'not_found', 'Not found. Agent paths: status, cards, report, mail, sms, clear.'),
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

        return new Report($renderer)->render(
            $all->cards($this->cards->cards($query->project, self::MAX_SCAN), $renderer),
            $query->mails($this->catcher->mails->all()),
            $query->sms($this->catcher->sms->all()),
            $query,
        );
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
        }
        if ($what === 'all' || $what === 'mail') {
            $this->hub->publish($this->catcher->mails->clear($project));
        }
        if ($what === 'all' || $what === 'sms') {
            $this->hub->publish($this->catcher->sms->clear($project));
        }

        return Responses::json(200, ['cleared' => $what, 'project' => $project, 'now' => microtime(true)]);
    }

    private static function text(string $text, string $type = 'text/plain'): Response
    {
        return new Response(200, [
            'content-type' => $type . '; charset=utf-8',
            'cache-control' => 'no-store',
            'x-content-type-options' => 'nosniff',
        ], $text);
    }
}
