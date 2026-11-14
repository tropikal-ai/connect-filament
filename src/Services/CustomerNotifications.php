<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use TropikalAI\Connect\Domain\Security\SignedRequest;
use TropikalAI\ConnectFilament\Contracts\NotificationEmailSender;
use TropikalAI\ConnectFilament\Domain\NotificationRequest;
use TropikalAI\ConnectFilament\Jobs\DeliverCustomerNotification;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Models\NotificationDelivery;

class CustomerNotifications
{
    public const VERSION = 'customer_notifications.v1';

    private const PATH = '/api/connect-filament/notifications/v1';

    public function dispatchAfterCommit(Installation $installation, NotificationRequest $request, array $context = []): NotificationDelivery
    {
        if (! config('connect-filament.notifications.enabled', false) || ! app()->bound(NotificationEmailSender::class)) {
            throw new \LogicException('Customer notifications require explicit enablement and a host email sender.');
        }
        if (! $installation->isApiReady()) {
            throw new \LogicException('Customer notifications require a connected installation.');
        }
        $payload = $request->payload();
        $hash = hash('sha256', json_encode([$payload, $context], JSON_THROW_ON_ERROR));
        $delivery = NotificationDelivery::query()->firstOrCreate(
            ['installation_id' => $installation->getKey(), 'event_id' => $request->eventId],
            ['request_hash' => $hash, 'request_payload' => $payload, 'context' => $context, 'status' => 'queued', 'email_status' => 'pending'],
        );
        if (! hash_equals($delivery->request_hash, $hash)) {
            throw new \LogicException('The notification event already exists with different content.');
        }
        if ($delivery->wasRecentlyCreated) {
            DB::afterCommit(fn () => DeliverCustomerNotification::dispatch($delivery->id));
        }

        return $delivery;
    }

    public function process(int $id): bool
    {
        $lease = NotificationDelivery::query()->whereKey($id)
            ->where(fn ($query) => $query->whereNull('processing_until')->orWhere('processing_until', '<=', now()))
            ->update(['processing_until' => now()->addSeconds(120)]);
        if ($lease !== 1) {
            return NotificationDelivery::query()->whereKey($id)->exists();
        }
        try {
            return $this->advance($id);
        } finally {
            NotificationDelivery::query()->whereKey($id)->update(['processing_until' => null]);
        }
    }

    private function advance(int $id): bool
    {
        $delivery = NotificationDelivery::query()->findOrFail($id);
        if (in_array($delivery->status, ['delivered', 'email_delivered', 'uncertain', 'failed'], true)) {
            return false;
        }
        $installation = Installation::query()->find($delivery->installation_id);
        if ($installation === null || ! $installation->isApiReady() || ! config('connect-filament.notifications.enabled', false)) {
            return false;
        }
        try {
            if ($delivery->email_status === 'sending') {
                $delivery->forceFill(['email_status' => 'uncertain'])->save();
            }
            if (in_array($delivery->email_status, ['delivered', 'uncertain'], true)) {
                return $this->completeEmail($delivery, $installation);
            }
            if ($delivery->email_claim_token !== null) {
                return $this->sendEmail($delivery, $installation);
            }
            $submitting = false;
            if ($delivery->submission_started_at === null) {
                try {
                    $capabilities = $this->call($installation, 'GET', '/capabilities');
                } catch (Throwable) {
                    return ($delivery->request_payload['permissions']['email'] ?? false)
                        ? $this->legacyEmail($delivery, $installation) : true;
                }
                if (in_array($capabilities->status(), [404, 405], true)
                    || ($capabilities->successful() && ($capabilities->json('supported') === false))) {
                    return $this->legacyEmail($delivery, $installation);
                }
                if (! $capabilities->successful()) {
                    if (in_array($capabilities->status(), [401, 403], true)) {
                        Log::warning('Customer notification connection requires configuration review.',
                            ['event_id' => $delivery->event_id, 'http_status' => $capabilities->status()]);
                    }

                    return ($delivery->request_payload['permissions']['email'] ?? false)
                        ? $this->legacyEmail($delivery, $installation) : true;
                }
                if ($capabilities->json('version') !== self::VERSION || $capabilities->json('supported') !== true) {
                    return true;
                }
                $kinds = $capabilities->json('kinds');
                if (! is_array($kinds)) {
                    return true;
                }
                $supported = collect($kinds)->contains(fn ($kind) => is_array($kind)
                    && ($kind['kind'] ?? null) === $delivery->request_payload['kind']
                    && in_array($delivery->request_payload['locale'], (array) ($kind['locales'] ?? []), true));
                if (! $supported) {
                    return $this->legacyEmail($delivery, $installation);
                }
                $delivery->forceFill(['submission_started_at' => now(), 'status' => 'pending'])->save();
                $payload = $delivery->request_payload;
                $payload['variables'] = (object) $payload['variables'];
                $submitting = true;
                $response = $this->call($installation, 'POST', '/deliveries', $payload);
            } else {
                $response = $this->call($installation, 'GET', '/deliveries/'.rawurlencode($delivery->event_id));
            }
            if ($response->status() === 404) {
                $delivery->forceFill(['status' => 'uncertain'])->save();

                return false;
            }
            if (! $this->applyResult($delivery, $response)) {
                return true;
            }
            if ($submitting && $delivery->status === 'unsupported') {
                $delivery->forceFill(['submission_started_at' => null])->save();

                return $this->legacyEmail($delivery, $installation);
            }
            if ($delivery->status === 'email_required') {
                if (! ($delivery->request_payload['permissions']['email'] ?? false)) {
                    $delivery->forceFill(['status' => 'failed'])->save();

                    return false;
                }
                $claim = $this->call($installation, 'POST', '/deliveries/'.rawurlencode($delivery->event_id).'/email-claim', []);
                if (! $this->applyResult($delivery, $claim)) {
                    return true;
                }
                $token = $claim->json('email_claim_token');
                if ($delivery->status !== 'email_claimed' || ! is_string($token) || $token === '') {
                    $delivery->forceFill(['status' => 'uncertain'])->save();

                    return false;
                }
                $delivery->forceFill(['email_claim_token' => $token])->save();

                return $this->sendEmail($delivery, $installation);
            }
            if ($delivery->status === 'email_claimed') {
                $delivery->forceFill(['status' => 'uncertain'])->save();

                return false;
            }

            return in_array($delivery->status, ['accepted', 'pending'], true);
        } catch (Throwable) {
            return true;
        }
    }

