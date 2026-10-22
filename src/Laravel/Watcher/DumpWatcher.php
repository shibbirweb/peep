<?php

namespace ShibbirWeb\Peep\Laravel\Watcher;

use ShibbirWeb\Peep\Bridge\VarDumper\DumpHandler;

/**
 * dump() and dd() calls also go to Peep. With "dumps_passthrough" (default) the dump
 * still appears where it normally does.
 */
final class DumpWatcher extends AbstractWatcher
{
    public function isSupported(): bool
    {
        return DumpHandler::isAvailable();
    }

    public function register($app): void
    {
        $passthrough = ! isset($this->config['dumps_passthrough']) || (bool) $this->config['dumps_passthrough'];

        DumpHandler::register($passthrough, $this->factory, function () {
            return $this->isOn();
        });
    }

    protected function configKey(): string
    {
        return 'send_dumps';
    }
}
