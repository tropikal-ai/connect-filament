<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

/** Solves the authorization server's short-lived dynamic-registration proof. */
final class RegistrationProof
{
    private const MAX_ATTEMPTS = 2_000_000;

    /**
     * @param  array<string, mixed>  $challenge
     * @return array{pow_challenge_id: string, pow_nonce: string}
     */
    public static function solve(array $challenge): array
    {
        $challengeId = trim((string) ($challenge['challenge_id'] ?? ''));
        $prefix = trim((string) ($challenge['prefix'] ?? ''));
        if ($challengeId === '' || strlen($challengeId) > 128) {
            throw new \RuntimeException('The authorization server returned an invalid registration challenge.');
        }
        if ($prefix === '' || strlen($prefix) > 8 || preg_match('/^[0-9a-f]+$/', $prefix) !== 1) {
            throw new \RuntimeException('The authorization server returned an invalid registration challenge.');
        }

        for ($nonce = 0; $nonce < self::MAX_ATTEMPTS; $nonce++) {
            $candidate = (string) $nonce;
            $digest = hash('sha256', $challengeId.':'.$candidate);
            if (hash_equals($prefix, substr($digest, 0, strlen($prefix)))) {
                return [
                    'pow_challenge_id' => $challengeId,
                    'pow_nonce' => $candidate,
                ];
            }
        }

        throw new \RuntimeException('Unable to solve the authorization server registration challenge.');
    }
}
