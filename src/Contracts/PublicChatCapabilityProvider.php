<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Contracts;

use TropikalAI\ConnectFilament\Domain\PublicChatActor;

interface PublicChatCapabilityProvider
{
    /** @return array<int, array<string, mixed>> */
    public function capabilities(): array;

    /** @param array<string, mixed> $input */
    public function query(string $kind, array $input, ?PublicChatActor $actor): array;

    /** @param array<string, mixed> $input */
    public function execute(string $kind, array $input, ?PublicChatActor $actor): array;
}
