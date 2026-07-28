<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use Illuminate\Support\Facades\RateLimiter as LaravelRateLimiter;
use TropikalAI\Connect\Application\PublicChannels\Ports\RateLimiter;

final class LaravelPublicChannelRateLimiter implements RateLimiter
{
    public function allow(string $bucket, string $source, int $limit, int $windowSeconds): bool
    {
        return LaravelRateLimiter::attempt(
            'tropikal-connect:'.$bucket.':'.hash('sha256', $source),
            $limit,
            static fn (): bool => true,
            $windowSeconds,
        );
    }
}
