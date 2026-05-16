# Contributing

Keep changes small, tested, and aligned with `docs/specs/connect-filament.md`.

## Development

```bash
composer validate --strict
composer install
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/phpunit --colors=never
```

## Rules

- Add or update tests before changing setup, signing, resource exposure, public payloads, or discovery behavior.
- Keep protocol/domain primitives in `tropikal-ai/connect`.
- Keep Laravel, Filament, Eloquent, migrations, routes, controllers, and views in this package.
- Do not add production URLs, private server behavior, token-paste setup, or copied-secret setup.
- Keep browser/public payload behavior fail-closed.
- Prefer clear names over comments.
