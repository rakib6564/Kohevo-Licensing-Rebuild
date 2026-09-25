# Phase 1: 15 — Decision Register

**Document Status:** Architecture Specification (Documentation Only)
**Phase:** Phase 1 — Target Architecture Specification
**Purpose:** Consolidate every architectural decision made across `01`–`14`, and every open item flagged `REQUIRES VERIFICATION`, into one register per `DECISIONS.md` §21's format.

---

## 1. Decisions Made in This Phase

| # | Decision | Reason | Alternatives Considered | Selected Architecture | Impact | Unresolved |
| :-- | :--- | :--- | :--- | :--- | :--- | :--- |
| D1 | Split Central `licensing_installs` into `licensing_licenses` + `licensing_installations` | `DB-02`: current table conflates commercial entity with deployment record; a License must be able to exist and be validated before any Installation binds | (a) Keep one table with a discriminator column; (b) keep as-is and bolt entitlements on | Two distinct tables, 1:1-*active* via a generated-column partial unique key on Installations (`uniq_installation_active_license` — see `D17`; supersedes this row's original flat `UNIQUE (license_id)` framing) | New migration required on Central Server; no data migration needed (`13` §1) | ~~FK constraints on the relationship — see D8~~ **Resolved, see `D16`.** |
| D2 | Move entitlements from Plan (`entitlements_json`) to License (`licensing_license_modules`) | `DB-03`: all installs on a plan currently get identical, non-customizable modules, violating `REQUIREMENTS.md` §6 | (a) Keep entitlements on Plan, allow per-install override JSON column; (b) full split (selected) | `licensing_plan_modules` (template) + `licensing_license_modules` (actual grant) | Plan becomes purely a creation-time convenience; License creation UI (future phase) must let admin toggle modules independently of the chosen plan | None |
| D3 | Core entitlements are implicit, never rows in the entitlement table | `REQUIREMENTS.md` §3.1, `DECISIONS.md` §4 — Core must not be individually disable-able | (a) Insert 3 always-present rows; (b) implicit (selected) | Core = "true whenever license is valid," no row lookup | Global License Guard alone protects Core; no Module Guard call needed for Core routes | None |
| D4 | `module_key` is `VARCHAR`, not `ENUM` | `REQUIREMENTS.md` §14 — future modules (Editor, Content) must not require redesign | (a) `ENUM('forms','membership','booking')`; (b) `VARCHAR` allow-listed in application code (selected) | Free-text column, app-level validation | Adding a module requires zero schema migration | None |
| D5 | Global License Guard invoked from a single choke point in `config.php` | `06-LICENSING-SECURITY-AUDIT.md` finding 2.10 — per-file gating is exactly how the current gaps (`MOD-01`–`MOD-05`) happened | (a) Add the call individually to every current entry point (mirrors `Auth::require()` convention but repeats the current failure mode); (b) single `config.php`-level call (selected, `RECOMMENDED`) | One mandatory call, whitelist-based early return | Cannot be "forgotten" by a future new admin page/route | Confirm during Phase 6 implementation kickoff — flagged `RECOMMENDED`, not locked |
| D6 | Admin sessions no longer bypass the license gate; unconfigured installs default to locked, not open | `AUTH-02`, finding 2.12 — both are fail-open bugs today | (a) Keep admin bypass for operational convenience; (b) remove both bypasses, add an explicit whitelisted recovery screen instead (selected) | Fail-closed by default; narrow, explicit, auditable whitelist | Admin must use a dedicated License Locked recovery screen, not full app access, when locked | None — directly required by `REQUIREMENTS.md` §5 |
| D7 | Reuse `/licensing/check` for both Activation and Refresh — no separate `/licensing/activate` endpoint | `LicensingAPI::handleCheckIn()` already implements find-or-create binding semantics correctly | (a) New dedicated activation endpoint; (b) reuse existing endpoint (selected) | Same endpoint, synchronous call at install time vs. best-effort at cron time | Simpler; avoids duplicating binding/activation-limit logic in two places | None |
| D8 | Foreign keys on new Central tables | `DB-06` risk vs. `install.sql`'s stated no-FK plugin convention | (a) Add FKs, break convention **← selected, see `D16`**; (b) no FKs, keep convention, rely on app code | **`RESOLVED`: (a) — see `D16` in §1a** | Determines whether orphaned rows are possible by construction or prevented by the database | ~~Yes — see §2, item R1~~ **No — resolved.** |
| D9 | Domain-normalized binding: hard block vs. soft signal | Current code hard-blocks on domain mismatch; `CLOUD-AGENT-RULES.md` §10 warns against domain-alone identity (not against domain as a second factor) | (a) Keep hard block (current, safer default); (b) soft/informational only | **Not selected — open, `RECOMMENDED` default is (a) until decided** | Affects operability of legitimate hosting migrations/domain changes vs. cloning resistance | **Yes — see §2, item R2** |
| D10 | Offline/network tolerance window length | `REQUIREMENTS.md` §10.3 requires the concept but specifies no number | (a) Reuse existing 7-day `REMOTE_GRACE_SECONDS` value; (b) pick a new number | `RECOMMENDED`: reuse 7 days, formally renamed/separated from commercial grace | Determines how long an installation stays usable during a Central Server outage | **Yes — see §2, item R3** |
| D11 | Renew vs. Extend — semantic distinction | Both listed as distinct operations in `REQUIREMENTS.md` §9 with no definition given | (a) Treat as synonyms; (b) distinct semantics (selected, `ASSUMPTION`) | Renew = new commercial period; Extend = push `expires_at` without a new period; same schema effect, different audit `event_type` | Reporting/audit clarity only — no different enforcement behavior | **Yes — see §2, item R4** |
| D12 | New installations only; no data migration from existing Central/client licensing tables | Locked project decision for this phase | N/A — directly given | No dual-write/dual-read; legacy tables remain, unused by new installs | Simplifies implementation significantly | None — locked |
| D13 | Legacy client licensing code (`LicenseService`, `PlanService`, tenant/plan/license admin pages) is isolated, not deleted, this phase | Phase 1 instruction: "Do NOT delete or rename existing licensing code" | N/A — directly given | Deprecated in documentation (`13` §2); actual removal deferred to a future phase | Codebase carries dead-for-new-installs code for now | Whether/when a future phase removes it — not this phase's decision |

### 1a. Corrections Following Independent Review (`16-PHASE-1-ANTIGRAVITY-REVIEW.md`)

The following decisions were made in response to Antigravity's "Approved With Conditions" verdict and formally supersede the corresponding entries above (`D8`, `D9` in part) and open items (`R1`, `R13`) in §2. Each is `LOCKED` by explicit project-owner instruction, not merely `RECOMMENDED`.

| # | Decision | Reason | Selected Architecture | Impact | Supersedes |
| :-- | :--- | :--- | :--- | :--- | :--- |
| D14 | Bind the signed check-in payload to a specific installation via an `installation_id` field | Finding F-01 (`P0 — CRITICAL`): without it, a copied `raw_payload`/`raw_signature` verifies successfully on any installation, enabling offline license cloning | `installation_id` added to the signed JSON payload (`11` §2); client asserts `payload.installation_id === local installation_id` on every verification, write-time and read-time alike (`10` §4), treating a mismatch as a verification failure | Closes the most severe finding in the review; converts installation-cache cloning from undetected to prevented at read time (`12` §2.2) | None — new payload field, additive to the existing envelope |
| D15 | Bound installations receive `HTTP 200` with a signed payload for every commercial state (`active`/`expired`/`suspended`/`revoked`); `4xx` is reserved for invalid credentials/envelope/binding mismatch | Finding F-02 (`P1 — HIGH`): the prior design let `11`'s "preserve existing transport" claim contradict `08`'s requirement that Grace/Locked be derived from a signed payload the client actually receives | `LicensingAPI::handleCheckIn()` response contract corrected in `11` §1, §7, §13 | An expired/suspended/revoked license can now actually enter Grace or trigger authoritative Locked, as `08`'s state machine already assumed | None — corrects an internal contradiction, not a decision reversal |
| D16 | Answers `R1`: adopt database-level foreign keys on the three new Central licensing relationships (`licensing_installations.license_id` `RESTRICT`, `licensing_license_modules.license_id`/`licensing_license_events.license_id` `CASCADE`) | Finding confirmed independently as blocking (review §5 item 1); `DB-06`'s orphan-row risk is exactly the category of gap application-code-only discipline has already failed to prevent once | FKs adopted per `09` §12's table, breaking from the plugin's no-FK convention specifically for this subsystem | Migration `0025`'s DDL must include the FK clauses; a License row can no longer be hard-deleted while an active Installation references it (by design) | `D8` (was "not selected — open"), `R1` in §2 (was open) |
| D17 | Answers `R13`: `licensing_installations` soft-deactivates on reset (`status = 'superseded'`, `deleted_at` set) instead of hard-deleting; uniqueness enforced via a generated `active_license_id` column, not a flat `UNIQUE (license_id)` | Finding F-03 (`P1 — HIGH`): hard-delete-on-reset (the prior default) destroys exactly the installation/activation history `REQUIREMENTS.md` §8 requires the Central Server to retain | `09` §7's generated-column partial-unique-index pattern; full before/after installation history remains queryable under `idx_installation_license` | Reset (`resetBindings()`-equivalent) becomes an `UPDATE` + `INSERT` within one transaction instead of a `DELETE` + `INSERT` | `D9`'s implicit hard-delete framing, `R13` in §2 (was "(a) Hard-delete... simplest") |
| D18 | Installer persists the generated Installation ID to `.env` (`INSTALLATION_ID=...`), not only to the `installation_identity` table, and reuses it on any Step 2 retry | Finding F-04 (`P1 — HIGH`): a database wipe between a successful Step 4 activation and a failed Step 5/6 previously caused Step 2 to mint a *new* Installation ID that the Central Server, having already recorded an activation for the *old* one, would reject with `activation_limit` — a permanent deadlock | `.env`-persisted ID, reused across a DB-level retry (`05` §4) | Closes the one Phase 4/installer-scope finding the review flagged as blocking-in-spirit even though it does not block Phase 2's schema work | None — new mechanism, no prior decision existed for this scenario |
| D19 | Global License Guard bypasses for automated test execution and migration-runner execution, gated on non-request-controllable constants/env values only | Finding F-05 (`P2 — MEDIUM`): a strict fail-closed Guard bootstrapped from `config.php` would otherwise deadlock every PHPUnit run and every schema migration against a database with no license state yet | `SLATE_TESTING`/`SLATE_MIGRATING`/`APP_ENV=testing` early-return, defined only by test/migration bootstrap code, never by request input (`06` §3a) | Test suites and migrations remain runnable once the Guard exists; production/dev traffic gets no exception | None — new mechanism |
| D20 | `admin/logout.php` added to the Global License Guard's whitelist | Finding F-07 (`P3 — LOW`): an admin logged in under an account without `licensing.manage` when the system locks previously had no way to log out and re-authenticate as an account that could reach the recovery screen | `06` §2 whitelist entry | Closes the one gap in the "installation, activation, license validation, recovery, logout" whitelist checklist | None — new whitelist entry |

---

## 2. Open Items Requiring Explicit Project Decision (`REQUIRES VERIFICATION`)

| # | Item | Where Raised | Options | Recommendation If Forced to Default |
| :-- | :--- | :--- | :--- | :--- |
| R1 | ~~Add FK constraints to `licensing_installations.license_id`, `licensing_license_modules.license_id`, `licensing_license_events.license_id`?~~ **`RESOLVED` — see `D16` in §1a.** | `09-CENTRAL-DATABASE-DESIGN.md` §12 | (a) Add FKs, break plugin convention **← selected** ; (b) keep no-FK convention | **Decided: (a).** FKs adopted — `RESTRICT` on `licensing_installations.license_id`, `CASCADE` on the two child tables |
| R2 | Domain binding: hard block or soft signal? | `05-INSTALLATION-ACTIVATION.md` §4 | (a) Hard block (current behavior); (b) soft/informational | (a), as the safer fail-closed default, until explicitly changed |
| R3 | Offline tolerance window — exact duration | `08-EXPIRY-GRACE-OFFLINE.md` §4 | Any duration; 7 days reuses existing code | 7 days, reusing `EXISTING` `REMOTE_GRACE_SECONDS` value, as a grounded starting point |
| R4 | Renew vs. Extend semantic split — confirm or reject | `03-LICENSE-LIFECYCLE.md` §3 | (a) Confirm the split as documented; (b) treat as synonyms | Confirm the split — it costs nothing extra to record the distinction in the audit trail even if UI/process doesn't yet differentiate them |
| R5 | Is `trial` a first-class V1 state, or out of scope? | `03-LICENSE-LIFECYCLE.md` §1 | (a) Retain as an Active sub-value (current code already does); (b) remove from V1 scope entirely | Retain — removing it would require touching `EXISTING`, currently-correct code (`EntitlementService::LICENSED_STATUSES`) for no requirement-driven reason |
| R6 | Is `cancelled` a first-class V1 state? | `03-LICENSE-LIFECYCLE.md` §1 | (a) Retain as superset-compatible; (b) drop | Retain, unused unless the project owner defines its meaning |
| R7 | Central Server: fresh deployment or upgrade of the currently-audited instance? | `13-MIGRATION-STRATEGY.md` §3 | (a) Fresh; (b) upgrade of existing | Not defaulted — materially changes whether legacy Central tables need to coexist with real data |
| R8 | Request signing (per-installation HMAC secret) — implement now or defer? | `11-LICENSING-API-CONTRACT.md` §8–§9, `12-SECURITY-ARCHITECTURE.md` §2.5 | (a) Implement in this rebuild; (b) defer to a later security-hardening pass | Defer — not required by locked decisions, existing binding-mismatch check already provides meaningful protection |
| R9 | Replay protection (`checked_at` tolerance / nonce) — implement now or defer? | `11-LICENSING-API-CONTRACT.md` §10 | (a) Basic tolerance window now; (b) full nonce-based later; (c) defer entirely | Basic tolerance window is low-cost and `RECOMMENDED` now; full nonce-based protection tied to R8, defer |
| R10 | Rate limiting on `/licensing/check` | `12-SECURITY-ARCHITECTURE.md` §4 | (a) Implement (requires new infra — cache or counter table); (b) defer | Defer to security-hardening phase given added infrastructure cost and low marginal risk reduction given existing anti-enumeration design |
| R11 | Central Server signing-key rotation procedure | `12-SECURITY-ARCHITECTURE.md` §5 | Needs a defined procedure; none exists today | Defer — no rotation event is anticipated for V1; document as a known gap |
| R12 | Nav-hiding of deprecated client platform-admin pages (`tenants.php` etc.) on new installs | `13-MIGRATION-STRATEGY.md` §2 | (a) RBAC gate alone is sufficient; (b) also hide from nav defensively | (a) is sufficient for V1; (b) is a cheap follow-up, not blocking |
| R13 | ~~Should `licensing_installations` preserve history across an admin-initiated reset, or hard-delete (current behavior)?~~ **`RESOLVED` — see `D17` in §1a.** | `09-CENTRAL-DATABASE-DESIGN.md` §7 | (a) Hard-delete (current, simpler); (b) soft-delete for history **← selected** | **Decided: (b).** Soft-deactivation (`status = 'superseded'`, `deleted_at`) via a generated-column unique key; supersedes this row's original "(a), simplest" recommendation, which Finding F-03 showed to violate `REQUIREMENTS.md` §8 |
| R14 | Core entitlement key names (`admin-user`, `dashboard`, `site-settings`) — confirm exact naming | `04-ENTITLEMENT-ARCHITECTURE.md` §1 | Any naming scheme | Confirm proposed names or supply alternatives before Phase 2 implementation — purely a naming convenience, not a structural question |
| R15 | "Activated but no admin created" abandoned-install follow-up process | `05-INSTALLATION-ACTIVATION.md` §8 | Operational/customer-success process, not architecture | No architectural default needed — flagged for awareness only |
| R16 | Whether `install.sql` on the Central Server should be split so fresh deployments skip legacy tables | `13-MIGRATION-STRATEGY.md` §3 | (a) Split; (b) always provision both old and new schema | Depends on R7's answer |

---

## 3. Architecture Risks Carried Forward

Restating, for visibility, the risks this Phase's documents identified but does not resolve (each is detailed in its source document):

- **Cloning during the offline tolerance window, whole-database vector only** is detected on next check-in, not prevented in real time (`12-SECURITY-ARCHITECTURE.md` §2.2, vector 1) — an inherent trade-off of any offline-tolerant pull-based licensing scheme, not a flaw specific to this design. (The more severe cached-state-only cloning vector, `12` §2.2 vector 2, is now prevented outright at read time following `D14`/Finding F-01's correction — no longer merely detected.)
- **Clock manipulation** can distort local enforcement of Warning/Grace/Locked boundaries and offline-tolerance freshness; only partially mitigated by the fact that the underlying signed state cannot itself be forged (`08-EXPIRY-GRACE-OFFLINE.md` §7).
- **Loss of the Central Server's `APP_SECRET`** would make the Ed25519 private signing key unrecoverable, breaking license issuance for every existing license issued by that server (`MIG-05`, `12-SECURITY-ARCHITECTURE.md` §2.16) — an operational/backup-procedure risk, not resolved by architecture alone.

---

## 4. Assumptions Register

Consolidated from every document's `ASSUMPTION` tags, for a single point of review:

1. Core entitlement key naming (`admin-user`/`dashboard`/`site-settings`) is proposed, not confirmed (R14).
2. `trial` and `cancelled` remain in the License status enum without new defined meaning beyond their current code usage (R5, R6).
3. Renew and Extend are semantically distinct operations with identical schema effect but different audit intent (R4).
4. `bin/license-check.php` is the only CLI surface requiring Guard classification (`06-GLOBAL-LICENSE-GUARD.md` §6).
5. HTTPS transport is assumed for all Central Server API traffic (`12-SECURITY-ARCHITECTURE.md` §2.5).
6. The existing Slate framework's general CSRF/output-escaping conventions apply uniformly to new licensing UI without a licensing-specific gap (`12-SECURITY-ARCHITECTURE.md` §6).
7. `EntitlementService`'s `tenantId` parameter is retained unchanged in new Module Guard call sites rather than removed (`14-BACKWARD-COMPATIBILITY.md` §2).

---

## 5. Items Requiring Human Approval Before Phase 2 Implementation

Per `DECISIONS.md` §17's "obtain project decision" step, the following must be explicitly confirmed (not merely left as this document's default) before implementation begins, in priority order:

1. ~~**R1** (foreign keys)~~ — **`RESOLVED`, see `D16` in §1a.** No longer blocks Phase 2 kickoff.
2. **R7** (fresh vs. upgrade Central Server) — affects whether `13-MIGRATION-STRATEGY.md` §3's dual-schema-coexistence approach is even the right shape.
3. **R2** (domain binding strength) — affects installer/activation error messaging and support documentation.
4. **R3** (offline tolerance duration) — affects a customer-visible behavior (how long the app stays usable during an outage).
5. **R14** (core entitlement key names) — low-risk but blocks writing the first migration's exact column values.

Items R8–R12, R15–R16 are `RECOMMENDED` to defer past V1 without blocking Phase 2 kickoff, per each item's own recommendation column in §2. **R13 is `RESOLVED`, see `D17` in §1a** — it no longer needs deferring or an explicit go-forward decision; the soft-deactivation design is now the specified baseline.

**Findings F-04, F-05, F-07** (`16-PHASE-1-ANTIGRAVITY-REVIEW.md`) are resolved as `D18`–`D20` in §1a and require no further human approval — each was a gap-closure with a single reasonable resolution, not a trade-off needing a project-owner choice between alternatives.

---

## 6. Recommended Next Phase

Per `planning.md` §12, the next phase is **PHASE 2 — Central Licensing Platform Foundation**. Before starting it, this document recommends the project owner explicitly resolve the remaining items in §5 above, since Phase 2's first deliverable (the Central Server's new schema migration) directly depends on `R7` and `R14` (`R1` is now resolved — see `D16`), and Phase 2's admin/API work depends on `R2` and `R3`'s answers to correctly document expected behavior in tests (`planning.md` Phase 12's test matrix already anticipates "Grace period," "Grace expired" cases that need R3's number to be testable). With `R1`/`R13` resolved (`D16`, `D17`) and Findings F-01/F-02/F-04/F-05/F-07 corrected (`D14`, `D15`, `D18`–`D20`), Phase 1 satisfies every `Blocks Phase 2: YES` condition `16-PHASE-1-ANTIGRAVITY-REVIEW.md` §11 lists — see that document's four Binding Conditions, all now reflected in `09` §7/§12 and `11` §2/§7.
