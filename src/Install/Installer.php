<?php

namespace ShibbirWeb\Peep\Install;

use Phar;
use ShibbirWeb\Peep\Version;

/**
 * "php peep.phar install": copies the PHAR to ~/.peep/peep.phar and loads it before every
 * script of this PHP through an ini file in PHP's scan directory (auto_prepend_file).
 *
 * The decisions are made by install() and uninstall() from plain arguments, so they can be
 * tested without touching the real PHP configuration.
 */
final class Installer
{
    public const INI_FILE = '99-peep.ini';

    private const MARKER = '; Added by "php peep.phar install". Remove with "php peep.phar uninstall".';

    private const USAGE = <<<'TEXT'
Usage: php peep.phar <command> [options]

Commands:
  install     Load Peep before every PHP script of this PHP (auto_prepend_file)
  uninstall   Remove that again
  status      Show what is installed
  version     Show the version

Options for install:
  --force          Replace an auto_prepend_file that something else set
  --dry-run        Show what would change, change nothing
  --ini-dir=DIR    Write the ini file into DIR instead of PHP's scan directory
TEXT;

    private function __construct()
    {
    }

    /**
     * @param list<string> $arguments
     * @param resource $output
     * @param resource $errors
     */
    public static function run(array $arguments, $output, $errors): int
    {
        $command = isset($arguments[0]) ? $arguments[0] : 'help';
        $options = self::options(array_slice($arguments, 1));

        if ($command === 'version' || $command === '--version') {
            fwrite($output, 'Peep client ' . Version::VERSION . "\n");

            return 0;
        }

        if ($command === 'install' || $command === 'uninstall' || $command === 'status') {
            $home = self::home();
            $iniDirectory = isset($options['ini-dir']) ? $options['ini-dir'] : self::scanDirectory();
            $currentPrepend = (string) ini_get('auto_prepend_file');

            if ($command === 'install') {
                $result = self::install(
                    Phar::running(false),
                    $home,
                    $iniDirectory,
                    $currentPrepend,
                    isset($options['force']),
                    isset($options['dry-run'])
                );
            } elseif ($command === 'uninstall') {
                $result = self::uninstall($home, $iniDirectory, isset($options['dry-run']));
            } else {
                $result = self::status($home, $iniDirectory, $currentPrepend);
            }

            fwrite($result['code'] === 0 ? $output : $errors, implode("\n", $result['lines']) . "\n");

            return $result['code'];
        }

        fwrite($command === 'help' || $command === '--help' ? $output : $errors, self::USAGE . "\n");

        return $command === 'help' || $command === '--help' ? 0 : 2;
    }

    /**
     * @return array{code: int, lines: list<string>}
     */
    public static function install(string $pharPath, ?string $home, ?string $iniDirectory, string $currentPrepend, bool $force, bool $dryRun): array
    {
        if ($pharPath === '' || ! is_file($pharPath)) {
            return self::fail('Run install from peep.phar: php peep.phar install');
        }

        if ($home === null) {
            return self::fail('Cannot find your home directory (HOME is not set).');
        }

        $target = $home . DIRECTORY_SEPARATOR . '.peep' . DIRECTORY_SEPARATOR . 'peep.phar';
        $lines = [];

        if ($currentPrepend !== '' && self::samePath($currentPrepend, $target) !== true && ! $force) {
            return self::fail(
                'auto_prepend_file is already set to ' . $currentPrepend . '.',
                'PHP allows only one. (Laravel Herd, for example, uses it for its own dumps.)',
                'Run "php peep.phar install --force" to replace it, or add peep() to projects with Composer instead.'
            );
        }

        if (! $dryRun) {
            if (! is_dir(dirname($target)) && ! @mkdir(dirname($target), 0755, true)) {
                return self::fail('Cannot create ' . dirname($target) . '.');
            }

            if (self::samePath($pharPath, $target) !== true && ! @copy($pharPath, $target)) {
                return self::fail('Cannot copy peep.phar to ' . $target . '.');
            }
        }
        $lines[] = ($dryRun ? 'Would copy' : 'Copied') . ' peep.phar to ' . $target;

        $ini = self::MARKER . "\nauto_prepend_file = \"" . $target . "\"\n";

        if ($iniDirectory === null || ! is_dir($iniDirectory)) {
            $loaded = php_ini_loaded_file();
            $lines[] = 'This PHP has no ini scan directory. Add this line to ' . (is_string($loaded) ? $loaded : 'your php.ini') . ':';
            $lines[] = '    auto_prepend_file = "' . $target . '"';

            return ['code' => 0, 'lines' => array_merge($lines, self::nextSteps())];
        }

        $iniFile = rtrim($iniDirectory, '/\\') . DIRECTORY_SEPARATOR . self::INI_FILE;

        if (! $dryRun && @file_put_contents($iniFile, $ini) === false) {
            return self::fail('Cannot write ' . $iniFile . '. Try again with sudo, or use --ini-dir.');
        }
        $lines[] = ($dryRun ? 'Would write' : 'Wrote') . ' ' . $iniFile;

        return ['code' => 0, 'lines' => array_merge($lines, self::nextSteps())];
    }

