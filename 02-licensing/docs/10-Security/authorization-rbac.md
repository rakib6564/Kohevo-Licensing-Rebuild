# 10 — Authorization & RBAC

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

Deciding *what* an authenticated principal may do. Authorization is separate from
[authentication](authentication.md): authn establishes identity, authz gates
actions. There is **one decision point** — the policy engine — that both web and
API go through.

---

## 1. The model

- **Permissions** are `<domain>.<action>` keys (`users.edit`, `shop.orders.view`,
  `membership.manage`). Modules **declare** their permissions in the manifest
  ([06-SDK/manifest.md](../06-SDK/manifest.md)); the full set is the union of core
  + active-module permissions.
- **Roles** are per-tenant named bundles of permissions. **Super Admin** (role 1)
  short-circuits to all permissions and is read-only.
- **Principals** (user/contact/API client) carry their resolved permission set
  ([authentication.md](authentication.md)).

## 2. One decision point: the policy engine

```php
interface Policy {
    public function can(Principal $p, string $permission, mixed $resource = null): bool;
}
```

- Every gate — admin page, service method, API endpoint — calls `can(...)`. No
  scattered `if ($role == 'admin')` checks.
- The [lifecycle](../01-Architecture/request-lifecycle.md) authorize step invokes
  it identically for web and API, which is why the API needs no parallel authz
  system.

```php
$policy->can($principal, 'shop.orders.refund', $order);   // may fail on permission OR ownership
```

## 3. Beyond flat permissions: resource & ownership checks

Flat role→permission is enough for admin CRUD but not for CRM/LMS/enterprise. The
policy engine also supports:

- **Resource-scoped** checks — "can edit *this* record" (e.g. a coach may manage
  only their assigned clients).
- **Ownership** checks — the principal owns/created the resource.
- **Tenant guard** — a resource in another tenant is invisible (returns not-found,
  not forbidden), reinforcing isolation.

Policies for a resource type are registered by the owning module and consulted by
the single engine, so the *rule* lives with the domain but the *decision* is
centralized.

## 4. Declaring & granting

- A module declares permissions in its manifest; on activation they become
  grantable in the roles editor (fixing today's dead `activate()` permission
  loop).
- Roles are edited per tenant; the union is always current because it derives from
  active modules' manifests, not a hardcoded list.

## 5. API scopes

API tokens carry scopes that **map onto** these permissions and can only *narrow*
the owner's rights ([07-API/authentication.md](../07-API/authentication.md)) —
never a separate grant table.

---

## Related

- [README.md](README.md) · [authentication.md](authentication.md) · [error-handling.md](error-handling.md)
- [06-SDK/manifest.md](../06-SDK/manifest.md) · [01-Architecture/request-lifecycle.md](../01-Architecture/request-lifecycle.md)
