<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use TropikalAI\ConnectFilament\Domain\PublicChatActor;
use TropikalAI\ConnectFilament\Models\Installation;

final class PublicChatActorPermit
{
    public const HEADER = 'X-Tropikal-Actor-Context';

    public const INITIAL_HEADER = 'X-Tropikal-Initial-Actor-Context';

    public const SESSION_HEADER = 'X-Tropikal-Chat-Session';

    public function issue(PublicChatActor $actor, Installation $installation, string $sessionId): string
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '' || strlen($sessionId) > 128) {
            throw new \InvalidArgumentException('Public chat session is invalid.');
        }

        return Crypt::encryptString(json_encode([
            'v' => 1,
            'installation_id' => (string) $installation->public_id,
            'session_id' => $sessionId,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'expires_at' => now()->addSeconds($this->ttl())->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    public function redeem(?string $permit, Installation $installation, string $sessionId): ?PublicChatActor
    {
        if (! is_string($permit) || $permit === '' || $sessionId === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($permit), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }
        if (
            ! is_array($payload)
            || ($payload['v'] ?? null) !== 1
            || ! hash_equals((string) $installation->public_id, (string) ($payload['installation_id'] ?? ''))
            || ! hash_equals($sessionId, (string) ($payload['session_id'] ?? ''))
            || (int) ($payload['expires_at'] ?? 0) < now()->getTimestamp()
        ) {
            return null;
        }

        try {
            return new PublicChatActor(
                (string) ($payload['actor_type'] ?? ''),
                (string) ($payload['actor_id'] ?? ''),
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function ttl(): int
    {
        return max(60, min(3600, (int) config('connect-filament.public_chat.actor_permit_ttl_seconds', 900)));
    }
}
