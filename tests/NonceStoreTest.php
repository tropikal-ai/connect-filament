<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use TropikalAI\ConnectFilament\Services\CacheNonceStore;

final class NonceStoreTest extends TestCase
{
    public function test_it_fails_closed_on_a_non_persistent_cache_driver(): void
    {
        $this->expectException(RuntimeException::class);

        new CacheNonceStore('array');
    }

    public function test_it_enforces_single_use_on_a_persistent_driver(): void
    {
        $store = new CacheNonceStore('database');

        $this->assertTrue($store->claim('inst_1', 'nonce_a', 600), 'first claim succeeds');
        $this->assertFalse($store->claim('inst_1', 'nonce_a', 600), 'replay of the same nonce is rejected');
        $this->assertTrue($store->claim('inst_1', 'nonce_b', 600), 'a different nonce is independent');
        $this->assertTrue($store->claim('inst_2', 'nonce_a', 600), 'nonces are scoped per installation');
    }

    public function test_it_does_not_store_the_raw_nonce_in_the_cache_key(): void
    {
        $store = new CacheNonceStore('database');
        $store->claim('inst_1', 'super-secret-nonce', 600);

        $keys = DB::table('cache')->pluck('key')->all();
        foreach ($keys as $key) {
            $this->assertStringNotContainsString('super-secret-nonce', (string) $key);
        }
        $this->assertNotEmpty($keys);
    }
}
