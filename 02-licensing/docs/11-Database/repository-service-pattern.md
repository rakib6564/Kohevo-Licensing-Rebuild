# 11 — Repository / Service Pattern

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

The layering that keeps persistence safe and business logic testable:
**Repositories** own data access (and auto-scope tenants), **Services** own
business logic (and emit events), **Controllers** stay thin. This is where
invariant #2 (tenant scoping) becomes structural rather than remembered.

---

## 1. The three roles

```
Controller (thin)  →  Service (logic + events)  →  Repository (persistence, tenant-scoped)
```

| Role | Owns | Never |
|---|---|---|
| Repository | one entity's queries; tenant scoping; hydration | business rules; other entities' tables |
| Service | orchestration; invariants; emits domain events | SQL; HTTP concerns |
| Controller / API resource | parse request, call a service, format response | business logic |

Because both web and API controllers call the **same services**, headless/API is
free ([07-API](../07-API/README.md)).

## 2. The base repository (automatic tenant scoping)

```php
abstract class Repository {
    protected string $table;

    protected function query(): QueryBuilder {
        return (new QueryBuilder($this->table))->where('tenant_id', TenantContext::id()); // always
    }

    public function find(int $id): ?Entity { return $this->query()->where('id',$id)->first(); }
    public function all(): array           { return $this->query()->get(); }
    public function insert(array $a): int  { $a['tenant_id'] = TenantContext::id(); /* … */ }

    /** The ONLY way to escape tenant scope — greppable + audited. */
    public function crossTenant(callable $fn): mixed {
        Audit::log('data.cross_tenant', static::class);
        return TenantContext::withoutScope($fn);
    }
}
```

- An author writing `$this->all()` **cannot** forget the tenant predicate — they
  never write it. (Fixes the manual `AND tenant_id = ?` leak.)
- Crossing tenants is explicit, logged, and searchable — the complete list of
  every deliberate escape is `grep crossTenant`.

## 3. A concrete repository

```php
final class OrderRepository extends Repository {
    protected string $table = 'shop_orders';

    public function paidSince(DateTimeImmutable $t): array {
        return $this->query()->where('status','paid')->where('paid_at','>=',$t)->get();
    }
}
```

Only queries the module's own table (invariant #1); tenant scope is inherited.

## 4. Services emit events, don't call modules

```php
final class OrderService {
    public function __construct(private OrderRepository $repo, private PaymentGateway $pay) {}

    public function place(Cart $cart, Contact $buyer): Order {
        $order = $this->repo->create($cart->toOrder($buyer));   // Money-typed totals
        Events::dispatch(new OrderPlaced($order->id, $buyer->id, $order->total));
        return $order;
    }
}
```

Other modules react to `OrderPlaced` ([06-SDK/event-catalogue.md](../06-SDK/event-catalogue.md));
the service never reaches into another module.

## 5. Entities & `Money`

- Rows hydrate into typed entities; money columns hydrate to `Money`
  ([schema-conventions.md](schema-conventions.md)), so a total is *never* a float
  in application code.
- Value objects (Money, EmailAddress, PhoneNumber) are used at the boundary, not
  primitive strings/ints.

## 6. Testability

Repositories are the only thing touching the DB, so services are unit-testable
with an in-memory/fake repository, and the tenant-scope guarantee is asserted
once at the base-repository level ([12-Testing/architecture-conformance.md](../12-Testing/architecture-conformance.md)).

---

## Related

- [README.md](README.md) · [migrations.md](migrations.md) · [schema-conventions.md](schema-conventions.md)
- [01-Architecture/multi-tenancy.md](../01-Architecture/multi-tenancy.md) · [01-Architecture/service-layer.md](../01-Architecture/service-layer.md)
