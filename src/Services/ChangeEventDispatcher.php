<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use Illuminate\Support\Facades\DB;
use TropikalAI\ConnectFilament\Domain\ChangeEnvelope;
use TropikalAI\ConnectFilament\Jobs\DeliverChangeEvent;
use TropikalAI\ConnectFilament\Models\Installation;

/**
 * Decides whether a change is anyone's business, and hands off if it is.
 *
 * Delivery is queued and deferred until the surrounding transaction commits.
 * Both matter: a save should never wait on TROPIKAL being reachable, and an
 * event for a change that then rolled back would send TROPIKAL looking for
 * something that never happened.
 */
class ChangeEventDispatcher
{
    public function dispatch(Installation $installation, ChangeEnvelope $envelope): void
    {
        $routeKeys = $this->routeKeysFor($installation, $envelope->slug, $envelope->event);
        if ($routeKeys === []) {
            // Shared, but nothing is listening. A resource with no Job triggered
            // by it is a perfectly normal state — the owner shared it so their
            // assistant can read it, not so something runs on every edit.
            return;
        }

        foreach ($routeKeys as $routeKey) {
            DeliverChangeEvent::dispatch($installation->getKey(), $routeKey, $envelope->toArray())
                ->afterCommit();
        }
    }

    /**
     * Job routes triggered by this slug and event.
     *
     * The map is a local cache of what TROPIKAL knows, refreshed whenever the
     * dashboard lists routes. Reading it here rather than asking the control
     * plane keeps a model save off the network.
     *
     * @return array<int, string>
     */
    public function routeKeysFor(Installation $installation, string $slug, string $event): array
    {
        $settings = is_array($installation->settings) ? $installation->settings : [];
        $triggers = $settings['change_triggers'] ?? [];
        if (! is_array($triggers)) {
            return [];
        }

        $keys = [];
        foreach ($triggers as $trigger) {
            if (! is_array($trigger)) {
                continue;
            }
            if ((string) ($trigger['slug'] ?? '') !== $slug) {
                continue;
            }
            $events = is_array($trigger['events'] ?? null) ? $trigger['events'] : [];
            if (! in_array($event, array_map('strval', $events), true)) {
                continue;
            }
            $routeKey = (string) ($trigger['route_key'] ?? '');
            if ($routeKey !== '') {
                $keys[] = $routeKey;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Replace the cached trigger map. Written by the dashboard from what the
     * control plane reports, so a Job deleted on TROPIKAL stops firing here
     * without anyone having to remember to clean up.
     *
     * @param  array<int, array<string, mixed>>  $triggers
     */
    public function rememberTriggers(Installation $installation, array $triggers): void
    {
        $clean = [];
        foreach ($triggers as $trigger) {
            if (! is_array($trigger)) {
                continue;
            }
            $slug = (string) ($trigger['slug'] ?? '');
            $routeKey = (string) ($trigger['route_key'] ?? '');
            $events = array_values(array_filter(
                array_map('strval', is_array($trigger['events'] ?? null) ? $trigger['events'] : []),
                fn (string $event): bool => in_array($event, [
                    ChangeEnvelope::CREATED,
                    ChangeEnvelope::UPDATED,
                    ChangeEnvelope::DELETED,
                ], true),
            ));

            if ($slug !== '' && $routeKey !== '' && $events !== []) {
                $clean[] = ['slug' => $slug, 'route_key' => $routeKey, 'events' => $events];
            }
        }

        $settings = is_array($installation->settings) ? $installation->settings : [];
        if (($settings['change_triggers'] ?? null) === $clean) {
            return;
        }

        $settings['change_triggers'] = $clean;
        DB::transaction(fn () => $installation->forceFill(['settings' => $settings])->save());
    }
}
