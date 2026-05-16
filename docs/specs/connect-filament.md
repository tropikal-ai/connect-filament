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
token.

## Test Plan

Orchestra Testbench covers package boot, Filament registration, OAuth setup, encrypted persistence, resource access rules, audit logging, public payload safety, sessionless public embed chat proxying, signed request rejection cases, and SQLite in-memory execution.
