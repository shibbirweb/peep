<?php

namespace ShibbirWeb\Peep;

use BadMethodCallException;
use Closure;
use DateTimeInterface;
use ReflectionFunction;
use ReflectionNamedType;
use ShibbirWeb\Peep\Exception\StopExecutionException;
use ShibbirWeb\Peep\Limiter\RateLimiter;
use ShibbirWeb\Peep\Origin\Origin;
use ShibbirWeb\Peep\Payload\ColorPayload;
use ShibbirWeb\Peep\Payload\ExceptionPayload;
use ShibbirWeb\Peep\Payload\FilePayload;
use ShibbirWeb\Peep\Payload\GenericPayload;
use ShibbirWeb\Peep\Payload\HtmlPayload;
use ShibbirWeb\Peep\Payload\ImagePayload;
use ShibbirWeb\Peep\Payload\JsonPayload;
use ShibbirWeb\Peep\Payload\LabelPayload;
use ShibbirWeb\Peep\Payload\LogPayload;
use ShibbirWeb\Peep\Payload\PayloadInterface;
use ShibbirWeb\Peep\Payload\SizePayload;
use ShibbirWeb\Peep\Payload\TablePayload;
use ShibbirWeb\Peep\Payload\TextPayload;
use ShibbirWeb\Peep\Payload\TracePayload;
use ShibbirWeb\Peep\Serializer\SerializerInterface;
use ShibbirWeb\Peep\Support\Clock;
use ShibbirWeb\Peep\Support\Invader;
use ShibbirWeb\Peep\Support\Uuid;
use Throwable;

/**
 * One card in the viewer. Every method sends to the same card and returns it, so calls chain:
 *
 *     peep($user)->green()->label('Signed in');
 *
 * When Peep is disabled, the viewer is known to be down, or the card is muted by
 * once(), limit() or if(), methods return immediately without serializing anything.
 *
 * Added as macros by the Laravel adapter (only available in Laravel apps):
 *
 * @method mixed showQueries(?callable $callback = null) Show every query; with a callback, only while it runs.
 * @method mixed queries(?callable $callback = null) Alias of showQueries().
 * @method $this stopShowingQueries()
 * @method mixed showSelectQueries(?callable $callback = null)
 * @method mixed showInsertQueries(?callable $callback = null)
 * @method mixed showUpdateQueries(?callable $callback = null)
 * @method mixed showDeleteQueries(?callable $callback = null)
 * @method mixed showSlowQueries(int|callable|null $thresholdOrCallback = null, ?callable $callback = null)
 * @method mixed slowQueries(int|callable|null $thresholdOrCallback = null, ?callable $callback = null)
 * @method $this stopShowingSlowQueries()
 * @method mixed showDuplicateQueries(?callable $callback = null)
 * @method $this stopShowingDuplicateQueries()
 * @method mixed showConditionalQueries(callable $condition, ?callable $callback = null)
 * @method $this stopShowingConditionalQueries()
 * @method mixed countQueries(callable $callback) Show how many queries the callback ran; returns its result.
 * @method mixed showEvents(?callable $callback = null)
 * @method $this stopShowingEvents()
 * @method mixed showJobs(?callable $callback = null)
 * @method $this stopShowingJobs()
 * @method mixed showCache(?callable $callback = null)
 * @method $this stopShowingCache()
 * @method mixed showHttpClientRequests(?callable $callback = null)
 * @method $this stopShowingHttpClientRequests()
 * @method mixed showViews(?callable $callback = null)
 * @method $this stopShowingViews()
 * @method mixed showRequests(?callable $callback = null)
 * @method $this stopShowingRequests()
 * @method $this model(mixed ...$models) Eloquent models with attributes and loaded relations.
 * @method $this models(mixed ...$models)
 * @method $this mailable(object $mailable) The rendered mail.
 * @method $this markdown(string $markdown) Markdown rendered to HTML.
 * @method $this context(string ...$keys) Laravel 11+ Context values.
 * @method $this hiddenContext(string ...$keys)
 */
final class Peep
{
    private const POLL_INTERVAL_MICROSECONDS = 500000;

