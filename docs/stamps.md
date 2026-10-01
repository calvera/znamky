# Tourist stamps

Catalog of tourist stamps (`Stamp`), tags (`StampTag`), and sales places
(`StampPlace`). Data is loaded from CSV under `data/`, exposed as read-only API
Platform resources, and optionally geocoded via Google Maps.

Interactive docs: [`/api/docs`](/api/docs). Machine-readable: [`openapi.yaml`](openapi.yaml).

## Domain

| Entity | Natural key | Notes |
|--------|-------------|--------|
| `Stamp` | `(country, type, number)` | Numbers collide across country/type |
| `StampTag` | `name` (unique), `slug` | From CS `Kategorie` only |
| `StampPlace` | `catalogKey` = SHA-256 of `name` + NUL + `url` | Shared catalog (M2M) |

- **Country:** `CZ` / `SK` (`App\Enum\StampCountry`)
- **Type:** `regular` / `annual` (`App\Enum\StampType`)
- **Coords:** nullable `latitude` / `longitude` on stamps and places
- Stamp ↔ tag and stamp ↔ place are unidirectional ManyToMany from `Stamp`

Code layout: entities in `src/Entity/`, services in `src/Service/Stamp/`,
commands in `src/Command/`.

## Source CSVs (`data/`)

| File | Country | Type | Tags | Places |
|------|---------|------|------|--------|
| `cs-stamps.csv` | CZ | regular | yes | yes |
| `sk-stamps.csv` | SK | regular | no | almost empty |
| `cs-annual.csv` | CZ | annual | no | sparse |

Delimiter `;`, UTF-8 BOM, multiline quoted place cells.

- Tags: `Kategorie` split on `, `
- Places: `Prodejní místa (název a web)` — one place per line; last `(…)` used as
  URL when it looks like a domain; bare domains / `www.` normalized to `https://…`

## Import

```bash
# Prefer --no-debug on large imports (Doctrine query backtraces blow memory in debug)
php bin/console app:stamps:import --no-debug
php bin/console app:stamps:import --purge --no-debug   # wipe catalog first
php bin/console app:stamps:import --path=/other/dir --no-debug
```

Upserts by natural keys. Re-import **preserves** existing lat/lng unless
`--purge`. Import does **not** call Google.

Import and geocode share a Symfony Lock resource (`stamps-catalog`, via
`LOCK_DSN`, default `flock`). A second concurrent run exits with an error
instead of racing a purge/import.

Service: `App\Service\Stamp\StampImportService` (uses
`App\Service\Stamp\PlaceLineParser` and injected `SluggerInterface` for tag
slugs).

## Geocoding

Requires `GOOGLE_MAPS_API_KEY` in `.env.local` (empty default in `.env`).

```bash
php bin/console app:stamps:geocode
php bin/console app:stamps:geocode --only=stamps
php bin/console app:stamps:geocode --only=places
php bin/console app:stamps:geocode --force          # re-geocode filled rows
php bin/console app:stamps:geocode --delay=100      # ms between API calls
```

| Target | Query |
|--------|--------|
| Stamp | `{name}, {region}, {countryLabel}` (omit empty region) e.g. `Praděd, Moravskoslezský kraj, Czech Republic` |
| Place | place `name` only |

Rows are streamed with Doctrine `toIterable()`, flushed in batches, and the
entity manager is cleared periodically to keep memory bounded. Failed lookups
leave coords null so a later run can retry. Client:
`App\Service\Stamp\GoogleGeocoder` (Symfony HttpClient).

Same `stamps-catalog` lock as import — do not run import and geocode at once.

## API (read-only, JWT required)

All `/api/*` routes need `Authorization: Bearer <jwt>` except auth/docs paths
(see [auth.md](auth.md)).

| Method | Path | Filters |
|--------|------|---------|
| `GET` | `/api/stamps` | `country`, `type`, `number` (exact), `name` (partial), `order[number]` |
| `GET` | `/api/stamps/{id}` | — |
| `GET` | `/api/stamp_tags` | `name` (partial), `slug` (exact) |
| `GET` | `/api/stamp_tags/{id}` | — |
| `GET` | `/api/stamp_places` | `name` (partial) |
| `GET` | `/api/stamp_places/{id}` | — |

No Post/Put/Patch/Delete. Lat/lng are included when set. Place `catalogKey` is
not exposed.

```http
GET /api/stamps?country=CZ&type=regular&order[number]=asc
Authorization: Bearer <jwt>
```

```http
GET /api/stamp_tags?slug=jeseníky
Authorization: Bearer <jwt>
```

## Tests

```bash
php bin/phpunit tests/Service/Stamp
php bin/phpunit tests/Api/StampCatalogTest.php
```

Service tests cover place/tag parsing, Google geocoder (mocked HTTP), import
shared-place reuse, and stamp geocode query building. `StampCatalogTest` covers
JWT gating and collection/filter responses for stamps, tags, and places.
