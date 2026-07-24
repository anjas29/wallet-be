# Changelog

Notable changes to the Wallet API and its data model, newest first. The API itself is path-versioned (`/api/v1`); entries below are dated rather than semver-tagged. The authoritative, always-current endpoint reference is the **[Docs API](/docs/api)**.

## 2026-07-24

- **Profile picture (S3).** Added `POST /api/v1/auth/profile/avatar` and `DELETE /api/v1/auth/profile/avatar` (multipart `avatar`, ≤ 2 MB, `jpg`/`jpeg`/`png`/`webp`). Files are stored on the `s3` disk under `avatars/{user_id}/…` with public read via bucket policy; the user object gains `avatar_path` and a public `avatar_url`.
- **Public reference data.** `GET /currencies`, `GET /currencies/{id}`, `GET /categories`, `GET /categories/{id}` are now open (no auth), so the client can load them before login.
- **User-owned categories.** New `user_categories` table and `user_category` sync entity (create/update/delete via `/sync/push`, delivered in `/sync/pull`). `transactions.category_id` now references a user-owned category (existing rows backfilled from the global template). The global `categories` table remains read-only and is the template the client seeds from.
- **Profile picture column.** Added `users.avatar_path`.

## 2026-07-09

- Initial `v1`: authentication (register / login / refresh / logout / logout-all / profile), read endpoints (currencies, categories, user-currencies, accounts, transactions, transfers, liabilities, liability-payments), and offline-first sync (`GET /sync/pull`, `POST /sync/push`).
