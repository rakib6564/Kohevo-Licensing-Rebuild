# Contributing

This repository holds two applications that share one PHP shell:

- [`01-client/`](01-client/) — the customer-installed application
- [`02-licensing/`](02-licensing/) — the central licensing server

Each app has its own [`CONTRIBUTING.md`](01-client/CONTRIBUTING.md) with the **house rules** (tenancy, money,
authorization, secrets, brand tokens, migrations). Read it first; this file covers what is specific to working in
the repository as a whole.

## Workflow

1. Branch from `main`: `feature/<short-name>`, `fix/<short-name>` or `chore/<short-name>`.
2. Make one scoped change per commit. Use the imperative and a conventional prefix — `feat:`, `fix:`, `docs:`,
   `chore:`, `test:` — with a scope where it helps (`fix(booking): …`).
3. Open a pull request into `main`. CI must be green (lint, secret scan, client and central suites on MySQL 8.0 and
   MariaDB 10.11). `main` is what gets deployed — never push work-in-progress to it.

## Two trees, one shell

Most of `includes/`, `src/`, `admin/`, `customer/`, `lang/` and every plugin except `licensing` exist in **both**
trees and are expected to stay identical. When you change shared code, **apply the same change to both trees** in
the same commit. Differences are deliberate and few (installer, entitlement/licensing services, tenancy, a handful
of admin pages). If you find an unexplained difference, that is a bug — please report it.

## Translations

Every user-visible string goes through `__('key', 'English fallback')` and must have an entry in the French
dictionary. Plugins keep their own packs in `plugins/<slug>/lang/`. Details and the coverage check:
[`docs/05-guides/I18N.md`](docs/05-guides/I18N.md). A change that adds a key without its French entry will be sent back.

## Tests

- Run `make ci` inside the app you changed (needs a test database) or `make test-unit` (no database).
- Add or update a test for behaviour you change. Tests live in `tests/unit` and `tests/integration` and use the
  dependency-free harness (`unit()`, `assert_true()`, `assert_eq()`).
- Never point tests at a production database; the guard (`slate_require_test_database()`) exists for a reason.

## Versioning and releases

- The release number is in [`VERSION`](VERSION) and must equal `SLATE_VERSION` in both apps (`config.php`,
  `install.php`). CI checks it.
- Record user-visible changes in [`CHANGELOG.md`](CHANGELOG.md) under the next version.
- Build packages with [`scripts/build-release.sh`](scripts/build-release.sh); procedure in
  [`docs/06-operations/RELEASING.md`](docs/06-operations/RELEASING.md).

## Secrets

Never commit `.env`, keys, tokens, database dumps or real customer data. Report anything you find that looks like
a leaked credential via [SECURITY.md](SECURITY.md).
