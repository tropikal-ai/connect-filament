<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Support;

use TropikalAI\ConnectFilament\Contracts\PublicChatCapabilityProvider;
use TropikalAI\ConnectFilament\Domain\PublicChatActor;

final class NullPublicChatCapabilityProvider implements PublicChatCapabilityProvider
{
    public function capabilities(): array
    {
        return [];
    }

    public function query(string $kind, array $input, ?PublicChatActor $actor): array
    {
        return [];
    }

    public function execute(string $kind, array $input, ?PublicChatActor $actor): array
    {
        return [];
    }
}
