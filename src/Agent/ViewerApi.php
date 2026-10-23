<?php

namespace ShibbirWeb\Peep\Agent;

use ShibbirWeb\Peep\Config\Config;
use ShibbirWeb\Peep\Config\ConfigLoader;
use ShibbirWeb\Peep\Exception\TransportException;
use ShibbirWeb\Peep\Transport\CurlTransport;
use ShibbirWeb\Peep\Transport\StreamTransport;
use ShibbirWeb\Peep\Transport\TransportInterface;

/**
 * What the `peep` command line and the MCP server ask the viewer, through its
 * /_peep/agent endpoints. Every method answers with text for a person or an AI agent
 * and whether it worked; nothing here throws.
 */
final class ViewerApi
{
    public const FILTERS = ['project', 'since', 'request', 'card', 'search', 'limit'];

    public const CLEAR_WHAT = ['all', 'cards', 'mail', 'sms'];

    private const CONNECT_TIMEOUT_MS = 1000;

    private const TIMEOUT_MS = 15000;

    private const POLL_INTERVAL_MS = 500;

    /**
     * @var TransportInterface
     */
    private $transport;

    /**
     * @var string
     */
    private $address;

    /**
     * @var string|null
     */
    private $base;

    /**
     * @var string|null
     */
    private $defaultProject;

    /**
     * @var callable(int): void
     */
    private $sleep;

    /**
     * @param string $address Shown in messages, e.g. 127.0.0.1:23517.
     * @param string|null $base Project root: paths under it are shown relative to it.
     * @param string|null $defaultProject Used when a call names no project (PEEP_PROJECT).
     * @param (callable(int): void)|null $sleep Waits the given milliseconds; for tests.
     */
    public function __construct(TransportInterface $transport, string $address, ?string $base = null, ?string $defaultProject = null, ?callable $sleep = null)
    {
        $this->transport = $transport;
        $this->address = $address;
        $this->base = $base;
        $this->defaultProject = $defaultProject;
        $this->sleep = $sleep !== null ? $sleep : static function (int $milliseconds) {
            usleep($milliseconds * 1000);
        };
    }

    /**
     * Uses the app's own settings (peep.php, PEEP_HOST, PEEP_PORT, PEEP_TOKEN), so it asks
     * the viewer the app sends to.
     */
    public static function fromEnvironment(?string $base): self
    {
        $config = (new ConfigLoader())->load();
        $project = ConfigLoader::readEnvironmentVariable('PEEP_PROJECT');

        return new self(
            self::transport($config),
            $config->host() . ':' . $config->port(),
            $base,
            is_string($project) && $project !== '' ? $project : null
        );
    }

    /**
     * @return array{ok: bool, text: string}
     */
    public function status(bool $json = false): array
    {
        return $this->get('status', $json ? ['format' => 'json'] : []);
    }

