<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use TropikalAI\Connect\Domain\OAuth\OAuthState;
use TropikalAI\Connect\Domain\OAuth\RedirectUri;
use TropikalAI\ConnectFilament\Filament\Resources\InstallationResource;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Services\ControlPlaneClient;
use TropikalAI\ConnectFilament\Services\OAuthClient;

class OAuthSetupController extends Controller
{
    public function connect(Request $request, OAuthClient $oauth): RedirectResponse
    {
        abort_unless($request->user(), 403);

        $installation = Installation::query()->firstOrCreate([], [
            'site_url' => $oauth->siteUrl(),
            'control_plane_url' => $oauth->controlPlaneUrl(),
            'allowed_resources' => [],
            'resource_permissions' => [],
        ]);

        return redirect()->away($oauth->beginAuthorization($installation));
    }

    public function callback(Request $request, OAuthClient $oauth, ControlPlaneClient $controlPlane): RedirectResponse
    {
        $state = trim((string) $request->query('state', ''));
        $code = trim((string) $request->query('code', ''));
        abort_if($state === '' || $code === '', 400, 'OAuth callback is missing state or code.');
        abort_unless((new RedirectUri($oauth->redirectUri()))->matches($request->url()), 400, 'OAuth callback URL does not match the configured redirect URI.');

        $installation = Installation::query()
            ->where('oauth_state_hash', OAuthState::hash($state))
            ->first();
        abort_if(! $installation, 400, 'OAuth state is invalid.');
        abort_if(! $installation->oauth_state_expires_at || ! OAuthState::valid($state, (string) $installation->oauth_state_hash, $installation->oauth_state_expires_at->toDateTimeImmutable()), 400, 'OAuth state has expired.');
        abort_if(blank($installation->oauth_code_verifier_encrypted), 400, 'OAuth verifier is missing.');

        $tokens = $oauth->completeAuthorization($installation, $code);
        $installation->forceFill([
            'status' => Installation::STATUS_PENDING_REGISTRATION,
            'oauth_refresh_token_encrypted' => $tokens->refreshToken,
            'oauth_state_hash' => null,
            'oauth_code_verifier_encrypted' => null,
            'oauth_state_expires_at' => null,
            'connected_at' => null,
            'last_synced_at' => now(),
        ])->save();

        try {
            $controlPlane->registerInstallation($installation, $tokens);
        } catch (\Throwable $exception) {
            $installation->forceFill([
                'status' => Installation::STATUS_ERROR,
                'connected_at' => null,
                'server_signing_key_encrypted' => null,
            ])->save();

            throw $exception;
        }

        return redirect($this->afterConnectUrl());
    }

    private function afterConnectUrl(): string
    {
        $configured = trim((string) config('connect-filament.setup.after_connect_url', ''));
        if ($configured !== '') {
            return $configured;
        }

        return InstallationResource::getUrl('index');
    }
}
