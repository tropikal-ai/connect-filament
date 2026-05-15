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

    'site' => [
        'url' => env('CONNECT_FILAMENT_SITE_URL', env('APP_URL')),
    ],

    'oauth' => [
        'authorization_server_url' => env('CONNECT_FILAMENT_AUTHORIZATION_SERVER_URL', ''),
        'client_id' => env('CONNECT_FILAMENT_OAUTH_CLIENT_ID', ''),
        'client_name' => env('CONNECT_FILAMENT_OAUTH_CLIENT_NAME', ''),
        'redirect_base_url' => env('CONNECT_FILAMENT_OAUTH_REDIRECT_BASE_URL', env('APP_URL')),
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
        'embed_proxy_path' => env('CONNECT_FILAMENT_CONTROL_PLANE_EMBED_PROXY_PATH', '/api/connect-filament/embed'),
        'embed_asset_path' => env('CONNECT_FILAMENT_CONTROL_PLANE_EMBED_ASSET_PATH', '/embed'),
        'timeout_seconds' => (int) env('CONNECT_FILAMENT_CONTROL_PLANE_TIMEOUT_SECONDS', 20),
    ],

    'api' => [
        'prefix' => env('CONNECT_FILAMENT_API_PREFIX', 'tropikal-connect'),
        'base_url' => env('CONNECT_FILAMENT_API_BASE_URL'),
        'signature_tolerance_seconds' => (int) env('CONNECT_FILAMENT_SIGNATURE_TOLERANCE_SECONDS', 300),
        'nonce_cache_seconds' => (int) env('CONNECT_FILAMENT_NONCE_CACHE_SECONDS', 300),
    ],

    'embed' => [
        'enabled' => (bool) env('CONNECT_FILAMENT_EMBED_ENABLED', true),
        'prefix' => env('CONNECT_FILAMENT_EMBED_PREFIX', 'tropikal-connect'),
        'base_url' => env('CONNECT_FILAMENT_EMBED_BASE_URL'),
        'asset_cache_seconds' => (int) env('CONNECT_FILAMENT_EMBED_ASSET_CACHE_SECONDS', 300),
        'asset_rewrite_prefixes' => [],
    ],

    'discovery' => [
        'enabled' => (bool) env('CONNECT_FILAMENT_DISCOVERY_ENABLED', true),
        'model_classes' => [],
        'included_model_namespaces' => [
            'App\\Models\\',
        ],
        'excluded_model_classes' => [],
        'excluded_field_patterns' => [
            '/password/i',
            '/token/i',
            '/secret/i',
            '/credential/i',
            '/auth/i',
            '/hash/i',
            '/session/i',
            '/remember_token/i',
            '/api_key/i',
            '/access_token/i',
            '/refresh_token/i',
            '/private_key/i',
            '/public_key/i',
        ],
        'max_records_per_list_response' => (int) env('CONNECT_FILAMENT_MAX_RECORDS_PER_LIST', 100),
    ],

    'resources' => [],
];
