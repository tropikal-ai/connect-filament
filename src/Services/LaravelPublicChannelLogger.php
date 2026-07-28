<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use Illuminate\Support\Facades\Log;
use TropikalAI\Connect\Application\PublicChannels\Ports\PublicChannelLogger;

final class LaravelPublicChannelLogger implements PublicChannelLogger
{
    public function warning(string $event, array $context = []): void
    {
        Log::warning($event, $context);
    }
}
