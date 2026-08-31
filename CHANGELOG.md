# Changelog

## Unreleased

- Add the first-party human-verification challenge route and require a site-, session-, and action-bound proof before forwarding public booking confirmations.
- Strip browser proof and verification fields from signed action decisions; fail closed with safe typed verification errors while keeping cancellation proof-free.
- Complete the public Website Chat adapter with signed session, anonymous history, and action-decision routes.
- Bind anonymous history to a rotating 256-bit first-party HttpOnly cookie and require same-origin intent for deletion.
- Revalidate stable loader/iframe entrypoints and proxy only fingerprinted JavaScript/CSS assets as immutable.
- Redact upstream failures and secret-shaped payloads from every browser-facing chat response.

## 0.1.5 - 2026-08-28

- Self-heal dynamic OAuth client ids retained by installations disconnected before v0.1.4.

## 0.1.4 - 2026-08-28

- Recover reconnects by discarding disconnected dynamic OAuth client ids.
- Support dynamic registration from new HTTPS origins with the authorization server's registration proof.

## 0.1.0 - 2026-05-16
- Prepared the Filament integration package for open-source release-candidate review.
- Documented install, local path development, plugin registration, OAuth setup, business-object discovery, security model, and private server boundaries.
- Aligned dependency metadata with the first `tropikal-ai/connect` minor release line.
- Expanded public package docs to avoid private endpoint assumptions.
- Added release-candidate threat model documentation and hardened URL, discovery, signature-error, and field-projection safety.
- Added explicit destructive delete grants and signed delete execution for business objects.
- Added pagination, search, and safe exact-filter schemas for list capabilities.
