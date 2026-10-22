<?php

namespace ShibbirWeb\Peep\Laravel;

use ShibbirWeb\Peep\Context\ExecutionContext;

/**
 * Gives each queued job and artisan command its own request id inside long-running
 * processes (queue workers, Octane), so the viewer groups their cards.
 */
final class ContextTracker
{
    /**
     * @var ExecutionContext
     */
    private $context;

    public function __construct(ExecutionContext $context)
    {
        $this->context = $context;
    }

    /**
     * @param \Illuminate\Contracts\Foundation\Application $app
     */
    public function register($app): void
    {
        $events = $app->make('events');

        $begin = function (string $kind, string $label) {
            $this->context->begin($kind, $label);
        };
        $end = function () {
            $this->context->end();
        };

        if (class_exists('Illuminate\\Queue\\Events\\JobProcessing')) {
            $events->listen('Illuminate\\Queue\\Events\\JobProcessing', function ($event) use ($begin) {
                $begin('job', self::jobName($event));
            });
            $events->listen('Illuminate\\Queue\\Events\\JobProcessed', $end);
            $events->listen('Illuminate\\Queue\\Events\\JobFailed', $end);
            // A job that throws and will be retried fires this instead of JobProcessed.
            // When it also fails for good, end() runs twice; end() never removes the worker's own context.
            $events->listen('Illuminate\\Queue\\Events\\JobExceptionOccurred', $end);
        }

        if (class_exists('Illuminate\\Console\\Events\\CommandStarting')) {
            $events->listen('Illuminate\\Console\\Events\\CommandStarting', function ($event) use ($begin) {
                $begin('cli', 'artisan ' . (string) $event->command);
            });
            $events->listen('Illuminate\\Console\\Events\\CommandFinished', $end);
        }

        // Octane serves many requests per process; start a new context for each.
        $events->listen('Laravel\\Octane\\Events\\RequestReceived', function () {
            $this->context->reset();
        });
    }

    /**
     * @param \Illuminate\Queue\Events\JobProcessing $event
     */
    private static function jobName($event): string
    {
        $job = $event->job;

        return method_exists($job, 'resolveName') ? (string) $job->resolveName() : 'job';
    }
}
