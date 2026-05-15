<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Http\Controllers;

use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use TropikalAI\Connect\Domain\Security\SensitiveData;
use TropikalAI\Connect\Domain\Security\SignedRequest;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Services\ControlPlaneClient;

class EmbedController extends Controller
{
    private const ASSETS = [
        'chat-widget.js' => 'application/javascript; charset=utf-8',
        'iframe.html' => 'text/html; charset=utf-8',
        'iframe.js' => 'application/javascript; charset=utf-8',
        'iframe.css' => 'text/css; charset=utf-8',
        'markdown.js' => 'application/javascript; charset=utf-8',
    ];

    public function widget(): Response
    {
        $prefix = trim((string) config('connect-filament.embed.prefix', 'tropikal-connect'), '/');
        $script = "(() => { fetch('/{$prefix}/embed/info', { credentials: 'same-origin' }); })();";

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age='.(int) config('connect-filament.embed.asset_cache_seconds', 300),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function asset(string $asset): Response
    {
        if (! array_key_exists($asset, self::ASSETS)) {
            abort(404);
        }

        $response = Http::timeout($this->timeoutSeconds())
            ->accept(self::ASSETS[$asset])
            ->get($this->controlPlaneUrl().$this->assetPath($asset));

        if (! $response->successful()) {
            return response('Connect embed asset unavailable.', 502, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response($this->rewriteAssetPrefixes($response->body()), 200, [
            'Content-Type' => self::ASSETS[$asset],
            'Cache-Control' => 'public, max-age='.(int) config('connect-filament.embed.asset_cache_seconds', 300),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function info(Request $request): JsonResponse
    {
        $installation = Installation::query()->first();
        if (! $installation) {
            return response()->json(['status' => Installation::STATUS_NOT_CONNECTED]);
        }

        return response()->json($installation->safeStatus())
            ->header('Cache-Control', 'no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function chatInfo(Request $request, ControlPlaneClient $controlPlane): Response|JsonResponse
    {
        return $this->proxy($request, $controlPlane, 'GET', 'info', '');
    }

    public function chat(Request $request, ControlPlaneClient $controlPlane): Response|JsonResponse
    {
        return $this->proxy($request, $controlPlane, 'POST', 'chat', $request->getContent() ?: '');
    }

    private function proxy(Request $request, ControlPlaneClient $controlPlane, string $method, string $action, string $body): Response|JsonResponse
    {
        $installation = $this->activeEmbedInstallation();
        if (! $installation) {
            return $this->chatUnavailableResponse();
        }

        $path = rtrim((string) config('connect-filament.control_plane.embed_proxy_path', '/api/connect-filament/embed'), '/').'/'.$action;
        $query = $request->getQueryString() ?? '';
        $response = $this->proxyRequest($request, $installation, $method, $path, $query, $body);

        if ($this->shouldRepairRegistration($response)) {
            $installation = $this->repairRegistration($installation, $controlPlane);
            if ($installation?->isApiReady()) {
                $response = $this->proxyRequest($request, $installation, $method, $path, $query, $body);
            }
        }

        return $this->proxyResponse($response->body(), $response->status(), $response->header('Content-Type'));
    }

    private function proxyRequest(Request $request, Installation $installation, string $method, string $path, string $query, string $body): ClientResponse
    {
        $headers = SignedRequest::headers(
            (string) $installation->server_signing_key_encrypted,
            (string) $installation->public_id,
            $method,
            $path,
            $query,
            $body,
        );

        $client = Http::timeout($this->timeoutSeconds())
            ->acceptJson()
            ->withHeaders([
                ...$headers,
                'X-Embed-Origin' => $this->visitorOrigin($request),
            ]);

        $url = $this->controlPlaneUrl().$path.($query !== '' ? '?'.$query : '');

        return $method === 'POST'
            ? $client->withBody($body, $request->header('Content-Type', 'application/json'))->post($url)
            : $client->get($url);
    }

    private function shouldRepairRegistration(ClientResponse $response): bool
    {
        return in_array($response->status(), [401, 403], true);
    }

    private function repairRegistration(Installation $installation, ControlPlaneClient $controlPlane): ?Installation
    {
        try {
            $controlPlane->syncCapabilities($installation);

            $installation->refresh();

            return $installation;
        } catch (\Throwable $exception) {
            Log::warning('Connect embed registration repair failed.', [
                'installation_id' => $installation->public_id,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    private function proxyResponse(string $body, int $status, ?string $contentType): Response|JsonResponse
    {
        if (in_array($status, [401, 403], true)) {
            return $this->chatUnavailableResponse();
        }

        $contentType = $contentType ?: 'application/json';
        if (str_contains(strtolower($contentType), 'json')) {
            $payload = json_decode($body, true);
            if (is_array($payload)) {
                SensitiveData::assertPublicPayload($payload);
            }
        }

        return response($body, $status, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function chatUnavailableResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'chat_not_enabled',
            'message' => 'Website chat is not enabled for this site.',
        ], 503)->header('Cache-Control', 'no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    private function activeEmbedInstallation(): ?Installation
    {
        /** @var Installation|null $installation */
        $installation = Installation::query()
            ->where('status', Installation::STATUS_CONNECTED)
            ->orderByDesc('updated_at')
            ->first();

        return $installation?->isApiReady() ? $installation : null;
    }

    private function assetPath(string $asset): string
    {
        return rtrim((string) config('connect-filament.control_plane.embed_asset_path', '/embed'), '/').'/'.$asset;
    }

    private function controlPlaneUrl(): string
    {
        return rtrim((string) config('connect-filament.control_plane.base_url'), '/');
    }

    private function rewriteAssetPrefixes(string $body): string
    {
        $prefix = '/'.trim((string) config('connect-filament.embed.prefix', 'tropikal-connect'), '/');
        $legacyPrefixes = config('connect-filament.embed.asset_rewrite_prefixes', []);
        if (! is_array($legacyPrefixes)) {
            return $body;
        }

        foreach ($legacyPrefixes as $legacyPrefix) {
            $legacyPrefix = '/'.trim((string) $legacyPrefix, '/');
            if ($legacyPrefix !== '/') {
                $body = str_replace($legacyPrefix, $prefix, $body);
            }
        }

        return $body;
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('connect-filament.control_plane.timeout_seconds', 20));
    }

    private function visitorOrigin(Request $request): string
    {
        $declared = trim((string) $request->headers->get('X-Embed-Origin', ''));
        if ($declared !== '') {
            return $declared;
        }

        $origin = trim((string) $request->headers->get('Origin', ''));
        if ($origin !== '') {
            return $origin;
        }

        $referer = trim((string) $request->headers->get('Referer', ''));
        if ($referer !== '') {
            $parts = parse_url($referer);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            }
        }

        return $request->getSchemeAndHttpHost();
    }
}
