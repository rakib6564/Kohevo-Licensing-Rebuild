# Contributing to Slate

## Before anything else

Read `docs/` — it is a numbered hub (`00-Vision` … `15-Contributing`) and it is
the source of truth for where Slate is going. Architectural changes go through
an ADR in `docs/14-ADR` **before** the code lands.

## Setup

```bash
cp .env.example .env      # fill in real values
php bin/migrate migrate   # fresh install
php bin/migrate baseline  # existing install whose schema already exists
make smoke                # confirm it boots and connects
```

There is no `composer install` step. PHPMailer is vendored in `vendor/` on
purpose, because production is shared hosting without composer.

## The loop

```bash
make lint      # syntax
make test      # unit + integration + smoke + render
make ci        # everything CI runs
```

`make test` needs a database. `make test-unit` does not, and is the fastest
useful signal.

## Branches and commits

- Branch from `main` as `feature/<short-name>` or `fix/<short-name>`.
- CI runs on `main`, `feature/**`, `fix/**`, and every PR.
- Keep commits scoped to one change. The subject line says what changed and why,
  in the imperative.

## House rules

These are load-bearing. A PR that breaks one will be sent back.

- **Tenancy.** Every query touching a tenant-scoped table filters on
  `tenant_id`. Failing to scope must fail closed, never fall back to "all rows".
- **Money.** Money is a `Money` value object. Never a float, ever.
- **Authorization.** Admin routes check `Auth::can()`. Customer portal routes do
  **not** use `Auth::can()` — they self-gate, per the customer-nav contract.
- **Secrets.** Nothing real in `config.php`. See `SECURITY.md`.
- **Brand tokens.** Call `slate_brand_accent_emit()` after `slate_ui_emit_css()`
  or `var(--accent)` renders the default blue. Darkened-brand tokens are *text*
  colours — using one as a gradient fill under `--on-accent` text fails AA.
- **Admin CSS.** The admin UI is glassmorphism. Never put `backdrop-filter` on
  `.app-panel` — it traps fixed-position modals.
- **Assets.** Plugin CSS/JS sits behind a 7-day Cloudflare cache. `PluginLoader`
  busts by mtime; test the `?v=` URL, not the bare one.
- **Responsive.** Scrollbars are hidden app-wide, so wrap rather than scroll
  horizontally. `index.php` is the reference implementation.

## Migrations

Core schema changes are migrations in `db/migrations/`, applied with
`php bin/migrate migrate`. Core is forward-only: `0001` deliberately does not
drop tables in `down()`. Plugins manage their own tables in `ensureSchema()`.

## Tests

The suite is intentionally dependency-free — plain PHP, no PHPUnit — so it runs
on shared hosting exactly as production does. Add tests to `tests/unit` when
they need no database, `tests/integration` when they do, and `tests/render` when
the risk is "does the page still load at all".
