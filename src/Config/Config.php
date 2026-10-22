<?php

namespace ShibbirWeb\Peep\Config;

/**
 * Immutable client configuration.
 *
 * Built from an array of options (see defaults()); invalid values fall back to
 * the default instead of throwing, because configuration must never break the app.
 */
final class Config
{
    /**
     * @var bool
     */
    private $enabled;

    /**
     * @var string
     */
    private $host;

    /**
     * @var int
     */
    private $port;

    /**
     * @var int
     */
    private $connectTimeoutMs;

    /**
     * @var int
     */
    private $timeoutMs;

    /**
     * @var string
     */
    private $project;

    /**
     * @var string|null
     */
    private $remotePath;

    /**
     * @var string|null
     */
    private $localPath;

    /**
     * @var int
     */
    private $maxDepth;

    /**
     * @var int
     */
    private $maxItems;

    /**
     * @var int
     */
    private $maxStringLength;

    /**
     * @var int
     */
    private $maxNodes;

    /**
     * @var list<string>
     */
    private $redactKeys;

    /**
     * @var string "peep" or "var-dumper"
     */
    private $serializer;

    /**
     * @var string|null
     */
    private $token;

    /**
     * @param array<string, mixed> $options
     */
    private function __construct(array $options)
    {
        $defaults = self::defaults();

        $this->enabled = self::toBool($options['enabled'], false);
        $this->host = self::toNonEmptyString($options['host'], $defaults['host']);
        $this->port = self::toIntInRange($options['port'], $defaults['port'], 1, 65535);
        $this->connectTimeoutMs = self::toIntInRange($options['connect_timeout_ms'], $defaults['connect_timeout_ms'], 1, 60000);
        $this->timeoutMs = self::toIntInRange($options['timeout_ms'], $defaults['timeout_ms'], 1, 60000);
        $this->project = self::toNonEmptyString($options['project'], $defaults['project']);
        $this->remotePath = self::toNullableString($options['remote_path']);
        $this->localPath = self::toNullableString($options['local_path']);
        $this->maxDepth = self::toIntInRange($options['max_depth'], $defaults['max_depth'], 1, 100);
        $this->maxItems = self::toIntInRange($options['max_items'], $defaults['max_items'], 1, 100000);
        $this->maxStringLength = self::toIntInRange($options['max_string_length'], $defaults['max_string_length'], 1, 10000000);
        $this->maxNodes = self::toIntInRange($options['max_nodes'], $defaults['max_nodes'], 1, 1000000);
        $this->redactKeys = self::toKeyList($options['redact_keys'], $defaults['redact_keys']);
        $this->serializer = in_array($options['serializer'], ['peep', 'var-dumper'], true) ? $options['serializer'] : 'peep';
        $this->token = self::toToken($options['token']);
    }

    /**
     * @return array{enabled: bool, host: string, port: int, connect_timeout_ms: int, timeout_ms: int, project: string, remote_path: null, local_path: null, max_depth: int, max_items: int, max_string_length: int, max_nodes: int, redact_keys: list<string>, serializer: string, token: null}
     */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'host' => '127.0.0.1',
            'port' => 23517,
            'connect_timeout_ms' => 50,
            'timeout_ms' => 500,
            'project' => 'default',
            'remote_path' => null,
            'local_path' => null,
            'max_depth' => 5,
            'max_items' => 100,
            'max_string_length' => 10000,
            'max_nodes' => 5000,
            'redact_keys' => [
                'password',
                'password_confirmation',
                'secret',
                'token',
                'api_key',
                'apikey',
                'authorization',
                'cookie',
            ],
            'serializer' => 'peep',
            'token' => null,
        ];
    }

    /**
     * @param array<string, mixed> $options Unknown keys are ignored; missing keys use the defaults.
     */
    public static function fromArray(array $options): self
    {
        return new self(array_merge(self::defaults(), $options));
    }

    public function withEnabled(bool $enabled): self
    {
        $copy = clone $this;
        $copy->enabled = $enabled;

        return $copy;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * Identifies the viewer this config points at, e.g. for the availability cache.
     */
    public function endpointKey(): string
    {
        return $this->host . ':' . $this->port;
    }

    public function connectTimeoutMs(): int
    {
        return $this->connectTimeoutMs;
    }

    public function timeoutMs(): int
    {
        return $this->timeoutMs;
    }

    public function project(): string
    {
        return $this->project;
    }

    public function remotePath(): ?string
    {
        return $this->remotePath;
    }

    public function localPath(): ?string
    {
        return $this->localPath;
    }

    public function maxDepth(): int
    {
        return $this->maxDepth;
    }

    public function maxItems(): int
    {
        return $this->maxItems;
    }

    public function maxStringLength(): int
    {
        return $this->maxStringLength;
    }

    public function maxNodes(): int
    {
        return $this->maxNodes;
    }

    /**
     * @return list<string> Lower-case keys whose values are redacted.
     */
    public function redactKeys(): array
    {
        return $this->redactKeys;
    }

    /**
     * "peep" (default: never calls code on the value) or "var-dumper" (Symfony casters).
     */
    public function serializer(): string
    {
        return $this->serializer;
    }

    /**
     * Shared secret sent as X-Peep-Token, for a viewer on another machine (or Docker on Linux).
     */
    public function token(): ?string
    {
        return $this->token;
    }

    /**
     * Accepts booleans, 1/0 and the strings true/false, 1/0, yes/no, on/off.
     *
     * @param mixed $value
     */
    public static function toBool($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return $default;
    }

    /**
     * @param mixed $value
     */
    private static function toNonEmptyString($value, string $default): string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return $default;
    }

    /**
     * @param mixed $value
     */
    private static function toNullableString($value): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return null;
    }

    /**
     * Only characters that are safe in an HTTP header; anything else is ignored.
     *
     * @param mixed $value
     */
    private static function toToken($value): ?string
    {
        if (is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,256}$/', trim($value)) === 1) {
            return trim($value);
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private static function toIntInRange($value, int $default, int $min, int $max): int
    {
        if (is_string($value) && preg_match('/^\s*-?\d+\s*$/', $value) === 1) {
            $value = (int) $value;
        }

        if (is_int($value) && $value >= $min && $value <= $max) {
            return $value;
        }

        return $default;
    }

    /**
     * @param mixed $value
     * @param list<string> $default
     * @return list<string>
     */
    private static function toKeyList($value, array $default): array
    {
        if (! is_array($value)) {
            return $default;
        }

        $keys = [];
        foreach ($value as $key) {
            if (is_string($key) && $key !== '') {
                $keys[] = strtolower($key);
            }
        }

        return array_values(array_unique($keys));
    }
}
