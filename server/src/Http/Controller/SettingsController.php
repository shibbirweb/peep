<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use RuntimeException;
use ShibbirWeb\PeepServer\Event\EventHub;
use ShibbirWeb\PeepServer\Event\ServerEvent;
use ShibbirWeb\PeepServer\Http\JsonBody;
use ShibbirWeb\PeepServer\Http\Responses;
use ShibbirWeb\PeepServer\Settings\InvalidSettingException;
use ShibbirWeb\PeepServer\Settings\SettingsStore;
use ShibbirWeb\PeepServer\Storage\CardStorage;

/**
 * GET  /_peep/ui/settings  the settings page (with the token, so it can be copied).
 * POST /_peep/ui/settings  {"changes": {...}}: validates, saves, tells every open UI.
 */
final class SettingsController implements RequestHandler
{
    /**
     * @var list<string> Changed settings that apply after a restart, since this viewer started.
     */
    private array $restartRequired = [];

    public function __construct(
        private readonly SettingsStore $store,
        private readonly EventHub $hub,
        private readonly CardStorage $storage,
        private readonly string $listeningOn,
    ) {}

    public function handleRequest(Request $request): Response
    {
        if ($request->getMethod() === 'GET') {
            return Responses::json(200, $this->page());
        }

        $changes = JsonBody::read($request)['changes'] ?? null;
        if (! is_array($changes)) {
            return Responses::error(400, 'invalid_request', 'Send the changed settings as {"changes": {...}}.');
        }

        // Switches and webhooks have their own endpoints, which keep the running stores in sync.
        unset($changes['switches'], $changes['webhooks']);

        try {
            $restart = $this->store->settings->update($changes);
        } catch (InvalidSettingException $exception) {
            return Responses::json(422, ['error' => 'invalid_setting', 'setting' => $exception->setting, 'message' => $exception->getMessage()]);
        }

        try {
            $this->store->save();
        } catch (RuntimeException $exception) {
            return Responses::error(500, 'not_saved', $exception->getMessage());
        }

        $this->restartRequired = array_values(array_unique([...$this->restartRequired, ...$restart]));
        $this->hub->publish([new ServerEvent('settings.changed', $this->store->public())]);

        return Responses::json(200, $this->page());
    }

    /**
     * @return array<string, mixed>
     */
    private function page(): array
    {
        return [
            'settings' => $this->store->settings->toArray(),
            'restart_required' => $this->restartRequired,
            'listening_on' => $this->listeningOn,
            'storage' => $this->storage->describe(),
            'file' => $this->store->file?->path(),
        ];
    }
}
