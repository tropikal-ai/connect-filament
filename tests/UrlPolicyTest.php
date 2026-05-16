<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use TropikalAI\ConnectFilament\Services\UrlPolicy;

final class UrlPolicyTest extends TestCase
{
    public function test_trusted_base_urls_require_https_except_local_development(): void
    {
        $this->assertSame('https://example.com/base', UrlPolicy::trustedBaseUrl('https://example.com/base/', 'Example URL'));
        $this->assertSame('http://localhost:8000', UrlPolicy::trustedBaseUrl('http://localhost:8000', 'Local URL'));

        foreach ([
            'http://example.com',
            'https://user:pass@example.com',
            'https://example.com?token=value',
            'not-a-url',
        ] as $url) {
            try {
                UrlPolicy::trustedBaseUrl($url, 'Unsafe URL');
                $this->fail("Unsafe URL was accepted: {$url}");
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_public_url_and_origin_helpers_reject_unsafe_values(): void
    {
        $this->assertSame('https://example.com/path?tab=overview', UrlPolicy::publicUrlOrNull('https://example.com/path?tab=overview'));
        $this->assertSame('https://example.com:8443', UrlPolicy::originOrNull('https://example.com:8443/path'));
        $this->assertNull(UrlPolicy::publicUrlOrNull('http://example.com/path'));
        $this->assertNull(UrlPolicy::publicUrlOrNull('https://user:pass@example.com/path'));
        $this->assertNull(UrlPolicy::originOrNull('javascript:alert(1)'));
    }
}
