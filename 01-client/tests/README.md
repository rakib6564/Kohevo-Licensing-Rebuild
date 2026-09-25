# Slate Tests

The Phase-0 safety net (see `docs/09-Roadmap/refactor-roadmap.md` and
`docs/12-Testing/`). Intentionally **dependency-free** — plain PHP, no PHPUnit or
`composer install` — so it runs on shared hosting exactly as production does.

## Run

```bash
php tests/smoke.php      # or: bash tests/run.sh
```

Exit code `0` = all passed, `1` = one or more failures. Output is TAP-style.

## What `smoke.php` checks (read-only)

- `config.php` boots without a fatal (autoload/bootstrap intact)
- Core classes are wired (`Database`, `Auth`, `Hook`, `PluginLoader`, …)
- Core constants defined (`SLATE_VERSION`, `SLATE_URL`)
- Database connectivity (`SELECT 1`)
- Core schema present (`tenants`, `roles`, `users`, `settings`, `plugins`, …)
- The default tenant is seeded

It never writes data.

## Growing the suite

This is a seed. As the refactor lands, add assertions for the highest-value
paths first (per `docs/12-Testing/`): **money** (`Money` never a float),
**tenancy** (no query escapes tenant scope), **auth/authz**, **payments**, and
**migrations** (up/down round-trip). When a real test DB and CI runner are
available, promote these to a proper suite and wire the
[architecture-conformance](../docs/12-Testing/architecture-conformance.md) checks
as gates.

## CI

GitHub Actions runs this suite on every push to `main`, `feature/**`, `fix/**`,
and on every pull request — see `.github/workflows/ci.yml`. Four jobs:

| Job | What it gates | Needs a DB |
| --- | --- | --- |
| `lint` | `php -l` over every source file | no |
| `secrets` | no `.env`, no credentials, no live provider keys | no |
| `unit` | `tests/unit` on PHP 8.3 and 8.4 | no |
| `integration` | migrations, then integration + smoke + render | yes (MySQL 8 service) |

The integration job builds a database from scratch with `php bin/migrate migrate`,
so migrations are themselves tested on every run.

Run the same gates locally with `make ci`.

## Test isolation (Phase 0)

The integration suite writes real rows. Run it against **its own database**, never
the live one:

```bash
bash tests/bin/provision-test-db.sh      # once per checkout
```

That creates `<prefix>_slate_t<ns>` through cPanel's uapi (the account's MySQL
user is not granted `CREATE DATABASE`), builds the schema from migrations, seeds
the minimum fixture the suite assumes, and repoints this checkout's `.env`. The
live database is never touched.

**One database per checkout, not one shared database with namespaced ids.**
`IdentityStoreTest`, `MfaRepositoryTest` and `RbacTenantIsolationTest` operate on
`current_tenant_id()` with fixed identifiers on purpose — testing tenancy against
the real tenant is the point of them. Two concurrent runs therefore insert the
same credential under tenant 1 and collide on a unique key. Measured: namespacing
alone still gave 2-9 failures per concurrent pair. Separate databases gave
70/70 on both sides, four rounds running.

`tests/isolation.php` namespaces synthetic tenants and test IPs on top of that.
It is complementary, not the isolation itself: it keeps a stray row traceable to
the checkout that wrote it, and limits the damage if someone runs without
provisioning first.

If a run is interrupted, rows can leak and the next run fails on a unique
constraint — deterministically, which is the tell. Re-provision rather than
hunting the rows:

```bash
uapi Mysql delete_database name=<prefix>_slate_t<ns>
bash tests/bin/provision-test-db.sh
```

## Why local and CI disagree

There are **two independent provisioners for the same database** and nothing keeps
them in sync: `tests/bin/provision-test-db.sh` for local checkouts, and inline
steps in `.github/workflows/ci.yml` for CI. The script's header says it "mirrors
what CI seeds". As of 2 Sep 2026 it does not.

| | `provision-test-db.sh` | `ci.yml` |
|---|---|---|
| schema from `bin/migrate` | yes | yes |
| **active plugins** | **content-builder** | **content-builder, forms, booking, membership** |
| **plugin schemas installed** | **content-builder** | **+ forms, booking, membership** |
| booking column top-ups | no | yes — `tests/fixtures/booking-schema.php` |
| render fixtures | no | `tests/fixtures/render-seed.sql` |
| **`APP_URL`** | **the checkout's real URL** | **`http://localhost`** |

Those last three rows are the entire explanation for the disagreements seen so far,
and they run in **both** directions:

- **Passes locally, fails in CI.** A plugin that is inactive locally registers no
  hooks, so its behaviour is invisible. `BookingCanBookGateTest` passed locally
  because membership was never booted, and failed in CI because it was —
  membership listens on `booking_can_book` at `Membership.php:52` and refuses
  anonymous bookings. A test that depends on which plugins are active must say so
  itself; see `bcbg_membership_rules_off()` in that file.
- **Fails locally, passes in CI.** The two document goldens differ by exactly one
  line — `<a class="cb-brand" href="…/p/home">`. `_slate_envelope_strip_base()`
  (`tests/integration/EnvelopeGoldenTest.php:33`) normalises only the URL *path*,
  and CI's `APP_URL=http://localhost` has no path, so the goldens are stored with
  an absolute `http://localhost` host that only a localhost checkout reproduces.

**Before concluding a local result means anything**, check `SELECT slug, status
FROM plugins` against the `ci.yml` list. Tracked as OPS-2 in
`Claude/slate-issue-queue.md`; until it is fixed, CI is the authority.
