<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TropikalAI\Connect\Infrastructure\PublicChannels\PublicAssets;
use TropikalAI\ConnectFilament\Models\Installation;

final class InjectPublicComponents
{
    public const MARKER = 'data-tropikal-public-components';

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $response = $next($request);
        if (! $this->eligible($request, $response)) {
            return $response;
        }
        $content = $response->getContent();
        if (! is_string($content) || str_contains($content, self::MARKER)) {
            return $response;
        }
        $position = strripos($content, '</body>');
        if ($position === false) {
            return $response;
        }
        $script = sprintf(
            '<script defer %s src="/tropikal-connect/assets/public-channels.js?v=%s"></script>',
            self::MARKER,
            PublicAssets::version(),
        );
        $response->setContent(substr($content, 0, $position).$script.substr($content, $position));
        $response->headers->remove('Content-Length');

        return $response;
    }

    private function eligible(Request $request, SymfonyResponse $response): bool
    {
        if (! (bool) config('connect-filament.public_components.middleware.enabled', true)
            || ! $request->isMethod('GET')
            || ! $response instanceof Response
            || $response instanceof BinaryFileResponse
            || $response instanceof StreamedResponse
            || ! $response->isSuccessful()
            || $response->headers->has('Content-Encoding')
            || ! str_contains(strtolower((string) $response->headers->get('Content-Type', '')), 'text/html')) {
            return false;
        }
        $path = trim($request->path(), '/');
        $includes = config('connect-filament.public_components.middleware.include_paths', ['*']);
        $excludes = config('connect-filament.public_components.middleware.exclude_paths', [
            'api/*', 'admin*', 'tropikal-connect/*', 'downloads/*',
        ]);
        if (! $this->matches($path, is_array($includes) ? $includes : ['*'])
            || $this->matches($path, is_array($excludes) ? $excludes : [])) {
            return false;
        }

        return Installation::query()->where('status', Installation::STATUS_CONNECTED)->exists();
    }

    /** @param array<int, mixed> $patterns */
    private function matches(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (is_string($pattern) && Str::is(trim($pattern, '/'), $path)) {
                return true;
            }
        }

        return false;
    }
}
