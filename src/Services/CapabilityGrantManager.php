<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use TropikalAI\ConnectFilament\Domain\FieldSelection;
use TropikalAI\ConnectFilament\Models\Installation;

/**
 * The only writer of `resource_permissions`.
 *
 * Everything the owner can decide about a shared resource — whether it is
 * shared at all, what TROPIKAL may do with it, and which of its fields travel —
 * lands in one array, written from one place. That is what makes the stored
 * permissions readable as a single answer to "what did the owner agree to",
 * instead of the union of whatever several call sites each happened to append.
 *
 * Adding a resource IS the grant. There is no second allocation step and no
 * per-consumer picker: a shared resource is readable, optionally changeable and
 * deletable, and publishes the fields the owner left ticked.
 */
class CapabilityGrantManager
{
    /** @var array<int, string> */
    private const GRANTS = ['read', 'write', 'delete'];

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
                'delete' => in_array('delete', $resourcePermissions, true),
            ];
        }

        return $grants;
    }

    /**
     * Slugs the owner has actually added, in registry order.
     *
     * @return array<int, string>
     */
    public function sharedSlugs(Installation $installation): array
    {
        $permissions = $installation->resource_permissions ?? [];

        return array_values(array_filter(
            array_map('strval', array_keys($this->registry->all())),
            fn (string $slug): bool => is_array($permissions[$slug] ?? null) && $permissions[$slug] !== [],
        ));
    }

    public function isShared(Installation $installation, string $slug): bool
    {
        return in_array($slug, $this->sharedSlugs($installation), true);
    }

    /**
     * Add a resource. Adding IS the grant, so this issues it immediately.
     *
     * `See it` is not optional — a resource TROPIKAL may not read is not shared,
     * it is absent. Change and delete are opt-in. Every readable field starts
     * ticked, which is what the owner sees, and is recorded explicitly: a column
     * added to the model later is NOT disclosed until they tick it.
     *
     * @param  array<int, string>  $grants  any of write, delete
     */
    public function share(Installation $installation, string $slug, array $grants = []): Installation
    {
        $this->assertDiscoverable($slug);

        $state = $this->state($installation);
        $state[$slug] = [
            'read' => true,
            'write' => in_array('write', $grants, true),
            'delete' => in_array('delete', $grants, true),
            'fields' => $this->selectableFields($slug),
        ];

        return $this->persist($installation, $state);
    }

    /**
     * Remove a resource. The grant goes with it — there is no state where a
     * resource is un-shared but still reachable.
     */
    public function stopSharing(Installation $installation, string $slug): Installation
    {
        $state = $this->state($installation);
        unset($state[$slug]);

        return $this->persist($installation, $state);
    }

    public function set(Installation $installation, string $slug, string $grant, bool $enabled): Installation
    {
        if (! in_array($grant, self::GRANTS, true)) {
            throw new \InvalidArgumentException('Capability grants must be read, write, or delete.');
        }
        $this->assertDiscoverable($slug);

        $state = $this->state($installation);
        $state[$slug] ??= ['read' => false, 'write' => false, 'delete' => false, 'fields' => null];
        $state[$slug][$grant] = $enabled;

        // Toggling a permission must never silently re-widen a field selection,
        // so a resource that has one keeps it. A resource being granted for the
        // first time gets the default: everything readable.
        if (($state[$slug]['fields'] ?? null) === null && $this->isEnabled($state[$slug])) {
            $state[$slug]['fields'] = $this->selectableFields($slug);
        }

        return $this->persist($installation, $state);
    }

    /**
     * Tick or untick one field of a shared resource.
     *
     * The identifier is not selectable. Nothing TROPIKAL receives could be
     * matched back to a record without it, so removing it would not produce a
     * narrower share — it would produce an unusable one.
     */
    public function setField(Installation $installation, string $slug, string $field, bool $enabled): Installation
    {
        $this->assertDiscoverable($slug);

        if ($field === $this->identifierFor($slug)) {
            throw new \InvalidArgumentException("The identifier cannot be excluded: {$slug}.{$field}");
        }
        if (! in_array($field, $this->selectableFields($slug), true)) {
            throw new \InvalidArgumentException("Field is not readable on this resource: {$slug}.{$field}");
        }

        $state = $this->state($installation);
        if (! isset($state[$slug])) {
            throw new \InvalidArgumentException("Resource is not shared: {$slug}");
        }

        $selected = $state[$slug]['fields'] ?? $this->selectableFields($slug);
        $state[$slug]['fields'] = array_values(array_unique($enabled
            ? [...$selected, $field]
            : array_values(array_diff($selected, [$field]))));

        return $this->persist($installation, $state);
    }

    /**
     * Fields of a shared resource that currently travel, identifier aside.
     *
     * @return array<int, string>
     */
    public function selectedFields(Installation $installation, string $slug): array
    {
        $selected = FieldSelection::fromPermissions($installation->resource_permissions ?? [], $slug);

        // Null means no selection was ever recorded, which is what every
        // installation stored before field selection existed. Those keep
        // receiving every readable field.
        return $selected === null
            ? $this->selectableFields($slug)
            : array_values(array_intersect($this->selectableFields($slug), $selected));
    }

    /**
     * Every field of a resource the owner could tick, identifier aside.
     *
     * @return array<int, string>
     */
    public function selectableFields(string $slug): array
    {
        $resource = $this->registry->resource($slug) ?? [];
        $identifier = $this->registry->identifierFor($resource);

        return array_values(array_filter(
            array_map('strval', array_keys(array_filter(
                (array) ($resource['fields'] ?? []),
                fn (mixed $definition): bool => is_array($definition) && ($definition['readable'] ?? true) !== false,
            ))),
            fn (string $field): bool => $field !== $identifier,
        ));
    }

    public function identifierFor(string $slug): string
    {
        return $this->registry->identifierFor($this->registry->resource($slug) ?? []);
    }

    /**
     * @return array<string, array{read: bool, write: bool, delete: bool, fields: array<int, string>|null}>
     */
    private function state(Installation $installation): array
    {
        $permissions = $installation->resource_permissions ?? [];

        $state = [];
        foreach ($this->grants($installation) as $slug => $grants) {
            $stored = is_array($permissions[$slug] ?? null) ? $permissions[$slug] : [];
            if ($stored === [] && ! $this->isEnabled($grants)) {
                continue;
            }
            $state[(string) $slug] = [
                ...$grants,
                'fields' => FieldSelection::fromPermissions($permissions, (string) $slug),
            ];
        }

        return $state;
    }

    /**
     * @param  array<string, array{read: bool, write: bool, delete: bool, fields: array<int, string>|null}>  $state
     */
    private function persist(Installation $installation, array $state): Installation
    {
        $permissions = [];
        $grants = [];

        foreach (array_keys($this->registry->all()) as $slug) {
            $slug = (string) $slug;
            $resourceState = $state[$slug] ?? ['read' => false, 'write' => false, 'delete' => false, 'fields' => null];
            $grants[$slug] = [
                'read' => (bool) ($resourceState['read'] ?? false),
                'write' => (bool) ($resourceState['write'] ?? false),
                'delete' => (bool) ($resourceState['delete'] ?? false),
            ];

            $resourcePermissions = [];
            if ($grants[$slug]['read']) {
                $resourcePermissions[] = 'read';
            }
            if ($grants[$slug]['write']) {
                $resourcePermissions = [...$resourcePermissions, 'create', 'update'];
            }
            if ($grants[$slug]['delete']) {
                $resourcePermissions[] = 'delete';
            }
            if ($resourcePermissions === []) {
                continue;
            }

            $permissions[$slug] = array_values(array_unique([
                ...$resourcePermissions,
                ...$this->fieldGrants($slug, $resourceState['fields'] ?? null),
            ]));
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

    /**
     * @param  array<int, string>|null  $fields
     * @return array<int, string>
     */
    private function fieldGrants(string $slug, ?array $fields): array
    {
        if ($fields === null) {
            return [];
        }

        // Always via FieldSelection, whose marker is what makes "the owner
        // unticked everything" legible as a choice. Without it an empty
        // selection reads as "never chose" and the projection fails open.
        return FieldSelection::toGrants(array_intersect($this->selectableFields($slug), $fields));
    }

    /**
     * @param  array{read?: bool, write?: bool, delete?: bool}  $grants
     */
    private function isEnabled(array $grants): bool
    {
        return ($grants['read'] ?? false) || ($grants['write'] ?? false) || ($grants['delete'] ?? false);
    }

    private function assertDiscoverable(string $slug): void
    {
        if (! $this->registry->resource($slug)) {
            throw new \InvalidArgumentException("Capability resource is not discoverable: {$slug}");
        }
    }
}
