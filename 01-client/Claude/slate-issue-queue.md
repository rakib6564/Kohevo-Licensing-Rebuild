# Slate — issue queue v2

**Supersedes v1 entirely.** v1's #1 and #2 were built on a browser-level reading that the code disproved; the security findings did not exist when it was written.

**Sources:** the browser audit (`claude/slate-duplication-audit.md`, corrections block at the top), the Codex code pass (`audit/inventory.md`, `audit/findings.md`), and the Claude Code audit (H-0…H-5, M-0…M-9, L-1…L-4 plus per-cluster reports).

**House rules — acceptance criteria on every issue below:**

- branch off `develop`, small gated changes
- shared/appearance changes golden-test gated, and the test proven able to fail before it is trusted green
- migrations run clean **both ways** against a disposable copy of the live DB
- smoke-check affected live pages after merge
- new UI strings ship FR + EN

**The target shape is `studio`.** It is the only identity-spine adopter, the only plugin with working migration rollbacks, has 16/16 admin screens gated and correct portal ownership. Where an issue below says "match studio", that is a reviewable standard, not an aspiration — the pattern already exists in this codebase.

---

## Pre-flight — RESOLVED 1 Sep 2026 (checked directly on the host via cPanel)

**PF-1. `register_argc_argv` — On.** `php -i` on premium106 returns `register_argc_argv => On => On`. The `?--apply` path on the destructive studio scripts was therefore remotely triggerable, not merely theoretical. (Read from the CLI SAPI; the web SAPI uses the same php.ini family but was not separately confirmed. Moot once SEC-1 deploys.)

**PF-3. Access logs — no evidence of access. Not an incident.**

Searched every archived log for `greenlightinduction.rakibhasaan.com` — 14,334 requests spanning **31 Jul 2026 09:39 → 1 Sep 2026 06:36**, plus today's live window (414 requests, 08:40–16:02):

| Pattern | Hits | Outcome |
|---|---|---|
| `plugins/*/{tools,bin,scripts}/` | **0** in archives | the single hit today was our own verification probe → **403** |
| `/slate/_*.php` | 7 | all **403** — the existing `.htaccess:29-32` deny is working |
| `/slate/.env` | 1 | **403** |
| `.env` anywhere (bot scanning) | 143 | all **403/404**; none served |

Nobody found the scripts. Note this is luck rather than protection: the directory returns 403 only because indexes are off, so a request for a script *by name* would have been served. Log retention starts 31 Jul 2026 — anything earlier is not retained, so the honest claim is "no evidence of access in the last five weeks", not "never accessed".

**Credential rotation is therefore not required.**

**PF-2. `APP_SECRET` — SET, 64 characters.** Read from the live `.env` on the host (length only, value never printed). A 256-bit secret is in place, so the HMACs it keys are **not** derivable. SEC-2 is therefore a latent fail-open defect, not a live exposure — re-rated below.

### All three pre-flight items are now resolved

| Item | Result |
|---|---|
| `register_argc_argv` | **On** — the destructive capability was real |
| Access logs (31 Jul → 1 Sep + live) | **Zero** requests to the exposed paths; no exploitation |
| `APP_SECRET` | **Set, 64 chars** — nothing derivable |

**No live security exposure remains open.** Everything below is hardening, correctness, or cleanup.

---

## Scope — narrowed 1 Sep 2026

**Out of scope for now:** `shop`, `shop-emails`, `restaurant`, `flat-rate-shipping`, `shipping-flat-rate`, `timeclock`, `sitehub`, `slate-mcp` (AI Gateway), `clientdesk`.

**In scope:** `booking`, `booking-plus`, `membership`, `coaching`, `content-builder`, `forms`, `multilang-translate`, `react-site-bridge`, `stripe-payment`, `studio`, plus core (`admin/`, `src/`).

### Deactivate rather than ignore

An out-of-scope plugin that is still **active** keeps its admin screens, its public routes and its attack surface. Deactivating the ones on the skip list is the cheaper move and it serves three goals at once: it shrinks what has to be secured, it removes rows from an already overloaded admin nav (UX-1), and it is reversible in one click.

`restaurant` is the clearest case — it is active on a wellness tenant, putting "POS register" and "Floor & tables" in Stéphanie's sidebar.

**Done 1 Sep 2026.** Of the nine on the skip list, only two were active. Already inactive: `clientdesk`, `flat-rate-shipping`, `shipping-flat-rate`, `seo`, `shop`, `shop-emails`, `timeclock`, `sitehub`.

- [x] **`restaurant` deactivated** — admin nav lost its 12-row RESTAURANT group; `/book`, the admin dashboard and the customer portal all verified still working
- [ ] **`slate-mcp` (Slate AI Gateway) — STALE: this said "still active, deliberately"; the database says `inactive` and the break it warned about has already happened. See SEC-9.** It is an *access* plugin, not a feature plugin: it issues MCP tokens (`slatemcp_tokens`) and backs the "AI Access" screen. Deactivating it silently breaks any agent or integration currently connecting to Slate through it. Confirm nothing depends on it before switching it off.
- [ ] **`small-business-kit` is active and appears on neither the in-scope nor the skip list.** Decide which it is.

### What descoping does *not* remove

- **PRIV-1 (erasure).** Deactivating a plugin does not delete its data. `shop_customers`, `restaurant_customers`, `clientdesk_clients` and the order snapshots still hold personal data, and a subject-access deletion still has to reach them. The lifecycle contract must cover dormant tables; only the *feature* work is deferred.
- **SEC-2, CORE-1, FIX-1, PRIV-2.** These are core-level, not plugin-level. Shop and forms are merely where the `APP_SECRET` bug surfaces; the fix is one accessor in core either way.
- **SEC-4 (Stripe).** `stripe-payment` stays in scope — payments are live.

### Dropped from the queue

- **FIX-4 item 10** (two shipping plugins claiming the same filter) — both plugins are inactive and out of scope.

---

## Dependency order

```
SEC-1 ─ ship today, blocks nothing, waits for nothing
SEC-2, SEC-3, SEC-4 ─ independent, land this week
        │
CORE-1 (anti-drift mechanism) ──┬─→ hardens SEC-2/3 permanently
                                └─→ prerequisite for CON-* not regressing

PRIV-1 (erasure path) ──→ PRIV-2 (profile consolidation)

FIX-1…FIX-4 ─ independent, any time

CON-1 (Booking+ into Booking) ──┬─→ CON-2 one service editor
                                ├─→ CON-3 one notification schedule
                                └─→ CON-4 one booking-rules screen

UX-1 (nav) ─ after CON-1 lands; it moves what CON-1 creates
UX-2, UX-3 ─ independent
OPS-1 ─ independent
```

---

# P0 — Security

## SEC-1 — Unauthenticated maintenance scripts under `plugins/studio/tools/`

**Labels:** `security` `P0` `ship-today`

### Problem

Six maintenance scripts are served directly over HTTP with no CLI guard, no auth, and no `CRON_SECRET`. They bypass the app bootstrap and hand-parse `.env` for DB credentials. Three are destructive and gate destruction on `$argv`, which is reachable from the query string when `register_argc_argv` is On. Nothing in `.htaccess` covers `plugins/*/tools/`.

Disclosure is unconditional. Deletion depends on one PHP directive.

`.htaccess:29-32` shows this exact bug was found and fixed here before, for root `_*.php` files, with a comment describing scripts that dumped form submissions with submitter email addresses. **The fix never generalised.**

### Proposed change

Deny the **class**, not the path. `plugins/*/tools/` closes today's six scripts and leaves the next `plugins/*/bin/` or `plugins/*/scripts/` open. If the host allows it, the durable version is deny-by-default with an allowlist of real entry points.

Then, separately and without urgency: give the scripts a proper CLI guard (`php_sapi_name() === 'cli'`), a `CRON_SECRET` for any that must run over HTTP, and make them boot through the app rather than parsing `.env` themselves.

### Acceptance criteria

**Scope widened during the fix:** `plugins/shop/bin/migrate-images.php` was exposed by the same gap. The rule covers `tools`, `bin` and `scripts`.

**Status:** committed on `fix/sec-1-deny-tools-dirs` as `RewriteRule (^|/)(tools|bin|scripts)/ - [F,L]`, placed above the file-passthrough rules. **Not deployed** — the Deploy workflow is manual-only, and it ships all of `develop` plus runs migrations with `rsync --delete`, which is disproportionate for one config line. Prefer a direct file upload.

