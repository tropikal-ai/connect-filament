<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Domain;

final readonly class MutationIdentity
{
    public const HEADER = 'X-Tropikal-Idempotency-Key';

    private const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{7,159}$/';

    private function __construct(
        public string $key,
        public string $operation,
        public string $requestHash,
    ) {}

    public static function fromRequestParts(
        string $key,
        string $operation,
        string $method,
        string $path,
        string $query,
        string $body,
    ): self {
        $key = trim($key);
        if (! preg_match(self::KEY_PATTERN, $key)) {
            throw new \InvalidArgumentException('The idempotency key is invalid.');
        }

        $operation = trim($operation);
        if ($operation === '') {
            throw new \InvalidArgumentException('The mutation operation is required.');
        }

        return new self(
            key: $key,
            operation: $operation,
            requestHash: hash('sha256', implode("\n", [
                strtoupper($method),
                '/'.ltrim($path, '/'),
                $query,
                hash('sha256', $body),
            ])),
        );
    }
}
