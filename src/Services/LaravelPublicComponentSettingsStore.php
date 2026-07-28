<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use TropikalAI\Connect\Application\PublicChannels\Ports\PublicComponentSettingsStore;
use TropikalAI\Connect\Domain\PublicChannels\PublicComponentPlacement;
use TropikalAI\Connect\Domain\PublicChannels\PublicComponentType;
use TropikalAI\ConnectFilament\Models\Installation;

final class LaravelPublicComponentSettingsStore implements PublicComponentSettingsStore
{
    public function get(PublicComponentType $type): PublicComponentPlacement
    {
        $installation = Installation::query()->latest('updated_at')->first();
        $settings = is_array($installation?->settings) ? $installation->settings : [];
        $components = is_array($settings['public_components'] ?? null) ? $settings['public_components'] : [];
        $component = is_array($components[$type->value] ?? null) ? $components[$type->value] : [];
        $stored = $component['auto_inject'] ?? null;

        return new PublicComponentPlacement($type, $stored === null ? true : (bool) $stored);
    }

    public function save(PublicComponentPlacement $placement): void
    {
        $installation = Installation::query()->latest('updated_at')->firstOrFail();
        $settings = is_array($installation->settings) ? $installation->settings : [];
        $components = is_array($settings['public_components'] ?? null) ? $settings['public_components'] : [];
        $components[$placement->component->value] = ['auto_inject' => $placement->autoInject];
        $settings['public_components'] = $components;
        $installation->forceFill(['settings' => $settings])->save();
    }
}
