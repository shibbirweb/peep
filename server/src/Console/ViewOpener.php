<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Console;

/**
 * Opens the viewer in the default browser, or in the desktop app through its deep link.
 */
final class ViewOpener
{
    public const string DEEP_LINK = 'peep://open';

    /**
     * @return string|null Why the requested view could not be used, or null when it opened as asked.
     */
    public function open(OpenMode $mode, string $url): ?string
    {
        if ($mode === OpenMode::None) {
            return null;
        }

        if ($mode === OpenMode::Window) {
            // Without the desktop app, the browser shows the viewer instead.
            if ($this->desktopAppInstalled() && $this->launch(self::DEEP_LINK . '?url=' . rawurlencode($url))) {
                return null;
            }

            $this->launch($url);

            return 'The Peep desktop app is not installed, so the viewer opened in your browser.';
        }

        return $this->launch($url) ? null : "Could not open a browser. Open {$url} yourself.";
    }

    /**
     * The desktop app registers the peep:// link when it is installed (DT-06).
     */
    private function desktopAppInstalled(): bool
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => is_dir('/Applications/Peep.app') || is_dir(getenv('HOME') . '/Applications/Peep.app'),
            'Linux' => trim((string) @shell_exec('xdg-mime query default x-scheme-handler/peep 2>/dev/null')) !== '',
            'Windows' => $this->succeeds('reg query HKCU\\Software\\Classes\\peep'),
            default => false,
        };
    }

    private function succeeds(string $command): bool
    {
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            return false;
        }

        foreach ($pipes as $pipe) {
            stream_get_contents($pipe);
            fclose($pipe);
        }

        return proc_close($process) === 0;
    }

    private function launch(string $target): bool
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => 'open ' . escapeshellarg($target),
            'Windows' => 'cmd /c start "" ' . escapeshellarg($target),
            default => 'xdg-open ' . escapeshellarg($target) . ' >/dev/null 2>&1 &',
        };

        $process = @proc_open($command, [], $pipes);
        if (! is_resource($process)) {
            return false;
        }

        return proc_close($process) === 0;
    }
}
