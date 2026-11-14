<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use Illuminate\Http\JsonResponse;

final class PublicChatMemberAuthentication
{
    public function requiredResponse(): JsonResponse
    {
        $path = config('connect-filament.public_chat.member_login_path');
        $authentication = is_string($path) && preg_match('/\A\/[A-Za-z0-9][A-Za-z0-9\/_-]{0,199}\z/', $path) === 1
            ? ['authentication' => ['schema' => 'member_login.v1', 'login_path' => $path]] : [];

        return response()->json(['error' => 'member_authentication_required', ...$authentication], 401)
            ->header('Cache-Control', 'no-store');
    }
}
