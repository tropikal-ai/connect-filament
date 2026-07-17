<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use TropikalAI\Connect\Application\Ports\NonceStore;

/**
 * Replay protection backed by a shared, persistent cache. Fails closed at
 * construction if the resolved store is non-durable (array/null), because those
 * drivers do not remember a claimed nonce across requests/workers and would
 * silently disable single-use enforcement. The nonce is hashed into the key so
 * raw nonces never land in the cache backend.
 */
class CacheNonceStore implements NonceStore
{
    private Repository $cache;

    public function __construct(?string $store = null)
    {
        $store ??= config('connect-filament.api.nonce_cache_store');
        $this->cache = Cache::store($store);

        $backend = $this->cache->getStore();
        if ($backend instanceof NullStore || $backend instanceof ArrayStore) {
            throw new \RuntimeException(
                'TROPIKAL Connect replay protection requires a shared, persistent cache store '
                .'(redis, memcached, database, dynamodb, or file). The "'.class_basename($backend).'" '
                .'driver cannot enforce single-use nonces across requests. Set '
                .'CONNECT_FILAMENT_NONCE_CACHE_STORE to a persistent store.'
            );
        }
    }

    public function claim(string $installationId, string $nonce, int $ttlSeconds): bool
    {
        $key = 'connect-filament:nonce:'.$installationId.':'.hash('sha256', $nonce);

        return $this->cache->add($key, true, $ttlSeconds);
    }
}
