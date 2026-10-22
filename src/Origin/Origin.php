<?php

namespace ShibbirWeb\Peep\Origin;

/**
 * Where a peep() call was made.
 */
final class Origin
{
    /**
     * @var string
     */
    private $file;

    /**
     * @var int
     */
    private $line;

    /**
     * @var string|null
     */
    private $function;

    /**
     * @var string|null
     */
    private $class;

    public function __construct(string $file, int $line, ?string $function = null, ?string $class = null)
    {
        $this->file = $file;
        $this->line = $line;
        $this->function = $function;
        $this->class = $class;
    }

    public function file(): string
    {
        return $this->file;
    }

    public function line(): int
    {
        return $this->line;
    }

    public function functionName(): ?string
    {
        return $this->function;
    }

    public function className(): ?string
    {
        return $this->class;
    }

    /**
     * @return array{file: string, line: int, function: string|null, class: string|null}
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'function' => $this->function,
            'class' => $this->class,
        ];
    }
}
