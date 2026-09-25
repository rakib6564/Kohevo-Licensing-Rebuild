# Design Review — `Database`

**Source:** `includes/Database.php` (120 lines) · **Callers:** 179 files · **No code.**

The smallest of the four but the **highest blast radius**: a static PDO singleton
every DB call in the shell and every plugin flows through.

---

## 1. Current implementation

**Responsibilities**
- Own the single PDO connection (lazy, `READ COMMITTED`, exceptions on, emulation
  off).
- Provide generic query helpers over prepared statements.
- Provide a tenant-scoped **settings** accessor (`setting`/`setSetting`). *(This is
  a second responsibility — see SRP note.)*

**Public API** (all static)
| Method | Purpose |
|---|---|
| `get(): PDO` | lazy connection (sets isolation level on first connect) |
| `query(sql, params): PDOStatement` | prepared execute |
| `row / rows / all / value` | fetch one / many / many-alias / scalar |
| `insert(table, data): int` | insert, returns lastInsertId |
| `update(table, data, where, whereParams): int` | returns rowCount |
| `delete(table, where, params): int` | returns rowCount |
| `setting(key, ?tenantId)` / `setSetting(key, value, ?tenantId)` | tenant-scoped settings read/upsert |

Top methods by call volume: `setting` (356), `row` (262), `rows` (260), `value`
(198), `update` (184), `setSetting` (136), `insert` (135), `delete` (96).

**Internal dependencies**
- Global classes: `PDO`, `PDOStatement` (constructor + type hints).
- Global constants: `DB_HOST/DB_NAME/DB_USER/DB_PASS/DB_CHARSET` (from `config.php`).
- Global function: `current_tenant_id()` (only in `setting`/`setSetting`).
- **Depends on no other core class** → it is the dependency **leaf**.

**External callers** — 179 files (core `includes/*`, every `admin/*`, `customer/*`,
and all 19 plugins). Every one of the 7 already-migrated classes calls `\Database::`.

**Bootstrap order** — required early in `config.php` (line 69, right after
`helpers.php`). No connection is opened at require time; `get()` connects lazily on
the first query. First real use: `Database::setting('force_https')` in `config.php`
before session start.

**Lifecycle** — process-scoped singleton (`private static ?PDO $pdo`). One
connection per PHP request; no explicit close.

---

## 2. Coupling analysis

- **Who depends on it:** effectively everything (179 files). It is the root of the
  data call-graph.
- **What it depends on:** only PDO + config constants + `current_tenant_id()`.
  Nothing that could form a cycle.
- **Circular dependency risk:** **none.** Database calls no core class. (Note the
  *inverse* fact: because everything calls it, migrating it is the one whose
  regressions surface everywhere — verify breadth, not depth.)
- **Hidden assumptions:**
  - `READ COMMITTED` is set once per connection in `get()` — a deliberate isolation
    choice; must be preserved.
  - `setting()` returns `null` when absent and swallows nothing — callers rely on
    `?: 'default'` patterns; keep the exact null/`''` semantics.
  - `insert()`/`update()` build column lists with backtick-quoting; the SQL shape
    must not change.
- **Global state:** the static `$pdo` singleton. This is the only mutable static;
  it is safe (one connection per request) but is exactly what the container will
  later own.

---

## 3. Migration strategy

