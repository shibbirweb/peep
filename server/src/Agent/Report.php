<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Agent;

use ShibbirWeb\PeepServer\Card\Card;
use ShibbirWeb\PeepServer\Log\LogEntry;
use ShibbirWeb\PeepServer\Mail\Mail;
use ShibbirWeb\PeepServer\Sms\Sms;
use ShibbirWeb\PeepServer\Version;

/**
 * A Markdown debug report of what Peep caught: the problems first (exceptions, failed jobs,
 * slow and duplicate queries, failed HTTP calls, error logs, error responses), then every
 * request with its cards, then mail and SMS. An AI agent shows it to the user as it is.
 */
final readonly class Report
{
    public const int MAX_CARDS_PER_REQUEST = 40;

    private const int MAX_PROBLEMS_PER_KIND = 10;

    private const array ERROR_LEVELS = ['error', 'critical', 'alert', 'emergency'];

    public function __construct(
        private TextRenderer $renderer,
    ) {}

    /**
     * @param list<Card> $cards Oldest first.
     * @param list<Mail> $mails Newest first.
     * @param list<Sms> $messages Newest first.
     * @param list<array{project: string, file: string, entry: LogEntry}> $logErrors Error-level log entries in the window.
     */
    public function render(array $cards, array $mails, array $messages, CardQuery $query, array $logErrors = []): string
    {
        $requests = self::byRequest($cards);
        $problems = $this->problems($cards, $requests);
        foreach ($logErrors as $logError) {
            $entry = $logError['entry'];
            $problems['Log file errors'][] = strtoupper($entry->level) . ' `' . self::oneLine($entry->exception !== null ? $entry->exception['class'] . ': ' . $entry->exception['message'] : $entry->message) . '`'
                . ($entry->exception !== null ? ' at ' . $this->renderer->path($entry->exception['file']) . ':' . $entry->exception['line'] : '')
                . ' (' . $logError['project'] . ', ' . $logError['file'] . ', ' . $entry->time . ')';
        }
        $queries = self::queryCounts($cards);

        $scope = [
            'Project: ' . ($query->project ?? 'all'),
            'Window: ' . ($query->since !== null ? 'since ' . date('Y-m-d H:i:s', (int) $query->since) . ' (viewer time)' : 'everything the viewer holds'),
            'Peep ' . Version::VERSION,
        ];

        $lines = [
            '# Peep debug report',
            '',
            implode(' | ', $scope),
            '',
            '## Summary',
            '',
            '- ' . self::plural(count($requests), 'request') . ', ' . self::plural(count($cards), 'card'),
            '- ' . self::plural($queries['total'], 'query card') . ' (' . $queries['slow'] . ' slow, ' . $queries['duplicate'] . ' repeated)'
                . ', ' . self::plural(count($problems['Exceptions'] ?? []), 'exception') . ', ' . self::plural(count($problems['Failed jobs'] ?? []), 'failed job'),
            '- ' . self::plural(count($mails), 'mail', 'mails') . ', ' . self::plural(count($messages), 'SMS', 'SMS') . ', ' . self::plural(count($logErrors), 'log file error'),
            '',
            '## Problems',
            '',
        ];

        if ($problems === []) {
            $lines[] = 'None found: no exceptions, failed jobs, slow or duplicate queries, failed HTTP calls, error logs, log file errors or error responses.';
            $lines[] = '';
        }

        foreach ($problems as $kind => $items) {
            $lines[] = '### ' . $kind;
            $lines[] = '';
            foreach (array_slice($items, 0, self::MAX_PROBLEMS_PER_KIND) as $item) {
                $lines[] = '- ' . $item;
            }
            if (count($items) > self::MAX_PROBLEMS_PER_KIND) {
                $lines[] = '- ... ' . (count($items) - self::MAX_PROBLEMS_PER_KIND) . ' more';
            }
            $lines[] = '';
        }

        $lines[] = '## Requests';
        $lines[] = '';

        if ($requests === []) {
            $lines[] = 'No cards. Check that the app sends to this viewer (`peep status`), then run the code again.';
            $lines[] = '';
        }

        foreach ($requests as $requestCards) {
            $first = $requestCards[0];
            $lines[] = '### ' . ($first->request['label'] !== '' ? $first->request['label'] : $first->request['kind'])
                . ' (' . $first->request['kind'] . ', ' . $first->project . ', request ' . TextRenderer::shortId($first->request['id']) . ', ' . TextRenderer::ago($this->now() - $first->createdAt) . ')';
            $lines[] = '';
            $lines[] = '```text';
            $lines[] = implode("\n\n", array_map(
                fn(Card $card): string => str_replace('```', "'''", $this->renderer->card($card)),
                array_slice($requestCards, 0, self::MAX_CARDS_PER_REQUEST),
            ));
            if (count($requestCards) > self::MAX_CARDS_PER_REQUEST) {
                $lines[] = '... ' . (count($requestCards) - self::MAX_CARDS_PER_REQUEST) . ' more cards: peep cards --request=' . TextRenderer::shortId($first->request['id']);
            }
            $lines[] = '```';
            $lines[] = '';
        }

        if ($mails !== []) {
            $lines[] = '## Mail';
            $lines[] = '';
            foreach ($mails as $mail) {
                $lines[] = '```text';
                $lines[] = str_replace('```', "'''", $this->renderer->mail($mail, false));
                $lines[] = '```';
                $lines[] = '';
            }
        }

        if ($messages !== []) {
            $lines[] = '## SMS';
            $lines[] = '';
            foreach ($messages as $sms) {
                $lines[] = '```text';
                $lines[] = str_replace('```', "'''", $this->renderer->sms($sms));
                $lines[] = '```';
                $lines[] = '';
            }
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    /**
     * @param list<Card> $cards Oldest first.
     * @return list<non-empty-list<Card>> Requests in the order they started.
     */
    private static function byRequest(array $cards): array
    {
        $requests = [];
        foreach ($cards as $card) {
            $requests[$card->request['id']][] = $card;
        }

        return array_values($requests);
    }

    /**
     * @param list<Card> $cards
     * @param list<non-empty-list<Card>> $requests
     * @return array<string, list<string>>
     */
    private function problems(array $cards, array $requests): array
    {
        $problems = [];
        $duplicates = [];

        foreach ($cards as $card) {
            $where = ' (' . ($card->request['label'] !== '' ? $card->request['label'] : $card->request['kind']) . ', card ' . TextRenderer::shortId($card->id) . ')';

            foreach ($card->toArray()['payloads'] as $payload) {
                $content = $payload['content'];

                switch ($payload['type']) {
                    case 'exception':
                        $problems['Exceptions'][] = '`' . self::string($content['class'] ?? '') . ': ' . self::oneLine(self::string($content['message'] ?? '')) . '` at '
                            . $this->renderer->path(self::string($content['file'] ?? '')) . ':' . self::string($content['line'] ?? '') . $where;
                        break;

                    case 'job':
                        if (($content['status'] ?? null) === 'failed') {
                            $problems['Failed jobs'][] = '`' . self::string($content['name'] ?? '') . '`' . (isset($content['exception']) ? ': ' . self::oneLine(self::string($content['exception'])) : '') . $where;
                        }
                        break;

                    case 'query':
                        $sql = '`' . self::oneLine(TextRenderer::sql(self::string($content['sql'] ?? ''), is_array($content['bindings'] ?? null) ? array_values($content['bindings']) : [])) . '`';
                        if (($content['slow'] ?? false) === true) {
                            $problems['Slow queries'][] = self::string($content['time_ms'] ?? '?') . ' ms ' . $sql . $where;
                        }
                        if (is_int($content['duplicate_count'] ?? null) && $content['duplicate_count'] > 1) {
                            $duplicates[self::string($content['sql'] ?? '')] = 'ran ' . $content['duplicate_count'] . ' times in one request: ' . $sql . $where;
                        }
                        break;

                    case 'http':
                        $status = is_int($content['status'] ?? null) ? $content['status'] : 0;
                        if ($status >= 400 || isset($content['error'])) {
                            $problems['Failed HTTP calls'][] = '`' . self::string($content['method'] ?? '') . ' ' . self::string($content['url'] ?? '') . '` -> '
                                . ($status > 0 ? $status : self::string($content['error'] ?? '')) . $where;
                        }
                        break;

                    case 'log_message':
                        if (in_array($content['level'] ?? null, self::ERROR_LEVELS, true)) {
                            $problems['Error logs'][] = self::string($content['level']) . ': ' . self::oneLine(self::string($content['message'] ?? '')) . $where;
                        }
                        break;

                    case 'request':
                        if (is_int($content['status'] ?? null) && $content['status'] >= 400) {
                            $problems['Error responses'][] = '`' . self::string($content['method'] ?? '') . ' ' . self::string($content['uri'] ?? '') . '` -> ' . $content['status'] . $where;
                        }
                        break;
                }
            }
        }

        if ($duplicates !== []) {
            $problems['Duplicate queries'] = array_values($duplicates);
        }

        $order = ['Exceptions', 'Failed jobs', 'Error responses', 'Failed HTTP calls', 'Error logs', 'Log file errors', 'Slow queries', 'Duplicate queries'];

        return array_filter(array_merge(array_fill_keys($order, []), $problems), static fn(array $items): bool => $items !== []);
    }

    /**
     * @param list<Card> $cards
     * @return array{total: int, slow: int, duplicate: int}
     */
    private static function queryCounts(array $cards): array
    {
        $counts = ['total' => 0, 'slow' => 0, 'duplicate' => 0];

        foreach ($cards as $card) {
            foreach ($card->toArray()['payloads'] as $payload) {
                if ($payload['type'] !== 'query') {
                    continue;
                }

                $counts['total']++;
                $counts['slow'] += ($payload['content']['slow'] ?? false) === true ? 1 : 0;
                $counts['duplicate'] += is_int($payload['content']['duplicate_count'] ?? null) && $payload['content']['duplicate_count'] > 1 ? 1 : 0;
            }
        }

        return $counts;
    }

    private function now(): float
    {
        return $this->renderer->now;
    }

    private static function plural(int $count, string $one, ?string $many = null): string
    {
        return $count . ' ' . ($count === 1 ? $one : $many ?? $one . 's');
    }

    private static function oneLine(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > 300 ? mb_substr($text, 0, 300) . '...' : $text;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }
}
