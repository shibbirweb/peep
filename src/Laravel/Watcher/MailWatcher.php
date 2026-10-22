<?php

namespace ShibbirWeb\Peep\Laravel\Watcher;

use Throwable;

/**
 * Sent mail: a preview card for each message, and headers that let the Peep mail
 * catcher link a caught mail to the request that sent it.
 *
 * Works with SwiftMailer (Laravel 5.5 to 8) and Symfony Mailer (Laravel 9+): both
 * message classes have getSubject() and getHeaders()->addTextHeader().
 */
final class MailWatcher extends AbstractWatcher
{
    public const PROJECT_HEADER = 'X-Peep-Project';
    public const REQUEST_HEADER = 'X-Peep-Request-Id';

    private const SENDING = 'Illuminate\\Mail\\Events\\MessageSending';
    private const SENT = 'Illuminate\\Mail\\Events\\MessageSent';

    public function isSupported(): bool
    {
        return class_exists(self::SENT);
    }

    public function register($app): void
    {
        if (class_exists(self::SENDING)) {
            $app->make('events')->listen(self::SENDING, function ($event) {
                $this->tag($event->message);
            });
        }

        $app->make('events')->listen(self::SENT, function ($event) {
            $this->handle($event->message);
        });
    }

    /**
     * @param object $message Swift_Message or Symfony\Component\Mime\Email
     */
    public function tag($message): void
    {
        if (! $this->factory->dispatcher()->isEnabled()) {
            return;
        }

        if (! method_exists($message, 'getHeaders')) {
            return;
        }

        try {
            $headers = $message->getHeaders();
            if (! $headers->has(self::REQUEST_HEADER)) {
                $headers->addTextHeader(self::PROJECT_HEADER, $this->factory->config()->project());
                $headers->addTextHeader(self::REQUEST_HEADER, $this->factory->context()->current()['id']);
            }
        } catch (Throwable $exception) {
            // Never let Peep break sending mail.
        }
    }

    /**
     * @param object $message
     */
    public function handle($message): void
    {
        if (! $this->isOn()) {
            return;
        }

        try {
            $this->peep()->payload('mail_preview', [
                'subject' => method_exists($message, 'getSubject') ? (string) $message->getSubject() : '',
                'from' => self::addresses($message, 'getFrom'),
                'to' => self::addresses($message, 'getTo'),
                'cc' => self::addresses($message, 'getCc'),
                'html' => self::html($message),
            ])->label('Mail');
        } catch (Throwable $exception) {
            // Never let Peep break sending mail.
        }
    }

    protected function configKey(): string
    {
        return 'send_mail';
    }

    /**
     * @param object $message
     * @return list<string>
     */
    private static function addresses($message, string $method): array
    {
        if (! method_exists($message, $method)) {
            return [];
        }

        $addresses = [];
        foreach ((array) $message->{$method}() as $key => $value) {
            if (is_object($value) && method_exists($value, 'toString')) {
                $addresses[] = $value->toString(); // Symfony Address
            } elseif (is_string($key)) {
                $addresses[] = $value !== null && $value !== '' ? $value . ' <' . $key . '>' : $key; // Swift: email => name
            } else {
                $addresses[] = (string) $value;
            }
        }

        return $addresses;
    }

    /**
     * @param object $message
     */
    private static function html($message): string
    {
        if (method_exists($message, 'getHtmlBody')) {
            $html = $message->getHtmlBody();
            if (is_string($html) && $html !== '') {
                return $html;
            }

            $text = method_exists($message, 'getTextBody') ? $message->getTextBody() : '';

            return '<pre>' . htmlspecialchars(is_string($text) ? $text : '', ENT_QUOTES) . '</pre>';
        }

        $body = method_exists($message, 'getBody') ? $message->getBody() : '';
        $contentType = method_exists($message, 'getContentType') ? (string) $message->getContentType() : 'text/html';

        return strpos($contentType, 'html') !== false ? (string) $body : '<pre>' . htmlspecialchars((string) $body, ENT_QUOTES) . '</pre>';
    }
}
