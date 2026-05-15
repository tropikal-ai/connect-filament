# Security

`tropikal-ai/connect-filament` is the Laravel + Filament integration package. It owns setup UI, encrypted persistence, resource grants, and signed resource execution. Protocol primitives live in `tropikal-ai/connect`.

## Supported Versions

Security fixes target the latest released minor version. Before the first public tag, fixes target the default development branch.

## Reporting

Report vulnerabilities privately through the repository security advisory flow or the maintainer contact published with the package. Do not include production credentials, customer data, access tokens, refresh tokens, signing credentials, request signatures, or private endpoint details in public reports.

## Security Expectations

- OAuth authorization code with PKCE is the only setup path.
- Setup routes require authenticated Filament/admin access.
- Callback validation includes state, PKCE, expiry, host, and exact redirect URI.
- Refresh credentials, PKCE verifiers, and server signing credentials are encrypted at rest.
- Signed server-to-server requests include method, path, normalized query string, timestamp, nonce, installation id, and body hash.
- Nonce replay protection is atomic.
- Empty grants expose nothing.
- Reads and writes are limited to explicitly declared fields.
- Write grants do not expose delete.
- Browser/public payloads must never contain credentials.
