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
use Symfony\Component\HttpFoundation\Cookie;
use TropikalAI\Connect\Domain\Security\SensitiveData;
use TropikalAI\Connect\Domain\Security\SignedRequest;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Services\ControlPlaneClient;
use TropikalAI\ConnectFilament\Services\PublicActionService;
use TropikalAI\ConnectFilament\Services\UrlPolicy;

class EmbedController extends Controller
{
    private const STABLE_ASSETS = [
        'chat-widget.js' => 'application/javascript; charset=utf-8',
        'iframe.html' => 'text/html; charset=utf-8',
    ];

    private const HASHED_ASSET_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9_-]*-[A-Za-z0-9_-]{8,}\.(?:js|css)\z/';

    private const HISTORY_COOKIE_PATTERN = '/\A[a-f0-9]{64}\z/';

    public function widget(Request $request): Response
    {
        return $this->asset($request, 'chat-widget.js');
    }

    public function asset(Request $request, string $asset): Response
    {
        if (! array_key_exists($asset, self::STABLE_ASSETS)) {
            abort(404);
        }

        return $this->proxyAsset($request, $asset, self::STABLE_ASSETS[$asset], false);
    }

    public function hashedAsset(Request $request, string $asset): Response
    {
        if (preg_match(self::HASHED_ASSET_PATTERN, $asset) !== 1) {
            abort(404);
        }

        $contentType = str_ends_with($asset, '.css')
            ? 'text/css; charset=utf-8'
            : 'application/javascript; charset=utf-8';

        return $this->proxyAsset($request, 'assets/'.$asset, $contentType, true);
    }

    private function proxyAsset(Request $request, string $asset, string $contentType, bool $immutable): Response
    {
        try {
            $client = Http::timeout($this->timeoutSeconds())
                ->accept($contentType)
                ->withHeaders(array_filter([
                    'If-None-Match' => $request->header('If-None-Match'),
                    'If-Modified-Since' => $request->header('If-Modified-Since'),
                ]));
            $response = $client->get($this->assetUrl($asset));
        } catch (\Throwable) {
            return $this->assetUnavailableResponse();
        }

        if (! $response->successful() && $response->status() !== 304) {
            return $this->assetUnavailableResponse();
        }

        $headers = [
            'Content-Type' => $contentType,
            'Cache-Control' => $this->safeAssetCacheControl($response, $immutable),
            'X-Content-Type-Options' => 'nosniff',
        ];
        foreach (['ETag', 'Last-Modified', 'Content-Security-Policy'] as $header) {
            if (is_string($response->header($header)) && $response->header($header) !== '') {
                $headers[$header] = $response->header($header);
            }
        }

        $body = $response->status() === 304 ? '' : $this->rewriteAssetUrls($asset, $response->body());

        return response($body, $response->status(), $headers);
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

    public function chatSession(Request $request, ControlPlaneClient $controlPlane): Response|JsonResponse
    {
        return $this->proxy($request, $controlPlane, 'GET', 'session', '');
    }

    public function history(Request $request, ControlPlaneClient $controlPlane): Response|JsonResponse
    {
        return $this->historyProxy($request, $controlPlane, 'list');
    }

    public function historyRead(Request $request, ControlPlaneClient $controlPlane, string $conversation): Response|JsonResponse
    {
        return $this->historyProxy($request, $controlPlane, 'read', $conversation);
    }

    public function historyDelete(Request $request, ControlPlaneClient $controlPlane, string $conversation): Response|JsonResponse
    {
        if ($response = $this->assertHistoryMutation($request)) {
            return $response;
        }

        return $this->historyProxy($request, $controlPlane, 'delete', $conversation);
    }

    public function historyClear(Request $request, ControlPlaneClient $controlPlane): Response|JsonResponse
    {
        if ($response = $this->assertHistoryMutation($request)) {
            return $response;
        }

        return $this->historyProxy($request, $controlPlane, 'clear');
    }

    public function humanVerificationChallenge(
        Request $request,
        ControlPlaneClient $controlPlane,
        PublicActionService $actions,
    ): Response|JsonResponse {
        return $actions->challenge(
            $request->json()->all(),
            fn (string $path, string $body): Response|JsonResponse => $this->proxyPath(
                $request,
                $controlPlane,
                'POST',
                $path,
                $body,
            ),
        );
    }

    public function actionConfirm(
        Request $request,
        ControlPlaneClient $controlPlane,
        PublicActionService $actions,
        string $action,
    ): Response|JsonResponse {
        return $actions->confirm(
            $action,
            $request->json()->all(),
            (string) $request->ip(),
            fn (string $path, string $body): Response|JsonResponse => $this->proxyPath(
                $request,
                $controlPlane,
                'POST',
                $path,
                $body,
            ),
        );
    }

    public function actionCancel(
        Request $request,
        ControlPlaneClient $controlPlane,
        PublicActionService $actions,
        string $action,
    ): Response|JsonResponse {
        return $actions->cancel(
            $action,
            $request->json()->all(),
            fn (string $path, string $body): Response|JsonResponse => $this->proxyPath(
                $request,
                $controlPlane,
                'POST',
                $path,
                $body,
            ),
        );
    }

    private function historyProxy(Request $request, ControlPlaneClient $controlPlane, string $action, string $conversation = ''): Response|JsonResponse
    {
        [$token, $cookieName] = $this->historyToken($request);
        $payload = ['visitor_history_token' => $token];
        if ($conversation !== '') {
            $payload['conversation_ref'] = $conversation;
        }
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $response = $this->proxy($request, $controlPlane, 'POST', 'history/'.$action, $body);

        return $response->withCookie(new Cookie(
            $cookieName,
            $token,
            now()->addDays(30),
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX,
        ));
    }

    private function proxy(Request $request, ControlPlaneClient $controlPlane, string $method, string $action, string $body): Response|JsonResponse
    {
        $path = rtrim((string) config('connect-filament.control_plane.embed_proxy_path', '/api/connect-filament/embed'), '/').'/'.$action;

        return $this->proxyPath($request, $controlPlane, $method, $path, $body);
    }

    private function proxyPath(Request $request, ControlPlaneClient $controlPlane, string $method, string $path, string $body): Response|JsonResponse
    {
        $installation = $this->activeEmbedInstallation();
        if (! $installation) {
            return $this->chatUnavailableResponse();
        }

        $query = $this->canonicalQuery($request);
        try {
            $response = $this->proxyRequest($request, $installation, $method, $path, $query, $body);
        } catch (\Throwable) {
            return $this->chatTemporaryUnavailableResponse();
        }

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
        $headers = SignedRequest::headersWithRequestOrigin(
            (string) $installation->server_signing_key_encrypted,
            (string) $installation->public_id,
            $method,
            $path,
            $this->visitorOrigin($request),
            $query,
            $body,
        );

        $client = Http::timeout($this->timeoutSeconds())
            ->acceptJson()
            ->withHeaders($headers);

        $url = $this->controlPlaneUrl().$path.($query !== '' ? '?'.$query : '');

        return $method === 'POST'
            ? $client->withBody($body, $request->header('Content-Type', 'application/json'))->post($url)
            : $client->get($url);
    }

    private function shouldRepairRegistration(ClientResponse $response): bool
    {
        if (in_array($response->status(), [401, 403], true)) {
            return true;
        }

        if ($response->status() !== 404) {
            return false;
        }

        $detail = $response->json('detail');

        return is_string($detail)
            && strcasecmp(trim($detail), 'Connect installation not found') === 0;
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
                // The embed protocol has a few exact public exceptions to the
                // generic secret-shaped-key rule. Every other path is still
                // checked before the response reaches the browser.
                $guardedPayload = $this->guardedPublicPayload($payload);
                try {
                    SensitiveData::assertPublicPayload($guardedPayload);
                } catch (\Throwable) {
                    return $this->chatTemporaryUnavailableResponse();
                }
            }
        }

        if ($status >= 500) {
            return $this->chatTemporaryUnavailableResponse();
        }

        return response($body, $status, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Remove only documented public-protocol exceptions from the generic
     * secret-shaped-key guard.
     *
     * Interactive review fields use `key` as a stable presentation identifier
     * (for example `service` or `time`). The Connect security primitive treats
     * every property literally named `key` as server-only, so running the raw
     * review through it would reject a valid pending action. Limit the
     * exception to that exact typed-review path; every field value and every
     * other key remains guarded.
     */
    private function guardedPublicPayload(array $payload): array
    {
        unset($payload['resume_token'], $payload['history_capability']);

        $review = $payload['pending_action']['review'] ?? null;
        $fields = is_array($review) ? ($review['fields'] ?? null) : null;
        if (($review['schema'] ?? null) !== 'interactive_action_review.v1' || ! is_array($fields)) {
            return $payload;
        }

        foreach (array_keys($fields) as $index) {
            $field = $payload['pending_action']['review']['fields'][$index] ?? null;
            $key = is_array($field) ? ($field['key'] ?? null) : null;
            if (
                ! is_string($key)
                || preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $key) !== 1
                || ! SensitiveData::isPublicKey($key)
            ) {
                return $payload;
            }
            unset($payload['pending_action']['review']['fields'][$index]['key']);
        }

        return $payload;
    }

    private function chatUnavailableResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'chat_not_enabled',
            'message' => 'Website chat is not enabled for this site.',
        ], 503)->header('Cache-Control', 'no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    private function chatTemporaryUnavailableResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'chat_unavailable',
            'message' => 'Website chat is temporarily unavailable.',
        ], 502)->header('Cache-Control', 'no-store')
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

    private function assetUrl(string $asset): string
    {
        return $this->controlPlaneUrl().$this->assetPath($asset);
    }

    private function controlPlaneUrl(): string
    {
        return UrlPolicy::trustedBaseUrl((string) config('connect-filament.control_plane.base_url'), 'The control plane URL');
    }

    private function rewriteAssetUrls(string $asset, string $body): string
    {
        if ($asset === 'iframe.html') {
            $body = str_replace('./assets/', $this->assetUrl('assets/'), $body);
        }

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

    /** @return array{string, string} */
    private function historyToken(Request $request): array
    {
        $cookieName = $request->isSecure()
            ? (string) config('connect-filament.embed.history_cookie', '__Host-tropikal-chat-history')
            : (string) config('connect-filament.embed.history_cookie_local', 'tropikal-chat-history');
        $token = $request->cookie($cookieName);
        if (! is_string($token) || preg_match(self::HISTORY_COOKIE_PATTERN, $token) !== 1) {
            $token = bin2hex(random_bytes(32));
        }

        return [$token, $cookieName];
    }

    private function assertHistoryMutation(Request $request): ?JsonResponse
    {
        if ($request->header('X-Tropikal-History-Intent') !== '1') {
            return response()->json(['error' => 'history_intent_required'], 428)
                ->header('Cache-Control', 'no-store');
        }

        $origin = UrlPolicy::originOrNull(trim((string) $request->header('Origin', '')));
        if ($origin === null || $origin !== $request->getSchemeAndHttpHost()) {
            return response()->json(['error' => 'same_origin_required'], 403)
                ->header('Cache-Control', 'no-store');
        }

        return null;
    }

    private function canonicalQuery(Request $request): string
    {
        $query = $request->query();
        ksort($query);

        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function safeAssetCacheControl(ClientResponse $response, bool $immutable): string
    {
        $value = strtolower(trim((string) $response->header('Cache-Control')));
        if ($immutable && str_contains($value, 'immutable') && str_contains($value, 'max-age=31536000')) {
            return (string) $response->header('Cache-Control');
        }
        if (! $immutable && str_contains($value, 'no-cache') && str_contains($value, 'must-revalidate')) {
            return (string) $response->header('Cache-Control');
        }

        return $immutable
            ? 'public, max-age=31536000, immutable'
            : 'no-cache, max-age=0, must-revalidate';
    }

    private function assetUnavailableResponse(): Response
    {
        return response('Connect embed asset unavailable.', 502, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function visitorOrigin(Request $request): string
    {
        $origin = trim((string) $request->headers->get('Origin', ''));
        if ($trusted = UrlPolicy::originOrNull($origin)) {
            return $trusted;
        }

        $referer = trim((string) $request->headers->get('Referer', ''));
        if ($trusted = UrlPolicy::originOrNull($referer)) {
            return $trusted;
        }

        $declared = trim((string) $request->headers->get('X-Embed-Origin', ''));
        if ($trusted = UrlPolicy::originOrNull($declared)) {
            return $trusted;
        }

        return $request->getSchemeAndHttpHost();
    }
}
