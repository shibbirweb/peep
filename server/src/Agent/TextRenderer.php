<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Agent;

use ShibbirWeb\PeepServer\Card\Card;
use ShibbirWeb\PeepServer\Log\LogEntry;
use ShibbirWeb\PeepServer\Mail\Mail;
use ShibbirWeb\PeepServer\Sms\Sms;

/**
 * Plain-text forms of cards, mail and SMS for AI agents and terminals.
 *
 * Follows the UI's "Copy" text (resources/ui/src/lib/text.js) and adds the Laravel
 * payloads, so an agent reads the same thing a developer sees in the viewer.
 */
final readonly class TextRenderer
{
    public const int MAX_CARD_CHARS = 4000;

    private const int MAX_FRAMES = 12;

    private const int MAX_BODY_CHARS = 2000;

    /**
     * @param string|null $base Project root; paths under it are shown relative to it.
     */
    public function __construct(
        public float $now,
        private ?string $base = null,
    ) {}

    public function card(Card $card): string
    {
        $data = $card->toArray();
        $origin = $data['origin'];

        $head = ['[' . self::shortId($card->id) . ']', self::ago($this->now - $card->updatedAt), $data['request']['label'] !== '' ? $data['request']['label'] : $data['request']['kind']];
        if ($origin !== null) {
            $head[] = $this->path($origin['file']) . ':' . $origin['line'] . ($origin['function'] !== null ? ' in ' . ($origin['class'] !== null ? $origin['class'] . '::' : '') . $origin['function'] . '()' : '');
        }
        if ($data['color'] !== null) {
            $head[] = $data['color'];
        }
        if ($data['label'] !== null) {
            $head[] = '"' . $data['label'] . '"';
        }

        $body = array_values(array_filter(
            array_map($this->payload(...), $data['payloads']),
            static fn(string $text): bool => $text !== '',
        ));

        $text = implode('  ', $head);
        if ($body !== []) {
            $text .= "\n" . self::indent(implode("\n", $body), '  ');
        }

        return self::cut($text, self::MAX_CARD_CHARS);
    }

    /**
     * @param array{type: string, content: array<string, mixed>, origin: mixed} $payload
     */
    public function payload(array $payload): string
    {
        $content = $payload['content'];

        return match ($payload['type']) {
            'log', 'replace' => implode("\n", array_map(fn(mixed $node): string => $this->node($node), self::list($content['values'] ?? []))),
            'text', 'html', 'xml' => self::string($content[$payload['type']] ?? ''),
            'json' => self::prettyJson(self::string($content['json'] ?? '')),
            'table' => trim(self::string($content['label'] ?? '') . "\n" . $this->node($content['rows'] ?? null)),
            'query' => $this->query($content),
            'query_count' => self::int($content['count'] ?? 0) . ' ' . (self::int($content['count'] ?? 0) === 1 ? 'query' : 'queries') . ' in ' . self::number($content['time_ms'] ?? 0) . ' ms',
            'event' => trim('event ' . self::string($content['name'] ?? '') . "\n" . (isset($content['payload']) ? self::indent($this->node($content['payload']), '  ') : '')),
            'job' => $this->job($content),
            'cache' => trim('cache ' . self::string($content['action'] ?? '') . ' ' . self::string($content['key'] ?? '') . self::details([self::string($content['store'] ?? ''), isset($content['ttl']) ? self::number($content['ttl']) . ' s' : '']) . "\n" . (isset($content['value']) ? self::indent($this->node($content['value']), '  ') : '')),
            'http' => $this->http($content),
            'view' => 'view ' . self::string($content['name'] ?? '') . (isset($content['path']) ? ' (' . $this->path(self::string($content['path'])) . ')' : ''),
            'request' => $this->request($content),
            'log_message' => trim('log ' . self::string($content['level'] ?? '') . ': ' . self::string($content['message'] ?? '') . "\n" . (is_array($content['context'] ?? null) && self::list($content['context']['children'] ?? null) !== [] ? self::indent($this->node($content['context']), '  ') : '')),
            'mail_preview' => 'mail preview "' . self::string($content['subject'] ?? '') . '"' . self::details(['to ' . implode(', ', array_map(self::string(...), self::list($content['to'] ?? [])))]),
            'exception' => $this->exception($content),
            'trace', 'caller' => implode("\n", $this->frames($content['frames'] ?? [])),
            'measure' => $this->measure($content),
            'count' => 'count ' . (isset($content['name']) ? self::string($content['name']) . ': ' : '') . self::int($content['count'] ?? 0),
            'notify' => 'notify: ' . self::string($content['message'] ?? ''),
            'link' => trim(self::string($content['label'] ?? '') . ' ' . self::string($content['url'] ?? '')),
            'image' => 'image ' . (str_starts_with(self::string($content['src'] ?? ''), 'data:') ? '(inline data)' : self::string($content['src'] ?? '')),
            'file' => $this->file($content),
            'separator' => '----',
            'new_screen' => 'new screen: ' . self::string($content['title'] ?? ''),
            'lock' => 'paused at lock "' . self::string($content['name'] ?? '') . '"',
            'color', 'label', 'size', 'hide', 'expand' => '',
            default => $payload['type'] . ' ' . (string) json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        };
    }

    /**
     * One value tree node, children indented below their parent.
     */
    public function node(mixed $node, string $indent = ''): string
    {
        if (! is_array($node)) {
            return 'null';
        }

        if (($node['type'] ?? null) === 'string') {
            return self::string($node['value'] ?? '') . (isset($node['truncated']) ? ' ... +' . self::int($node['truncated']) . ' chars' : '');
        }

        $head = self::summary($node);
        $children = self::list($node['children'] ?? null);

        if ($children === []) {
            return $head;
        }

        $inner = $indent . '  ';
        $lines = [$head];
        foreach ($children as $child) {
            $childNode = is_array($child) ? ($child['node'] ?? null) : null;
            $key = is_array($child) && (is_string($child['key'] ?? null) || is_int($child['key'] ?? null)) ? (string) $child['key'] : '?';
            $value = is_array($childNode) && ($childNode['type'] ?? null) === 'string' ? self::summary($childNode) : $this->node($childNode, $inner);
            $lines[] = $inner . $key . ': ' . $value;
        }

        $truncated = self::int($node['truncated'] ?? 0);
        if ($truncated > 0) {
            $lines[] = $inner . '... ' . $truncated . ' more';
        }

        return implode("\n", $lines);
    }

    /**
     * The one-line head of a node, as the UI shows it.
     *
     * @param array<mixed> $node
     */
    public static function summary(array $node): string
    {
        return match ($node['type'] ?? null) {
            'null' => 'null',
            'bool' => ($node['value'] ?? false) === true ? 'true' : 'false',
            'int', 'float' => self::string($node['value'] ?? ''),
            'string' => (string) json_encode(self::string($node['value'] ?? ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . (isset($node['truncated']) ? ' ... +' . self::int($node['truncated']) . ' chars' : '') . (($node['redacted'] ?? false) === true ? ' (redacted)' : ''),
            'binary' => 'binary(' . self::int($node['length'] ?? 0) . ' bytes)',
            'array' => 'array(' . self::int($node['length'] ?? 0) . ')',
            'object' => self::string($node['class'] ?? 'object') . ' #' . self::int($node['id'] ?? 0)
                . (($node['lazy'] ?? false) === true ? ' (lazy, not initialized)' : (isset($node['summary']) ? ' ' . self::string($node['summary']) : '')),
            'ref' => '-> #' . self::int($node['id'] ?? 0),
            'resource' => 'resource(' . self::string($node['value'] ?? '') . ')',
            'max_depth' => isset($node['class']) ? self::string($node['class']) . ' ...' : 'array(' . self::int($node['length'] ?? 0) . ') ...',
            default => self::string($node['type'] ?? 'unknown'),
        };
    }

    public function mail(Mail $mail, bool $full): string
    {
        $lines = [
            '[' . $mail->id . '] ' . self::ago($this->now - $mail->receivedAt) . '  ' . $mail->project . '  via ' . $mail->source,
            'From: ' . $mail->from,
            'To: ' . implode(', ', $mail->to),
        ];
        if ($mail->cc !== []) {
            $lines[] = 'Cc: ' . implode(', ', $mail->cc);
        }
        if ($mail->bcc !== []) {
            $lines[] = 'Bcc: ' . implode(', ', $mail->bcc);
        }
        $lines[] = 'Subject: ' . $mail->subject;

        $attachments = array_values(array_filter($mail->attachments, static fn(array $attachment): bool => ! $attachment['inline']));
        if ($attachments !== []) {
            $lines[] = 'Attachments: ' . implode(', ', array_map(static fn(array $attachment): string => $attachment['filename'] . ' (' . $attachment['content_type'] . ', ' . $attachment['size'] . ' bytes)', $attachments));
        }

        $body = $mail->text ?? self::htmlToText($mail->html ?? '');
        if (! $full) {
            $preview = trim((string) preg_replace('/\s+/u', ' ', $body));

            return implode("\n", $lines) . ($preview !== '' ? "\n" . mb_substr($preview, 0, 160) : '');
        }

        return implode("\n", $lines) . "\n\n" . self::cut(trim($body), self::MAX_BODY_CHARS * 5);
    }

    /**
     * One log entry: its head line, the exception with its first frames, and the context as JSON.
     */
    public function logEntry(LogEntry $entry): string
    {
        $lines = ['[' . $entry->time . '] ' . strtoupper($entry->level) . ' ' . $entry->channel . ': ' . $entry->message];

        if ($entry->exception !== null) {
            $exception = $entry->exception;
            $lines[] = '  ' . $exception['class'] . ': ' . $exception['message'] . ' at ' . $this->path($exception['file']) . ':' . $exception['line'];
            foreach (array_slice($exception['frames'], 0, self::MAX_FRAMES) as $frame) {
                $lines[] = '    at ' . $frame['call'] . ($frame['file'] !== null ? ' ' . $this->path($frame['file']) . ':' . $frame['line'] : '');
            }
            if (count($exception['frames']) > self::MAX_FRAMES) {
                $lines[] = '    ... ' . (count($exception['frames']) - self::MAX_FRAMES) . ' more frames';
            }
        }

        $context = is_array($entry->context) ? array_diff_key($entry->context, $entry->exception !== null ? ['exception' => true] : []) : $entry->context;
        if ($context !== null && $context !== []) {
            $lines[] = self::indent(self::cut((string) json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), self::MAX_BODY_CHARS), '  ');
        }

        return implode("\n", $lines);
    }

    public function sms(Sms $sms): string
    {
        return '[' . $sms->id . '] ' . self::ago($this->now - $sms->receivedAt) . '  ' . $sms->project . '  via ' . $sms->source
            . "\nFrom: " . $sms->from . '  To: ' . $sms->to
            . "\n" . $sms->body;
    }

    public function path(string $file): string
    {
        if ($this->base === null || $this->base === '') {
            return $file;
        }

        $base = rtrim($this->base, '/\\') . '/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    /**
     * "Puts query bindings into the SQL for display (never for running)", like the UI.
     *
     * @param list<mixed> $bindings
     */
    public static function sql(string $sql, array $bindings): string
    {
        if ($bindings === []) {
            return $sql;
        }

        $result = '';
        $index = 0;
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                $result .= $char;
            } elseif ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $result .= $char;
            } elseif ($char === '?' && $index < count($bindings)) {
                $result .= self::quote($bindings[$index]);
                $index++;
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    public static function ago(float $seconds): string
    {
        $seconds = max(0, (int) round($seconds));

        return match (true) {
            $seconds < 60 => $seconds . 's ago',
            $seconds < 3600 => intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's ago',
            default => intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm ago',
        };
    }

    public static function shortId(string $id): string
    {
        return substr(str_replace('-', '', $id), 0, 8);
    }

    public static function cut(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit) . "\n... (" . (mb_strlen($text) - $limit) . ' more characters; ask for this card alone or use --json)';
    }

    public static function indent(string $text, string $indent): string
    {
        return $indent . str_replace("\n", "\n" . $indent, $text);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function query(array $content): string
    {
        $sql = self::sql(self::string($content['sql'] ?? ''), self::list($content['bindings'] ?? []));

        return 'query ' . $sql . self::details([
            isset($content['time_ms']) ? self::number($content['time_ms']) . ' ms' : '',
            self::string($content['connection'] ?? ''),
            ($content['slow'] ?? false) === true ? 'SLOW' : '',
            self::int($content['duplicate_count'] ?? 0) > 1 ? 'DUPLICATE, ran ' . self::int($content['duplicate_count']) . ' times' : '',
        ]);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function job(array $content): string
    {
        $text = 'job ' . strtoupper(self::string($content['status'] ?? '')) . ' ' . self::string($content['name'] ?? '') . self::details([
            isset($content['queue']) ? 'queue ' . self::string($content['queue']) : '',
            self::string($content['connection'] ?? ''),
            isset($content['attempts']) ? 'attempt ' . self::int($content['attempts']) : '',
            isset($content['time_ms']) ? self::number($content['time_ms']) . ' ms' : '',
        ]);

        return isset($content['exception']) ? $text . "\n  " . self::string($content['exception']) : $text;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function http(array $content): string
    {
        $status = self::int($content['status'] ?? 0);
        $result = match (true) {
            $status > 0 => (string) $status,
            isset($content['error']) => 'ERROR ' . self::string($content['error']),
            default => 'pending',
        };

        $text = 'http ' . self::string($content['method'] ?? '') . ' ' . self::string($content['url'] ?? '') . ' -> ' . $result
            . (isset($content['time_ms']) ? ' (' . self::number($content['time_ms']) . ' ms)' : '');

        if ($status >= 400 && isset($content['response_body'])) {
            $text .= "\n  response: " . self::cut(self::string($content['response_body']), self::MAX_BODY_CHARS);
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function request(array $content): string
    {
        $text = 'request ' . self::string($content['method'] ?? '') . ' ' . self::string($content['uri'] ?? '') . ' -> ' . self::int($content['status'] ?? 0) . self::details([
            isset($content['route']) ? 'route ' . self::string($content['route']) : '',
            self::string($content['action'] ?? ''),
            isset($content['time_ms']) ? self::number($content['time_ms']) . ' ms' : '',
            isset($content['peak_memory']) ? 'peak ' . round(self::int($content['peak_memory']) / 1048576, 1) . ' MB' : '',
        ]);

        if (isset($content['input']) && self::list(is_array($content['input']) ? ($content['input']['children'] ?? null) : null) !== []) {
            $text .= "\n  input: " . $this->node($content['input'], '  ');
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function exception(array $content): string
    {
        $lines = [
            'EXCEPTION ' . self::string($content['class'] ?? '') . ': ' . self::string($content['message'] ?? ''),
            '  at ' . $this->path(self::string($content['file'] ?? '')) . ':' . self::int($content['line'] ?? 0),
            ...$this->frames($content['frames'] ?? []),
        ];

        foreach (self::list($content['previous'] ?? []) as $previous) {
            if (is_array($previous)) {
                $lines[] = 'caused by ' . self::string($previous['class'] ?? '') . ': ' . self::string($previous['message'] ?? '') . ' at ' . $this->path(self::string($previous['file'] ?? '')) . ':' . self::int($previous['line'] ?? 0);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function frames(mixed $frames): array
    {
        $frames = self::list($frames);
        $lines = [];

        foreach (array_slice($frames, 0, self::MAX_FRAMES) as $frame) {
            if (! is_array($frame)) {
                continue;
            }

            $where = isset($frame['file']) ? $this->path(self::string($frame['file'])) . ':' . self::int($frame['line'] ?? 0) : '[internal]';
            $call = isset($frame['function']) ? (isset($frame['class']) ? self::string($frame['class']) . '::' : '') . self::string($frame['function']) . '() ' : '';
            $lines[] = '  at ' . $call . $where;
        }

        if (count($frames) > self::MAX_FRAMES) {
            $lines[] = '  ... ' . (count($frames) - self::MAX_FRAMES) . ' more frames';
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function measure(array $content): string
    {
        if (($content['started'] ?? false) === true) {
            return 'stopwatch started';
        }

        return 'measure ' . self::number($content['total_ms'] ?? 0) . ' ms total'
            . (isset($content['since_last_ms']) ? ', ' . self::number($content['since_last_ms']) . ' ms since last' : '')
            . (isset($content['peak_memory']) ? ', peak ' . round(self::int($content['peak_memory']) / 1048576, 1) . ' MB' : '');
    }

    /**
     * @param array<string, mixed> $content
     */
    private function file(array $content): string
    {
        $head = 'file ' . $this->path(self::string($content['path'] ?? '')) . ' (' . self::int($content['size'] ?? 0) . ' bytes)';

        if (isset($content['error'])) {
            return $head . ': ' . self::string($content['error']);
        }

        if (($content['binary'] ?? false) === true) {
            return $head . ', binary';
        }

        return $head . "\n" . self::indent(self::cut(self::string($content['contents'] ?? ''), self::MAX_BODY_CHARS), '  ');
    }

    /**
     * @param list<string> $parts
     */
    private static function details(array $parts): string
    {
        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));

        return $parts === [] ? '' : ' (' . implode(', ', $parts) . ')';
    }

    private static function prettyJson(string $json): string
    {
        $decoded = json_decode($json, true);

        return $decoded === null && $json !== 'null' ? $json : (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function htmlToText(string $html): string
    {
        $text = (string) preg_replace(['#<(script|style)\b[^>]*>.*?</\1>#is', '#<br\s*/?>#i', '#</(p|div|h[1-6]|li|tr)>#i'], ['', "\n", "\n"], $html);

        return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function quote(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default => "'" . str_replace("'", "''", self::string($value)) . "'",
        };
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : (is_bool($value) ? ($value ? 'true' : 'false') : ''));
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : (is_float($value) || (is_string($value) && is_numeric($value)) ? (int) $value : 0);
    }

    private static function number(mixed $value): string
    {
        return is_int($value) || is_float($value) ? (string) round($value, 2) : '0';
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
