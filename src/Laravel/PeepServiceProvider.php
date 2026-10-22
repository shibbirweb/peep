<?php

namespace ShibbirWeb\Peep\Laravel;

use Illuminate\Support\ServiceProvider;
use ShibbirWeb\Peep\Config\Config;
use ShibbirWeb\Peep\Laravel\Blade\PeepDirectives;
use ShibbirWeb\Peep\Laravel\Caster\CollectionCaster;
use ShibbirWeb\Peep\Laravel\Caster\ModelCaster;
use ShibbirWeb\Peep\Laravel\Mail\MailTransportRegistrar;
use ShibbirWeb\Peep\Laravel\Macro\FrameworkMacros;
use ShibbirWeb\Peep\Laravel\Macro\PeepMacros;
use ShibbirWeb\Peep\Laravel\Watcher\CacheWatcher;
use ShibbirWeb\Peep\Laravel\Watcher\DeprecationWatcher;
use ShibbirWeb\Peep\Laravel\Watcher\DumpWatcher;
use ShibbirWeb\Peep\Laravel\Watcher\EventWatcher;
use ShibbirWeb\Peep\Laravel\Watcher\ExceptionWatcher;
use ShibbirWeb\Peep\Laravel\Watcher\HttpClientWatcher;
use ShibbirWeb\Peep\Laravel\Watcher\JobWatcher;
use ShibbirWeb\Peep\Laravel\Watcher\LogWatcher;
use ShibbirWeb\Peep\Laravel\Watcher\MailWatcher;
use ShibbirWeb\Peep\Laravel\Watcher\QueryWatcher;
use ShibbirWeb\Peep\Laravel\Watcher\RequestWatcher;
use ShibbirWeb\Peep\Laravel\Watcher\SmsWatcher;
use ShibbirWeb\Peep\Laravel\Watcher\ViewWatcher;
use ShibbirWeb\Peep\Laravel\Watcher\WatcherInterface;
use ShibbirWeb\Peep\PeepFactory;
use ShibbirWeb\Peep\Serializer\ValueSerializer;
use ShibbirWeb\Peep\Transport\TransportInterface;

/**
 * Laravel integration, auto-discovered. When Peep is disabled (by default in production)
 * boot() registers nothing, so the app pays nothing.
 */
class PeepServiceProvider extends ServiceProvider
{
    public const WATCHERS = [
        QueryWatcher::class,
        EventWatcher::class,
        JobWatcher::class,
        CacheWatcher::class,
        HttpClientWatcher::class,
        ViewWatcher::class,
        RequestWatcher::class,
        LogWatcher::class,
        ExceptionWatcher::class,
        MailWatcher::class,
        SmsWatcher::class,
        DumpWatcher::class,
        DeprecationWatcher::class,
    ];

    private const CONFIG_FILE = __DIR__ . '/../../config/peep.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_FILE, 'peep');

        if (! $this->app->bound(PeepFactory::class)) {
            $this->app->singleton(PeepFactory::class, function ($app) {
                return self::makeFactory($app);
            });
        }

        foreach (self::WATCHERS as $class) {
            $this->app->singleton($class, function ($app) use ($class) {
                return new $class($app->make(PeepFactory::class), self::settings($app));
            });
        }

