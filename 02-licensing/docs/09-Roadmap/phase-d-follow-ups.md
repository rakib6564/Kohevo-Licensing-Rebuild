# Phase D — tracked follow-ups

Gaps left open deliberately while converting feature plugins from iframe embeds
to real blocks. Recorded here rather than in code comments alone, per this
section's rule: where the code and the blueprint disagree, the gap is tracked,
never silently baked in.

---

## D1-a — an inline form's validation errors leave the page

**Status:** open · **Raised:** 2026-08-28, during D1 · **Blocks:** nothing

### What happens

The `form` block renders inline and POSTs to `/forms/<slug>`, which owns CSRF,
the spam guard, validation, storage and dispatch. A **successful** submit returns
to the host page via the `return_to` field, and the block renders the form's
`success_message` in place.

A submit that **fails validation** does not come back. `/forms/<slug>` re-renders
the standalone form page with the errors and the submitted values, so the visitor
is moved off the page they were on and sees the form in different chrome.

It is functional and recoverable — nothing is lost, the errors are correct, and
the visitor can complete the form there. It is not seamless, and it is a
behaviour change from the iframe, which kept a failed submit in place.

### Why it was not fixed in D1

Returning to the host page needs the submitted values and the error array carried
across a redirect, which means flashing them through the session and having the
block read and clear that flash. That is a larger change than the conversion
itself — it introduces per-form session state, an expiry question, and a
multiple-blocks-on-one-page question — and bundling it would have made the
conversion hard to review and hard to attribute if something regressed.

### What fixing it involves

- Flash `['values' => …, 'errors' => …]` under a per-form session key on the
  validation-failure branch of `plugins/forms/public/router.php`, then redirect
  to `return_to` instead of re-rendering.
- `FormsAPI::renderContentBlock()` reads and clears that flash, passing the
  values and errors into the `renderFormBody()` call it already makes — the
  parameters exist and are currently passed empty.
- Decide what happens when a page carries two form blocks and one fails: the key
  must be per form slug, not per session.
- Keep the standalone page working unchanged for direct `/forms/<slug>` visits,
  which have no `return_to`.

### How to know it is done

A test that posts an invalid submission with `return_to` set and asserts the
redirect target is the host page, that the returned page shows the field error,
and that the previously entered values are still in the inputs.

---

## Delete `Theme::fontPairing()`

**Status:** decided, not yet done · **Raised:** 2026-08-29, during Phase E step 1

### What it is

`Slate\Presentation\Theme\Theme` declares `fontPairing()`, implemented by
`ArrayTheme` and `DefaultTheme`, returning `['sans' => …, 'mono' => …]` with the
primitives as fallbacks. It reads like the mechanism by which a theme chooses its
typefaces.

**Nothing emits it.** `TokenEmitter` emits `$theme->tokens()` and only that. The
only other `fontPairing` hits in the codebase are `Branding::fontPairings()`,
which is content-builder's unrelated preset picker for the site settings screen.
So a theme can implement `fontPairing()` in full and no font will change.

### Why it is being deleted rather than wired up

This is the fourth interface in this codebase found complete on the read side
with no production caller, after `RenderContext::withTheme()`, the
`content_resolve_media_key` filter, and the whole
`DocumentTemplate`/`PageAssembler` envelope layer. Each cost a real investigation
to discover, and each looked correct while doing nothing.

The need it appears to serve is already met: Phase E step 1 added
`slate-font-heading` as a token, and a theme overrides it through
`Theme::tokens()`, which *is* emitted and *is* already how every other themed
value travels. Keeping a second, dead mechanism for the same job invites someone
to implement against it.

### The condition for wiring it instead

Only if there is a concrete, near-term caller. In that case it ships **with**
that caller and with a reachability test — the `served === 'core'` shape — never
dormant. A wired interface with no test asserting it is reached is the same
defect with better intentions.

### Scope

Its own commit. Not folded into a token change, because deleting a public
interface method is a different kind of change from adding tokens, and the two
should be revertable independently.

---

## Four of six built-in palettes ship muted text below AA

**Status:** open, owner's call · **Raised:** 2026-08-29 · **Blocks:** nothing
· **Not** a Phase E item

### What was measured

`Branding`'s built-in themes pair a `muted` text colour with `surface` and
`page_bg`. WCAG AA requires 4.5:1 for normal text; muted is used at 14px, which
is normal text. Measured from the palette definitions:

