<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TropikalAI\Connect\Domain\Security\SensitiveData;
use TropikalAI\ConnectFilament\Domain\PublicChatCapabilityException;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Services\IdempotentMutationExecutor;
use TropikalAI\ConnectFilament\Services\PublicChatActorPermit;
use TropikalAI\ConnectFilament\Services\PublicChatCapabilityRegistry;
use TropikalAI\ConnectFilament\Services\PublicChatInputValidator;

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
        if ($capability === null || ! in_array($operation, ['query', 'execute'], true)) {
            return response()->json(['error' => 'capability_not_found'], 404);
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
        $actor = $this->actors->redeem(
            $request->header(PublicChatActorPermit::HEADER),
            $installation,
            $sessionId,
        );
        if ($capability['audience'] === 'member' && ($actor === null || $actor->type !== 'member')) {
            return response()->json(['error' => 'member_authentication_required'], 401);
        }
        if ($operation === 'execute' && $capability['audience'] === 'member') {
            $initialActor = $this->actors->redeem(
                $request->header(PublicChatActorPermit::INITIAL_HEADER),
                $installation,
                $sessionId,
            );
            if ($initialActor === null
                || $actor === null
                || ! hash_equals($initialActor->type, $actor->type)
                || ! hash_equals($initialActor->id, $actor->id)) {
                return response()->json(['error' => 'member_identity_changed'], 401);
            }
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
        );
    }
}
