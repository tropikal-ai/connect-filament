<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use Illuminate\Support\Facades\Http;
use TropikalAI\ConnectFilament\Filament\Resources\InstallationResource\Pages\Dashboard;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Services\CapabilityGrantManager;
use TropikalAI\ConnectFilament\Services\ResourceRegistry;
use TropikalAI\ConnectFilament\Tests\Fixtures\ReviewedPostAction;

final class OwnerActionGrantTest extends TestCase
{
    private function manager(): CapabilityGrantManager
    {
        $this->configurePostResource();
        config()->set('connect-filament.resources.posts.actions.review', [
            'label' => 'Review posts',
            'handler' => ReviewedPostAction::class,
            'input_schema' => ['type' => 'object', 'additionalProperties' => false,
                'properties' => ['ids' => ['type' => 'array', 'maxItems' => 2,
                    'items' => ['type' => 'integer', 'minimum' => 1]]]],
        ]);
        $this->app->forgetInstance(CapabilityGrantManager::class);

        return app(CapabilityGrantManager::class);
    }

    public function test_owner_can_grant_one_declared_action_without_generic_crud_then_revoke_it(): void
    {
        $manager = $this->manager();
        $installation = $manager->setAction($this->connectedInstallation(), 'posts', 'review', true);
        $this->assertSame(['posts'], $installation->allowed_resources);
        $this->assertContains('action:review', $installation->resource_permissions['posts']);
        foreach (['create', 'update', 'delete'] as $grant) {
            $this->assertNotContains($grant, $installation->resource_permissions['posts']);
        }
        $this->assertTrue($manager->actionGrants($installation)['posts']['review']);
        $resource = app(ResourceRegistry::class)->controlPlaneResourcesFor($installation)['posts'];
        $this->assertSame(['review'], array_column($resource['capabilities'], 'operation'));
        $this->assertTrue($resource['capabilities'][0]['requires_confirmation']);
        $installation = $manager->setAction($installation, 'posts', 'review', false);
        $this->assertSame([], $installation->allowed_resources);
        $this->assertSame([], $installation->resource_permissions);
    }

    public function test_field_and_read_changes_preserve_exact_actions_and_field_selection(): void
    {
        $manager = $this->manager();
        $installation = $manager->share($this->connectedInstallation(), 'posts');
        $installation = $manager->setAction($installation, 'posts', 'review', true);
        $installation = $manager->setField($installation, 'posts', 'body', false);
        $installation = $manager->set($installation, 'posts', 'write', true);
        $this->assertContains('action:review', $installation->resource_permissions['posts']);
        $this->assertNotContains('field:body', $installation->resource_permissions['posts']);
        $this->assertContains('fields:selected', $installation->resource_permissions['posts']);
        $installation = $manager->set($installation, 'posts', 'read', false);
        $this->assertContains('action:review', $installation->resource_permissions['posts']);
        $installation = $manager->stopSharing($installation, 'posts');
        $this->assertSame([], $installation->resource_permissions);
    }

    public function test_unknown_and_legacy_model_method_actions_cannot_be_granted(): void
    {
        $manager = $this->manager();
        $installation = $this->connectedInstallation();
        $before = $installation->refresh()->getAttributes();
        foreach (['publish', 'missing', '../review', 'review:delete'] as $action) {
            try {
                $manager->setAction($installation, 'posts', $action, true);
                $this->fail('An undeclared typed action must be refused.');
            } catch (\InvalidArgumentException) {
                $this->assertSame($before, $installation->refresh()->getAttributes());
            }
        }
        $this->assertSame(['review'], array_keys($manager->ownerActions('posts')));
    }

    public function test_dashboard_control_uses_exact_grant_manager_and_preserves_other_choices(): void
    {
        $manager = $this->manager();
        $installation = $manager->share($this->connectedInstallation(), 'posts');
        $installation->update(['status' => Installation::STATUS_PENDING_REGISTRATION]);
        Http::preventStrayRequests();
        $page = new Dashboard;
        $page->mount();
        $page->setOwnerActionGrant('posts', 'review', true);
        $this->assertTrue($page->ownerActionGrants['posts']['review']);
        $this->assertTrue($page->capabilityGrants['posts']['read']);
        $this->assertContains('field:title', $page->installation->resource_permissions['posts']);
        $page->setOwnerActionGrant('posts', 'review', false);
        $this->assertFalse($page->ownerActionGrants['posts']['review']);
        $this->assertNotContains('action:review', $page->installation->resource_permissions['posts']);
        $this->assertSame('Review posts', $page->ownerActions('posts')['review']['label']);
    }
}
