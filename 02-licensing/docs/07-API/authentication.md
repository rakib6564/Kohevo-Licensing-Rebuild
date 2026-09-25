# 07 — API Authentication

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

How API clients prove identity and how their access maps onto the same
authorization model as the web app. The API shares the platform's
[Auth](../10-Security/authentication.md) and [RBAC](../10-Security/authorization-rbac.md)
services — API auth is a *credential type*, not a parallel permission system.

---

## 1. Credential types

| Type | For | Lifetime |
|---|---|---|
| **Personal Access Token (PAT)** | scripts, server-to-server integrations | long-lived, revocable |
| **OAuth2 client credentials** | trusted backend apps (a tenant's own integration) | short access + refresh |
| **OAuth2 authorization code** | third-party apps acting for a user (v5 marketplace) | short access + refresh |

All tokens are stored hashed; the plaintext is shown once at creation. Tokens are
tenant-scoped and carry an owning principal (a user or an API client).

## 2. Scopes ↔ RBAC

An API token carries **scopes** that map to the same `<domain>.<action>`
permissions the [policy engine](../10-Security/authorization-rbac.md) uses:

```
scope "shop.orders.read"  →  permission check can(principal, 'shop.orders.view', order)
```

- A token can never exceed its owner's permissions; scopes *narrow*, never widen.
- The [lifecycle](../01-Architecture/request-lifecycle.md) authorize step is
  identical for web and API — one decision point, one policy engine. This is why
  headless/SaaS is "free": no second authz system to keep in sync.

## 3. Request flow

```
Authorization: Bearer <token>
   → resolve token (hashed lookup) → principal + tenant + scopes
   → lifecycle: tenant context, authenticate, authorize (policy engine), dispatch
```

Unauthenticated requests to protected resources return `401`; authenticated but
unauthorized return `403`, both in the standard [error envelope](versioning-and-errors.md).

## 4. Rate limiting

- Per-token and per-IP limits ([10-Security](../10-Security/)), with a
  shared-hosting-friendly default driver (DB/APCu) and a Redis driver at scale
  (ADR-0012).
- Limits are returned in headers (`X-RateLimit-*`); `429` on exceed.

## 5. Security posture

- Tokens transmitted only over HTTPS (force-HTTPS + HSTS).
- Scoped, revocable, auditable — every issue/revoke/use-failure is audited.
- OAuth2 flows follow current best practice (PKCE for auth-code, short access
  tokens, rotating refresh). Full detail in [10-Security/authentication.md](../10-Security/authentication.md).

---

## Related

- [README.md](README.md) · [webhooks.md](webhooks.md) · [versioning-and-errors.md](versioning-and-errors.md)
- [10-Security/authentication.md](../10-Security/authentication.md) · [10-Security/authorization-rbac.md](../10-Security/authorization-rbac.md)
