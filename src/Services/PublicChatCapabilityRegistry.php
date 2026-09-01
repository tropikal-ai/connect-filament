<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use TropikalAI\ConnectFilament\Contracts\PublicChatCapabilityProvider;

final class PublicChatCapabilityRegistry
{
    private const KIND_PATTERN = '/\A[a-z][a-z0-9_.-]{2,119}\z/';

    public function __construct(private readonly PublicChatCapabilityProvider $provider) {}

    /** @return array<int, array<string, mixed>> */
    public function manifest(): array
    {
        $capabilities = [];
        foreach (array_slice($this->provider->capabilities(), 0, 20) as $capability) {
            if ($validated = $this->validate($capability)) {
                $capabilities[] = $validated;
            }
        }

        return $capabilities;
    }

    public function capability(string $kind): ?array
    {
        foreach ($this->manifest() as $capability) {
            if ($capability['kind'] === $kind) {
                return $capability;
            }
        }

        return null;
    }

    public function provider(): PublicChatCapabilityProvider
    {
        return $this->provider;
    }

    private function validate(mixed $capability): ?array
    {
        if (! is_array($capability)) {
            return null;
        }
        $kind = trim((string) ($capability['kind'] ?? ''));
        $title = trim((string) ($capability['title'] ?? ''));
        $description = trim((string) ($capability['description'] ?? ''));
        $audience = (string) ($capability['audience'] ?? '');
        $query = $capability['query_tool'] ?? null;
        if (
            preg_match(self::KIND_PATTERN, $kind) !== 1
            || $title === '' || strlen($title) > 120
            || $description === '' || strlen($description) > 500
            || ! in_array($audience, ['public', 'member'], true)
            || ! is_array($query)
            || preg_match('/\A[a-z][a-z0-9_]{2,63}\z/', (string) ($query['name'] ?? '')) !== 1
            || ! is_string($query['description'] ?? null)
            || ! is_array($query['input_schema'] ?? null)
            || ! is_array($capability['proposal_input_schema'] ?? null)
            || ! is_array($capability['execution_input_schema'] ?? null)
        ) {
            return null;
        }

        return [
            'kind' => $kind,
            'title' => $title,
            'description' => $description,
            'audience' => $audience,
            'enabled_by_default' => ($capability['enabled_by_default'] ?? true) === true,
            'query_tool' => [
                'name' => (string) $query['name'],
                'description' => substr((string) $query['description'], 0, 500),
                'input_schema' => $query['input_schema'],
            ],
            'proposal_input_schema' => $capability['proposal_input_schema'],
            'execution_input_schema' => $capability['execution_input_schema'],
        ];
    }

    public function manifestHash(): string
    {
        $manifest = $this->sortKeysRecursively($this->manifest());

        return hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function sortKeysRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortKeysRecursively($item);
        }

        return $value;
    }
}
