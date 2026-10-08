# B2-P6 — Preview + Publish + SEO / Accessibility + Production Hardening

> Planning specification only. See `00-BUILDER-2-MASTER-REVIEW.md` §20, §22–23, and decisions **D5, D9, D-undo**. Paths: `PLUGIN` = `01-client/plugins/studio-builder`, `UI` = `PLUGIN/ui/src`, `SB` = `01-client/src/Module/StudioBuilder`.

## Objective
Ship Builder 2.0 safely: a **checks-gated publish pipeline** with scheduling and unpublish, polished Preview, version history UX, **redirect enforcement**, complete **SEO** (hreflang, JSON-LD, social fields), **accessibility** both in output and in authoring checks, and the performance, concurrency, tenant-isolation and regression hardening that makes the program production-ready.

## User-visible outcome
- **Preview** (editor and visitor) with device frames, *Draft Preview* chip, auto-save-before-open, open-in-new-tab.
- **Publish ▾**: *Publish now*, *Schedule…*, *Unpublish*; before publishing the author sees a **Checks** report (errors block; warnings informative) with click-to-fix navigation to the offending node; status messages are truthful at every stage (*Saving → Validating → Compiling → Checking → Published*).
- **Version history:** list, compare (visual + structural), restore (creates a draft), with clear AI/import/rollback labelling.
- **SEO:** title/description/canonical/robots/social title+description+image, hreflang for localized pages, structured data (JSON-LD) templates; redirects created automatically on slug change and manageable in one place.
- **Accessibility:** authoring-time checks (heading order, alt text, labels, contrast, empty links/buttons, landmark structure), skip link and landmark output, keyboard/focus/contrast correctness in the Builder UI itself.
- A faster, more stable editor (measured budgets), clearer conflict recovery.

## Features
1. **Checks service (read-only):** `StudioPublishChecks` returns `{errors[], warnings[]}` with node ids: SEO completeness, heading hierarchy, missing/empty alt, unlabeled form controls, empty links/buttons, broken internal links/anchors, unresolved bindings/missing entitlements, empty sections, contrast warnings against tokens, duplicate ids, oversized content.
2. **Publish pipeline:** *Save draft → Validate → Compile → Checks → Publish* as an explicit staged flow with `expected_revision_id` **required** (close the null-skip gap); atomic publish+compile unchanged.
3. **Scheduling & unpublish:** publisher job (cron/queue per platform convention) that publishes a specific revision at `scheduled_for`; `unpublishPage()` implemented; UI for schedule/cancel/unpublish; audit entries; timezone handling.
4. **History/compare/restore UX:** polish existing `HistoryDialog`/`diff`; visual diff via preview of two revisions; retention/pruning policy (**decision**; immutability preserved by archiving, not editing).
5. **Redirects:** enforce `studio_redirects` at the public router (tenant-scoped, loop-safe, status 301/302), auto-create on slug/route change, a manager UI; move `redirects.php` onto the application-service pipeline.
6. **SEO completion:** hreflang (localized pages + `x-default`), JSON-LD (Organization/WebSite/Article/LocalBusiness/Service/FAQ templates generated from page data, not authored free-form), social title/description separate from SEO, sitemap index with per-locale entries and cached output, canonical edge cases (unset `APP_URL`).
7. **Accessibility — output:** skip link, landmark roles, `lang`, focus-visible, reduced motion, form labelling defaults, link/button semantics; runtime (`studio-runtime.js`) focus management audit.
8. **Accessibility — authoring UI:** keyboard-reachable overlay and menus, dialog focus traps everywhere (incl. sheets), translated labels, 44 px touch targets, contrast of builder chrome, axe checks in CI.
9. **Comments & reviews (decision D9):** only if prioritised — page/section-anchored comments with an approvals state, new tenant table via the Studio schema mechanism; otherwise explicitly deferred.
10. **Performance:** meet budgets (§23 of master) — split contexts (done P1), canvas patch coverage, virtualised Layers for large documents, lazy panels, SQL-bounded providers, compile caching, bundle budget, image/media handling in builder.
11. **Concurrency & conflict UX:** conflict banner that offers *reload*, *view diff*, *copy my changes*; advisory lock UX clarified; no auto-merge (locked decision) unless approved; undo latency review (**D-undo**).
12. **Production hardening:** adversarial tenant suite in CI, load tests (250 blocks/50 sections, large menus, large collections), public rate limiting, CSP strategy (currently blocked by inline style and the multilang script — decide), error-handling pass (safe messages, request ids), observability (`StudioLog`/`StudioRequestId`), bundle-drift CI check, stale-doc cleanup (OPEN-QUESTIONS, ROADMAP header, missing Phase 9A–C docs), release notes and upgrade/recompile runbook.

