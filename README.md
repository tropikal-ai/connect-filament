# TROPIKAL Connect for Filament

Laravel + Filament integration for TROPIKAL Connect.

`tropikal-ai/connect-filament` provides the Filament plugin, OAuth setup controller, encrypted installation model, Eloquent business-object discovery, explicit read/write/delete grants, signed resource API, audit logging, and optional public embed proxy endpoints. Protocol primitives live in `tropikal-ai/connect`.

## Requirements

- PHP 8.2 or newer
- Laravel 11 or 12
- Filament 3
- Composer 2

Filament 4 or newer is not claimed by this package.

## Install

```bash
composer require tropikal-ai/connect-filament
php artisan connect-filament:install
php artisan migrate
```

For clone-based development inside an app, add both local packages as path repositories:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "shared/connect",
            "options": {
                "symlink": true,
                "versions": {
                    "tropikal-ai/connect": "0.1.0"
                }
            }
        },
        {
            "type": "path",
            "url": "shared/connect-filament",
            "options": {
                "symlink": true,
                "versions": {
                    "tropikal-ai/connect-filament": "0.1.0"
                }
            }
        }
    ],
    "require": {
        "tropikal-ai/connect-filament": "^0.1"
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

## Filament Plugin

Register the plugin in your Filament panel provider:

```php
use Filament\Panel;
use TropikalAI\ConnectFilament\ConnectFilamentPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugin(ConnectFilamentPlugin::make());
}
```

## OAuth One-Click Setup

Configure endpoints for the environment. Public examples use placeholder hosts:

```env
APP_URL=https://cms.example.com
CONNECT_FILAMENT_AUTHORIZATION_SERVER_URL=https://auth.example.com
CONNECT_FILAMENT_CONTROL_PLANE_URL=https://control.example.com
```

Open the Filament navigation item labeled `TROPIKAL Connect` and click `Connect`. The package starts OAuth authorization code with PKCE, validates callback state and exact redirect URI, exchanges the code server-side, stores credentials encrypted, and registers the installation with a safe payload.

OAuth PKCE is the only supported setup path. Administrators never paste connection ids, raw tokens, signing credentials, endpoint credentials, or other secrets into the UI. There is no token-paste setup path, no copied-secret setup path, and no browser-visible credential path.

## Business Object Discovery

Discovery is enabled by default, but access is opt-in. Empty grants expose nothing.

The package discovers safe Eloquent business-object candidates, excludes framework/internal/auth/security models, removes secret-shaped fields, and shows three explicit grants in Filament:

- Read
- Write
- Delete

Read grants create list/get capabilities. Write grants create create/update capabilities. Delete grants create a destructive delete capability that requires confirmation.
List capabilities support `page`, `per_page`, `limit`, `search`, and exact filters for safe readable scalar fields such as `slug` or `category`.

Optional discovery configuration:

```php
'discovery' => [
    'enabled' => true,
    'included_model_namespaces' => [
        'App\\Models\\',
    ],
    'excluded_model_classes' => [],
    'max_records_per_list_response' => 100,
],
```

After connecting, open `TROPIKAL Connect` in Filament and enable only the access needed for each discovered business object. Granted capabilities sync to the private control plane and can be used by website owner chat and automation runtimes through the same capability contract.

## Resource Declaration Example

Discovery can be supplemented with explicit resources in `config/connect-filament.php`:

```php
'resources' => [
    'research_posts' => [
        'label' => 'Research Posts',
        'model' => App\Models\ResearchPost::class,
        'identifier' => 'id',
        'fields' => [
            'title' => ['label' => 'Title', 'readable' => true, 'writable' => true],
            'status' => ['label' => 'Status', 'readable' => true, 'writable' => true],
            'published_at' => ['label' => 'Published at', 'readable' => true, 'writable' => false],
        ],
    ],
],
```

Only declared readable fields are returned. Only declared writable fields are accepted.

## Security Model

- Setup routes require authenticated Filament/admin access.
- The OAuth callback validates state, PKCE, expiry, host, and exact redirect URI.
- OAuth, redirect, site, and control-plane URLs must use HTTPS outside localhost development.
- Refresh credentials, PKCE verifiers, and server signing credentials use Laravel encrypted casts.
- Server-to-server calls use short-lived signed requests from `tropikal-ai/connect`.
- Signatures cover method, path, normalized query string, timestamp, nonce, installation id, and body hash.
- Nonces are claimed atomically through the Laravel cache.
- Resource reads project declared fields only.
- Resource lists support pagination, text search, and exact filters over safe readable scalar fields only.
- Resource writes reject unknown or unsafe fields and return structured 400/422 responses for expected input problems.
- Delete grants are explicit destructive capabilities and require confirmation.
- Eloquent discovery excludes secret-shaped fields before grants can be enabled.
- Write grants do not expose delete.
- Public browser payloads are checked recursively for secret-shaped keys.
- No secrets are returned to browsers.

See [`docs/security/threat-model.md`](docs/security/threat-model.md) for the release-candidate threat model.

## Troubleshooting

- `403` on `/tropikal-connect/oauth/connect`: the current user is not authenticated for the configured setup middleware.
- Callback validation failed: the authorization server callback URL must exactly match `APP_URL` plus the configured callback path, unless `CONNECT_FILAMENT_OAUTH_REDIRECT_BASE_URL` is set.
- Signed API `401`: the signature, query string, body hash, timestamp, nonce, or installation id did not match.
- A discovered model is missing: check `included_model_namespaces`, `excluded_model_classes`, and the model's table connection.
- A field is missing from a business object: secret-shaped, hidden, guarded, relation, and unsafe fields are excluded by default.
- A write returns `422`: the input did not match the explicit writable field schema.

## Private Server Boundary

Server and control-plane internals are intentionally absent from this package. Public examples use `example.com` endpoints only.
