# 10 — Security Architecture

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

The platform's security model: trust boundaries, defense-in-depth, and the
principle that safety is **structural** — you opt *out* loudly, never opt *in*
silently. Realizes the *secure-and-multi-tenant-by-default* vision principle
([00-Vision](../00-Vision/)).

> **Baseline today** ([AUDIT-BRIEFING](../AUDIT-BRIEFING.md)) is genuinely
> decent: CSRF tokens, bcrypt+rehash, per-IP login throttling, AES-256-GCM secret
> encryption, prepared statements, MIME-checked uploads, open-redirect guard,
> Forms SSRF guard. This section formalizes that into one model and closes the
> gaps (manual tenant scoping, email-verify-as-auth confusion, ad-hoc guards).

---

## Section contents

- **[authentication.md](authentication.md)** — proving who a principal is.
- **[authorization-rbac.md](authorization-rbac.md)** — deciding what they may do.
- **[error-handling.md](error-handling.md)** — failing safely, leaking nothing.

---

## 1. Trust boundaries

```
Internet ── Cloudflare/edge ── Apache/.htaccess ── Front Controller
   └─ untrusted input ─┘         └─ tenant resolve ─┘  └─ authn ─ authz ─ service ─ data
```

Everything left of the service layer is untrusted. Input is validated at the
boundary; authorization happens before any service call; the data layer enforces
tenant isolation beneath that.

## 2. Defense in depth

| Layer | Control |
|---|---|
| Transport | force-HTTPS + HSTS; secure/HttpOnly/SameSite cookies |
| Edge/headers | CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy |
| Request | CSRF tokens (timing-safe), rate limiting, idle-session timeout |
| AuthN | bcrypt+rehash, throttling, timing-attack mitigation, optional 2FA ([authentication.md](authentication.md)) |
| AuthZ | central policy engine; resource + ownership checks ([authorization-rbac.md](authorization-rbac.md)) |
| Data | automatic tenant scoping; prepared statements ([../11-Database](../11-Database/)) |
| Secrets | AES-256-GCM envelope encryption at rest; keys from env |
| Uploads | MIME validation; PHP-off upload dirs; SVG script-strip |
| Egress | SSRF guard on all outbound HTTP ([../07-API/webhooks.md](../07-API/webhooks.md)) |
| Audit | every sensitive action recorded ([../13-Operations/logging-and-auditing.md](../13-Operations/logging-and-auditing.md)) |

## 3. Secrets management

- Provider keys, webhook secrets, and tokens are encrypted at rest
  (`enc:v1:` AES-256-GCM) via the app secret; publishable/public keys stay
  plaintext by design.
- The app secret and DB credentials come from the environment, never the repo
  ([13-Operations/deployment.md](../13-Operations/deployment.md)).

## 4. Input & output

- **Output escaped by default** in the rendering layer; raw output is an explicit,
  reviewed exception.
- **Input validated** at the boundary (typed request objects); never trust client
  data for authorization (e.g. never trust a posted `tenant_id` or price).
- **CSV/formula-injection** neutralized on exports; **open redirects** rejected via
  a safe-redirect allowlist.

## 5. Threat model highlights

| Threat | Mitigation |
|---|---|
| Cross-tenant data access | automatic scoping + audited `crossTenant` (#2) |
| Privilege escalation | central policy engine; self-protection guards |
| Payment tampering | amount reconciliation in the gateway ([../07-API/payments.md](../07-API/payments.md)) |
| SSRF via webhooks/imports | scheme + private-IP refusal, IP pinning |
| Account enumeration | uniform responses + dummy verify timing |
| Session hijack | HTTPS-only, HttpOnly/SameSite, idle timeout, rotation on login |

---

## Related

- [authentication.md](authentication.md) · [authorization-rbac.md](authorization-rbac.md) · [error-handling.md](error-handling.md)
- [07-API/authentication.md](../07-API/authentication.md) · [11-Database](../11-Database/) · [13-Operations](../13-Operations/)
