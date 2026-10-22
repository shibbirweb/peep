<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Console;

use Amp\DeferredFuture;
use Revolt\EventLoop\UnsupportedFeatureException;
use ShibbirWeb\PeepServer\Application;
use ShibbirWeb\PeepServer\Version;
use InvalidArgumentException;
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
    ) {}

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments): int
    {
        try {
            $options = ServeOptions::fromArguments($arguments);
        } catch (InvalidArgumentException $exception) {
            $this->error('Error: ' . $exception->getMessage());
            $this->error(Cli::USAGE);

            return 2;
        }

        $application = new Application($options->host, $options->port, new ConsoleLogger());

        switch ($this->probe->check($options->host, $options->port)) {
            case PortStatus::Peep:
                $this->write("Peep is already running at {$application->url()}.");
                $this->openView($options->open, $application->url());

                return 0;

            case PortStatus::Other:
                $this->error("Port {$options->port} is used by another program. Choose another one with --port.");

                return 1;

            case PortStatus::Free:
                break;
        }

        try {
            $application->start();
        } catch (Throwable $exception) {
            $this->error('Could not start Peep: ' . $exception->getMessage());

            return 1;
        }

        $this->write(sprintf('Peep %s is running at %s. Press Ctrl+C to stop.', Version::VERSION, $application->url()));
        $this->openView($options->open, $application->url());

        $this->waitUntilStopped();
        $application->stop();

        return 0;
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
