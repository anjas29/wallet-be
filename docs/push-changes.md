# Push Changes — `POST /api/v1/sync/push`

_Version **1.1.0** — 2026-07-24 · see [Changelog](#changelog)_

The single write path for wallet data (offline-first, batch). GET endpoints are read-only.

- **Auth:** `Authorization: Bearer <access_token>` (+ `Content-Type: application/json`, `Accept: application/json`).
- **Batch:** send a list of `changes`; each is applied independently.
- **Partial success:** always HTTP `200`; each item reports `applied` or `failed`. Good items commit even if others fail.
- **IDs:** `id` is a **client-generated ULID**. `create`/`update` upsert by it (retries are safe). `delete` is a soft delete and idempotent.
- `client_change_id` is echoed back so you can reconcile each result.

## Change object

```
{ "client_change_id": "c1", "entity": "<entity>", "op": "create|update|delete", "id": "<client ULID>", "data": { } }
```

`delete` needs only `entity`, `op`, `id` (no `data`).

## Entity × operation

| entity | create | update | delete |
|---|:--:|:--:|:--:|
| `transaction` | ✓ | ✓ | ✓ |
| `transfer` | ✓ | ✓ | ✓ |
| `account` | ✓ | ✓ | ✓ |
| `user_currency` | ✓ | ✓ | ✗ |
| `user_category` | ✓ | ✓ | ✓ |
| `liability` | ✓ | ✓ | ✓ |
| `liability_payment` | ✓ | ✓ | ✓ |

`currency` and `category` are global reference data — not writable here. User-owned categories are written via `user_category` (the mobile client seeds these from the global `category` list, then users can add their own). _(`user_category` added v1.1.0.)_

## Response

```
{
  "success": true,
  "status_code": 200,
  "message": "",
  "data": {
    "results": [
      {
        "client_change_id": "c1",
        "id": "01...",
        "entity": "transaction",
        "status": "applied",
        "record": { }
      },
      {
        "client_change_id": "c2",
        "id": "01...",
        "entity": "transaction",
        "status": "failed",
        "error": {
          "message": "Validation failed.",
          "errors": { "data": ["The selected account or category is invalid."] }
        }
      }
    ],
    "server_time": "2026-07-10T03:00:00.000000Z"
  }
}
```

`applied` create/update includes the resulting `record`; `delete` omits it. `401` if unauthenticated, `422` if `changes` is missing/not an array.

---

## Full request (multiple entities in one call)

```
{
  "changes": [
    {
      "client_change_id": "c1",
      "entity": "user_currency",
      "op": "create",
      "id": "01UC00000000000000000UC01",
      "data": {
        "currency_id": "01CUR0000000000000000USD1",
        "exchange_rate": "1",
        "is_anchor": true
      }
    },
    {
      "client_change_id": "c2",
      "entity": "account",
      "op": "create",
      "id": "01AC00000000000000000AC01",
      "data": {
        "user_currency_id": "01UC00000000000000000UC01",
        "name": "Cash",
        "type": "cash",
        "color": "#22C55E",
        "initial_balance": "100.00",
        "is_default": true
      }
    },
    {
      "client_change_id": "c3",
      "entity": "transaction",
      "op": "create",
      "id": "01TX00000000000000000TX01",
      "data": {
        "account_id": "01AC00000000000000000AC01",
        "category_id": "01CT00000000000000000CT01",
        "type": "expense",
        "amount": "12.50",
        "transaction_date": "2026-07-10"
      }
    }
  ]
}
```

---

## transaction

A transaction is always in its account's currency (no `currency_id`). `type`: `income` | `expense`.
Required: `account_id`, `category_id`, `type`, `amount`, `transaction_date`. Optional: `exchange_rate_to_anchor` (default `1`), `description`. As of v1.1.0, `category_id` must be a **`user_category` you own** (not a global `category`).

**create**
```
{
  "client_change_id": "c1",
  "entity": "transaction",
  "op": "create",
  "id": "01TX00000000000000000TX01",
  "data": {
    "account_id": "01AC00000000000000000AC01",
    "category_id": "01CT00000000000000000CT01",
    "type": "expense",
    "amount": "12.50",
    "exchange_rate_to_anchor": "1",
    "description": "Lunch",
    "transaction_date": "2026-07-10"
  }
}
```

**update**
```
{
  "client_change_id": "c1",
  "entity": "transaction",
  "op": "update",
  "id": "01TX00000000000000000TX01",
  "data": {
    "account_id": "01AC00000000000000000AC01",
    "category_id": "01CT00000000000000000CT01",
    "type": "expense",
    "amount": "9.00",
    "transaction_date": "2026-07-10"
  }
}
```

**delete**
```
{
  "client_change_id": "c1",
  "entity": "transaction",
  "op": "delete",
  "id": "01TX00000000000000000TX01"
}
```

## transfer

Move money between two of your accounts. Required: `from_account_id`, `to_account_id` (must differ), `from_amount`, `to_amount`, `transfer_date`. Optional: `exchange_rate`, `fee` (default `0`), `description`.

**create**
```
{
  "client_change_id": "c2",
  "entity": "transfer",
  "op": "create",
  "id": "01TR00000000000000000TR01",
  "data": {
    "from_account_id": "01AC00000000000000000AC01",
    "to_account_id": "01AC00000000000000000AC02",
    "from_amount": "100.00",
    "to_amount": "100.00",
    "exchange_rate": "1",
    "fee": "0",
    "description": "Move to savings",
    "transfer_date": "2026-07-10"
  }
}
```

**update**
```
{
  "client_change_id": "c2",
  "entity": "transfer",
  "op": "update",
  "id": "01TR00000000000000000TR01",
  "data": {
    "from_account_id": "01AC00000000000000000AC01",
    "to_account_id": "01AC00000000000000000AC02",
    "from_amount": "80.00",
    "to_amount": "80.00",
    "fee": "0",
    "transfer_date": "2026-07-10"
  }
}
```

**delete**
```
{
  "client_change_id": "c2",
  "entity": "transfer",
  "op": "delete",
  "id": "01TR00000000000000000TR01"
}
```

## account

`type`: `cash` | `bank` | `e_wallet` | `other`. Required: `user_currency_id`, `name`, `type`. Optional: `color` (`#RRGGBB`, default `#64748B`), `initial_balance` (default `0`), `is_default` (default `false`). Setting `is_default` unsets it on your other accounts.

**create**
```
{
  "client_change_id": "c3",
  "entity": "account",
  "op": "create",
  "id": "01AC00000000000000000AC03",
  "data": {
    "user_currency_id": "01UC00000000000000000UC01",
    "name": "Savings",
    "type": "bank",
    "color": "#22C55E",
    "initial_balance": "0",
    "is_default": false
  }
}
```

**update**
```
{
  "client_change_id": "c3",
  "entity": "account",
  "op": "update",
  "id": "01AC00000000000000000AC03",
  "data": {
    "user_currency_id": "01UC00000000000000000UC01",
    "name": "Savings (main)",
    "type": "bank",
    "is_default": true
  }
}
```

**delete**
```
{
  "client_change_id": "c3",
  "entity": "account",
  "op": "delete",
  "id": "01AC00000000000000000AC03"
}
```

## user_currency

Your currency holdings. **No delete.** Required: `currency_id`. Optional: `exchange_rate` (default `1`), `is_anchor` (default `false`). Setting `is_anchor` unsets it on your other currencies. `currency_id` must be unique per user.

**create**
```
{
  "client_change_id": "c4",
  "entity": "user_currency",
  "op": "create",
  "id": "01UC00000000000000000UC01",
  "data": {
    "currency_id": "01CUR0000000000000000USD1",
    "exchange_rate": "1",
    "is_anchor": true
  }
}
```

**update**
```
{
  "client_change_id": "c4",
  "entity": "user_currency",
  "op": "update",
  "id": "01UC00000000000000000UC01",
  "data": {
    "currency_id": "01CUR0000000000000000USD1",
    "exchange_rate": "1.05",
    "is_anchor": true
  }
}
```

## user_category

Your own categories (seeded client-side from the global `GET /categories` list, plus any you add). `type`: `income` | `expense`. Required: `name`, `type`, `icon`. Optional: `color` (`#RRGGBB`). Transactions reference a `user_category` you own via `category_id`. _(Added v1.1.0.)_

**create**
```
{
  "client_change_id": "c7",
  "entity": "user_category",
  "op": "create",
  "id": "01UT00000000000000000UT01",
  "data": {
    "name": "Groceries",
    "type": "expense",
    "icon": "shopping-basket",
    "color": "#D81B60"
  }
}
```

**update**
```
{
  "client_change_id": "c7",
  "entity": "user_category",
  "op": "update",
  "id": "01UT00000000000000000UT01",
  "data": {
    "name": "Groceries & Household",
    "type": "expense",
    "icon": "shopping-basket",
    "color": "#D81B60"
  }
}
```

**delete**
```
{
  "client_change_id": "c7",
  "entity": "user_category",
  "op": "delete",
  "id": "01UT00000000000000000UT01"
}
```

## liability

`type`: `loan` | `credit_card` | `personal`. Required: `user_currency_id`, `name`, `type`, `principal_amount`. Optional: `interest_rate`, `due_date`, `notes`, `is_settled` (default `false`).

**create**
```
{
  "client_change_id": "c5",
  "entity": "liability",
  "op": "create",
  "id": "01LB00000000000000000LB01",
  "data": {
    "user_currency_id": "01UC00000000000000000UC01",
    "name": "Car Loan",
    "type": "loan",
    "principal_amount": "5000.00",
    "interest_rate": "2.50",
    "due_date": "2027-01-01",
    "notes": "36 months",
    "is_settled": false
  }
}
```

**update**
```
{
  "client_change_id": "c5",
  "entity": "liability",
  "op": "update",
  "id": "01LB00000000000000000LB01",
  "data": {
    "user_currency_id": "01UC00000000000000000UC01",
    "name": "Car Loan",
    "type": "loan",
    "principal_amount": "4500.00",
    "is_settled": false
  }
}
```

**delete**
```
{
  "client_change_id": "c5",
  "entity": "liability",
  "op": "delete",
  "id": "01LB00000000000000000LB01"
}
```

## liability_payment

A payment against a liability, from one of your accounts (ownership is via the parent liability). Required: `liability_id`, `account_id`, `amount`, `payment_date`. Optional: `note`.

**create**
```
{
  "client_change_id": "c6",
  "entity": "liability_payment",
  "op": "create",
  "id": "01LP00000000000000000LP01",
  "data": {
    "liability_id": "01LB00000000000000000LB01",
    "account_id": "01AC00000000000000000AC01",
    "amount": "250.00",
    "payment_date": "2026-07-10",
    "note": "July installment"
  }
}
```

**update**
```
{
  "client_change_id": "c6",
  "entity": "liability_payment",
  "op": "update",
  "id": "01LP00000000000000000LP01",
  "data": {
    "liability_id": "01LB00000000000000000LB01",
    "account_id": "01AC00000000000000000AC01",
    "amount": "300.00",
    "payment_date": "2026-07-10"
  }
}
```

**delete**
```
{
  "client_change_id": "c6",
  "entity": "liability_payment",
  "op": "delete",
  "id": "01LP00000000000000000LP01"
}
```

---

## Changelog

- **1.1.0** (2026-07-24) — Added the `user_category` entity (create/update/delete). Transaction `category_id` must now reference a `user_category` you own rather than a global `category`.
- **1.0.0** — Initial push-changes contract.