    /**
     * @var array<string, callable>
     */
    private static $macros = [];

    /**
     * @var string
     */
    private $card;

    /**
     * @var Services
     */
    private $services;

    /**
     * @var bool
     */
    private $muted = false;

    /**
     * @var bool Whether send() already put values on this card; later sends replace them.
     */
    private $hasValues = false;

    public function __construct(Services $services, ?string $card = null)
    {
        $this->services = $services;
        $this->card = $card !== null ? $card : Uuid::v4();
    }

    public function card(): string
    {
        return $this->card;
    }

    // Values and formats ---------------------------------------------------------------

    /**
     * Show one or more values. On a card that already shows values, replace them.
     *
     * @param mixed ...$values
     */
    public function send(...$values): self
    {
        if ($values === [] || ! $this->isActive()) {
            return $this;
        }

        try {
            $nodes = $this->serialize($values, $this->services->serializer);

            if ($this->hasValues) {
                $this->dispatch(new GenericPayload('replace', ['values' => $nodes]));
            } else {
                $this->dispatch(new LogPayload($nodes, $this->origin()));
                $this->hasValues = true;
            }
        } catch (Throwable $exception) {
            // Never let a problem inside Peep reach the app.
        }

        return $this;
    }

    /**
     * Show a value and return it, so it can be used inside an expression.
     *
     * @param mixed $value
     * @return mixed
     */
    public function pass($value)
    {
        $this->send($value);

        return $value;
    }

    /**
     * Show values with Peep's formatting turned off (no casters: every property as stored).
     *
     * @param mixed ...$values
     */
    public function raw(...$values): self
    {
        if ($values !== [] && $this->isActive()) {
            $this->dispatch(new LogPayload($this->serialize($values, $this->services->rawSerializer), $this->origin()));
        }

        return $this;
    }

    /**
     * Send a payload of any type, for framework adapters and macros. The viewer shows
     * types it does not know as raw JSON.
     *
     * @param array<string, mixed> $content
     */
    public function payload(string $type, array $content = []): self
    {
        return $this->content(function (?Origin $origin) use ($type, $content) {
            return new GenericPayload($type, $content, $origin);
        });
    }

    /**
     * Whether calls on this card are sent right now (enabled, viewer reachable, not muted).
     * Lets integrations skip expensive work.
     */
    public function isActive(): bool
    {
        return ! $this->muted && $this->services->dispatcher->isActive();
    }

    /**
     * Show a value with a label in one call (for macros).
     *
     * @param mixed $value
     */
    public function sendCustom($value, string $label): self
    {
        return $this->send($value)->label($label);
    }

    /**
     * Show text as is: whitespace kept, HTML not rendered.
     */
    public function text(string $text): self
    {
        return $this->content(function (?Origin $origin) use ($text) {
            return new TextPayload($text, $origin);
        });
    }

    public function html(string $html): self
    {
        return $this->content(function (?Origin $origin) use ($html) {
            return new HtmlPayload($html, $origin);
        });
    }

    /**
     * Show a JSON string, pretty-printed.
     */
    public function json(string $json): self
    {
        return $this->content(function (?Origin $origin) use ($json) {
            return new JsonPayload($json, $origin);
        });
    }

    /**
     * Encode values with json_encode() and show the JSON.
     *
     * @param mixed ...$values
     */
    public function toJson(...$values): self
    {
        foreach ($values as $value) {
            $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $this->json(is_string($json) ? $json : 'json_encode() failed: ' . json_last_error_msg());
        }

        return $this;
    }

    public function xml(string $xml): self
    {
        return $this->content(function (?Origin $origin) use ($xml) {
            return new GenericPayload('xml', ['xml' => $xml], $origin);
        });
    }

    /**
     * Show an array as a table.
     *
     * @param array<mixed> $rows
     */
    public function table(array $rows, ?string $label = null): self
    {
        return $this->content(function (?Origin $origin) use ($rows, $label) {
            return new TablePayload($this->services->serializer->serialize($rows), $label, $origin);
        });
    }

    /**
     * Show an image from a URL, a file path, a data URI or base64 data.
     */
    public function image(string $source): self
    {
        return $this->content(function (?Origin $origin) use ($source) {
            return new ImagePayload($source, $origin);
        });
    }

