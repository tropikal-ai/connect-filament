<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OperationReceipt extends Model
{
    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_FAILED_NO_EFFECT = 'failed_no_effect';

    protected $table = 'connect_filament_operation_receipts';

    protected $fillable = [
        'public_id',
        'installation_id',
        'idempotency_key',
        'operation',
        'resource_slug',
        'request_hash',
        'status',
        'result_ref',
        'response_status',
        'response_json',
        'completed_at',
    ];

    protected $casts = [
        'response_json' => 'array',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $receipt): void {
            if (! $receipt->public_id) {
                $receipt->public_id = 'cfr_'.Str::random(32);
            }
        });
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }
}
