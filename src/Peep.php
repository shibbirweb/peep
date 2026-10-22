<?php

namespace ShibbirWeb\Peep;

use ShibbirWeb\Peep\Origin\OriginResolver;
use ShibbirWeb\Peep\Payload\ColorPayload;
use ShibbirWeb\Peep\Payload\HtmlPayload;
use ShibbirWeb\Peep\Payload\JsonPayload;
use ShibbirWeb\Peep\Payload\LabelPayload;
use ShibbirWeb\Peep\Payload\LogPayload;
use ShibbirWeb\Peep\Payload\PayloadInterface;
use ShibbirWeb\Peep\Payload\SizePayload;
use ShibbirWeb\Peep\Payload\TablePayload;
use ShibbirWeb\Peep\Payload\TextPayload;
use ShibbirWeb\Peep\Serializer\SerializerInterface;
use ShibbirWeb\Peep\Support\Uuid;
use Throwable;

/**
 * One card in the viewer. Every method sends to the same card and returns it, so calls chain:
 *
 *     peep($user)->green()->label('Signed in');
 *
 * When Peep is disabled, or the viewer is known to be down, every method returns
 * immediately without serializing anything.
 */
final class Peep
{
    /**
     * @var string
     */
    private $card;

    /**
     * @var Dispatcher
     */
    private $dispatcher;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var OriginResolver
     */
    private $originResolver;

    public function __construct(Dispatcher $dispatcher, SerializerInterface $serializer, OriginResolver $originResolver, ?string $card = null)
    {
        $this->dispatcher = $dispatcher;
        $this->serializer = $serializer;
        $this->originResolver = $originResolver;
        $this->card = $card !== null ? $card : Uuid::v4();
    }

    public function card(): string
    {
        return $this->card;
    }

    /**
     * Show one or more values.
     *
     * @param mixed ...$values
     */
    public function send(...$values): self
    {
        if ($values === [] || ! $this->dispatcher->isActive()) {
            return $this;
        }

        try {
            $nodes = [];
            foreach ($values as $value) {
                $nodes[] = $this->serializer->serialize($value);
            }

            $this->dispatch(new LogPayload($nodes, $this->originResolver->resolve()));
        } catch (Throwable $exception) {
            // Never let a problem inside Peep reach the app.
        }

        return $this;
    }

    /**
     * Show text as is: whitespace kept, HTML not rendered.
     */
    public function text(string $text): self
    {
        if ($this->dispatcher->isActive()) {
            $this->dispatch(new TextPayload($text, $this->originResolver->resolve()));
        }

        return $this;
    }

    public function html(string $html): self
    {
        if ($this->dispatcher->isActive()) {
            $this->dispatch(new HtmlPayload($html, $this->originResolver->resolve()));
        }

        return $this;
    }

    /**
     * Show a JSON string, pretty-printed.
     */
    public function json(string $json): self
    {
        if ($this->dispatcher->isActive()) {
            $this->dispatch(new JsonPayload($json, $this->originResolver->resolve()));
        }

        return $this;
    }

    /**
     * Show an array as a table.
     *
     * @param array<mixed> $rows
     */
    public function table(array $rows, ?string $label = null): self
    {
        if (! $this->dispatcher->isActive()) {
            return $this;
        }

        try {
            $this->dispatch(new TablePayload($this->serializer->serialize($rows), $label, $this->originResolver->resolve()));
        } catch (Throwable $exception) {
            // Never let a problem inside Peep reach the app.
        }

        return $this;
    }

    /**
     * @param string $color One of ColorPayload::COLORS; anything else is ignored.
     */
    public function color(string $color): self
    {
        if ($this->dispatcher->isActive() && ColorPayload::isSupported($color)) {
            $this->dispatch(new ColorPayload($color));
        }

        return $this;
    }

    public function red(): self
    {
        return $this->color('red');
    }

    public function green(): self
    {
        return $this->color('green');
    }

    public function blue(): self
    {
        return $this->color('blue');
    }

    public function orange(): self
    {
        return $this->color('orange');
    }

    public function purple(): self
    {
        return $this->color('purple');
    }

    public function gray(): self
    {
        return $this->color('gray');
    }

    public function yellow(): self
    {
        return $this->color('yellow');
    }

    public function label(string $label): self
    {
        if ($this->dispatcher->isActive()) {
            $this->dispatch(new LabelPayload($label));
        }

        return $this;
    }

    public function small(): self
    {
        if ($this->dispatcher->isActive()) {
            $this->dispatch(new SizePayload(SizePayload::SMALL));
        }

        return $this;
    }

    public function large(): self
    {
        if ($this->dispatcher->isActive()) {
            $this->dispatch(new SizePayload(SizePayload::LARGE));
        }

        return $this;
    }

    /**
     * Start sending, for every peep() call in this process.
     */
    public function enable(): self
    {
        $this->dispatcher->enable();

        return $this;
    }

    /**
     * Stop sending, for every peep() call in this process.
     */
    public function disable(): self
    {
        $this->dispatcher->disable();

        return $this;
    }

    public function enabled(): bool
    {
        return $this->dispatcher->isEnabled();
    }

    public function disabled(): bool
    {
        return ! $this->dispatcher->isEnabled();
    }

    private function dispatch(PayloadInterface $payload): void
    {
        $this->dispatcher->dispatch($this->card, [$payload]);
    }
}
