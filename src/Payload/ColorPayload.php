<?php

namespace ShibbirWeb\Peep\Payload;

/**
 * Sets the color of an existing card.
 */
final class ColorPayload extends AbstractPayload
{
    public const COLORS = ['red', 'green', 'blue', 'orange', 'purple', 'gray', 'yellow'];

    /**
     * @var string
     */
    private $color;

    public function __construct(string $color)
    {
        parent::__construct(null);

        $this->color = $color;
    }

    public static function isSupported(string $color): bool
    {
        return in_array($color, self::COLORS, true);
    }

    public function type(): string
    {
        return 'color';
    }

    public function content(): array
    {
        return ['color' => $this->color];
    }
}
