# Changelog

All notable changes to Kohevo (client and central) are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [Semantic Versioning](https://semver.org/)
(`MAJOR.MINOR.PATCH`). The current number lives in [`VERSION`](VERSION) and in `SLATE_VERSION`
(`config.php`, `install.php` of each app); CI fails if they disagree.

Deployment packages are named `KOHEVO-<CLIENT|CENTRAL>-V<MAJOR.MINOR>-DEPLOYMENT-READY.zip` (`V<MAJOR.MINOR.PATCH>` for a patch release).

## [Unreleased]

### Added
- **Studio builder (Builder 2.0, phase 1): workspace foundation.** Selection is now a shared model (primary, picked nodes,
  hover, focus): Cmd/Ctrl-click and Shift-click multi-select on the canvas and in Layers stay in sync, the Inspector shows
  "N selected", and Cmd/Ctrl+D duplicates the top-level picked nodes. The selection box, name chip and a keyboard-reachable
  action toolbar are now drawn by the builder page above the canvas instead of being written into the canvas frame, so a
  node's `overflow` can no longer clip them (locked layers disable move, edit and delete). New bottom bar: breadcrumb,
  zoom − / % / + and Fit, column grid, and a keyboard-shortcut help; Cmd/Ctrl+K now opens Add and focuses its search. On a
  phone the shell has a two-row top bar with an Unsaved / Saved / Published chip, a Responsive-view sheet (device preset,
  zoom, element outlines, section labels, column grid), draggable bottom sheets with snap points, and selecting a node no
  longer covers the canvas (the Edit tab opens the Style sheet). No document, schema or API change.
- **Studio builder: composed section presets (Builder 2.0 phase 2a).** The Add panel's Sections tab now lists 17 built-in,
  fully composed presets in 9 categories (Hero ×3, Features ×3, Services, Numbers, About, FAQ, Gallery, Testimonials,
  Pricing, Call to Action, Team, Contact, Footer), each with a generated wireframe thumbnail, search, session favourites and
  French names. One click inserts a real, editable section built from registered blocks (the old cards inserted a single
  heading or text block). Presets are seeded per tenant on first use as **system templates** (`is_system = 1`: copyable,
  never overwritable or deletable; a tenant template that already uses a preset key is left alone) through the new read-only
  `section_presets` action; no schema change. Block card titles, descriptions and icons now come from the server manifest
  (translated per locale) instead of a hard-coded table in the UI.
- **Studio builder: developer test harness.** `plugins/studio-builder/ui/e2e` adds a Playwright DOM/e2e suite
  (desktop 1280, tablet 820, phone 390) and `e2e/sandbox.sh`, which builds a disposable local sandbox. Dev-only (`npm run
  test:e2e`); nothing in it runs on customer installs. CI now runs it as its own job ("Studio builder e2e (Playwright)")
  against a MySQL 8.0 service and uploads traces on failure.
- **Studio builder: layer lock and block rename.** Layers can be locked (sections: `locked`; blocks: `metadata.locked`) and
  blocks can carry a display name (`metadata.label`, max 80 characters, shown in Layers and the breadcrumb). Two new
  document operations, `update_block_meta` and `update_section_locked`, set them. A locked layer and everything inside it
  cannot be edited, moved, renamed or removed, nothing can be inserted into it, and a parent holding a locked child cannot
  be removed; duplicating stays allowed. The rules are enforced in `DocumentOperationApplier` (`Document/LayerLock.php`),
  so they bind the builder, the MCP/AI path and any other caller, and are mirrored client-side to refuse an edit up front.
  Assistants may rename layers but cannot lock or unlock them. Documents without locks or names are byte-identical to before.

### Fixed

- **Studio builder in French.** The 259 remaining builder messages (Save, Preview, the inspectors, Library, Theme, History,
  AI review, Import/Export, validation and server error messages) were defined in English but never shipped to the browser,
  so French users saw English. All now ship through `boot.messages` with French. The parity test now covers every message in
  the table, checks the PHP default matches the English text, and fails on any `t()` key with no English text.
  Block inspector device labels and the media "Change" button used keys that did not exist and showed the raw key name.
- **Hard-coded English in the inspectors.** The rich-text toolbar, font weight / text case / border style / size / radius
  options, background type, fit and position controls, focal-point labels, URL placeholders and the dialog close button were
  literal English. They now go through the message table with French. A test fails on any prose in an attribute or `<option>`
  anywhere in the UI, and a French e2e checks the block inspector.

### Security

- **Studio builder: block `attributes` are now a closed allow-list.** Block attributes were written into the wrapper tag
  with only a name-shape check, so a user with edit permission (or an AI/MCP `update_block_attributes` call) could store an
  event handler such as `onclick` that ran for every visitor. Only `aria-*`, `data-*`, `id`, `role`, `tabindex`, `title`
  and `lang` are accepted now (string or number values, at most 32 per block; `data-sb-*` is reserved). The validator
  rejects anything else with `invalid_attribute`, and the renderer drops it too, so pages stored before this fix stop
  emitting handlers after the next compile.
- **Licence signatures can no longer be forged from the public key (P0).** The client verifier accepted `hmac:`-prefixed
  signatures — and, without `ext-sodium`, bare keyed hashes — computed with the *public* key as the HMAC key, so anyone
  holding the public key could mint a licence the client would trust, even with sodium installed. Central could also
  issue such signatures and a pseudo-keypair when sodium was missing. All HMAC paths are removed: signing and
  verification are Ed25519 through `ext-sodium` only, anything else is rejected, and a missing sodium fails closed
  (Central issues nothing, the client constructs no verifier, `install.php` and `bin/license-check.php` refuse to run).
  Regression tests cover forged `hmac:`/raw keyed hashes with and without sodium.
  **Upgrade note:** Central must have a real Ed25519 keypair (64-byte secret). A key generated by the old no-sodium
  fallback is a 32-byte pseudo-key: Central now answers `server_error` and logs it — regenerate it with
  `bin/licensing-generate-keys.php --force` and redeploy the new public key to every client.

## [1.6.2] — 2026-09-28

### Fixed
- **Studio builder: top bar, phone canvas and sheets.** At 1280 px the Edit / Preview / Visitor group overlapped the Draft
  chip and save status; the bar now shrinks and hides lower-priority items instead (nothing overlaps or clips at 1280, 820
  or 390 px). On a phone the canvas was forced to full width and then scaled down to 35%, showing a narrow strip; Theme and
  More also opened the Layers sheet behind them with a blur over the sheet. Sheets now respect the bottom safe area and the
  on-screen keyboard, and use 44 px touch targets.
- **Studio builder in French.** About 290 builder labels (shell, Layers, top bar, dock, sheets, block palette, conflict
  banner and the announcements read to screen readers) were hard-coded in English, or missing from the French pack or from
  `boot.messages`; they are translated now, and a parity test (`ui/tests/i18n-parity.test.mjs`) keeps the touched components complete. The builder
  page also declares the active language (`<html lang>` was fixed to `en`), and with `multilang-translate` active its floating
  language switcher no longer sits on top of the builder's controls or appears inside the editing canvas.
- **Client did not pick up license changes made on the Central Server** (for example a renewed or extended expiry kept
  showing the old date). The client only refreshed its license through an optional cron job, so an install without that
  cron never updated. It now refreshes itself automatically — about every 15 minutes, after the response is sent so
  pages are not slowed — and the License page has a **Check for updates now** button. Trust rules are unchanged: the
  signed response is still verified, and any failure leaves the last verified license untouched.

## [1.6.1] — 2026-09-28

### Added
- **Plugin protection (client admin → Plugins).** Deactivating, uninstalling and uploading plugins are now **locked by
  default** and enforced on the server. "Unlock changes" needs the admin's password and lasts 15 minutes (then re-locks;
  "Lock now" re-locks at once; wrong passwords are throttled; everything is audited). Uninstall also requires typing the
  plugin's name. Activating is never locked. See [`docs/05-guides/PLUGIN-PROTECTION.md`](docs/05-guides/PLUGIN-PROTECTION.md).
