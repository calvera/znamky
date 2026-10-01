# Authentication

API-token email flows: mails contain a raw token; the client posts it to the API.
There are no frontend verification/reset links.

Interactive docs: [`/api/docs`](/api/docs). Machine-readable: [`openapi.yaml`](openapi.yaml).

## Endpoints

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| `POST` | `/api/register` | — | Create account; send verification email |
| `POST` | `/api/verify-email` | — | Confirm email with token from mail |
| `POST` | `/api/login` | — | Issue JWT + refresh token |
| `POST` | `/api/token/refresh` | — | Rotate JWT using refresh token |
| `POST` | `/api/forgot-password` | — | Send password-reset email (always 204) |
| `POST` | `/api/reset-password` | — | Set new password with reset token |
| `POST` | `/api/logout` | JWT | Invalidate JWT (and optional refresh token) |
| `GET` | `/api/me` | JWT | Current user profile |

All other `/api/*` routes require a valid JWT (`IS_AUTHENTICATED_FULLY`).

## Register → verify → login

```http
POST /api/register
Content-Type: application/json

{"email":"user@example.com","password":"password123"}
```

**201** `{ "id": 1, "email": "user@example.com" }` — user is **not** verified yet.
Login before verification returns **401**.

The verification email body includes a raw token. Confirm it:

```http
POST /api/verify-email
Content-Type: application/json

{"token":"<token-from-email>"}
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
token is invalid.

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

Always **204** (does not reveal whether the email exists). If the user exists, a
reset token is emailed.

```http
POST /api/reset-password
Content-Type: application/json

{"token":"<token-from-email>","password":"newpassword123"}
```

**204** on success.

## Logout

```http
POST /api/logout
Authorization: Bearer <jwt>
Content-Type: application/json

{"refresh_token":"<refresh>"}
```

**204**. The access token is blocklisted. If `refresh_token` is provided, it is
revoked as well.

## Validation & errors

- Invalid payloads typically return **422** (validation).
- Bad credentials / unverified account / expired tokens: **401**.
- Duplicate registration email: **422**.

Password minimum length is **8** characters.

## Code map

| Area | Location |
|------|----------|
| HTTP routes | `src/Controller/Auth/AuthController.php` |
| Request DTOs | `src/Dto/Auth/` |
| Domain services | `src/Service/Auth/` |
| User entity | `src/Entity/User.php` |
| Block unverified login | `src/Security/UserChecker.php` |
| Email templates | `templates/email/` |
| Functional tests | `tests/Api/AuthTest.php` |
