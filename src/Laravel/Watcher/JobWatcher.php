<?php

namespace ShibbirWeb\Peep\Laravel\Watcher;

use ShibbirWeb\Peep\Support\Clock;
use ShibbirWeb\Peep\Support\Uuid;
use Throwable;

/**
 * Queued jobs: queued (Laravel 8+), processing, processed and failed, on one card per job.
 */
final class JobWatcher extends AbstractWatcher
{
    private const QUEUED = 'Illuminate\\Queue\\Events\\JobQueued';
    private const PROCESSING = 'Illuminate\\Queue\\Events\\JobProcessing';
    private const PROCESSED = 'Illuminate\\Queue\\Events\\JobProcessed';
    private const FAILED = 'Illuminate\\Queue\\Events\\JobFailed';

    /**
     * @var array<string, array{card: string, started: int}> job id => card and start time
     */
    private $running = [];

    public function isSupported(): bool
    {
        return class_exists(self::PROCESSING);
    }

    public function register($app): void
    {
        $events = $app->make('events');

        if (class_exists(self::QUEUED)) {
            $events->listen(self::QUEUED, function ($event) {
                $this->send(null, [
                    'status' => 'queued',
                    'name' => is_object($event->job) ? get_class($event->job) : (string) $event->job,
                    'connection' => isset($event->connectionName) ? $event->connectionName : null,
                    'id' => isset($event->id) ? (string) $event->id : null,
                ]);
            });
        }

        $events->listen(self::PROCESSING, function ($event) {
            $this->started($event);
        });

        $events->listen(self::PROCESSED, function ($event) {
            $this->finished($event, 'processed', null);
        });

        $events->listen(self::FAILED, function ($event) {
            $this->finished($event, 'failed', isset($event->exception) ? $event->exception : null);
        });
    }

    protected function configKey(): string
    {
        return 'send_jobs';
    }

    /**
     * @param \Illuminate\Queue\Events\JobProcessing $event
     */
    private function started($event): void
    {
        if (! $this->isOn()) {
            return;
        }

        $id = self::jobId($event->job);
        $this->running[$id] = ['card' => Uuid::v4(), 'started' => Clock::nanoseconds()];

        $this->send($this->running[$id]['card'], ['status' => 'processing'] + self::describe($event));
    }

    /**
     * @param \Illuminate\Queue\Events\JobProcessed|\Illuminate\Queue\Events\JobFailed $event
     * @param Throwable|null $exception
     */
    private function finished($event, string $status, $exception): void
    {
        $id = self::jobId($event->job);
        $running = isset($this->running[$id]) ? $this->running[$id] : null;
        unset($this->running[$id]);

        if (! $this->isOn()) {
            return;
        }

        $content = ['status' => $status] + self::describe($event);
        if ($running !== null) {
            $content['time_ms'] = round((Clock::nanoseconds() - $running['started']) / 1e6, 2);
        }
        if ($exception instanceof Throwable) {
            $content['exception'] = get_class($exception) . ': ' . $exception->getMessage();
        }

        $this->send($running !== null ? $running['card'] : null, $content);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function send(?string $card, array $content): void
    {
        if (! $this->isOn()) {
            return;
        }

        try {
            $peep = $this->peep($card)->payload('job', $content);

            if ($content['status'] === 'failed') {
                $peep->red();
            } elseif ($content['status'] === 'processed') {
                $peep->green();
            }
        } catch (Throwable $exception) {
            // Never let Peep break the queue.
        }
    }

    /**
     * @param \Illuminate\Queue\Events\JobProcessing|\Illuminate\Queue\Events\JobProcessed|\Illuminate\Queue\Events\JobFailed $event
     * @return array<string, mixed>
     */
    private static function describe($event): array
    {
        $job = $event->job;

        return [
            'name' => method_exists($job, 'resolveName') ? $job->resolveName() : get_class($job),
            'queue' => method_exists($job, 'getQueue') ? $job->getQueue() : null,
            'connection' => isset($event->connectionName) ? $event->connectionName : null,
            'attempts' => method_exists($job, 'attempts') ? $job->attempts() : null,
            'id' => self::jobId($job),
        ];
    }

    /**
     * @param \Illuminate\Contracts\Queue\Job $job
     */
    private static function jobId($job): string
    {
        $id = method_exists($job, 'getJobId') ? $job->getJobId() : null;

        return is_scalar($id) && $id !== '' ? (string) $id : spl_object_hash($job);
    }
}
