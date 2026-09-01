<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Support;

use Illuminate\Http\Request;
use TropikalAI\ConnectFilament\Contracts\PublicChatActorResolver;
use TropikalAI\ConnectFilament\Domain\PublicChatActor;

final class NullPublicChatActorResolver implements PublicChatActorResolver
{
    public function resolve(Request $request): ?PublicChatActor
    {
        return null;
    }
}
