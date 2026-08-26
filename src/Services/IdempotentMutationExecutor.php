<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use TropikalAI\ConnectFilament\Domain\MutationIdentity;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Models\OperationReceipt;

final class IdempotentMutationExecutor
{
    public function execute(
        Request $request,
        Installation $installation,
        string $resourceSlug,
        string $operation,
        string $identifier,
        Closure $mutation,
        ?Closure $replayPayload = null,
    ): JsonResponse {
        $rawKey = (string) $request->header(MutationIdentity::HEADER, '');
        if ($rawKey === '') {
            if ((bool) config('connect-filament.api.require_idempotency_for_mutations', false)) {
                return response()->json([
                    'error' => 'idempotency_key_required',
                    'message' => 'This mutation requires an idempotency key.',
                ], 428);
            }

            return $mutation();
        }

        try {
            $identity = MutationIdentity::fromRequestParts(
                key: $rawKey,
                operation: $operation,
                method: $request->method(),
                path: $request->path(),
                query: $request->getQueryString() ?? '',
                body: $request->getContent() ?: '',
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'error' => 'invalid_idempotency_key',
                'message' => $exception->getMessage(),
            ], 422);
        }

        try {
            return DB::transaction(function () use (
                $identity,
                $identifier,
                $installation,
                $mutation,
                $replayPayload,
                $resourceSlug,
            ): JsonResponse {
                $receipt = $this->findReceipt($installation, $identity->key, lock: true);
                if ($receipt !== null) {
                    return $this->existingReceiptResponse(
                        $receipt,
                        $identity,
                        $resourceSlug,
                        $replayPayload,
                    );
                }

                $receipt = OperationReceipt::query()->create([
                    'installation_id' => $installation->getKey(),
                    'idempotency_key' => $identity->key,
                    'operation' => $identity->operation,
                    'resource_slug' => $resourceSlug,
                    'request_hash' => $identity->requestHash,
                    'status' => OperationReceipt::STATUS_CLAIMED,
                ]);

                $response = $mutation();
                $status = $response->getStatusCode();
                if ($status >= 500) {
                    throw new \RuntimeException('The mutation failed before a durable outcome was recorded.');
                }

                $payload = $response->getData(true);
                $receipt->forceFill([
                    'status' => $status >= 400
                        ? OperationReceipt::STATUS_FAILED_NO_EFFECT
                        : OperationReceipt::STATUS_COMMITTED,
                    'result_ref' => $this->resultReference($payload, $identifier),
                    'response_status' => $status,
                    'response_json' => $payload,
                    'completed_at' => now(),
                ])->save();

                return $this->receiptResponse($receipt, replayed: false);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // Two workers may both observe no receipt before either insert is
            // visible. The unique index grants one of them the commit right;
            // the loser resolves the winner's durable outcome after rollback.
            $receipt = $this->findReceipt($installation, $identity->key, lock: false);
            if ($receipt === null) {
                throw $exception;
            }

            return $this->existingReceiptResponse(
                $receipt,
                $identity,
                $resourceSlug,
                $replayPayload,
            );
        }
    }

    private function existingReceiptResponse(
        OperationReceipt $receipt,
        MutationIdentity $identity,
        string $resourceSlug,
        ?Closure $replayPayload,
    ): JsonResponse {
        if (
            $receipt->request_hash !== $identity->requestHash
            || $receipt->operation !== $identity->operation
            || $receipt->resource_slug !== $resourceSlug
        ) {
            return response()->json([
                'error' => 'idempotency_conflict',
                'message' => 'This idempotency key was already used for a different mutation.',
            ], 409);
        }

        if ($receipt->status === OperationReceipt::STATUS_CLAIMED) {
            return response()->json([
                'error' => 'operation_in_progress',
                'message' => 'This mutation is already being processed.',
            ], 409);
        }

        $payload = $replayPayload ? $replayPayload($receipt) : null;

        return $this->receiptResponse(
            $receipt,
            replayed: true,
            payload: is_array($payload) ? $payload : null,
        );
    }

    private function findReceipt(Installation $installation, string $key, bool $lock): ?OperationReceipt
    {
        $query = OperationReceipt::query()
            ->where('installation_id', $installation->getKey())
            ->where('idempotency_key', $key);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function receiptResponse(OperationReceipt $receipt, bool $replayed, ?array $payload = null): JsonResponse
    {
        $payload ??= is_array($receipt->response_json) ? $receipt->response_json : [];
        $payload['operation_receipt'] = [
            'id' => $receipt->public_id,
            'status' => $receipt->status,
            'result_ref' => $receipt->result_ref,
            'replayed' => $replayed,
        ];

        return response()->json(
            $payload,
            (int) ($receipt->response_status ?: 200),
            ['X-Tropikal-Idempotency-Replayed' => $replayed ? 'true' : 'false'],
        );
    }

    private function resultReference(array $payload, string $identifier): ?string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $value = $data[$identifier] ?? $data['id'] ?? null;

        return is_scalar($value) ? substr((string) $value, 0, 255) : null;
    }
}
