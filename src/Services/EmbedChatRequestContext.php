<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use Illuminate\Http\Request;
use TropikalAI\Connect\Domain\Security\SignedRequestContext;
use TropikalAI\ConnectFilament\Contracts\PublicChatActorResolver;
use TropikalAI\ConnectFilament\Models\Installation;

final readonly class EmbedChatRequestContext
{
    public function __construct(
        private PublicChatActorResolver $actors,
        private PublicChatActorPermit $permits,
    ) {}

    /** @return array<string, string> */
    public function headers(Request $request, Installation $installation, string $body, string $requestSignature): array
    {
        $secret = (string) $installation->server_signing_key_encrypted;
        $payload = json_decode($body, true);
        $settings = is_array($installation->settings) ? $installation->settings : [];
        $history = config('connect-filament.public_chat.history_actor_context') === true
            && ($settings['embed_history_protocol'] ?? null) === 'embed-history.v1'
            && $request->routeIs('connect-filament.embed.chat.bootstrap', 'connect-filament.embed.chat.history.*', 'connect-filament.embed.chat.session');
        $sessionId = ! $history && is_array($payload) && is_string($payload['session_id'] ?? null)
            ? trim($payload['session_id']) : '';
        $actorHeaders = [];
        $actorIdentity = '';
        if ($history || ($sessionId !== '' && preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $sessionId) === 1)) {
            $actor = $this->actors->resolve($request);
            if ($actor !== null) {
                $actorHeaders = $history ? [] : [
                    PublicChatActorPermit::HEADER => $this->permits->issue($actor, $installation, $sessionId),
                    PublicChatActorPermit::SESSION_HEADER => $sessionId,
                ];
                // Stable across permit rotation; scoped to this installation and
                // signed server-side without exposing a member's raw identifier.
                $actorIdentity = hash_hmac('sha256', json_encode([
                    'embed-actor.v1', (string) $installation->public_id, $actor->type, $actor->id,
                ], JSON_THROW_ON_ERROR), trim($secret));
            }
        }

        $cookieName = $request->isSecure()
            ? (string) config('connect-filament.embed.history_cookie', '__Host-tropikal-chat-history')
            : (string) config('connect-filament.embed.history_cookie_local', 'tropikal-chat-history');
        $historyToken = $request->cookie($cookieName);
        $context = json_encode([
            'v' => 1,
            'kind' => $history ? 'embed-history' : 'embed-chat',
            'visitor_history_token' => is_string($historyToken) && preg_match('/\A[a-f0-9]{64}\z/', $historyToken) === 1
                ? $historyToken : '',
            'actor_identity' => $actorIdentity,
            'actor_context_sha256' => hash('sha256', $actorHeaders[PublicChatActorPermit::HEADER] ?? ''),
            'session_id' => $sessionId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [...$actorHeaders, ...SignedRequestContext::headers($secret, $requestSignature, $context)];
    }
}
