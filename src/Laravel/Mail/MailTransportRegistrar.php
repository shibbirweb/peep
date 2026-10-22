<?php

namespace ShibbirWeb\Peep\Laravel\Mail;

use ShibbirWeb\Peep\PeepFactory;

/**
 * Adds the "peep" mailer: MAIL_MAILER=peep (Laravel 7+) or MAIL_DRIVER=peep (5.5 to 6)
 * sends every mail to the viewer's inbox over HTTP, with no SMTP setup.
 */
final class MailTransportRegistrar
{
    private function __construct()
    {
    }

    /**
     * @param \Illuminate\Contracts\Foundation\Application $app
     */
    public static function register($app): void
    {
        // Laravel 7+ needs a "mailers.peep" entry to select the transport; add it unless the app has one.
        $config = $app->make('config');
        if (is_array($config->get('mail.mailers')) && ! $config->has('mail.mailers.peep')) {
            $config->set('mail.mailers.peep', ['transport' => 'peep']);
        }

        // Laravel 7+: the mail manager. Laravel 5.5 to 6: the Swift transport manager.
        $extend = function ($manager) use ($app) {
            if (is_object($manager) && method_exists($manager, 'extend')) {
                $manager->extend('peep', function () use ($app) {
                    return self::transport($app->make(PeepFactory::class));
                });
            }
        };

        self::whenResolved($app, 'mail.manager', $extend);

        if (! $app->bound('mail.manager')) {
            self::whenResolved($app, 'swift.transport', $extend);
        }
    }

    /**
     * @return object PeepSymfonyTransport or PeepSwiftTransport
     */
    public static function transport(PeepFactory $factory)
    {
        if (class_exists('Symfony\\Component\\Mailer\\Transport\\AbstractTransport')) {
            return new PeepSymfonyTransport($factory);
        }

        return new PeepSwiftTransport($factory);
    }

    /**
     * @param \Illuminate\Contracts\Foundation\Application $app
     */
    private static function whenResolved($app, string $abstract, \Closure $callback): void
    {
        if ($app->resolved($abstract)) {
            $callback($app->make($abstract));

            return;
        }

        $app->afterResolving($abstract, $callback);
    }
}
