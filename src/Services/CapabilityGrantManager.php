<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use TropikalAI\ConnectFilament\Models\Installation;

class CapabilityGrantManager
{
    public function __construct(private readonly ResourceRegistry $registry) {}

    public function grants(Installation $installation): array
    {
        $permissions = $installation->resource_permissions ?? [];
        $grants = [];

        foreach ($this->registry->all() as $slug => $_resource) {
            $resourcePermissions = is_array($permissions[$slug] ?? null) ? $permissions[$slug] : [];
            $grants[$slug] = [
                'read' => in_array('read', $resourcePermissions, true),
                'write' => in_array('create', $resourcePermissions, true) && in_array('update', $resourcePermissions, true),
            ];
        }

        return $grants;
    }

    public function set(Installation $installation, string $slug, string $grant, bool $enabled): Installation
    {
        if (! in_array($grant, ['read', 'write'], true)) {
            throw new \InvalidArgumentException('Capability grants must be read or write.');
        }
        if (! $this->registry->resource($slug)) {
            throw new \InvalidArgumentException("Capability resource is not discoverable: {$slug}");
        }

        $grants = $this->grants($installation);
        $grants[$slug][$grant] = $enabled;

        $permissions = [];
        foreach ($grants as $resourceSlug => $resourceGrants) {
            $resourcePermissions = [];
            if (($resourceGrants['read'] ?? false) === true) {
                $resourcePermissions[] = 'read';
            }
            if (($resourceGrants['write'] ?? false) === true) {
                $resourcePermissions = [...$resourcePermissions, 'create', 'update'];
            }
            if ($resourcePermissions !== []) {
                $permissions[$resourceSlug] = array_values(array_unique($resourcePermissions));
            }
        }

        $settings = is_array($installation->settings) ? $installation->settings : [];
        $settings['capability_grants'] = $grants;

        $installation->forceFill([
            'allowed_resources' => array_keys($permissions),
            'resource_permissions' => $permissions,
            'settings' => $settings,
            'last_synced_at' => now(),
        ])->save();

        return $installation->refresh();
    }
}
