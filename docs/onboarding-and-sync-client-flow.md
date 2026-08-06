# Onboarding & Sync — Client Flow (All Possibilities)

## Entry point: every login / app-start

Two independent signals are checked, **always both, never short-circuited**:

1. **`on_board_required`** — server truth (account-level), from register/login/profile response
2. **`initialSyncCompletedAt`** — local truth (device-level), a flag in Room, set only after a full successful `sync/pull`

## The 4-cell matrix → 3 real branches

| | Local DB empty | Local DB populated |
|---|---|---|
| **`on_board_required=true`** | **Branch A** — New user | Anomalous (shouldn't occur) |
| **`on_board_required=false`** | **Branch B** — Existing user, new device | **Branch C** — Normal returning login |

### Branch A — brand-new user
`Onboarding UI (currency + first account)` → account creation flips server flag → `Sync (full pull, no since)` → `Home`

### Branch B — existing account, empty local DB (reinstall / new device)
Skip onboarding UI entirely → `Sync (full pull, no since)` → `Home`

### Branch C — normal returning login
Straight to `Home`, optionally kicking off a **background delta sync** (uses `since`, non-blocking)

### Anomalous cell
`on_board_required=true` + local DB populated — flagged as "shouldn't normally happen" (would imply a completed account with no data anywhere). No handling is prescribed beyond noting it's unexpected.

## Full flowchart

```
                         Login/Register response
                                  │
                    ┌─────────────┴─────────────┐
              on_board_required=true      on_board_required=false
                    │                              │
          ┌─────────┴─────────┐          ┌─────────┴─────────┐
     local DB empty      local DB       local DB empty   local DB
                          populated                       populated
          │                  │               │                │
     [Branch A]         (anomalous,      [Branch B]      [Branch C]
    Onboarding UI        undefined)     skip onboarding      Home
   (currency+account)                    UI directly     (bg delta sync
          │                                   │             optional)
    flag flips server-                        │
    side on create                            │
          │                                   │
          └───────────────┬───────────────────┘
                           ▼
                Sync (full pull, no `since`)
              (paginate all entities: currencies,
               categories, user_categories, etc.)
                           │
                  all pages succeed?
                    │            │
                   yes           no (killed / connectivity drop)
                    │            │
          set initialSyncCompletedAt   nothing persisted;
                    │            relaunch → same branch
                    ▼            re-enters Sync screen,
                  Home            restarts full pull from
                                  scratch (stateless, safe)
```

## Interruption / resilience paths (composable with any branch)

**Onboarding interrupted** (app killed mid-flow, offline during account creation):
- Relaunch → re-check `on_board_required` fresh from server (or cached value + background refresh) — never trust a stale local "onboarding done" flag
- If the create was queued offline, it rides the same `pendingOp` / client-ULID upsert mechanism as any other write — safe to retry, no special-case protocol

**Sync interrupted** (killed mid-pull, connectivity drop, mid-pagination):
- Because the completion flag is set *only* after full success across all paginated pages, an interrupted sync simply looks "not done" on relaunch
- Client resumes by restarting the full pull from scratch (`sync/pull` is stateless — no server-side per-device cursor is wired up, so no partial-resume state to reconcile)
- Explicitly *not* using a row-count check to detect "empty" — that can't tell "never started" apart from "died mid-pagination with partial rows"

## Multi-device concrete walkthrough (as given)

- **Phone A** (new): register → A=true/empty → Branch A → Onboarding → flag flips server-side → Sync → Home
- **Phone B** (same account, added later): login → B sees `on_board_required=false` immediately (server truth, device-independent) but B's local DB is empty → Branch B → skip onboarding, straight to Sync → pulls everything A created + seeded categories → Home

The doc's explicit warning: **B must never land in onboarding** (empty DB alone doesn't mean new account) **and must never skip straight to Home** (false flag alone doesn't mean this device has data). Both signals gate independently, every single time.

## Screen/state ownership (kept separate, not fused)
- **Onboarding** = one-time, account-level, minimal UI (currency + first account) — its only job is flipping the server flag
- **Sync** = device-level, reused as-is whether entered from Branch A (post-onboarding) or Branch B (empty device) — same pagination/retry/resume handling, no duplicated logic
- **Home** = terminal state for all branches, with Branch C optionally trailing a non-blocking background delta sync
