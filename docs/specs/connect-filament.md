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

Browser-facing embed proxy endpoints are public, tokenless same-origin
endpoints. They must run through Laravel's `api` middleware, not the `web`
session stack, because visitor chat is authenticated by a server-to-server
signed request to the control plane rather than by a Laravel session or CSRF
token. The public surface is chat info/bootstrap/send/session, anonymous history
list/read/delete/clear, and action confirm/cancel. History requests add a
package-owned 256-bit first-party HttpOnly cookie value only to the signed JSON
body sent upstream; the browser never sees that raw identifier. The versioned
chat context below is the sole additional server-to-server transport. History
deletion also requires an explicit intent header and an exact same-origin
`Origin` value.

Private bootstrap uses the same signed cookie boundary to mint a short-lived
write capability without listing conversations. The App runtime invokes it
only after deliberate chat use. Its response and all private chat/history
responses remain no-store; a capability is not a transcript-read credential.

Chat forwards an already acknowledged history cookie through Connect Core's
optional request-bound `SignedRequestContext` extension, retaining the exact
legacy body and main signature for older Apps. This explicitly extends the
former body-only rule. No new cookie is minted during send. Its opaque JSON
payload is `v:1`, `kind:embed-chat`, `visitor_history_token`, `actor_identity`,
`actor_context_sha256`, and `session_id`. The actor identity is an
installation-scoped HMAC of the host-resolved actor type/id; the rotating
encrypted permit is separately bound by its SHA256 and exact session. No
browser-submitted context is trusted. The App rejects invalid/partial context
and still authorizes the conversation independently. Public info/assets never
carry the extension; private responses remain no-store and never expose it.
Both context headers and actor/session headers are private and must be removed
from access logs. Stable member retries on the new App require a package that
supports this extension; installation of the two supported package lines must
precede reliance on that guarantee during rollout.

The stable `embed/chat-widget.js` and `embed/iframe.html` proxy paths always
revalidate the current upstream bytes, then derive validators from the actual
host-transformed representation. Upstream Last-Modified and pre-transform ETag
cannot validate those bytes. Only strict fingerprinted
`embed/assets/<name>-<hash>.js|css` paths are immutable. The proxy forwards no
browser cookies, authorization, or signing headers upstream and returns only
safe cache, validator, MIME, and iframe-CSP response headers downstream.
All three public asset routes use the sessionless API middleware. Their one
canonical cache policy cannot inherit contradictory upstream no-store/private
or stale-while-revalidate fields. Fingerprinted bytes are never rewritten.
The iframe's generated module, stylesheet and worker graph stays on its own
origin through the registered `route_prefix`. Generated dotted basenames such
as `proofOfWork.worker-<hash>.js` are accepted without permitting path segments,
empty dot segments, mutable filenames or other extensions. Cross-origin module
URLs are not a safe shortcut: a worker resolved relative to that module would
fail the browser's same-origin Worker constructor check even with CORS.

Chat presentation forwards the locale query and bounded If-None-Match header
to the authoritative signed App read. Shared caching is permitted only for
App's exact public/no-cache/max-age=0/must-revalidate policy, without cookies,
and an at-most-4KB JSON body that passes the public secret-field guard. Its
validator is derived from the exact returned bytes. A valid upstream 304 keeps
the same policy and validator; malformed/unsafe 304s fail closed. Legacy,
oversized, contradictory or cookie-bearing responses remain no-store. The
package does not create a presentation TTL, cached revision, or media store.

## Test Plan

Orchestra Testbench covers package boot, Filament registration, OAuth setup,
encrypted persistence, resource access rules, audit logging, public payload
safety, the complete sessionless public chat/history surface, cookie rotation,
same-origin mutation intent, strict asset proxying, signed request rejection
cases, and SQLite in-memory execution.