## Screens affected
D01 (Publish ▾), D12 (Preview/Publish — **no image supplied**), M10 (Publish sheet **scope-limited**: no hosting/domain/export/visibility, decision D5), M12 (Preview), M13 (History, SEO), all screens (a11y/performance).

## Frontend work
- `UI/components/publish/*` (PublishMenu, ChecksPanel, SchedulePicker, StatusStepper), `UI/components/preview/*` (device frames, chips), `UI/components/history/*`, `UI/components/seo/*` (extends `PageInspector`), `UI/components/redirects/*`.
- Checks click-to-fix: select node + open the relevant Inspector section.
- Accessibility remediations across shell/overlay/sheets; axe integration.
- Performance work (virtualised Layers, selector splits already in P1), bundle analysis.

## Backend/domain integration
- `StudioApplicationService`: `runChecks`, `schedulePublish`, `cancelSchedule`, `unpublishPage`, redirect commands, SEO fields — new `StudioAuthoringApi` actions on the allow-list; all via `authorize()`; audited; publish still the **only** publish path (AI/MCP remain unable to publish or schedule).
- `StudioRevisionService`: require `expected_revision_id` for publish; scheduling pointer; unpublish semantics.
- `SeoHead`: hreflang/JSON-LD/social fields; `StudioSitemapService`: index + per-locale + caching.
- Public router: redirect enforcement.
- Publisher job: new CLI/cron entry using the application service (no direct repository access).

## Data/model impact
- `scheduled_for` and `scheduled` status already exist (schema) — no new columns for scheduling itself; a job state/last-run record if needed (approval-gated, additive).
- **Redirects:** currently a tenant-setting JSON blob — consider a table for scale (approval-gated; otherwise keep JSON with caps).
- **SEO:** document `seo` keys extend (social title/description) — additive optional keys; hreflang derives from page locale relations (existing locale model) — **[UNKNOWN]** depth of Phase 9A–C locale work (docs missing; verify).
- **Comments (if approved):** new table, `tenant_id NOT NULL`.
- Compile: output changes (skip link, landmarks, JSON-LD, hreflang) ⇒ version bumps; recompile runbook.

## Security considerations
- Scheduling/unpublish are state-changing: `studio-builder.publish`, CSRF, audited, tenant-scoped; publisher job re-checks entitlement and tenant at run time (a lapsed licence must not publish).
- Redirects: allow-list of schemes/hosts (same-site only unless explicitly approved), loop and chain limits, no open-redirect via user input, tenant-scoped.
- JSON-LD generated server-side with escaping; **no author-supplied raw JSON-LD** in V1; hreflang URLs constrained to the tenant's own domains.
- Checks are read-only and must not leak other tenants' data; error output uses safe messages.
- CSP: any move to a nonce-based policy must not break the runtime; keep canvas/preview CSP as is.
- Rate limiting on public endpoints; preview endpoints remain private/no-store/noindex.
- Comments (if built): sanitised text, permission-gated, tenant-scoped.

