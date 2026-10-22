<?php

namespace ShibbirWeb\Peep\Bridge;

use ShibbirWeb\Peep\PeepFactory;
use Throwable;

/**
 * Sends one log message as a "log_message" card, used by the PSR-3 logger and the
 * Monolog handler. A Throwable under the "exception" context key is shown as an
 * exception card instead.
 */
final class LogRecord
{
    private const COLORS = [
        'emergency' => 'red',
        'alert' => 'red',
        'critical' => 'red',
        'error' => 'red',
        'warning' => 'orange',
        'notice' => 'blue',
        'info' => 'blue',
        'debug' => 'gray',
    ];

    private function __construct()
    {
    }

    /**
     * @param mixed $message
     * @param array<mixed> $context
     */
    public static function send(PeepFactory $factory, string $level, $message, array $context, ?string $channel = null): void
    {
        $peep = $factory->make();
        if (! $peep->isActive()) {
            return;
        }

        $level = strtolower($level);

        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $peep->exception($context['exception'])->label(ucfirst($level) . ': ' . self::text($message, $context));

            return;
        }

        $content = [
            'level' => $level,
            'message' => self::interpolate(self::text($message, $context), $context),
            'context' => $factory->services()->serializer->serialize($context),
        ];

        if ($channel !== null) {
            $content['channel'] = $channel;
        }

        $peep->payload('log_message', $content);

        if (isset(self::COLORS[$level])) {
            $peep->color(self::COLORS[$level]);
        }
    }

    /**
     * PSR-3 placeholders: "User {id} signed in" with ['id' => 7].
     *
     * @param array<mixed> $context
     */
    public static function interpolate(string $message, array $context): string
    {
        if (strpos($message, '{') === false) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null || (is_object($value) && method_exists($value, '__toString'))) {
                $replacements['{' . $key . '}'] = $value === null ? 'null' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            }
        }

        return strtr($message, $replacements);
    }

    /**
     * @param mixed $message
     * @param array<mixed> $context
     */
    private static function text($message, array $context): string
    {
        if (is_string($message)) {
            return $message;
        }

        if (is_scalar($message) || (is_object($message) && method_exists($message, '__toString'))) {
            return (string) $message;
        }

        $encoded = json_encode($message);

        return is_string($encoded) ? $encoded : gettype($message);
    }
}
