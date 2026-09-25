# 08 — Search

**Status:** Draft · **Applies to:** Slate v3.x

## Purpose

Cross-module search: let any module make its entities searchable through one
contract, with a shared-hosting-friendly default engine and an optional heavier
one. Admins and end users search products, pages, contacts, bookings, and courses
from one place.

## Bounded context

**Cross-cutting** ([02-Domain](../02-Domain/)). This is a **core service**
(`Slate\Services\Search`), **not a module** — it exposes the `SearchIndex` contract
every module indexes into. It is documented in the Modules section only because
modules interact with it heavily ([README §Catalogue](README.md)).

## Consumes

| Service / capability | For |
|---|---|
| Events | reindex on entity create/update/delete |
| Data | the index storage (default driver) |
| RBAC | results filtered to what the principal may see |

## Provides

- The `SearchIndex` capability + drivers:

```php
interface SearchIndex {
    public function index(string $type, string|int $id, array $doc): void;
    public function remove(string $type, string|int $id): void;
    public function query(string $q, SearchScope $scope): SearchResults;
}
```

## How modules integrate

A module makes an entity searchable by contributing a **document** for it — no
module writes another module's index, and the search module knows nothing about
any vertical's schema:

- On `product.updated` / `page.published` / `contact.created`, the owning module
  (or a small indexer subscribing to those events) calls `index(type, id, doc)`.
- Results are **tenant-scoped** and **permission-filtered** before display.

## Drivers (ADR-0012 optionality)

| Driver | Posture | Notes |
|---|---|---|
| **MySQL FULLTEXT** | shared hosting (default) | no extra infrastructure; good to mid-scale |
| **External engine** (Meilisearch/Elasticsearch) | VPS/enterprise | typo-tolerance, facets, scale |

Swapping drivers is configuration; the `SearchIndex` contract and every module's
indexing code stay identical.

## Owns

- `search_documents` (+ FULLTEXT indexes) for the default driver, tenant-scoped.
- **Does NOT own** the source entities — it holds derived documents; the source of
  truth stays in each module.

## Degradation

If FULLTEXT is unavailable on a host, search degrades to a bounded `LIKE` rather
than hard-failing ([13-Operations/shared-hosting-compatibility.md](../13-Operations/shared-hosting-compatibility.md)).

---

## Related

- [../06-SDK/base-classes-and-contracts.md](../06-SDK/base-classes-and-contracts.md) · [../13-Operations/shared-hosting-compatibility.md](../13-Operations/shared-hosting-compatibility.md) · [../14-ADR/0012-swappable-driver-interfaces.md](../14-ADR/0012-swappable-driver-interfaces.md)