    /**
     * @param array<string, mixed> $filters project, since, request, card, search, limit
     * @return array{ok: bool, text: string}
     */
    public function cards(array $filters, bool $json = false): array
    {
        return $this->get('cards', $this->query($filters, $json));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{ok: bool, text: string}
     */
    public function report(array $filters): array
    {
        return $this->get('report', $this->query($filters, false));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{ok: bool, text: string}
     */
    public function mail(array $filters, ?string $mailId = null, bool $json = false): array
    {
        $query = $this->query($filters, $json);
        if ($mailId !== null && $mailId !== '') {
            $query['id'] = $mailId;
        }

        return $this->get('mail', $query);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{ok: bool, text: string}
     */
    public function sms(array $filters, bool $json = false): array
    {
        return $this->get('sms', $this->query($filters, $json));
    }

    /**
     * Entries of a project's log file (the newest file unless $file names one), at $level or above.
     *
     * @param array<string, mixed> $filters project, since, search, limit
     * @return array{ok: bool, text: string}
     */
    public function logs(array $filters, ?string $file = null, ?string $level = null, bool $json = false): array
    {
        $query = $this->query($filters, $json);

        if ($file !== null && $file !== '') {
            $query['file'] = $file;
        }

        if ($level !== null && $level !== '') {
            $query['level'] = $level;
        }

        return $this->get('logs', $query);
    }

    /**
     * Waits until $count new cards arrive (or change), then shows them.
     *
     * @param array<string, mixed> $filters
     * @return array{ok: bool, text: string}
     */
    public function wait(array $filters, int $timeoutSeconds, int $count = 1): array
    {
        $status = $this->get('status', ['format' => 'json']);
        if (! $status['ok']) {
            return $status;
        }

        $decoded = json_decode($status['text'], true);
        $cursor = is_array($decoded) && isset($decoded['now']) && is_numeric($decoded['now']) ? (float) $decoded['now'] : null;
        if ($cursor === null) {
            return self::fail('The viewer did not say what time it is. Update the Peep viewer.');
        }

        $filters['since'] = sprintf('%.6F', $cursor);
        $waited = 0;
        $limit = max(1, $timeoutSeconds) * 1000;

        while (true) {
            $answer = $this->get('cards', $this->query($filters, true));
            if (! $answer['ok']) {
                return $answer;
            }

            $decoded = json_decode($answer['text'], true);
            $cards = is_array($decoded) && isset($decoded['cards']) && is_array($decoded['cards']) ? $decoded['cards'] : [];

            if (count($cards) >= max(1, $count) || $waited >= $limit) {
                break;
            }

            call_user_func($this->sleep, self::POLL_INTERVAL_MS);
            $waited += self::POLL_INTERVAL_MS;
        }

        if ($cards === []) {
            return self::fail('No new cards in ' . max(1, $timeoutSeconds) . ' s. Run the code that calls peep() (or open the page, run the command or let the job run), then wait again.');
        }

        $texts = [];
        foreach ($cards as $card) {
            if (is_array($card) && isset($card['text']) && is_string($card['text'])) {
                $texts[] = $card['text'];
            }
        }

        return ['ok' => true, 'text' => implode("\n\n", $texts) . "\n"];
    }

    /**
     * @return array{ok: bool, text: string}
     */
    public function clear(?string $project, string $what = 'all'): array
    {
        if (! in_array($what, self::CLEAR_WHAT, true)) {
            return self::fail('Clear what? One of: ' . implode(', ', self::CLEAR_WHAT) . '.');
        }

        $project = $project !== null && $project !== '' ? $project : $this->defaultProject;

        try {
            $response = $this->transport->post('/_peep/agent/clear', (string) json_encode(['project' => $project, 'what' => $what]));
        } catch (TransportException $exception) {
            return $this->unreachable();
        }

        if (! $response->isSuccessful()) {
            return $this->failed($response->status(), $response->body());
        }

        return ['ok' => true, 'text' => 'Cleared ' . ($what === 'all' ? 'cards, mail and SMS' : $what) . ' of ' . ($project !== null ? 'project ' . $project : 'every project') . ".\n"];
    }

    /**
     * @param array<string, string> $query
     * @return array{ok: bool, text: string}
     */
    private function get(string $endpoint, array $query): array
    {
        $path = '/_peep/agent/' . $endpoint . ($query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');

        try {
            $response = $this->transport->get($path);
        } catch (TransportException $exception) {
            return $this->unreachable();
        }

        if (! $response->isSuccessful()) {
            return $this->failed($response->status(), $response->body());
        }

        return ['ok' => true, 'text' => $response->body()];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, string>
     */
    private function query(array $filters, bool $json): array
    {
        $query = [];

        foreach (self::FILTERS as $name) {
            if (isset($filters[$name]) && is_scalar($filters[$name]) && (string) $filters[$name] !== '') {
                $query[$name] = (string) $filters[$name];
            }
        }

        if (! isset($query['project']) && $this->defaultProject !== null) {
            $query['project'] = $this->defaultProject;
        }

        if ($this->base !== null) {
            $query['base'] = $this->base;
        }

        if ($json) {
            $query['format'] = 'json';
        }

        return $query;
    }

    /**
     * @return array{ok: bool, text: string}
     */
    private function unreachable(): array
    {
        return self::fail(
            'No Peep viewer is running at ' . $this->address . '.' . "\n"
            . 'Start it: open the Peep desktop app, or run "php peep-server.phar serve" (download it from https://github.com/shibbirweb/peep/releases).' . "\n"
            . 'If the viewer runs elsewhere, set PEEP_HOST and PEEP_PORT the way the app has them.'
        );
    }

    /**
     * @return array{ok: bool, text: string}
     */
    private function failed(int $status, string $body): array
    {
        $decoded = json_decode($body, true);
        $error = is_array($decoded) && isset($decoded['error']) && is_string($decoded['error']) ? $decoded['error'] : null;

        // Plain-text answers (the logs endpoint) explain themselves; an older viewer's
        // static "Not found." does not.
        if (! is_array($decoded) && trim($body) !== '' && trim($body) !== 'Not found.') {
            return self::fail(rtrim($body));
        }
        $message = is_array($decoded) && isset($decoded['message']) && is_string($decoded['message']) ? $decoded['message'] : $body;

        // A viewer from before the agent endpoints answers 404 for all of them.
        if ($status === 404 && ($error === null || $error === 'not_found')) {
            return self::fail('This Peep viewer has no agent endpoints. Update the Peep viewer (desktop app or peep-server.phar) to the same version as this package.');
        }

        if ($status === 404) {
            return self::fail($message);
        }

        return self::fail('The Peep viewer answered ' . $status . ': ' . $message);
    }

    /**
     * @return array{ok: bool, text: string}
     */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'text' => $message . "\n"];
    }

    private static function transport(Config $config): TransportInterface
    {
        if (function_exists('stream_socket_client')) {
            return new StreamTransport($config->host(), $config->port(), self::CONNECT_TIMEOUT_MS, self::TIMEOUT_MS, $config->token());
        }

        return new CurlTransport($config->host(), $config->port(), self::CONNECT_TIMEOUT_MS, self::TIMEOUT_MS, $config->token());
    }
}
