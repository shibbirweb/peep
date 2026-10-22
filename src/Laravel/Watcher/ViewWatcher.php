<?php

namespace ShibbirWeb\Peep\Laravel\Watcher;

use Throwable;

/**
 * Rendered views with their data.
 */
final class ViewWatcher extends AbstractWatcher
{
    public function register($app): void
    {
        $app->make('view')->composer('*', function ($view) {
            $this->handle($view);
        });
    }

    /**
     * @param \Illuminate\View\View $view
     */
    public function handle($view): void
    {
        if (! $this->isOn()) {
            return;
        }

        try {
            $data = method_exists($view, 'getData') ? $view->getData() : [];
            unset($data['__env'], $data['app'], $data['errors']);

            $this->peep()->payload('view', [
                'name' => method_exists($view, 'getName') ? $view->getName() : null,
                'path' => method_exists($view, 'getPath') ? $this->factory->originResolver()->pathMapper()->map((string) $view->getPath()) : null,
                'data' => $this->serialize($data),
            ]);
        } catch (Throwable $exception) {
            // Never let Peep break rendering.
        }
    }

    protected function configKey(): string
    {
        return 'send_views';
    }
}