    /**
     * Show the contents of a file.
     */
    public function file(string $path): self
    {
        return $this->content(function (?Origin $origin) use ($path) {
            return new FilePayload($path, $this->services->originResolver->pathMapper()->map($path), $origin);
        });
    }

    public function link(string $url, ?string $label = null): self
    {
        return $this->content(function (?Origin $origin) use ($url, $label) {
            return new GenericPayload('link', ['url' => $url, 'label' => $label], $origin);
        });
    }

    public function url(string $url, ?string $label = null): self
    {
        return $this->link($url, $label);
    }

    /**
     * @param DateTimeInterface|mixed $date
     */
    public function date($date): self
    {
        return $this->send($date);
    }

    /**
     * @param object $object
     */
    public function className($object): self
    {
        return $this->send(get_class($object));
    }

    /**
     * Read private properties and call private methods:
     *
     *     peep()->invade($order)->secretTotal;
     *
     * @param object $object
     */
    public function invade($object): Invader
    {
        return new Invader($object, $this);
    }

    /**
     * Show PHP's configuration, or the given ini settings.
     */
    public function phpinfo(string ...$keys): self
    {
        if (! $this->isActive()) {
            return $this;
        }

        if ($keys === []) {
            $extensions = get_loaded_extensions();
            sort($extensions);
            $iniFile = php_ini_loaded_file();

            $info = [
                'PHP version' => PHP_VERSION,
                'SAPI' => PHP_SAPI,
                'OS' => PHP_OS,
                'php.ini' => is_string($iniFile) ? $iniFile : '(none)',
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'error_reporting' => error_reporting(),
                'display_errors' => ini_get('display_errors'),
                'opcache.enable' => ini_get('opcache.enable'),
                'extensions' => implode(', ', $extensions),
            ];
        } else {
            $info = [];
            foreach ($keys as $key) {
                $value = ini_get($key);
                $info[$key] = $value === false ? '(unknown setting)' : $value;
            }
        }

        return $this->table($info, 'PHP');
    }

    public function separator(): self
    {
        return $this->content(function (?Origin $origin) {
            return new GenericPayload('separator', [], $origin);
        });
    }

    // Styling ----------------------------------------------------------------------------

    /**
     * @param string $color One of ColorPayload::COLORS; anything else is ignored.
     */
    public function color(string $color): self
    {
        if (ColorPayload::isSupported($color)) {
            $this->update(new ColorPayload($color));
        }

        return $this;
    }

    public function red(): self
    {
        return $this->color('red');
    }

    public function green(): self
    {
        return $this->color('green');
    }

    public function blue(): self
    {
        return $this->color('blue');
    }

    public function orange(): self
    {
        return $this->color('orange');
    }

    public function purple(): self
    {
        return $this->color('purple');
    }

    public function gray(): self
    {
        return $this->color('gray');
    }

    public function yellow(): self
    {
        return $this->color('yellow');
    }

    public function label(string $label): self
    {
        return $this->update(new LabelPayload($label));
    }

    public function small(): self
    {
        return $this->update(new SizePayload(SizePayload::SMALL));
    }

    public function large(): self
    {
        return $this->update(new SizePayload(SizePayload::LARGE));
    }

    /**
     * Show the card collapsed.
     */
    public function hide(): self
    {
        return $this->update(new GenericPayload('hide'));
    }

    /**
     * Open nested values: expand() one level more, expand(3) three levels,
     * expand('user', 'user.roles') the given keys.
     *
     * @param int|string ...$levelOrKeys
     */
    public function expand(...$levelOrKeys): self
    {
        $level = null;
        $keys = [];

        foreach ($levelOrKeys as $value) {
            if (is_int($value)) {
                $level = $value;
            } elseif ($value !== '') {
                $keys[] = $value;
            }
        }

        if ($level === null && $keys === []) {
            $level = 3;
        }

        return $this->update(new GenericPayload('expand', ['level' => $level, 'keys' => $keys, 'all' => false]));
    }

