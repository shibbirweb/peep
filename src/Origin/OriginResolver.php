<?php

namespace ShibbirWeb\Peep\Origin;

/**
 * Finds the file and line of the user's peep() call.
 *
 * Backtrace frames are innermost first. Each frame says where a call was made
 * (file, line) and what was called (function, class). The first frame whose file
 * is outside Peep is the user's call site; the frame after it names the function
 * that contains the call.
 */
final class OriginResolver
{
    private const BACKTRACE_LIMIT = 40;

    /**
     * @var PathMapper
     */
    private $pathMapper;

    /**
     * @var list<string>
     */
    private $skippedPrefixes;

    /**
     * @var array<string, string> compiled view file => source file
     */
    private $viewSources = [];

    /**
     * @param list<string> $skippedPrefixes Extra paths whose frames are skipped (e.g. a framework's macro code).
     */
    public function __construct(PathMapper $pathMapper, array $skippedPrefixes = [])
    {
        $this->pathMapper = $pathMapper;
        $this->skippedPrefixes = array_merge([dirname(__DIR__) . DIRECTORY_SEPARATOR], $skippedPrefixes);
    }

    /**
     * Skip frames from files under this path too. Used by framework adapters.
     */
    public function skip(string $prefix): void
    {
        if (! in_array($prefix, $this->skippedPrefixes, true)) {
            $this->skippedPrefixes[] = $prefix;
        }
    }

    public function resolve(): ?Origin
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::BACKTRACE_LIMIT);

        foreach ($frames as $index => $frame) {
            if (! isset($frame['file']) || $this->isSkipped($frame['file'])) {
                continue;
            }

            $caller = isset($frames[$index + 1]) ? $frames[$index + 1] : [];

            return new Origin(
                $this->pathMapper->map($this->viewSource($frame['file'])),
                isset($frame['line']) ? (int) $frame['line'] : 0,
                isset($caller['function']) ? $caller['function'] : null,
                isset($caller['class']) ? $caller['class'] : null
            );
        }

        return null;
    }

    /**
     * The backtrace from the user's call site outwards, with paths mapped.
     * Frame 0 is the call site; frame 1 is where the function containing it was called.
     *
     * @return list<array<string, mixed>>
     */
    public function userFrames(int $limit = 50): array
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::BACKTRACE_LIMIT + $limit);

        foreach ($frames as $index => $frame) {
            if (! isset($frame['file']) || $this->isSkipped($frame['file'])) {
                continue;
            }

            $userFrames = [];
            foreach (array_slice($frames, $index, $limit) as $userFrame) {
                if (isset($userFrame['file'])) {
                    $userFrame['file'] = $this->pathMapper->map($this->viewSource($userFrame['file']));
                }

                $userFrames[] = $userFrame;
            }

            return $userFrames;
        }

        return [];
    }

    public function pathMapper(): PathMapper
    {
        return $this->pathMapper;
    }

    /**
     * Laravel compiles Blade views to storage/framework/views/<hash>.php and ends each file
     * with a comment naming the source. Point at the .blade.php file instead.
     */
    private function viewSource(string $file): string
    {
        if (strpos($file, DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR) === false) {
            return $file;
        }

        if (! isset($this->viewSources[$file])) {
            $this->viewSources[$file] = $file;

            $size = @filesize($file);
            $handle = is_int($size) ? @fopen($file, 'rb') : false;

            if (is_resource($handle)) {
                fseek($handle, max(0, (int) $size - 1024));
                $tail = (string) fread($handle, 1024);
                fclose($handle);

                if (preg_match('#/\*\*PATH (.+?) ENDPATH\*\*/#', $tail, $matches) === 1) {
                    $this->viewSources[$file] = $matches[1];
                }
            }
        }

        return $this->viewSources[$file];
    }

    private function isSkipped(string $file): bool
    {
        foreach ($this->skippedPrefixes as $prefix) {
            if (strpos($file, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }
}
