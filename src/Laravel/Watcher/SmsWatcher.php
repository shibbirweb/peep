<?php

namespace ShibbirWeb\Peep\Laravel\Watcher;

use Throwable;

/**
 * SMS notifications (Vonage / Nexmo, and the Twilio notification channel) go to the
 * viewer's SMS inbox. With "block_real_sms" on, and outside production, the real send
 * is skipped, so nothing reaches a phone during development.
 */
final class SmsWatcher extends AbstractWatcher
{
    private const SENDING = 'Illuminate\\Notifications\\Events\\NotificationSending';

    /**
     * Channel name (as used in via()) => notification method and route name.
     */
    private const CHANNELS = [
        'vonage' => ['toVonage', 'vonage'],
        'nexmo' => ['toNexmo', 'nexmo'],
        'twilio' => ['toTwilio', 'twilio'],
    ];

    public function isSupported(): bool
    {
        return class_exists(self::SENDING);
    }

    public function register($app): void
    {
        $production = $app->environment('production') === true;

        $app->make('events')->listen(self::SENDING, function ($event) use ($production) {
            return $this->handle($event->notifiable, $event->notification, (string) $event->channel, $production);
        });
    }

    /**
     * @param mixed $notifiable
     * @param object $notification
     * @return false|null False stops Laravel from sending for real.
     */
    public function handle($notifiable, $notification, string $channel, bool $production = false)
    {
        $name = self::channelName($channel);
        if ($name === null) {
            return null;
        }

        if ($this->isOn()) {
            try {
                $this->capture($notifiable, $notification, $name);
            } catch (Throwable $exception) {
                // Never let Peep break sending notifications.
            }
        }

        $block = isset($this->config['block_real_sms']) && filter_var($this->config['block_real_sms'], FILTER_VALIDATE_BOOLEAN);

        return $block && ! $production ? false : null;
    }

    protected function configKey(): string
    {
        return 'send_sms';
    }

    /**
     * @param mixed $notifiable
     * @param object $notification
     */
    private function capture($notifiable, $notification, string $name): void
    {
        list($method, $route) = self::CHANNELS[$name];

        $message = method_exists($notification, $method) ? $notification->{$method}($notifiable) : null;
        $to = is_object($notifiable) && method_exists($notifiable, 'routeNotificationFor') ? $notifiable->routeNotificationFor($route, $notification) : null;

        $this->factory->dispatcher()->postJson('/_peep/sms', [
            'to' => is_scalar($to) ? (string) $to : '',
            'from' => self::property($message, 'from'),
            'body' => is_string($message) ? $message : self::property($message, 'content'),
            'source' => 'laravel',
            'channel' => $name,
            'notification' => get_class($notification),
        ]);
    }

    private static function channelName(string $channel): ?string
    {
        $short = strtolower(substr((string) strrchr('\\' . $channel, '\\'), 1));

        if (isset(self::CHANNELS[$short])) {
            return $short;
        }

        // Channel classes: NotificationChannels\Twilio\TwilioChannel, VonageSmsChannel, NexmoSmsChannel.
        foreach (['twilio', 'vonage', 'nexmo'] as $name) {
            if (strpos($short, $name) === 0) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param mixed $message
     */
    private static function property($message, string $name): string
    {
        if (! is_object($message)) {
            return '';
        }

        if (isset($message->{$name}) && is_scalar($message->{$name})) {
            return (string) $message->{$name};
        }

        $getter = 'get' . ucfirst($name);

        return method_exists($message, $getter) && is_scalar($message->{$getter}()) ? (string) $message->{$getter}() : '';
    }
}
