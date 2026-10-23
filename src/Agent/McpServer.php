<?php

namespace ShibbirWeb\Peep\Agent;

use ShibbirWeb\Peep\Version;

/**
 * `peep mcp`: a Model Context Protocol server over stdio (one JSON-RPC message per line),
 * so AI tools that speak MCP (Claude, Cursor, Codex, Gemini CLI, VS Code and others) can
 * read what Peep caught. Every tool is a thin call to ViewerApi.
 */
final class McpServer
{
    public const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private const INSTRUCTIONS = <<<'TEXT'
Peep is a debugger for PHP apps: the app sends values, queries, exceptions, jobs, logs, mail and SMS to the Peep viewer, and these tools read them.
To debug: call peep_status to check the viewer runs; add peep($value) calls to the code you suspect (peep($x)->label('why') helps you find your own cards); call peep_clear, reproduce the problem (open the page, run the command or the test); then call peep_report and read the Problems section first. Show the report to the user when they asked for one.
Remove every peep() call you added before you finish (vendor/bin/peep-clean --fix lists and removes them).
TEXT;

    private const FILTER_PROPERTIES = [
        'project' => ['type' => 'string', 'description' => 'One project (the app\'s PEEP_PROJECT); every project when left out'],
        'since' => ['type' => 'string', 'description' => 'Only what came after: an age such as 30s, 5m, 2h, or a unix time'],
        'request' => ['type' => 'string', 'description' => 'One request: the request id shown in cards and reports'],
        'search' => ['type' => 'string', 'description' => 'Only what contains this text (case-insensitive)'],
        'limit' => ['type' => 'integer', 'description' => 'How many of the newest (default 50)'],
    ];

    /**
     * @var ViewerApi
     */
    private $viewer;

    public function __construct(ViewerApi $viewer)
    {
        $this->viewer = $viewer;
    }

