<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as BaseTestCase;
use TropikalAI\Connect\Domain\Security\SignedRequest;
use TropikalAI\ConnectFilament\ConnectFilamentServiceProvider;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Services\EloquentDiscovery;
use TropikalAI\ConnectFilament\Services\ResourceRegistry;
use TropikalAI\ConnectFilament\Tests\Fixtures\Article;
use TropikalAI\ConnectFilament\Tests\Fixtures\Post;
use TropikalAI\ConnectFilament\Tests\Fixtures\User;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('https://cms.example.com');
        URL::forceScheme('https');
        Authenticate::redirectUsing(fn (): string => '/login');

        $this->artisan('migrate', ['--database' => 'testing'])->run();
        $this->createFixtureTables();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ConnectFilamentServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:nF1hRQ7s8TtsUvCcllFH9MjJL99qeqdq5BfL40EprR0=');
        $app['config']->set('app.url', 'https://cms.example.com');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('auth.defaults.guard', 'web');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('connect-filament.oauth.authorization_server_url', 'https://auth.example.com');
        $app['config']->set('connect-filament.control_plane.base_url', 'https://control.example.com');
        $app['config']->set('connect-filament.setup.connect_middleware', ['auth']);
        $app['config']->set('connect-filament.setup.after_connect_url', '/admin/tropikal-connect');
        $app['config']->set('connect-filament.discovery.included_model_namespaces', [
            'TropikalAI\\ConnectFilament\\Tests\\Fixtures\\',
        ]);
        $app['config']->set('connect-filament.discovery.model_classes', [
            Article::class,
            Post::class,
            User::class,
        ]);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/login', fn (): string => 'login')->name('login');
    }

    protected function createUser(): User
    {
        return User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    protected function configurePostResource(): void
    {
        config()->set('connect-filament.resources', [
            'posts' => [
                'label' => 'Posts',
                'model' => Post::class,
                'identifier' => 'id',
                'sort_column' => 'id',
                'fields' => [
                    'title' => ['type' => 'string', 'required' => true],
                    'body' => ['type' => 'text'],
                    'published_at' => ['type' => 'datetime', 'writable' => false],
                ],
                'searchable' => ['title'],
                'filterable' => ['published_at'],
                'actions' => [
                    'publish' => [
                        'label' => 'Publish',
                        'method' => 'publish',
                    ],
                ],
            ],
        ]);

        $this->app->forgetInstance(ResourceRegistry::class);
        $this->app->singleton(ResourceRegistry::class, fn (): ResourceRegistry => new ResourceRegistry(
            config('connect-filament.resources', []),
            $this->app->make(EloquentDiscovery::class),
        ));
    }

    protected function connectedInstallation(array $attributes = []): Installation
    {
        return Installation::query()->create(array_merge([
            'status' => Installation::STATUS_CONNECTED,
            'site_url' => 'https://cms.example.com',
            'control_plane_url' => 'https://control.example.com',
            'oauth_client_id' => 'client_123',
            'oauth_refresh_token_encrypted' => 'refresh_123',
            'server_signing_key_encrypted' => 'server-signing-key',
            'allowed_resources' => [],
            'resource_permissions' => [],
            'embed_status' => Installation::EMBED_NOT_ENABLED,
        ], $attributes));
    }

    protected function sign(Installation $installation, string $method, string $path, array|string|null $query = null, string $body = '', string $nonce = 'nonce_1', ?int $timestamp = null): array
    {
        return SignedRequest::headers(
            (string) $installation->server_signing_key_encrypted,
            (string) $installation->public_id,
            $method,
            $path,
            $query,
            $body,
            $timestamp,
            $nonce,
        );
    }

    protected function signedJson(Installation $installation, string $method, string $path, array $payload, string $nonce): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->withHeaders($this->sign($installation, $method, $path, null, $body, $nonce))
            ->json($method, $path, $payload);
    }

    protected function signedGet(Installation $installation, string $path, ?string $query, string $nonce): TestResponse
    {
        return $this->withHeaders([
            ...$this->sign($installation, 'GET', $path, $query, '', $nonce),
            'Accept' => 'application/json',
        ])->get($query ? $path.'?'.$query : $path);
    }

    private function createFixtureTables(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('test_posts')) {
            Schema::create('test_posts', function (Blueprint $table): void {
                $table->id();
                $table->string('title');
                $table->text('body')->nullable();
                $table->string('secret_note')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('test_articles')) {
            Schema::create('test_articles', function (Blueprint $table): void {
                $table->id();
                $table->string('title');
                $table->string('slug')->unique();
                $table->text('content');
                $table->string('category')->default('Research');
                $table->timestamps();
            });
        }
    }
}
