<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Protocol;

/**
 * One payload of an envelope. Content stays as received: the UI renders it.
 */
final readonly class Payload
{
    /**
     * Types that change an existing card instead of adding content to it.
     */
    public const array UPDATE_TYPES = ['color', 'label', 'size', 'replace', 'remove'];

    /**
     * @param array<string, mixed> $content
     * @param array{file: string, line: int, function: string|null, class: string|null}|null $origin
     */
    public function __construct(
        public string $type,
        public array $content,
        public ?array $origin,
    ) {}

    public function isUpdate(): bool
    {
        return in_array($this->type, self::UPDATE_TYPES, true);
    }

    /**
     * @return array{type: string, content: array<string, mixed>, origin: array{file: string, line: int, function: string|null, class: string|null}|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'content' => $this->content,
            'origin' => $this->origin,
        ];
    }
}
