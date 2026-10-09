# Authentication

API-token email flows: mails contain a raw token; the client posts it to the API.
There are no frontend verification/reset links.

Interactive docs: [`/api/docs`](/api/docs). Machine-readable: [`openapi.yaml`](openapi.yaml).

## Endpoints

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| `POST` | `/api/register` | — | Create account; send verification email |
| `POST` | `/api/verify-email` | — | Confirm email and set the account password |
| `POST` | `/api/login` | — | Issue JWT + refresh token |
| `POST` | `/api/token/refresh` | — | Rotate JWT using refresh token |
| `POST` | `/api/forgot-password` | — | Send password-reset email (always 204) |
| `POST` | `/api/reset-password` | — | Set new password with reset token |
| `POST` | `/api/logout` | JWT | Invalidate JWT (and refresh token(s)) |
| `GET` | `/api/me` | JWT | Current user profile |

All other `/api/*` routes require a valid JWT (`IS_AUTHENTICATED_FULLY`), except
`/api/graphql` (public entrypoint; operation-level security applies).

An invalid, expired, or blocklisted `Authorization: Bearer` on the `api` firewall
is ignored (request continues as anonymous). Public auth routes and GraphQL auth
mutations still run; protected routes/`me`/`logout`/stamp queries still require a
valid JWT.

## GraphQL

`POST /api/graphql` (IDE at `/api/graphql/graphiql`). Auth mutations reuse the
same services and rules as REST. Stamp catalog queries still require JWT.
Clients may keep a stale Bearer while calling `refreshTokenUser` or `loginUser`.

| Field | Auth | Purpose |
|-------|------|---------|
| `registerUser` | — | Create account; send verification email |
| `verifyEmailUser` | — | Confirm email and set the account password |
| `loginUser` | — | Issue JWT + refresh token |
| `refreshTokenUser` | — | Rotate JWT using refresh token |
| `forgotPasswordUser` | — | Send password-reset email |
| `resetPasswordUser` | — | Set new password with reset token |
| `logoutUser` | JWT | Invalidate JWT (and refresh token(s)) |
| `meUser` | JWT | Current user profile |

Example:

```graphql
mutation {
  registerUser(input: { email: "user@example.com" }) {
    user { id email }
  }
}

mutation {
  loginUser(input: { email: "user@example.com", password: "password123" }) {
    user { token refreshToken }
  }
}

query {
  meUser {
    email
    roles
  }
}
```

Code: `src/ApiResource/Auth.php`, resolvers in `src/GraphQl/Resolver/Auth/`.

## Register → verify → login

```http
POST /api/register
Content-Type: application/json

{"email":"user@example.com"}
```

Optional `locale` (`en` default, or `cs`) selects the verification email language.

**201** `{ "id": 1, "email": "user@example.com" }` — user is **not** verified yet.
Login before verification returns **401**.

If the email already belongs to an **unverified** account, register rotates the
verification token, extends expiry by one day, and resends the email (same **201**
shape). A **verified** email still returns **422**.

The verification email body includes a raw token (enter it in the client app).
Confirm it and set the password that will be used to log in. Only the mailbox
owner chooses the credential that can authenticate.

```http
POST /api/verify-email
Content-Type: application/json

{"token":"<token-from-email>","password":"password123"}
```

**204** on success.

```http
POST /api/login
Content-Type: application/json

{"email":"user@example.com","password":"password123"}
```

**200** `{ "token": "<jwt>", "refresh_token": "<refresh>" }`.

Use the JWT on protected routes:

```http
GET /api/me
Authorization: Bearer <jwt>
```

## Refresh tokens

Refresh tokens are **single-use**. After a successful refresh, the previous refresh
token is invalid. Tokens are stored hashed (`hash_tokens`); cleartext rows and the
stored `sha256$…` digest are not accepted as credentials
(`accept_stored_in_the_clear: false`). Replaying a spent refresh token revokes the
whole token family (session), for both REST and GraphQL. Replay detection uses
`cache.app` — share that cache across replicas in a multi-instance deploy.

```http
POST /api/token/refresh
Content-Type: application/json

{"refresh_token":"<refresh>"}
```

