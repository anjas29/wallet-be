# Changelog

Notable changes to the Wallet API and its data model, newest first. The API itself is path-versioned (`/api/v1`); entries below are dated rather than semver-tagged. The authoritative, always-current endpoint reference is the **[Docs API](/docs/api)**.

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
