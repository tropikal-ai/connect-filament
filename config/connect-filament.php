<?php

declare(strict_types=1);

return [
    'route_prefix' => env('CONNECT_FILAMENT_ROUTE_PREFIX', 'tropikal-connect'),

    'filament' => [
        'label' => env('CONNECT_FILAMENT_LABEL', 'TROPIKAL Connect'),
        'navigation_label' => env('CONNECT_FILAMENT_NAVIGATION_LABEL', env('CONNECT_FILAMENT_LABEL', 'TROPIKAL Connect')),
        'navigation_group' => env('CONNECT_FILAMENT_NAVIGATION_GROUP', 'Integrations'),
        'navigation_sort' => (int) env('CONNECT_FILAMENT_NAVIGATION_SORT', 90),
        'slug' => env('CONNECT_FILAMENT_SLUG', 'tropikal-connect'),
    ],

    'setup' => [
        'connect_middleware' => ['auth'],
        'after_connect_url' => env('CONNECT_FILAMENT_AFTER_CONNECT_URL'),
    ],

    'oauth' => [
        'authorization_server_url' => env('CONNECT_FILAMENT_AUTHORIZATION_SERVER_URL', ''),
        'client_id' => env('CONNECT_FILAMENT_OAUTH_CLIENT_ID', ''),
        'client_name' => env('CONNECT_FILAMENT_OAUTH_CLIENT_NAME', ''),
        'redirect_path' => env('CONNECT_FILAMENT_OAUTH_REDIRECT_PATH', '/tropikal-connect/oauth/callback'),
        'scopes' => env('CONNECT_FILAMENT_OAUTH_SCOPES', 'example:install'),
        'resource' => env('CONNECT_FILAMENT_OAUTH_RESOURCE', ''),
        'software_id' => env('CONNECT_FILAMENT_OAUTH_SOFTWARE_ID', 'tropikal-ai/connect-filament'),
        'register_path' => env('CONNECT_FILAMENT_OAUTH_REGISTER_PATH', '/oauth/register'),
        'authorize_path' => env('CONNECT_FILAMENT_OAUTH_AUTHORIZE_PATH', '/oauth/authorize'),
        'token_path' => env('CONNECT_FILAMENT_OAUTH_TOKEN_PATH', '/oauth/token'),
        'revocation_path' => env('CONNECT_FILAMENT_OAUTH_REVOCATION_PATH'),
        'timeout_seconds' => (int) env('CONNECT_FILAMENT_OAUTH_TIMEOUT_SECONDS', 10),
    ],

    'control_plane' => [
        'base_url' => env('CONNECT_FILAMENT_CONTROL_PLANE_URL', ''),
        'register_path' => env('CONNECT_FILAMENT_CONTROL_PLANE_REGISTER_PATH', '/api/connect-filament/installations'),
        'disconnect_path' => env('CONNECT_FILAMENT_CONTROL_PLANE_DISCONNECT_PATH', '/api/connect-filament/installations/disconnect'),
        'embed_status_path' => env('CONNECT_FILAMENT_CONTROL_PLANE_EMBED_STATUS_PATH', '/api/connect-filament/embed/active'),
        'timeout_seconds' => (int) env('CONNECT_FILAMENT_CONTROL_PLANE_TIMEOUT_SECONDS', 20),
    ],

    'api' => [
        'prefix' => env('CONNECT_FILAMENT_API_PREFIX', 'tropikal-connect'),
        'signature_tolerance_seconds' => (int) env('CONNECT_FILAMENT_SIGNATURE_TOLERANCE_SECONDS', 300),
        'nonce_cache_seconds' => (int) env('CONNECT_FILAMENT_NONCE_CACHE_SECONDS', 300),
    ],

    'embed' => [
        'enabled' => (bool) env('CONNECT_FILAMENT_EMBED_ENABLED', true),
        'prefix' => env('CONNECT_FILAMENT_EMBED_PREFIX', 'tropikal-connect'),
        'asset_cache_seconds' => (int) env('CONNECT_FILAMENT_EMBED_ASSET_CACHE_SECONDS', 300),
    ],

    'resources' => [],
];
