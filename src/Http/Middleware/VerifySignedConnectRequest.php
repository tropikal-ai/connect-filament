<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TropikalAI\Connect\Application\SignedRequestVerifier;
use TropikalAI\Connect\Domain\Security\SignedRequest;
use TropikalAI\Connect\Exceptions\ConnectException;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Services\CacheNonceStore;

class VerifySignedConnectRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $routeInstallationId = (string) $request->route('installationId');
        $headerInstallationId = (string) $request->header(SignedRequest::INSTALLATION_HEADER, '');
        if ($routeInstallationId === '' || $headerInstallationId !== $routeInstallationId) {
            return response()->json(['error' => 'Invalid connect installation'], 401);
        }

        $installation = Installation::query()
            ->where('public_id', $routeInstallationId)
            ->first();
        if (! $installation || ! $installation->isApiReady()) {
            return response()->json(['error' => 'Connect installation is not connected'], 403);
        }

        try {
            (new SignedRequestVerifier(
                new CacheNonceStore,
                max(1, (int) config('connect-filament.api.signature_tolerance_seconds', 300)),
            ))->verify(
                (string) $installation->server_signing_key_encrypted,
                $installation->public_id,
                $request->method(),
                '/'.ltrim($request->path(), '/'),
                $request->getQueryString() ?? '',
                $request->getContent() ?: '',
                $request->headers->all(),
            );
        } catch (ConnectException $exception) {
            return response()->json(['error' => $exception->getMessage()], 401);
        }

        $request->attributes->set('connect_filament_installation', $installation);
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
