<?php

namespace ShibbirWeb\Peep;

use ShibbirWeb\Peep\Limiter\CallLimiter;
use ShibbirWeb\Peep\Limiter\Counter;
use ShibbirWeb\Peep\Origin\OriginResolver;
use ShibbirWeb\Peep\Serializer\SerializerInterface;
use ShibbirWeb\Peep\Support\Stopwatch;

/**
 * The shared, per-process services every Peep card uses. Built once by PeepFactory.
 */
final class Services
{
    /**
     * @var Dispatcher
     */
    public $dispatcher;

    /**
     * @var SerializerInterface
     */
    public $serializer;

    /**
     * @var SerializerInterface Serializer without casters, for raw().
     */
    public $rawSerializer;

    /**
     * @var OriginResolver
     */
    public $originResolver;

    /**
     * @var CallLimiter
     */
    public $callLimiter;

    /**
     * @var Counter
     */
    public $counter;

    /**
     * @var Stopwatch
     */
    public $stopwatch;

    public function __construct(
        Dispatcher $dispatcher,
        SerializerInterface $serializer,
        SerializerInterface $rawSerializer,
        OriginResolver $originResolver
    ) {
        $this->dispatcher = $dispatcher;
        $this->serializer = $serializer;
        $this->rawSerializer = $rawSerializer;
        $this->originResolver = $originResolver;
        $this->callLimiter = new CallLimiter();
        $this->counter = new Counter();
        $this->stopwatch = new Stopwatch();
    }
}
