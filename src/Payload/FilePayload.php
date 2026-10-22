<?php

namespace ShibbirWeb\Peep\Payload;

use ShibbirWeb\Peep\Origin\Origin;

/**
 * The contents of a file (text, up to 1 MB shown).
 */
final class FilePayload extends AbstractPayload
{
    public const MAX_BYTES = 1048576;

    /**
     * @var array<string, mixed>
     */
    private $content;

    /**
     * @param string $path The file to read.
     * @param string $displayPath The path to show (mapped to the developer's machine).
     */
    public function __construct(string $path, string $displayPath, ?Origin $origin = null)
    {
        parent::__construct($origin);

        $this->content = self::read($path, $displayPath);
    }

    public function type(): string
    {
        return 'file';
    }

    public function content(): array
    {
        return $this->content;
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(string $path, string $displayPath): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return ['path' => $displayPath, 'contents' => null, 'error' => 'The file does not exist or cannot be read.'];
        }

        $size = filesize($path);
        $handle = fopen($path, 'rb');
        $contents = is_resource($handle) ? fread($handle, self::MAX_BYTES) : false;
        if (is_resource($handle)) {
            fclose($handle);
        }

        if (! is_string($contents)) {
            return ['path' => $displayPath, 'contents' => null, 'error' => 'The file could not be read.'];
        }

        $binary = preg_match('//u', $contents) !== 1;

        return [
            'path' => $displayPath,
            'size' => is_int($size) ? $size : strlen($contents),
            'binary' => $binary,
            'contents' => $binary ? base64_encode($contents) : $contents,
            'truncated' => is_int($size) && $size > self::MAX_BYTES,
            'error' => null,
        ];
    }
}
