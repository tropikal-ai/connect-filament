<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TropikalAI\Connect\Domain\Security\SensitiveData;
use TropikalAI\ConnectFilament\Domain\MutationIdentity;
use TropikalAI\ConnectFilament\Domain\PublicChatCapabilityException;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Services\IdempotentMutationExecutor;
use TropikalAI\ConnectFilament\Services\PublicChatActorPermit;
use TropikalAI\ConnectFilament\Services\PublicChatCapabilityRegistry;
use TropikalAI\ConnectFilament\Services\PublicChatInputValidator;
use TropikalAI\ConnectFilament\Services\PublicChatMemberAuthentication;

final class PublicChatCapabilityController
{
    public function __construct(
        private readonly PublicChatCapabilityRegistry $registry,
        private readonly PublicChatActorPermit $actors,
        private readonly IdempotentMutationExecutor $mutations,
        private readonly PublicChatInputValidator $inputValidator,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $kind = (string) $request->route('kind');
        $operation = (string) $request->route('operation');
        $installation = $request->attributes->get('connect_filament_installation');
        if (! $installation instanceof Installation) {
            return response()->json(['error' => 'connect_installation_required'], 403);
        }
        $capability = $this->registry->capability($kind);
        if ($capability === null || ! in_array($operation, ['query', 'execute', 'status'], true)) {
            return response()->json(['error' => 'capability_not_found'], 404);
        }
        if ($operation === 'execute' && ($capability['query_only'] ?? false)) {
            return response()->json(['error' => 'query_only_capability'], 405);
        }
        $receipts = ($capability['execution_receipts'] ?? null) === 'public-chat-receipt.v1';
        if ($operation === 'status' && ! $receipts) {
            return response()->json(['error' => 'capability_not_found'], 404);
        }
        if ($receipts && $operation !== 'query' && trim((string) $request->header(MutationIdentity::HEADER, '')) === '') {
            return response()->json(['error' => 'idempotency_key_required'], 428);
        }
        $payload = $request->json()->all();
        if (! is_array($payload) || array_keys($payload) !== ['input'] || ! is_array($payload['input'])) {
            return response()->json(['error' => 'invalid_capability_input'], 422);
        }
        $schema = $operation === 'query'
            ? ($capability['query_tool']['input_schema'] ?? [])
            : ($capability['execution_input_schema'] ?? []);
        if (! is_array($schema) || ! $this->inputValidator->accepts($payload['input'], $schema)) {
            return response()->json(['error' => 'invalid_capability_input'], 422);
        }
        $sessionId = trim((string) $request->header(PublicChatActorPermit::SESSION_HEADER, ''));
        if ($receipts && $operation !== 'query' && preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $sessionId) !== 1) {
            return response()->json(['error' => 'invalid_chat_session'], 422);
        }
        $actor = $this->actors->redeem(
            $request->header(PublicChatActorPermit::HEADER),
            $installation,
            $sessionId,
        );
        if ($capability['audience'] === 'member' && ($actor === null || $actor->type !== 'member')) {
            return app(PublicChatMemberAuthentication::class)->requiredResponse();
        }
        if (in_array($operation, ['execute', 'status'], true) && $capability['audience'] === 'member') {
            $initialActor = $this->actors->redeem(
                $request->header(PublicChatActorPermit::INITIAL_HEADER),
                $installation,
                $sessionId,
            );
            if (($operation === 'execute' && $initialActor === null)
                || ($initialActor !== null && (! hash_equals($initialActor->type, $actor->type)
                    || ! hash_equals($initialActor->id, $actor->id)))) {
                return response()->json(['error' => 'member_identity_changed'], 401);
            }
        }

        $authorityContext = $receipts ? json_encode([
            'installation' => $installation->public_id, 'session' => $sessionId,
            'member' => $capability['audience'] === 'member' ? [$actor->type, $actor->id] : null,
        ], JSON_THROW_ON_ERROR) : null;
        if ($operation === 'status') {
            return $this->mutations->status($request, $installation, 'public-chat-capability',
                "public-chat:{$kind}:execute", substr($request->path(), 0, -strlen('status')).'execute', $authorityContext)
                ->header('Cache-Control', 'no-store');
        }

        $invoke = function () use ($operation, $kind, $payload, $actor): JsonResponse {
            try {
                $result = $this->registry->provider()->{$operation}($kind, $payload['input'], $actor);
                SensitiveData::assertPublicPayload($result);

                return response()->json($result);
            } catch (PublicChatCapabilityException $exception) {
                return response()->json([
                    'error' => $exception->error,
                    'message' => substr($exception->getMessage(), 0, 300),
                ], max(400, min(499, $exception->status)));
            } catch (\InvalidArgumentException $exception) {
                return response()->json([
                    'error' => 'capability_input_rejected',
                    'message' => substr($exception->getMessage(), 0, 300),
                ], 422);
            }
        };

        if ($operation !== 'execute') {
            return $invoke();
        }

        return $this->mutations->execute(
            request: $request,
            installation: $installation,
            resourceSlug: 'public-chat-capability',
            operation: "public-chat:{$kind}:execute",
            identifier: 'id',
            mutation: $invoke,
            authorityContext: $authorityContext,
        );
    }
}
