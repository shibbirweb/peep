<?php

namespace ShibbirWeb\Peep\Bridge\VarDumper;

use ShibbirWeb\Peep\Config\Config;
use ShibbirWeb\Peep\Serializer\SerializerInterface;
use ShibbirWeb\Peep\Serializer\ValueSerializer;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Throwable;

/**
 * Serializes values with Symfony VarDumper's cloner, so its many casters (Doctrine, Carbon,
 * DOM, SPL, ...) shape the result. Opt-in: casters may call methods on the value, which
 * Peep's own ValueSerializer never does. Falls back to ValueSerializer on any error.
 */
final class VarDumperSerializer implements SerializerInterface
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var ValueSerializer
     */
    private $fallback;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->fallback = ValueSerializer::fromConfig($config);
    }

    public static function isAvailable(): bool
    {
        return class_exists('Symfony\\Component\\VarDumper\\Cloner\\VarCloner');
    }

    public function serialize($value): array
    {
        if (! self::isAvailable()) {
            return $this->fallback->serialize($value);
        }

        try {
            $cloner = new VarCloner();
            $cloner->setMaxItems($this->config->maxNodes());
            $cloner->setMaxString($this->config->maxStringLength());

            $data = $cloner->cloneVar($value)
                ->withMaxDepth($this->config->maxDepth())
                ->withMaxItemsPerDepth($this->config->maxItems());

            $dumper = new TreeDumper();
            $data->dump($dumper);

            return $dumper->tree();
        } catch (Throwable $exception) {
            return $this->fallback->serialize($value);
        }
    }
}