    public function expandAll(): self
    {
        return $this->update(new GenericPayload('expand', ['level' => null, 'keys' => [], 'all' => true]));
    }

    // Inspection -------------------------------------------------------------------------

    /**
     * Show where the function containing this call was called from.
     */
    public function caller(): self
    {
        if (! $this->isActive()) {
            return $this;
        }

        $frames = TracePayload::normalize($this->services->originResolver->userFrames(2));
        $origin = $this->origin();

        return $this->dispatchContent(TracePayload::caller(isset($frames[1]) ? $frames[1] : null, $origin));
    }

    /**
     * Show the call stack from here outwards.
     */
    public function trace(int $limit = 50): self
    {
        if (! $this->isActive()) {
            return $this;
        }

        $frames = TracePayload::normalize($this->services->originResolver->userFrames($limit), $limit);

        return $this->dispatchContent(TracePayload::trace($frames, $this->origin()));
    }

    public function backtrace(int $limit = 50): self
    {
        return $this->trace($limit);
    }

    /**
     * Show an exception with its trace and source. The card is colored red.
     */
    public function exception(Throwable $exception): self
    {
        if (! $this->isActive()) {
            return $this;
        }

        try {
            $mapper = $this->services->originResolver->pathMapper();
            $this->services->dispatcher->dispatch($this->card, [
                new ExceptionPayload($exception, function (string $path) use ($mapper) {
                    return $mapper->map($path);
                }, $this->origin()),
                new ColorPayload('red'),
            ]);
        } catch (Throwable $inner) {
            // Never let a problem inside Peep reach the app.
        }

        return $this;
    }

    /**
     * Without a callback: the first call starts a stopwatch, later calls show the time
     * since the start and since the previous call. With a callback: time one call.
     */
    public function measure(?callable $callback = null): self
    {
        if (! $this->isActive()) {
            if ($callback !== null) {
                $callback();
            }

            return $this;
        }

        if ($callback === null) {
            $lap = $this->services->stopwatch->lap();

            return $this->dispatchContent(new GenericPayload('measure', $lap, $this->origin()));
        }

        // memory_reset_peak_usage() exists from PHP 8.2; before, the peak covers the whole request.
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage(); // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.memory_reset_peak_usageFound
        }

        $start = Clock::nanoseconds();
        $callback();
        $elapsed = Clock::nanoseconds() - $start;

