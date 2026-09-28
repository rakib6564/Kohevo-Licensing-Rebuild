# Changelog

All notable changes to Kohevo (client and central) are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [Semantic Versioning](https://semver.org/)
(`MAJOR.MINOR.PATCH`). The current number lives in [`VERSION`](VERSION) and in `SLATE_VERSION`
(`config.php`, `install.php` of each app); CI fails if they disagree.

Deployment packages are named `KOHEVO-<CLIENT|CENTRAL>-V<MAJOR.MINOR>-DEPLOYMENT-READY.zip`.

## [Unreleased]

### Fixed
- **Admin → Notifications on phones:** the bulk-action bar no longer pushes the page wider than the screen. With long
  (translated) labels such as « Supprimer la sélection » it overflowed and the whole page scrolled sideways. The bar and
  the header actions now wrap and share the row, and long labels wrap instead of overflowing (checked at 320, 360 and
  390 px in English and French, with rows expanded; desktop layout unchanged).

## [1.6.0] — 2026-09-28

### Added
- **Safe cross-site iframe embedding of the booking widget.** Steps that need a session (login, confirmation,
  payment) now break out of the iframe to the top window and offer a validated "back to the site" link. A
  per-site allow-list of embedding origins (Booking → Settings → *Embedding on other websites*) drives the
  `frame-ancestors` / `X-Frame-Options` policy for the whole app. See
  [`docs/05-guides/EMBEDDING.md`](docs/05-guides/EMBEDDING.md).
- `VERSION` file, `scripts/build-release.sh` (reproducible package build) and the guides under `docs/05-guides/`
  and `docs/06-operations/`.
- Root `README.md`, `CHANGELOG.md`, `SECURITY.md`, `CONTRIBUTING.md`, and GitHub issue / pull-request templates.

### Changed
- **`SLATE_VERSION` now reads 1.6.0** (it had stayed at 1.3.0 while later packages shipped). The version is
  reported to the central server on each license check-in.
- The licensing server now has its own README (it previously duplicated the client's).
- Old build zips are no longer tracked in git (`*.zip` is ignored); loose root documents moved under `docs/`.

### Fixed
- The public form's client-side validation messages ("This field is required", …) are translated, and their
  placeholders follow the active language.
- Remaining hard-coded English in Booking, Membership, Stripe Payment and core error responses now goes through
  the translation layer; the default date format is day-first (`j F Y`) in French.

## [1.5.0] — 2026-09-28

### Added
- **One notification email template for the whole platform** (`EmailTemplate`), based on the Booking design:
  booking, membership, form, sign-in verification, password reset, SMTP test and staff notifications all share it.
- **Member profile completion can be switched on/off** (Membership → Settings → *Member portal features*).
- `01-client/bin/seed-solaya-services.php` — idempotent seed for services, provider, service rules and a plan.

### Changed
- Verification and password-reset emails, previously plain, use the branded template.
- French translations for error pages, landing page and the "Propulsé par" platform signature.

## [1.4.0] — 2026-09-28

### Added
- Central: every commercial and platform module (including **Coaching** and **MCP Gateway**) is in the module
  catalog used when creating plans and licenses.
- Client: auto-discovery and auto-sync of commercial modules on the Plugins page, with a client-managed lifecycle.

### Changed
- Membership uses professional appointment/service wording instead of generic "session/course" terms.

### Fixed
- Licensing works on hosts **without `ext-sodium`**: pure-PHP HMAC fallback and public-key derivation.
- Terms link, activity copy, calendar week start and capitalisation on the client.

## [1.3.0] — 2026-09-28

### Added
- Membership portal feature toggles (member card, attendance).

### Fixed
- Date & time formatting on the customer dashboard. See
  [`docs/releases/V1.3-CLIENT-AUDIT.md`](docs/releases/V1.3-CLIENT-AUDIT.md).

### Removed
- The visual page editor and Content section (client and central).

## Licensing rebuild (Phases 1–14) — 2026-09-25 → 2026-09-27

The commercial licensing architecture was rebuilt end to end; phase notes are in
[`docs/03-implementation/`](docs/03-implementation/) and QA reports in [`docs/04-qa/`](docs/04-qa/).

- **Central:** products, plans, clients, licenses and module entitlements; Ed25519 signing; one-license-to-one-
  installation binding; lifecycle and audit history; the `POST /licensing/check` endpoint.
- **Client:** five-step installer with license activation; signature verification and `remote_license_cache`;
  Global License Guard and Module Guard; 7-day pre-expiry warning and 7-day post-expiry grace; legacy-install
  handling; daily `bin/license-check.php` check-in.
- **Security hardening** (Phase 11) and production QA (Phases 12–14), verified in CI on MySQL 8.0 and MariaDB 10.11.

[1.6.0]: https://github.com/rakib6564/Kohevo-Licensing-Rebuild/releases/tag/v1.6.0
