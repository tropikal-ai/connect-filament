<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StagedAsset extends Model
{
    public const STATUS_PREPARED = 'prepared';

    public const STATUS_STAGED = 'staged';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_REJECTED = 'rejected';

    protected $table = 'connect_filament_staged_assets';

    protected $fillable = [
        'public_id',
        'installation_id',
        'prepare_idempotency_key',
        'request_hash',
        'resource_slug',
        'field_name',
        'upload_token_encrypted',
        'status',
        'disk',
        'directory',
        'original_filename',
        'stored_path',
        'mime_type',
        'size_bytes',
        'input_sha256',
        'stored_sha256',
        'expires_at',
        'uploaded_at',
        'committed_at',
    ];

    protected $hidden = ['upload_token_encrypted'];

    protected $casts = [
        'upload_token_encrypted' => 'encrypted',
        'expires_at' => 'datetime',
        'uploaded_at' => 'datetime',
        'committed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $asset): void {
            if (! $asset->public_id) {
                $asset->public_id = 'cfa_'.Str::random(32);
            }
        });
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }
}
