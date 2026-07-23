# Android Room Schema (Offline-First) — mirrors the Wallet BE

_Doc version **1.1.0** — 2026-07-24 · Room `@Database(version = 2)` · see [Changelog](#changelog)_

A local Room mirror of the backend, built for full offline support: read/write locally, then reconcile via `GET /api/v1/sync/pull` (download) and `POST /api/v1/sync/push` (upload).

## Conventions

| BE type | Room / Kotlin | Notes |
|---|---|---|
| ULID `id` (string) | `String` `@PrimaryKey` | Client **generates ULIDs locally** for new rows (same id the server upserts by). |
| `decimal(15,2)` money | `String` | Mirrors the API 1:1 (`"12.50"`); parse to `BigDecimal` for math. Never `Float`/`Double`. (Alt: `Long` minor units.) |
| `date` (`2026-07-10`) | `String` | ISO date; convert to `LocalDate` in the domain layer if needed. |
| `timestamp` (ISO-8601) | `String` | `created_at`/`updated_at`/`deleted_at`. `updated_at` is the **sync cursor** field. |
| enum (`type`, …) | `String` (+ Kotlin enum via `@TypeConverter`) | Keep raw string in DB for forward-compat. |
| bool | `Boolean` | |

**Client-only columns** added to every *writable* entity (not on read-only reference tables):
- `pendingOp: PendingOp` — local dirty state (`NONE`, `CREATE`, `UPDATE`, `DELETE`).
- `clientChangeId: String?` — set on local edit, echoed in push for reconciliation.
- `deletedAt: String?` — tombstone mirror of the BE soft delete.
- `updatedAt: String` — server's value; also the delta-sync cursor.

**Foreign keys:** synced rows can arrive parent-after-child, so avoid hard `@ForeignKey` enforcement (it would reject out-of-order inserts). Use plain **indices** on relation columns instead; enforce integrity in the repository/UI.

**Not stored:** account `balance` (derive locally — sum transactions/transfers/payments) and transaction currency (derive from `account → user_currency → currency`; the BE dropped `transactions.currency_id`).

---

## Enums + converters

```kotlin
enum class PendingOp { NONE, CREATE, UPDATE, DELETE }
enum class CategoryType { income, expense }
enum class AccountType { cash, bank, e_wallet, other }
enum class LiabilityType { loan, credit_card, personal }

class Converters {
    @TypeConverter fun pendingOp(v: String) = PendingOp.valueOf(v)
    @TypeConverter fun pendingOp(v: PendingOp) = v.name
    // Keep `type` fields as String in entities to stay tolerant of new server values;
    // map to the enums above in the domain layer.
}
```

---

## Reference entities (read-only — pulled, never pushed)

```kotlin
@Entity(tableName = "currencies")
data class CurrencyEntity(
    @PrimaryKey val id: String,
    val code: String,
    val name: String,
    val symbol: String,
    val decimalPlaces: Int,
    val createdAt: String?,
    val updatedAt: String,
    val deletedAt: String?,   // reference data is soft-deletable server-side
)

@Entity(tableName = "categories")
data class CategoryEntity(
    @PrimaryKey val id: String,
    val name: String,
    val type: String,         // income | expense
    val icon: String?,
    val color: String?,
    val createdAt: String?,
    val updatedAt: String,
    val deletedAt: String?,
)
```

---

## User-scoped entities (pulled **and** pushed)

```kotlin
@Entity(
    tableName = "user_currencies",
    indices = [Index("userId"), Index("currencyId"), Index("updatedAt"), Index("pendingOp")],
)
data class UserCurrencyEntity(
    @PrimaryKey val id: String,
    val userId: String,
    val currencyId: String,
    val exchangeRate: String,      // decimal(20,6)
    val isAnchor: Boolean,
    val createdAt: String?,
    val updatedAt: String,
    // user_currencies has NO server soft-delete; keep deletedAt only for local bookkeeping if needed.
    val pendingOp: PendingOp = PendingOp.NONE,
    val clientChangeId: String? = null,
)

// Added v1.1.0. User-owned categories (writable), distinct from the read-only global
// `categories` reference above. Seed these locally from CategoryEntity on first run, then
// let the user add their own; transactions reference a row here via categoryId.
@Entity(
    tableName = "user_categories",
    indices = [Index("userId"), Index("updatedAt"), Index("pendingOp")],
)
data class UserCategoryEntity(
    @PrimaryKey val id: String,
    val userId: String,
    val name: String,
    val type: String,              // income | expense
    val icon: String,
    val color: String?,
    val createdAt: String?,
    val updatedAt: String,
    val deletedAt: String?,
    val pendingOp: PendingOp = PendingOp.NONE,
    val clientChangeId: String? = null,
)

@Entity(
    tableName = "accounts",
    indices = [Index("userId"), Index("userCurrencyId"), Index("updatedAt"), Index("pendingOp")],
)
data class AccountEntity(
    @PrimaryKey val id: String,
    val userId: String,
    val userCurrencyId: String,
    val name: String,
    val type: String,              // cash | bank | e_wallet | other
    val color: String = "#64748B", // matches BE default
    val initialBalance: String,    // decimal(15,2); `balance` is derived, not stored
    val isDefault: Boolean,
    val createdAt: String?,
    val updatedAt: String,
    val deletedAt: String?,
    val pendingOp: PendingOp = PendingOp.NONE,
    val clientChangeId: String? = null,
)

@Entity(
    tableName = "transactions",
    indices = [Index("userId"), Index("accountId"), Index("categoryId"),
        Index("transactionDate"), Index("updatedAt"), Index("pendingOp")],
)
data class TransactionEntity(
    @PrimaryKey val id: String,
    val userId: String,
    val accountId: String,
    val categoryId: String,        // → user_categories.id (a UserCategoryEntity you own; changed v1.1.0)
    val type: String,              // income | expense
    val amount: String,            // decimal(15,2)
    val exchangeRateToAnchor: String,
    val description: String?,
    val transactionDate: String,   // YYYY-MM-DD
    // NOTE: no currencyId — a transaction is in its account's currency.
    val createdAt: String?,
    val updatedAt: String,
    val deletedAt: String?,
    val pendingOp: PendingOp = PendingOp.NONE,
    val clientChangeId: String? = null,
)

@Entity(
    tableName = "transfers",
    indices = [Index("userId"), Index("fromAccountId"), Index("toAccountId"),
        Index("transferDate"), Index("updatedAt"), Index("pendingOp")],
)
data class TransferEntity(
    @PrimaryKey val id: String,
    val userId: String,
    val fromAccountId: String,
    val toAccountId: String,
    val fromAmount: String,
    val toAmount: String,
    val exchangeRate: String?,     // nullable
    val fee: String,               // default "0"
    val description: String?,
    val transferDate: String,
    val createdAt: String?,
    val updatedAt: String,
    val deletedAt: String?,
    val pendingOp: PendingOp = PendingOp.NONE,
    val clientChangeId: String? = null,
)

@Entity(
    tableName = "liabilities",
    indices = [Index("userId"), Index("userCurrencyId"), Index("updatedAt"), Index("pendingOp")],
)
data class LiabilityEntity(
    @PrimaryKey val id: String,
    val userId: String,
    val userCurrencyId: String,
    val name: String,
    val type: String,              // loan | credit_card | personal
    val principalAmount: String,
    val interestRate: String?,     // nullable
    val dueDate: String?,          // nullable date
    val notes: String?,
    val isSettled: Boolean,
    val createdAt: String?,
    val updatedAt: String,
    val deletedAt: String?,
    val pendingOp: PendingOp = PendingOp.NONE,
    val clientChangeId: String? = null,
)

@Entity(
    tableName = "liability_payments",
    indices = [Index("liabilityId"), Index("accountId"), Index("updatedAt"), Index("pendingOp")],
)
data class LiabilityPaymentEntity(
    @PrimaryKey val id: String,
    val liabilityId: String,       // ownership flows through the parent liability (no userId)
    val accountId: String,
    val amount: String,
    val paymentDate: String,
    val note: String?,
    val createdAt: String?,
    val updatedAt: String,
    val deletedAt: String?,
    val pendingOp: PendingOp = PendingOp.NONE,
    val clientChangeId: String? = null,
)
```

Optional (BE table exists, no API yet — add when the endpoint ships): `transaction_attachments`.

---

## Auth + sync bookkeeping (local only)

```kotlin
@Entity(tableName = "profile")
data class ProfileEntity(         // the signed-in user
    @PrimaryKey val id: String,
    val name: String,
    val email: String,
    val role: String,
    val avatarPath: String?,      // profile picture path/URL (added v1.1.0)
    val updatedAt: String,
)

@Entity(tableName = "sync_state")
data class SyncStateEntity(
    @PrimaryKey val id: Int = 1,          // single row
    val cursor: String? = null,           // last pull's `server_time` (sent as `since`)
    val accessToken: String? = null,      // store in EncryptedSharedPreferences / Keystore, not here, ideally
    val refreshToken: String? = null,
    val lastSyncAt: String? = null,
)
```

---

## DAO pattern (per entity)

```kotlin
@Dao
interface AccountDao {
    @Upsert suspend fun upsertAll(rows: List<AccountEntity>)          // apply server pull

    @Query("SELECT * FROM accounts WHERE deletedAt IS NULL AND userId = :uid ORDER BY name")
    fun observe(uid: String): Flow<List<AccountEntity>>

    @Query("SELECT * FROM accounts WHERE pendingOp != 'NONE'")
    suspend fun dirty(): List<AccountEntity>                          // collect for push

    @Query("UPDATE accounts SET pendingOp='NONE', clientChangeId=NULL WHERE id IN (:ids)")
    suspend fun clearPending(ids: List<String>)                      // after push confirms `applied`
}
```

Reference DAOs (`CurrencyDao`, `CategoryDao`) only need `upsertAll` + observe queries — no dirty/push. `UserCategoryDao` **is** writable, so it follows the full `AccountDao` pattern (`dirty()` / `clearPending()`).

---

## Database

```kotlin
@Database(
    version = 2,   // bumped 1 → 2 in v1.1.0: added UserCategoryEntity + ProfileEntity.avatarPath
    entities = [
        CurrencyEntity::class, CategoryEntity::class,
        UserCurrencyEntity::class, UserCategoryEntity::class, AccountEntity::class,
        TransactionEntity::class, TransferEntity::class,
        LiabilityEntity::class, LiabilityPaymentEntity::class,
        ProfileEntity::class, SyncStateEntity::class,
    ],
)
@TypeConverters(Converters::class)
abstract class WalletDatabase : RoomDatabase() {
    abstract fun accounts(): AccountDao
    // … one accessor per DAO
}
```

**Migration 1 → 2** (v1.1.0):

```kotlin
val MIGRATION_1_2 = object : Migration(1, 2) {
    override fun migrate(db: SupportSQLiteDatabase) {
        db.execSQL("ALTER TABLE profile ADD COLUMN avatarPath TEXT")
        db.execSQL("""
            CREATE TABLE IF NOT EXISTS user_categories (
                id TEXT NOT NULL PRIMARY KEY,
                userId TEXT NOT NULL,
                name TEXT NOT NULL,
                type TEXT NOT NULL,
                icon TEXT NOT NULL,
                color TEXT,
                createdAt TEXT,
                updatedAt TEXT NOT NULL,
                deletedAt TEXT,
                pendingOp TEXT NOT NULL DEFAULT 'NONE',
                clientChangeId TEXT
            )
        """.trimIndent())
        db.execSQL("CREATE INDEX IF NOT EXISTS index_user_categories_userId ON user_categories(userId)")
        db.execSQL("CREATE INDEX IF NOT EXISTS index_user_categories_updatedAt ON user_categories(updatedAt)")
        db.execSQL("CREATE INDEX IF NOT EXISTS index_user_categories_pendingOp ON user_categories(pendingOp)")
    }
}
```

---

## Sync flow (how the columns are used)

**Pull** (`GET /sync/pull?since=<cursor>`):
1. For each entity array in `data`, `upsertAll(...)` the rows (map JSON → entity).
2. A row with non-null `deletedAt` is a **tombstone** — keep it (hidden by `deletedAt IS NULL` queries) or hard-delete locally once no local `pendingOp` references it.
3. Save `data.server_time` into `sync_state.cursor`.
4. **Conflict rule:** if a pulled row's `id` has a local `pendingOp != NONE`, keep the local version (last-write-wins on your unsynced edit) — don't clobber unpushed changes.

**Local write (offline):**
- Create: generate a ULID, insert with `pendingOp = CREATE`, `clientChangeId = <uuid>`, `updatedAt = now`.
- Update: set fields, `pendingOp = UPDATE` (keep `CREATE` if still uncreated), new `clientChangeId`.
- Delete: set `deletedAt = now`, `pendingOp = DELETE`.

**Push** (`POST /sync/push`):
1. Gather `dirty()` across DAOs → build `changes[]`: `{ client_change_id, entity, op, id, data }` (`op` from `pendingOp`, `data` = entity fields; delete needs only id).
2. Send. For each result:
   - `applied` → `clearPending([id])` and store the returned `record` (server timestamps).
   - `failed` → surface the `error`; leave `pendingOp` set to retry (or resolve the conflict).

See **[Push Changes guide](/docs/push-changes)** for the exact per-entity payloads.

---

## Notes
- **Cursor:** currently the server's `server_time` (ISO-8601). If the BE later adds a bigint `version`, only `sync_state.cursor` and the query param change — the entity schema is unaffected.
- **Order of applying a pull:** reference (currencies, categories) → user_currencies → user_categories → accounts → transactions/transfers → liabilities → liability_payments, so relations resolve.
- **Derived, never stored:** account balance, transaction currency.

---

## Changelog

- **1.1.0** (2026-07-24) — Room `@Database` `version = 1` → `2` (`MIGRATION_1_2`).
  - Added `UserCategoryEntity` (`user_categories`, pushed + pulled), mirroring the new BE table.
  - `TransactionEntity.categoryId` now references a `UserCategoryEntity` you own (was the global `categories`).
  - Added `ProfileEntity.avatarPath`.
- **1.0.0** — Initial Room mirror (`@Database(version = 1)`).
