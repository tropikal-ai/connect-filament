<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use TropikalAI\ConnectFilament\Services\CapabilityGrantManager;
use TropikalAI\ConnectFilament\Services\ResourceRegistry;
use TropikalAI\ConnectFilament\Tests\Fixtures\Post;

/**
 * "Untick anything TROPIKAL should not see" is a promise, and these tests are
 * where it is kept: the unticked field must be gone from what the site hands
 * over, not merely hidden by whatever receives it.
 */
final class CapabilityFieldSelectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePostResource();
    }

    public function test_adding_a_resource_is_the_grant_and_ticks_every_readable_field(): void
    {
        $installation = app(CapabilityGrantManager::class)->share($this->connectedInstallation(), 'posts');

        $this->assertSame(['posts'], $installation->allowed_resources);
        $this->assertContains('read', $installation->resource_permissions['posts']);
        $this->assertSame(
            ['field:title', 'field:body', 'field:published_at'],
            array_values(array_filter(
                $installation->resource_permissions['posts'],
                fn (string $grant): bool => str_starts_with($grant, 'field:'),
            )),
        );
    }

    public function test_see_it_is_always_on_while_change_and_delete_are_opt_in(): void
    {
        $manager = app(CapabilityGrantManager::class);

        $readOnly = $manager->share($this->connectedInstallation(), 'posts');
        $this->assertContains('read', $readOnly->resource_permissions['posts']);
        $this->assertNotContains('update', $readOnly->resource_permissions['posts']);
        $this->assertNotContains('delete', $readOnly->resource_permissions['posts']);

        $full = $manager->share($readOnly, 'posts', ['write', 'delete']);
        foreach (['read', 'create', 'update', 'delete'] as $permission) {
            $this->assertContains($permission, $full->resource_permissions['posts']);
        }
    }

    public function test_unticking_a_field_removes_it_from_what_the_site_hands_over(): void
    {
        $manager = app(CapabilityGrantManager::class);
        $installation = $manager->share($this->connectedInstallation(), 'posts');
        $installation = $manager->setField($installation, 'posts', 'body', false);

        $this->assertSame(['title', 'published_at'], $manager->selectedFields($installation, 'posts'));
        $this->assertNotContains('field:body', $installation->resource_permissions['posts']);

        Post::query()->create(['title' => 'Hello', 'body' => 'Secret body', 'secret_note' => 'x']);
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/resources/posts";

        $data = $this->signedGet($installation, $path, null, 'field_selection_list')
            ->assertOk()
            ->json('data.0');

        $this->assertArrayNotHasKey('body', $data);
        $this->assertArrayHasKey('title', $data);
        $this->assertArrayHasKey('id', $data);
    }

    /**
     * The identifier is not a field the owner can trade away: without it nothing
     * TROPIKAL receives could be matched back to a record.
     */
    public function test_the_identifier_cannot_be_excluded(): void
    {
        $manager = app(CapabilityGrantManager::class);
        $installation = $manager->share($this->connectedInstallation(), 'posts');

        $this->expectException(\InvalidArgumentException::class);
        $manager->setField($installation, 'posts', 'id', false);
    }

    public function test_the_identifier_still_travels_when_every_field_is_unticked(): void
    {
        $manager = app(CapabilityGrantManager::class);
        $installation = $manager->share($this->connectedInstallation(), 'posts');
        foreach (['title', 'body', 'published_at'] as $field) {
            $installation = $manager->setField($installation, 'posts', $field, false);
        }

        $post = Post::query()->create(['title' => 'Hello', 'body' => 'B']);
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/resources/posts/{$post->getKey()}";

        $this->assertSame(
            ['id' => $post->getKey()],
            $this->signedGet($installation, $path, null, 'identifier_only')->assertOk()->json('data'),
        );
    }

    public function test_fields_cannot_be_selected_on_a_resource_that_is_not_shared(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(CapabilityGrantManager::class)->setField($this->connectedInstallation(), 'posts', 'body', false);
    }

    public function test_a_field_selection_is_not_a_way_to_reach_an_unreadable_field(): void
    {
        $manager = app(CapabilityGrantManager::class);
        $installation = $manager->share($this->connectedInstallation(), 'posts');

        $this->expectException(\InvalidArgumentException::class);
        $manager->setField($installation, 'posts', 'secret_note', true);
    }

    /**
     * The grant manager stays the single writer, so a permission toggle that
     * rebuilds the stored permissions must carry the field selection through.
     * Losing it here would silently re-share a field the owner had unticked.
     */
    public function test_toggling_a_permission_does_not_re_widen_the_field_selection(): void
    {
        $manager = app(CapabilityGrantManager::class);
        $installation = $manager->share($this->connectedInstallation(), 'posts');
        $installation = $manager->setField($installation, 'posts', 'body', false);

        $installation = $manager->set($installation, 'posts', 'write', true);
        $this->assertSame(['title', 'published_at'], $manager->selectedFields($installation, 'posts'));

        $installation = $manager->set($installation, 'posts', 'delete', true);
        $this->assertSame(['title', 'published_at'], $manager->selectedFields($installation, 'posts'));
        $this->assertNotContains('field:body', $installation->resource_permissions['posts']);
    }

    /**
     * Installations connected before field selection existed stored no `field:*`
     * entry at all. That has to keep meaning "every readable field" — reading it
     * as "none" would blank out every live integration on upgrade.
     */
    public function test_an_installation_stored_before_field_selection_still_receives_every_field(): void
    {
        $installation = $this->connectedInstallation([
            'allowed_resources' => ['posts'],
            'resource_permissions' => ['posts' => ['read']],
        ]);

        $this->assertSame(
            ['title', 'body', 'published_at'],
            app(CapabilityGrantManager::class)->selectedFields($installation, 'posts'),
        );

        Post::query()->create(['title' => 'Hello', 'body' => 'B']);
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/resources/posts";
        $data = $this->signedGet($installation, $path, null, 'legacy_no_selection')->assertOk()->json('data.0');

        $this->assertArrayHasKey('body', $data);
        $this->assertArrayHasKey('title', $data);
    }

    public function test_stop_sharing_removes_the_resource_and_its_grants_together(): void
    {
        $manager = app(CapabilityGrantManager::class);
        $installation = $manager->share($this->connectedInstallation(), 'posts', ['write', 'delete']);

        $installation = $manager->stopSharing($installation, 'posts');

        $this->assertSame([], $installation->allowed_resources);
        $this->assertSame([], $installation->resource_permissions);
        $this->assertFalse($manager->isShared($installation, 'posts'));

        $path = "/api/tropikal-connect/installations/{$installation->public_id}/resources/posts";
        $this->signedGet($installation, $path, null, 'after_stop_sharing')->assertForbidden();
    }

    /**
     * A resource may never publish an operation its own row does not permit —
     * the pills the owner sees and the operations TROPIKAL is offered are the
     * same decision, not two that could drift.
     */
    public function test_published_operations_never_exceed_the_rows_permissions(): void
    {
        $manager = app(CapabilityGrantManager::class);
        $installation = $manager->share($this->connectedInstallation(), 'posts');

        $operations = fn ($installation): array => array_column(
            app(ResourceRegistry::class)
                ->controlPlaneResourcesFor($installation)['posts']['capabilities'],
            'operation',
        );

        $this->assertSame(['list', 'get'], $operations($installation));

        $installation = $manager->share($installation, 'posts', ['write']);
        $this->assertSame(['list', 'get', 'create', 'update'], $operations($installation));

        $installation = $manager->share($installation, 'posts', ['write', 'delete']);
        $this->assertContains('delete', $operations($installation));

        $installation = $manager->share($installation, 'posts');
        $this->assertSame(['list', 'get'], $operations($installation));
    }
}