**ORIGIN FIX APPLIED AND VERIFIED — 1 Sep 2026.** `RewriteRule (^|/)(tools|bin|scripts)/ - [F,L]` inserted directly into the live `.htaccess` immediately below `RewriteBase /slate/`, inside the `<IfModule mod_rewrite.c>` block and above the file-passthrough conditions. Backup at `~/htaccess-greenlight-backup-20260901.txt`. Verified: site loads normally, and `purge_studio_orphans.php` returns **403**. **SEC-1 is closed for the live install.**

Still open: the same rule has not been applied to `~/public_html/slate-test` (see the scope correction below), and the live file now differs from the deployed ref until `fix/sec-1-deny-tools-dirs` is merged and shipped.

**Edge mitigation is live (1 Sep 2026).** Cloudflare custom rule *"Block plugin tools dirs (SEC-1)"* — Block, Active, matching `/slate/plugins/` + `/tools/|/bin/|/scripts/`. Built by repurposing the disabled "Protect Wordpress" rule, so the free plan's 5-rule limit still has a spare slot. It matched 3 requests in the preceding 24h (our own verification probes) and 0 legitimate traffic.

**This does not close the issue.** The edge rule is bypassed by the 76 account-wide IP Access "Allow" entries, and by anyone reaching the origin IP directly (SEC-6). Origin `.htaccess` is still required.

### Scope correction — a second exposed copy exists on the host

Found on the server, not in the repo. Three Slate trees are on disk:

| Path | Served at | `.htaccess` | `plugins/*/tools`, `bin` |
|---|---|---|---|
| `~/greenlightinduction.rakibhasaan.com/slate` | the live site | 98 lines, no tools rule | `studio/tools`, `shop/bin` present |
| `~/public_html/slate-test` | `rakibhasaan.com/slate-test/` | 93 lines, no tools rule | `studio/tools`, `shop/bin` present |
| `~/public_html/slate` | `rakibhasaan.com/slate/` | **none at all** | none present |

**`slate-test` is a second publicly reachable copy of the whole platform with the same exposure**, its own admin login and presumably its own database. The Cloudflare rule as deployed matches `/slate/plugins/` and therefore **does not cover `/slate-test/plugins/`**.

Required:

- [x] widen the Cloudflare expression to `(/slate/plugins/ or /slate-test/plugins/)` — **done 1 Sep 2026**, rule saved and Active
- [x] origin `.htaccess` block applied to the live tree — verified 403
- [x] **`slate-test` parked 1 Sep 2026** — `Require all denied` prepended to its `.htaccess`; `https://rakibhasaan.com/slate-test/` returns a server-level **403**. Backup at `~/htaccess-slatetest-backup-20260901.txt`. It remains on disk pending the Phase 2 decision to archive or delete.
- [ ] `~/public_html/slate` has no `.htaccess` whatsoever; establish whether it is live or dead weight

- [ ] every script under `plugins/*/{tools,bin,scripts}/` returns 403 over HTTP, verified against the live host **after deploy**
- [ ] the deny is written against the class, and a newly created `plugins/foo/tools/x.php` is denied without further config
- [ ] the rule's comment states that `tools/`, `bin/` and `scripts/` are reserved directory names that are never web-served, so a future static-asset directory is not named one of them and 403s mysteriously
- [ ] destructive scripts additionally refuse to run outside CLI, so the `.htaccess` is defence in depth rather than the only control
- [ ] access logs checked for prior hits (PF-3); if any, treat as an incident and rotate DB credentials
- [ ] no plugin's tools directory parses `.env` directly afterwards

---

## SEC-2 — `APP_SECRET` guarded with `defined()` instead of a non-empty check

**Labels:** `security` `P1` `fail-open`

**Re-rated from P0 on 1 Sep 2026:** the deployed `APP_SECRET` is set and 64 characters long, so nothing is currently derivable. This is a latent fail-open defect, not a live exposure. Fix it with CORE-1 rather than ahead of it.

### Problem

`APP_SECRET` defaults to empty. Three HMAC call sites check it with `defined()`, which is true for an empty constant, so with no secret present they would key an HMAC on an empty string — shop's storefront CSRF token and forms' document-download token would become derivable. Today's environment has a real secret, so they don't.

The danger is the failure mode, not today's state: stand up a new environment, rename `.env`, or lose the variable in a deploy, and the app silently degrades to an empty-keyed HMAC instead of refusing to boot. Silent degradation is the worst possible behaviour for a signing key.

**Four other call sites get this right.** This is drift, not ignorance.

### Proposed change

Fail closed, loudly, in one place: a single accessor that throws (or refuses to boot) when `APP_SECRET` is missing or empty. Every consumer routed through it. No call site does its own check.

### Acceptance criteria

- [ ] all seven consumers call one accessor; zero use `defined()` directly
- [ ] an empty or missing `APP_SECRET` fails at boot with a clear operator message, not silently at token-generation time
- [ ] any tokens minted under an empty secret are invalidated, not just fixed going forward
- [ ] a test asserts that an empty secret cannot produce a token
- [ ] covered by CORE-1 so a fourth wrong copy cannot be added

---

## SEC-3 — Two public lookups omit the tenant filter their sibling copies have

**Labels:** `security` `P0`

### Problem

booking-plus's `manage_token` lookup and react-site-bridge's `public_id` lookup are missing the tenant filter that their sibling copies carry — five `manage_token` lookups with one wrong, three site lookups with one wrong. The RSB one needs no secret at all: the identifier is in the site's own URL.

Bounded by the tenancy model: there is no host-based tenant resolution, so one deployment serves one tenant and these are inert **today**. That bound is a deployment fact, not a code property — and the owner has confirmed **multi-tenant single-install is on the roadmap**, so it expires. Fix now, while it is cheap and nothing is on fire.

### Proposed change

Add the missing filters. Then route all five `manage_token` lookups and all three site lookups through one function each, so there is no longer a set of copies to drift.

### Acceptance criteria

- [ ] both lookups tenant-qualified
- [ ] one lookup function per identifier type; no inline copies remain
- [ ] a test asserts a token/public_id from tenant A cannot resolve under tenant B
- [ ] the tenancy assumption is written down in the repo — *one tenant per deployment today, multi-tenant single-install planned* — so the next developer does not write another unqualified query in good faith

---

## SEC-8 — `multilang-translate` overwrote translations across tenants — FIXED

**Labels:** `security` `P0` `multi-tenant`
**Found:** 2 Sep 2026, by the CORE-1 guard, when the plugin was first committed to git.

### Problem

`plugins/multilang-translate/includes/StringRepo.php` was tenant-aware almost
everywhere — 24 `tenant_id` references in that one file — and missed it in two
places, both reachable from user input:

- **`StringRepo.php:143` (`saveCell()`)** — the method is *handed* `$tid` and did
  not use it in its lookup:
  `SELECT id FROM multilangtranslate_translations WHERE string_id = ? AND locale = ?`.
  `$stringId` arrives from the grid POST or a CSV import and was not otherwise
  proven to belong to the caller's tenant. The UNIQUE KEY is
  `(string_id, locale)` with **no `tenant_id`**, so one row serves that pair
  globally — the unscoped lookup found another tenant's row and the `UPDATE`
  on the next line overwrote it.

- **`StringRepo.php:363` (CSV import)** — read `translated_text` by a
  `string_id` taken straight from the uploaded file, so a crafted CSV could read
  another tenant's translations and then overwrite them through `saveCell()`.

Four further hits in the same file were **transitively safe** and annotated
rather than rewritten: each updates by an id returned from a tenant-scoped
`SELECT` on the preceding line, or correlates against ids from a tenant-scoped
query. A fifth of that kind (`:93`) surfaced only once the others cleared — it
had been masked by an earlier finding in the same file.

### Why this matters beyond the fix

This is the first bug **found by CORE-1 rather than by a human reading code**,
and it was found the moment a plugin that had been running in production for
months first entered git. The plugin had never been scanned because it had never
been committed — which is the same single-copy problem the cleanup plan's one
rule exists to close. SEC-8 is the argument for that rule, not a footnote to it.

It also refutes the comfortable reading of SEC-3, that unscoped queries are
inert while one deployment serves one tenant. These two are not inert: the
UNIQUE KEY makes `(string_id, locale)` a *global* row, so tenants collide
through the key even without host-based tenant resolution.

### Resolution

