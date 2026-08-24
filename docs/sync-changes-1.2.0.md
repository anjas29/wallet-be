# Sync Changes — v1.2.0 (2026-08-24)

A field-by-field diff of what changed in `POST /api/v1/sync/push` and `GET /api/v1/sync/pull` in this release: two new entities (`budget`, `recurring_transaction`) and new derived fields on `liability`. For full request/response payload examples, see **[Push Changes](/docs/push-changes)** — this page is the detailed companion for client implementers, not a replacement.

## New entity: `budget`

- **Table:** `budgets`
- **Push:** entity `budget` — create / update / delete
- **Pull:** new top-level key `budgets` in `GET /sync/pull`
- **REST reads:** `GET /api/v1/budgets`, `GET /api/v1/budgets/{id}`

| Field | Type | Notes |
|---|---|---|
| `id` | ulid | |
| `user_id` | ulid | |
| `category_id` | ulid | must be a `user_category` you own, **type `expense`** — rejected otherwise |
| `amount` | decimal(15,2) | the budget cap |
| `period_type` | `monthly` \| `custom` | |
| `period_start` | date, nullable | **required** if `custom`, **must be omitted** if `monthly` |
| `period_end` | date, nullable | **required** if `custom` (`>= period_start`), **must be omitted** if `monthly` |
| `spent` | decimal string, derived | **read-only** — sum of matching `transaction` rows for the resolved period; absent on push responses |
| `remaining` | decimal string, derived | **read-only** — `amount - spent`; absent on push responses |
| `created_at` / `updated_at` / `deleted_at` | ISO-8601 | |

A `monthly` budget has no stored dates — its window is always "the current calendar month," recomputed on every read. `spent`/`remaining` are therefore never stable across a month boundary; there's no historical snapshot of a past month's performance.

## New entity: `recurring_transaction`

- **Table:** `recurring_transactions`
- **Push:** entity `recurring_transaction` — create / update / delete
- **Pull:** new top-level key `recurring_transactions` in `GET /sync/pull`
- **REST reads:** `GET /api/v1/recurring-transactions`, `GET /api/v1/recurring-transactions/{id}`

| Field | Type | Notes |
|---|---|---|
| `id` | ulid | |
| `user_id` | ulid | |
| `account_id` | ulid | must be an account you own |
| `category_id` | ulid | must be a `user_category` you own |
| `amount` | decimal(15,2) | |
| `description` | string, nullable | |
| `frequency` | `daily` \| `weekly` \| `monthly` \| `yearly` | |
| `start_date` | date | first occurrence |
| `end_date` | date, nullable | generation stops once passed |
| `next_run_date` | date, nullable, derived | **server-computed and read-only** — any value sent by the client is ignored |
| `is_active` | boolean, default `true` | set `false` to pause without deleting |
| `created_at` / `updated_at` / `deleted_at` | ISO-8601 | |

**Behavior to design around:**
- A server-side scheduled job (`recurring-transactions:generate`, runs daily) creates real `transaction` rows from due templates — this happens even if the app is never opened.
- Generated transactions have **no marker** distinguishing them from manually entered ones (no `is_auto_generated` flag exists or is planned). They arrive through the existing `transaction` entity in the next pull, and are fully editable/deletable like any other transaction.
- `type` on the generated transaction is derived from the category, not client-supplied — there's nothing for the client to reconcile here.
- If a template's `account_id`/`category_id` is later soft-deleted, generation silently stops for that template (no error surfaced) rather than failing.
- If the server is down for several days, the next run catches up all missed occurrences (capped at 366 per template per run) rather than skipping them.

## New fields on `liability`

`paid_amount` and `remaining_balance` are now included on:
- `GET /liabilities`
- `GET /liabilities/{id}`
- `GET /sync/pull` (`liabilities` collection)

| Field | Type | Notes |
|---|---|---|
| `paid_amount` | decimal string, derived | sum of the liability's `liability_payment` rows |
| `remaining_balance` | decimal string, derived | `principal_amount - paid_amount`, floored at `0` |

Both are **absent** (not `null` — omitted) on `POST /sync/push` create/update responses for `liability`, the same way `account.balance` behaves on push. If you need the up-to-date value immediately after a push, re-fetch via `GET /liabilities/{id}` or wait for the next pull.

## Report changes (not sync, but related)

`GET /api/v1/reports/transactions` now includes `liability_payment` rows in the merged chronological ledger (previously only `transaction` + `transfer`), and each account statement gains a `totalLiabilityPayment` total alongside the existing income/expense/transfer totals.

## Entity × operation table delta

| entity | create | update | delete |
|---|:--:|:--:|:--:|
| `budget` | ✓ *(new)* | ✓ *(new)* | ✓ *(new)* |
| `recurring_transaction` | ✓ *(new)* | ✓ *(new)* | ✓ *(new)* |

## Mobile / Room migration checklist

- Bump Room `@Database` version `2` → `3`.
- Add `BudgetEntity` (table `budgets`) and `RecurringTransactionEntity` (table `recurring_transactions`).
- Add `LiabilityEntity.paidAmount` / `.remainingBalance` (nullable columns via `ALTER TABLE`).
- Apply pull order: append `budgets` and `recurring_transactions` after `liability_payments`.
- See **[Android Room Schema](/docs/android-room-schema)** for the full entity definitions and migration SQL.

## Deployment note

`recurring-transactions:generate` is registered on Laravel's scheduler but **requires `php artisan schedule:run` to be invoked every minute** (host cron or a `schedule:work` process) in the deployed environment. This is not yet wired into this repo's `docker-compose.yml`, or confirmed on the deployed VPS compose — add it before relying on recurring transactions actually generating.
