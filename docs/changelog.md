# Changelog

Notable changes to the Wallet API and its data model, newest first. The API itself is path-versioned (`/api/v1`); entries below are dated rather than semver-tagged. The authoritative, always-current endpoint reference is the **[Docs API](/docs/api)**.

## 2026-08-25

- **AI analyst: image attachments.** `POST /api/v1/ai/chat` now also accepts `multipart/form-data` with an `image` field (`jpg`/`png`/`webp`) — a receipt, a statement, a screenshot. `message` becomes optional when an image is sent, so the photo can be the whole question. Attachments are stored privately on the `s3` disk under `ai-attachments/{user_id}/` and come back on `GET /ai/conversations/{id}` as `image_url`, a **signed URL valid for 60 minutes** (re-read the conversation to refresh it) plus `image_mime`. Uploads are capped at `GEMINI_IMAGE_MAX_UPLOAD_KB` (default 1536) and **downscaled server-side** to `GEMINI_IMAGE_MAX_EDGE` (default 1024 px longest edge), re-encoded as JPEG with EXIF rotation baked in; only that copy is stored or sent, so full-resolution uploads buy nothing. Only the newest `GEMINI_MAX_HISTORY_IMAGES` (default 1) attachments in a chat are re-sent on later turns — every image is re-billed on every round trip — and older ones degrade to a text note. The analyst reads images but never generates them; every answer is still text.
- **AI analyst: free-tier rations.** Two per-user, per-calendar-day quotas, refused with a normal `429` envelope **before** the stream opens: `GEMINI_DAILY_TURN_LIMIT` messages (default 20) and `GEMINI_DAILY_IMAGE_LIMIT` attachments (default 10) — hitting the image limit still leaves text questions working. The burst throttle on `POST /ai/chat` drops from 20/min to 5/min. These exist because one chat *turn* is up to `GEMINI_MAX_TOOL_ITERATIONS` upstream requests, not one, and the Gemini key is shared with receipt scanning.
- **AI analyst: record reference tags.** Answers may now contain inline markers naming a record the analyst is talking about — `[[transaction:<ulid>]]`, `[[transfer:<ulid>]]`, `[[liability:<ulid>]]` — so the app can render a tappable chip that opens it. The client resolves the label from its **local** copy (all three entities already arrive via `/sync/pull`), so no extra request is needed. Every id is verified against the database, scoped to the asking user, **before** the text is streamed: a hallucinated, foreign or soft-deleted id is removed, and the prose is written to read correctly with all tags stripped. Match with `\[\[(transaction|transfer|liability):([0-9A-Za-z]{26})\]\]` against the accumulated answer, not a single `delta` — a tag routinely straddles several.
- **AI analyst: `list_transfers` tool.** The analyst can now see transfers between the user's own accounts. Previously no tool surfaced them at all, so a balance that dropped without any spending was unexplainable. `list_transactions` and `get_liabilities` now also return each row's `id`, which is what makes a reference tag possible.

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
