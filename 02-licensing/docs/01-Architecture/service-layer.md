# 01 — Service Layer (The Platform Standard Library)

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

A **Service** is a container-resolved capability with a stable interface, owning
its data and exposing behavior plus events (see the [canonical
glossary](../README.md#canonical-glossary-fixed-vocabulary)). The service layer
is Slate's "standard library": Identity, Data, Payments, Media, SEO, Cache,
Settings, Notifications, Search, and the rest live here. **Business logic lives
in services; controllers and API resources are thin.** This document specifies
what a service *is*, how it is registered and resolved, the boundary it must
hold, and how a module integrates with it.

This realizes *Kernel + Contracts + Modules* ([00-Vision
§3](../00-Vision/README.md)) and ADR-0004; it is the layer the
[kernel](kernel.md) resolves and the [pipeline](request-lifecycle.md) dispatches
into.

---

## 1. What the service layer replaces

Today "services" exist only as convention: business logic is spread across
`admin/*.php` page scripts, and cross-cutting capabilities are found by
`class_exists('BookingAPI')` on a **concrete** global class. The concrete
problems this document fixes:

| Today | Consequence | Service-layer answer |
|---|---|---|
| Logic lives inside `admin/booking.php`, `customer/*.php`, storefront scripts | The same rule is re-implemented per entry point; web ≠ API behavior | Logic lives in a service; every entry point calls the **same** method |
| `class_exists('ShopAPI')` / `new BookingAPI()` | Coupling to a concrete class; no interface, no versioning | `get(Catalog::class)` resolves an **interface** to whichever module provides it |
| `ShopAPI::createOrder()` and the cart compute totals via **two code paths** | Divergent results (the audit's coupon/total bug) | One service method is the single source of a computed value |
| A page reads another module's table directly | Cross-module coupling; [invariant 1](README.md#7-architectural-invariants-must-always-hold) violation | A service owns its tables; others reach it via its interface or its events |
| Email sent inline inside the request | Slow requests, no retry | The service **emits an event**; delivery happens after-response (see [event-system.md](event-system.md)) |

The service layer is **not** a rewrite. Today's `ShopAPI` / `BookingAPI` /
`MembershipAPI` facades are already proto-services; this document formalizes them
into interface-first, container-resolved capabilities.

---

## 2. What a service is (and is not)

A service is **the owner of one cross-cutting capability and its data**, reachable
only through a published interface.

| A service IS | A service is NOT |
|---|---|
| An interface (`PaymentGateway`, `Catalog`, `Identity`) with a stable contract | A grab-bag of static helpers |
| The single owner of its tables | A reader of another service's or module's tables |
| The place business rules and invariants live | A place for HTML, `$_GET`, or `echo` |
| Resolved from the container by interface | Constructed with `new` across a boundary ([invariant 5](README.md#7-architectural-invariants-must-always-hold)) |
| A producer of **value objects / DTOs** and **events** | A returner of raw PDO rows or presentation strings |
| Tenant-scoped automatically through the [Data Layer](../11-Database/) | A hand-writer of `AND tenant_id = ?` (see [multi-tenancy.md](multi-tenancy.md)) |

**The two ways to reach a service** — and the only two:

1. **Call its interface** (synchronous need): `get(Catalog::class)->find($sku)`.
2. **React to its events** (a fact happened): subscribe to `order.paid`.

A service never exposes its tables, its concrete class, or its internals to
another module. This is the boundary that makes 50+ modules tractable
(ADR-0005).

---

## 3. Interface-first registration & resolution

Every service is defined **interface first**. The interface is the contract; the
concrete class is an implementation detail the container hides.

```php
// The contract — published on the SDK surface, semver'd (ADR-0009).
interface Catalog
{
    public function find(Sku $sku): ?Product;
    public function search(CatalogQuery $q): ProductPage;
    public function priceOf(Sku $sku, Quantity $qty): Money;   // returns Money — invariant 3
}
```

A module **provides** the capability by binding the interface to its concrete
implementation in `register()` (see [kernel.md §5](kernel.md#5-how-a-module-integrates-with-the-kernel)):

```php
public function register(Container $c): void
{
    $c->singleton(CatalogService::class, fn ($c) => new CatalogService(
        $c->get(Repository::class),   // tenant-scoped data layer — never raw PDO
        $c->get(EventBus::class),     // to emit facts
    ));
    // Declare the capability: the interface resolves to this provider.
    $c->alias(Catalog::class, CatalogService::class);
}
```

A **consumer** resolves the interface — never the provider class:

```php
$catalog = $c->get(Catalog::class);   // provider is whoever the manifest wired
$price   = $catalog->priceOf($sku, $qty);
```

**Why interface-first, not `class_exists`:**

| Property | `class_exists('ShopAPI')` today | `get(Catalog::class)` |
|---|---|---|
| Binds to | a concrete global class | a contract |
| Swap the provider | impossible without editing callers | change the manifest wiring only |
| Two providers | silent last-one-wins | **refused at boot** (ambiguity is an error — [kernel §2.5](kernel.md#25-resolution-rules-invariants)) |
| Missing provider | runtime `false`, late failure | fatal, named error at activation ([plugin-architecture §resolver](plugin-architecture.md)) |
| Versioning | none | capability version + constraint (`^1.0`) |

---

## 4. Thin controllers, fat services

The [dispatch stage](request-lifecycle.md#47-dispatch-thin-controller--api-resource)
hands off to a **thin** handler. Its whole job is *validate input → call a
service → map the result to a response*. It holds **no business logic and no DB
access**.

```php
final class CheckoutController
{
    public function __construct(private Checkout $checkout) {}   // injected interface

    public function submit(Request $req): Response
    {
        $cmd = PlaceOrderCommand::fromRequest($req);   // validate + map input
        $order = $this->checkout->place($cmd);         // ALL logic lives here
        return Response::redirect(route('order.show', $order->id));
    }
}
```

The identical service call backs the API resource — the divergence is only in
what each does with the return value:

```php
final class OrderResource
{
    public function __construct(private Checkout $checkout) {}

    public function create(ApiRequest $req): JsonResponse
    {
        $order = $this->checkout->place(PlaceOrderCommand::fromApi($req));
        return JsonResponse::created(OrderView::of($order));
    }
}
```

Because both tails call the **same** `Checkout::place()`, a capability shipped on
the web is on the API for free, and both inherit tenancy, auth, and
authorization from the [one pipeline](request-lifecycle.md#6-web-vs-api-one-pipeline-two-tails).
This is the structural fix for today's web-vs-API and cart-vs-order divergence.

**The litmus test:** if deleting a controller would lose a business rule, the
rule was in the wrong place.

---

## 5. Services own data and emit events

A service is the **sole writer** of its tables and the **single source** of any
value computed from them. It never lets another component read those tables; it
publishes what others need two ways:

- **Return value objects** for synchronous callers (`Money`, `Order`, `Product`
  — never raw rows).
- **Emit past-tense events** for anyone who reacts to a change of state.

```php
final class Checkout
{
    public function place(PlaceOrderCommand $cmd): Order
    {
        $order = $this->orders->create($cmd);         // owns the orders table
        $this->payments->capture($order->total());    // via PaymentGateway contract (invariant 7)

        $this->bus->emit(new OrderPaid(               // a fact — many listeners
            orderId:  $order->id,
            tenantId: $order->tenantId,
            total:    $order->total(),                 // Money, always (invariant 3)
        ));
        return $order;
    }
}
```

What listens to `order.paid` — the notifications service (receipt email), the
membership module (grant access), the search service (index) — is **none of
Checkout's business**. Checkout states the fact; the [event
system](event-system.md) fans it out, off the request path and queue-safely. A
service that instead called `Mailer::send()` inline would be reaching across a
boundary and blocking the request — exactly today's synchronous-send problem.

**Ownership rule of thumb:** a table has exactly one owning service; that
service's interface and its events are the *only* public doors to that data.

---

## 6. The standing services (the platform library)

These are the capabilities the kernel expects to exist; per-service detail lives
in the sibling section noted.

| Service | Capability (interface) | Owns | Detailed home |
|---|---|---|---|
| Identity / Contacts | `Identity` | the single `contacts` row + profiles (invariant 4) | [02-Domain](../02-Domain/) |
| Auth | `Authenticator` | sessions, credentials, throttling | [10-Security](../10-Security/) |
| RBAC / Policy | `PolicyEngine` | the single `can()` decision | [10-Security](../10-Security/) |
| Tenancy | `TenantContext` | tenant resolution + scope | [multi-tenancy.md](multi-tenancy.md) |
| Data Layer | `Repository`, migrations | scoped persistence primitives | [11-Database](../11-Database/) |
| Settings | `Settings` | tenant-scoped key/value | [13-Operations](../13-Operations/) |
| Media | `MediaLibrary` | `media_files` / `media_usage` | [05-Rendering](../05-Rendering/) |
| Payments | `PaymentGateway` | provider brokerage (invariant 7) | [07-API](../07-API/) |
| Notifications | `Notifier` | delivery + templates | [event-system.md](event-system.md) |
| SEO | `SeoResolver` | meta/OG/sitemap contribution | [05-Rendering](../05-Rendering/) |
| Search | `SearchIndex` | index + query (swappable driver) | [13-Operations](../13-Operations/) |
| Cache | `Cache` | the four tiers (swappable driver) | [13-Operations](../13-Operations/) |
| Jobs / Cron | `Queue`, `Scheduler` | background work | [13-Operations](../13-Operations/) |
| Audit / Log | `AuditLog` | the actor/action trail | [10-Security](../10-Security/) |
| I18n | `Translator` | 3-layer string resolution | [05-Rendering](../05-Rendering/) |

Each is a **swappable interface** where scale demands (cache, queue, search,
tenancy storage) per the optionality principle (ADR-0012) — a file/APCu default
on shared cPanel, a heavier driver on a VPS, *without touching a module*.

---

## 7. Boundaries (what a service must never do)

- **No presentation.** No HTML, no `echo`, no `$_GET`/`$_SESSION` — request
  state arrives as injected, request-scoped values ([kernel §2.4](kernel.md#24-scopes)).
- **No cross-service data reads.** A service reaches another service only through
  its interface or its events — never its tables or concrete class.
- **No `new` of a collaborator.** Collaborators are injected; the container
  constructs ([invariant 5](README.md#7-architectural-invariants-must-always-hold)).
- **No raw money.** Every monetary value is a `Money` object end to end
  ([invariant 3](README.md#7-architectural-invariants-must-always-hold)).
- **No manual tenant scoping.** Scoping is automatic through the base repository;
  opting out is explicit and audited (invariant 2, [multi-tenancy.md](multi-tenancy.md)).

---

## 8. Related documents

- [kernel.md](kernel.md) — the container that registers and resolves services
- [request-lifecycle.md](request-lifecycle.md) — the dispatch stage that calls a service
- [event-system.md](event-system.md) — the facts services emit and the filters they honor
- [plugin-architecture.md](plugin-architecture.md) — how a module provides/requires a capability
- [multi-tenancy.md](multi-tenancy.md) — the automatic scoping every service inherits
- [11-Database](../11-Database/) — the repository/data layer services own their tables through
- [06-SDK](../06-SDK/) — the semver'd interface surface services are published on
- ADR-0004 (kernel + contracts), ADR-0005 (events + contracts), ADR-0012 (swappable drivers)
