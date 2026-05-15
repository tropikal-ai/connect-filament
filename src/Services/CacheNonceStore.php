<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use Illuminate\Support\Facades\Cache;
use TropikalAI\Connect\Application\Ports\NonceStore;

class CacheNonceStore implements NonceStore
{
    public function claim(string $installationId, string $nonce, int $ttlSeconds): bool
    {
        return Cache::add("connect-filament:nonce:{$installationId}:{$nonce}", true, $ttlSeconds);
    }
}
