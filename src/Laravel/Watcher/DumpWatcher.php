<?php

namespace ShibbirWeb\Peep\Laravel\Watcher;

/**
 * dump() and dd() calls also go to Peep. With "dumps_passthrough" (default) the dump
 * still appears where it normally does.
 */
final class DumpWatcher extends AbstractWatcher
{
    private const VAR_DUMPER = 'Symfony\\Component\\VarDumper\\VarDumper';

    /**
     * @var callable|null
     */
    private $previous;

    public function isSupported(): bool
    {
        return class_exists(self::VAR_DUMPER);
    }

    public function register($app): void
    {
        $class = self::VAR_DUMPER;

        $this->previous = $class::setHandler(function ($value, ...$rest) use ($class) {
            if ($this->isOn()) {
                $this->peep()->send($value)->label('dump()');
            }

            if (! $this->passthrough()) {
                return;
            }

            if ($this->previous !== null) {
                call_user_func($this->previous, $value, ...$rest);

                return;
            }

            // No earlier handler: let VarDumper print with its default handler, then put ours back.
            $ours = $class::setHandler(null);
            $class::dump($value);
            $class::setHandler($ours);
        });
    }

    protected function configKey(): string
    {
        return 'send_dumps';
    }

    private function passthrough(): bool
    {
        return ! isset($this->config['dumps_passthrough']) || (bool) $this->config['dumps_passthrough'];
    }
}