- **Namespace / location:** `Slate\Data\Database` → `src/Data/Database.php`.
- **Qualification:** `PDO` → `\PDO`, `PDOStatement` → `\PDOStatement`, and the
  `PDO::ATTR_*` / `PDO::FETCH_*` / `PDO::ERRMODE_*` constants → `\PDO::…`.
  Functions/constants (`DB_*`, `current_tenant_id`, `implode`, `array_*`) fall back
  to global automatically. No cross-core-class calls to qualify (it's a leaf).
- **Alias strategy:** `class_alias(\Slate\Data\Database::class, 'Database');` in
  `src/compat/aliases.php`. Because **every already-migrated class already calls
  `\Database::`**, the alias makes those resolve to the new class transparently.
- **Bootstrap considerations:** `aliases.php` runs before `includes/Database.php` in
  `config.php`. `class_alias` autoloads `Slate\Data\Database` (via the PSR-4 loader)
  and creates the global `Database`. Then `require includes/Database.php` (thin
  forwarder) is a no-op. **Order-safe** — matches the six prior migrations exactly.
- **Backward compatibility:** total. All 179 callers keep calling `Database::…`; the
  7 migrated classes keep calling `\Database::…`. No signatures change.
- **Risk assessment:** **code risk LOW** (120 trivial lines, leaf); **blast-radius
  HIGH** (everything). The mitigation is *breadth verification* after the move, not
  code complexity.
- **Rollback strategy:** `git revert` the single commit. Because the change is
  additive (new file + alias + forwarder), reverting restores the original global
  class with zero data/schema impact. Smoke is the go/no-go gate.

---

## 4. Verification plan

- **Existing smoke coverage:** `tests/smoke.php` already asserts `class_exists('Database')`,
  `Database::query('SELECT 1')`, and core-table reads — so a broken Database alias
  fails smoke immediately.
- **Additional checks required (run after the commit, before proceeding):**
  - Re-verify **all 7 already-migrated classes** resolve `\Database::` through the
    alias: `Notifications::unreadCount()`, `Media::counts()`, `I18n::translate()`,
    `AuditLog::recent()`, `Mailer::verifyConnection()` all execute against the DB.
  - Confirm `Database::setting()` / `setSetting()` round-trip a value.
  - Confirm `insert/update/delete` return the right counts/id (a throwaway temp row
    in a scratch table, rolled back — or a read-only equivalent).
- **Runtime verification:** load one real admin page and one public page (booking
  `/book`) to exercise the connection under the actual bootstrap, not just CLI.
- **Edge cases:** pre-install path (no `settings` table) — `setting()` must still
  throw-and-be-caught by callers exactly as today; the lazy `get()` must not connect
  until first query.
- **Plugin compatibility:** every plugin uses `Database::` — smoke boots active
  plugins; additionally spot-check `BookingAPI`/`ShopAPI` static entry points
  resolve `Database::` (they call the global name, satisfied by the alias).

---

## 5. Future evolution (Architecture v1.0)

- **Service Container:** the static singleton becomes a container-managed
  `Connection` service; the static `Database` facade remains via the alias for BC
  until a major.
- **Repository Layer:** `query/row/rows/insert/update/delete` become the low-level
  engine under `Slate\Data\QueryBuilder` + a base `Repository`; module code moves to
  repositories while raw `Database::` stays working.
- **Migration Framework:** `get()->exec(...)` DDL scattered across services
  (`ensureSchema`) is replaced by the migration runner using this connection.
- **Tenant Context:** the base repository injects `tenant_id` via `TenantContext`,
  removing the need for callers to pass `?tenantId` and closing the manual-scoping
  gap.
- **Dependency Injection:** services receive a `Connection`/`Repository` by
  constructor rather than reaching for the static.
- **Capability Contracts:** a `Slate\Contracts\Data\Connection` interface lets the
  driver be swapped (read replicas, per-tenant DB) without touching callers.

### SRP note
**Mild violation:** `setting()`/`setSetting()` is a *Settings* responsibility bolted
onto a *connection/query* class. **Do not split during A3** — migrate whole. In
Phase 3, extract to `Slate\Services\Settings` (backed by this connection), leaving
`Database::setting()` as a thin deprecated forwarder during the BC window.

---

## Recommendation

Migrate **second** (after `PublicRouter`), as the first of the trio. Low code risk,
high reach — treat the post-migration **breadth re-verification** (all 7 migrated
classes + a real page load) as the real gate, not the diff size.
