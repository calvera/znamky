# AGENTS.md

This is a Symfony API project (Doctrine ORM, API Platform, Security, Mailer, Lexik
JWT). Check `composer.json` for the exact Symfony/PHP version, and read
`symfony.lock` to see which recipes ran. Don't assume extras like Messenger or Lock
are installed unless those files say so.

## Project decisions (settled)

Do not re-ask these. Follow the existing patterns under `src/` and `config/`.

- **Persistence:** Doctrine ORM on PostgreSQL. Schema changes go through migrations.
- **Interface:** JSON API under `/api` (API Platform + thin controllers). Twig is for email
  templates only, not server-rendered pages.
- **Auth:** Lexik JWT (`Authorization: Bearer`) with Gesdinet refresh tokens.
  - Login: `POST /api/login` (`email` + `password`) via `json_login`.
  - Refresh: `POST /api/token/refresh`.
  - User provider: `App\Entity\User` by email.
  - Unverified users cannot authenticate (`App\Security\UserChecker`).
- **Email flows (API-token, not frontend links):** Mailer sends a raw token; the client posts
  it to the API (`POST /api/verify-email`, `POST /api/reset-password`). Register at
  `POST /api/register`; forgot password at `POST /api/forgot-password`.
- **Auth code layout:** Controllers in `src/Controller/Auth/`, DTOs in `src/Dto/Auth/`,
  services in `src/Service/Auth/`. Prefer extending these over inventing parallel stacks.
- **Stamp catalog:** Read-only API Platform resources (`Stamp`, `StampTag`,
  `StampPlace`) under `/api/stamps`, `/api/stamp_tags`, `/api/stamp_places`.
  - Identity: stamp `(country, type, number)`; country `CZ`/`SK`, type
    `regular`/`annual` (`App\Enum\StampCountry`, `App\Enum\StampType`).
  - Tags and places are shared catalogs (M2M from Stamp). Places dedupe by
    `catalogKey` (SHA-256 of name + url).
  - CSV import: `app:stamps:import` from `data/` (`cs-stamps.csv`,
    `sk-stamps.csv`, `cs-annual.csv`). Prefer `--no-debug` on full imports.
  - Geocoding: `app:stamps:geocode` via Google Maps (`GOOGLE_MAPS_API_KEY` in
    `.env.local`). Stamp query = name + region + country; place query = name.
    Import does not geocode; re-import preserves existing coords unless `--purge`.
  - Code: entities in `src/Entity/`, services in `src/Service/Stamp/`, commands
    in `src/Command/`. Human docs: `docs/stamps.md`.
- **Docs:** Human docs in `README.md` and `docs/`; keep OpenAPI in sync via
  `App\OpenApi\AuthOpenApiFactory` and `docs/openapi.yaml` (`api:openapi:export`).

## Ask before generating

For anything not covered above, ask rather than guess (e.g. new auth mechanisms,
non-API UIs, alternate persistence). If you can't ask, state the assumption and pick
the smallest option that fits the settled decisions.

## Adding features: Flex, not hand-wiring

Install new capabilities with `composer require <package>` (e.g. `symfony/lock`,
`symfony/messenger`, `orm-pack`) and let the Flex recipe register the bundle and
generate its config. Don't hand-edit `config/bundles.php` or hand-write a bundle's
base config; that's what the recipe is for. Don't skip a good-fit component just
because it isn't installed yet; installing it is one command.

## Conventions

Follow https://symfony.com/doc/current/best_practices.html to write idiomatic
Symfony:

- Use PHP attributes for framework metadata, and not only on controllers:
  `#[Route]`, `#[MapRequestPayload]`, `#[IsGranted]` on actions, `#[Assert\...]`
  on properties, `#[AsCommand]`, `#[AsEventListener]`, `#[AsMessageHandler]`, and
  `#[AsAlias]` / `#[AsTaggedItem]` / `#[Autoconfigure]` on services. No YAML or
  XML routing.
- Rely on autowiring and autoconfiguration. Type-hint constructor arguments and
  let the container resolve them. Where a type-hint can't express it, stay in the
  class with `#[Autowire]` (parameters, env vars, expressions) or `#[Target]` (one
  of several implementations of an interface). A YAML service definition is the
  last resort, not the first.
- Controllers extend `AbstractController`, stay thin, and delegate to services.
- Use the framework for what it already does: Form for server-rendered forms,
  Validator for validation, Serializer for JSON, Messenger for async work,
  Security (voters, authenticators) for access control, Twig `path()`/`url()`
  instead of hardcoded URLs.
- Before hand-writing infrastructure (locks, queues, caches, HTTP clients,
  mailers, schedulers) or reaching for a third-party library, check whether a
  Symfony component covers it. It usually does.

Three specifics worth spelling out, because they are easy to get wrong:

- Bind request data with `#[MapRequestPayload]` / `#[MapQueryString]` on action
  arguments, which wires up Serializer and Validator for you, instead of calling
  `json_decode()` or `SerializerInterface` by hand. If neither package is
  installed yet, `composer require` them rather than falling back to manual
  parsing.
- Use constructor property promotion, and `readonly` for DTOs and value objects.
  Don't mark a service `readonly` if it might become `lazy: true`: a lazy proxy
  can't extend a `readonly` class.
- Use `symfony/lock` (`LockFactory`) for mutual exclusion. A hand-built flag or
  lock file looks fine in review and is usually wrong under concurrency.

## Everyday workflow

- Run the app with `symfony serve -d`, and commands with `symfony console ...`
  (or `bin/console` when the Symfony CLI isn't available).
- When something fails, read `var/log/dev.log` and the web profiler
  (`/_profiler`) before changing code.
- If `maker-bundle` is installed, prefer `bin/console make:*` with every argument
  passed up front and `--no-interaction` where supported: makers prompt on a
  terminal by default, which hangs a non-interactive shell. If a maker still
  needs interactive input, hand-write the code instead.
- If Doctrine ORM is installed, schema changes go through migrations
  (`bin/console make:migration`, then `doctrine:migrations:migrate`), never
  `doctrine:schema:update` or hand-written SQL.
- `.env` is committed and holds defaults only. Real secrets belong in `.env.local`
  (git-ignored) or the secrets vault (`bin/console secrets:set`), read via
  `%env(...)%`.

## Testing

Install `symfony/test-pack` if it isn't already. Functional/HTTP tests extend
`WebTestCase`; service-level tests extend `KernelTestCase`. Run
`php bin/phpunit` (falls back to `vendor/bin/phpunit`). A feature isn't done
until it has a test that exercises it the way a caller would, an HTTP request for
a controller or a service call for a service, not just "it didn't throw."

## Code style

Symfony's coding standard, the `@Symfony` php-cs-fixer ruleset (a PSR-12-derived
superset). Run `vendor/bin/php-cs-fixer fix` if `friendsofphp/php-cs-fixer` is
installed; it isn't part of the skeleton by default.

## Discover, don't guess

Framework APIs change between versions and your training data may be stale. Look
things up in the project instead of relying on memory:

- `bin/console about`: versions, environment, paths.
- `bin/console debug:router`, `debug:container`, `debug:autowiring <name>`,
  `debug:config <bundle>`, `config:dump-reference <bundle>`: what exists and how
  it is configured.
- `bin/console lint:container`, plus `lint:twig templates/` and
  `lint:yaml config/` where those packages are installed: validate before running.
- Read the installed source and docblocks under `vendor/`.
- Docs: https://symfony.com/doc/current/ (switch to the version matching
  `composer.json` if it differs).