    /**
     * @return array{code: int, lines: list<string>}
     */
    public static function uninstall(?string $home, ?string $iniDirectory, bool $dryRun): array
    {
        $lines = [];

        if ($iniDirectory !== null) {
            $iniFile = rtrim($iniDirectory, '/\\') . DIRECTORY_SEPARATOR . self::INI_FILE;
            $contents = is_file($iniFile) ? (string) file_get_contents($iniFile) : '';

            if (strpos($contents, self::MARKER) === 0) {
                if (! $dryRun && ! @unlink($iniFile)) {
                    return self::fail('Cannot remove ' . $iniFile . '. Try again with sudo.');
                }
                $lines[] = ($dryRun ? 'Would remove ' : 'Removed ') . $iniFile;
            } elseif ($contents !== '') {
                $lines[] = $iniFile . ' was not written by Peep; left alone.';
            }
        }

        if ($home !== null) {
            $phar = $home . DIRECTORY_SEPARATOR . '.peep' . DIRECTORY_SEPARATOR . 'peep.phar';
            if (is_file($phar)) {
                if (! $dryRun) {
                    @unlink($phar);
                }
                $lines[] = ($dryRun ? 'Would remove ' : 'Removed ') . $phar;
            }
        }

        if ($lines === []) {
            $lines[] = 'Peep is not installed globally.';
        }

        return ['code' => 0, 'lines' => $lines];
    }

    /**
     * @return array{code: int, lines: list<string>}
     */
    public static function status(?string $home, ?string $iniDirectory, string $currentPrepend): array
    {
        $phar = $home !== null ? $home . DIRECTORY_SEPARATOR . '.peep' . DIRECTORY_SEPARATOR . 'peep.phar' : null;
        $active = $phar !== null && $currentPrepend !== '' && self::samePath($currentPrepend, $phar) === true;

        return ['code' => 0, 'lines' => [
            'Peep client ' . Version::VERSION,
            'Global install: ' . ($active ? 'active' : 'not active'),
            'auto_prepend_file: ' . ($currentPrepend !== '' ? $currentPrepend : '(not set)'),
            'ini scan directory: ' . ($iniDirectory !== null ? $iniDirectory : '(none)'),
        ]];
    }

    /**
     * PHP's ini scan directory: PHP_INI_SCAN_DIR (first entry) or the compiled-in one.
     */
    public static function scanDirectory(): ?string
    {
        $fromEnvironment = getenv('PHP_INI_SCAN_DIR');
        if (is_string($fromEnvironment) && $fromEnvironment !== '') {
            $first = explode(PATH_SEPARATOR, $fromEnvironment)[0];

            return $first !== '' ? $first : null;
        }

        return PHP_CONFIG_FILE_SCAN_DIR !== '' ? PHP_CONFIG_FILE_SCAN_DIR : null;
    }

    /**
     * @return list<string>
     */
    private static function nextSteps(): array
    {
        return [
            '',
            'Peep is now on for every script this PHP runs. Next:',
            '  1. Start the viewer: php peep-server.phar serve (or open the Peep desktop app)',
            '  2. Call peep($anything) in any PHP file',
            'Turn it off for a project with a peep.php file returning [\'enabled\' => false], or PEEP_ENABLED=false.',
            'Restart PHP-FPM (or Herd / Valet) so web requests pick up the change.',
        ];
    }

    private static function home(): ?string
    {
        foreach (['HOME', 'USERPROFILE'] as $variable) {
            $value = getenv($variable);
            if (is_string($value) && $value !== '') {
                return rtrim($value, '/\\');
            }
        }

        return null;
    }

    /**
     * @return bool|null Null when either path does not exist yet.
     */
    private static function samePath(string $a, string $b): ?bool
    {
        $realA = realpath($a);
        $realB = realpath($b);

        if ($realA === false || $realB === false) {
            return $a === $b ? true : null;
        }

        return $realA === $realB;
    }

    /**
     * @param list<string> $arguments
     * @return array<string, string>
     */
    private static function options(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $matches) === 1) {
                $options[$matches[1]] = isset($matches[2]) ? $matches[2] : '1';
            }
        }

        return $options;
    }

    /**
     * @return array{code: int, lines: list<string>}
     */
    private static function fail(string ...$lines): array
    {
        return ['code' => 1, 'lines' => array_values($lines)];
    }
}
