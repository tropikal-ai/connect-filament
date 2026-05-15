<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Services\AuditLogger;
use TropikalAI\ConnectFilament\Services\ResourceRegistry;

class ResourceController extends Controller
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly AuditLogger $audit,
    ) {}

    public function schema(Request $request): JsonResponse
    {
        return response()->json([
            'resources' => $this->registry->schemaFor($this->installation($request)),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        [, , $resource] = $this->resourceContext($request, 'read');
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

        $filters = $request->query('filter', []);
        if (is_array($filters)) {
            foreach ($filters as $field => $value) {
                if (in_array($field, $resource['filterable'] ?? [], true)) {
                    $query->where($field, $value);
                }
            }
        }

        $maxPerPage = max(1, (int) config('connect-filament.discovery.max_records_per_list_response', 100));
        $paginated = $query->paginate(min(max((int) $request->query('per_page', 20), 1), $maxPerPage));

        return response()->json([
            'data' => collect($paginated->items())
                ->map(fn (Model $record): array => $this->registry->project($record, $resource))
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
        [, , $resource] = $this->resourceContext($request, 'read');
        $record = $this->findRecord($resource, (string) $request->route('id'));
        if (! $record) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        return response()->json(['data' => $this->registry->project($record, $resource)]);
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
            $record = new $modelClass;
            foreach ($validated as $field => $value) {
                $record->setAttribute($field, $value);
            }
            $record->save();
            $this->audit->record($request, $installation, $slug, $record->getKey(), 'create', ['created' => $validated]);
        } catch (QueryException $exception) {
            report($exception);

            return $this->resourceWriteError($request, 422);
        } catch (Throwable $exception) {
            report($exception);

            return $this->resourceWriteError($request, 500);
        }

        return response()->json(['data' => $this->registry->project($record->fresh() ?? $record, $resource)], 201);
    }

    public function update(Request $request): JsonResponse
    {
        [$installation, $slug, $resource] = $this->resourceContext($request, 'update');
        $record = $this->findRecord($resource, (string) $request->route('id'));
        if (! $record) {
            return response()->json(['error' => 'Record not found'], 404);
        }
        if ($unknown = $this->registry->unknownWriteFields($slug, $request->all())) {
            return response()->json(['error' => 'Unknown fields', 'unknown_fields' => $unknown], 422);
        }

        $validated = $request->validate($this->registry->validationRules($resource, false));
        $before = collect($record->getAttributes())->only(array_keys($validated))->toArray();
        try {
            foreach ($validated as $field => $value) {
                $record->setAttribute($field, $value);
            }
            $record->save();
            $this->audit->record($request, $installation, $slug, $record->getKey(), 'update', ['before' => $before, 'after' => $validated]);
        } catch (QueryException $exception) {
            report($exception);

            return $this->resourceWriteError($request, 422);
        } catch (Throwable $exception) {
            report($exception);

            return $this->resourceWriteError($request, 500);
        }

        return response()->json(['data' => $this->registry->project($record->fresh() ?? $record, $resource)]);
    }

    public function action(Request $request): JsonResponse
    {
        $action = (string) $request->route('action');
        [$installation, $slug, $resource] = $this->resourceContext($request, "action:{$action}");
        $actions = $resource['actions'] ?? [];
        if (! isset($actions[$action])) {
            return response()->json(['error' => 'Action not found'], 404);
        }

        $record = $this->findRecord($resource, (string) $request->route('id'));
        if (! $record) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $method = $actions[$action]['method'] ?? null;
        if (! is_string($method) || ! method_exists($record, $method)) {
            return response()->json(['error' => 'Action method not found'], 500);
        }

        $before = $this->registry->project($record, $resource);
        $record->{$method}();
        $record = $record->fresh();
        $after = $record ? $this->registry->project($record, $resource) : [];
        $this->audit->record($request, $installation, $slug, $record?->getKey(), "action:{$action}", ['before' => $before, 'after' => $after]);

        return response()->json(['data' => $after]);
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

    private function resourceWriteError(Request $request, int $status): JsonResponse
    {
        $correlationId = (string) ($request->headers->get('X-Tropikal-Correlation-Id')
            ?: $request->headers->get('X-Request-Id')
            ?: '');

        return response()->json(array_filter([
            'error' => $status === 422 ? 'Invalid resource data' : 'Resource mutation failed',
            'message' => $status === 422
                ? 'The resource could not be saved with the provided fields.'
                : 'The resource could not be saved. Check application logs.',
            'correlation_id' => $correlationId !== '' ? $correlationId : null,
        ]), $status);
    }
}
