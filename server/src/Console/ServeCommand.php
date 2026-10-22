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
use Revolt\EventLoop;

use function Amp\async;
use function Amp\trapSignal;

/**
 * `peep-server serve`: starts the viewer, or only opens the view when one already runs.
 */
final readonly class ServeCommand
{
    public const float PARENT_CHECK_SECONDS = 1.0;

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

        if ($settings->smtp) {
            $smtpError = $application->startSmtp();
            $this->write($smtpError === null
                ? "SMTP: catching mail on {$application->smtpAddress()} (any login; the username picks the project)."
                : "SMTP: not started, port {$settings->smtpPort} is not free ({$smtpError}). Change it in the settings.");
        }
        $this->write('Settings: ' . $settingsFile->path());
        $this->openView($options->open, $application->url());

        $this->waitUntilStopped($options->exitWith !== null ? new ParentProcess($options->exitWith) : null);
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

    /**
     * Until Ctrl+C or SIGTERM, or until the parent process (the desktop app) is gone.
     */
    private function waitUntilStopped(?ParentProcess $parent): void
    {
        $stopped = new DeferredFuture();
        $stop = static function () use ($stopped): void {
            if (! $stopped->isComplete()) {
                $stopped->complete();
            }
        };

        if (defined('SIGINT') && defined('SIGTERM')) {
            async(static function () use ($stop): void {
                try {
                    trapSignal([SIGINT, SIGTERM]);
                    $stop();
                } catch (UnsupportedFeatureException) {
                    // The event loop driver cannot watch signals: run until killed.
                }
            })->ignore();
        }

        $watch = $parent !== null
            ? EventLoop::repeat(self::PARENT_CHECK_SECONDS, static function () use ($parent, $stop): void {
                if (! $parent->isRunning()) {
                    $stop();
                }
            })
            : null;

        $stopped->getFuture()->await();

        if ($watch !== null) {
            EventLoop::cancel($watch);
        }
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
