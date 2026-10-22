<?php

namespace ShibbirWeb\Peep\Payload;

use ShibbirWeb\Peep\Origin\Origin;

/**
 * Stack frames: trace() shows many, caller() shows one.
 */
final class TracePayload extends AbstractPayload
{
    /**
     * @var string
     */
    private $type;

    /**
     * @var list<array{file: string|null, line: int|null, function: string|null, class: string|null}>
     */
    private $frames;

    /**
     * Public because PHP 7.1 does not allow a narrower constructor than the parent's.
     * Prefer the trace() and caller() factories.
     *
     * @param list<array{file: string|null, line: int|null, function: string|null, class: string|null}> $frames
     */
    public function __construct(string $type, array $frames, ?Origin $origin)
    {
        parent::__construct($origin);

        $this->type = $type;
        $this->frames = $frames;
    }

    /**
     * @param list<array{file: string|null, line: int|null, function: string|null, class: string|null}> $frames
     */
    public static function trace(array $frames, ?Origin $origin): self
    {
        return new self('trace', $frames, $origin);
    }

    /**
     * @param array{file: string|null, line: int|null, function: string|null, class: string|null}|null $frame
     */
    public static function caller(?array $frame, ?Origin $origin): self
    {
        return new self('caller', $frame !== null ? [$frame] : [], $origin);
    }

    /**
     * Normalizes debug_backtrace() or Throwable::getTrace() frames.
     *
     * @param array<int, array<string, mixed>> $frames
     * @return list<array{file: string|null, line: int|null, function: string|null, class: string|null}>
     */
    public static function normalize(array $frames, int $limit = 50): array
    {
        $normalized = [];

        foreach (array_slice($frames, 0, $limit) as $frame) {
            $normalized[] = [
                'file' => isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : null,
                'line' => isset($frame['line']) && is_int($frame['line']) ? $frame['line'] : null,
                'function' => isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : null,
                'class' => isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : null,
            ];
        }

        return $normalized;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function content(): array
    {
        return ['frames' => $this->frames];
    }
}
