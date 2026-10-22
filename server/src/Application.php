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
use ShibbirWeb\PeepServer\Event\EventHub;
use ShibbirWeb\PeepServer\Http\Controller\ClearController;
use ShibbirWeb\PeepServer\Http\Controller\EnvelopeController;
use ShibbirWeb\PeepServer\Http\Controller\HealthController;
use ShibbirWeb\PeepServer\Http\Controller\LockController;
use ShibbirWeb\PeepServer\Http\Controller\StaticFileController;
use ShibbirWeb\PeepServer\Http\Controller\StreamController;
use ShibbirWeb\PeepServer\Http\Controller\SwitchController;
use ShibbirWeb\PeepServer\Http\Guard\HostGuard;
use ShibbirWeb\PeepServer\Http\Guard\OriginGuard;
use ShibbirWeb\PeepServer\Http\Router;
use ShibbirWeb\PeepServer\Lock\LockRegistry;
use ShibbirWeb\PeepServer\Protocol\EnvelopeDecoder;
use ShibbirWeb\PeepServer\Switch\SwitchStore;

use function Amp\Http\Server\Middleware\stackMiddleware;

/**
 * Composition root of the viewer: builds the stores, the event hub and the HTTP server.
 */
final class Application
{
    public const int KEEP_ALIVE_SECONDS = 15;

    public readonly CardStore $cards;

    public readonly EventHub $hub;

    public readonly LockRegistry $locks;

    public readonly SwitchStore $switches;

    private ?SocketHttpServer $server = null;

    private ?string $keepAliveTimer = null;

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        private readonly LoggerInterface $logger,
        private readonly string $publicDirectory = __DIR__ . '/../public',
    ) {
        $this->cards = new CardStore();
        $this->hub = new EventHub();
        $this->locks = new LockRegistry();
        $this->switches = new SwitchStore();
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
    }

    public function stop(): void
    {
        if ($this->keepAliveTimer !== null) {
            EventLoop::cancel($this->keepAliveTimer);
            $this->keepAliveTimer = null;
        }

        $this->hub->closeAll();
        $this->server?->stop();
        $this->server = null;
    }

    public function url(): string
    {
        $host = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;

        return 'http://' . $host . ':' . $this->port;
    }

    public function requestHandler(): RequestHandler
    {
        $locks = new LockController($this->locks, $this->hub);

        $router = new Router()
            ->add('GET', '/_peep/health', new HealthController())
            ->add('POST', '/_peep/envelope', new EnvelopeController(new EnvelopeDecoder(), $this->cards, $this->hub, $this->locks, $this->switches))
            ->add('GET', '/_peep/switches', new SwitchController($this->switches))
            ->add('GET', '/_peep/stream', new StreamController($this->cards, $this->hub, $this->locks))
            ->add('GET', '/_peep/locks/*', $locks)
            ->add('POST', '/_peep/ui/clear', new ClearController($this->cards, $this->hub))
            ->add('POST', '/_peep/ui/locks/*', $locks)
            ->add('GET', '/*', new StaticFileController($this->publicDirectory));

        return stackMiddleware(
            $router,
            new HostGuard($this->host, $this->port),
            new OriginGuard($this->host, $this->port),
        );
    }
}