    /**
     * Answers messages from $input until it closes.
     *
     * @param resource $input
     * @param resource $output
     */
    public function serve($input, $output): void
    {
        while (($line = fgets($input)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $answer = $this->handle($line);
            if ($answer !== null) {
                fwrite($output, $answer . "\n");
                fflush($output);
            }
        }
    }

    /**
     * One JSON-RPC message in, the JSON answer out (null for notifications).
     */
    public function handle(string $line): ?string
    {
        $message = json_decode($line, true);

        if (! is_array($message)) {
            return self::encode(self::error(null, -32700, 'Parse error'));
        }

        if (array_keys($message) === range(0, count($message) - 1)) {
            return self::encode(self::error(null, -32600, 'Batches are not supported'));
        }

        $id = array_key_exists('id', $message) && (is_int($message['id']) || is_string($message['id'])) ? $message['id'] : null;
        $method = isset($message['method']) && is_string($message['method']) ? $message['method'] : null;
        $params = isset($message['params']) && is_array($message['params']) ? $message['params'] : [];

        if ($method === null) {
            return $id === null ? null : self::encode(self::error($id, -32600, 'Invalid request'));
        }

        if ($id === null) {
            return null;
        }

        switch ($method) {
            case 'initialize':
                $requested = isset($params['protocolVersion']) && is_string($params['protocolVersion']) ? $params['protocolVersion'] : '';

                return self::encode(self::result($id, [
                    'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0],
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'peep', 'title' => 'Peep PHP debugger', 'version' => Version::VERSION],
                    'instructions' => self::INSTRUCTIONS,
                ]));

            case 'ping':
                return self::encode(self::result($id, new \stdClass()));

            case 'tools/list':
                return self::encode(self::result($id, ['tools' => self::tools()]));

            case 'tools/call':
                $name = isset($params['name']) && is_string($params['name']) ? $params['name'] : '';
                $arguments = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];

                $result = $this->call($name, $arguments);
                if ($result === null) {
                    return self::encode(self::error($id, -32602, 'Unknown tool: ' . $name));
                }

                return self::encode(self::result($id, [
                    'content' => [['type' => 'text', 'text' => $result['text']]],
                    'isError' => ! $result['ok'],
                ]));

            default:
                return self::encode(self::error($id, -32601, 'Method not found: ' . $method));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function tools(): array
    {
        $filters = self::FILTER_PROPERTIES;

        return [
            self::tool('peep_status', 'Is the Peep viewer running? Lists the projects and how many cards, mail and SMS each caught. Call this first.', []),
            self::tool('peep_report', 'A Markdown debug report of what Peep caught: problems first (exceptions, failed jobs, error responses, failed HTTP calls, error logs, slow and duplicate queries), then every request with its cards, then mail and SMS. Use it to explain a bug and show it to the user.', $filters),
            self::tool('peep_cards', 'The newest cards (peep() output, queries, exceptions, jobs, logs) as text, oldest first. Narrow them with the filters.', $filters + [
                'card' => ['type' => 'string', 'description' => 'One card: the id in square brackets at the start of a card'],
            ]),
            self::tool('peep_mail', 'Mail the app sent, caught by Peep. Give an id to read one mail with its body.', $filters + [
                'id' => ['type' => 'string', 'description' => 'One mail, with its body (the id in square brackets)'],
            ]),
            self::tool('peep_sms', 'SMS the app sent, caught by Peep.', $filters),
            self::tool('peep_wait', 'Waits for new cards (after the user or a command runs the code), then returns them. Use it when something runs in the background, like a queued job.', [
                'project' => $filters['project'],
                'search' => $filters['search'],
                'timeout_seconds' => ['type' => 'integer', 'description' => 'How long to wait (default 30)'],
                'count' => ['type' => 'integer', 'description' => 'How many new cards to wait for (default 1)'],
            ]),
            self::tool('peep_clear', 'Empties the viewer so the next report holds only the next run. Clear before reproducing a bug.', [
                'project' => $filters['project'],
                'what' => ['type' => 'string', 'enum' => ViewerApi::CLEAR_WHAT, 'description' => 'What to clear (default all)'],
            ]),
        ];
    }

    /**
     * @param array<mixed> $arguments
     * @return array{ok: bool, text: string}|null Null for an unknown tool.
     */
    private function call(string $name, array $arguments): ?array
    {
        $string = static function (string $key) use ($arguments) {
            return isset($arguments[$key]) && is_scalar($arguments[$key]) && (string) $arguments[$key] !== '' ? (string) $arguments[$key] : null;
        };
        $filters = [];
        foreach (ViewerApi::FILTERS as $filter) {
            if ($string($filter) !== null) {
                $filters[$filter] = $string($filter);
            }
        }

        switch ($name) {
            case 'peep_status':
                return $this->viewer->status();

            case 'peep_report':
                return $this->viewer->report($filters);

            case 'peep_cards':
                return $this->viewer->cards($filters);

            case 'peep_mail':
                return $this->viewer->mail($filters, $string('id'));

            case 'peep_sms':
                return $this->viewer->sms($filters);

            case 'peep_wait':
                $timeout = $string('timeout_seconds');
                $count = $string('count');

                return $this->viewer->wait(
                    $filters,
                    $timeout !== null && ctype_digit($timeout) ? min(300, (int) $timeout) : 30,
                    $count !== null && ctype_digit($count) ? (int) $count : 1
                );

            case 'peep_clear':
                $what = $string('what');

                return $this->viewer->clear($string('project'), $what !== null ? $what : 'all');

            default:
                return null;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @return array<string, mixed>
     */
    private static function tool(string $name, string $description, array $properties): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => [
                'type' => 'object',
                'properties' => $properties !== [] ? $properties : new \stdClass(),
                'additionalProperties' => false,
            ],
            'annotations' => [
                'readOnlyHint' => $name !== 'peep_clear',
                'destructiveHint' => $name === 'peep_clear',
                'openWorldHint' => false,
            ],
        ];
    }

    /**
     * @param int|string $id
     * @param mixed $result
     * @return array<string, mixed>
     */
    private static function result($id, $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @param int|string|null $id
     * @return array<string, mixed>
     */
    private static function error($id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function encode(array $message): string
    {
        // The viewer's text is UTF-8 already; the flag (PHP 7.2+) only guards against surprises.
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= (int) constant('JSON_INVALID_UTF8_SUBSTITUTE');
        }

        return (string) json_encode($message, $flags);
    }
}
