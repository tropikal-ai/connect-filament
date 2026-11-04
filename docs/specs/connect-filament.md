# Connect Filament Spec

Status: release candidate

## Problem

Laravel Filament projects need a one-click way to connect a site to a private control plane without exposing server credentials to administrators or browsers.

## Goals

- Provide the Laravel service provider, Filament plugin, routes, migrations, encrypted models, and resource API.
- Use OAuth authorization code with PKCE as the only setup path.
- Store refresh credentials, PKCE verifiers, and server signing credentials with encrypted casts.
- Verify server-to-server requests with the shared `tropikal-ai/connect` primitives.
- Discover Eloquent business-object candidates, but expose no resources until an admin grants read, write, or delete access.
- Keep public status and embed payloads free of secret-shaped keys.

## Non-Goals

- No pasted credential setup.
- No private control-plane implementation.
- No WordPress, Shopify, or non-Filament integration code.
- No production endpoint defaults.

## Setup Flow

1. The administrator installs the package and registers the Filament plugin.
2. The administrator opens TROPIKAL Connect and clicks Connect.
3. The package registers or reuses an OAuth public client, generates state and PKCE, then redirects to authorization.
4. The callback validates state, PKCE, expiry, host, and exact redirect URI.
5. The package exchanges the authorization code, stores the refresh credential encrypted, registers the installation with a safe payload, and stores server signing credentials encrypted.
6. The administrator grants read, write, and/or delete access per discovered business object.
7. Capability schema and embed state are synchronized from private server responses.

## Resource Boundary

Discovery finds Eloquent candidates and removes auth/internal/security models plus secret-shaped fields. Empty grants expose nothing. Read grants create list/get capabilities. List capabilities advertise pagination, search, and exact filters for safe readable scalar fields. Write grants create create/update capabilities. Delete grants create destructive delete capabilities that require confirmation. Reads project declared fields only. Writes reject undeclared fields and set attributes explicitly instead of passing arbitrary payloads to mass assignment.

The Filament page shows exactly three grant controls per business object: Read, Write, and Delete. Granted capabilities are source-neutral and can be used by website owner chat or automation runtimes.

## Security Model

All API requests from the private control plane must include a signed assertion covering method, path, normalized query string, timestamp, nonce, installation id, and body hash. Nonces are claimed through the Laravel cache with an atomic add operation.

The package never trusts browser-submitted account metadata and does not decode identity claims as authority. Account metadata comes from the private control plane after token exchange.

Embed requests additionally authenticate an `embed-chat` v1 context through
Connect Core's `SignedRequestContext` (requires Core 0.1.4 or later). The proof
binds the context to the already signed request, including its origin, body,
nonce, and route. Its exact fields are `v`, `kind`, `visitor_history_token`,
`actor_identity`, `actor_context_sha256`, and `session_id`.

For signed-in chat, the host's `PublicChatActorResolver` supplies the actor.
The connector derives a stable keyed identity from the installation, actor
type, and actor id, and binds it to the SHA-256 digest of the session-bound
opaque actor permit. Permit rotation on retries does not change the stable
identity. Different members, actor types, and installations have different
identities. Anonymous requests carry empty actor identity/session fields and
the empty permit's digest. History identity comes only from the valid
first-party HttpOnly cookie. Browser-supplied identity and proof headers are
never forwarded. Identity resolution/signing failures return the safe
temporary-unavailability response without sending an anonymous fallback.

Browser-facing embed proxy endpoints are public, tokenless same-origin
endpoints. They must run through Laravel's `api` middleware, not the `web`
session stack, because visitor chat is authenticated by a server-to-server
signed request to the control plane rather than by a Laravel session or CSRF
token. The public surface is chat info/send/session, anonymous history
list/read/delete/clear, and action confirm/cancel. History requests add a
package-owned 256-bit first-party HttpOnly cookie value only to the signed JSON
body sent upstream; the browser never sees that raw identifier. History
deletion also requires an explicit intent header and an exact same-origin
`Origin` value.

The stable `embed/chat-widget.js` and `embed/iframe.html` proxy paths always
revalidate and preserve provider validators. Only strict fingerprinted
`embed/assets/<name>-<hash>.js|css` paths are immutable. The proxy forwards no
browser cookies, authorization, or signing headers upstream and returns only
safe cache, validator, MIME, and iframe-CSP response headers downstream.

## Test Plan

Orchestra Testbench covers package boot, Filament registration, OAuth setup,
encrypted persistence, resource access rules, audit logging, public payload
safety, the complete sessionless public chat/history surface, cookie rotation,
same-origin mutation intent, strict asset proxying, signed request rejection
cases, and SQLite in-memory execution.
