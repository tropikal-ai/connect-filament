# TROPIKAL Connect Filament Threat Model

Status: release-candidate review

## Scope

This document covers `tropikal-ai/connect-filament`, the Laravel + Filament integration package. Protocol primitives are covered by `tropikal-ai/connect`. Private authorization server and control-plane internals are intentionally outside this package.

## Assets

- OAuth refresh credentials.
- PKCE verifier values before callback completion.
- Server-to-server signing credentials.
- Installation status and identifiers.
- Eloquent business-object schemas and grants.
- Resource records read from or written to the host application.
- Audit logs for mutations.
- Public embed status and chat proxy responses.

## Entry Points

- Authenticated Filament setup route.
- Public OAuth callback.
- Signed resource API routes.
- Public embed status and chat proxy routes.
- Filament grant management UI.
- Install command and published configuration.

## Trust Boundaries

- Filament admin browser to setup UI: trusted only after Laravel/Filament authentication.
- OAuth callback browser redirect to package: untrusted until state, expiry, PKCE, host, and exact redirect URI validate.
- Package to authorization server: HTTPS required outside localhost development.
- Package to private control plane: HTTPS required outside localhost development.
- Private control plane to resource API: trusted only after signed request verification.
- Visitor browser to public embed endpoints: untrusted; responses must be browser-safe.
- Eloquent discovery to resource execution: discovery is informational; access requires explicit grants.

## Threats And Mitigations

| Threat | Mitigation |
| --- | --- |
| Unauthenticated setup | Connect route uses configured authenticated middleware and checks the current user. |
| OAuth state replay or forgery | State is random, hashed at rest, expires, and is cleared after successful callback. |
| PKCE verifier theft from database | Verifier is encrypted at rest and cleared after callback. |
| Refresh credential theft from database | Refresh credential uses Laravel encrypted casts. |
| Signing credential theft from database | Server signing credential uses Laravel encrypted casts. |
| Sending credentials over plaintext network | OAuth, redirect, site, and control-plane URLs must be HTTPS outside localhost development. |
| Signed API replay | Nonces are claimed with Laravel cache `add`, which is atomic for supported stores. |
| Signed API probing | Public 401 responses are generic; detailed rejection reasons are logged server-side without credentials. |
| Resource overexposure by discovery | Discovery defaults to included application model namespaces, excludes auth/internal/security models, and exposes nothing until grants are enabled. |
| All-model discovery from empty namespace config | Empty discovery namespaces now disable broad classpath scanning unless models are explicitly configured. |
| Secret field exposure | Secret-shaped fields are excluded and rejected before schema publication. |
| Read overexposure | Read responses project only readable fields plus identifier. |
| Write overexposure | Writes accept only writable fields and set attributes explicitly. |
| Destructive mutation | Write grants create create/update capabilities only; delete is not exposed. |
| Browser secret exposure | Public status and proxied JSON responses are checked recursively for secret-shaped keys. |
| Spoofed embed origin value | Embed origin forwarding uses valid HTTP(S) origins only and prefers browser `Origin`/`Referer` over a declared fallback header. |
| Expected model input failure becoming raw 500 | Validation and database input failures return structured 400/422-style responses where expected. |

## Security Invariants

- OAuth PKCE is the only setup path.
- No token-paste or copied-credential setup path exists.
- Empty grants expose nothing.
- Read and write grants are independent.
- Write never implies delete.
- Named actions require explicit grants.
- Browser payloads never contain credentials, request signatures, signing credentials, refresh credentials, or server-only endpoint credentials.
- Control-plane responses are treated as private server responses and are still filtered before browser exposure.

## Operational Requirements

- Configure `APP_URL`, OAuth URLs, and control-plane URLs with HTTPS in production.
- Use a Laravel cache driver whose `add` operation is atomic in production.
- Keep `APP_KEY` stable and secret; encrypted casts depend on it.
- Keep debug mode disabled in production.
- Grant only the business objects and fields needed by the customer use case.
- Review discovered fields before enabling read or write access.

## Residual Risks

- A compromised Filament administrator can grant access to discovered business objects.
- A compromised host application can expose unsafe explicit resource definitions.
- A compromised private control plane can request any capability the installation has granted.
- Public embed endpoints can be called by untrusted clients; rate limiting and abuse controls should be applied at the host or edge.
- External security review is still recommended before a stable public tag.
