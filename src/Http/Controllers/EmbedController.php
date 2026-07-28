<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use TropikalAI\Connect\Application\PublicChannels\PublicChannelsService;
use TropikalAI\Connect\Application\PublicChannels\PublicResponse;
use TropikalAI\Connect\Infrastructure\PublicChannels\PublicAssets;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Services\ControlPlaneClient;

final class EmbedController extends Controller
{
    public function widget(): Response
    {
        return response(PublicAssets::contents('public-channels.js'), 200, [
            'Content-Type' => PublicAssets::contentType('public-channels.js'),
            'Cache-Control' => 'no-cache',
            'X-Tropikal-Deprecated' => 'Use /tropikal-connect/assets/public-channels.js',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function publicAsset(Request $request, string $asset): Response
    {
        if (! hash_equals(PublicAssets::version(), trim((string) $request->query('v', '')))) {
            abort(404);
        }
        try {
            return response(PublicAssets::contents($asset), 200, [
                'Content-Type' => PublicAssets::contentType($asset),
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (\Throwable) {
            abort(404);
        }
    }

    public function asset(Request $request, PublicChannelsService $channels, string $asset): Response
    {
        return $this->respond($channels->embedAsset($asset, trim((string) $request->query('v', ''))));
    }

    public function info(): JsonResponse
    {
        $installation = Installation::query()->first();

        return response()->json(
            $installation?->safeStatus() ?? ['status' => Installation::STATUS_NOT_CONNECTED],
        )->header('Cache-Control', 'no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function chatInfo(PublicChannelsService $channels, ControlPlaneClient $controlPlane): Response
    {
        $installation = $this->activeInstallation();
        if ($installation === null) {
            return $this->respond($this->chatNotEnabled());
        }
        $response = $channels->chatInfo();
        if (in_array($response->status, [401, 403], true)) {
            try {
                $controlPlane->syncCapabilities($installation);
                $response = $channels->chatInfo();
            } catch (\Throwable) {
                return $this->respond($this->chatUnavailable());
            }
        }

        return $this->respond(in_array($response->status, [401, 403], true) ? $this->chatUnavailable() : $response);
    }

    public function chat(Request $request, PublicChannelsService $channels): Response
    {
        if ($this->activeInstallation() === null) {
            return $this->respond($this->chatNotEnabled());
        }
        $body = $request->json()->all();
        if ($request->getContent() === '' || ! is_array($body) || array_is_list($body)) {
            return $this->respond(PublicResponse::json(400, ['error' => 'invalid_json']));
        }

        return $this->respond($channels->chat($body));
    }

    public function health(PublicChannelsService $channels): Response
    {
        $connected = Installation::query()->where('status', Installation::STATUS_CONNECTED)->exists();
        if (! $connected) {
            return $this->respond(PublicResponse::json(200, [
                'status' => 'ok',
                'installation' => 'disconnected',
                'chat' => 'not_enabled',
                'booking' => 'not_enabled',
                'asset_version' => PublicAssets::version(),
            ]));
        }

        return $this->respond($channels->health(PublicAssets::version()));
    }

    private function respond(PublicResponse $response): Response
    {
        return response($response->body, $response->status, [
            ...$response->headers,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function activeInstallation(): ?Installation
    {
        $installation = Installation::query()
            ->where('status', Installation::STATUS_CONNECTED)
            ->latest('updated_at')
            ->first();

        return $installation?->isApiReady() ? $installation : null;
    }

    private function chatNotEnabled(): PublicResponse
    {
        return PublicResponse::json(404, [
            'error' => 'chat_not_enabled',
            'message' => 'Website chat is not enabled for this site.',
        ]);
    }

    private function chatUnavailable(): PublicResponse
    {
        return PublicResponse::json(503, [
            'error' => 'chat_unavailable',
            'message' => 'Website chat is temporarily unavailable.',
        ]);
    }
}