        return $this->dispatchContent(new GenericPayload('measure', [
            'callable' => true,
            'total_ms' => round($elapsed / 1e6, 3),
            'memory' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ], $this->origin()));
    }

    /**
     * Count how often this line (or a named counter) runs. The count updates one card in place.
     */
    public function count(?string $name = null): self
    {
        if (! $this->isActive()) {
            return $this;
        }

        $origin = $this->origin();
        $key = $name !== null ? $name : self::callSite($origin);
        $counter = $this->services->counter->increment($key);

        $this->services->dispatcher->dispatch($counter['card'], [
            new GenericPayload('count', ['name' => $name, 'count' => $counter['count']], $origin),
        ]);

        return $this;
    }

    public function counterValue(string $name): int
    {
        return $this->services->counter->value($name);
    }

    /**
     * Run a callback; exceptions matching the filter are shown instead of thrown.
     *
     * The filter can be null (every exception), a class name, a list of class names, or a
     * closure whose first parameter is typed with the exception class. A closure filter is
     * called with the exception and this card instead of showing it.
     *
     * @param string|list<string>|Closure|null $filter
     * @return mixed The callback's result, or null when an exception was caught.
     */
    public function catch(callable $callback, $filter = null)
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            if (! self::matches($exception, $filter)) {
                throw $exception;
            }

            if ($filter instanceof Closure) {
                $filter($exception, $this);
            } else {
                $this->exception($exception);
            }

            return null;
        }
    }

    // Control ----------------------------------------------------------------------------

    /**
     * Send only the first time this line runs. Values given here are sent too.
     *
     * @param mixed ...$values
     */
    public function once(...$values): self
    {
        return $this->limit(1)->send(...$values);
    }

    /**
     * Send at most $times times from this line; afterwards every call on the card does nothing.
     */
    public function limit(int $times): self
    {
        if ($this->isActive() && ! $this->services->callLimiter->allow(self::callSite($this->origin()), $times)) {
            $this->muted = true;
        }

        return $this;
    }

    /**
     * Process-wide limits: peep()->rateLimiter()->max(100)->perSecond(10).
     */
    public function rateLimiter(): RateLimiter
    {
        return $this->services->dispatcher->rateLimiter();
    }

    /**
     * Without a callback, the rest of the chain only runs when the condition is true.
     * With a callback, only the callback depends on the condition.
     *
     * @param mixed $condition A truthy value, or a Closure returning one.
     */
    public function if($condition, ?callable $callback = null): self
    {
        $result = $condition instanceof Closure ? (bool) $condition() : (bool) $condition;

        if ($callback !== null) {
            if ($result) {
                $callback($this);
            }

            return $this;
        }

        if (! $result) {
            $this->muted = true;
        }

        return $this;
    }

    /**
     * @param mixed $condition A truthy value, or a Closure returning one.
     */
    public function showIf($condition): self
    {
        return $this->if($condition);
    }

    /**
     * Stop here until "Continue" is clicked in the viewer. "Stop" throws StopExecutionException.
     *
     * @throws StopExecutionException
     */
    public function pause(): self
    {
        if (! $this->isActive()) {
            return $this;
        }

        $name = self::lockName();
        $this->dispatchContent(new GenericPayload('lock', ['name' => $name], $this->origin()));

        if (! $this->services->dispatcher->isActive()) {
            return $this;
        }

        // Pausing is an explicit developer action; it must not hit the time limit while waiting.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        while (true) {
            usleep(self::POLL_INTERVAL_MICROSECONDS);

            $state = $this->services->dispatcher->getJson('/_peep/locks/' . rawurlencode($name));
            if ($state === null || empty($state['active'])) {
                if ($state !== null && ! empty($state['stop'])) {
                    throw new StopExecutionException('Execution stopped from the Peep viewer.');
                }

                return $this;
            }
        }
    }

    /**
     * Stop the script after sending (like dd()).
     *
     * @return never
     */
    public function die(): void
    {
        exit(1);
    }

    // Card and screen --------------------------------------------------------------------

    public function remove(): self
    {
        return $this->update(new GenericPayload('remove'));
    }

    /**
     * @param mixed $condition A truthy value, or a Closure returning one.
     */
    public function removeIf($condition): self
    {
        $result = $condition instanceof Closure ? (bool) $condition() : (bool) $condition;

        return $result ? $this->remove() : $this;
    }

    /**
     * @param mixed $condition A truthy value, or a Closure returning one.
     */
    public function removeWhen($condition): self
    {
        return $this->removeIf($condition);
    }

    /**
     * Clear the viewer for this project and start a new screen, optionally titled.
     */
    public function newScreen(string $title = ''): self
    {
        return $this->content(function (?Origin $origin) use ($title) {
            return new GenericPayload('new_screen', ['title' => $title], $origin);
        });
    }

    public function clearScreen(): self
    {
        return $this->update(new GenericPayload('clear_screen'));
    }

    /**
     * Clear every project's cards.
     */
    public function clearAll(): self
    {
        return $this->update(new GenericPayload('clear_all'));
    }

    /**
     * Send an SMS to the viewer's SMS inbox instead of a provider, for apps with their own
     * SMS code. Nothing is sent to a phone. The viewer may then simulate delivery webhooks.
     */
    public function sms(string $to, string $body, ?string $from = null): self
    {
        if ($this->isActive()) {
            $this->services->dispatcher->postJson('/_peep/sms', ['to' => $to, 'body' => $body, 'from' => $from !== null ? $from : '', 'source' => 'peep']);
        }

        return $this;
    }

    public function notify(string $message): self
    {
        return $this->content(function (?Origin $origin) use ($message) {
            return new GenericPayload('notify', ['message' => $message], $origin);
        });
    }

    public function showApp(): self
    {
        return $this->update(new GenericPayload('show_app'));
    }

    public function hideApp(): self
    {
        return $this->update(new GenericPayload('hide_app'));
    }

    // Enabling ---------------------------------------------------------------------------

    /**
     * Start sending, for every peep() call in this process.
     */
    public function enable(): self
    {
        $this->services->dispatcher->enable();

        return $this;
    }

    /**
     * Stop sending, for every peep() call in this process.
     */
    public function disable(): self
    {
        $this->services->dispatcher->disable();

        return $this;
    }

    public function enabled(): bool
    {
        return $this->services->dispatcher->isEnabled();
    }

    public function disabled(): bool
    {
        return ! $this->services->dispatcher->isEnabled();
    }

    // Macros -----------------------------------------------------------------------------

    /**
     * Add a method: Peep::macro('money', function ($amount) { return $this->send(...); });
     * Closures are bound to the card, so $this is the Peep instance.
     *
     * @param-closure-this Peep $macro
     */
    public static function macro(string $name, callable $macro): void
    {
        self::$macros[$name] = $macro;
    }

    public static function hasMacro(string $name): bool
    {
        return isset(self::$macros[$name]);
    }

    /**
     * @param array<mixed> $arguments
     * @return mixed
     */
    public function __call(string $method, array $arguments)
    {
        if (! isset(self::$macros[$method])) {
            throw new BadMethodCallException(sprintf('Method %s::%s() does not exist.', self::class, $method));
        }

        $macro = self::$macros[$method];

        if ($macro instanceof Closure) {
            $bound = Closure::bind($macro, $this, self::class);
            $macro = $bound instanceof Closure ? $bound : $macro;
        }

        return call_user_func_array($macro, $arguments);
    }

    // Internals --------------------------------------------------------------------------

    private function origin(): ?Origin
    {
        return $this->services->originResolver->resolve();
    }

    /**
     * Sends a content payload built only when the card is active (no work otherwise).
     *
     * @param callable(Origin|null): PayloadInterface $build
     */
    private function content(callable $build): self
    {
        if (! $this->isActive()) {
            return $this;
        }

        try {
            $this->dispatch($build($this->origin()));
        } catch (Throwable $exception) {
            // Never let a problem inside Peep reach the app.
        }

        return $this;
    }

    private function dispatchContent(PayloadInterface $payload): self
    {
        $this->dispatch($payload);

        return $this;
    }

    private function update(PayloadInterface $payload): self
    {
        if ($this->isActive()) {
            $this->dispatch($payload);
        }

        return $this;
    }

    private function dispatch(PayloadInterface $payload): void
    {
        $this->services->dispatcher->dispatch($this->card, [$payload]);
    }

    /**
     * @param array<mixed> $values
     * @return list<array<string, mixed>>
     */
    private function serialize(array $values, SerializerInterface $serializer): array
    {
        $nodes = [];
        foreach ($values as $value) {
            $nodes[] = $serializer->serialize($value);
        }

        return $nodes;
    }

    private static function callSite(?Origin $origin): string
    {
        return $origin !== null ? $origin->file() . ':' . $origin->line() : 'unknown';
    }

    private static function lockName(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (Throwable $exception) {
            return md5(uniqid('', true));
        }
    }

    /**
     * @param string|list<string>|Closure|null $filter
     */
    private static function matches(Throwable $exception, $filter): bool
    {
        if ($filter === null) {
            return true;
        }

        if (is_string($filter)) {
            return $exception instanceof $filter;
        }

        if (is_array($filter)) {
            foreach ($filter as $class) {
                if ($exception instanceof $class) {
                    return true;
                }
            }

            return false;
        }

        $parameters = (new ReflectionFunction($filter))->getParameters();
        $type = isset($parameters[0]) ? $parameters[0]->getType() : null;

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            $class = $type->getName();

            return $exception instanceof $class;
        }

        // Union types (PHP 8): accept when any member matches.
        if ($type !== null && method_exists($type, 'getTypes')) {
            foreach ($type->getTypes() as $member) {
                if ($member instanceof ReflectionNamedType && ! $member->isBuiltin()) {
                    $class = $member->getName();
                    if ($exception instanceof $class) {
                        return true;
                    }
                }
            }

            return false;
        }

        return true;
    }
}
