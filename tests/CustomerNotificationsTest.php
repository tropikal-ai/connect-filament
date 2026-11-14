<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use TropikalAI\Connect\Application\Ports\NonceStore;
use TropikalAI\Connect\Application\SignedRequestVerifier;
use TropikalAI\ConnectFilament\Contracts\NotificationEmailSender;
use TropikalAI\ConnectFilament\Domain\NotificationRequest;
use TropikalAI\ConnectFilament\Jobs\DeliverCustomerNotification;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Models\NotificationDelivery;
use TropikalAI\ConnectFilament\Services\CustomerNotifications;

class CustomerNotificationsTest extends TestCase
{
    private int $emails = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('connect-filament.notifications.enabled', true);
        $test = $this;
        $this->app->instance(NotificationEmailSender::class, new class($test) implements NotificationEmailSender
        {
            public function __construct(private CustomerNotificationsTest $test) {}

            public function send(NotificationDelivery $delivery): void
            {
                $this->test->emailSent();
            }
        });
    }

    public function emailSent(): void
    {
        $this->emails++;
    }

    private function delivery(): NotificationDelivery
    {
        $installation = Installation::query()->create([
            'status' => Installation::STATUS_CONNECTED,
            'site_url' => 'https://cms.example.com',
            'control_plane_url' => 'https://control.example.com',
            'server_signing_key_encrypted' => 'secret',
        ]);

        return app(CustomerNotifications::class)->dispatchAfterCommit($installation,
            new NotificationRequest('trial_123', 'trial_requested', 'de', '+41791234567', true, true, [1 => 'Ada'], 'Training received', true),
            ['booking_id' => 123]);
    }

    private function runDelivery(NotificationDelivery $delivery): void
    {
        (new DeliverCustomerNotification($delivery->id))->handle(app(CustomerNotifications::class));
    }

    private function wireResult(string $status, array $extra = []): array
    {
        return ['version' => 'customer_notifications.v1', 'event_id' => 'trial_123', 'status' => $status, 'channel' => null, ...$extra];
    }

    public function test_dispatch_is_durable_idempotent_and_deferred_until_commit(): void
    {
        DB::beginTransaction();
        $delivery = $this->delivery();
        $this->assertSame(123, $delivery->context['booking_id']);
        $this->assertSame('queued', $delivery->status);
        Queue::assertNothingPushed();
        DB::rollBack();
        $this->assertSame(0, NotificationDelivery::query()->count());
        Http::assertNothingSent();
    }

    public function test_old_app_uses_email_only_before_admission(): void
    {
        Http::fake(['*/capabilities' => Http::response([], 404)]);
        $delivery = $this->delivery();
        $this->runDelivery($delivery);
        $this->runDelivery($delivery);
        $this->assertSame(1, $this->emails);
        $this->assertSame('delivered', $delivery->fresh()->email_status);
        Http::assertSentCount(1);
    }

    public function test_unknown_submit_never_falls_back_to_email(): void
    {
        Http::fake([
            '*/capabilities' => Http::response(['version' => 'customer_notifications.v1', 'supported' => true, 'kinds' => [['kind' => 'trial_requested', 'locales' => ['de'], 'whatsapp' => true, 'sms' => true]]]),
            '*/deliveries' => Http::failedConnection(),
            '*/deliveries/trial_123' => Http::response([], 404),
        ]);
        $delivery = $this->delivery();
        $this->runDelivery($delivery);
        $this->runDelivery($delivery);
        $this->assertSame(0, $this->emails);
        $this->assertNotNull($delivery->fresh()->submission_started_at);
        $this->assertSame('uncertain', $delivery->fresh()->status);
    }

    public function test_email_is_claimed_before_sending_and_completed_once(): void
    {
        Http::fake([
            '*/capabilities' => Http::response(['version' => 'customer_notifications.v1', 'supported' => true, 'kinds' => [['kind' => 'trial_requested', 'locales' => ['de'], 'whatsapp' => true, 'sms' => true]]]),
            '*/deliveries' => Http::response($this->wireResult('email_required')),
            '*/email-claim' => Http::response($this->wireResult('email_claimed', ['email_claim_token' => 'claim-secret'])),
            '*/email-complete' => Http::response($this->wireResult('email_delivered', ['channel' => 'email'])),
        ]);
        $delivery = $this->delivery();
        $this->runDelivery($delivery);
        $this->runDelivery($delivery);
        $this->assertSame(1, $this->emails);
        $this->assertSame('email_delivered', $delivery->fresh()->status);
        $this->assertArrayNotHasKey('email_claim_token', $delivery->fresh()->safeStatus());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/email-complete') && $request['claim_token'] === 'claim-secret');
    }

    public function test_crashed_email_send_is_uncertain_and_never_repeated(): void
    {
        Http::fake(['*/email-complete' => Http::response($this->wireResult('uncertain'))]);
        $delivery = $this->delivery();
        $delivery->forceFill(['submission_started_at' => now(), 'email_status' => 'sending', 'email_claim_token' => 'claim-secret'])->save();
        $this->runDelivery($delivery);
        $this->assertSame(0, $this->emails);
        $this->assertSame('uncertain', $delivery->fresh()->email_status);
        Http::assertSent(fn ($request) => $request['outcome'] === 'uncertain');
    }

    public function test_disabled_hosts_do_not_admit_requests(): void
    {
        config()->set('connect-filament.notifications.enabled', false);
        $this->expectException(\LogicException::class);
        $this->delivery();
    }

    public function test_commit_queues_once_and_reusing_event_with_different_content_is_rejected(): void
    {
        DB::beginTransaction();
        $delivery = $this->delivery();
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushed(DeliverCustomerNotification::class, 1);
        $installation = Installation::query()->findOrFail($delivery->installation_id);
        $request = new NotificationRequest('trial_123', 'trial_requested', 'de', '+41791234567', true, true, [1 => 'Ada'], 'Training received', true);
        $same = app(CustomerNotifications::class)->dispatchAfterCommit($installation, $request, ['booking_id' => 123]);
        $this->assertSame($delivery->id, $same->id);
        Queue::assertPushed(DeliverCustomerNotification::class, 1);
        $this->expectException(\LogicException::class);
        app(CustomerNotifications::class)->dispatchAfterCommit($installation, $request, ['booking_id' => 999]);
    }

    public function test_pending_delivery_polls_without_resubmission_and_signs_the_exact_body(): void
    {
        Http::fake([
            '*/capabilities' => Http::response(['version' => 'customer_notifications.v1', 'supported' => true, 'kinds' => [['kind' => 'trial_requested', 'locales' => ['de'], 'whatsapp' => true, 'sms' => true]]]),
            '*/deliveries' => Http::response($this->wireResult('pending', ['channel' => 'whatsapp'])),
            '*/deliveries/trial_123' => Http::response($this->wireResult('delivered', ['channel' => 'whatsapp'])),
        ]);
        $delivery = $this->delivery();
        $this->runDelivery($delivery);
        $this->runDelivery($delivery);
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame(0, $this->emails);
        Http::assertSentCount(3);
        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'POST') {
                return false;
            }
            $nonces = new class implements NonceStore
            {
                public function claim(string $installationId, string $nonce, int $ttlSeconds): bool
                {
                    return true;
                }
            };
            (new SignedRequestVerifier($nonces))->verify('secret', Installation::query()->first()->public_id,
                'POST', '/api/connect-filament/notifications/v1/deliveries', '', $request->body(), $request->headers());
            $this->assertSame('Ada', $request['variables']['1']);
            $this->assertTrue($request['permissions']['email']);

            return true;
        });
    }

    public function test_wrong_version_and_claim_timeout_cannot_send_email(): void
    {
        Http::fake([
            '*/capabilities' => Http::sequence()
                ->push(['version' => 'customer_notifications.v2', 'supported' => true])
                ->push(['version' => 'customer_notifications.v1', 'supported' => true, 'kinds' => [['kind' => 'trial_requested', 'locales' => ['de']]]]),
            '*/deliveries' => Http::response($this->wireResult('email_required')),
            '*/email-claim' => Http::failedConnection(),
            '*/deliveries/trial_123' => Http::response($this->wireResult('email_claimed')),
        ]);
        $delivery = $this->delivery();
        $this->runDelivery($delivery);
        $this->assertNull($delivery->fresh()->submission_started_at);
        $this->assertSame(0, $this->emails);
        $this->runDelivery($delivery);
        $this->runDelivery($delivery);
        $this->assertSame('uncertain', $delivery->fresh()->status);
        $this->assertSame(0, $this->emails);
    }

    public function test_no_email_target_never_invokes_host_and_an_active_lease_prevents_advancement(): void
    {
        Http::fake(['*/capabilities' => Http::response([], 404)]);
        $delivery = $this->delivery();
        $payload = $delivery->request_payload;
        $payload['permissions']['email'] = false;
        $delivery->forceFill(['request_payload' => $payload, 'processing_until' => now()->addMinute()])->save();
        $this->runDelivery($delivery);
        Http::assertNothingSent();
        $delivery->forceFill(['processing_until' => null])->save();
        $this->runDelivery($delivery);
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame(0, $this->emails);
    }

    public function test_completion_timeout_retries_only_receipt_and_host_exception_never_resends(): void
    {
        Http::fake(['*/email-complete' => Http::sequence()
            ->push([], 503)
            ->push($this->wireResult('email_delivered', ['channel' => 'email']))]);
        $delivery = $this->delivery();
        $delivery->forceFill(['submission_started_at' => now(), 'email_claim_token' => 'claim-secret'])->save();
        $this->runDelivery($delivery);
        $this->assertSame('delivered', $delivery->fresh()->email_status);
        $this->runDelivery($delivery);
        $this->assertSame(1, $this->emails);
        $this->assertSame('email_delivered', $delivery->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_host_email_exception_is_terminal_uncertainty(): void
    {
        Http::fake(['*/capabilities' => Http::response([], 404)]);
        $this->app->instance(NotificationEmailSender::class, new class implements NotificationEmailSender
        {
            public function send(NotificationDelivery $delivery): void
            {
                throw new \RuntimeException('SMTP outcome unknown');
            }
        });
        $delivery = $this->delivery();
        $this->runDelivery($delivery);
        $this->runDelivery($delivery);
        $this->assertSame('uncertain', $delivery->fresh()->email_status);
        $this->assertSame('uncertain', $delivery->fresh()->status);
        Http::assertSentCount(1);
        $raw = DB::table('connect_filament_notification_deliveries')->first();
        $this->assertStringNotContainsString('+41791234567', $raw->request_payload);
        $this->assertStringNotContainsString('booking_id', $raw->context);
    }

    public function test_unmapped_kind_falls_back_before_admission(): void
    {
        Http::fake(['*/capabilities' => Http::response(['version' => 'customer_notifications.v1', 'supported' => true, 'kinds' => []])]);
        $delivery = $this->delivery();
        $this->runDelivery($delivery);
        $this->assertSame(1, $this->emails);
        $this->assertNull($delivery->fresh()->submission_started_at);
        Http::assertSentCount(1);
    }

    public function test_explicit_unsupported_admission_is_safe_for_legacy_email(): void
    {
        Http::fake([
            '*/capabilities' => Http::response(['version' => 'customer_notifications.v1', 'supported' => true, 'kinds' => [['kind' => 'trial_requested', 'locales' => ['de']]]]),
            '*/deliveries' => Http::response($this->wireResult('unsupported')),
        ]);
        $delivery = $this->delivery();
        $this->runDelivery($delivery);
        $this->assertSame(1, $this->emails);
        $this->assertSame('email_delivered', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->submission_started_at);
        Http::assertSentCount(2);
    }

    public function test_capability_network_failure_uses_email_without_admitting_remote_delivery(): void
    {
        Http::fake(['*/capabilities' => Http::failedConnection()]);
        $delivery = $this->delivery();
        $this->runDelivery($delivery);
        $this->runDelivery($delivery);
        $this->assertSame(1, $this->emails);
        $this->assertNull($delivery->fresh()->submission_started_at);
        $this->assertSame('email_delivered', $delivery->fresh()->status);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_phone_only_capability_outage_retries_without_email_or_admission(): void
    {
        Http::fake(['*/capabilities' => Http::response([], 503)]);
        $delivery = $this->delivery();
        $payload = $delivery->request_payload;
        $payload['permissions']['email'] = false;
        $delivery->forceFill(['request_payload' => $payload])->save();
        $this->assertTrue(app(CustomerNotifications::class)->process($delivery->id));
        $this->assertSame('queued', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->submission_started_at);
        $this->assertSame(0, $this->emails);
    }

    public function test_deleted_deliveries_are_not_requeued(): void
    {
        $delivery = $this->delivery();
        $delivery->delete();
        $this->assertFalse(app(CustomerNotifications::class)->process($delivery->id));
        Http::assertNothingSent();
    }
}
