# Wallet API — Documentation

Everything you need to integrate with the Wallet backend. Base URL: `/api/v1` (all authenticated routes use a Bearer token from Sanctum).

## Reference

- **[API Docs](/docs/api)** — live OpenAPI documentation (Scramble).

## Guides

- **[Server database schema](/docs/database-schema)** — the authoritative PostgreSQL schema: tables, columns, relationships, and conventions (ULIDs, money, soft deletes, sync cursors).
- **[Android Room schema](/docs/android-room-schema)** — the offline-first client mirror of the server schema and how it reconciles via sync.
- **[Push changes (sync)](/docs/push-changes)** — the batch write contract for `POST /api/v1/sync/push`, with per-entity payloads and result semantics.

## Meta

- **[Changelog](/docs/changelog)** — notable API and data-model changes, by version.
