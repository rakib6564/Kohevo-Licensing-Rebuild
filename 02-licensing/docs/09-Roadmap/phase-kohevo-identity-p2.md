# Kohevo Brand Persistence System — Phase 2 (Application Shell Integration)

**Status:** Implemented
**Date:** 2026-09-21

## What changed

`PlatformSignature` (built in Phase 1, pointed at official artwork in Phase
1A) is now rendered in two live shell surfaces:

| Surface | File | Mode | Why this mode |
|---|---|---|---|
| Admin sidebar (desktop only) | `admin/partials/header.php` | `MODE_STANDARD` (mark + "Kohevo") | Roomy vertical rail, matches the original spec's own "◈ Kohevo" mockup |
| Customer dashboard topbar | `customer/partials/header.php` | `MODE_COMPACT` (mark only, alt text carries the name) | Single horizontal row already carrying tenant name/sublabel and (on wider screens) the signed-in user block; a second text label would compete rather than stay subordinate |

Both call sites are a single line each: `\Slate\Services\Content\PlatformSignature::render(...)`.
Neither file names a platform asset path or the literal string `Kohevo`
anywhere in the new code — everything comes from the existing `PlatformSignature`/
`PlatformIdentity` abstractions built in Phase 1/1A.

## Admin sidebar — exact placement

Inserted between the nav-groups loop and the pre-existing `sidebar-footer`
(tenant user info + logout), matching the spec's own preferred layout
(nav, then a divider, then the signature). The existing `sidebar-footer`
block is completely untouched.

**Dark/light sidebar handling (Section 12 decision):** the admin sidebar's
background is theme-dependent — 4 of 5 built-in themes (`ink`/`navy`/`teal`/`slate`)
are dark, 1 (`light`) is light (`includes/ui_components.php`'s `slate_sidebar_themes()`).
The official compact mark is pure black ink, so on a dark sidebar it would be
nearly invisible — the exact same problem the pre-existing tenant-logo
rendering already solves via a `.is-tiled` white-tile treatment, gated on the
already-computed `$sidebarLogoTile` boolean. **Decision: reuse that same
`$sidebarLogoTile` boolean for the platform signature** (a new, small,
scoped `.sidebar-signature.is-tiled` CSS rule modeled on the existing
`.sidebar-brand-logo.is-tiled` pattern, not a shared class — the existing
class carries brand-row-specific sizing that doesn't fit this smaller row).
This was extending presentation (CSS), not `PlatformIdentity`/`PlatformSignature`'s
API — no `markDarkUrl()`/theme-aware mode was added to either class, per the
instruction to prefer a CSS solution over a second abstraction when one
already exists for the identical problem.

**Desktop vs. mobile:** the admin shell hides the entire `<aside class="sidebar">`
below 768px (`admin/partials/header.php`'s existing `.sidebar { display: none; }`),
replacing it with a bottom tab bar + "More" overflow sheet
(`admin/partials/footer.php`) that has no existing brand/signature slot at
all — it is pure navigation icons. Adding one would mean inventing a new
mobile branding region, which is explicitly out of scope for this phase.
**Decision: the platform signature is desktop-sidebar-only in Phase 2; it
does not appear anywhere in the mobile admin shell.** `admin/partials/footer.php`
was not modified. This is a documented limitation, not an oversight — see
the Phase 2 instruction's own Section 5, which anticipated and sanctioned
exactly this outcome ("keep the Phase 2 implementation limited to the
existing appropriate shell surface and document the limitation").

## Customer dashboard — exact placement

Inserted inside `<header class="cust-topbar">`, after the existing
conditional signed-in-user block, still before `</header>` — so it renders
unconditionally on every `dashboard`-variant page, not gated on `$cust`.
`customer/partials/header.php`'s `auth-split` and `auth` variants (and
therefore `customer/login.php`, which uses `auth-split`) were **not**
touched — confirmed by a unit test that locates the call strictly between
the `'dashboard'` branch marker and the `'auth-split'` `elseif`.

**Dark/light handling:** the customer topbar's background
(`rgba(245,244,241,0.82)`, off-white) gives the black-ink compact mark good
contrast as-is — no tile/variant treatment needed, unlike the admin sidebar.

**Responsive behavior:** the customer shell has no separate mobile
region/tab-bar (confirmed in the Phase 1 audit) — `.cust-topbar` is the only
persistent chrome at every viewport width, so there is no duplication risk
to guard against here the way there is on the admin side.

`customer/partials/footer.php` was not modified — it is purely structural
(closes tags for whichever variant is active) and had nothing to hook into.

## Tenant branding verification

Neither integration touches a single tenant-branding read or write path.
`TenantBranding`, `slate_logo_urls()`, `slate_favicon_url()`, tenant colors,
tenant fonts, and the existing `sidebar-brand`/`cust-topbar` tenant identity
markup are all byte-for-byte unchanged. An integration test renders the real
admin shell and asserts both `sidebar-brand` (tenant) and `sidebar-signature`
(platform) are present simultaneously — proving the platform signature is
additive, not a replacement.

## Pre-existing issue observed, not touched

The Phase 1 audit's finding that the customer dashboard topbar never renders
an uploaded tenant logo (always shows the letter-mark, unlike the `auth-split`
variant) is still present and was **not** fixed here — it's orthogonal to
adding the platform signature and explicitly out of scope for this phase.

## Non-goals for this phase

No login page, error page, email template, or licensing code was touched.
No database change. No `white_label` entitlement. No Slate→Kohevo literal
cleanup. No Presentation/Theme-layer change — both integrations use the live
procedural shell exactly as it already existed.
