<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Throwable;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Services\AuditLogger;
use TropikalAI\ConnectFilament\Services\IdempotentMutationExecutor;
use TropikalAI\ConnectFilament\Services\PublicChatCapabilityRegistry;
use TropikalAI\ConnectFilament\Services\ResourceRegistry;
use TropikalAI\ConnectFilament\Services\StagedAssetManager;

class ResourceController extends Controller
{
    private const LIST_ARGUMENTS = ['page', 'per_page', 'limit', 'search', 'filter'];

    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly AuditLogger $audit,
        private readonly IdempotentMutationExecutor $mutations,
        private readonly StagedAssetManager $assets,
    ) {}

    public function schema(Request $request): JsonResponse
    {
        return response()->json([
            'resources' => $this->registry->schemaFor($this->installation($request)),
        ]);
    }

    /**
     * The full control-plane resource payload (identical to the install/sync
     * push), so the control plane can pull a fresh copy on demand instead of
     * waiting for the next push. Unlike schema(), this includes each resource's
     * capabilities and operation input schemas.
     */
    public function controlPlaneResources(Request $request, PublicChatCapabilityRegistry $publicChatCapabilities): JsonResponse
    {
        return response()->json([
            'resources' => $this->registry->controlPlaneResourcesFor($this->installation($request)),
            'public_chat_capabilities' => $publicChatCapabilities->manifest(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        [$installation, $slug, $resource] = $this->resourceContext($request, 'read');
        $modelClass = $resource['model'];
        $query = $modelClass::query()->orderByDesc($this->sortColumn($resource));

        $search = trim((string) $request->query('search', ''));
        if ($search !== '' && ! empty($resource['searchable'])) {
            $query->where(function ($query) use ($resource, $search): void {
                foreach ($resource['searchable'] as $field) {
                    $query->orWhere($field, 'LIKE', "%{$search}%");
                }
            });
        }

        foreach ($this->listFilters($request, $resource) as $field => $value) {
            $query->where($field, $value);
        }

        $maxPerPage = max(1, (int) config('connect-filament.discovery.max_records_per_list_response', 100));
        $paginated = $query->paginate(min(max($this->perPage($request), 1), $maxPerPage));

        return response()->json([
            'data' => collect($paginated->items())
                ->map(fn (Model $record): array => $this->registry->projectFor($installation, $slug, $record, $resource))
                ->all(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        [$installation, $slug, $resource] = $this->resourceContext($request, 'read');
        $record = $this->findRecord($resource, (string) $request->route('id'));
        if (! $record) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        return response()->json(['data' => $this->registry->projectFor($installation, $slug, $record, $resource)]);
    }

    public function store(Request $request): JsonResponse
    {
        [$installation, $slug, $resource] = $this->resourceContext($request, 'create');
        if ($unknown = $this->registry->unknownWriteFields($slug, $request->all())) {
            return response()->json(['error' => 'Unknown fields', 'unknown_fields' => $unknown], 422);
        }

        $validated = $request->validate($this->registry->validationRules($resource, true));
        $modelClass = $resource['model'];
        try {
            return $this->mutations->execute(
                request: $request,
                installation: $installation,
                resourceSlug: $slug,
                operation: 'create',
                identifier: $this->registry->identifierFor($resource),
                mutation: function () use ($installation, $modelClass, $request, $resource, $slug, $validated): JsonResponse {
                    $resolved = $this->assets->resolveForMutation($installation, $slug, $resource, $validated);
                    $record = new $modelClass;
                    foreach ($resolved as $field => $value) {
                        $record->setAttribute($field, $value);
                    }
                    $record->save();
                    $this->audit->record($request, $installation, $slug, $record->getKey(), 'create', ['created' => $resolved]);

                    return response()->json([
                        'data' => $this->registry->projectFor(
                            $installation,
                            $slug,
                            $record->fresh() ?? $record,
                            $resource,
                        ),
                    ], 201);
                },
                replayPayload: fn ($receipt): array => $this->registry->narrowResponsePayloadFor(
                    $installation,
                    $slug,
                    $resource,
                    is_array($receipt->response_json) ? $receipt->response_json : [],
                ),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (UniqueConstraintViolationException $exception) {
            report($exception);

            return $this->resourceConflictError($request, $resource, $exception);
        } catch (QueryException $exception) {
            report($exception);

            return $this->resourceMutationError($request, 422);
        } catch (Throwable $exception) {
            report($exception);

            return $this->resourceMutationError($request, 500);
        }
    }

    public function update(Request $request): JsonResponse
    {
        [$installation, $slug, $resource] = $this->resourceContext($request, 'update');
        if ($unknown = $this->registry->unknownWriteFields($slug, $request->all())) {
            return response()->json(['error' => 'Unknown fields', 'unknown_fields' => $unknown], 422);
        }

        $validated = $request->validate($this->registry->validationRules($resource, false));
        try {
            return $this->mutations->execute(
                request: $request,
                installation: $installation,
                resourceSlug: $slug,
                operation: 'update',
                identifier: $this->registry->identifierFor($resource),
                mutation: function () use ($installation, $request, $resource, $slug, $validated): JsonResponse {
                    $record = $this->findRecord($resource, (string) $request->route('id'));
                    if (! $record) {
                        return response()->json(['error' => 'Record not found'], 404);
                    }
                    $resolved = $this->assets->resolveForMutation($installation, $slug, $resource, $validated);
                    $before = collect($record->getAttributes())->only(array_keys($resolved))->toArray();
                    foreach ($resolved as $field => $value) {
                        $record->setAttribute($field, $value);
                    }
                    $record->save();
                    $this->audit->record($request, $installation, $slug, $record->getKey(), 'update', ['before' => $before, 'after' => $resolved]);

                    return response()->json([
                        'data' => $this->registry->projectFor(
                            $installation,
                            $slug,
                            $record->fresh() ?? $record,
                            $resource,
                        ),
                    ]);
                },
                replayPayload: fn ($receipt): array => $this->registry->narrowResponsePayloadFor(
                    $installation,
                    $slug,
                    $resource,
                    is_array($receipt->response_json) ? $receipt->response_json : [],
                ),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (UniqueConstraintViolationException $exception) {
            report($exception);

            return $this->resourceConflictError($request, $resource, $exception);
        } catch (QueryException $exception) {
            report($exception);

            return $this->resourceMutationError($request, 422);
        } catch (Throwable $exception) {
            report($exception);

            return $this->resourceMutationError($request, 500);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        [$installation, $slug, $resource] = $this->resourceContext($request, 'delete');
        try {
            return $this->mutations->execute(
                request: $request,
                installation: $installation,
                resourceSlug: $slug,
                operation: 'delete',
                identifier: $this->registry->identifierFor($resource),
                mutation: function () use ($installation, $request, $resource, $slug): JsonResponse {
                    $record = $this->findRecord($resource, (string) $request->route('id'));
                    if (! $record) {
                        return response()->json(['error' => 'Record not found'], 404);
                    }
                    $identifier = (string) $record->getAttribute($this->registry->identifierFor($resource));
                    $before = $this->registry->project($record, $resource);
                    $record->delete();
                    $this->audit->record($request, $installation, $slug, $identifier, 'delete', ['before' => $before]);

                    return response()->json(['data' => ['id' => $identifier, 'deleted' => true]]);
                },
                replayPayload: fn ($receipt): array => $this->registry->narrowResponsePayloadFor(
                    $installation,
                    $slug,
                    $resource,
                    is_array($receipt->response_json) ? $receipt->response_json : [],
                ),
            );
        } catch (QueryException $exception) {
            report($exception);

            return $this->resourceMutationError($request, 422);
        } catch (Throwable $exception) {
            report($exception);

            return $this->resourceMutationError($request, 500);
        }
    }

    public function action(Request $request): JsonResponse
    {
        $action = (string) $request->route('action');
        [$installation, $slug, $resource] = $this->resourceContext($request, "action:{$action}");
        $actions = $resource['actions'] ?? [];
        if (! isset($actions[$action])) {
            return response()->json(['error' => 'Action not found'], 404);
        }

        $method = $actions[$action]['method'] ?? null;
        if (! is_string($method)) {
            return response()->json(['error' => 'Action method not found'], 500);
        }

        return $this->mutations->execute(
            request: $request,
            installation: $installation,
            resourceSlug: $slug,
            operation: "action:{$action}",
            identifier: $this->registry->identifierFor($resource),
            mutation: function () use ($action, $installation, $method, $request, $resource, $slug): JsonResponse {
                $record = $this->findRecord($resource, (string) $request->route('id'));
                if (! $record) {
                    return response()->json(['error' => 'Record not found'], 404);
                }
                if (! method_exists($record, $method)) {
                    return response()->json(['error' => 'Action method not found'], 500);
                }

                $before = $this->registry->project($record, $resource);
                $record->{$method}();
                $record = $record->fresh();
                // The audit log is the site's own record of what changed, so it keeps
                // the full before/after. Only the response is narrowed to the fields
                // this installation may receive.
                $after = $record ? $this->registry->project($record, $resource) : [];
                $this->audit->record($request, $installation, $slug, $record?->getKey(), "action:{$action}", ['before' => $before, 'after' => $after]);

                return response()->json([
                    'data' => $record ? $this->registry->projectFor($installation, $slug, $record, $resource) : [],
                ]);
            },
            replayPayload: fn ($receipt): array => $this->registry->narrowResponsePayloadFor(
                $installation,
                $slug,
                $resource,
                is_array($receipt->response_json) ? $receipt->response_json : [],
            ),
        );
    }

    private function installation(Request $request): Installation
    {
        $installation = $request->attributes->get('connect_filament_installation');
        if (! $installation instanceof Installation) {
            abort(403, 'Connect installation is not connected.');
        }

        return $installation;
    }

    private function resourceContext(Request $request, string $permission): array
    {
        $installation = $this->installation($request);
        $slug = (string) $request->route('resource');
        $resource = $this->registry->resource($slug);
        if (! $resource) {
            abort(response()->json(['error' => 'Resource not found'], 404));
        }
        if (! $this->registry->allowedResource($installation, $slug)) {
            abort(response()->json(['error' => 'Resource not allowed'], 403));
        }
        if (! $this->registry->allows($installation, $slug, $permission)) {
            abort(response()->json(['error' => 'Permission denied'], 403));
        }

        return [$installation, $slug, $resource];
    }

    private function findRecord(array $resource, string $id): ?Model
    {
        $modelClass = $resource['model'];

        return $modelClass::query()
            ->where($this->registry->identifierFor($resource), $id)
            ->first();
    }

    private function sortColumn(array $resource): string
    {
        $column = $resource['sort_column'] ?? 'created_at';

        return is_string($column) && $column !== '' ? $column : $this->registry->identifierFor($resource);
    }

    private function listFilters(Request $request, array $resource): array
    {
        $allowed = array_flip($this->registry->listFilterFields($resource));
        $filters = [];

        $nested = $request->query('filter', []);
        if (is_array($nested)) {
            foreach ($nested as $field => $value) {
                if (is_string($field) && isset($allowed[$field]) && is_scalar($value)) {
                    $filters[$field] = $value;
                }
            }
        }

        foreach ($request->query() as $field => $value) {
            if (! is_string($field) || in_array($field, self::LIST_ARGUMENTS, true)) {
                continue;
            }
            if (isset($allowed[$field]) && is_scalar($value)) {
                $filters[$field] = $value;
            }
        }

        return $filters;
    }

    private function perPage(Request $request): int
    {
        $value = $request->query('per_page', $request->query('limit', 20));

        return is_scalar($value) ? (int) $value : 20;
    }

    private function resourceMutationError(Request $request, int $status): JsonResponse
    {
        $correlationId = (string) ($request->headers->get('X-Tropikal-Correlation-Id')
            ?: $request->headers->get('X-Request-Id')
            ?: '');

        return response()->json(array_filter([
            'error' => $status === 422 ? 'Invalid resource data' : 'Resource mutation failed',
            'message' => $status === 422
                ? 'The resource could not be changed with the provided fields.'
                : 'The resource could not be changed. Check application logs.',
            'correlation_id' => $correlationId !== '' ? $correlationId : null,
        ]), $status);
    }

    /**
     * A unique-constraint collision is not malformed data and retrying will not
     * help — the record already exists. Return a distinct 409 the control plane
     * can classify honestly ("already exists") instead of steering the operator
     * to "fix your inputs". The offending field is surfaced when derivable, but
     * only from the resource's own writable fields so no internal schema leaks.
     */
    private function resourceConflictError(
        Request $request,
        array $resource,
        UniqueConstraintViolationException $exception,
    ): JsonResponse {
        $correlationId = (string) ($request->headers->get('X-Tropikal-Correlation-Id')
            ?: $request->headers->get('X-Request-Id')
            ?: '');

        $field = $this->conflictFieldFor($resource, $exception);

        return response()->json(array_filter([
            'error' => 'duplicate_resource',
            'message' => $field !== null
                ? "A record with this {$field} already exists."
                : 'A record with these details already exists.',
            'field' => $field,
            'correlation_id' => $correlationId !== '' ? $correlationId : null,
        ], static fn ($value): bool => $value !== null), 409);
    }

    /**
     * Best-effort map from the database's unique-constraint name back to one of
     * the resource's own declared field names. Never returns a field the
     * resource does not declare, so the response can't disclose columns outside
     * the published contract.
     *
     * The exception message carries the full failing SQL (column list, bindings)
     * so we must first isolate the constraint token — otherwise a field that
     * merely appears in the INSERT column list (e.g. created_at) would match.
     * Handles the three drivers we run against:
     *   - SQLite:   "UNIQUE constraint failed: research_posts.slug"
     *   - Postgres: 'duplicate key value violates unique constraint "..._slug_unique"'
     *   - MySQL:    "Duplicate entry 'x' for key 'research_posts_slug_unique'"
     */
    private function conflictFieldFor(array $resource, UniqueConstraintViolationException $exception): ?string
    {
        $message = $exception->getMessage();
        $token = '';
        if (preg_match('/unique constraint failed:\s*([^\s(]+)/i', $message, $m)) {
            $token = $m[1];
        } elseif (preg_match('/unique constraint\s+"([^"]+)"/i', $message, $m)) {
            $token = $m[1];
        } elseif (preg_match("/for key '([^']+)'/i", $message, $m)) {
            $token = $m[1];
        }
        if ($token === '') {
            return null;
        }

        $token = strtolower($token);
        $candidates = array_keys($resource['fields'] ?? []);
        // Prefer the longest matching field name so a compound column wins over a
        // shorter substring of it.
        usort($candidates, static fn ($a, $b): int => strlen((string) $b) <=> strlen((string) $a));
        foreach ($candidates as $field) {
            $needle = strtolower((string) $field);
            if ($needle !== '' && str_contains($token, $needle)) {
                return (string) $field;
            }
        }

        return null;
    }
}