    private function sendEmail(NotificationDelivery $delivery, Installation $installation): bool
    {
        $claimed = NotificationDelivery::query()->whereKey($delivery->id)->where('email_status', 'pending')
            ->update(['email_status' => 'sending']);
        if ($claimed !== 1) {
            return true;
        }
        try {
            app(NotificationEmailSender::class)->send($delivery->refresh());
            $delivery->forceFill(['email_status' => 'delivered'])->save();
        } catch (Throwable) {
            $delivery->forceFill(['email_status' => 'uncertain'])->save();
            Log::warning('Customer notification email outcome is uncertain.', ['event_id' => $delivery->event_id]);
        }

        return $this->completeEmail($delivery, $installation);
    }

    private function legacyEmail(NotificationDelivery $delivery, Installation $installation): bool
    {
        $delivery->forceFill(['status' => 'unsupported'])->save();
        if (! ($delivery->request_payload['permissions']['email'] ?? false)) {
            $delivery->forceFill(['status' => 'failed'])->save();

            return false;
        }

        return $this->sendEmail($delivery, $installation);
    }

    private function completeEmail(NotificationDelivery $delivery, Installation $installation): bool
    {
        if ($delivery->submission_started_at === null) {
            $delivery->forceFill(['status' => $delivery->email_status === 'delivered' ? 'email_delivered' : 'uncertain', 'channel' => 'email'])->save();

            return false;
        }
        $response = $this->call($installation, 'POST', '/deliveries/'.rawurlencode($delivery->event_id).'/email-complete',
            ['claim_token' => $delivery->email_claim_token, 'outcome' => $delivery->email_status]);

        $expected = $delivery->email_status === 'delivered' ? 'email_delivered' : 'uncertain';
        if ($response->json('status') !== $expected) {
            return true;
        }

        return ! $this->applyResult($delivery, $response);
    }

    private function applyResult(NotificationDelivery $delivery, Response $response): bool
    {
        $statuses = ['accepted', 'pending', 'delivered', 'email_required', 'email_claimed', 'email_delivered', 'uncertain', 'unsupported', 'failed'];
        if (! $response->successful() || $response->json('version') !== self::VERSION
            || $response->json('event_id') !== $delivery->event_id || ! in_array($response->json('status'), $statuses, true)
            || ! in_array($response->json('channel'), [null, 'whatsapp', 'sms', 'email'], true)) {
            return false;
        }
        $delivery->forceFill(['status' => $response->json('status'), 'channel' => $response->json('channel')])->save();

        return true;
    }

    private function call(Installation $installation, string $method, string $suffix, ?array $payload = null): Response
    {
        $path = self::PATH.$suffix;
        $body = $payload === null ? '' : json_encode($payload === [] ? (object) [] : $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return Http::withHeaders([...SignedRequest::headers(
            (string) $installation->server_signing_key_encrypted, (string) $installation->public_id, $method, $path, '', $body,
        ), 'Accept' => 'application/json', 'Content-Type' => 'application/json'])
            ->timeout(15)->withBody($body, 'application/json')
            ->send($method, rtrim((string) $installation->control_plane_url, '/').$path);
    }
}
