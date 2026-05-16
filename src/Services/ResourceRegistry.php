<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use TropikalAI\Connect\Domain\Capabilities\CapabilityDescriptor;
use TropikalAI\Connect\Domain\Capabilities\CapabilitySet;
use TropikalAI\Connect\Domain\Capabilities\FieldDescriptor;
use TropikalAI\Connect\Domain\Capabilities\OperationDescriptor;
use TropikalAI\Connect\Domain\Resources\ResourceSchema;
use TropikalAI\ConnectFilament\Models\Installation;

class ResourceRegistry
{
    public function __construct(
        private readonly array $resources = [],
        private readonly ?EloquentDiscovery $discovery = null,
    ) {}

    public function resource(string $slug): ?array
    {
        return $this->all()[$slug] ?? null;
    }

    public function all(): array
    {
        return [
            ...($this->discovery?->discover() ?? []),
            ...$this->resources,
        ];
    }

    public function schemaFor(Installation $installation): array
    {
        return $this->schema()->publicSchema(
            $installation->allowed_resources ?? [],
            $installation->resource_permissions ?? [],
        );
    }

    public function allowedResource(Installation $installation, string $slug): ?array
    {
        if (! in_array($slug, $installation->allowed_resources ?? [], true)) {
            return null;
        }

        return $this->resource($slug);
    }

    public function allows(Installation $installation, string $slug, string $permission): bool
    {
        return $this->schema()->allows($installation->resource_permissions ?? [], $slug, $permission);
    }

    public function identifierFor(array $resource): string
    {
        $identifier = $resource['identifier'] ?? 'id';

        return is_string($identifier) && $identifier !== '' ? $identifier : 'id';
    }

    public function writableFields(array $resource): array
    {
        return array_values(array_filter(
            array_keys($resource['fields'] ?? []),
            fn (string $field): bool => ($resource['fields'][$field]['writable'] ?? true) !== false,
        ));
    }

    public function project(Model $record, array $resource): array
    {
        $payload = [];
        foreach ($this->readableFields($resource) as $field) {
            $payload[$field] = $record->getAttribute($field);
        }

        return $payload;
    }

    public function validationRules(array $resource, bool $creating): array
    {
        $rules = [];
        foreach ($resource['fields'] ?? [] as $field => $definition) {
            if (($definition['writable'] ?? true) === false) {
                continue;
            }

            $fieldRules = [($creating && ! empty($definition['required'])) ? 'required' : 'sometimes'];
            if (empty($definition['required'])) {
                $fieldRules[] = 'nullable';
            }

            match ($definition['type'] ?? 'string') {
                'email' => $fieldRules[] = 'email',
                'url' => $fieldRules[] = 'url',
                'integer' => $fieldRules[] = 'integer',
                'boolean' => $fieldRules[] = 'boolean',
                'json' => $fieldRules[] = 'array',
                'datetime' => $fieldRules[] = 'date',
                default => $fieldRules[] = 'string',
            };

            if (($definition['type'] ?? null) === 'enum') {
                $fieldRules[] = Rule::in($definition['options'] ?? []);
            }
            if (isset($definition['max'])) {
                $fieldRules[] = 'max:'.(int) $definition['max'];
            }
            if (isset($definition['min'])) {
                $fieldRules[] = 'min:'.(int) $definition['min'];
            }

            $rules[$field] = $fieldRules;
        }

        return $rules;
    }

    public function unknownWriteFields(string $slug, array $payload): array
    {
        return $this->schema()->unknownWriteFields($slug, $payload);
    }

    public function controlPlaneResourcesFor(Installation $installation): array
    {
        $schema = $this->schemaFor($installation);
        foreach ($schema as $slug => $resource) {
            $definition = $this->resource((string) $slug) ?? [];
            $permissions = is_array($resource['permissions'] ?? null) ? $resource['permissions'] : [];
            $schema[$slug] = [
                ...$resource,
                'source_kind' => 'connect_filament',
                'model_class' => $definition['model'] ?? null,
                'permissions' => $permissions,
                'capabilities' => $this->operationPayload((string) $slug, $definition, $permissions),
            ];
        }

        return $schema;
    }

