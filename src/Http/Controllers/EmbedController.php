<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use TropikalAI\ConnectFilament\Models\Installation;

class EmbedController extends Controller
{
    public function widget(): Response
    {
        $prefix = trim((string) config('connect-filament.embed.prefix', 'tropikal-connect'), '/');
        $script = "(() => { fetch('/{$prefix}/embed/info', { credentials: 'same-origin' }); })();";

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age='.(int) config('connect-filament.embed.asset_cache_seconds', 300),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function info(Request $request): JsonResponse
    {
        $installation = Installation::query()->first();
        if (! $installation) {
            return response()->json(['status' => Installation::STATUS_NOT_CONNECTED]);
        }

        return response()->json($installation->safeStatus())
            ->header('Cache-Control', 'no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
