<?php

namespace ShibbirWeb\Peep\Laravel\Macro;

use LogicException;
use ShibbirWeb\Peep\Laravel\Watcher\AbstractWatcher;
use ShibbirWeb\Peep\Laravel\Watcher\CacheWatcher;
use ShibbirWeb\Peep\Laravel\Watcher\EventWatcher;
use ShibbirWeb\Peep\Laravel\Watcher\HttpClientWatcher;
use ShibbirWeb\Peep\Laravel\Watcher\JobWatcher;
use ShibbirWeb\Peep\Laravel\Watcher\QueryWatcher;
use ShibbirWeb\Peep\Laravel\Watcher\RequestWatcher;
use ShibbirWeb\Peep\Laravel\Watcher\ViewWatcher;
use ShibbirWeb\Peep\Peep;

/**
 * Laravel-only Peep methods, added as macros so the core client never depends on Laravel:
 *
 *     peep()->showQueries();
 *     peep()->countQueries(function () { ... });
 *     peep()->model($user);
 *
 * Every show...() method takes an optional callback: then it applies only while the
 * callback runs and returns the callback's result.
 */
final class PeepMacros
{
    /**
     * Watcher behind each show...() / stopShowing...() pair, by method suffix.
     */
    private const TOGGLES = [
        'Events' => EventWatcher::class,
        'Jobs' => JobWatcher::class,
        'Cache' => CacheWatcher::class,
        'HttpClientRequests' => HttpClientWatcher::class,
        'Views' => ViewWatcher::class,
        'Requests' => RequestWatcher::class,
    ];

    private function __construct()
    {
    }

    /**
     * @param \Illuminate\Contracts\Container\Container $container
     */
    public static function register($container): void
    {
        self::registerQueries($container);

        foreach (self::TOGGLES as $suffix => $class) {
            Peep::macro('show' . $suffix, function (?callable $callback = null) use ($container, $class) {
                $watcher = PeepMacros::watcher($container, $class);

                if ($callback !== null) {
                    return $watcher->during($callback);
                }

                $watcher->enable();

                return $this;
            });

            Peep::macro('stopShowing' . $suffix, function () use ($container, $class) {
                PeepMacros::watcher($container, $class)->disable();

                return $this;
            });
        }

        self::registerObjects();
    }

    /**
     * @param \Illuminate\Contracts\Container\Container $container
     */
    private static function registerQueries($container): void
    {
        $queries = function () use ($container): QueryWatcher {
            $watcher = PeepMacros::watcher($container, QueryWatcher::class);
            if (! $watcher instanceof QueryWatcher) {
                throw new LogicException('The query watcher is not registered.');
            }

            return $watcher;
        };

        Peep::macro('showQueries', function (?callable $callback = null) use ($queries) {
            if ($callback !== null) {
                return $queries()->during($callback);
            }

            $queries()->enable();

            return $this;
        });

        Peep::macro('queries', function (?callable $callback = null) {
            return $this->__call('showQueries', [$callback]);
        });

        Peep::macro('stopShowingQueries', function () use ($queries) {
            $queries()->disable();
            $queries()->onlyTypes(null);

            return $this;
        });

        foreach (['select', 'insert', 'update', 'delete'] as $type) {
            Peep::macro('show' . ucfirst($type) . 'Queries', function (?callable $callback = null) use ($queries, $type) {
                if ($callback !== null) {
                    return $queries()->duringTypes([$type], $callback);
                }

                $queries()->onlyTypes([$type]);
                $queries()->enable();

                return $this;
            });
        }

        // showSlowQueries(), showSlowQueries(100), showSlowQueries(100, $callback), showSlowQueries($callback)
        Peep::macro('showSlowQueries', function ($thresholdOrCallback = null, ?callable $callback = null) use ($queries) {
            if (is_callable($thresholdOrCallback)) {
                return $queries()->duringSlow($thresholdOrCallback);
            }

            $threshold = is_numeric($thresholdOrCallback) ? (int) $thresholdOrCallback : null;

            if ($callback !== null) {
                return $queries()->duringSlow($callback, $threshold);
            }

            $queries()->showSlow(true, $threshold);

            return $this;
        });

        Peep::macro('slowQueries', function ($thresholdOrCallback = null, ?callable $callback = null) {
            return $this->__call('showSlowQueries', [$thresholdOrCallback, $callback]);
        });

        Peep::macro('stopShowingSlowQueries', function () use ($queries) {
            $queries()->showSlow(false);

            return $this;
        });

        Peep::macro('showDuplicateQueries', function (?callable $callback = null) use ($queries) {
            if ($callback !== null) {
                return $queries()->duringDuplicates($callback);
            }

            $queries()->showDuplicates(true);

            return $this;
        });

        Peep::macro('stopShowingDuplicateQueries', function () use ($queries) {
            $queries()->showDuplicates(false);

            return $this;
        });

        Peep::macro('showConditionalQueries', function (callable $condition, ?callable $callback = null) use ($queries) {
            $queries()->showWhen($condition);

            if ($callback === null) {
                return $this;
            }

            try {
                return $callback();
            } finally {
                $queries()->showWhen(null);
            }
        });

        Peep::macro('stopShowingConditionalQueries', function () use ($queries) {
            $queries()->showWhen(null);

            return $this;
        });

        Peep::macro('countQueries', function (callable $callback) use ($queries) {
            $counted = $queries()->count($callback);

            $this->payload('query_count', [
                'count' => $counted['count'],
                'time_ms' => round($counted['time'], 2),
            ]);

            return $counted['result'];
        });
    }

