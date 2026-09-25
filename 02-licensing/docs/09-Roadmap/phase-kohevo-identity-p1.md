# Kohevo Brand Persistence System — Phase 1 (Foundation)

**Status:** Implemented, official brand assets integrated in Phase 1A (foundation only — nothing wired into the UI yet)
**Date:** 2026-09-21

## Why

Kohevo is already the platform's live default product name (see the repo-wide
audit that preceded this phase), but it exists only as a literal string,
`'Kohevo'`, repeated at 20+ call sites (`Database::setting('site_name') ?:
'Kohevo'`), rather than one canonical source. Tenant branding (logo, colors,
favicon, login image, …) is real and configurable, but nothing prevents that
same scattered-literal pattern from eventually drifting or being edited into
something that isn't the platform's identity. This phase introduces the
boundary the full Kohevo Brand Persistence System spec calls for, without yet
changing anything a user can see.

## Architecture

```
TenantBranding                    PlatformIdentity
      |                                  |
"What does this client            "What platform is
 want their app to                 providing this
 look like?"                       application?"
      |                                  |
settings table                    fixed constants +
(tenant-scoped,                   SLATE_URL only —
tenant-editable)                  never DB, never
      |                           tenant-editable
      v                                  v
 name, logo, favicon,              name, mark, wordmark,
 colors, fonts, login              wordmark-dark, favicon,
 image, tagline, sublabel          "Powered by Kohevo"
                    \                /
                     v              v
                  PlatformSignature
                  (renders the platform's own
                   attribution — not wired into
                   any UI surface yet)
```

- **`Slate\Services\Content\PlatformIdentity`** — the single, deterministic
  source of the Kohevo platform identity. No database access, no tenant
  context, no settings read. `name()`, `markUrl()`, `wordmarkUrl()`,
  `wordmarkDarkUrl()`, `faviconUrl()`, `signature()`.
- **`Slate\Services\Content\TenantBranding`** — expanded (not replaced) to be
  the canonical read path for a tenant's own identity, alongside the
  pre-existing `KEYS`/`resolve()` (colors/fonts, unchanged). New methods:
  `siteName()`, `businessName()`, `logoUrls()`, `faviconUrl()`,
  `loginImageUrl()`, `loginTagline()`, `sublabel()` — same `settings` keys,
  same fallback behavior as the existing readers they parallel
  (`includes/helpers.php`'s `slate_logo_urls()`/`slate_favicon_url()`,
  `includes/error_page.php`, `BrandedEmail::brand()`), not yet migrated onto
  by any of those call sites.
- **`Slate\Services\Content\PlatformSignature`** — a small, self-contained
  renderer for "Powered by Kohevo" in three modes (`compact`/`standard`/
  `signature`), sourcing every value from `PlatformIdentity`. Not called from
  any shell, login, error, or email surface yet — foundation only.

### Why co-located under `Services\Content`, not `Services\Identity`

`Slate\Services\Identity` already exists and means something unrelated: the
customer/contact authentication "identity spine" (`ContactRepository`,
`IdentityStore`, `IdentityRepository`, `IdentityTokenRepository` — see
`docs/09-Roadmap/phase2a-identity-design.md`). Putting `PlatformIdentity`
there would collide with that established meaning. `Services\Content` is
where `TenantBranding` already lives, so `PlatformIdentity`/`PlatformSignature`
sit next to the class they exist to complement.

### Platform assets

`assets/platform/brand/` holds the **official Kohevo monogram package**
("Monogramme K pour Kohevo"), integrated in Phase 1A — copied byte-for-byte
from the supplied ZIP (verified via `diff` against the source), replacing the
three Phase 1 placeholder SVGs (`kohevo-mark.svg`, `kohevo-wordmark.svg`,
`kohevo-wordmark-dark.svg`, all removed — no ambiguity between placeholder
and official files remains). See that directory's `README.md` and
`LISEZMOI.txt` (the brand package's own usage guidance, copied unmodified)
for the full asset set and mapping table. In summary:

| `PlatformIdentity` method | Official asset |
|---|---|
| `markUrl()` | `kohevo-compact-noir.svg` |
| `wordmarkUrl()` | `kohevo-lockup-horizontal-noir.svg` |
| `wordmarkDarkUrl()` | `kohevo-lockup-horizontal-blanc.svg` |
| `faviconUrl()` | `kohevo-compact-noir.svg` (same as `markUrl()` — LISEZMOI names this variant for favicon use) |

Two variants ship in the package but are intentionally unexposed by
`PlatformIdentity` for now (`kohevo-signe-3-bandes-*`, the larger from-32px
full sign; `kohevo-lockup-vertical-*`, the stacked lockup) — nothing in the
current architecture needs them yet; add an accessor when a real consumer
does, per the instruction to keep this API minimal and intentional rather
than exposing every supplied asset speculatively.

`assets/img/kohevo-favicon.ico` (pre-existing, outside this directory) is
unchanged — not deleted, not moved, still exactly what the separate,
pre-existing `slate_default_favicon_url()` helper resolves to.
`PlatformIdentity::faviconUrl()` now points at the official compact mark
instead — a Phase-1A-safe change with zero production effect, since nothing
live resolves a favicon through `PlatformIdentity` yet.

Platform assets live under `assets/` (versioned, git-tracked), never
`uploads/` (gitignored, tenant-writable via `Uploads::handle()`). No tenant
upload path can ever write into `assets/`, so a tenant can never overwrite a
platform asset — true by construction, not by a new check.

## Why the `Slate\` namespace is unchanged

`Slate\` is the codebase's internal engineering codename (`README.md`), not
the product identity — that split already exists and is correct. ADR-0013
frames renaming it as "the most disruptive change possible" once code depends
on it (106+ namespace declarations, 467+ `use` imports). Nothing in this
phase — or any phase of this feature — touches it.

## Why licensing is intentionally deferred

`Slate\Services\Licensing\EntitlementService::canAccess()` already exists and
is the right mechanism for a future `white_label` capability gate, but it has
**zero production callers today** — it would be the first real use of that
code path. Wiring it in before the platform signature exists anywhere to gate
would be premature. A separate, already-shipped, currently *ungated*
"White label & support" settings feature (`admin/settings.php`, tested by
`tests/integration/WhiteLabelSettingsTest.php`) also needs a product decision
— should it be brought under the same gate, or is it a distinct,
intentionally-separate concept from mark/logo suppression — before licensing
work begins. Recorded here, not resolved here.

## Non-goals for this phase

No shell, login, error-page, or email markup was changed. No database schema
changed. No `white_label` entitlement was seeded. No existing branding-reader
call site (`includes/helpers.php`, `includes/error_page.php`,
`BrandedEmail.php`) was migrated onto `TenantBranding`'s new methods. No
"Slate" user-facing string was fixed. All of the above are later-phase work.