| palette | muted | on surface | on page | |
| --- | --- | ---: | ---: | --- |
| `soft-rose` | `#9a7480` | **3.43** | **3.80** | fails |
| `warm-editorial` | `#8a6f6f` | **3.76** | **4.20** | fails |
| `minimal-mono` | `#71717a` | **4.40** | 4.83 | fails on surface |
| `fresh-natural` | `#5f7268` | **4.41** | 4.80 | fails on surface |
| `clean-corporate` | `#667085` | 4.75 | 4.97 | passes |
| `bold-dark` | `#94a3b8` | 5.71 | 6.96 | passes |

### Why it is not a Phase E item

This is not caused by anything the consolidation does. Every tenant on those four
palettes renders sub-AA muted text on their own pages **today**, and has since
the palettes shipped. It was found only because Phase E 2a made the membership
block follow tenant branding, and checking that block for contrast regressions
surfaced the inherited pair.

The block was fixed so it stops *spreading* the problem — it no longer uses muted
for small text at all. That is deliberately the smaller fix: it changes only code
we own and holds on every palette. It does not fix the palettes.

### Why it needs a decision rather than a patch

Correcting the four `muted` values changes the appearance of every page of every
tenant on those themes. That is a bigger, more visible change than anything in
Phase E, and it is the owner's to make — which is exactly why it is recorded here
instead of being quietly folded into a migration.

Darkening each failing `muted` until it clears 4.5:1 on both `surface` and
`page_bg` is the mechanical fix; whether the resulting greys still read as
"muted" is a design judgement.

### A smaller related note

`--slate-color-border` has no tenant source — `Branding` emits no border colour —
so after 2a a cool-grey default border sits on warm tenant surfaces at 1.01:1,
effectively invisible. It is decorative, so not an AA text failure, and it was
already near-invisible before 2a (1.24:1). Worth knowing that content-builder's
own `.cb-card` uses no border at all and relies on background alone; the
membership block introduced one. Either derive a border from the tenant palette
or drop it — a design call, not a migration one.

---

## Auth tests poisoned later runs (fixed)

**Status:** fixed · **Raised:** 2026-08-29, during Phase E batch 1

Two integration tests could fail, leave a row behind, and then make the *next*
run fail differently — for a reason unrelated to whatever that run changed. That
is a gate-integrity problem, not a flaky-test annoyance: a poisoned rerun can
make a clean batch look broken, and a rerun that happens to clean up can make a
broken one look green.

### Cause

`_identity_cleanup()` and `_auth_wipe()` both ran in a `finally`, so cleanup was
not missing — it was **parent-scoped**. Each looked up the contact or customer
first and deleted identities via `contact_id`. An identity whose parent was
already gone was therefore unreachable, and identities carry
`uniq_tenant_provider_cred`, so the orphan collided on every later run:

```
Duplicate entry '1-password-__ids_auth@example.test' for key 'uniq_tenant_provider_cred'
```

That state is exactly what a test leaves when it dies between `register()` and
its assertions.

### Fix

Delete identities by `credential_ref` directly, before the parent-scoped sweep,
so teardown does not depend on the parent existing.

Proven both directions with a deliberately injected parentless identity: without
the fix the suite fails with the duplicate-key error above (140/141); with it,
141/141 and the orphan is cleaned to zero.

### Worth remembering

A `finally` block is not the same as working teardown. Both of these had one, and
both leaked — because the cleanup could only reach the rows it was trying to
delete *through* a row that was already gone.

---

## The golden suite outgrew the default tool timeout

**Status:** open · **Raised:** 2026-08-29 · **Blocks:** nothing yet

`php tests/integration/run.php` now takes ~67s on its own and the full
`tests/run.sh` exceeds two minutes. It is not slow logic — it is bytes.

The SBK golden is 86KB, and most of that is not the thing being gated. The
`sbk-fontface` block carries the display font as a base64 data URI whenever the
inline path is taken, and every render re-encodes it. The gate's real signal is
the ~200 token consumers in `sb.css`; the font blob is dead weight that gets
generated, written, read back and byte-compared on every run.

### The fix, and the precedent for it

`render-goldens.php` already strips the install base before comparing, so a
golden is about markup rather than about where the checkout happens to live. The
same move applies: strip or hash the `data:font/...;base64,...` payload out of
the compared bytes, in both the generator and the comparison, so the golden keeps
asserting what the page is made of without carrying 35KB of font per render.

