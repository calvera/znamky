# Znamky

Symfony 8 JSON API with JWT authentication, a tourist-stamp catalog, email
verification, and password reset.

## Requirements

- PHP 8.4+
- Composer
- PostgreSQL 16 (Docker Compose included)
- Symfony CLI (optional)

## Quick start

```bash
docker compose up -d
composer install
# Generate JWT keys if missing (recipe usually creates config/jwt/*.pem)
php bin/console lexik:jwt:generate-keypair --skip-if-exists
php bin/console doctrine:migrations:migrate -n
symfony serve -d   # or: php -S 127.0.0.1:8000 -t public
```

Useful env defaults live in `.env`. Override secrets in `.env.local` (never commit
them) — including `APP_SECRET`, `JWT_PASSPHRASE`, and `GOOGLE_MAPS_API_KEY`.

## Documentation

| Doc | Description |
|-----|-------------|
| [docs/auth.md](docs/auth.md) | Auth flows, endpoints, tokens, validation errors |
| [docs/stamps.md](docs/stamps.md) | Stamp catalog, CSV import, geocoding, read-only API |
| [docs/openapi.yaml](docs/openapi.yaml) | OpenAPI 3 spec (auth + stamp resources) |
| `/api/docs` | Interactive Swagger UI |
| `/api/graphql` | GraphQL endpoint (JWT); IDE at `/api/graphql/graphiql` |

Export the live OpenAPI document anytime:

```bash
php bin/console api:openapi:export --yaml > docs/openapi.yaml
```

## Auth overview

1. `POST /api/register` — creates an unverified user and emails a verification token
2. `POST /api/verify-email` — confirms the email and sets the account password
3. `POST /api/login` — returns JWT + refresh token (verified users only)
4. Authenticated calls use `Authorization: Bearer <token>`
5. `POST /api/token/refresh` — exchanges a refresh token for a new pair
6. `POST /api/logout` — blocklists JWT; revokes refresh token(s)
7. Password reset: `POST /api/forgot-password` then `POST /api/reset-password`

DTO validation failures return **422** with `{ title, detail, violations[] }`.
See [docs/auth.md](docs/auth.md).

## Stamp catalog

1. Migrate, then import CSVs: `php bin/console app:stamps:import --no-debug`
2. Optional geocode (needs `GOOGLE_MAPS_API_KEY` in `.env.local`):
   `php bin/console app:stamps:geocode`
3. Read-only API (JWT): `GET /api/stamps`, `/api/stamp_tags`, `/api/stamp_places`
   (same catalog via GraphQL: `POST /api/graphql`)

Import and geocode take a shared lock (`stamps-catalog`) so they cannot run
concurrently. See [docs/stamps.md](docs/stamps.md) for filters, CSV layout, and
geocode queries.

## Tests

```bash
php bin/phpunit
```

GitHub Actions (`.github/workflows/tests.yml`) runs this suite on every push and
pull request against PostgreSQL 16. The job prints PHPUnit's coverage report in
the logs and uploads Clover and Cobertura reports as artifacts.

## Stack

- Symfony 8.1, API Platform 5 (REST + GraphQL), Doctrine ORM, PostgreSQL
- Lexik JWT + Gesdinet refresh tokens
- Symfony Mailer (verification & password reset)
- Stamp catalog import + Google Geocoding (`symfony/http-client`)
- `symfony/lock` for stamp import/geocode mutual exclusion
