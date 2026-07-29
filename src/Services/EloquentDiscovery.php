<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;
use TropikalAI\Connect\Domain\Security\SensitiveData;

class EloquentDiscovery
{
    public function discover(): array
    {
        if (! (bool) config('connect-filament.discovery.enabled', true)) {
            return [];
        }

        $resources = [];
        foreach ($this->modelClasses() as $class) {
            $resource = $this->resourceFor($class);
            if ($resource !== null) {
                $resources[$resource['slug']] = $resource['definition'];
            }
        }

        ksort($resources);

        return $resources;
    }

    public function resourceFor(string $class): ?array
    {
        if (! $this->isDiscoverableModel($class)) {
            return null;
        }

        /** @var Model $model */
        $model = new $class;
        $table = $model->getTable();
        if (! Schema::hasTable($table)) {
            return null;
        }

        $identifier = $model->getKeyName() ?: 'id';
        $columns = $this->columnsByName($table);
        $fields = [];
        foreach (Schema::getColumnListing($table) as $column) {
            if ($column === $identifier || $this->isUnsafeField($model, $column)) {
                continue;
            }

            $fields[$column] = [
                'type' => $this->fieldType($model, $table, $column, $columns[$column] ?? []),
                'required' => $this->isRequiredField($model, $column, $columns[$column] ?? []),
                'writable' => $this->isWritableField($model, $column),
            ];
        }

        if ($fields === []) {
            return null;
        }

        // A slug the guard rejects means this model must not reach the bridge,
        // not that the host application is broken. Throwing here propagates out
        // of discover() and takes down every caller — the Filament dashboard
        // and the whole resource API — because one model happens to be named
        // after tokens, keys or secrets. Skip it instead.
        $slug = Str::of(class_basename($class))->snake()->plural()->toString();
        if (SensitiveData::isSensitiveKey($slug)) {
            return null;
        }
        SensitiveData::assertPublicPayload($fields);

        return [
            'slug' => $slug,
            'definition' => [
                'label' => Str::of(class_basename($class))->headline()->plural()->toString(),
                'model' => $class,
                'identifier' => $identifier,
                'sort_column' => Schema::hasColumn($table, 'created_at') ? 'created_at' : $identifier,
                'fields' => $fields,
                'searchable' => $this->searchableFields($fields),
                'filterable' => [],
                'actions' => [],
                'source_kind' => 'connect_filament',
                'discovered' => true,
            ],
        ];
    }

    private function modelClasses(): array
    {
        $classes = [
            ...$this->explicitModelClasses(),
            ...get_declared_classes(),
            ...$this->classMapClasses(),
        ];

        return array_values(array_unique(array_filter($classes, 'is_string')));
    }

    private function classMapClasses(): array
    {
        $classes = [];
        foreach (spl_autoload_functions() ?: [] as $loader) {
            if (is_array($loader) && ($loader[0] ?? null) instanceof ClassLoader) {
                $classes = [...$classes, ...array_keys($loader[0]->getClassMap())];
            }
        }

        return $classes;
    }

    private function isDiscoverableModel(string $class): bool
    {
        if (in_array($class, (array) config('connect-filament.discovery.excluded_model_classes', []), true)) {
            return false;
        }

        if (! $this->matchesIncludedNamespace($class) && ! in_array($class, $this->explicitModelClasses(), true)) {
            return false;
        }

        try {
            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                return false;
            }

            if (is_subclass_of($class, Authenticatable::class)) {
                return false;
            }

            $reflection = new ReflectionClass($class);
        } catch (Throwable) {
            return false;
        }

        if ($reflection->isAbstract() || $reflection->isInternal()) {
            return false;
        }

