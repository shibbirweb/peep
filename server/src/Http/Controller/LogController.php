<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use ShibbirWeb\PeepServer\Event\EventHub;
use ShibbirWeb\PeepServer\Http\JsonBody;
use ShibbirWeb\PeepServer\Http\Responses;
use ShibbirWeb\PeepServer\Log\LogEntry;
use ShibbirWeb\PeepServer\Log\LogFiles;
use ShibbirWeb\PeepServer\Log\LogFolders;
use ShibbirWeb\PeepServer\Log\LogParser;

/**
 * The Logs tab:
 *
 * GET  /_peep/ui/logs                                     every project's log folder
 * GET  /_peep/ui/logs/files?project=                      the folder's .log files
 * GET  /_peep/ui/logs/entries?project=&file=&limit=       the newest entries
 *      ...&before=OFFSET                                  older ones
 *      ...&after=OFFSET                                   what was added (watching)
 * POST /_peep/ui/logs/folder {"project", "path"}          choose a folder; "path": null goes back to the app's
 *
 * Only .log files directly inside a project's folder are read.
 */
final readonly class LogController implements RequestHandler
{
    public function __construct(
        private LogFolders $folders,
        private EventHub $hub,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $path = $request->getUri()->getPath();
        parse_str($request->getUri()->getQuery(), $query);

        if ($request->getMethod() === 'POST') {
            return $path === '/_peep/ui/logs/folder' ? $this->choose(JsonBody::read($request)) : Responses::error(404, 'not_found', 'Not found.');
        }

        return match ($path) {
            '/_peep/ui/logs' => Responses::json(200, ['folders' => $this->folders->all()]),
            '/_peep/ui/logs/files' => $this->files(self::text($query, 'project')),
            '/_peep/ui/logs/entries' => $this->entries($query),
            default => Responses::error(404, 'not_found', 'Not found.'),
        };
    }

    private function files(?string $project): Response
    {
        $folder = $project === null ? null : $this->folders->for($project);
        if ($project === null || $folder === null) {
            return Responses::error(404, 'no_log_folder', 'This project has no log folder. Choose one, or send a card from the app (Laravel reports storage/logs).');
        }

        $files = LogFiles::list($folder['path']);
        if ($files === null) {
            return Responses::json(200, ['project' => $project, ...$folder, 'files' => [], 'error' => 'Cannot read ' . $folder['path'] . '. Choose another folder.']);
        }

        return Responses::json(200, ['project' => $project, ...$folder, 'files' => $files, 'error' => null]);
    }

    /**
     * @param array<mixed> $query
     */
    private function entries(array $query): Response
    {
        $project = self::text($query, 'project');
        $folder = $project === null ? null : $this->folders->for($project);
        $file = $folder === null ? null : LogFiles::resolve($folder['path'], self::text($query, 'file') ?? '');

        if ($folder === null || $file === null) {
            return Responses::error(404, 'unknown_log', 'No such log file; it may have been deleted or rotated.');
        }

        $parser = new LogParser($folder['timezone']);
        $limit = self::offset($query, 'limit') ?? LogFiles::DEFAULT_LIMIT;
        $after = self::offset($query, 'after');

        $result = $after !== null
            ? LogFiles::after($file, $after, $parser)
            : [...LogFiles::before($file, self::offset($query, 'before'), $limit, $parser), 'reset' => false];

        return Responses::json(200, [
            ...$result,
            'entries' => array_map(static fn(LogEntry $entry): array => $entry->toArray(), $result['entries']),
        ]);
    }

    /**
     * @param array<mixed> $data
     */
    private function choose(array $data): Response
    {
        $project = JsonBody::project($data);
        $path = $data['path'] ?? null;

        if ($project === null || ($path !== null && ! is_string($path))) {
            return Responses::error(400, 'invalid_request', 'Send {"project": "shop", "path": "/folder" or null}.');
        }

        if (is_string($path)) {
            $path = rtrim(trim($path), '/\\');

            if ($path === '' || ! is_dir($path) || ! is_readable($path)) {
                return Responses::error(422, 'invalid_folder', 'Not a folder this viewer can read: ' . $path);
            }
        }

        $this->hub->publish($this->folders->choose($project, $path));

        return Responses::json(200, ['folders' => $this->folders->all()]);
    }

    /**
     * @param array<mixed> $query
     */
    private static function text(array $query, string $name): ?string
    {
        return is_string($query[$name] ?? null) && $query[$name] !== '' ? $query[$name] : null;
    }

    /**
     * @param array<mixed> $query
     */
    private static function offset(array $query, string $name): ?int
    {
        return is_string($query[$name] ?? null) && ctype_digit($query[$name]) ? (int) $query[$name] : null;
    }
}
