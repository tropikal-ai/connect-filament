<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use TropikalAI\ConnectFilament\Models\NotificationDelivery;
use TropikalAI\ConnectFilament\Services\CustomerNotifications;

class DeliverCustomerNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 200;

    public int $timeout = 90;

    public function __construct(public readonly int $deliveryId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('connect-notification-'.$this->deliveryId))->releaseAfter(10)->expireAfter(120)];
    }

    public function handle(CustomerNotifications $notifications): void
    {
        if ($notifications->process($this->deliveryId) && $this->job !== null) {
            $this->release(30);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        NotificationDelivery::query()->whereKey($this->deliveryId)
            ->whereNotIn('status', ['delivered', 'email_delivered', 'failed'])
            ->update(['status' => 'uncertain']);
    }
}
