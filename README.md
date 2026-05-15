# TROPIKAL Connect for Filament

Laravel + Filament integration for TROPIKAL Connect.

This package provides the Filament plugin, OAuth setup controller, Eloquent business-object discovery, explicit read/write grants, signed resource API, encrypted installation model, audit logging, and optional public embed status endpoint. Protocol primitives live in `tropikal-ai/connect`.

## Requirements

- PHP 8.2 or newer
- Laravel 11 or 12
- Filament 3

## Install

```bash
composer require tropikal-ai/connect-filament
php artisan vendor:publish --tag=connect-filament-config
php artisan migrate
```

For clone-based development inside an app:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "shared/connect",
            "options": { "symlink": true }
        },
        {
            "type": "path",
            "url": "shared/connect-filament",
            "options": { "symlink": true }
        }
    ]
}
```

## Filament Plugin

```php
use Filament\Panel;
use TropikalAI\ConnectFilament\ConnectFilamentPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugins([
            ConnectFilamentPlugin::make(),
        ]);
}
```

## OAuth Setup

Configure placeholder endpoints for local development:

```env
APP_URL=https://cms.example.com
CONNECT_FILAMENT_AUTHORIZATION_SERVER_URL=https://auth.example.com
CONNECT_FILAMENT_CONTROL_PLANE_URL=https://control.example.com
```

Open the Filament navigation item labeled `TROPIKAL Connect` and click `Connect`. OAuth PKCE is the only supported setup path. Administrators never paste raw credentials into the UI.

## Business Object Discovery

Discovery is on by default, but access is opt-in. The package discovers safe Eloquent business-object candidates, excludes auth/internal/security models, removes secret-shaped fields, and shows two grants in Filament:

- Read
- Write

The default grant state is empty, which exposes nothing. Write grants create `create` and `update` capabilities only. Delete is not exposed.

Optional discovery configuration:

```php
'discovery' => [
    'enabled' => true,
    'included_model_namespaces' => [
        'App\\Models\\',
    ],
    'excluded_model_classes' => [],
],
```

After OAuth, open `TROPIKAL Connect` in Filament and check `Read` and/or `Write` for each discovered business object. Granted capabilities sync to the private control plane and can be used as website owner chat tools or Ops workflow Functions through the same capability contract.

## Security Model

- Refresh credentials, PKCE verifiers, and server signing credentials use Laravel encrypted casts.
- Server-to-server calls use short-lived signed requests from `tropikal-ai/connect`.
- Signatures cover method, path, normalized query string, timestamp, nonce, installation id, and body hash.
- Resource reads project declared fields only.
- Resource writes reject unknown fields.
- Eloquent discovery excludes secret-shaped fields before grants can be enabled.
- Write grants do not expose delete.
- Public browser payloads are checked recursively for secret-shaped keys.
- No secrets are returned to browsers.

## Troubleshooting

- A 403 on `/tropikal-connect/oauth/connect` means the current user is not authenticated for the configured setup middleware.
- A callback error usually means the redirect URL in the authorization server does not exactly match `APP_URL` plus the configured callback path.
- A signed API 401 means the request signature, query string, body hash, timestamp, nonce, or installation id did not match.

## Private Server Boundary

Server and control-plane internals are intentionally absent from this package. Public examples use `example.com` endpoints only.
