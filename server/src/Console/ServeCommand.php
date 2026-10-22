<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Console;

use Amp\DeferredFuture;
use Revolt\EventLoop\UnsupportedFeatureException;
use ShibbirWeb\PeepServer\Application;
use ShibbirWeb\PeepServer\Version;
use InvalidArgumentException;
use PDOException;
use ShibbirWeb\PeepServer\Settings\Settings;
use ShibbirWeb\PeepServer\Settings\SettingsFile;
use ShibbirWeb\PeepServer\Settings\SettingsStore;
use ShibbirWeb\PeepServer\Storage\CardStorage;
use ShibbirWeb\PeepServer\Storage\NullCardStorage;
use ShibbirWeb\PeepServer\Storage\SqliteCardStorage;
use Throwable;

use function Amp\trapSignal;

/**
 * `peep-server serve`: starts the viewer, or only opens the view when one already runs.
 */
final readonly class ServeCommand
{
    /**
     * @param resource $output
     * @param resource $errors
     */
    public function __construct(
        private HealthProbe $probe = new HealthProbe(),
        private ViewOpener $opener = new ViewOpener(),
        private mixed $output = STDOUT,
        private mixed $errors = STDERR,
        private ?SettingsFile $settingsFile = null,
    ) {}

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments): int
    {
        $settingsFile = $this->settingsFile ?? SettingsFile::inDefaultDirectory();
        $settings = $settingsFile->load();

        try {
            $options = ServeOptions::fromArguments($arguments, $settings);
        } catch (InvalidArgumentException $exception) {
            $this->error('Error: ' . $exception->getMessage());
            $this->error(Cli::USAGE);

            return 2;
        }

        $url = new Application($options->host, $options->port, new ConsoleLogger())->url();

        switch ($this->probe->check($options->host, $options->port)) {
            case PortStatus::Peep:
                $this->write("Peep is already running at {$url}.");
                $this->openView($options->open, $url);

                return 0;

            case PortStatus::Other:
                $this->error("Port {$options->port} is used by another program. Choose another one with --port.");

                return 1;

            case PortStatus::Free:
                break;
        }

        $logger = new ConsoleLogger();
        $application = new Application(
            $options->host,
            $options->port,
            $logger,
            settings: new SettingsStore($settings, $settingsFile),
            storage: $this->storage($settings, $settingsFile),
        );

        try {
            $restored = $application->cards->restore();
            $application->start();
        } catch (Throwable $exception) {
            $this->error('Could not start Peep: ' . $exception->getMessage());

            return 1;
        }

        $this->write(sprintf('Peep %s is running at %s. Press Ctrl+C to stop.', Version::VERSION, $application->url()));
        $this->write('History: ' . $application->cards->storage()->describe() . ($restored > 0 ? ", {$restored} cards restored" : '') . '.');
        $this->write('Settings: ' . $settingsFile->path());
        $this->openView($options->open, $application->url());

        $this->waitUntilStopped();
        $application->stop();

        return 0;
    }

    private function storage(Settings $settings, SettingsFile $file): CardStorage
    {
        if (! $settings->history) {
            return new NullCardStorage();
        }

        if (! SqliteCardStorage::isAvailable()) {
            return new NullCardStorage('this PHP has no pdo_sqlite');
        }

        try {
            return SqliteCardStorage::open($file->directory . DIRECTORY_SEPARATOR . SqliteCardStorage::FILE_NAME);
        } catch (PDOException $exception) {
            return new NullCardStorage('cannot open the history file: ' . $exception->getMessage());
        }
    }

    private function openView(OpenMode $mode, string $url): void
    {
        $notice = $this->opener->open($mode, $url);

        if ($notice !== null) {
            $this->write($notice);
        }
    }

    private function waitUntilStopped(): void
    {
        if (defined('SIGINT') && defined('SIGTERM')) {
            try {
                trapSignal([SIGINT, SIGTERM]);

                return;
            } catch (UnsupportedFeatureException) {
                // Fall through: the event loop driver cannot watch signals.
            }
        }

        // No signal support (e.g. no pcntl): run until the process is killed.
        new DeferredFuture()->getFuture()->await();
    }

    private function write(string $line): void
    {
        fwrite($this->output, $line . "\n");
    }

    private function error(string $line): void
    {
        fwrite($this->errors, $line . "\n");
    }
}
