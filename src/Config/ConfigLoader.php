<?php

namespace ShibbirWeb\Peep\Config;

use Throwable;

/**
 * Builds the client configuration. Later sources win:
 *
 * 1. Defaults (Config::defaults()).
 * 2. A peep.php file returning an array, found by walking up from the start directories.
 * 3. Environment variables (PEEP_ENABLED, PEEP_HOST, ...).
 * 4. Overrides passed to load() (used by framework adapters).
 */
final class ConfigLoader
{
    public const FILE_NAME = 'peep.php';

    /**
     * Option name => environment variable.
     */
    public const ENVIRONMENT_VARIABLES = [
        'enabled' => 'PEEP_ENABLED',
        'host' => 'PEEP_HOST',
        'port' => 'PEEP_PORT',
        'project' => 'PEEP_PROJECT',
        'remote_path' => 'PEEP_REMOTE_PATH',
        'local_path' => 'PEEP_LOCAL_PATH',
        'serializer' => 'PEEP_SERIALIZER',
        'token' => 'PEEP_TOKEN',
    ];

    private const MAX_PARENT_LEVELS = 64;

    /**
     * @var list<string>
     */
    private $startDirectories;

    /**
     * @var callable(string): (string|null)
     */
    private $environment;

    /**
     * @param list<string>|null $startDirectories Defaults to the working directory and the entry script's directory.
     * @param (callable(string): (string|null))|null $environment Reads one environment variable; defaults to the process environment.
     */
    public function __construct(?array $startDirectories = null, ?callable $environment = null)
    {
        $this->startDirectories = $startDirectories !== null ? $startDirectories : self::defaultStartDirectories();
        $this->environment = $environment !== null ? $environment : [self::class, 'readEnvironmentVariable'];
    }

    /**
     * @param array<string, mixed> $overrides Win over everything (framework adapters).
     * @param array<string, mixed> $defaults Lose against everything (e.g. the global install enables Peep by default).
     */
    public function load(array $overrides = [], array $defaults = []): Config
    {
        $options = $defaults;

        $file = $this->findFile();
        if ($file !== null) {
            $options = array_merge($options, $this->readFile($file));
        }

        $options = array_merge($options, $this->readEnvironment(), $overrides);

        return Config::fromArray($options);
    }

    public function findFile(): ?string
    {
        foreach ($this->startDirectories as $startDirectory) {
            $directory = realpath($startDirectory);
            if ($directory === false) {
                continue;
            }

            for ($level = 0; $level < self::MAX_PARENT_LEVELS; $level++) {
                $candidate = $directory . DIRECTORY_SEPARATOR . self::FILE_NAME;
                if (is_file($candidate)) {
                    return $candidate;
                }

                $parent = dirname($directory);
                if ($parent === $directory) {
                    break;
                }

                $directory = $parent;
            }
        }

        return null;
    }

    /**
     * @return string|null
     */
    public static function readEnvironmentVariable(string $name)
    {
        $value = getenv($name);
        if (is_string($value)) {
            return $value;
        }

        if (isset($_ENV[$name]) && is_scalar($_ENV[$name])) {
            return (string) $_ENV[$name];
        }

        if (isset($_SERVER[$name]) && is_scalar($_SERVER[$name])) {
            return (string) $_SERVER[$name];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readFile(string $path): array
    {
        try {
            $values = (static function ($peepConfigFile) {
                return require $peepConfigFile;
            })($path);
        } catch (Throwable $exception) {
            return [];
        }

        if (! is_array($values)) {
            return [];
        }

        $options = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $options[$key] = $value;
            }
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function readEnvironment(): array
    {
        $options = [];

        foreach (self::ENVIRONMENT_VARIABLES as $option => $variable) {
            $value = call_user_func($this->environment, $variable);
            if (is_string($value)) {
                $options[$option] = $value;
            }
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private static function defaultStartDirectories(): array
    {
        $directories = [];

        $workingDirectory = getcwd();
        if (is_string($workingDirectory)) {
            $directories[] = $workingDirectory;
        }

        if (isset($_SERVER['SCRIPT_FILENAME']) && is_string($_SERVER['SCRIPT_FILENAME'])) {
            $directories[] = dirname($_SERVER['SCRIPT_FILENAME']);
        }

        return array_values(array_unique($directories));
    }
}
