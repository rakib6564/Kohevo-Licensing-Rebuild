# Running tests safely

The DB-backed suites — integration, smoke and page-render — write real rows.
Pointed at production they leave fixture identities, synthetic tenants and login
attempts in live tables. This has happened: seven fixture identity rows reached
the live database and were only noticed when a unique key collided later.
Nothing about the leak was loud at the time.

Two mechanisms prevent a repeat. Both are deliberate, and neither is optional.

## 1. A database per checkout

```bash
bash tests/bin/provision-test-db.sh
```

Creates an isolated database for this checkout, points its `.env` at it, builds
the schema **from migrations** (not from a dump, which drifts), and seeds the
minimum fixture the suite assumes.

cPanel does not grant `CREATE DATABASE` to the account's MySQL user, so the
script goes through `uapi`.

Two checkouts must not share one database. Several integration tests operate on
`current_tenant_id()` with fixed identifiers — `IdentityStoreTest`,
`MfaRepositoryTest` and `RbacTenantIsolationTest` — because testing tenancy
relative to the real tenant is the point of them. Two concurrent runs then insert
the same credential and collide on a unique key. Measured: identifier namespacing
alone still produced 2–9 failures per concurrent pair. Separate databases hold;
namespacing alone does not.

`tests/isolation.php` still namespaces identifiers per checkout. That is
complementary, not redundant: it keeps a stray row traceable to the checkout that
wrote it.

## 2. The guard refuses anything else

`tests/guard.php` aborts unless the target database carries a marker row proving
it is a test database. The provisioning script writes that marker; CI seeds it.

This is **positive identification, not a denylist**. Matching on names would lapse
the first time someone provisioned a database outside the expected pattern, and a
lapsed guard fails exactly as the original accident did. A production database
has no reason to carry the marker, so it refuses by default.

### Running against production on purpose

A read-only check against the live database is legitimate — a post-deploy health
check is the obvious case. It requires an explicit opt-in:

```bash
SLATE_ALLOW_LIVE_DB=1 php tests/smoke.php
```

The flag prints a warning to stderr so it is visible in any log that captures it.

**Only use it with `smoke.php`.** Smoke is read-only by design. The integration
and render suites write, and the flag will not stop them.

`deploy.yml`'s post-deploy smoke step already passes this flag. Anything else
automated that calls a DB-backed suite against production needs the same, or it
will start failing closed the moment the guard reaches that checkout.

## Which suite needs what

| Suite | Database | Guard applies |
| --- | --- | --- |
| `tests/unit/run.php` | none — autoloader only | no |
| `tests/smoke.php` | yes, read-only | yes (`SLATE_ALLOW_LIVE_DB` permitted) |
| `tests/integration/run.php` | yes, writes | yes |
| `tests/render/run.php` | yes, writes | yes |

The unit suite boots neither `config.php` nor a database, so it runs anywhere and
is the fastest useful signal.

## If something leaks anyway

Fixture rows are identifiable. Tenant ids in the synthetic range (`>= 900000`),
credentials and emails ending `@example.test`, and login attempts from `10.x.x.x`
are all test-only. Check every table carrying a `tenant_id`, not the handful you
expect — the first sweep after the incident above missed rows because it guessed
at which tables were involved.

Reconcile against the most recent nightly backup in `~/slate-backups` from both
directions: what the snapshot has that live is missing, *and* what live has that
the snapshot does not. One direction alone will not tell you whether data was
lost or merely added.
