<?php

namespace ShibbirWeb\Peep\Envelope;

use ShibbirWeb\Peep\Config\Config;
use ShibbirWeb\Peep\Context\ExecutionContext;
use ShibbirWeb\Peep\Origin\PathMapper;
use ShibbirWeb\Peep\Version;

/**
 * Builds the meta block every envelope carries.
 */
final class MetaFactory
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var ExecutionContext
     */
    private $context;

    /**
     * @var string|null
     */
    private $hostname;

    /**
     * @var array{path: string, timezone: string}|null|false False until worked out.
     */
    private $logs = false;

    public function __construct(Config $config, ExecutionContext $context)
    {
        $this->config = $config;
        $this->context = $context;
    }

    /**
     * @return array{project: string, hostname: string, php_version: string, peep_version: string, request: array{id: string, kind: string, label: string, started_at: float}, sent_at: float, pid: int|null, logs?: array{path: string, timezone: string}}
     */
    public function create(): array
    {
        $pid = getmypid();

        $meta = [
            'project' => $this->config->project(),
            'hostname' => $this->hostname(),
            'php_version' => PHP_VERSION,
            'peep_version' => Version::VERSION,
            'request' => $this->context->current(),
            'sent_at' => microtime(true),
            'pid' => is_int($pid) ? $pid : null,
        ];

        $logs = $this->logs();
        if ($logs !== null) {
            $meta['logs'] = $logs;
        }

        return $meta;
    }

    /**
     * Where the app writes its logs, as a path on the developer's machine (mapped like
     * origins), so the viewer can show the files. Null when no log folder is configured.
     *
     * @return array{path: string, timezone: string}|null
     */
    private function logs(): ?array
    {
        if ($this->logs === false) {
            $path = $this->config->logPath();
            $timezone = $this->config->logTimezone();

            $this->logs = $path === null ? null : [
                'path' => (new PathMapper($this->config->remotePath(), $this->config->localPath()))->map($path),
                'timezone' => $timezone !== null ? $timezone : date_default_timezone_get(),
            ];
        }

        return $this->logs;
    }

    private function hostname(): string
    {
        if ($this->hostname === null) {
            $hostname = gethostname();
            $this->hostname = is_string($hostname) && $hostname !== '' ? $hostname : 'unknown';
        }

        return $this->hostname;
    }
}
