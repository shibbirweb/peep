<?php

namespace ShibbirWeb\Peep\Payload;

use ShibbirWeb\Peep\Origin\Origin;

/**
 * An image from a URL, a file path, a data URI or bare base64.
 * Files are embedded as data URIs (up to 5 MB) because the viewer cannot read the app's disk.
 */
final class ImagePayload extends AbstractPayload
{
    public const MAX_FILE_BYTES = 5242880;

    private const MIME_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'avif' => 'image/avif',
        'bmp' => 'image/bmp',
        'ico' => 'image/x-icon',
    ];

    /**
     * @var string
     */
    private $src;

    /**
     * @var string|null
     */
    private $error;

    public function __construct(string $source, ?Origin $origin = null)
    {
        parent::__construct($origin);

        list($this->src, $this->error) = self::resolve($source);
    }

    public function type(): string
    {
        return 'image';
    }

    public function content(): array
    {
        return ['src' => $this->src, 'error' => $this->error];
    }

    /**
     * @return array{0: string, 1: string|null} The src, and why it could not be loaded.
     */
    private static function resolve(string $source): array
    {
        $source = trim($source);

        if (preg_match('#^(https?:)?//#i', $source) === 1 || strpos($source, 'data:image/') === 0) {
            return [$source, null];
        }

        if (is_file($source)) {
            $size = filesize($source);
            if (! is_int($size) || $size > self::MAX_FILE_BYTES) {
                return ['', 'The image file is larger than 5 MB.'];
            }

            $contents = file_get_contents($source);
            if (! is_string($contents)) {
                return ['', 'The image file could not be read.'];
            }

            $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
            $mime = isset(self::MIME_TYPES[$extension]) ? self::MIME_TYPES[$extension] : 'image/png';

            return ['data:' . $mime . ';base64,' . base64_encode($contents), null];
        }

        if (preg_match('#^[A-Za-z0-9+/\r\n]+={0,2}$#', $source) === 1) {
            return ['data:image/png;base64,' . preg_replace('/\s+/', '', $source), null];
        }

        return ['', 'Not a URL, an existing file or base64 image data.'];
    }
}
