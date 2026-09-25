# 10 — Error Handling

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

How Slate fails: never leaking internals to users, always leaving a diagnosable
trail, and never letting one component's failure take down the request or the
platform. Error handling is a security concern (leakage) and a reliability
concern (isolation) at once.

---

## 1. Never leak to users

- `display_errors` **off** in every environment; errors are logged, never
  rendered.
- User-facing failures are **branded, generic pages** (403/404/500) or the
  standard [API error envelope](../07-API/versioning-and-errors.md) — no stack
  traces, SQL, paths, or secrets.
- A resource in another tenant returns **404, not 403**, so existence isn't leaked
  across tenants.

## 2. Always diagnosable: correlation ids

- Every request is assigned a **correlation id** at the front controller.
- It appears in every log line for that request and in the user-facing error
  ("reference: `req_01H…`"), so a support report ties to exact logs without the
  user ever seeing internals ([13-Operations/logging-and-auditing.md](../13-Operations/logging-and-auditing.md)).

## 3. Exception boundaries (isolation)

Failures are contained at well-defined boundaries so blast radius is bounded:

| Boundary | On failure |
|---|---|
| Module `boot()` | logged + skipped; the platform and other modules continue |
| Event listener | logged + skipped; dispatch continues to other listeners |
| Extension-point contributor | its contribution is dropped; the pipeline continues |
| Rendering a Block | the block renders a safe placeholder; the page still renders |
| Request handler | caught by the front controller → branded 500 + logged |

This is the existing hook error-isolation, generalized into a platform rule: **no
single guest can crash the host.**

## 4. Error taxonomy

| Kind | Handling |
|---|---|
| **Expected/domain** (validation, not-found, forbidden) | typed exceptions → proper status + envelope; not alarmed on |
| **Unexpected** (bugs, infra) | caught at the boundary → 500 + full log + alert |
| **Security** (authz denial, SSRF refusal, throttle) | denied + audited (feeds threat monitoring) |

## 5. Logging & audit split

- **Application logs** = diagnostics (levels, correlation id, context) for
  developers/ops.
- **Audit log** = business facts (who did what) for compliance
  ([13-Operations/logging-and-auditing.md](../13-Operations/logging-and-auditing.md)).
- Security-relevant errors feed both.

## 6. Fail closed

When in doubt, deny: an authorization check that errors denies; a payment whose
amount can't be reconciled parks for review rather than fulfilling; a webhook that
can't be verified is rejected. Safety defaults win over convenience.

---

## Related

- [README.md](README.md) · [authentication.md](authentication.md) · [authorization-rbac.md](authorization-rbac.md)
- [07-API/versioning-and-errors.md](../07-API/versioning-and-errors.md) · [13-Operations/logging-and-auditing.md](../13-Operations/logging-and-auditing.md)