- **Booking widget auto-height.** The embed code now includes a small helper script (`assets/js/embed.js`) that sizes the
  iframe to the widget on every step — no cut-off content, no inner scrollbar — and brings the widget back into view when
  the visitor moves to the next step. The admin shows the ready-to-paste code with a **Copy** button (dashboard and
  Booking → Settings). See [`docs/05-guides/EMBEDDING.md`](docs/05-guides/EMBEDDING.md).
- `&chrome=0` on the widget address for a flat look without the card border.

### Changed
- **Plugins page redesign (client):** compact one-row cards — a meaningful icon per plugin, name, status and version, an
  on/off switch (with a padlock on its knob while protected) and a "⋯" menu holding Capabilities, Download ZIP and
  Uninstall. Nothing can overflow a card any more; the author and description lines are gone; the search box no longer
  shows a double border.
- **Embedded widget look:** a centred, width-capped rounded card; calendar and times side by side on wide frames (stacked
  on phones); the calendar no longer stretches into giant cells on wide pages.

### Fixed
- `scripts/build-release.sh` sometimes refused to build (about a third of runs) with a bogus "SLATE_VERSION does not match" error:
  `git show | grep -q` under `pipefail` fails when grep exits early. The check now captures the file first.
- The widget's height report could never shrink (it read a value tied to the iframe's own height), and nothing on the
  host page listened to it, so embeds were cut off at the snippet's fixed height. Both sides are fixed.
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

[1.6.2]: https://github.com/rakib6564/Kohevo-Licensing-Rebuild/releases/tag/v1.6.2
[1.6.1]: https://github.com/rakib6564/Kohevo-Licensing-Rebuild/releases/tag/v1.6.1
[1.6.0]: https://github.com/rakib6564/Kohevo-Licensing-Rebuild/releases/tag/v1.6.0
