<?php

namespace ShibbirWeb\Peep\Payload;

use ShibbirWeb\Peep\Origin\Origin;
use ShibbirWeb\Peep\Support\SourceSnippet;
use Throwable;

/**
 * An exception with its trace, a source snippet and up to five previous exceptions.
 */
final class ExceptionPayload extends AbstractPayload
{
    private const MAX_PREVIOUS = 5;

    /**
     * @var array<string, mixed>
     */
    private $content;

    /**
     * @param callable(string): string $mapPath Maps container paths to local paths.
     * @param list<array<string, mixed>>|null $trace Replaces the exception's own trace (fatal errors).
     */
    public function __construct(Throwable $exception, callable $mapPath, ?Origin $origin = null, ?array $trace = null)
    {
        parent::__construct($origin);

        $this->content = self::describe($exception, $mapPath, $trace);

        $previous = [];
        $current = $exception->getPrevious();
        while ($current !== null && count($previous) < self::MAX_PREVIOUS) {
            $previous[] = self::describe($current, $mapPath);
            $current = $current->getPrevious();
        }

        $this->content['previous'] = $previous;
    }

    public function type(): string
    {
        return 'exception';
    }

    public function content(): array
    {
        return $this->content;
    }

    /**
     * @param callable(string): string $mapPath
     * @param list<array<string, mixed>>|null $trace
     * @return array<string, mixed>
     */
    private static function describe(Throwable $exception, callable $mapPath, ?array $trace = null): array
    {
        $frames = TracePayload::normalize($trace !== null ? $trace : $exception->getTrace());
        foreach ($frames as $index => $frame) {
            if ($frame['file'] !== null) {
                $frames[$index]['file'] = $mapPath($frame['file']);
            }
        }

        return [
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $mapPath($exception->getFile()),
            'line' => $exception->getLine(),
            'snippet' => SourceSnippet::around($exception->getFile(), $exception->getLine()),
            'frames' => $frames,
        ];
    }
}
