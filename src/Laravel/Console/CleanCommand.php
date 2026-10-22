<?php

namespace ShibbirWeb\Peep\Laravel\Console;

use Illuminate\Console\Command;
use ShibbirWeb\Peep\Clean\Cleaner;

/**
 * php artisan peep:clean [--fix] [paths...]: peep() calls left in app/, routes/,
 * resources/views/, config/, database/ and tests/.
 */
final class CleanCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'peep:clean {paths?* : Files or directories (default: the app\'s code)} {--fix : Remove the calls that can go safely}';

    /**
     * @var string
     */
    protected $description = 'List (and remove) peep() calls left in the code';

    public function handle(): int
    {
        $paths = $this->argument('paths');
        $paths = is_array($paths) && $paths !== [] ? array_values(array_map('strval', $paths)) : self::defaultPaths($this->laravel->basePath());
        $fix = (bool) $this->option('fix');

        $result = (new Cleaner())->run($paths, $fix);

        foreach (Cleaner::report($result, $fix, $this->laravel->basePath()) as $line) {
            $this->line($line);
        }

        foreach ($result['findings'] as $finding) {
            if (! $fix || ! $finding->removable) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * @return list<string>
     */
    private static function defaultPaths(string $base): array
    {
        $paths = [];
        foreach (['app', 'routes', 'resources/views', 'config', 'database', 'tests', 'src'] as $directory) {
            if (is_dir($base . DIRECTORY_SEPARATOR . $directory)) {
                $paths[] = $base . DIRECTORY_SEPARATOR . $directory;
            }
        }

        return $paths;
    }
}
