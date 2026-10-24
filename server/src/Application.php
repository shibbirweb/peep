<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\ResourceServerSocketFactory;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use ShibbirWeb\PeepServer\Card\CardStore;
use ShibbirWeb\PeepServer\Card\Receiving;
use ShibbirWeb\PeepServer\Event\EventHub;
use ShibbirWeb\PeepServer\Http\Controller\AgentController;
use ShibbirWeb\PeepServer\Http\Controller\ClearController;
use ShibbirWeb\PeepServer\Http\Controller\EnvelopeController;
use ShibbirWeb\PeepServer\Http\Controller\HealthController;
use ShibbirWeb\PeepServer\Http\Controller\InboxController;
use ShibbirWeb\PeepServer\Http\Controller\LockController;
use ShibbirWeb\PeepServer\Http\Controller\LogController;
use ShibbirWeb\PeepServer\Http\Controller\MailController;
use ShibbirWeb\PeepServer\Http\Controller\MailgunController;
use ShibbirWeb\PeepServer\Http\Controller\ReceivingController;
use ShibbirWeb\PeepServer\Http\Controller\ScreenController;
use ShibbirWeb\PeepServer\Http\Controller\SettingsController;
use ShibbirWeb\PeepServer\Http\Controller\SmsController;
use ShibbirWeb\PeepServer\Http\Controller\StaticFileController;
use ShibbirWeb\PeepServer\Http\Controller\StreamController;
use ShibbirWeb\PeepServer\Http\Controller\SwitchController;
use ShibbirWeb\PeepServer\Http\Controller\UiSwitchController;
use ShibbirWeb\PeepServer\Http\Controller\WebhookController;
use ShibbirWeb\PeepServer\Http\Guard\HostGuard;
use ShibbirWeb\PeepServer\Http\Guard\OriginGuard;
use ShibbirWeb\PeepServer\Http\Guard\TokenGuard;
use ShibbirWeb\PeepServer\Http\Router;
use ShibbirWeb\PeepServer\Inbox\Catcher;
use ShibbirWeb\PeepServer\Lock\LockRegistry;
use ShibbirWeb\PeepServer\Log\LogFolders;
use ShibbirWeb\PeepServer\Mail\MailParser;
use ShibbirWeb\PeepServer\Mail\MailStore;
use ShibbirWeb\PeepServer\Protocol\EnvelopeDecoder;
use ShibbirWeb\PeepServer\Settings\SettingsStore;
use ShibbirWeb\PeepServer\Smtp\CaughtMessage;
use ShibbirWeb\PeepServer\Smtp\SmtpServer;
use ShibbirWeb\PeepServer\Sms\SmsStore;
use ShibbirWeb\PeepServer\Storage\CardStorage;
use ShibbirWeb\PeepServer\Storage\NullCardStorage;
use ShibbirWeb\PeepServer\Switch\SwitchStore;
use ShibbirWeb\PeepServer\Webhook\WebhookRules;
use ShibbirWeb\PeepServer\Webhook\WebhookRunner;
use Throwable;

use function Amp\Http\Server\Middleware\stackMiddleware;

/**
 * Composition root of the viewer: builds the stores, the event hub and the HTTP server.
 */
final class Application
{
    public const int KEEP_ALIVE_SECONDS = 15;

    public const float FLUSH_SECONDS = 0.5;

    public readonly CardStore $cards;

    public readonly EventHub $hub;

    public readonly LockRegistry $locks;

    public readonly SwitchStore $switches;

    public readonly Receiving $receiving;

    public readonly Catcher $catcher;

    public readonly WebhookRunner $webhooks;

    public readonly LogFolders $logFolders;

    private ?SocketHttpServer $server = null;

    private ?SmtpServer $smtp = null;

    private ?string $keepAliveTimer = null;

    private ?string $flushTimer = null;

