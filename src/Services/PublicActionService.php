<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Orchestrates the first-party verification boundary for public actions.
 *
 * The supplied forwarder is the connector's signed, origin-bound transport.
 * Keeping the sequencing here prevents controllers from accidentally
 * forwarding a browser proof or trusting a browser-authored verification flag.
 */
final class PublicActionService
{
    private const CHALLENGE_SCHEMA = 'connect.public.human_verification_challenge';

    private const CONTRACT_VERSION = '1.0';

    private const CHALLENGE_PATH = '/api/connect-filament/public/human-verification/challenge';

    private const VERIFY_PATH = '/api/connect-filament/public/human-verification';

    private const ACTION_PATH = '/api/connect-filament/embed/actions';

    /**
     * @param  array<string, mixed>  $body
     * @param  callable(string, string): (Response|JsonResponse)  $forward
     */
    public function challenge(array $body, callable $forward): Response|JsonResponse
    {
        $response = $forward(self::CHALLENGE_PATH, $this->json([
            'session_id' => $this->string($body, 'session_id', 128),
            'action_id' => $this->string($body, 'action_id', 36),
        ]));

        if ($response->getStatusCode() >= 500) {
            return $this->verificationUnavailable();
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return $response;
        }

        $payload = json_decode((string) $response->getContent(), true);
        $challenge = is_array($payload)
            && ($payload['_tropikal_connect'] ?? null) === true
            && ($payload['schema'] ?? null) === self::CHALLENGE_SCHEMA
            && ($payload['contract_version'] ?? null) === self::CONTRACT_VERSION
            && is_array($payload['data']['challenge'] ?? null)
                ? $payload['data']['challenge']
                : null;
        if ($challenge === null) {
            return $this->verificationUnavailable();
        }

        return response()->json(['challenge' => $challenge])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  callable(string, string): (Response|JsonResponse)  $forward
     */
    public function confirm(
        string $actionId,
        array $body,
        callable $forward,
    ): Response|JsonResponse {
        $proof = $this->string($body, 'proof_of_work_token', 4096);
        $sessionId = $this->string($body, 'session_id', 128);
        if ($proof === '' || $sessionId === '') {
            return $this->verificationFailed();
        }

        $verification = $forward(self::VERIFY_PATH, $this->json([
            'token' => $proof,
            'session_id' => $sessionId,
            'action_id' => $actionId,
        ]));
        if (! $this->verified($verification)) {
            return in_array($verification->getStatusCode(), [400, 422], true)
                ? $this->verificationFailed()
                : $this->verificationUnavailable();
        }

        return $forward(
            self::ACTION_PATH.'/'.rawurlencode($actionId).'/confirm',
            $this->decisionBody($body),
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  callable(string, string): (Response|JsonResponse)  $forward
     */
    public function cancel(string $actionId, array $body, callable $forward): Response|JsonResponse
    {
        return $forward(
            self::ACTION_PATH.'/'.rawurlencode($actionId).'/cancel',
            $this->decisionBody($body),
        );
    }

    private function verified(Response|JsonResponse $response): bool
    {
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return false;
        }
        $payload = json_decode((string) $response->getContent(), true);
        if (! is_array($payload)) {
            return false;
        }
        if (($payload['_tropikal_connect'] ?? null) === true && is_array($payload['data'] ?? null)) {
            $payload = $payload['data'];
        }

        return ($payload['verified'] ?? false) === true;
    }

    /** @param array<string, mixed> $body */
    private function decisionBody(array $body): string
    {
        return $this->json([
            'decision_capability' => $this->string($body, 'decision_capability', 256),
            'resume_token' => $this->string($body, 'resume_token', 4096),
            'session_id' => $this->string($body, 'session_id', 128),
        ]);
    }

    /** @param array<string, mixed> $body */
    private function string(array $body, string $key, int $maximum): string
    {
        $value = $body[$key] ?? '';
        if (! is_string($value)) {
            return '';
        }

        $value = trim($value);

        return mb_strlen($value) <= $maximum ? $value : '';
    }

    /** @param array<string, string> $payload */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function verificationFailed(): JsonResponse
    {
        return response()->json([
            'error' => 'verification_failed',
            'message' => 'Please complete human verification again.',
        ], 422)->header('Cache-Control', 'no-store');
    }

    private function verificationUnavailable(): JsonResponse
    {
        return response()->json([
            'error' => 'verification_unavailable',
            'message' => 'Human verification is temporarily unavailable. Please retry.',
        ], 503)->header('Cache-Control', 'no-store');
    }
}
