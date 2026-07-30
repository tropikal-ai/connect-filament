<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Domain;

/**
 * Which readable fields of a shared resource may travel to TROPIKAL.
 *
 * The selection is stored inside `resource_permissions[$slug]` as `field:<name>`
 * entries, alongside the operation grants and the existing `action:{$name}`
 * ones. That is the Connect-wide convention — `tropikal-ai/connect` reads the
 * same strings in `ResourceSchema::project()` so every other host narrows
 * identically — but the format is owned here, by the package that writes it,
 * rather than being borrowed from a constant across a release boundary.
 *
 * Three states, and the difference between the last two is the whole reason
 * this class exists:
 *
 *  - some `field:*` entries → exactly those fields travel;
 *  - the marker with no entries → the owner unticked everything, so only the
 *    identifier travels;
 *  - nothing at all → no selection was ever made, which is what every
 *    installation stored before field selection existed, and every readable
 *    field still travels.
 *
 * Collapsing the last two would mean the safe reading of one is the unsafe
 * reading of the other: an owner who unticked everything would silently get
 * everything back.
 */
final readonly class FieldSelection
{
    public const GRANT_PREFIX = 'field:';

    public const MARKER = 'fields:selected';

    /**
     * @param  array<string, array<int, string>>  $permissions
     * @return array<int, string>|null null when no selection was ever recorded
     */
    public static function fromPermissions(array $permissions, string $slug): ?array
    {
        $grants = $permissions[$slug] ?? [];
        if (! is_array($grants)) {
            return null;
        }

        $selected = [];
        $declared = false;

        foreach ($grants as $grant) {
            if (! is_string($grant)) {
                continue;
            }
            if ($grant === self::MARKER) {
                $declared = true;

                continue;
            }
            if (str_starts_with($grant, self::GRANT_PREFIX)) {
                $declared = true;
                $selected[] = substr($grant, strlen(self::GRANT_PREFIX));
            }
        }

        return $declared ? array_values(array_unique($selected)) : null;
    }

    /**
     * The stored form of a selection: the marker first, then one entry per field.
     *
     * @param  array<int, string>  $fields
     * @return array<int, string>
     */
    public static function toGrants(array $fields): array
    {
        return [
            self::MARKER,
            ...array_map(fn (string $field): string => self::GRANT_PREFIX.$field, array_values($fields)),
        ];
    }
}
