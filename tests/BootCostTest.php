<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use TropikalAI\ConnectFilament\ConnectFilamentServiceProvider;
use TropikalAI\ConnectFilament\Jobs\DeliverChangeEvent;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Services\CapabilityGrantManager;
use TropikalAI\ConnectFilament\Services\ChangeEventDispatcher;
use TropikalAI\ConnectFilament\Services\ResourceRegistry;
use TropikalAI\ConnectFilament\Tests\Fixtures\Article;
use TropikalAI\ConnectFilament\Tests\Fixtures\Post;

final class BootCostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePostResource();
        Queue::fake();
    }

    public function test_booting_with_shared_resources_reads_only_the_installation(): void
    {
        $this->installationSharing(['posts', 'articles']);

        $queries = $this->queriesDuring(fn () => $this->bootPackage());

        $this->assertCount(1, $queries, implode("\n", $queries));
        $this->assertStringContainsString('connect_filament_installations', $queries[0]);
    }

    public function test_booting_still_watches_every_shared_resource(): void
    {
        $this->installationSharing(['posts', 'articles']);

        $this->bootPackage();
        Post::query()->create(['title' => 'Watched post']);
        Article::query()->create(['title' => 'Watched article', 'content' => 'Body']);

        $slugs = [];
        Queue::assertPushed(DeliverChangeEvent::class, function (DeliverChangeEvent $job) use (&$slugs): bool {
            $slugs[] = $job->envelope['slug'];

            return true;
        });
        sort($slugs);
        $this->assertSame(['articles', 'posts'], $slugs);
    }

    public function test_a_granted_resource_that_no_longer_exists_is_not_watched(): void
    {
        $installation = $this->installationSharing(['posts']);
        $installation->forceFill(['resource_permissions' => [
            ...$installation->resource_permissions,
            'retired_things' => ['read'],
        ]])->save();

        $this->bootPackage();
        Post::query()->create(['title' => 'Still watched']);

        Queue::assertPushed(DeliverChangeEvent::class, 1);
    }

    public function test_discovery_introspects_the_schema_once_per_process(): void
    {
        $registry = app(ResourceRegistry::class);
        $registry->all();

        $queries = $this->queriesDuring(function () use ($registry): void {
            $registry->all();
            $registry->resource('articles');
            $registry->resource('posts');
        });

        $this->assertSame([], $queries);
    }

    public function test_migrating_rediscovers_the_schema(): void
    {
        $registry = app(ResourceRegistry::class);
        $this->assertArrayNotHasKey('published_at', $registry->resource('articles')['fields']);

        Schema::table('test_articles', function (Blueprint $table): void {
            $table->timestamp('published_at')->nullable();
        });
        event(new MigrationsEnded('up'));

        $this->assertArrayHasKey('published_at', $registry->resource('articles')['fields']);
    }

    /**
     * @param  array<int, string>  $slugs
     */
    private function installationSharing(array $slugs): Installation
    {
        $manager = app(CapabilityGrantManager::class);
        $installation = $this->connectedInstallation();
        foreach ($slugs as $slug) {
            $installation = $manager->share($installation, $slug);
        }
        app(ChangeEventDispatcher::class)->rememberTriggers($installation, array_map(
            fn (string $slug): array => ['slug' => $slug, 'route_key' => "on-{$slug}", 'events' => ['created']],
            $slugs,
        ));

        return $installation->refresh();
    }

    private function bootPackage(): void
    {
        (new ConnectFilamentServiceProvider($this->app))->boot();
    }

    /**
     * @return array<int, string>
     */
    private function queriesDuring(callable $action): array
    {
        $queries = [];
        $recording = true;
        DB::listen(function (QueryExecuted $query) use (&$queries, &$recording): void {
            if ($recording) {
                $queries[] = $query->sql;
            }
        });
        $action();
        $recording = false;

        return $queries;
    }
}