Fixed in `d873ca1` on `feat/multilang-translate`, merged as #48. Both lookups and
the follow-up `UPDATE` now use `slate_tenant_clause()`; the four safe sites carry
`anti-drift-ignore: TENANT` with the reasoning at the call site. Guard clean,
TENANT backlog unchanged at 68 — no baseline entries were added to absorb these.

Committed **separately** from the verbatim import (`a4a4468`) so that commit
still records exactly what production was running, and the diff between the two
is the reviewable security change.

### Remaining

- [ ] **Live still runs the unscoped version** until a deploy carries `d873ca1`.
      The exposure needs a tenant boundary to matter, so it is not urgent on a
      single-tenant install — but it is the one item where live is knowingly
      behind git on a security fix.
- [ ] `multilangtranslate_translations` should carry `tenant_id` in its UNIQUE
      KEY, not just as a column. The correct query is now enforced by the guard;
      the schema still permits the collision the guard is compensating for.

---

## SEC-4 — `stripepayment_sessions` is not tenant-scoped

**Labels:** `security` `P1` `schema`

The Stripe session mapping table has no `tenant_id`, while the charge ledger beside it is tenant-scoped and unique on `(tenant_id, stripe_session_id)`. Success and webhook paths look rows up by Stripe ID alone.

Codex rated this Critical; the Claude Code pass established there is no host-based tenant resolution and withdrew its own equivalent finding. **Real schema debt, inert today, live the day multi-tenant ships** — which the owner has confirmed is planned. Backfilling `tenant_id` gets harder with every payment row added, so this is cheapest now and most expensive at migration time.

- [ ] `tenant_id` added, backfilled from associated order/session context; unmigratable rows flagged for manual reconciliation, never guessed
- [ ] unique keys made tenant-aware to match the ledger
- [ ] every select and update on the table is tenant-qualified
- [ ] `migrate` and `rollback` verified

---

## SEC-5 — Client IP is not reliably restored behind Cloudflare

**Labels:** `security` `P1` `infra`

### Problem

The site is Cloudflare-proxied — `greenlightinduction.rakibhasaan.com` resolves to `172.67.131.21` / `104.21.3.183`, and `rakibhasaan.com` delegates to `ajay/june.ns.cloudflare.com`.

Origin access logs contain **27 distinct Cloudflare IP addresses as request sources** alongside genuine client IPs. Client-IP restoration is therefore partial, not reliable.

Anything in the app that keys on `REMOTE_ADDR` is affected:

- **the login throttle** — if attempts bucket by a Cloudflare IP, thousands of unrelated visitors share one bucket. Either the throttle never trips for a real attacker, or one attacker locks out every user behind the same edge node. This throttle has already been broken once by the PHP/MySQL clock bug (FIX-1); this would be a second, independent break.
- rate limits, audit-log entries, session-binding, and any per-IP allow/deny

**Edge mitigation is live (1 Sep 2026).** Cloudflare rate-limiting rule *"Throttle Slate logins (SEC-5)"* — 5 POSTs per 10s per IP to `/slate/admin/login.php` or `/slate/customer/login.php`, Block, Active. Cloudflare sees the true client IP at the edge, so this throttle works even while the app's own does not.

**It is a speed bump, not a lock.** The free plan caps both the counting period and the mitigation duration at 10 seconds, so a determined attacker still gets roughly 30 attempts a minute. That beats unlimited; it does not replace a working application-level throttle.

### Proposed change

Restore the real client IP once, in core, from `CF-Connecting-IP` — trusting that header **only** when the request arrives from a published Cloudflare range, never unconditionally, or spoofing the header becomes trivial. Every consumer reads the restored value through one accessor.

### Acceptance criteria

