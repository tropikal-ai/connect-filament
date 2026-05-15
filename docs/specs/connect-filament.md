# Connect Filament Spec

Status: draft

## Problem

Laravel Filament projects need a one-click way to connect a site to the private Tropikal control plane without exposing server credentials to administrators or browsers.

## Goals

- Provide the Laravel service provider, Filament plugin, routes, migrations, encrypted models, and resource API.
- Use OAuth authorization code with PKCE as the only setup path.
- Store refresh credentials, PKCE verifiers, and server signing credentials with encrypted casts.
- Verify server-to-server requests with the shared `tropikal-ai/connect` primitives.
- Expose no resources until both local declarations and remote grants exist.
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
6. Resource schema and embed state are synchronized from private server responses.

## Resource Boundary

Local code declares resource fields and named actions. The private control plane grants a subset of those declarations. Empty declarations and empty grants expose nothing. Reads project declared fields only. Writes reject undeclared fields.

## Security Model

All API requests from the private control plane must include a signed assertion covering method, path, normalized query string, timestamp, nonce, installation id, and body hash. Nonces are claimed through the Laravel cache with an atomic add operation.

The package never trusts browser-submitted account metadata and does not decode identity claims as authority. Account and workspace metadata come from the private control plane after token exchange.

## Test Plan

Orchestra Testbench covers package boot, Filament registration, OAuth setup, encrypted persistence, resource access rules, audit logging, public payload safety, signed request rejection cases, and SQLite in-memory execution.