    private function readableFields(array $resource): array
    {
        return array_values(array_unique([
            $this->identifierFor($resource),
            ...array_keys(array_filter(
                $resource['fields'] ?? [],
                fn (mixed $definition): bool => is_array($definition) && ($definition['readable'] ?? true) !== false,
            )),
        ]));
    }

    private function schema(): ResourceSchema
    {
        return new ResourceSchema($this->all());
    }

    private function operationPayload(string $slug, array $resource, array $permissions): array
    {
        $fields = [];
        foreach ($resource['fields'] ?? [] as $field => $definition) {
            if (! is_string($field) || ! is_array($definition)) {
                continue;
            }
            $fields[$field] = new FieldDescriptor(
                name: $field,
                type: (string) ($definition['type'] ?? 'string'),
                readable: ($definition['readable'] ?? true) !== false,
                writable: ($definition['writable'] ?? true) !== false,
                required: (bool) ($definition['required'] ?? false),
            );
        }

        $operations = [];
        if (in_array('read', $permissions, true)) {
            $operations[] = new OperationDescriptor(
                name: "{$slug}.list",
                operation: 'list',
                riskLevel: 'read',
                inputSchema: ['type' => 'object'],
                outputSchema: ['type' => 'object'],
            );
            $operations[] = new OperationDescriptor(
                name: "{$slug}.get",
                operation: 'get',
                riskLevel: 'read',
                inputSchema: [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => ['id' => ['type' => 'string']],
                ],
                outputSchema: ['type' => 'object'],
            );
        }
        if (in_array('create', $permissions, true) && in_array('update', $permissions, true)) {
            $operations[] = new OperationDescriptor(
                name: "{$slug}.create",
                operation: 'create',
                riskLevel: 'write',
                inputSchema: $this->writeInputSchema($resource, true),
                outputSchema: ['type' => 'object'],
                requiresConfirmation: true,
            );
            $updateInputSchema = $this->writeInputSchema($resource, false);
            $operations[] = new OperationDescriptor(
                name: "{$slug}.update",
                operation: 'update',
                riskLevel: 'write',
                inputSchema: [
                    ...$updateInputSchema,
                    'required' => ['id'],
                    'properties' => [
                        'id' => ['type' => 'string'],
                        ...$updateInputSchema['properties'],
                    ],
                ],
                outputSchema: ['type' => 'object'],
                requiresConfirmation: true,
            );
        }

        $capability = new CapabilityDescriptor(
            sourceKind: 'connect_filament',
            resourceKey: $slug,
            resourceLabel: (string) ($resource['label'] ?? $slug),
            identifier: $this->identifierFor($resource),
            fields: $fields,
            operations: $operations,
            grants: $permissions,
        );

        return (new CapabilitySet([$capability]))->publicPayload()[0]['operations'];
    }

    private function writeInputSchema(array $resource, bool $creating): array
    {
        $properties = [];
        $required = [];

        foreach ($resource['fields'] ?? [] as $field => $definition) {
            if (! is_string($field) || ! is_array($definition) || ($definition['writable'] ?? true) === false) {
                continue;
            }

            $properties[$field] = $this->jsonSchemaForField($definition);
            if ($creating && ! empty($definition['required'])) {
                $required[] = $field;
            }
        }

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    private function jsonSchemaForField(array $definition): array
    {
        $schema = match ($definition['type'] ?? 'string') {
            'integer' => ['type' => 'integer'],
            'boolean' => ['type' => 'boolean'],
            'json' => ['type' => 'object'],
            'datetime' => ['type' => 'string', 'format' => 'date-time'],
            'email' => ['type' => 'string', 'format' => 'email'],
            'url' => ['type' => 'string', 'format' => 'uri'],
            default => ['type' => 'string'],
        };

        if (($definition['type'] ?? null) === 'enum') {
            $schema = ['type' => 'string', 'enum' => array_values((array) ($definition['options'] ?? []))];
        }
        if (isset($definition['max']) && $schema['type'] === 'string') {
            $schema['maxLength'] = (int) $definition['max'];
        }
        if (isset($definition['min']) && $schema['type'] === 'string') {
            $schema['minLength'] = (int) $definition['min'];
        }

        return $schema;
    }
}
