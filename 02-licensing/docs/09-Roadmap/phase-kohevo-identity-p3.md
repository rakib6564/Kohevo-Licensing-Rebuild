# Kohevo Brand Persistence System — Phase 3 (Authentication/Login Identity Integration)

**Status:** Implemented
**Date:** 2026-09-21

## What changed

`PlatformSignature::render(MODE_SIGNATURE)` ("Powered by Kohevo" + mark) —
the mode its own Phase 1 docblock names for this exact context — now renders
at the bottom of both login surfaces, after everything else in the card:

| Surface | File(s) changed | Position |
|---|---|---|
| Admin login | `admin/login.php` | Immediately after the existing `.auth-credit` ("Built & maintained by") block, still inside `.auth-form-inner` |
| Customer auth-split (login, register, forgot-password, reset-password, verify-email — all share this partial) | `customer/partials/footer.php` (+ matching CSS in `customer/partials/header.php`) | Immediately before `.auth-form-inner` closes, i.e. after whatever form the calling page placed there |

Both call sites are one line:
`\Slate\Services\Content\PlatformSignature::render(\Slate\Services\Content\PlatformSignature::MODE_SIGNATURE)`.
Neither names a platform asset path or the literal string `Kohevo`.

## Why `customer/partials/footer.php`, not `customer/login.php` or only `header.php`

`customer/login.php` sets `$customerPageVariant = 'auth-split'` and requires
`partials/header.php` then `partials/footer.php` — it owns none of the shell
chrome itself, so it was never touched, per the instruction not to modify it
directly. The tenant brand already renders exactly once, at the *top* of the
card, in `partials/header.php`'s `auth-split` branch (confirmed by that
branch's own comment: "the brand mark is shown once, in the form panel
below — this hero panel is decorative"). The admin login's own layout puts
its lowest-priority identity element (the owner/support credit) at the very
*bottom* of the card, after the form. To match that same hierarchy
(tenant → form → attribution-if-any → Kohevo, Kohevo last) on the customer
side, the signature has to render *after* whatever `customer/login.php` (or
`register.php`, `forgot-password.php`, etc.) puts inside `.auth-form-inner` —
which only `partials/footer.php` can do, since it's the file that closes the
card after the calling page's own content has already been emitted.
`partials/header.php`'s `auth-split` branch itself was not modified.

## "Built & maintained by" — kept separate, not merged

`admin/login.php`'s existing `brand_owner`/`brand_owner_url`-backed credit
line is untouched: same settings, same fallback literals (`'Rakib Hasan'`,
`'https://rakibhasaan.com'`), same markup, same position. The platform
signature is a new, visually distinct block directly below it (its own
`margin`/smaller `font-size`, no shared class), not a merge of the two
concepts — a unit test asserts the existing attribution's source is
byte-identical in intent and that it precedes the new signature.

## Light/dark

Both insertion points sit on light/glass card backgrounds (`.auth-form-inner`
on both admin and customer), matching the *existing* logo treatment
immediately above them in both files (both already documented as
"always the light card behind this logo, regardless of OS theme"). The
compact mark's black ink has no legibility problem there, so — unlike the
Phase 2 admin sidebar — no tile/dark-variant handling was needed. No change
to `PlatformIdentity`/`PlatformSignature`.

## Security / regression

No authentication logic was touched: `Auth::check()`/`Auth::attemptLogin()`/
`Auth::customer()`/`Auth::attemptCustomerLogin()`/`csrf_verify()`/MFA flow are
all byte-unchanged (verified structurally by unit tests, and the existing
login-failure/rate-limit/enumeration integration test suite still passes
unmodified against the new markup). `PlatformIdentity` still reads no
tenant setting; tenant branding (logo, colors, site name) still renders
exactly as before on both pages.

## Non-goals for this phase

No error page, email template, or licensing code was touched. No database
change. No `white_label` entitlement. No Slate→Kohevo literal cleanup
(including the stray `admin/login.php` "Slate is installed" string, left
exactly as found). No change to the Phase 2 admin-shell/customer-dashboard
integrations — both verified unchanged.