        return true;
    }

    private function matchesIncludedNamespace(string $class): bool
    {
        $namespaces = array_filter((array) config('connect-filament.discovery.included_model_namespaces', []));
        if ($namespaces === []) {
            return false;
        }

        foreach ($namespaces as $namespace) {
            if (str_starts_with($class, (string) $namespace)) {
                return true;
            }
        }

        return false;
    }

    private function explicitModelClasses(): array
    {
        $classes = [];
        foreach ((array) config('connect-filament.resources', []) as $resource) {
            if (is_array($resource) && is_string($resource['model'] ?? null)) {
                $classes[] = $resource['model'];
            }
        }

        return array_values(array_unique([
            ...$classes,
            ...(array) config('connect-filament.discovery.model_classes', []),
        ]));
    }

    private function isUnsafeField(Model $model, string $field): bool
    {
        if (SensitiveData::isSensitiveKey($field) || in_array($field, $model->getHidden(), true)) {
            return true;
        }

        foreach ((array) config('connect-filament.discovery.excluded_field_patterns', []) as $pattern) {
            if (@preg_match((string) $pattern, $field) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isWritableField(Model $model, string $field): bool
    {
        if (in_array($field, [$model->getKeyName(), 'created_at', 'updated_at', 'deleted_at'], true)) {
            return false;
        }

        $fillable = $model->getFillable();
        if ($fillable !== []) {
            return in_array($field, $fillable, true);
        }

        return $model->getGuarded() !== ['*'] && ! in_array($field, $model->getGuarded(), true);
    }

    /**
     * Resolve the Connect field type for a column.
     *
     * The model's own cast wins when it declares one: it is driver-independent
     * and states intent directly. Schema introspection is the fallback, and it
     * has to be read carefully — MySQL has no native boolean, so `$table->
     * boolean()` lands as `tinyint(1)` and `Schema::getColumnType()` reports
     * plain "tinyint". Mapping that to the `string` default let payloads like
     * `"false"` pass a `string` validation rule and reach the database as a
     * literal string, which MySQL rejects with SQLSTATE[22007].
     */
    private function fieldType(Model $model, string $table, string $column, array $columnMeta = []): string
    {
        return $this->castFieldType($model, $column)
            ?? $this->schemaFieldType($table, $column, $columnMeta);
    }

    private function castFieldType(Model $model, string $column): ?string
    {
        $cast = $model->getCasts()[$column] ?? null;
        if (! is_string($cast) || $cast === '') {
            return null;
        }

        // Parameterised casts arrive as "decimal:2", "date:Y-m-d", etc.
        $cast = strtolower(explode(':', $cast, 2)[0]);

        return match ($cast) {
            'bool', 'boolean' => 'boolean',
            'int', 'integer' => 'integer',
            'array', 'json', 'object', 'collection' => 'json',
            'date', 'datetime', 'immutable_date', 'immutable_datetime', 'timestamp' => 'datetime',
            default => null,
        };
    }

    private function schemaFieldType(string $table, string $column, array $columnMeta = []): string
    {
        $type = Schema::getColumnType($table, $column);

        if ($type === 'tinyint') {
            // `tinyint(1)` is the MySQL boolean idiom; wider tinyints are ints.
            $declared = is_string($columnMeta['type'] ?? null) ? $columnMeta['type'] : '';

            return str_contains(strtolower($declared), 'tinyint(1)') ? 'boolean' : 'integer';
        }

        return match ($type) {
            'bigint', 'integer', 'smallint', 'mediumint' => 'integer',
            'boolean' => 'boolean',
            'date', 'datetime', 'timestamp' => 'datetime',
            'json' => 'json',
            'text' => 'text',
            default => 'string',
        };
    }

    private function columnsByName(string $table): array
    {
        try {
            $columns = Schema::getColumns($table);
        } catch (Throwable) {
            return [];
        }

        $byName = [];
        foreach ($columns as $column) {
            if (! is_array($column)) {
                continue;
            }
            $name = $column['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $byName[$name] = $column;
            }
        }

        return $byName;
    }

    private function isRequiredField(Model $model, string $field, array $column): bool
    {
        if (! $this->isWritableField($model, $field)) {
            return false;
        }
        if (in_array($field, (array) config('connect-filament.discovery.auto_generated_field_names', []), true)) {
            return false;
        }
        if (($column['nullable'] ?? true) === true) {
            return false;
        }
        if (array_key_exists('default', $column) && $column['default'] !== null) {
            return false;
        }

        return true;
    }

    private function searchableFields(array $fields): array
    {
        return array_values(array_filter(
            array_keys($fields),
            fn (string $field): bool => in_array($fields[$field]['type'] ?? null, ['string', 'text', 'email', 'url'], true),
        ));
    }
}
