<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

final class PublicChatInputValidator
{
    /** @param array<string, mixed> $input @param array<string, mixed> $schema */
    public function accepts(array $input, array $schema, int $depth = 0): bool
    {
        if ($depth > 5 || count($input) > min(200, (int) ($schema['maxProperties'] ?? 200))) {
            return false;
        }
        if (($schema['type'] ?? null) !== 'object'
            || ($schema['additionalProperties'] ?? null) !== false
            || ! is_array($schema['properties'] ?? null)
            || ! is_array($schema['required'] ?? [])) {
            return false;
        }
        $properties = $schema['properties'];
        if (array_diff(array_keys($input), array_keys($properties)) !== []
            || array_diff($schema['required'] ?? [], array_keys($input)) !== []) {
            return false;
        }
        foreach ($input as $key => $value) {
            $field = $properties[$key] ?? null;
            if (! is_array($field) || ! $this->acceptsField($value, $field, $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $schema */
    private function acceptsField(mixed $value, array $schema, int $depth): bool
    {
        if ($depth > 5 || (isset($schema['enum']) && ! in_array($value, $schema['enum'], true))) {
            return false;
        }
        $type = $schema['type'] ?? null;
        if ($type === 'array') {
            if (! is_array($value) || ! array_is_list($value)
                || count($value) < (int) ($schema['minItems'] ?? 0)
                || count($value) > min(200, (int) ($schema['maxItems'] ?? 200))
                || ! is_array($schema['items'] ?? null)) {
                return false;
            }
            if (($schema['uniqueItems'] ?? false) && count(array_unique(array_map('serialize', $value))) !== count($value)) {
                return false;
            }
            foreach ($value as $item) {
                if (! $this->acceptsField($item, $schema['items'], $depth + 1)) {
                    return false;
                }
            }

            return true;
        }
        if ($type === 'object') {
            if (! is_array($value) || count($value) > min(200, (int) ($schema['maxProperties'] ?? 200))
                || count($value) < (int) ($schema['minProperties'] ?? 0)) {
                return false;
            }
            if (($schema['additionalProperties'] ?? null) === false) {
                return $this->accepts($value, $schema, $depth);
            }
            if (! is_array($schema['additionalProperties'] ?? null) || ! isset($schema['propertyNames']['pattern'])) {
                return false;
            }
            foreach ($value as $key => $item) {
                if (@preg_match('/'.str_replace('/', '\\/', $schema['propertyNames']['pattern']).'/D', (string) $key) !== 1
                    || ! $this->acceptsField($item, $schema['additionalProperties'], $depth + 1)) {
                    return false;
                }
            }

            return true;
        }
        if ($type === 'string') {
            if (! is_string($value)) {
                return false;
            }
            $length = mb_strlen($value);
            if ($length < (int) ($schema['minLength'] ?? 0)
                || $length > (int) ($schema['maxLength'] ?? 4096)) {
                return false;
            }
            if (isset($schema['pattern']) && @preg_match('/'.str_replace('/', '\/', (string) $schema['pattern']).'/D', $value) !== 1) {
                return false;
            }

            return ($schema['format'] ?? null) !== 'email' || filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
        }
        if ($type === 'integer') {
            return is_int($value) && $this->withinNumericBounds($value, $schema);
        }
        if ($type === 'number') {
            return (is_int($value) || is_float($value)) && $this->withinNumericBounds($value, $schema);
        }

        return $type === 'boolean' && is_bool($value);
    }

    /** @param array<string, mixed> $schema */
    private function withinNumericBounds(int|float $value, array $schema): bool
    {
        return (! isset($schema['minimum']) || $value >= $schema['minimum'])
            && (! isset($schema['maximum']) || $value <= $schema['maximum']);
    }
}
