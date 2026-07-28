<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use Illuminate\Support\Facades\Cache;
use TropikalAI\Connect\Application\PublicChannels\Ports\ContractCache;

final class LaravelContractCache implements ContractCache
{
    public function get(string $key): ?array
    {
        $value = Cache::get($key);

        return is_array($value) ? $value : null;
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        Cache::put($key, $value, $ttlSeconds);
    }
}
