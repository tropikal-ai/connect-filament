<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TropikalAI\Connect\Domain\PublicChannels\PublicComponentPlacement;
use TropikalAI\Connect\Domain\PublicChannels\PublicComponentType;
use TropikalAI\ConnectFilament\Http\Middleware\InjectPublicComponents;
use TropikalAI\ConnectFilament\Services\LaravelPublicComponentSettingsStore;

final class PublicComponentsTest extends TestCase
{
    public function test_missing_setting_defaults_enabled_and_explicit_false_wins(): void
    {
        $this->connectedInstallation();
        $store = new LaravelPublicComponentSettingsStore;

        $this->assertTrue($store->get(PublicComponentType::Chat)->autoInject);
        $store->save(new PublicComponentPlacement(PublicComponentType::Chat, false));
        $this->assertFalse($store->get(PublicComponentType::Chat)->autoInject);
    }

    public function test_local_off_returns_not_enabled_without_an_upstream_request(): void
    {
        $this->connectedInstallation();
        (new LaravelPublicComponentSettingsStore)->save(
            new PublicComponentPlacement(PublicComponentType::Chat, false),
        );
        Http::fake();

        $this->getJson('/tropikal-connect/api/chat/info')
            ->assertNotFound()
            ->assertJsonPath('error', 'chat_not_enabled');
        Http::assertNothingSent();
    }

    public function test_html_middleware_injects_once_and_skips_ineligible_responses(): void
    {
        $this->connectedInstallation();
        $middleware = new InjectPublicComponents;
        $request = Request::create('/public-page', 'GET');
        $response = $middleware->handle($request, static fn (): Response => response(
            '<!doctype html><html><body><main>Page</main></body></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        ));
        $this->assertSame(1, substr_count((string) $response->getContent(), InjectPublicComponents::MARKER));

        $again = $middleware->handle($request, static fn () => $response);
        $this->assertSame(1, substr_count((string) $again->getContent(), InjectPublicComponents::MARKER));

        foreach ([
            [Request::create('/api/example', 'GET'), response('{}', 200, ['Content-Type' => 'application/json'])],
            [Request::create('/admin', 'GET'), response('<html><body>Admin</body></html>', 200, ['Content-Type' => 'text/html'])],
            [Request::create('/download', 'GET'), new StreamedResponse(static fn (): null => null)],
        ] as [$ineligibleRequest, $ineligibleResponse]) {
            $result = $middleware->handle($ineligibleRequest, static fn () => $ineligibleResponse);
            $this->assertStringNotContainsString(
                InjectPublicComponents::MARKER,
                (string) $result->getContent(),
            );
        }
    }

    public function test_static_injector_is_idempotent_and_fails_without_body(): void
    {
        $dir = sys_get_temp_dir().'/connect-filament-static-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $entry = $dir.'/index.html';
        file_put_contents($entry, '<!doctype html><html><body><main>Static</main></body></html>');

        $this->artisan('tropikal-connect:inject-public-components', ['--path' => [$entry]])
            ->assertSuccessful();
        $first = (string) file_get_contents($entry);
        $this->artisan('tropikal-connect:inject-public-components', ['--path' => [$entry]])
            ->assertSuccessful();
        $this->assertSame($first, file_get_contents($entry));
        $this->assertSame(1, substr_count($first, InjectPublicComponents::MARKER));

        $broken = $dir.'/broken.html';
        file_put_contents($broken, '<html>broken</html>');
        $this->artisan('tropikal-connect:inject-public-components', ['--path' => [$broken]])
            ->assertFailed();

        @unlink($entry);
        @unlink($broken);
        @rmdir($dir);
    }
}
