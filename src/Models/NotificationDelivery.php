<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $installation_id
 * @property string $event_id
 * @property string $request_hash
 * @property array $request_payload
 * @property array $context
 * @property string $status
 * @property string|null $channel
 * @property string $email_status
 * @property string|null $email_claim_token
 * @property Carbon|null $submission_started_at
 */
class NotificationDelivery extends Model
{
    protected $table = 'connect_filament_notification_deliveries';

    protected $guarded = [];

    protected $hidden = ['request_payload', 'context', 'email_claim_token'];

    protected $casts = [
        'request_payload' => 'encrypted:array', 'context' => 'encrypted:array',
        'email_claim_token' => 'encrypted', 'submission_started_at' => 'datetime',
    ];

    public function safeStatus(): array
    {
        return ['event_id' => $this->event_id, 'status' => $this->status,
            'channel' => $this->channel, 'email_status' => $this->email_status];
    }
}
