<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use Illuminate\Support\Facades\Queue;
use TropikalAI\ConnectFilament\Domain\ChangeEnvelope;
use TropikalAI\ConnectFilament\Domain\ChangeOrigin;
use TropikalAI\ConnectFilament\Jobs\DeliverChangeEvent;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Observers\SharedResourceObserver;
use TropikalAI\ConnectFilament\Services\CapabilityGrantManager;
use TropikalAI\ConnectFilament\Services\ChangeEventDispatcher;
use TropikalAI\ConnectFilament\Tests\Fixtures\Post;

/**
 * Events flow CMS → TROPIKAL, once, and only for things the owner shared.
 *
 * The two failure modes worth most of this file are silence where there should
 * be an event, and an event where there should be silence — the second being
 * worse, because a Job that edits on change would then re-trigger itself off
 * its own edit, forever.
 */
final class ChangeEventTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePostResource();
        Queue::fake();
    }

    private function sharedInstallation(array $events = ['created', 'updated', 'deleted']): Installation
    {
        $installation = app(CapabilityGrantManager::class)->share($this->connectedInstallation(), 'posts');
        app(ChangeEventDispatcher::class)->rememberTriggers($installation, [
            ['slug' => 'posts', 'route_key' => 'social-draft', 'events' => $events],
        ]);
        SharedResourceObserver::listen(Post::class, 'posts');

        return $installation->refresh();
    }

    private function dispatched(): array
    {
        $found = [];
        Queue::assertPushed(DeliverChangeEvent::class, function (DeliverChangeEvent $job) use (&$found): bool {
            $found[] = $job;

            return true;
        });

        return $found;
    }

    public function test_a_shared_resource_emits_its_change(): void
    {
        $this->sharedInstallation();

        Post::query()->create(['title' => 'Hello', 'body' => 'World']);

        $jobs = $this->dispatched();
        $this->assertCount(1, $jobs);
        $this->assertSame('social-draft', $jobs[0]->routeKey);
        $this->assertSame(ChangeEnvelope::CREATED, $jobs[0]->envelope['event']);
        $this->assertSame(ChangeOrigin::SITE, $jobs[0]->envelope['origin']);
        $this->assertNotEmpty($jobs[0]->envelope['event_id']);
    }

    public function test_an_unshared_resource_emits_nothing(): void
    {
        $installation = $this->connectedInstallation();
        app(ChangeEventDispatcher::class)->rememberTriggers($installation, [
            ['slug' => 'posts', 'route_key' => 'social-draft', 'events' => ['created']],
        ]);
        SharedResourceObserver::listen(Post::class, 'posts');

        Post::query()->create(['title' => 'Hello']);

        Queue::assertNotPushed(DeliverChangeEvent::class);
    }

    /**
     * The echo guard. A write TROPIKAL made arrives through the signed request
     * middleware, which stamps the origin; reporting it back would make a Job
     * that edits on change trigger itself forever.
     */
    public function test_a_tropikal_origin_write_emits_nothing(): void
    {
        $this->sharedInstallation();
        ChangeOrigin::stamp(request());

        Post::query()->create(['title' => 'Written by TROPIKAL']);

        Queue::assertNotPushed(DeliverChangeEvent::class);
    }

    /**
     * Eloquent fires `updated` for a touch that only moved `updated_at`.
     * Forwarding those would run the owner's Job on every unrelated bulk touch.
     */
    public function test_a_save_that_changed_nothing_shared_is_not_news(): void
    {
        $this->sharedInstallation();
        $post = Post::query()->create(['title' => 'Hello']);
        Queue::fake();

        $post->touch();

        Queue::assertNotPushed(DeliverChangeEvent::class);
    }

    public function test_editing_a_shared_field_is_news(): void
    {
        $this->sharedInstallation();
        $post = Post::query()->create(['title' => 'Hello']);
        Queue::fake();

        $post->update(['title' => 'Hello again']);

        $jobs = $this->dispatched();
        $this->assertCount(1, $jobs);
        $this->assertSame(ChangeEnvelope::UPDATED, $jobs[0]->envelope['event']);
    }

    /**
     * An unticked field is absent from the payload, not filtered downstream —
     * so editing only that field is not news either.
     */
    public function test_an_unticked_field_is_neither_carried_nor_watched(): void
    {
        $installation = $this->sharedInstallation();
        $installation = app(CapabilityGrantManager::class)->setField($installation, 'posts', 'body', false);
        app(ChangeEventDispatcher::class)->rememberTriggers($installation, [
            ['slug' => 'posts', 'route_key' => 'social-draft', 'events' => ['created', 'updated']],
        ]);

        $post = Post::query()->create(['title' => 'Hello', 'body' => 'Secret']);
        $created = $this->dispatched();
        $this->assertArrayNotHasKey('body', $created[0]->envelope['context']);
        $this->assertArrayHasKey('title', $created[0]->envelope['context']);

        Queue::fake();
        $post->update(['body' => 'Still secret']);
        Queue::assertNotPushed(DeliverChangeEvent::class);
    }

    /**
     * By the time `deleted` fires the row is gone, so the snapshot has to be
     * taken while the attributes are still readable.
     */
    public function test_a_delete_carries_a_pre_delete_snapshot(): void
    {
        $this->sharedInstallation();
        $post = Post::query()->create(['title' => 'Doomed', 'body' => 'Body']);
        $id = $post->getKey();
        Queue::fake();

        $post->delete();

        $jobs = $this->dispatched();
        $this->assertCount(1, $jobs);
        $this->assertSame(ChangeEnvelope::DELETED, $jobs[0]->envelope['event']);
        $this->assertSame('Doomed', $jobs[0]->envelope['context']['title']);
        $this->assertSame((string) $id, $jobs[0]->envelope['object_id']);
        $this->assertNull(Post::query()->find($id), 'the run is read-only — the record really is gone');
    }

    public function test_an_event_nothing_is_triggered_by_is_not_delivered(): void
    {
        $this->sharedInstallation(['deleted']);

        Post::query()->create(['title' => 'Nobody is listening']);

        Queue::assertNotPushed(DeliverChangeEvent::class);
    }

    /**
     * A retried delivery is the same event, not a second one — the id is fixed
     * when the change happens, so the receiving end can dedupe on it.
     */
    public function test_a_retried_delivery_carries_the_same_event_id(): void
    {
        $this->sharedInstallation();
        Post::query()->create(['title' => 'Hello']);

        $job = $this->dispatched()[0];
        $first = $job->envelope['event_id'];

        $this->assertSame($first, $job->envelope['event_id']);
        $this->assertGreaterThan(1, $job->tries, 'delivery retries, so the id has to survive the retry');
    }

    public function test_two_changes_are_two_distinct_events(): void
    {
        $this->sharedInstallation();

        Post::query()->create(['title' => 'One']);
        Post::query()->create(['title' => 'Two']);

        $ids = array_map(fn (DeliverChangeEvent $job): string => $job->envelope['event_id'], $this->dispatched());
        $this->assertCount(2, $ids);
        $this->assertNotSame($ids[0], $ids[1]);
    }

    public function test_the_trigger_map_drops_junk_and_unknown_events(): void
    {
        $installation = $this->connectedInstallation();
        $dispatcher = app(ChangeEventDispatcher::class);

        $dispatcher->rememberTriggers($installation, [
            ['slug' => 'posts', 'route_key' => 'ok', 'events' => ['created', 'exploded']],
            ['slug' => '', 'route_key' => 'no-slug', 'events' => ['created']],
            ['slug' => 'posts', 'route_key' => '', 'events' => ['created']],
            'not-an-array',
        ]);

        $this->assertSame(
            [['slug' => 'posts', 'route_key' => 'ok', 'events' => ['created']]],
            $installation->refresh()->settings['change_triggers'],
        );
    }
}