**200** `{ "token": "<new-jwt>", "refresh_token": "<new-refresh>" }`.

## Password reset

```http
POST /api/forgot-password
Content-Type: application/json

{"email":"user@example.com"}
```

Optional `locale` (`en` default, or `cs`) selects the reset email language.

Always **204** (does not reveal whether the email exists). If the user exists, a
reset token is emailed.

```http
POST /api/reset-password
Content-Type: application/json

{"token":"<token-from-email>","password":"newpassword123"}
```

**204** on success. All refresh tokens for the user are revoked, and JWTs issued
before the reset are rejected until they would have expired (`token_ttl`, 1 hour).
A JWT issued in that same second is refused too (`iat` only has second resolution);
sign in again on the next second. Reset does not verify the email; an unverified
account still has to `POST /api/verify-email` with the original verification token
and the password chosen at verification (verify overwrites any password set by reset).

## Logout

```http
POST /api/logout
Authorization: Bearer <jwt>
Content-Type: application/json

{"refresh_token":"<refresh>"}
```

**204**. The access token is blocklisted.

- If `refresh_token` is provided, that refresh token is revoked.
- If it is omitted (or `null`), **all** refresh tokens for the user are revoked,
  and every JWT issued before that logout is rejected for the rest of its `token_ttl`.

## Validation & errors

Request DTOs (`register`, `verify-email`, `forgot-password`, `reset-password`,
`logout`) are bound with `#[MapRequestPayload]`. Failed validation returns
**422** with a structured body:

```json
{
  "title": "Validation Failed",
  "detail": "The given data failed validation.",
  "violations": [
    {"propertyPath": "email", "message": "This value is not a valid email address."},
    {"propertyPath": "password", "message": "This value is too short. It should have 8 characters or more."}
  ]
}
```

Other cases:

- Bad credentials / unverified account / expired JWT or refresh: **401**
- Duplicate **verified** registration email: **422** (same `ValidationFailed`
  shape when thrown from the validator); unverified emails reclaim via register
- Invalid/expired verify or reset token: **422**
- Rate limit exceeded on public auth endpoints: **429** (`Retry-After` header)

Password minimum length is **8** characters.

## Rate limiting

| Endpoint | Limiter | Default |
|----------|---------|---------|
| `POST /api/register`, `POST /api/forgot-password` | `auth_email` | 10 / 15 minutes per IP |
| `POST /api/verify-email`, `POST /api/reset-password` | `auth_token` | 20 / 15 minutes per IP |
| `POST /api/login` | login throttling (`username+IP` + `IP`) | 5 / 5 minutes per username+IP; 50 / 15 minutes per IP |

Config: `config/packages/rate_limiter.yaml`.

These limiters key on `Request::getClientIp()`. Behind FrankenPHP/Caddy or another
load balancer, set `SYMFONY_TRUSTED_PROXIES` to the immediate trusted proxy
CIDR(s) (see `config/packages/framework.yaml`). Leave it empty when the app sees
clients directly. Do not trust all traffic (`0.0.0.0/0`) unless the network edge
already restricts who can reach PHP.

## Secrets

Put real secrets in `.env.local` (git-ignored), not in committed `.env`:

- `APP_SECRET`
- `JWT_PASSPHRASE` (matches the passphrase used when generating `config/jwt/*.pem`)
- `MAILER_DSN` / `MAILER_FROM` as needed

## Code map

| Area | Location |
|------|----------|
| HTTP routes | `src/Controller/Auth/AuthController.php` |
| Request DTOs | `src/Dto/Auth/` |
| Domain services | `src/Service/Auth/` |
| GraphQL resource | `src/ApiResource/Auth.php` |
| GraphQL resolvers | `src/GraphQl/Resolver/Auth/` |
| User entity | `src/Entity/User.php` |
| Block unverified login | `src/Security/UserChecker.php` |
| Validation error JSON | `src/EventListener/ValidationFailedExceptionListener.php` |
| OpenAPI auth paths | `src/OpenApi/AuthOpenApiFactory.php` |
| Email templates | `templates/email/` |
| Functional tests | `tests/Api/AuthTest.php`, `tests/Api/AuthGraphQlTest.php` |
