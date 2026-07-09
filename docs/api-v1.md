# Wallet API v1 — Implementation Reference

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
  "user": { "id": "string (ULID)", "name": "string", "email": "string", "role": "super_admin | user" },
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
    "created_at": "string (ISO-8601)",
    "updated_at": "string (ISO-8601)"
  }
}
```

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
      "entity": "account | transaction | user_currency | transfer | liability | liability_payment",
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
