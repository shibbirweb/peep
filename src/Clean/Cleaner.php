<?php

namespace ShibbirWeb\Peep\Clean;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Looks through PHP files and Blade templates for leftover peep() calls, and removes the
 * ones that can go safely when asked to.
 */
final class Cleaner
{
    /**
     * Directories never searched.
     */
    public const SKIPPED_DIRECTORIES = ['vendor', 'node_modules', 'storage', 'bootstrap/cache', '.git', '.idea', 'build'];

    /**
     * @var CallFinder
     */
    private $finder;

    public function __construct(?CallFinder $finder = null)
    {
        $this->finder = $finder !== null ? $finder : new CallFinder();
    }

    /**
     * @param list<string> $paths Files or directories.
     * @return array{findings: list<Finding>, removed: int, files: int}
     */
    public function run(array $paths, bool $fix): array
    {
        $findings = [];
        $removed = 0;
        $files = 0;

        foreach ($this->files($paths) as $file) {
            $code = @file_get_contents($file);
            if (! is_string($code)) {
                continue;
            }

            $files++;
            $isBlade = substr($file, -10) === '.blade.php';
            $calls = $isBlade ? self::bladeDirectives($code) : $this->finder->find($code);

            foreach ($calls as $call) {
                $findings[] = new Finding($file, $call['line'], $call['code'], $call['removable']);
            }

            if ($fix && $calls !== []) {
                $result = $isBlade ? self::fixBlade($code) : $this->finder->fix($code);

                if ($result['removed'] > 0 && @file_put_contents($file, $result['code']) !== false) {
                    $removed += $result['removed'];
                }
            }
        }

        return ['findings' => $findings, 'removed' => $removed, 'files' => $files];
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function files(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = $path;
                continue;
            }

            if (! is_dir($path)) {
                continue;
            }

            $root = rtrim($path, '/\\');
            $directories = new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                function (SplFileInfo $file) use ($root) {
                    if (! $file->isDir()) {
                        return substr($file->getFilename(), -4) === '.php';
                    }

                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

                    return ! in_array($relative, self::SKIPPED_DIRECTORIES, true) && ! in_array($file->getFilename(), ['vendor', 'node_modules', '.git'], true);
                }
            );

            foreach (new RecursiveIteratorIterator($directories) as $file) {
                if ($file instanceof SplFileInfo) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /**
     * @peep, @peepAll and @peepMeasure in Blade templates.
     *
     * @return list<array{line: int, code: string, removable: bool}>
     */
    private static function bladeDirectives(string $code): array
    {
        $found = [];

        foreach (explode("\n", $code) as $number => $line) {
            if (preg_match_all('/@peep(?:All|Measure)?\b(\((?:[^()]|(?1))*\))?/', $line, $matches) > 0) {
                foreach ($matches[0] as $directive) {
                    $found[] = ['line' => $number + 1, 'code' => $directive, 'removable' => true];
                }
            }
        }

        return $found;
    }

    /**
     * @return array{code: string, removed: int}
     */
    private static function fixBlade(string $code): array
    {
        $removed = 0;
        $lines = [];

        foreach (explode("\n", $code) as $line) {
            $cleaned = (string) preg_replace('/@peep(?:All|Measure)?\b(\((?:[^()]|(?1))*\))?/', '', $line, -1, $count);
            $removed += $count;

            if ($count > 0) {
                $cleaned = rtrim($cleaned, " \t");
            }

            // A line that held only the directive goes away.
            if ($count > 0 && trim($cleaned) === '') {
                continue;
            }

            $lines[] = $cleaned;
        }

        return ['code' => implode("\n", $lines), 'removed' => $removed];
    }

    /**
     * Lines for the console.
     *
     * @param array{findings: list<Finding>, removed: int, files: int} $result
     * @return list<string>
     */
    public static function report(array $result, bool $fix, string $base = ''): array
    {
        $lines = [];
        $base = $base !== '' ? rtrim($base, '/\\') . DIRECTORY_SEPARATOR : '';

        foreach ($result['findings'] as $finding) {
            $file = $base !== '' && strpos($finding->file, $base) === 0 ? substr($finding->file, strlen($base)) : $finding->file;
            $state = $finding->removable ? ($fix ? 'removed' : 'removable') : 'fix by hand';
            $lines[] = sprintf('%s:%d  %s  (%s)', $file, $finding->line, $finding->code, $state);
        }

        $count = count($result['findings']);
        $lines[] = '';
        $lines[] = $count === 0
            ? sprintf('No peep() calls in %d files.', $result['files'])
            : sprintf('%d peep() %s in %d files searched.', $count, $count === 1 ? 'call' : 'calls', $result['files'])
                . ($fix ? sprintf(' Removed %d.', $result['removed']) : ' Run with --fix to remove the removable ones.');

        return $lines;
    }
}
