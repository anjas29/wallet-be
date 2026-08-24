# Changelog

Notable changes to the Wallet API and its data model, newest first. The API itself is path-versioned (`/api/v1`); entries below are dated rather than semver-tagged. The authoritative, always-current endpoint reference is the **[Docs API](/docs/api)**.

## 2026-08-24

Full field-by-field detail for this release's sync changes: **[Sync Changes — v1.2.0](/docs/sync-changes-1.2.0)**.

- **Budgets.** New `budget` entity — a spending cap on an expense category, monthly or custom period, with derived `spent`/`remaining`.
- **Recurring transactions.** New `recurring_transaction` entity — a template that generates real `transaction` rows on a schedule, server-side, so bills post even if the app isn't opened. Requires `schedule:run` wired into the deployment (not yet done).
- **Liability derived fields.** `liability` reads now include derived `paid_amount` and `remaining_balance`.
- **Liability payments in the transaction report.** The PDF ledger now includes `liability_payments` (previously excluded — see 2026-08-21 below) plus a `totalLiabilityPayment` total.
- **AI analyst (Gemini, streaming).** Added `POST /api/v1/ai/chat` — a free-form, **read-only** financial chat. Unlike every other endpoint it returns a **Server-Sent Event stream** (`text/event-stream`), not the standard JSON envelope: `meta` (ids, first), `tool` (each data lookup), `delta` (answer chunks), `done`, or `error`, terminated by a `</stream>` sentinel. Errors *before* the stream opens (validation, auth, unconfigured key) still use the normal envelope. The model answers via Gemini function calling over six scoped aggregates — account balances, spending by category, income/expense trend, transaction lookup, budget status and liabilities — all converted to the user's anchor currency; it can read but never write. Throttled to 20 requests/minute. Requires the existing `GEMINI_API_KEY`; round trips per turn are capped by `GEMINI_MAX_TOOL_ITERATIONS` (default 5), and at the cap the model is forced to answer from what it has rather than failing.
- **AI conversations.** New `ai_conversations` and `ai_messages` tables. `GET /api/v1/ai/conversations` lists chats newest-first, `GET /api/v1/ai/conversations/{id}` returns one with its full transcript, `DELETE` removes it. Conversations are a sync entity (`ai_conversation`, delivered in `/sync/pull`; `/sync/push` accepts `update` for the title and `delete`, but refuses `create` — chats originate from `POST /ai/chat`). **Transcripts are deliberately not synced**: messages are large free text and unbounded in count, so they are read per-conversation rather than on every pull.

## 2026-08-21

- **Transaction report (PDF, S3).** Added `GET /api/v1/reports/transactions` (`period` query param: `yyyy-mm`, `yyyy`, or omitted for all-time). Generates a PDF statement covering every account — opening/closing balance, a chronological ledger merging `transactions` and `transfers` with a running balance, per-account totals, and an overall net-change summary converted to the user's anchor currency. Uploads to a private `reports/{user_id}/…` path on the `s3` disk and returns a 60-minute temporary signed `url`. Synchronous — the request blocks until the PDF is built and uploaded.

## 2026-08-20

- **Receipt scanning (Gemini).** Added `POST /api/v1/receipts/scan` (multipart `receipt`, ≤ 5 MB, `jpg`/`png`/`webp`). Sends the photo to the Gemini Developer API (`generateContent`, structured `responseSchema`) and returns `is_valid`, `merchant_name`, `transaction_date`, `total_amount`, `currency_code`, and a line-item breakdown. Each item's `category_id`/`category_name` is constrained to the authenticated user's own `user_categories` (schema enum + prompt-supplied id→name list, so the model can't hallucinate a category). `currency_code` is detected from the receipt and cross-checked against the `currencies` table, falling back to `null` when unrecognized or unclear. Requires `GEMINI_API_KEY` (and optional `GEMINI_MODEL`, default `gemini-3.6-flash`) in `.env`; upstream failures surface as a `502`, a user with no categories yet gets a `422`.

## 2026-08-06

- **Onboarding flag.** `on_board_required` added to the user object (register/login/profile responses) — computed from whether the account has ever had an account created (`withTrashed`), not a stored column. See [Onboarding & Sync client flow](/docs/onboarding-and-sync-client-flow).
- **Default category seeding.** Registration now seeds a new user's `user_categories` from the global `categories` template inside the same transaction as account creation.
- **Update profile.** Added `PUT /api/v1/auth/profile` to update the authenticated user's name.

## 2026-07-24

- **Profile picture (S3).** Added `POST /api/v1/auth/profile/avatar` and `DELETE /api/v1/auth/profile/avatar` (multipart `avatar`, ≤ 2 MB, `jpg`/`jpeg`/`png`/`webp`). Files are stored on the `s3` disk under `avatars/{user_id}/…` with public read via bucket policy; the user object gains `avatar_path` and a public `avatar_url`.
- **Public reference data.** `GET /currencies`, `GET /currencies/{id}`, `GET /categories`, `GET /categories/{id}` are now open (no auth), so the client can load them before login.
- **User-owned categories.** New `user_categories` table and `user_category` sync entity (create/update/delete via `/sync/push`, delivered in `/sync/pull`). `transactions.category_id` now references a user-owned category (existing rows backfilled from the global template). The global `categories` table remains read-only and is the template the client seeds from.
- **Profile picture column.** Added `users.avatar_path`.

## 2026-07-09

- Initial `v1`: authentication (register / login / refresh / logout / logout-all / profile), read endpoints (currencies, categories, user-currencies, accounts, transactions, transfers, liabilities, liability-payments), and offline-first sync (`GET /sync/pull`, `POST /sync/push`).
