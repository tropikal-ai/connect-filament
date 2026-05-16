<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use TropikalAI\ConnectFilament\Models\AuditLog;
use TropikalAI\ConnectFilament\Models\Installation;

class AuditLogger
{
    public function record(Request $request, Installation $installation, string $resourceSlug, mixed $recordId, string $action, array $changes): void
    {
        try {
            AuditLog::query()->create([
                'installation_id' => $installation->getKey(),
                'resource_slug' => $resourceSlug,
                'record_id' => $recordId !== null ? (string) $recordId : null,
                'action' => $action,
                'changes_json' => $changes,
                'ip_address' => $request->ip(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Connect Filament audit log failed.', ['message' => $exception->getMessage()]);
        }
    }
}
