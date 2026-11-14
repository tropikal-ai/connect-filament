<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use TropikalAI\ConnectFilament\Contracts\NotificationEmailSender;
use TropikalAI\ConnectFilament\Domain\NotificationRequest;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Models\NotificationDelivery;
use TropikalAI\ConnectFilament\Services\CustomerNotifications;

class CustomerNotificationCompatibilityTest extends TestCase
{
    public static function capabilityCases(): array
    {
        $cases = json_decode(file_get_contents(__DIR__.'/Fixtures/customer-notification-compatibility.json'), true, flags: JSON_THROW_ON_ERROR);

        return array_map(fn (array $case) => [$case], $cases);
    }

    #[DataProvider('capabilityCases')]
    public function test_mixed_version_capabilities_are_safe(array $case): void
    {
        Queue::fake();
        config()->set('connect-filament.notifications.enabled', true);
        $sender = new class implements NotificationEmailSender
        {
            public int $sent = 0;

            public function send(NotificationDelivery $delivery): void
            {
                $this->sent++;
            }
        };
        $this->app->instance(NotificationEmailSender::class, $sender);
        Http::fake([
            '*/capabilities' => Http::response($case['body'], $case['http']),
            '*/deliveries' => Http::response(['version' => 'customer_notifications.v1', 'event_id' => 'trial_1',
                'status' => 'delivered', 'channel' => 'whatsapp', 'future_optional' => ['safe' => true]]),
        ]);
        $installation = Installation::query()->create(['status' => Installation::STATUS_CONNECTED,
            'site_url' => 'https://cms.example.com', 'control_plane_url' => 'https://control.example.com',
            'server_signing_key_encrypted' => 'secret']);
        $notifications = app(CustomerNotifications::class);
        $delivery = $notifications->dispatchAfterCommit($installation,
            new NotificationRequest('trial_1', 'trial_requested', 'de', '+41791234567', true, true, [], '', true));
        $notifications->process($delivery->id);
        $this->assertSame($case['emails'], $sender->sent);
        $this->assertSame($case['status'], $delivery->fresh()->status);
        Http::assertSentCount($case['status'] === 'delivered' ? 2 : 1);
    }
}
