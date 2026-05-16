<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'connect_filament_audit_logs';

    protected $fillable = [
        'installation_id',
        'resource_slug',
        'record_id',
        'action',
        'changes_json',
        'ip_address',
    ];

    protected $casts = [
        'changes_json' => 'array',
        'created_at' => 'datetime',
    ];
}
