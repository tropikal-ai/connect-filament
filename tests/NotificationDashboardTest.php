<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use Illuminate\Support\Facades\Schema;
use TropikalAI\ConnectFilament\Filament\Resources\InstallationResource\Pages\Dashboard;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Models\NotificationDelivery;

class NotificationDashboardTest extends TestCase
{
    public function test_dashboard_only_exposes_safe_recent_status_for_its_installation(): void
    {
        config()->set('connect-filament.notifications.enabled', true);
        $installation = Installation::query()->create(['site_url' => 'https://cms.example.com']);
        $other = Installation::query()->create(['site_url' => 'https://other.example.com']);
        foreach (range(1, 12) as $id) {
            NotificationDelivery::query()->create(['installation_id' => $installation->id,
                'event_id' => 'trial_'.$id, 'request_hash' => str_repeat('a', 64),
                'request_payload' => ['recipient' => ['phone' => '+41791234567']],
                'context' => ['email' => 'private@example.com'], 'email_claim_token' => 'private-claim',
                'status' => 'delivered', 'channel' => 'whatsapp', 'email_status' => 'pending']);
        }
        NotificationDelivery::query()->create(['installation_id' => $other->id, 'event_id' => 'other_tenant',
            'request_hash' => str_repeat('b', 64), 'request_payload' => [], 'context' => [], 'status' => 'pending']);
        $page = new Dashboard;
        $page->mount();
        $status = $page->notificationStatus();
        $this->assertTrue($status['available']);
        $this->assertCount(10, $status['deliveries']);
        $json = json_encode($status, JSON_THROW_ON_ERROR);
        foreach (['+41791234567', 'private@example.com', 'private-claim', 'other_tenant', 'request_payload', 'context'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $this->assertSame('trial_12', $status['deliveries'][0]['event_id']);
        $html = view('connect-filament::filament.resources.installation-resource.pages.notification-status', ['notifications' => $status])->render();
        $this->assertStringContainsString('trial_12', $html);
        $this->assertStringContainsString('WhatsApp', $html);
        $this->assertStringNotContainsString('other_tenant', $html);
    }

    public function test_disabled_or_unmigrated_installations_render_without_database_failure(): void
    {
        $page = new Dashboard;
        $page->installation = Installation::query()->create(['site_url' => 'https://cms.example.com']);
        Schema::drop('connect_filament_notification_deliveries');
        $this->assertFalse($page->notificationStatus()['enabled']);
        config()->set('connect-filament.notifications.enabled', true);
        $status = $page->notificationStatus();
        $this->assertFalse($status['available']);
        $html = view('connect-filament::filament.resources.installation-resource.pages.notification-status', ['notifications' => $status])->render();
        $this->assertStringContainsString('Update required', $html);
    }
}
