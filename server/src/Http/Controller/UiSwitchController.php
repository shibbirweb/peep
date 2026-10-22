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
use ShibbirWeb\PeepServer\Settings\SettingsStore;
use ShibbirWeb\PeepServer\Switch\SwitchStore;

/**
 * POST /_peep/ui/switches {"project": "shop", "switches": {"queries": "on"}}: the toolbar.
 * Apps pick the change up with their next envelope or switch poll (at most 5 seconds).
 */
final readonly class UiSwitchController implements RequestHandler
{
    public function __construct(
        private SwitchStore $switches,
        private SettingsStore $settings,
        private EventHub $hub,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $data = JsonBody::read($request);
        $project = JsonBody::project($data);
        $changes = $data['switches'] ?? null;

        if ($project === null || ! is_array($changes)) {
            return Responses::error(400, 'invalid_request', 'Send {"project": "...", "switches": {...}}.');
        }

        $switches = $this->switches->update($project, array_filter($changes, is_string(...), ARRAY_FILTER_USE_KEY));
        $this->settings->settings->switches = $this->switches->all();

        try {
            $this->settings->save();
        } catch (RuntimeException) {
            // The switch works for this run even when the file cannot be written.
        }

        $this->hub->publish([new ServerEvent('switches.changed', ['project' => $project, 'switches' => $switches])]);

        return Responses::json(200, ['project' => $project, 'switches' => $switches]);
    }
}