### Why it is worth doing before step 3

Step 3 adds a substring-assertion pass over rendered output. That is another full
render of every golden, on top of a suite already brushing the timeout. Cheap to
do now, annoying to diagnose later as "CI got flaky".

---

## `cron.php` has no tenant contract, and the next listener will inherit that

**Status:** open · **Raised:** 2026-09-02, investigating the two cron sweeps · **Blocks:** the push worker in the realtime messaging design

### The evidence, in one class

`CoachingAPI::deliverScheduled()`:

```sql
SELECT id, thread_id, sender FROM coaching_message
 WHERE sent_at IS NULL AND send_at IS NOT NULL AND send_at <= NOW()
```

`CoachingAPI::generateSummariesForExpiringMemberships()`:

```sql
SELECT s.customer_id FROM membership_subscriptions s
  LEFT JOIN coaching_summary cs ON cs.customer_id = s.customer_id AND cs.tenant_id = s.tenant_id
 WHERE s.tenant_id = ?
```

Same class. Both called from the same `Coaching::runCron()`, two lines apart. One is tenant-scoped and one is not.

That is not carelessness — it is the **absence of a contract**. Nothing tells a listener author whether the runner has already scoped the request, so each one guesses, and the same author guessed differently twice in the same method.

### Five listeners, three behaviours

| listener | hook | tenancy |
|---|---|---|
| `Booking::sendReminders` | `frequent_cron` | **unscoped** — both the reminder and follow-up queries |
| `BookingPlus::runCron` | `frequent_cron` | `current_tenant_id()` + `WHERE m.tenant_id = ?` |
| `Coaching::runCron` | `frequent_cron` | **one of each**, as above |
| `Clientdesk::runDailyAutomations` | `daily_cron` | `current_tenant_id()` + filters |
| `Sitehub::dailyHealthCheck` | `daily_cron` | **unscoped** — `SELECT id FROM sitehub_sites` |

`with_tenant()` exists at `includes/helpers.php:38`. **Nothing outside `tests/` calls it** — the only other references are the render harness and slate-mcp's own private `withTenant()`. No listener receives tenant context from the runner, because the runner has none to give: `cron.php` fires `Hook::doAction('frequent_cron')` once, in whatever tenant the HTTP request resolved to (no session, so the `TENANT_ID` constant).

All three unscoped sweeps are already inside the anti-drift TENANT backlog — 30 of its 68 entries sit in these three files. Known debt, not new.

### Recommendation — fix the runner once

Iterate tenants in `cron.php`, wrap each in `with_tenant()` **and** its own `try/catch`, and make **"a listener may assume it is scoped"** the stated contract. The alternative is fixing N listeners forever, and every listener written after today is one more coin-flip.

### Three hazards, each a silent failure

**1. The loop and the isolation are one change, not two.**
`Hook::doAction()` puts its `try/catch` *inside* the inner loop (`src/Kernel/Event/Hook.php:92`), so today one listener throwing is logged and the rest still run. A tenant loop sits **outside** `doAction`, where nothing catches. Adding the loop without per-tenant `try/catch` in the same commit makes failure isolation **worse than it is now**: one tenant's throw kills every remaining tenant. They must ship together.

**2. `cron_last_daily` is tenant-scoped.**
`Database::setting()`/`setSetting()` filter on `current_tenant_id()`. If the gate is read outside the loop while `daily_cron` fires inside it, tenant 1 sets the flag and tenants 2..N never receive daily work — no error, no log, no failed request. The gate and the action have to sit in the same loop iteration.

**3. A loop makes one run N times longer.**
On shared hosting a cron timeout kills the run mid-loop and the last tenants are simply never reached. `try/catch` does not help — nothing throws. The loop must be resumable, or the run needs a time budget with a recorded cursor so the next invocation starts where this one stopped.

### Why now, and how

Blast radius today is **nil**: production has one tenant (`Default`). That is the argument for doing it now rather than deferring it — it can be built and verified while nothing depends on it being right.

It blocks the **push worker in the realtime messaging design**, which hangs off `frequent_cron` and would otherwise inherit the same coin-flip.

When built, it goes in **its own PR with the expected outcome stated before it lands**. It changes behaviour for every listener at once, so "what should be different afterwards" has to be written down before, not reconstructed from what happened.