- [ ] one accessor returns the client IP; no code reads `REMOTE_ADDR` directly (enforceable by CORE-1's lint)
- [ ] `CF-Connecting-IP` is honoured only from verified Cloudflare ranges, and the range list is refreshable
- [ ] a request forging `CF-Connecting-IP` from a non-Cloudflare source is ignored, proven by test
- [ ] origin logs show client IPs, not edge IPs — verify after the change
- [ ] the login throttle is re-tested against the restored value

---

## SEC-6 — Origin is reachable directly, bypassing Cloudflare

**Labels:** `security` `P2` `infra`

Shared origin IP `198.54.125.131` is published in cPanel and hosts many name-based vhosts. Any protection applied only at the Cloudflare edge can be bypassed by requesting the origin IP with the right `Host` header.

This is why an edge WAF rule is a **stopgap** for SEC-1 and never the fix — and why every origin-side control in this queue still matters.

- [ ] confirm whether the origin answers for this hostname on its IP directly
- [ ] if it does, restrict origin access to Cloudflare ranges (firewall or Apache), or accept the bypass explicitly and rely only on origin-side controls

---

## SEC-7 — Offboarding: a departed collaborator's access has not been revoked

**Labels:** `security` `P1` `access`

### Problem

A colleague (Rasel Ahmmed, credited in `multilang-translate`'s `plugin.json`) worked on the platform and no longer does. No access review has followed. Observed:

- **Slate admin Users** lists `rahmmed750@gmail.com` with the **Manager** role — a working login today
- `info@companybstudio.com` (Ryann Marshall) holds **Super Admin** on the Solaya install — verify that is still intended
- **5 FTP accounts** exist in cPanel with no recorded owner
- **76 Cloudflare IP Access "Allow" rules**, several labelled with people's names ("Rimon", "Shakib", "DHK RK"). Each bypasses every custom rule, account-wide, indefinitely — and residential IPs are reassigned to strangers over time
- GitHub collaborators on `rakib6564/slate-platform`, and any per-person database users, are unreviewed

Access that outlives the working relationship is the quietest kind of exposure: nothing fails, nothing alerts, and it stays true for years.

### Acceptance criteria

- [ ] every Slate admin user account has a current, named owner; the rest are removed
- [ ] every FTP account has a named owner; the rest are removed
- [ ] the Cloudflare IP allowlist is pruned to entries you can name **today**, each with a dated note
- [ ] GitHub collaborators reviewed
- [ ] per-person database users reviewed
- [ ] a short offboarding checklist is written down, so the next departure is a five-minute job

---

## SEC-9 — Deactivating a plugin does not remove its public surface

**Labels:** `security` `P0`

**12 of 59** plugin entry points under `plugins/*/public/` and `plugins/*/storefront/` check `PluginLoader::isActive()`. Only `stripe-payment` (4/4) does it consistently; `shop` is 7/9 and `booking` 1/2. Nine plugins have none at all:

| plugin | guarded / entry points |
|---|---|
| stripe-payment | **4 / 4** |
| shop | **7 / 9** |
| booking | 1 / 2 |
| booking-plus | 0 / 2 → 1 / 2 once `fix/booking-plus-message-isactive` merges |
| studio | 0 / 11 |
| membership | 0 / 9 |
| restaurant | 0 / 8 |
| react-site-bridge | 0 / 4 |
| content-builder | 0 / 3 |
| clientdesk, timeclock | 0 / 2 each |
| forms, slate-mcp, small-business-kit | 0 / 1 each |

`.htaccess:110` deliberately makes `assets/`, `public/` and `storefront/` browser-fetchable — that is the sanctioned convention, not an oversight. So an unguarded entry point is reachable by direct URL whether or not its plugin is active.

### Measured on the live host, read-only GET (2 Sep 2026)

| Path | Plugin state | Result |
|---|---|---|
| `plugins/studio/public/router.php` | **inactive** | **200, 106 KB** — "Class catalog — Solaya", real catalog content |
| `plugins/timeclock/public/clock.php` | **inactive** | **200, 28 KB** — "Clock In / Out · Company B studio", kiosk with live employee list |
| `plugins/timeclock/public/landing.php` | **inactive** | **200, 23 KB** |
| `plugins/shop/storefront/index.php` | inactive | 503 — guard works |
| `plugins/shop/storefront/order.php` | inactive | 503 — guard works |
| `plugins/restaurant/storefront/index.php` | inactive | 404 — include-only |
| `plugins/react-site-bridge/public/preview.php` | inactive | 404 |
| `plugins/slate-mcp/public/router.php` | inactive | 404 |

**Deactivation removes hooks and nav, not the public surface.** Studio was deactivated and still serves its full public catalog to anyone with the URL.

**The `restaurant` descoping decision (1 Sep) needs revisiting on this basis.** The stated reasoning was that deactivation "shrinks what has to be secured". Restaurant itself happens to be safe — all eight of its storefront files are include-only and return 404 — but that is a property of how those files were written, not of deactivation. The same reasoning applied to studio or timeclock is false, as measured above. Deactivation is not a security control.

### Second pass, 2 Sep 2026 — what the surface actually DOES

Rendering and functioning are different questions, and the answer differs per plugin. Established by reading the code and by read-only GETs; **no POST was submitted to production**, because an auth attempt against a live site is not a measurement.

**`timeclock/public/clock.php` is the headline — it functions, and it writes.**

- **No authentication check of any kind** anywhere in the file.
- The POST branch writes through `TimeclockAPI::clockIn()`, inserting into `timeclock_active`.
- The **only** gate is `csrf_verify()`, and the page issues that token itself.
- **Two real employee names are in the public HTML**, confirmed by matching `timeclock_employees.name` for tenant 1 against the served page.
- Every precondition the handler checks is satisfied on production: employee ids 1 and 2 exist on tenant 1, one `timeclock_sites` row exists, `timeclock_active` currently holds 0 rows.
- Tenant scoping does hold — `TimeclockAPI::employee()` filters on `current_tenant_id()` — so this is same-tenant, not cross-tenant.

The plugin is **deactivated**. An unauthenticated stranger can write attendance rows for named employees on a plugin the operator believes is off.

**`studio/public/router.php` renders but cannot write, for an anonymous visitor.** `router.php` serves nine views; four are public (`catalog`, `class`, `prices`, `policies`) and five hit `Auth::requireCustomer()` at `router.php:54`. All three POST handlers — lines 61, 146 and 243 — sit **below** that gate and additionally require `csrf_verify()`. No POST handling in the public views, no writes at load.

But the gated half is reachable: **Sign in is live, not a dead link.** It targets core `/customer/login.php` (HTTP 200), which is unaffected by studio's activation state, and `requireCustomer()` redirects there with `?next=` so an authenticated customer lands back in the studio portal. `/customer/register.php` is open (200), so a stranger can self-register and reach the gated views of a switched-off plugin.

**Studio's personal-data exposure is latent, not realised.** The catalogue publishes styles, timetable and prices, plus instructor *contact ids* (1918/1919/1921) — which resolve to no rows in `contacts`, so no names render. `studio_gravatar_url()` (`router.php:30`) would emit `md5(instructor_email)` into public HTML; with no linked instructors it currently emits nothing. Real code path, no data behind it today. Link an instructor and it publishes.

### Ranked: what a stranger actually sees

Of the 59 entry points, 9 were **not** fetched because they call `ensureSchema()`/`CREATE TABLE` at load and fetching them would write. The other 50 were measured:

| # | entry point | plugin | bytes | |
|---|---|---|---|---|
| 1 | `studio/public/router.php` | **inactive** | 106,112 | full Solaya class catalog |
| 2 | `content-builder/public/render.php` | active | 86,926 | complete page, bypasses the router |
| 3 | `clientdesk/public/portal.php` | **inactive** | 84,742 | branded shell + sign-in; gates correctly, 0 client records |
| 4 | `timeclock/public/clock.php` | **inactive** | 28,032 | **2 real employee names; unauthenticated write path** |
| 5 | `timeclock/public/landing.php` | **inactive** | 23,038 | |

Everything else refused: `shop` 503 throughout, `restaurant` 404 (include-only), `stripe-payment` 503, and all 16 `views/` partials returned 0 bytes. **Five complete pages; four of them belong to deactivated plugins.**

### Access-log check before containment (2 Sep 2026)

Retained window 31 Jul → 2 Sep 2026, 15,384 requests, archived `~/logs/*.gz` plus the live domlog. Requests matching `plugins/{twelve inactive slugs}/(public|storefront)/`: **75 total, 73 of them our own probes** from `198.54.126.229` (this server, `curl/7.61.1`). The remaining 2 were a Mac Chrome opening `studio/public/router.php` at 08:28 on 2 Sep — reading this finding, minutes after it was reported.

**Zero third-party traffic to any of those paths.** A deny there breaks nothing that is being used.

### But two deactivations have ALREADY broken live integrations

This is separate from the containment rule and more urgent than it.

- **`slate-mcp`.** The queue said (below, now stale) that it was "still active, deliberately", and warned that disabling it "silently breaks any agent or integration currently connecting". The database says `inactive`, so the queue text is the stale half — and the warning came true. `/slate/slate-mcp/mcp` returned **200 to `grok-connectors-manager/0.1.0`** (xAI connector infrastructure, Google Cloud IPs) as recently as **29 Aug**, and to a `node` client on 21 Aug. It returns **404 today**. Three tokens exist in `slatemcp_tokens`, last used 21 Aug. (The 281 hits on `/slate/mcp.php` are bot probes against a file that does not exist — all 404, unrelated.)
- **`react-site-bridge`.** `/slate/kaimana/` and its sub-paths served a live public site — 72, 64 and 91 hits on 20–22 Aug, still 200 on 29 Aug. **404 today.**

Neither is affected by the containment rule: both are `public_routes` prefixes dispatched by `public.php` through a PHP `require`, which mod_rewrite never sees, and both are already 404 from deactivation alone. But if either is reactivated, its slug must come out of the deny-list.

### A false friend worth naming

Two idioms look identical and do opposite things:

```php
if (!defined('SLATE_ROOT')) { http_response_code(404); exit; }   // refuses direct access
if (!defined('SLATE_ROOT')) { require_once …'/config.php'; }     // ENABLES direct access
```

Ten files use the second, including `studio/public/router.php`, which is why it serves. Anyone auditing by eye reads both as "guarded".

### Caveats on the counts

- Many `views/` files are include-only and self-guard with the refusing form. Of three sampled, two did; the actionable set is therefore smaller than 47.
- Routers are normally reached through the `public_routes` filter, which only registers for active plugins. The exposure is not the routed path but the **direct URL fetch** that `.htaccess` permits.
- **Static classification proved unreliable and should not be trusted over measurement.** A first pass scanning only the first 30 lines mis-classified `shop/storefront/order.php` as unguarded — its guard is on line 31 — and predicted execution for three files that in fact refused. Every claim in the table above is a measured HTTP response, not a grep result.
- `booking-plus/public/message.php` counts as unguarded here because the live host has not been deployed; the fix is on `fix/booking-plus-message-isactive`. With it merged the repo figure is 13 of 59.
- An earlier figure of "6 of 42" was quoted in conversation before this entry existed. It was scoped to `plugins/*/public/` only and missed `plugins/*/storefront/` entirely — 17 files, including all eight of `restaurant` and all nine of `shop`. Use 59.

### Shape of the fix

Not 47 hand-written guards — that is the diverged-copy shape CORE-1 exists to end, and `booking-plus/message.php` vs `booking/pay-intent.php` was already one instance of it.

- [ ] one required prologue, `slate_public_entry('<slug>')`, that bootstraps config, checks activation, and refuses with 503 — called as the first statement of every public entry point
- [ ] anti-drift rule: any file under `plugins/*/public/` or `plugins/*/storefront/` that does not call it fails CI, with a baseline ratchet as CORE-1 uses
- [ ] the include-only refusing guard stays for view partials; the prologue is for entry points
- [ ] re-measure the table above after the prologue lands — measurement, not grep, is the acceptance test

---

# P0 — The mechanism

## CORE-1 — Stop remediation being applied to the instance instead of the class

**Labels:** `architecture` `P0` `tooling`

### Problem

This is cross-cutting pattern 6, and it is the root of most of the security findings:

| Thing | Correct copies | Wrong copies |
|---|---|---|
| `APP_SECRET` consumers | 4 | 3 |
| `manage_token` lookups | 4 | 1 |
| Site `public_id` lookups | 2 | 1 |
| `.htaccess` script deny | root `_*.php` | `plugins/*/tools/` |
| DB clock via `slate_db_now()` | most | booking reminders |

Every single time, **the correct version already exists in the codebase.** Patching the wrong copies fixes today's instances and leaves the mechanism that produced them fully intact — which is how the `.htaccess` fix produced SEC-1 months after it was "fixed".

### Proposed change

Make the correct version the only reachable one, and make a second copy fail CI:

1. One helper per shared concern: secret access, each identifier lookup, DB time, tenant qualification.
2. Every call site routed through it — no exceptions kept "for now".
3. A lint or test that fails when a raw `defined('APP_SECRET')`, a raw `time()` in a DB-compared expression, a bare `manage_token` query, a direct write to legacy `customers`, or an unqualified tenant query appears.

**Tenant qualification is the highest-value rule here, and it is time-sensitive.** Multi-tenant single-install is planned. Every query written between now and then under the "one tenant per deployment" assumption is a vulnerability with a delivery date. Two known misses is not the problem; the problem is that nothing stops the third. Land this rule before the codebase grows, or the multi-tenant migration becomes an audit of thousands of queries under deadline.

### Acceptance criteria

- [ ] a new PR that introduces a second copy of any of the five patterns above fails CI
- [ ] each helper has a test proving it fails closed
- [ ] the check runs on `develop` and blocks merge, not just as a report
- [ ] SEC-2 and SEC-3 are covered by it, so their fixes cannot silently regress

This issue is worth more than any individual fix in this queue.

### Delivered 2 Sep 2026 — `feat/core-1-anti-drift` (53 files, local, not pushed)

Five helpers built: `slate_tenant_id()`/`slate_tenant_clause()`, `slate_app_secret()`/`slate_sign()`, `slate_db_now()`/`slate_db_time()`, `BookingAPI::findByManageToken()`, `ReactSiteBridgeAPI::getSiteByPublicId()`. Guard is `bin/anti-drift.php` in its own CI job, parsing with `token_get_all()` rather than grepping. **Verified to exit 1 against a fixture tripping all five rules** — the test was proven able to fail before being trusted green, per the house rules. 329/329 unit tests pass.

SECRET and TOKEN call sites are at **zero**. 92 clock stamps converted across 36 files. Both domain lookups now tenant-scoped, closing SEC-3.

**The mechanism paid for itself during construction:** the guard found two previously unknown instances of the same clock bug — coupon expiry (`BookingAPI.php:951`) and membership `days_left` (`MembershipAPI.php:246`).

**And again after merge:** it caught two live cross-tenant bugs in `multilang-translate` the first time that plugin entered git — see `SEC-8`. Neither was found by any human reading the code, including this audit's own pass over the tree.

### Follow-ups this created

- [ ] **The TENANT baseline is a 70-entry debt register, and multi-tenant is on the roadmap — so it is the migration backlog.** Print the count in CI output and enforce that it may only decrease, or it becomes permanent.
- [ ] **Take two entries out of the baseline now**, not later: `admin/users.php:144` and `admin/roles.php:97` load tenant-scoped rows by bare id, in an admin context. Those read as genuine bugs rather than accepted debt.
- [x] ~~Take two entries out of the baseline now~~ — done, `4c64f00`: both scoped, TENANT backlog re-anchored 70 → 68.
- [x] ~~Print the count in CI output and enforce that it may only decrease~~ — done, `4c64f00`: `bin/anti-drift.php` reports the count every run, fails when it rises, and `--update-baseline` refuses to write a higher ceiling.
- [ ] **booking's `starts_at`/`ends_at` is the largest remaining clock instance** and was deliberately skipped — converting it reinterprets every historical row by the timezone offset, so it needs a migration. Track it or it will be forgotten; it is the biggest surviving example of the exact bug class this issue exists to kill. **Tracked in code**, `plugins/booking/BookingAPI.php:541` — a block comment at the write site names the six comparison sites, why a search-and-replace is worse than the bug (it reinterprets every historical row by the offset), what a real migration needs, and why the anti-drift CLOCK rule cannot catch it (it matches a clock *read*; this is a conversion).
- [ ] Shop CSRF and the forms PDF token now **fail closed** without `APP_SECRET`. Production has one (64 chars), so it is safe — but any environment without it will now refuse rather than degrade. Intended; confirm before standing up a new environment.
- [ ] **Merging this widens the gap between `develop` and production**, which already matches no ref. Phase 1 reconciliation in `claude/slate-cleanup-plan.md` gets more urgent with every branch merged, not less.
- [ ] Put `claude/slate-issue-queue.md` in the repo. Three sessions running, agents with repo access have had to work from a pasted description instead of the ticket.

---

# P0 — Privacy

## PRIV-1 — There is no deletion path for member medical data

**Labels:** `privacy` `gdpr` `P0`

### Problem

There is no core customer-erasure route and no `customer_deleted` hook anywhere. Allergies, pathologies, ongoing-care notes and body photographs stored in `membership_profiles` and `coaching_profile` **cannot be removed through the product at all**. Booking additionally snapshots name/email/phone onto every appointment row; shop, restaurant and clientdesk hold their own person rows.

A subject-access deletion request cannot currently be honoured without direct SQL.

### Proposed change

A core customer lifecycle contract: **update, export, anonymise/delete, retention**. Each plugin declares its person-bearing tables and implements the hook. Core provides the admin action and the audit trail.

### Acceptance criteria

- [ ] one admin action erases a person across every plugin, verified against a seeded database with rows in all of them
- [ ] booking appointment snapshots are anonymised, not orphaned
- [ ] deletion is logged to the audit log with who and when
- [ ] export produces everything held about a person, from the same registry
- [ ] a plugin that does not implement the hook is detected at boot, not discovered during a deletion

---

## PRIV-2 — Consolidate duplicated profile attributes

**Labels:** `refactor` `privacy` `P1`
**Blocked by:** PRIV-1

### Problem

Not identity — membership and coaching both key off core `customers`. What is duplicated is **profile attributes**: gender, date of birth, medical notes and allergies are collected twice, in different words, into `membership_profiles` and `coaching_profile`. Two copies that can disagree, and for medical fields that is an integrity problem as well as a privacy one.

### Proposed change

Core owns identity-and-medical: name, phone, gender, DOB, medical notes, allergies/intolerances, emergency contact. Plugins **declare** additional fields against it — coaching keeps height/weight/BMI/BMR/diet, membership keeps skill level. One client-facing "Your details" screen replaces three data-entry surfaces; membership and coaching link into it deep, and keep their own screens only for genuinely program-specific fields.

Follow studio's spine adoption as the reference implementation.

### Acceptance criteria

- [ ] gender, DOB, medical and allergy data live in exactly one table
- [ ] existing data migrates without loss; conflicts between the two copies are surfaced for a human, never silently resolved
- [ ] a client is asked each question once
- [ ] old URLs redirect rather than 404
- [ ] PRIV-1's erasure path covers the consolidated table
- [ ] `migrate` / `rollback` clean; FR + EN for every relabelled field

---

## PRIV-3 — Finish the identity cutover, incrementally

**Labels:** `architecture` `P2` `decision-made`

`db/migrations/0002_identity_core.php` created `contacts`, `contact_emails`, `contact_phones` and `identities`; `ContactSeeder` dual-writes; reads still go to legacy `customers`, and the seeder deliberately keeps `customers` valid as a rollback target. Studio is the only adopter.

**Decision: finish it — but as a rider on work already queued, not as its own project.** Reverting is the wrong call: studio depends on the spine, studio is the target shape for every consolidation issue here, and multi-tenant single-install needs a real identity layer far more than the current deployment does. Big-bang finishing it now is also wrong — it would sit in front of the security backlog for weeks.

The state to escape is the current one. Dual-written with one adopter is worse than either end, and it gets worse on its own: every new plugin written against `customers` is another thing to migrate later.

### `multilang-translate` — resolved 1 Sep 2026

Committed and pushed as **`feat/multilang-translate`** (v1.3.0, 20 files, byte-identical to production, all files pass `php -l`, secret scan clean). Confirmed exhaustively: the path had **never** been tracked on any branch. The loss risk is closed — it now exists on GitHub, not only on one laptop and one server.

**But it is a moving target, and that is the real finding.** Five files were modified on the live server the same afternoon, 16:37–16:39 — `admin/index.php`, `admin/api.php`, `assets/js/admin.js`, `assets/css/admin.css`, `includes/Crawler.php`. `plugin.json` attributes authorship to Rasel Ahmmed. **Someone is editing this plugin directly on production.**

- [ ] establish who is editing live, and move them onto the branch before anyone merges — the first merge otherwise silently reverts their work
- [ ] do not merge to `develop` until that conversation has happened; the branch alone already removes the loss risk, so merging can wait
- [ ] re-snapshot from live at a stable point if the work has moved on since

**Still live-only:** the six Phase C/D content-builder files (`content-builder/public/api.php`, `lib/MediaKeyResolver.php`, `lib/PrecomposedBody.php`, `lib/SiteTemplate.php`, `lib/blocks/react.php`, `public/assets/slate-content.js`). The deploy deletion risk is reduced, not gone.

### Sequence

1. **This week, zero code:** record in the repo that `contacts` is the destination and `customers` is legacy. New code writes through the spine. This stops the gap widening at no cost.
2. **Membership and coaching** adopt the spine inside the PRIV-2 PR — those plugins are being opened for profile consolidation anyway.
3. **Booking** next; it is the largest person surface and holds per-appointment snapshots that PRIV-1 has to reach regardless.
4. **Shop, restaurant, clientdesk** last — none are in Solaya's live set.

### Acceptance criteria

- [ ] the destination table is documented in the repo, in one sentence, where a developer will hit it
- [ ] no new code path writes to `customers` directly (enforceable by CORE-1's lint)
- [ ] each adoption ships inside a PR that was already touching that plugin
- [ ] exit condition met: reads move off `customers`, `ContactSeeder`'s rollback target is dropped, and the dual-write ends — the cutover is not "done" while both tables are authorities

---

# P1 — Correctness

## FIX-1 — Booking reminders compare a PHP clock to the MySQL clock

**Labels:** `bug` `P1`

`slate_db_now()` exists precisely to prevent this, and the codebase records this same mistake having already broken the login throttle and membership expiry. This is the third occurrence.

- [ ] the comparison uses the DB clock on both sides
- [ ] every remaining `time()`/`date()` compared against a DB column is found and converted
- [ ] covered by CORE-1's lint so there is no fourth occurrence
- [ ] a test with deliberately skewed clocks proves reminders fire on the right boundary

---

## FIX-2 — Membership dashboard is wrong in three coupled ways

**Labels:** `bug` `P1`

1. **Attendance counts future bookings.** `MembershipAPI.php:893-902` loads recent attendance with no `starts_at <= NOW()` predicate; `home.php:86-99` sets `$present` whenever status is `confirmed` or `completed`. A 15:00 session shows "✓ Present" at 11:20am.
2. **"Enrolled" shows for every service.** `MembershipAPI.php:938-963` returns every active service and only marks one locked when insurance is missing, so `home.php:246-257` renders "Enrolled" against services with zero sessions.
3. **`session_quota` is sold but never enforced.** The plan advertises a quota; nothing checks it at booking time.

Fix together — they share a root, which is that portal state is inferred from availability and status labels rather than from domain facts.

- [ ] attendance includes only past appointments, and "Present" comes from a completed/attended state or an explicit attendance record
- [ ] "Enrolled" comes from a real entitlement — active subscription, explicit assignment, or qualifying booking history
- [ ] quota is enforced at booking time and the remaining count is derived from the same source the UI displays
- [ ] quota UI appears only for quota plans, never for time-based ones

---

## FIX-3 — Membership router maps every unknown view to Home

**Labels:** `bug` `P2`

`plugins/membership/public/router.php:243-246` converts any unrecognised `?view=` to `home`. Core `PublicRouter` 404s correctly, so this is membership's own behaviour. `/slate/member/profile` renders Home rather than routing or erroring.

- [ ] `home` remains the default only when no view is supplied
- [ ] an unknown view returns the shared 404
- [ ] path-style URLs either route or 404; they do not silently render something else

---

## FIX-4 — Small bug batch

**Labels:** `bug` `P2`

Each is independent; group or split as convenient.

1. **Currency handled as a display string** — Season pass in USD beside bookings in EUR. Store value + currency code; render from one formatter.
2. **Dead-end account screen** — "Email changes aren't supported yet" and "sign out and use the reset link" to change a password. Both should be inline flows.
3. **Emotion logging trapped inside meal logging** — all ten mood chips read "Log a meal feeling X". Allow standalone emotion entries.
4. **Coaching Home repeats itself** — hydration appears 3× on one screen, meals 3×, goals 2×.
5. **"Everything else"** is a catch-all of six features whose "My goals" duplicates the section nav's "Goals".
6. **Media library has no dedupe** — several files exist twice, one Used one Unused; 20 of 35 unused. Hash-based dedupe on upload, plus a "find duplicates" action.
7. **404 page renders "COMPANY B STUDIO"** — the error template does not read tenant branding.
8. **Two `Primary navigation` landmarks** share an accessible name in the admin (sidebar, bottom bar, and the "More" dialog duplicating the sidebar in DOM).
9. **Booking+ offers a Zoom `api` mode that is not wired** — `admin/service.php:233-240` renders it selectable. Hide it, or make it non-selectable with a visible runtime fallback warning.
10. **Two flat-rate shipping plugins** register the same `shop_shipping_rate` filter; whichever loads first wins. Make them mutually exclusive at activation. *(Not in Solaya's live set — repo-wide only.)*

---

## FIX-5 — Booking's real schema exists only in PHP, with no rollback

**Labels:** `bug` `infrastructure` `P1`

Booking's schema is spread across three places, and the one the running code actually writes to is not expressible as SQL:

| Source | Produces |
|---|---|
| `BookingAPI::ensureSchema()` | the original v0.1 tables |
| `plugins/booking/install.sql` | 30 columns on `booking_services`; `booking_appointments` still incomplete |
| `Booking.php:428-519` — `ensureColumn()` calls | `party_size`, `discount_cents`, `gift_applied_cents`, `stripe_session_id` and ~12 more |

Those last columns exist in **no `.sql` file anywhere** and are **not in `db/migrations`**, so `bin/migrate` does not produce them and there is **no rollback for any of it**. They are applied only as a side effect of `Booking::boot()` calling the private `runMigrations()`, which is why production works and a SQL-provisioned database does not: any caller of `BookingAPI::createAppointment()` fails on `Unknown column 'discount_cents'`.

`tests/fixtures/booking-schema.php` unblocks CI by invoking `runMigrations()` through reflection. **That is the right stopgap and the wrong permanent answer** — it makes the test harness depend on two private methods of one plugin (`runMigrations()` and `schemaIsCurrent()`), so a refactor inside booking breaks CI from outside booking, and it leaves the rollback gap untouched.

`studio` is the standard here: it is the only plugin with working migration rollbacks.

- [ ] every `ensureColumn()`/`ensureIndex()` in `Booking.php` expressed as a versioned migration with a down step
- [ ] `bin/migrate migrate` alone produces a schema `createAppointment()` can write to
- [ ] migrations run clean **both ways** against a disposable copy of the live DB
- [ ] `tests/fixtures/booking-schema.php` deleted and its CI step replaced by `bin/migrate`
- [ ] audit the other plugins for the same pattern before assuming booking is unique

---

# P1 — Consolidation

## CON-1 — Merge Booking+ into Booking

**Labels:** `epic` `refactor` `P1`

Booking+ adds four top-level nav rows duplicating Booking's own, and its Services screen ships an "Edit core services" link — the UI admitting you must bounce between two menu sections to configure one object.

Booking+ becomes a **capability provider** to Booking: prep pages, WhatsApp links, min-advance-days, prerequisite gate, reserved slots, auto-response, internal nudge, Zoom handling and the human-message step all register into Booking's own screens. Keep it a separate plugin on disk if packaging needs that — the merge is at the UI and settings layer.

- [ ] the `BOOKING` nav group loses its four `Booking+ *` rows
- [ ] deactivating booking-plus degrades gracefully; Booking hides the extra fields and nothing breaks
- [ ] no behaviour change for existing bookings; golden-gated, test proven fallible
- [ ] `booking_can_book`'s third consumer accounted for — see the scope correction below

### Scope correction — the nav seam already exists; do NOT build it (2 Sep 2026)

The first slice previously proposed for CON-1 was "a core `section` key on `admin_nav_items`", so Booking+ could place rows inside Booking's group without Booking knowing Booking+ exists. **That mechanism is already there, and Booking+ already uses it.** Checked before building it:

- `admin/partials/header.php` documents a `group` key — "items with the same group render under an uppercase section label" — and groups them at the bottom of the file, preserving first-seen order.
- Every Booking row passes `'group' => 'booking'`.
- Every Booking+ row passes `'group' => 'booking'` too, above the comment *"Flat items grouped under 'booking' so the section matches core Booking."*

So the two plugins already render as one BOOKING section. Building a `section` key would have duplicated `group` under a new name. The slice is redundant — skip it.

### What the nav problem actually is

Nineteen rows in one section, and two pairs pointing at duplicate-purpose screens:

| order | plugin | label | target |
|---|---|---|---|
| 600–611 | booking | Booking, Calendar, Appointments, Customers, **Services**, Providers, Categories, Add-ons, Custom fields, Locations, Resources, Coupons, Gift cards, **Booking settings** | `booking/admin/*` |
| 690 | booking-plus | Booking+ Messages | `booking-plus/admin/index.php` |
| 691 | booking-plus | Booking+ Reserved slots | `booking-plus/admin/restrictions.php` |
| 691 | booking-plus | **Booking+ Services** | `booking-plus/admin/services.php` |
| 692 | booking-plus | **Booking+ Settings** | `booking-plus/admin/settings.php` |

`Messages` and `Reserved slots` are genuine Booking+ features with no counterpart — they are not duplication and should survive the merge as capabilities registered into Booking's UI.

`Services` and `Settings` are the duplication. `booking-plus/admin/services.php:45` renders a link reading **"Edit core services"** pointing at `booking/admin/services.php` — the UI stating outright that configuring one object needs two screens.

### Corrected first slice

Fold **Booking+ Settings into Booking settings**, not Services. Both are duplicate-purpose, but Services is already scoped as CON-2 and carries the editor merge; Settings is the smaller, more reversible of the two and proves the pattern — Booking+ contributing fields into a Booking screen rather than owning a parallel one.

- [ ] Booking+ registers its settings fields into `booking/admin/settings.php` through a filter Booking owns
- [ ] the `bookingplus-settings` nav row disappears; no Booking+ row survives that points at a Booking-owned concept
- [ ] deactivating booking-plus leaves Booking settings intact and its fields simply absent
- [ ] golden-gated, test proven able to fail first

---

### Scope correction — `booking_can_book` has a consumer outside the merge (2 Sep 2026)

The survey's §3 lists Booking+ as the filter's consumer. It is not the only one:

| Registrar | Priority | Source |
|---|---|---|
| membership | 10 | `plugins/membership/Membership.php:52` |
| booking-plus | 20 | `plugins/booking-plus/BookingPlus.php:34` |
| *(applier)* | — | `plugins/booking/BookingAPI.php:500` |

membership runs **first** and refuses any booking without a signed-in member (`Membership.php:162`), with both of its gate settings defaulting to ON — `Membership.php:155-156` reads `!== '0'`, so an **absent** row means required, not optional.

So CON-1 moving or deleting that `applyFilters` silently disables membership's booking rules alongside two of Booking+'s nine capabilities, and it does so with no error and no log line. Found by `tests/integration/BookingCanBookGateTest.php`, which now pins the filter.

- [ ] survey §3 amended to list both consumers — **follow-up PR against `docs/con-1-survey`**, not folded into a code PR
- [ ] any change to `BookingAPI.php:500` re-checked against membership's gate, not only Booking+'s

---

## CON-2 — One service editor

**Labels:** `ux` `P1` · **Blocked by:** CON-1

- [ ] one Services list, one edit form, with a "Practitioner extras" fieldset contributed by booking-plus
- [ ] the "Edit core services" link is gone because it is meaningless
- [ ] per-service extras still saveable per service

---

## CON-3 — One notification schedule

**Labels:** `architecture` `P1` · **Blocked by:** CON-1

Booking and Booking+ both write `booking.reminder_leads` from two screens — deterministic at runtime, but with two admin owners. Membership's expiry reminders are a separate concept in different units.

A core scheduling service: plugins register **message types** (booking reminder, follow-up, membership expiry, coaching nudge) with lead times, channel and on/off. One screen, one unit.

- [ ] exactly one editable lead-time field per message type
- [ ] existing cadences migrate with no change in delivery behaviour, including the 11520,1440,10 seed
- [ ] the cron that fires these is verified running on the server — a known go-live gap
- [ ] built on FIX-1's DB clock, not a PHP clock

---

## CON-4 — One "Booking rules" screen

**Labels:** `ux` `P1` · **Blocked by:** CON-1

Gates live in Booking (widget options), Booking+ (`min_advance_days`, prerequisite service) and Membership (active membership, completed profile, insurance-required courses). Three screens for one question.

- [ ] every registered gate rendered in one place, whichever plugin owns it
- [ ] a "test a booking" tool: pick a person and a service, see which gate blocks them and why
- [ ] adding a gate later requires no change to this screen

---

# P2 — Interface

## UX-1 — Navigation restructure

**Labels:** `ux` `ia` `P2` · **Blocked by:** CON-1

**PLUGINS** contains Content items; **CONTENT** contains Coaching; **SETTINGS** has one item; the real plugin manager is under **SYSTEM → Plugins**, so two things are called "Plugins"; **BOOKING** is 15 rows; **RESTAURANT** shows "Restaurant" and "Orders" pointing at the same URL.

Top level becomes nouns — `Content · Media · People · Bookings · Membership · Program · Commerce · Appearance · Settings · System` — and plugins register **submenus under them**.

- [ ] no two top-level items share a name; no top-level group has one item
- [ ] adding a plugin cannot create a new top-level group
- [ ] the duplicate Restaurant/Orders row is gone; the two Help pages become one
- [ ] header search reaches every screen by name
- [ ] breadcrumb shows the real path

---

## UX-2 — One People screen

**Labels:** `ux` `P2` · **Blocked by:** PRIV-2

Six admin screens list people. Even where they share a table underneath, an admin cannot answer "what is this client's history" from one place.

- [ ] one People list — search, filters, tags — and a detail view with tabs: Overview · Bookings · Membership · Program · Submissions · Orders
- [ ] tabs render only for active plugins that hold data
- [ ] plugins contribute tabs through a registration API
- [ ] where plugins hold their own person rows (booking, shop, restaurant, clientdesk), the detail view links them to the core record

---

## UX-3 — One i18n mechanism, one theme system, one login

**Labels:** `ux` `P2`

Three independent instances of the same "one correct version, one stale copy" pattern at the interface layer.

1. **i18n** — membership uses `?lang=`, the rest of the portal uses multilang-translate's `?mlt_lang=`. One switcher, one param, one code path. Then bring the **admin** to FR/EN parity: the customer portal is bilingual and the admin is not, which contradicts the project's bilingual-by-default rule and affects the person who actually reads the admin.
2. **Themes** — Site Settings carries both "Site theme" (5 options) and "Theme preset" (6), with the page itself saying they will merge later. Merge them, and collapse the two "Site name" fields to one. Existing sites must render identically; golden-gated, test proven fallible.
3. **Login** — the admin screen lacks show-password, forgot-password, register and the EN/FR switcher that the customer screen has. One role-aware component; do not change session or CSRF handling in the same PR.

---

# P2 — Housekeeping

## OPS-1 — Decide what this install is

**Labels:** `chore` `P2`

`/book` publicly shows a "HAIRCUT" category containing "Boxe 100% Féminin" and "waterxfight" in USD, beneath the real EUR hypnosis services. Pages, media and the public site belong to other demo businesses.

- [ ] decided: Solaya's production install, or a demo sandbox
- [ ] if production — non-Solaya services, pages and media purged before go-live
- [ ] if sandbox — Solaya gets its own install and this one is not linked from anywhere public

---

## OPS-2 — Two provisioners for one test database

**Labels:** `bug` `infrastructure` `P2`

`tests/bin/provision-test-db.sh` and the inline steps in `.github/workflows/ci.yml` both provision the integration database, independently. The script's header claims it "mirrors what CI seeds"; it does not. It activates **content-builder** only, where CI activates **content-builder, forms, booking and membership**, installs their schemas, runs booking's column top-ups and loads the render fixtures. It also rewrites only `DB_NAME` in `.env`, leaving `APP_URL` as the checkout's real URL against CI's `http://localhost`.

The result is a local suite that disagrees with CI in both directions, which costs a CI round-trip every time it is rediscovered:

- an inactive plugin registers no hooks, so its behaviour is invisible locally — `BookingCanBookGateTest` passed locally and failed in CI on membership's `booking_can_book` listener
- the two document goldens fail locally and pass in CI, differing by one absolute URL, because `_slate_envelope_strip_base()` normalises only the URL path

The full comparison is in `tests/README.md` → "Why local and CI disagree".

- [ ] one provisioner — `ci.yml` calls `provision-test-db.sh`, or both call a shared script; neither seeds independently
- [ ] the plugin set is declared in one place both read
- [ ] `_slate_envelope_strip_base()` normalises scheme and host, not only path, so goldens are base-neutral for real
- [ ] a local full-suite run and a CI run on the same commit produce the same pass count

---

## MEM-1 — Membership's booking gate is enabled by an absent row, not a decision

**Labels:** `bug` `config` `P1`

**Owner's decision (2 Sep 2026): `!== '0'` at `Membership.php:155-156` is an implementation flaw, not the intended config model.** The target is explicit configuration with explicit defaults — fail-closed when the gate is *deliberately* enabled, never because a row is missing.

Today, `Database::setting('membership.require_membership_to_book') !== '0'` treats an **absent** row as ON. So on a fresh install with membership active, every online booking is refused with "Please sign in as a member to book." (`Membership.php:162`) and nothing anywhere says this is a default rather than a choice.

### Production check — read-only, run before scoping (2 Sep 2026)

Expected the rows to be absent, which would have meant the gate was live-by-default on a site holding medical data. **That expectation was wrong.** `rakilluy_booking` holds:

| tenant | key | value |
|---|---|---|
| 1 | `membership.require_membership_to_book` | `1` |
| 1 | `membership.require_profile_to_book` | `1` |

Both explicit, both ON, membership/booking/booking-plus all active. The Membership settings screen writes every key on save, and someone has saved it.

**So the comparison change is a behavioural no-op on production** — the rows already say what the code infers. The exposure is narrower than feared but real, and it is every install that was activated and never configured: a fresh tenant, a new deployment, CI. There the gate is on with no record of anyone turning it on, and flipping the comparison without a backfill would silently turn it **off**.

The migration must still write the current effective value explicitly first, so behaviour is identical the moment the change lands and only the visibility improves. On production that backfill will find rows already present and do nothing, which is the correct outcome, not a reason to skip it.

### Scope

- [ ] migration backfills explicit rows for installs already activated, plus activation defaults for new ones — both idempotent, neither overwriting an admin's existing choice
- [ ] client-facing refusal stays neutral; the diagnostic goes to the admin, preferably as an activation notice ("Membership is active and the booking gate is ON — online bookings will be refused for non-members") rather than an error someone hits later
- [ ] migration verified **both ways** against a disposable copy of the live DB

### The class, not the instance

16 settings reads compare against `'0'`. They split in two, and only the first group is the defect:

**No explicit default — an absent row silently means enabled (3):**

| Site | Setting |
|---|---|
| `plugins/membership/Membership.php:155` | `membership.require_membership_to_book` |
| `plugins/membership/Membership.php:156` | `membership.require_profile_to_book` |
| `plugins/multilang-translate/admin/index.php:275` | `multilang-translate.switcher_enabled` |

**Explicit default already passed (13)** — `(Database::setting($k) ?? '1') !== '0'` or `$this->setting($k, '1') !== '0'`, across `stripe-payment` and `booking`. Correct behaviour, restated thirteen times.

- [ ] one shared accessor taking an explicit default — `slate_setting_bool($key, bool $default)` — and all 16 routed through it
- [ ] anti-drift rule: a boolean settings read with no explicit default fails CI, the way the other CORE-1 rules do

### This is the settings face of a seam that keeps not existing

Three symptoms, one missing mechanism: **modules declare, core applies.**

| Symptom | Where |
|---|---|
| a module needs a nav row placed in another module's group | the `section` key on `admin_nav_items` (CON-1 first slice) |
| a module needs an event registered without core knowing it exists | the sync capability's event registration |
| a module needs a setting to have a default that survives absence | this entry |

Each has been solved locally, differently, three times. Worth building once, and CORE-1 is where it belongs.

---

## CON-5 — One content system: fold react-site-bridge into content-builder

**Labels:** `epic` `refactor` `P2`

**Not urgent, and no work yet.** Logged so the shape is agreed before anyone starts.

Two plugins serve public pages. `content-builder` is the page store and renderer; `react-site-bridge` served its own public site at `/slate/kaimana/` through a separate route tree, with its own admin screens (`visual.php`, `site.php`, `hosting.php`). That is the same duplication CON-1 is unpicking between Booking and Booking+ — two systems for one job, each with its own UI, diverging quietly.

`react-site-bridge` folds into `content-builder` as one content system, organised properly. Keep it a separate plugin on disk if packaging needs it; the merge is at the content-model and UI layer, as with CON-1.

**Both `react-site-bridge` and `slate-mcp` stay deactivated. That is a decision, not an open question.** `/slate/kaimana/` and `/slate/slate-mcp/mcp` returned 200 as recently as 29 Aug and 404 now; that is intended. Their `public/` directories are additionally denied at the origin by SEC-9's containment rule — and per that rule, **removing the slug is part of any future reactivation**.

### Deactivating slate-mcp did not delete its tokens

Three rows remain in `slatemcp_tokens`:

| id | label | prefix | last used | expires | revoked |
|---|---|---|---|---|---|
| 1 | Slate AI | `slmcp_QXe17V-bFt` | 2026-08-21 07:46 | never | 2026-09-01 22:32 |
| 2 | Slate AI | `slmcp_uXBQ6Yuge6` | never | 2026-08-29 | 2026-09-01 22:32 |
| 3 | Slate AI rk | `slmcp_YtTEGM9nIa` | never | never | 2026-09-01 22:32 |

**Correction to the premise this was logged under:** all three *are* revoked — `revoked_at` was set on 1 Sep 22:32, and `SlateMcpAPI::authenticate()` (`SlateMcpAPI.php:118`) filters `revoked_at IS NULL AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())`. So they would **not** start working again if the plugin were reactivated. Token 2 had also already expired on 29 Aug.

The residual point still stands, in a narrower form: revoked is not deleted. The rows persist — label, prefix, SHA-256 hash, scopes, creator — and nothing removes them. If they should be *gone* rather than *inert*, they have to be deleted explicitly.

- [ ] decide: revoked-and-retained (audit trail) or deleted
- [ ] if deleted, a deletion path exists — this is the same absence PRIV-1 records for member medical data
- [ ] the fold itself: content model, admin UI, route ownership — scoped before any code

---

## OPS-3 — Nothing reconciles the live `.htaccess` against the repo

**Labels:** `chore` `infrastructure` `P2`

Three changes have been hand-applied to the origin `.htaccess` and then committed separately: the SEC-1 `tools|bin|scripts` deny, the `.claude/` deny, and the SEC-9 containment rule. Each time the only thing confirming the live file and the repo agreed was a person running `diff`.

They agree right now — verified 2 Sep 2026, live `.htaccess` byte-identical to `origin/develop` once #62 and #63 merged. That is the good case, and it is unenforced.

**The failure mode is silent in both directions.** `.htaccess` is **not** in the deploy's rsync exclude list (`.github/workflows/deploy.yml:141-149`), so:

- a hand-applied live change that is never committed is **silently reverted by the next deploy** — including a security rule, with nothing in the deploy output naming it
- a hand-applied change made *after* a deploy drifts unnoticed until someone thinks to look

SEC-9's containment rule makes this sharper than it was: it is a deny-list keyed to which plugins are deactivated, so it is exactly the kind of rule someone edits on the host under time pressure.

- [ ] a check that fails when the live `.htaccess` differs from the deployed commit — CI against a fetched copy, or a deploy step that diffs before overwriting and refuses on unexpected drift
- [ ] the same question asked of the other hand-applied host state: per-worktree `.claude/.htaccess` files, and anything else that is deliberately rsync-excluded
- [ ] decide whether `.htaccess` should be deploy-managed at all, or excluded and owned on the host — currently it is deploy-managed by default rather than by decision

---

## Coverage gaps still open

Not issues, but the next audit's scope: `admin/` and most of `src/` have had no dedicated pass — and `src/` is where `slate_db_now()`, the settings API and the identity spine live, which is exactly the shared code everything else drifts away from. Worth more than another plugin sweep.

---

## What changed from v1

| v1 | v2 |
|---|---|
| #1 "build a core person record" | **Dropped.** Identity is already shared; a half-built spine exists. Replaced by PRIV-2 (profile attributes) and PRIV-3 (finish or park the cutover). |
| #2 "core profile" — framed as UX + GDPR risk | **Sharpened.** PRIV-1: there is no deletion path *at all*. That is the actual compliance exposure. |
| #7 "three reminder fields, unclear which wins" | **Corrected.** Two write the same key; runtime is deterministic. CON-3 keeps the consolidation, drops the alarm. |
| — | **New:** SEC-1…SEC-4, CORE-1, FIX-1. None were visible from the browser. |
| — | **New:** the target shape is studio, not an imported pattern. |