## Tests
- PHP: checks (one test per rule, positive/negative, multi-locale), publish stage/status truthfulness, `expected_revision_id` required, scheduling (publish at time, cancel, entitlement lapse, timezone/DST, double-fire protection), unpublish, redirects (enforce, loops, tenant isolation, auto-create), SEO output (hreflang, JSON-LD validity, canonical edge cases), sitemap index, **adversarial tenant suite**, authorization matrix for every new action, MCP cannot publish/schedule/unpublish.
- JS/DOM: Publish flow states, checks click-to-fix, schedule picker, history compare; **axe** on all main screens; keyboard-only flows; mobile.
- Non-functional: load tests, bundle-size gate, typing-latency probe, memory soak on a 250-block document.
- Regression: full suites on MySQL 8.0 and MariaDB 10.11; visual regression across breakpoints and modes.

## Dependencies
All of B2-P1…P5. Decisions **D5, D9, D-undo**, scheduling infrastructure (cron/queue convention), screen **D12** (supplied or confirmed from Notion). Locale model verification for hreflang.

## Files/modules likely to change
`SB/Application/StudioApplicationService.php`, `SB/Service/StudioRevisionService.php`, new `SB/Service/StudioPublishChecks.php`, `SB/Render/Seo/SeoHead.php`, `SB/Runtime/StudioSitemapService.php`, public router/redirect hook, `SB/Http/StudioAuthoringApi.php`, new `bin/studio-publisher` (or platform job registration), `PLUGIN/admin/{redirects,reviews}.php`, `SB/Render/PageDocumentAssembler.php` (skip link/landmarks), `StudioStylesheet.php`/`StudioCompiler.php` (version bumps), `UI/components/{publish,preview,history,seo,redirects}/**`, CI workflow (bundle drift, axe, adversarial suite), docs (`architecture/*` refresh), tests, `CHANGELOG.md`, bundle.

## Files/modules that must NOT change
`authorize()` order, `StudioRepository` tenant forcing, revision immutability, atomic publish+compile semantics (extended, not weakened), **no AI publish/import**, `IssuerAuthority`, canvas sandbox/CSP, `StudioCodePolicy`, platform signature/licensing gate, canonical JSON/ids/`schema_version`, `StudioReservedRoutes`.

## Acceptance criteria
1. Publishing is impossible without passing validation; blocking checks stop publish; warnings do not; every check has a test.
2. `expected_revision_id` is mandatory on publish; stale publishes are refused with the existing conflict contract.
3. A scheduled publish fires exactly once at the right time for the right revision, re-checks entitlement, and is cancellable; unpublish works and is audited.
4. Redirects are enforced, loop-safe and tenant-scoped; slug change creates one automatically.
5. SEO output includes valid hreflang and JSON-LD where applicable; sitemap index and per-locale entries are correct.
6. Zero critical/serious axe findings on the main builder screens and on seeded public pages; skip link and landmarks present.
7. Performance budgets met; adversarial tenant suite and load tests green in CI.
8. No new failures versus the documented baseline; docs refreshed.

## Definition of Done
Acceptance met; runbook for recompile/upgrade written and rehearsed on staging; CHANGELOG, EN/FR, docs; security review sign-off (publish, scheduling, redirects, SEO); CI green on both DBs including new gates; final regression of all six phases; release candidate tagged only after the open questions in master §24 are closed.

## Verification plan
End-to-end rehearsal on staging with two tenants: author → check → schedule → publish → verify public output (SEO, a11y, redirects) → unpublish → rollback; run all automated gates; manual a11y pass with a screen reader on desktop and mobile; performance profiling on a large page; attempt unauthorised publish/schedule via UI API, MCP and cross-tenant ids; verify licence-lapse behaviour of the publisher.

## Risks
- Scheduling infrastructure may not exist for plugins — needs a platform decision before work starts.
- Version bumps + large tenants ⇒ long recompiles; plan batching and cache warming.
- Redirect enforcement can break live sites if misconfigured — dry-run and logging first.
- hreflang depends on locale relations whose docs are missing — verify the model before building.
- Scope creep from M10 hosting/domain/visibility and from Comments — keep explicitly out/optional.
- A11y checks can produce noisy warnings — severity levels and an "acknowledge" mechanism may be needed.
