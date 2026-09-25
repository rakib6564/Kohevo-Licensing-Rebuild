# 07 — API Versioning & Error Format

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

The versioning scheme and the standard error envelope for the HTTP API. Both are
part of the **covered SDK surface** ([03-Standards](../03-Standards/versioning-and-compatibility.md))
— clients can rely on their stability.

---

## 1. Versioning

- **Path-versioned:** `/api/v1/…`. A backward-incompatible shape is a new
  namespace (`/api/v2`), never a mutation of `v1`.
- **Additive within a version:** new endpoints/fields are MINOR and don't break
  clients; removals/renames wait for a new version.
- **Deprecation window:** when `v2` ships, `v1` remains supported through the
  deprecation window ([03-Standards](../03-Standards/versioning-and-compatibility.md)),
  emitting a `Deprecation`/`Sunset` header.

## 2. Success envelope

```jsonc
// single resource
{ "data": { "id": 42, "type": "order", "total": { "amount": 2500, "currency": "USD" } } }

// collection (paginated)
{
  "data": [ /* … */ ],
  "meta": { "page": 1, "per_page": 25, "total": 137 },
  "links": { "next": "/api/v1/orders?page=2", "prev": null }
}
```

- **Money is always an object** `{amount, currency}` (integer minor units) — never
  a bare number (invariant #3).
- Pagination shape is stable and covered.

## 3. Error envelope

Every error — validation, auth, not-found, server — uses one shape:

```jsonc
{
  "error": {
    "code": "validation_failed",         // stable, machine-readable
    "message": "The request is invalid.", // human-readable, safe (never leaks internals)
    "correlation_id": "req_01H…",        // ties to server logs (10-Security/error-handling)
    "fields": {                           // present for validation errors
      "email": ["must be a valid email"],
      "amount": ["must be > 0"]
    }
  }
}
```

| HTTP | `code` examples | When |
|---|---|---|
| 400 | `bad_request`, `validation_failed` | malformed / invalid input |
| 401 | `unauthenticated` | missing/invalid token |
| 403 | `forbidden` | authenticated but policy denies |
| 404 | `not_found` | resource absent or not in tenant |
| 409 | `conflict` | version/state conflict |
| 422 | `unprocessable` | semantically invalid |
| 429 | `rate_limited` | over the rate limit (+ `Retry-After`) |
| 500 | `server_error` | unexpected; details logged, never returned |

- **No internal leakage.** Stack traces, SQL, and secrets never appear in a
  response; the `correlation_id` is how support ties a user report to the log
  entry ([10-Security/error-handling.md](../10-Security/error-handling.md)).
- **Tenant-safe 404.** A resource in another tenant returns `404`, not `403`, so
  existence isn't leaked across tenants.

## 4. Conventions

- `Content-Type: application/json`; UTF-8.
- Timestamps ISO-8601 UTC.
- Idempotency-Key header supported on unsafe methods for safe retries.

---

## Related

- [README.md](README.md) · [authentication.md](authentication.md) · [payments.md](payments.md)
- [03-Standards/versioning-and-compatibility.md](../03-Standards/versioning-and-compatibility.md) · [10-Security/error-handling.md](../10-Security/error-handling.md)
