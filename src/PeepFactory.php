<?php

namespace ShibbirWeb\Peep;

use ShibbirWeb\Peep\Config\Config;
use ShibbirWeb\Peep\Config\ConfigLoader;
use ShibbirWeb\Peep\Context\ExecutionContext;
use ShibbirWeb\Peep\Envelope\EnvelopeEncoder;
use ShibbirWeb\Peep\Envelope\MetaFactory;
use ShibbirWeb\Peep\Origin\OriginResolver;
use ShibbirWeb\Peep\Origin\PathMapper;
use ShibbirWeb\Peep\Serializer\SerializerInterface;
use ShibbirWeb\Peep\Serializer\ValueSerializer;
use ShibbirWeb\Peep\Transport\AvailabilityCache;
use ShibbirWeb\Peep\Transport\TransportFactory;
use ShibbirWeb\Peep\Transport\TransportInterface;

/**
 * Composition root: builds the shared services once per process and hands out Peep instances.
 *
 * The peep() helper uses the shared instance. Framework adapters replace it with
 * setInstance() after building one from their own configuration.
 */
final class PeepFactory
{
    /**
     * @var self|null
     */
    private static $instance;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Services
     */
    private $services;

    /**
     * @var ExecutionContext
     */
    private $context;

    public function __construct(
        Config $config,
        ?TransportInterface $transport = null,
        ?SerializerInterface $serializer = null,
        ?AvailabilityCache $availability = null
    ) {
        $this->config = $config;
        $this->context = new ExecutionContext();

        $valueSerializer = ValueSerializer::fromConfig($config);
        $dispatcher = new Dispatcher(
            $config->enabled(),
            $config->endpointKey(),
            $transport !== null ? $transport : TransportFactory::create($config),
            $availability !== null ? $availability : new AvailabilityCache(),
            new EnvelopeEncoder(),
            new MetaFactory($config, $this->context),
            $config->project()
        );

        $this->services = new Services(
            $dispatcher,
            $serializer !== null ? $serializer : $valueSerializer,
            $valueSerializer->withoutCasters(),
            new OriginResolver(new PathMapper($config->remotePath(), $config->localPath()))
        );
    }

    /**
     * The shared factory used by peep(). Built from peep.php and environment variables on first use.
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self((new ConfigLoader())->load());
        }

        return self::$instance;
    }

    /**
     * Replace the shared factory (framework adapters, tests). Null resets it.
     */
    public static function setInstance(?self $factory): void
    {
        self::$instance = $factory;
    }

    /**
     * A new card, or an existing one when its id is given (to update it later).
     */
    public function make(?string $card = null): Peep
    {
        return new Peep($this->services, $card);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function services(): Services
    {
        return $this->services;
    }

    public function dispatcher(): Dispatcher
    {
        return $this->services->dispatcher;
    }

    public function originResolver(): OriginResolver
    {
        return $this->services->originResolver;
    }

    public function context(): ExecutionContext
    {
        return $this->context;
    }
}
