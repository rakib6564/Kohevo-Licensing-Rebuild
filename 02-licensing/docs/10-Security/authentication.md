# 10 — Authentication

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

Proving *who* a principal is. Slate unifies its logins behind one `Authenticator`
with pluggable providers, so admin users, portal contacts, and API clients share
one hardened path rather than parallel implementations.

> **Problem being solved.** Today ([AUDIT-BRIEFING](../AUDIT-BRIEFING.md)) admin
> (`users`) and customer (`customers`) have separate login flows, and
> email-verification is ambiguously entangled with login. Unifying the
> authenticator and separating authn from verification removes both.

---

## 1. Principals

| Principal | Backed by | Logs in at |
|---|---|---|
| **Admin/staff user** | `users` | `/admin` |
| **Contact** (portal) | `contacts` + `identities` | `/portal` |
| **API client** | API token/OAuth client | `/api` ([07-API/authentication.md](../07-API/authentication.md)) |

All three resolve to a `Principal` with a tenant, an id, and a set of granted
permissions — one shape the [policy engine](authorization-rbac.md) consumes.

## 2. One Authenticator, pluggable providers

```php
interface Authenticator {
    public function attempt(Credentials $c): ?Principal;   // provider decides
    public function login(Principal $p): void;             // session/token issuance
    public function logout(): void;                        // CSRF-protected
}
```

| Provider | Mechanism |
|---|---|
| Password | bcrypt with **auto-rehash** on cost upgrade |
| OAuth2 / social | authorization-code + PKCE; SMTP OAuth already present |
| Magic link / passwordless | single-use hashed token (`identities`) |
| TOTP 2FA (optional) | second factor after primary success |

## 3. Hardening (carried forward + formalized)

- **Throttling & lockout** per client IP for *both* admin and portal (defaults
  10 attempts / 15 min).
- **Timing-attack mitigation** — the no-such-principal path runs a dummy
  `password_verify` so response timing doesn't reveal account existence.
- **Sessions** — HTTPS-only, HttpOnly, SameSite; **rotate the session id on
  login**; idle timeout enforced; logout requires a CSRF token (fixes today's GET
  logout).
- **Password reset / verification** — single-use, expiring, **hashed** tokens
  (only the plaintext leaves in the email link).

## 4. Email verification ≠ authorization

Verification proves an email is reachable; it is **not** a login gate or an
authorization signal. A module must never treat `email_verified` as permission
(this was an explicit footgun today). Gating sensitive actions is the
[policy engine](authorization-rbac.md)'s job, not a verification flag's.

## 5. Self-protection guards

Structural guards that survive from today: you cannot demote/suspend/delete
yourself or the last Super Admin; the Super Admin role is read-only. These are
enforced in the service layer, not the UI.

---

## Related

- [README.md](README.md) · [authorization-rbac.md](authorization-rbac.md) · [error-handling.md](error-handling.md)
- [02-Domain/identity-contacts.md](../02-Domain/identity-contacts.md) · [07-API/authentication.md](../07-API/authentication.md)
