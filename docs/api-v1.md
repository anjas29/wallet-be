# Wallet API v1 — Implementation Reference

_Version **1.2.0** — 2026-07-24 · see [Changelog](#5-changelog)_

Base URL: `/api/v1`

All authenticated routes require a Bearer token from Sanctum.

## 1. Authentication

### `POST /api/v1/auth/register`
Request body:

```json
{
  "name": "string",
  "email": "string (email)",
  "password": "string",
  "password_confirmation": "string"
}
```

Response `201`:

```json
{
  "user": {
    "id": "string (ULID)",
    "name": "string",
    "email": "string",
    "role": "super_admin | user",
    "avatar_path": "string | null",
    "avatar_url": "string (URL) | null",
    "created_at": "string (ISO-8601)",
    "updated_at": "string (ISO-8601)"
  },
  "token": "string"
}
```

### `POST /api/v1/auth/login`
Request body:

```json
{
  "email": "string (email)",
  "password": "string"
}
```

Optional header:

```http
X-Device-Id: string
```

Response `200`:

```json
{
  "user": { "id": "string (ULID)", "name": "string", "email": "string", "role": "super_admin | user", "avatar_path": "string | null", "avatar_url": "string (URL) | null" },
  "token": "string"
}
```

### `POST /api/v1/auth/logout`
Authenticated only.

Response `204` with empty body.

### `POST /api/v1/auth/logout-all`
Authenticated only.

Response `204` with empty body.

### `GET /api/v1/auth/profile`
Authenticated only.

Response `200`:

```json
{
  "user": {
    "id": "string (ULID)",
    "name": "string",
    "email": "string",
    "role": "super_admin | user",
    "avatar_path": "string | null",
    "avatar_url": "string (URL) | null",
    "created_at": "string (ISO-8601)",
    "updated_at": "string (ISO-8601)"
  }
}
```

### `POST /api/v1/auth/profile/avatar`
Authenticated only. Upload or replace the profile picture. **`multipart/form-data`** with an `avatar` file field.

- Validation: `image`, `mimes:jpg,jpeg,png,webp`, `max:2048` (KB).
- Stored on the `s3` disk under `avatars/{user_id}/{random}.{ext}` (public-read). Replacing deletes the previous object.

Response `200`: the standard envelope with `data.user` (including the new `avatar_path` / `avatar_url`).

### `DELETE /api/v1/auth/profile/avatar`
Authenticated only. Removes the S3 object and clears `avatar_path`.

Response `200`: `data.user` with `avatar_path` / `avatar_url` = `null`.

---

## 2. Read endpoints

All read endpoints are authenticated.

### Query parameters
- `since`: `string (ISO-8601)` — returns rows where `updated_at > since`
- `limit`: `integer` — max `500`

### List response

```json
{
  "data": [
    { "...resource fields..." }
  ],
  "meta": {
    "server_time": "string (ISO-8601)"
  }
}
```

### Single-item response

```json
{
  "data": { "...resource fields..." }
}
```

### `GET /api/v1/currencies`
### `GET /api/v1/currencies/{id}`

Currency object:

```json
{
  "id": "string (ULID)",
  "code": "string",
  "name": "string",
  "symbol": "string",
  "decimal_places": "integer",
  "created_at": "string (ISO-8601)",
  "updated_at": "string (ISO-8601)",
  "deleted_at": "string (ISO-8601) | null"
}
```

### `GET /api/v1/categories`
### `GET /api/v1/categories/{id}`

Optional query parameter:
- `type`: `income | expense`

Category object:

```json
{
  "id": "string (ULID)",
  "name": "string",
  "type": "income | expense",
  "icon": "string",
  "color": "string | null",
  "created_at": "string (ISO-8601)",
  "updated_at": "string (ISO-8601)",
  "deleted_at": "string (ISO-8601) | null"
}
```

> **User categories** (added v1.1.0) are user-owned and have **no dedicated REST list endpoint** — they are delivered under `user_categories` in the sync-pull payload and written via `sync/push` (entity `user_category`). The global `categories` above remain read-only and are the template the client seeds from.

User category object:

```json
{
  "id": "string (ULID)",
  "name": "string",
  "type": "income | expense",
  "icon": "string",
  "color": "string | null",
  "created_at": "string (ISO-8601)",
  "updated_at": "string (ISO-8601)",
  "deleted_at": "string (ISO-8601) | null"
}
```

### `GET /api/v1/user-currencies`
### `GET /api/v1/user-currencies/{id}`

User currency object:

```json
{
  "id": "string (ULID)",
  "user_id": "string (ULID)",
  "currency_id": "string (ULID)",
  "exchange_rate": "string (decimal)",
  "is_anchor": "boolean",
  "created_at": "string (ISO-8601)",
  "updated_at": "string (ISO-8601)"
}
```

### `GET /api/v1/accounts`
### `GET /api/v1/accounts/{id}`

Account object:

```json
{
  "id": "string (ULID)",
  "user_id": "string (ULID)",
  "user_currency_id": "string (ULID)",
  "name": "string",
  "type": "cash | bank | e_wallet | other",
  "initial_balance": "string (decimal)",
  "is_default": "boolean",
  "created_at": "string (ISO-8601)",
  "updated_at": "string (ISO-8601)",
  "deleted_at": "string (ISO-8601) | null"
}
```

### `GET /api/v1/transactions`
### `GET /api/v1/transactions/{id}`

Transaction object:

```json
{
  "id": "string (ULID)",
  "user_id": "string (ULID)",
  "account_id": "string (ULID)",
  "category_id": "string (ULID)",
  "currency_id": "string (ULID)",
  "exchange_rate_to_anchor": "string (decimal)",
  "type": "income | expense",
  "amount": "string (decimal)",
  "description": "string | null",
  "transaction_date": "string (YYYY-MM-DD)",
  "created_at": "string (ISO-8601)",
  "updated_at": "string (ISO-8601)",
  "deleted_at": "string (ISO-8601) | null"
}
```

### `GET /api/v1/transfers`
### `GET /api/v1/transfers/{id}`

Transfer object:

```json
{
  "id": "string (ULID)",
  "user_id": "string (ULID)",
  "from_account_id": "string (ULID)",
  "to_account_id": "string (ULID)",
  "from_amount": "string (decimal)",
  "to_amount": "string (decimal)",
  "exchange_rate": "string (decimal) | null",
  "fee": "string (decimal)",
  "description": "string | null",
  "transfer_date": "string (YYYY-MM-DD)",
  "created_at": "string (ISO-8601)",
  "updated_at": "string (ISO-8601)",
  "deleted_at": "string (ISO-8601) | null"
}
```

### `GET /api/v1/liabilities`
### `GET /api/v1/liabilities/{id}`

Liability object:

```json
{
  "id": "string (ULID)",
  "user_id": "string (ULID)",
  "user_currency_id": "string (ULID)",
  "name": "string",
  "type": "loan | credit_card | personal",
  "principal_amount": "string (decimal)",
  "interest_rate": "string (decimal) | null",
  "due_date": "string (YYYY-MM-DD) | null",
  "notes": "string | null",
  "is_settled": "boolean",
  "created_at": "string (ISO-8601)",
  "updated_at": "string (ISO-8601)",
  "deleted_at": "string (ISO-8601) | null"
}
```

### `GET /api/v1/liability-payments`
### `GET /api/v1/liability-payments/{id}`

Liability payment object:

```json
{
  "id": "string (ULID)",
  "liability_id": "string (ULID)",
  "account_id": "string (ULID)",
  "amount": "string (decimal)",
  "payment_date": "string (YYYY-MM-DD)",
  "note": "string | null",
  "created_at": "string (ISO-8601)",
  "updated_at": "string (ISO-8601)",
  "deleted_at": "string (ISO-8601) | null"
}
```

---

## 3. Batch sync endpoint

### `POST /api/v1/sync/push`

Authenticated only.

Request body:

```json
{
  "changes": [
    {
      "client_change_id": "string",
      "entity": "account | transaction | user_currency | user_category | transfer | liability | liability_payment",
      "op": "create | update | delete",
      "id": "string (ULID)",
      "data": { "...entity-specific fields..." }
    }
  ]
}
```

Response `200`:

```json
{
  "results": [
    {
      "client_change_id": "string",
      "id": "string (ULID)",
      "entity": "string",
      "status": "applied | failed",
      "record": { "...resource fields..." },
      "error": {
        "message": "string",
        "errors": { "field": ["string"] }
      }
    }
  ],
  "server_time": "string (ISO-8601)"
}
```

### Entity-specific `data` payloads

#### Account
```json
{
  "user_currency_id": "string (ULID)",
  "name": "string",
  "type": "cash | bank | e_wallet | other",
  "initial_balance": "string (decimal)",
  "is_default": "boolean"
}
```

#### Transaction
```json
{
  "account_id": "string (ULID)",
  "category_id": "string (ULID)",
  "currency_id": "string (ULID)",
  "exchange_rate_to_anchor": "string (decimal)",
  "type": "income | expense",
  "amount": "string (decimal)",
  "description": "string | null",
  "transaction_date": "string (YYYY-MM-DD)"
}
```

#### User currency
```json
{
  "currency_id": "string (ULID)",
  "exchange_rate": "string (decimal)",
  "is_anchor": "boolean"
}
```

#### User category
```json
{
  "name": "string",
  "type": "income | expense",
  "icon": "string",
  "color": "string | null"
}
```

#### Transfer
```json
{
  "from_account_id": "string (ULID)",
  "to_account_id": "string (ULID)",
  "from_amount": "string (decimal)",
  "to_amount": "string (decimal)",
  "exchange_rate": "string (decimal) | null",
  "fee": "string (decimal)",
  "description": "string | null",
  "transfer_date": "string (YYYY-MM-DD)"
}
```

#### Liability
```json
{
  "user_currency_id": "string (ULID)",
  "name": "string",
  "type": "loan | credit_card | personal",
  "principal_amount": "string (decimal)",
  "interest_rate": "string (decimal) | null",
  "due_date": "string (YYYY-MM-DD) | null",
  "notes": "string | null",
  "is_settled": "boolean"
}
```

#### Liability payment
```json
{
  "liability_id": "string (ULID)",
  "account_id": "string (ULID)",
  "amount": "string (decimal)",
  "payment_date": "string (YYYY-MM-DD)",
  "note": "string | null"
}
```

---

## 4. Notes
- Monetary values are sent and returned as strings to preserve precision.
- The API currently supports the core wallet workflow and does not implement transaction attachment upload/download yet.
- **Avatar storage (S3):** avatars use the `s3` disk regardless of `FILESYSTEM_DISK`. Set `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` (all scaffolded in `.env.example`). Requires the `league/flysystem-aws-s3-v3` package. For the public `avatar_url` to be readable, grant public GET on the `avatars/*` prefix via a bucket policy:
  ```json
  {
    "Version": "2012-10-17",
    "Statement": [{
      "Effect": "Allow",
      "Principal": "*",
      "Action": "s3:GetObject",
      "Resource": "arn:aws:s3:::YOUR_BUCKET/avatars/*"
    }]
  }
  ```
  and adjust the bucket's "Block Public Access" so bucket-policy public access is allowed. The app IAM user needs `s3:PutObject`, `s3:GetObject`, `s3:DeleteObject` on `arn:aws:s3:::YOUR_BUCKET/*`.

---

## 5. Changelog

- **1.2.0** (2026-07-24)
  - Added `POST`/`DELETE /auth/profile/avatar` for profile-picture upload/removal (S3, public-read).
  - Added `avatar_url` (public URL derived from `avatar_path`) to the user object.
- **1.1.0** (2026-07-24)
  - Added `avatar_path` to the user object in register/login/profile responses.
  - Added the `user_category` sync entity (pushable) and its `user_categories` sync-pull collection; documented the user-category object shape.
  - Transaction `category_id` now references a user-owned `user_category` (not a global `category`).
- **1.0.0** — Initial v1 reference.
