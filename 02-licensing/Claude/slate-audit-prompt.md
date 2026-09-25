# Prompt: full code-level issue audit of slate-platform

Paste the block below into Claude Code, run from the root of the `slate-platform` repo on `develop`.
It is written to be read-only — it finds and reports, it does not change anything.

---

You are auditing this repository for issues. Read-only: do not edit, refactor, commit, or open PRs. Your entire output is a report.

## Context

Slate is a multi-tenant PHP/MySQL platform. Plugins live in `plugins/<name>/`, each with its own `admin/` screens; core admin is in `admin/`. The live tenant (Solaya) runs `booking`, `booking-plus`, `membership`, `coaching`, `content-builder`, `forms`, `multilang-translate`, `react-site-bridge`, `stripe-payment`, `restaurant`.

A browser-level audit of the running admin and customer portal has already been done. Its central finding: **every plugin re-implements the same objects** — people, profiles, categories, settings, messaging, i18n — instead of sharing a core layer. Your job is to establish what that looks like *in the code*, and to find everything the browser could not see.

## Phase 1 — Map before you judge

Build a factual inventory first. Do not report anything yet.

1. Every plugin: its tables (from migrations/schema), its admin screens, its public routes, its settings keys.
2. Every table that stores a person (any table with an email or a name+phone column). Note the FKs between them, or the absence of FKs.
3. Every settings surface: what writes to which key, and whether two screens write the same key.
4. Every place a message/notification is composed or scheduled.
5. Every duplicated helper: functions or classes with the same job in more than one plugin (formatting, validation, mailing, currency, dates, slugs, CSRF, pagination, tables).

Write this inventory to `audit/inventory.md` as you go, so the analysis has something to cite.

## Phase 2 — Find the issues

Look for these specifically, in roughly this priority order:

**Duplication**
- The same domain object modelled in two or more plugins (people above all)
- The same field asked for twice under different names (gender/DOB/medical/allergies are known cases — confirm at schema level and find others)
- The same setting writable from two screens; say which one wins at runtime and whether that is deterministic
- Copy-pasted templates or helper functions that have since **diverged** — a divergence is a bug in waiting, flag it louder than a clean copy
- Two implementations of one UI primitive (login form, table, pagination, language switcher, date formatting)

**Correctness**
- State derived from the wrong source (known: attendance shows "Present" before a session starts; "Enrolled" is true for services with zero sessions — find the code and confirm the cause)
- Quota vs time-based plan logic being mixed
- Currency handled as a display string rather than a value+code pair
- Timezone handling: where is "today" computed, and is it consistent between server, tenant and client?
- Routing that silently falls through instead of 404ing (known: `/slate/member/profile` renders Home)

**Security & data**
- Tenant scoping: any query that could read or write across tenants. This is the highest-severity class — check every raw query, every cache key, every file path built from tenant input.
- CSRF coverage on state-changing endpoints; note any that are missed
- Authorization checks on admin endpoints — is every screen gated, or do some rely on nav visibility only?
- SQL built by string concatenation
- Personal and medical data: every table it lands in, and whether a single deletion path removes all of it (GDPR)
- Secrets or credentials in the repo or in committed config

**Migrations**
- Any migration without a working `rollback`
- Migrations that would break on a tenant with existing data

**Dead weight**
- Plugins/screens/routes/tables no longer reachable
- Stubbed features presented in the UI as working (known: Zoom `api` mode is stubbed)
- TODO/FIXME/"phase 4"/"will merge later" markers — collect them all; they are the team's own list of known debt

## Phase 3 — Verify before reporting

For every candidate issue: go back to the code and prove it. State the concrete failure — the input or state, and the wrong result. If you cannot construct that, either mark it **unverified** or drop it. A short list of certain findings is worth far more than a long list of suspicions.

Do not report: formatting, naming taste, missing type hints, or "consider extracting a helper" where nothing is actually wrong.

## Output

Write `audit/findings.md`:

- **Summary** — the 5 things that matter most, one line each
- **Findings**, ordered by severity (`critical` / `high` / `medium` / `low`), each with:
  - one-sentence claim
  - evidence: `path/to/file.php:120-140`, quoted minimally
  - the concrete failure case
  - blast radius: which tenants/plugins/screens
  - suggested fix, one paragraph, no code
  - `verified` or `unverified`
- **Cross-cutting patterns** — where the same root cause produces many findings
- **What I could not check** and why

Then print the summary to the terminal. Nothing else.

## Rules

- Read-only. No edits, no commits, no branches.
- Cite `file:line` for every claim. A finding without a citation is not a finding.
- Where the browser audit's conclusions conflict with the code, **the code wins** — say so explicitly.
- Never infer behaviour from a filename or a comment; read the implementation.
- If the repo is large, fan out with parallel subagents by plugin, then reconcile — but every finding still needs its own citation.

---

## Tuning notes

**To scope it down** — replace Phase 2 with one section, e.g. *"Only the tenant-scoping and authorization checks under Security & data."* A narrow audit that finishes beats a broad one that runs out of context.

**To go plugin by plugin** (recommended if the repo is big):

> Audit `plugins/booking/` and `plugins/booking-plus/` only. Same phases, same output rules, write to `audit/findings-booking.md`. Pay particular attention to what the two plugins duplicate between them: services, settings keys, reminder scheduling, and any helper that exists in both.

**To verify the browser audit specifically:**

> Read `claude/slate-duplication-audit.md`. Treat each finding as a hypothesis. For each one, find the code that causes it and either confirm it with a `file:line` citation or refute it. Report confirmations and refutations separately — refutations are as valuable as confirmations.

**To chase one bug:**

> In `plugins/membership/`, attendance shows "✓ Present" for a session that has not happened yet, and "Course progress" reports the member enrolled in every service including ones with zero sessions. Find both causes. Explain what each currently keys off and what it should key off. Do not fix them yet.
