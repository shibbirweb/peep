<?php

namespace ShibbirWeb\Peep\Payload;

/**
 * Sets the text size of an existing card.
 */
final class SizePayload extends AbstractPayload
{
    public const SMALL = 'small';
    public const NORMAL = 'normal';
    public const LARGE = 'large';

    /**
     * @var string
     */
    private $size;

    public function __construct(string $size)
    {
        parent::__construct(null);

        $this->size = in_array($size, [self::SMALL, self::NORMAL, self::LARGE], true) ? $size : self::NORMAL;
    }

    public function type(): string
    {
        return 'size';
    }

    public function content(): array
    {
        return ['size' => $this->size];
    }
}
