<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Contracts;

use Illuminate\Http\Request;
use TropikalAI\ConnectFilament\Domain\PublicChatActor;

interface PublicChatActorResolver
{
    public function resolve(Request $request): ?PublicChatActor;
}
