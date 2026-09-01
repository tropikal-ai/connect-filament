<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

final class PublicChatInputValidator
{
    /** @param array<string, mixed> $input @param array<string, mixed> $schema */
    public function accepts(array $input, array $schema): bool
    {
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
            if (! is_array($field) || ! $this->acceptsField($value, $field)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $schema */
    private function acceptsField(mixed $value, array $schema): bool
    {
        $type = $schema['type'] ?? null;
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
