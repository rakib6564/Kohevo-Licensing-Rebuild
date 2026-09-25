# 03 — Coding Standards

**Status:** Draft · **Applies to:** Slate v1.x → v5.x (core, SDK, and every module) · **Anchor document**

These are the rules for *how Slate code is written* — style, naming, structure,
error handling, security defaults, and the ban on ambient global state. They are
normative: a change that violates a MUST here does not merge. The permanent
**namespace strategy, `src/` layout, and autoloading conventions** are specified
once in [platform-foundation.md](platform-foundation.md) (ADR-0013).
Module-specific obligations extend these in
[module-development-standards.md](module-development-standards.md); versioning and
the backward-compatibility policy are in
[versioning-and-compatibility.md](versioning-and-compatibility.md).

Read [00-Vision §2–3](../00-Vision/) (*one concept, one owner*; *boring where it
counts*) and [01-Architecture §7](../01-Architecture/#7-architectural-invariants-must-always-hold)
(the eight invariants) first — these standards are how those invariants are held
at the line-of-code level. Vocabulary is the [canonical glossary](../README.md#canonical-glossary-fixed-vocabulary),
used here exactly.

> **RFC-2119 keywords.** MUST / MUST NOT are hard rules enforced in review and,
> over time, by lint/CI. SHOULD is a strong default a reviewer may waive with a
> written reason. MAY is a genuine option.

---

## 1. WHY these standards exist

The [audit](../AUDIT-BRIEFING.md) names the debt precisely: no namespaces, 691
`require_once` calls, global classes, no tests, no VCS, table prefixes that don't
match the slug, and settings drift. None of that is a "code smell" to tidy later —
each one is a boundary the platform *needs* dissolving. The standards below exist
to make the [invariants](../01-Architecture/#7-architectural-invariants-must-always-hold)
mechanically true rather than aspirational:

| Audit finding | Standard that retires it |
|---|---|
| No namespaces, global classes | §3 PSR-4 `Slate\…` namespaces; §9 no global state |
| 691 `require_once` | §3 autoloading only; `require` is banned in application code |
| Table prefix ≠ slug | §4 tables MUST match the slug-derived prefix |
| Settings drift | §4 settings keys MUST be `<slug>.` namespaced; one accessor |
| `ensureSchema()` self-heal | §7 ship migrations, never runtime schema mutation (ADR-0010) |
| `float`/`DECIMAL` money | §8 `Money` value object end to end (ADR-0011) |
| No tests | §10; the DoD in [module-development-standards.md](module-development-standards.md#definition-of-done) |

---

## 2. Language & style (PHP 8.1+)

- **Target PHP 8.1**, forward-compatible to the host's 8.4. Use language features
  that raise safety, not cleverness: typed properties, constructor property
  promotion, enums, `readonly`, first-class callable syntax, named arguments at
  call sites where they aid clarity, `match` over long `switch`.
- **`declare(strict_types=1);`** MUST be the first statement of every PHP file.
- **Types everywhere.** Every function/method parameter, return, and property MUST
  be typed. Use `void`, `never`, nullable (`?T`), union, and `iterable` types
  honestly. `mixed` MUST be justified in a comment.
- **One class/interface/enum/trait per file**, named identically to the symbol.
- **PSR-12** formatting: 4-space indent, braces on their own line for
  classes/methods, `camelCase` methods, one blank line between methods. A shipped
  `.editorconfig` and formatter config are the source of truth; do not hand-argue
  whitespace in review.
- **No suppression.** `@` error suppression is banned. No `error_reporting(0)`.
- **No dead debug.** `var_dump`, `print_r`, `dd()`, stray `error_log()`, and the
  `_*.php` inspection scripts flagged in the audit MUST NOT ship. Diagnostics go
  through the logger (§8) behind a permission or `CRON_SECRET`-style gate.
- **Immutability by default.** Prefer `readonly` value objects and pure functions.
  Mutable shared state is the thing this whole document is trying to kill.
- **Strings & i18n.** User-facing strings go through the I18n service, never
  concatenated inline. No business logic in string templates.

---

## 3. Namespaces, files & autoloading (PSR-4)

The single most important structural rule: **`Slate\…` PSR-4, no `require`.**

- **Root namespace `Slate`.** Kernel/services live under `Slate\` sub-namespaces
  (`Slate\Kernel`, `Slate\Identity`, `Slate\Payments`, `Slate\Rendering`, …). The
  SDK surface is `Slate\Sdk\…` ([06-SDK](../06-SDK/)). Each module owns a vendor-ish
  namespace derived from its slug: slug `booking` → `Slate\Module\Booking\…`, slug
  `content-builder` → `Slate\Module\ContentBuilder\…` (hyphen segments
  `StudlyCase`d).
- **PSR-4 autoloading only.** Application code MUST NOT contain `require`,
  `require_once`, `include`, or `include_once`. The front controller
  ([01-Architecture §3.1](../01-Architecture/#31-runtime--bootstrap)) registers the
  autoloader; everything else is resolved by class name. The lone permitted
  `require` is the autoloader bootstrap itself and the vendored PHPMailer entry.
- **Directory = namespace.** `includes/Payments/StripeGateway.php` ⇒
  `Slate\Payments\StripeGateway`. No file may be loaded by path.
- **File layout of a class file**, in order: `<?php`, `declare(strict_types=1);`,
  blank line, `namespace …;`, `use …;` (grouped: PHP core, `Slate\*`, module), blank
  line, the type. No side effects at file scope — a class file MUST NOT run code on
  inclusion (no top-level `new`, no `Hook::add…`, no output).

---

## 4. Naming (the contract vocabulary)

Names are load-bearing: the kernel, the permission union, the settings store, and
the tenant scoper all key off them. Get a name wrong and a boundary silently leaks.

### 4.1 Slug — the root identifier

Every module has one **slug**: lowercase, hyphen-separated, DNS-safe, globally
unique (`booking`, `content-builder`, `stripe-payment`). The slug is the root from
which the table prefix, namespace, settings namespace, asset path, and route prefix
are all **derived** — they are never chosen independently.

| Derived from slug | Rule | `content-builder` example |
|---|---|---|
| Table prefix | `slug` with `-`→`_`, then `_` | `content_builder_` |
| PHP namespace | `Slate\Module\` + StudlyCase(slug) | `Slate\Module\ContentBuilder` |
| Settings namespace | `slug` + `.` | `content-builder.` |
| Permission domain | `slug` (or a sub-domain of it) | `content-builder.*` |
| Asset base path | `assets/<slug>/…` | `assets/content-builder/…` |

### 4.2 Classes, methods, constants

- Classes/interfaces/enums/traits: `StudlyCaps`. Interfaces are named for the
  capability (`PaymentGateway`, `SearchIndex`), **not** `IPaymentGateway` or
  `PaymentGatewayInterface`. Abstract base classes MAY use an `Abstract` prefix
  only when a concrete same-named type also exists.
- Methods/functions/variables: `camelCase`. Constants: `UPPER_SNAKE`. Enum cases:
  `StudlyCaps`.
- No `snake_case` function soup, no global helper functions in application code;
  the few sanctioned global helpers (CSRF, escaping) live in a documented SDK
  namespace, not the global scope.

### 4.3 Database tables & columns

- **Tables MUST begin with the slug-derived prefix** (§4.1). `booking_appointments`,
  `content_builder_posts`. This is a MUST — the audit's "table prefix ≠ slug" is a
  boundary violation, because the only way tooling can attribute a table to its
  owner (and forbid cross-module reads, [invariant #1](../01-Architecture/#7-architectural-invariants-must-always-hold))
  is by the prefix.
- Table & column names: `snake_case`, plural table names, singular columns.
- **Every domain table MUST carry `tenant_id`** ([invariant #2](../01-Architecture/#7-architectural-invariants-must-always-hold)).
  Primary key `id` (`BIGINT UNSIGNED AUTO_INCREMENT`). Timestamps `created_at` /
  `updated_at`. Money stored as `*_minor` integer columns plus a `*_currency`
  column, never `DECIMAL` (§8). FK columns named `<referent_singular>_id`.
- Core tables carry **no** module prefix and are owned solely by their service; a
  module MUST NOT write them ([invariant #1](../01-Architecture/#7-architectural-invariants-must-always-hold),
  layer-boundary table).

### 4.4 Settings keys

- Format `<slug>.<key>`, dot-separated, `snake_case` segments after the slug
  (`booking.reminder_lead_minutes`). Core settings use documented top-level
  namespaces (`security.`, `branding.`, `smtp.`). Reads/writes go through the
  Settings service — never a raw `SELECT` on `settings`. A module reads **only** its
  own namespace; another module's setting is reached via that owner's capability,
  never a cross-namespace read (this is the "settings drift" fix).

### 4.5 Permission keys

- Format **`<domain>.<action>`**, both `snake_case`
  (`booking.appointment_cancel`, `content-builder.post_publish`). `<domain>` is the
  slug or a sub-domain of it. Actions are verbs on a resource. Declared in the
  manifest (§ module standards) so they join the permission union the RBAC service
  authorizes against ([10-Security](../10-Security/)). Wildcards (`booking.*`) are
  for role grants, never for `can()` checks.

### 4.6 Event names & extension-point (filter) names

- **Events are past-tense facts:** `<domain>.<thing>_<pastVerb>` —
  `order.paid`, `appointment.booked`, `contact.created`. Lowercase, dot + snake.
  They state that something *happened*; listeners react with side effects only.
- **Extension points (filters) are present-tense value pipelines:** `<area>.<noun>`
  — `nav.items`, `seo.meta`, `blocks.register`, `sitemap.collect`. They *shape
  shared data*; they are not RPC and MUST NOT be used to trigger side effects.
- The distinction is an [invariant](../01-Architecture/#6-cross-cutting-concerns--where-they-live):
  facts (past tense) vs. data shaping (present tense). The typed catalogue lives in
  [06-SDK/event-catalogue.md](../06-SDK/event-catalogue.md).

---

## 5. File & project layout

- Core code under PSR-4 namespaces mapped from `includes/` (transitional) toward a
  clean `src/` root; no new code may be added outside the autoloaded tree.
- A module is a self-contained folder keyed by slug, with a fixed shape (manifest,
  migrations, `src/` for classes, `admin/`, `public/`, `assets/`, `tests/`). The
  canonical layout is specified in [module-development-standards.md §2](module-development-standards.md#2-module-anatomy)
  and scaffolded by `bin/make module` ([06-SDK](../06-SDK/)).
- **No entry-point sprawl.** One front controller for web + API
  ([01-Architecture §3.1](../01-Architecture/#31-runtime--bootstrap)); modules
  declare routes in the manifest rather than shipping directly-hit PHP files.
- Secrets (`.env`) MUST NOT ship in a distributable ZIP; `bin/package-*` MUST fail
  the build if one is present.

---

## 6. Error handling

- **Exceptions, not error returns or `false` sentinels.** Model failures as typed
  exceptions extending a `Slate\…\DomainException` hierarchy; distinguish
  *expected domain failures* (validation, not-found, forbidden) from *bugs*.
- **One global handler.** The front controller installs the only
  exception/error/shutdown handler. It logs with a correlation id, emits the
  branded [error page](../10-Security/) (never a stack trace, never a raw message)
  or a structured API error body ([07-API](../07-API/)), and sets the right HTTP
  status. Individual code MUST NOT `echo` errors or leak internals to users.
- **Fail closed.** On an authorization or tenancy uncertainty, deny. On an
  unexpected exception in a public render path, serve a safe error, not partial or
  privileged output.
- **Isolate module boot.** A module throwing during boot MUST be caught by the
  module manager and must not take down the shell ([01-Architecture §3.2](../01-Architecture/#32-kernel)) —
  but the failure MUST be logged and surfaced in admin, never swallowed silently.
- **Never suppress; never `@`.** Convert warnings to exceptions in the handler so
  nothing is silently ignored.

---

## 7. Persistence & migrations

- **Data access through repositories/services only** ([11-Database](../11-Database/)).
  Controllers, blocks, and templates MUST NOT run SQL. No ad-hoc `Database::query`
  in a controller.
- **Always prepared statements**, always parameter-bound; never interpolate input
  into SQL. Identifiers are allow-listed, not interpolated.
- **Tenant scope is automatic.** The base repository injects the current
  `tenant_id` into every read/write; crossing tenants requires an explicit,
  audited `crossTenant()` and is a reviewed exception
  ([invariant #2](../01-Architecture/#7-architectural-invariants-must-always-hold)).
- **Migrations, not self-heal.** Schema changes ship as versioned, ordered,
  reversible migrations that run once ([ADR-0010](../14-ADR/), [11-Database](../11-Database/)).
  `ensureSchema()`/column-reconcile-on-boot is **prohibited** in new code — it has
  no history, no rollback, and runs per request forever.

---

## 8. Security & escaping defaults

Security is structural, not remembered ([00-Vision §3.7](../00-Vision/),
[10-Security](../10-Security/)). Defaults, all MUST:

- **Escape on output, contextually.** HTML via the escaping helper, attributes via
  the attribute escaper, URLs validated, JSON via `json_encode` with safe flags.
  Templates escape by default; raw output is an explicit, reviewed opt-out.
- **Authenticate then authorize — separately.** Authn establishes the principal;
  authz is one `can(principal, permission, resource)` decision through the policy
  engine. `email_verified` is **not** an authorization signal (audit note).
- **CSRF** token on every state-changing request (timing-safe compare). **Secrets
  at rest** encrypted (AES-256-GCM). **Redirects** pass through the safe-redirect
  guard. **Uploads** MIME-validated and stored under PHP-off directories.
- **Money is `Money`** (integer minor units + currency), end to end
  ([invariant #3](../01-Architecture/#7-architectural-invariants-must-always-hold),
  [ADR-0011](../14-ADR/)). No `float`, no `DECIMAL`, no bare integers "in cents"
  passed around untyped.
- **Payments only via the `PaymentGateway` contract**
  ([invariant #7](../01-Architecture/#7-architectural-invariants-must-always-hold)) —
  no module calls a provider SDK directly.
- **Log, don't display.** Structured logs with correlation ids; no secrets, no PII
  beyond policy, in logs. Full auth/RBAC/error rules: [10-Security](../10-Security/).

---

## 9. No global state; DI over singletons

This is the antidote to the audit's "global classes / 691 `require_once`."

- **No global mutable state.** No `global`, no mutable statics, no service locator
  reached from arbitrary code, no ambient registry. State lives in objects resolved
  from the container.
- **Constructor injection.** A class declares its collaborators as constructor
  parameters; the kernel container wires them ([ADR-0004](../14-ADR/)). A class MUST
  NOT `new` a service or reach a global to find one
  ([invariant #5](../01-Architecture/#7-architectural-invariants-must-always-hold): no
  `new` across a boundary).
- **DI over singletons.** Where a single shared instance is wanted, express it as a
  *container binding*, not a `Foo::instance()` singleton. Static factory singletons
  are banned in new code because they hide dependencies and defeat testing.
- **No cross-module reach.** `class_exists('OtherPluginAPI')` +
  `OtherPluginAPI::foo()` is an architecture violation
  ([01-Architecture §5](../01-Architecture/#5-how-modules-communicate-the-decoupling-contract)).
  Depend on a capability interface, hear an event, or contribute to a filter — the
  three channels, nothing else.
- **Pure where possible.** Prefer functions that take inputs and return outputs
  over methods that mutate shared state; it is the difference between testable and
  not.

---

## 10. Testing (baseline)

Every unit of code ships with tests; details and layers in [12-Testing](../12-Testing/).

- New services/repositories/value objects: **unit tests**. New capability
  contracts: a **contract test** the provider must pass. New routes/flows:
  **feature tests** through the lifecycle.
- Tests MUST be deterministic, tenant-aware, and independent of ordering.
- Money, tenancy scoping, permission checks, and escaping are **required** test
  targets — they are the invariants, so they are the non-negotiable coverage.
- A change with no test is not done ([DoD](module-development-standards.md#definition-of-done)).

---

## 11. Definition of done (code-level checklist)

Before a change is proposed for review, all MUST hold:

- [ ] `declare(strict_types=1);` present; all params/returns/properties typed.
- [ ] Fully namespaced under `Slate\…`; loaded by PSR-4, zero new `require`/`include`.
- [ ] No file-scope side effects; one type per file.
- [ ] Names follow §4: tables carry the slug prefix + `tenant_id`; settings are
      `<slug>.`; permissions are `<domain>.<action>`; events past-tense, filters
      present-tense.
- [ ] All DB access via repository/service, prepared + tenant-scoped; schema change
      shipped as a **migration**, no `ensureSchema()`.
- [ ] Money is `Money`; no `float`/`DECIMAL` for currency.
- [ ] Output escaped contextually; CSRF on state changes; secrets encrypted;
      authz via the policy engine.
- [ ] No `global`, no static singletons, no `class_exists`-based cross-module call;
      dependencies constructor-injected.
- [ ] Errors thrown as typed exceptions; nothing echoed to the user; no `@`, no
      stray debug output.
- [ ] Tests added/updated and passing; invariants covered.
- [ ] No BC break outside a MAJOR ([versioning-and-compatibility.md](versioning-and-compatibility.md));
      deprecations annotated and dated.
- [ ] Docs amended in the same change if a boundary or contract moved
      ([15-Contributing](../15-Contributing/), the amend-first rule).

---

## 12. Related documents

- [module-development-standards.md](module-development-standards.md) — the rules a
  module must additionally satisfy, and its definition of done.
- [versioning-and-compatibility.md](versioning-and-compatibility.md) — SemVer and
  the backward-compatibility / deprecation policy ([ADR-0009](../14-ADR/)).
- [06-SDK](../06-SDK/) — the surface these standards apply to; base classes,
  manifest, event catalogue.
- [11-Database](../11-Database/) — repositories, migrations, `Money`.
- [10-Security](../10-Security/) — auth, RBAC/policy, error handling in full.
- [12-Testing](../12-Testing/) — the testing strategy and tooling.
- [14-ADR](../14-ADR/) — the decisions (ADR-0004, 0005, 0009, 0010, 0011) these
  standards implement.
- [15-Contributing](../15-Contributing/) — how a change (code + doc) is proposed
  and reviewed.