    /**
     * @param \Illuminate\Contracts\Container\Container $container
     * @param class-string<AbstractWatcher> $class
     */
    public static function watcher($container, string $class): AbstractWatcher
    {
        $watcher = $container->make($class);

        if (! $watcher instanceof AbstractWatcher) {
            throw new LogicException("{$class} is not a Peep watcher.");
        }

        return $watcher;
    }

    private static function registerObjects(): void
    {
        Peep::macro('model', function (...$models) {
            foreach ($models as $model) {
                $this->send($model)->label(is_object($model) ? get_class($model) : gettype($model));
            }

            return $this;
        });

        Peep::macro('models', function (...$models) {
            return $this->__call('model', $models);
        });

        Peep::macro('mailable', function ($mailable) {
            if (! is_object($mailable) || ! method_exists($mailable, 'render')) {
                return $this->send($mailable);
            }

            // Render first: build() usually sets the subject.
            $html = (string) $mailable->render();
            $subject = isset($mailable->subject) && is_string($mailable->subject) ? $mailable->subject : get_class($mailable);

            return $this->html($html)->label('Mailable: ' . $subject);
        });

        Peep::macro('markdown', function (string $markdown) {
            // Str::markdown() exists from Laravel 8; older versions parse with Illuminate\Mail\Markdown.
            if (class_exists('Illuminate\\Support\\Str') && in_array('markdown', get_class_methods('Illuminate\\Support\\Str'), true)) {
                $html = \Illuminate\Support\Str::markdown($markdown);
            } elseif (class_exists('Illuminate\\Mail\\Markdown')) {
                $html = (string) \Illuminate\Mail\Markdown::parse($markdown);
            } else {
                return $this->text($markdown);
            }

            return $this->html((string) $html);
        });

        // Laravel 11+ Context.
        Peep::macro('context', function (string ...$keys) {
            $context = 'Illuminate\\Support\\Facades\\Context';
            if (! class_exists($context)) {
                return $this->text('Context needs Laravel 11 or newer.');
            }

            return $this->send($keys === [] ? $context::all() : $context::only($keys))->label('Context');
        });

        Peep::macro('hiddenContext', function (string ...$keys) {
            $context = 'Illuminate\\Support\\Facades\\Context';
            if (! class_exists($context)) {
                return $this->text('Context needs Laravel 11 or newer.');
            }

            return $this->send($keys === [] ? $context::allHidden() : $context::onlyHidden($keys))->label('Hidden context');
        });
    }
}
