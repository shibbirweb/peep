<?php

namespace ShibbirWeb\Peep\Envelope;

use ShibbirWeb\Peep\Config\Config;
use ShibbirWeb\Peep\Context\ExecutionContext;
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

    public function __construct(Config $config, ExecutionContext $context)
    {
        $this->config = $config;
        $this->context = $context;
    }

    /**
     * @return array{project: string, hostname: string, php_version: string, peep_version: string, request: array{id: string, kind: string, label: string, started_at: float}, sent_at: float, pid: int|null}
     */
    public function create(): array
    {
        $pid = getmypid();

        return [
            'project' => $this->config->project(),
            'hostname' => $this->hostname(),
            'php_version' => PHP_VERSION,
            'peep_version' => Version::VERSION,
            'request' => $this->context->current(),
            'sent_at' => microtime(true),
            'pid' => is_int($pid) ? $pid : null,
        ];
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
