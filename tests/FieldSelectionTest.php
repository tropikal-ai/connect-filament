<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use PHPUnit\Framework\TestCase as UnitTestCase;
use TropikalAI\ConnectFilament\Domain\FieldSelection;

/**
 * Three states, and the difference between the last two is the point: collapsing
 * them would mean the safe reading of one is the unsafe reading of the other.
 */
final class FieldSelectionTest extends UnitTestCase
{
    public function test_named_fields_are_the_selection(): void
    {
        $this->assertSame(
            ['title', 'body'],
            FieldSelection::fromPermissions(['posts' => ['read', 'field:title', 'field:body']], 'posts'),
        );
    }

    public function test_the_marker_alone_means_the_owner_unticked_everything(): void
    {
        $this->assertSame([], FieldSelection::fromPermissions(['posts' => ['read', 'fields:selected']], 'posts'));
    }

    /**
     * An installation stored before field selection existed. Reading this as
     * "none" would blank out every live integration on upgrade.
     */
    public function test_nothing_recorded_means_no_selection_was_ever_made(): void
    {
        $this->assertNull(FieldSelection::fromPermissions(['posts' => ['read', 'create']], 'posts'));
        $this->assertNull(FieldSelection::fromPermissions([], 'posts'));
        $this->assertNull(FieldSelection::fromPermissions(['posts' => 'not-an-array'], 'posts'));
    }

    public function test_another_resources_selection_is_not_this_ones(): void
    {
        $this->assertNull(FieldSelection::fromPermissions(['pages' => ['field:title']], 'posts'));
    }

    public function test_grants_round_trip_through_storage(): void
    {
        $grants = FieldSelection::toGrants(['title', 'body']);

        $this->assertSame(['fields:selected', 'field:title', 'field:body'], $grants);
        $this->assertSame(['title', 'body'], FieldSelection::fromPermissions(['posts' => $grants], 'posts'));
    }

    public function test_an_empty_selection_still_round_trips_as_a_choice(): void
    {
        $this->assertSame([], FieldSelection::fromPermissions(['posts' => FieldSelection::toGrants([])], 'posts'));
    }

    public function test_duplicate_entries_collapse(): void
    {
        $this->assertSame(
            ['title'],
            FieldSelection::fromPermissions(['posts' => ['field:title', 'field:title']], 'posts'),
        );
    }
}