    /**
     * @param string $host Where to listen. A non-loopback host needs a token (see ServeOptions).
     */
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        private readonly LoggerInterface $logger,
        private readonly string $publicDirectory = __DIR__ . '/../public',
        public readonly SettingsStore $settings = new SettingsStore(),
        CardStorage $storage = new NullCardStorage(),
    ) {
        $this->cards = new CardStore(max(1, $settings->settings->maxCards), $storage);
        $this->hub = new EventHub();
        $this->locks = new LockRegistry();
        $this->switches = new SwitchStore($settings->settings->switches);
        $this->receiving = new Receiving();
        $this->catcher = new Catcher(new MailStore(), new SmsStore(), $this->hub);
        $this->webhooks = new WebhookRunner(new WebhookRules($settings), $this->hub, logger: $logger);
        $this->logFolders = new LogFolders($settings);
        $this->catcher->onMail($this->webhooks->onMail(...));
        $this->catcher->onSms($this->webhooks->onSms(...));
    }

    public function start(): void
    {
        $this->server = new SocketHttpServer(
            $this->logger,
            new ResourceServerSocketFactory(),
            new SocketClientFactory($this->logger),
            httpDriverFactory: new DefaultHttpDriverFactory(
                $this->logger,
                // Server-Sent Events streams stay open; only idle keep-alive connections time out.
                streamTimeout: 60,
                bodySizeLimit: EnvelopeController::MAX_BODY_BYTES,
            ),
        );

        $this->server->expose($this->host . ':' . $this->port);
        $this->server->start($this->requestHandler(), new DefaultErrorHandler());

        // A comment line keeps idle SSE connections from being closed by proxies and webviews.
        $this->keepAliveTimer = EventLoop::repeat(self::KEEP_ALIVE_SECONDS, fn() => $this->hub->broadcast(": keep-alive\n\n"));
        $this->flushTimer = EventLoop::repeat(self::FLUSH_SECONDS, $this->flush(...));
    }

    /**
     * Starts the SMTP listener on the settings' port.
     *
     * @return string|null Why it could not start (the port is taken), or null.
     */
    public function startSmtp(?int $port = null): ?string
    {
        $parser = new MailParser();
        $this->smtp = new SmtpServer(
            $this->host,
            $port ?? $this->settings->settings->smtpPort,
            function (CaughtMessage $message) use ($parser): void {
                try {
                    $this->catcher->mail($parser->parse($message->raw, $message->username, $message->recipients, 'smtp', microtime(true)));
                } catch (Throwable $exception) {
                    $this->logger->warning('Could not read a mail sent over SMTP: ' . $exception->getMessage());
                }
            },
            $this->logger,
            $this->settings->settings->token,
        );

        try {
            $this->smtp->start();
        } catch (Throwable $exception) {
            $this->smtp = null;

            return $exception->getMessage();
        }

        return null;
    }

    public function smtpAddress(): ?string
    {
        return $this->smtp?->address();
    }

    /**
     * Writes buffered history. A failing disk must not stop the viewer: it logs and goes on.
     */
    public function flush(): void
    {
        try {
            $this->cards->storage()->flush();
        } catch (Throwable $exception) {
            $this->logger->warning('Could not save history: ' . $exception->getMessage());
        }
    }

    public function stop(): void
    {
        if ($this->keepAliveTimer !== null) {
            EventLoop::cancel($this->keepAliveTimer);
            $this->keepAliveTimer = null;
        }

        if ($this->flushTimer !== null) {
            EventLoop::cancel($this->flushTimer);
            $this->flushTimer = null;
        }

        $this->flush();

        $this->hub->closeAll();
        $this->smtp?->stop();
        $this->smtp = null;
        $this->server?->stop();
        $this->server = null;
    }

    public function url(): string
    {
        // Listening on every address includes this machine; the browser opens it through loopback.
        $host = match ($this->host) {
            '0.0.0.0' => '127.0.0.1',
            '::', '[::]' => '[::1]',
            default => str_contains($this->host, ':') ? '[' . trim($this->host, '[]') . ']' : $this->host,
        };

        return 'http://' . $host . ':' . $this->port;
    }

    public function requestHandler(): RequestHandler
    {
        $locks = new LockController($this->locks, $this->hub);
        $settings = new SettingsController($this->settings, $this->hub, $this->cards->storage(), $this->url());
        $screens = new ScreenController($this->cards, $this->hub);
        $sms = new SmsController($this->catcher);
        $inbox = new InboxController($this->catcher, $this->hub);
        $webhooks = new WebhookController($this->webhooks, $this->catcher, $this->hub);
        $agent = new AgentController($this->cards, $this->catcher, $this->hub, $this->receiving, $this->switches, $this->url(), $this->logFolders, $this->webhooks);
        $logs = new LogController($this->logFolders, $this->hub);

        $router = new Router()
            ->add('GET', '/_peep/health', new HealthController())
            ->add('POST', '/_peep/envelope', new EnvelopeController(new EnvelopeDecoder(), $this->cards, $this->hub, $this->locks, $this->switches, $this->receiving, $this->logFolders))
            ->add('GET', '/_peep/switches', new SwitchController($this->switches))
            ->add('GET', '/_peep/stream', new StreamController($this->cards, $this->hub, $this->locks, $this->switches, $this->receiving, $this->settings, $this->catcher, $this->webhooks, $this->logFolders))
            ->add('POST', '/_peep/mail', new MailController($this->catcher))
            ->add('POST', '/v3/*', new MailgunController($this->catcher))
            ->add('POST', '/_peep/sms', $sms)
            ->add('POST', '/2010-04-01/*', $sms)
            ->add('POST', '/sms/json', $sms)
            ->add('GET', '/_peep/ui/mail/*', $inbox)
            ->add('POST', '/_peep/ui/mail/*', $inbox)
            ->add('POST', '/_peep/ui/sms/*', $inbox)
            ->add('GET', '/_peep/ui/webhooks/*', $webhooks)
            ->add('POST', '/_peep/ui/webhooks/*', $webhooks)
            ->add('GET', '/_peep/locks/*', $locks)
            ->add('POST', '/_peep/ui/clear', new ClearController($this->cards, $this->hub))
            ->add('POST', '/_peep/ui/locks/*', $locks)
            ->add('GET', '/_peep/ui/settings', $settings)
            ->add('POST', '/_peep/ui/settings', $settings)
            ->add('POST', '/_peep/ui/switches', new UiSwitchController($this->switches, $this->settings, $this->hub))
            ->add('POST', '/_peep/ui/receiving', new ReceivingController($this->receiving, $this->hub))
            ->add('POST', '/_peep/ui/screens', $screens)
            ->add('GET', '/_peep/ui/screens/*', $screens)
            ->add('GET', '/_peep/ui/logs', $logs)
            ->add('GET', '/_peep/ui/logs/*', $logs)
            ->add('POST', '/_peep/ui/logs/*', $logs)
            ->add('GET', '/_peep/agent/*', $agent)
            ->add('POST', '/_peep/agent/*', $agent)
            ->add('GET', '/*', new StaticFileController($this->publicDirectory));

        return stackMiddleware(
            $router,
            new TokenGuard($this->settings->settings->token),
            new HostGuard($this->host, $this->port),
            new OriginGuard($this->host, $this->port),
        );
    }
}