        // Registered even when Peep is off: an app with MAIL_MAILER=peep must still resolve its mailer.
        MailTransportRegistrar::register($this->app);
    }

    public function boot(): void
    {
        if (function_exists('config_path')) {
            $this->publishes([self::CONFIG_FILE => config_path('peep.php')], 'peep-config');
        }

        $factory = $this->app->make(PeepFactory::class);
        PeepFactory::setInstance($factory);

        if (! $factory->dispatcher()->isEnabled()) {
            return;
        }

        // Origins point at the app's own code, not at the framework or packages.
        foreach (self::vendorDirectories($this->app) as $directory) {
            $factory->originResolver()->skip($directory);
        }

        (new ContextTracker($factory->context()))->register($this->app);

        PeepMacros::register($this->app);
        FrameworkMacros::register();

        if ($this->app->bound('blade.compiler')) {
            PeepDirectives::register($this->app->make('blade.compiler'));
        }

        $this->registerWatchers();
    }

    /**
     * Builds the factory from the "peep" config. The transport can be replaced (tests).
     *
     * @param \Illuminate\Contracts\Foundation\Application $app
     */
    public static function makeFactory($app, ?TransportInterface $transport = null): PeepFactory
    {
        $settings = self::settings($app);
        $enabled = $settings['enabled'] === null || $settings['enabled'] === ''
            ? ! $app->environment('production')
            : Config::toBool($settings['enabled'], false);

        $config = Config::fromArray([
            'enabled' => $enabled,
            'host' => $settings['host'],
            'port' => $settings['port'],
            'project' => self::project($app, $settings),
            'remote_path' => $settings['remote_path'],
            'local_path' => $settings['local_path'],
            'serializer' => $settings['serializer'],
            'token' => $settings['token'],
        ]);

        // The Laravel casters (models without queries, collections) apply to Peep's serializer;
        // with "var-dumper" the factory uses VarDumper's casters instead.
        $serializer = $config->serializer() === 'peep'
            ? ValueSerializer::fromConfig($config)->withCaster(new CollectionCaster())->withCaster(new ModelCaster())
            : null;

        return new PeepFactory($config, $transport, $serializer);
    }

    /**
     * The app's vendor/ directory, and the one the framework was loaded from. They differ
     * when the app lives elsewhere (a test harness), and Composer's own ClassLoader cannot
     * be used to find it: tools such as Laravel Herd preload a ClassLoader of their own.
     *
     * @param \Illuminate\Contracts\Foundation\Application $app
     * @return list<string>
     */
    private static function vendorDirectories($app): array
    {
        // basePath() takes no argument on Laravel 5.5, so the path is joined here.
        $directories = [rtrim($app->basePath(), '/\\') . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR];

        $frameworkFile = (new \ReflectionClass(get_class($app)))->getFileName();
        $marker = DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR;
        $position = is_string($frameworkFile) ? strrpos($frameworkFile, $marker) : false;

        if ($position !== false) {
            $directories[] = substr((string) $frameworkFile, 0, $position + strlen($marker));
        }

        return array_values(array_unique($directories));
    }

    private function registerWatchers(): void
    {
        $exceptions = $this->app->make(ExceptionWatcher::class);
        $logs = $this->app->make(LogWatcher::class);
        if ($logs instanceof LogWatcher && $exceptions instanceof ExceptionWatcher) {
            $logs->setExceptionWatcher($exceptions);
        }

        foreach (self::WATCHERS as $class) {
            $watcher = $this->app->make($class);

            if ($watcher instanceof WatcherInterface && $watcher->isSupported()) {
                $watcher->register($this->app);
            }
        }
    }

    /**
     * @param \Illuminate\Contracts\Foundation\Application $app
     * @return array<string, mixed>
     */
    private static function settings($app): array
    {
        $settings = $app->make('config')->get('peep', []);

        return (is_array($settings) ? $settings : []) + [
            'enabled' => null,
            'host' => null,
            'port' => null,
            'project' => null,
            'remote_path' => null,
            'local_path' => null,
            'serializer' => 'peep',
            'token' => null,
        ];
    }

    /**
     * @param \Illuminate\Contracts\Foundation\Application $app
     * @param array<string, mixed> $settings
     */
    private static function project($app, array $settings): string
    {
        if (is_string($settings['project']) && $settings['project'] !== '') {
            return $settings['project'];
        }

        $name = $app->make('config')->get('app.name');

        return is_string($name) && $name !== '' && $name !== 'Laravel' ? $name : basename($app->basePath());
    }
}
