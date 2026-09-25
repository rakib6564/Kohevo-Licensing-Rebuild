# 06 — Base Classes & Capability Contracts

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

This document defines the two halves of the SDK's *typed* surface:

1. **Base classes** you `extends` — the skeletons the kernel knows how to
   construct, wire, and call: `Module`, `Service`, `Repository`, `Entity`,
   `Block`, `Component`, `Controller`.
2. **Capability interfaces** you `implements` or resolve — the named contracts
   that let one module use another's power without naming its classes:
   `PaymentGateway`, `IdentityStore`, `MediaLibrary`, `NotificationChannel`,
   `ShippingRateProvider`, `BlockProvider`, `SearchIndex`, `SeoMetaProvider`.

Read [06-SDK/README.md](README.md) (the semver guarantee and the three channels)
and [01-Architecture §5](../01-Architecture/) (the decoupling contract) first.
The glossary terms used here — *Module, Service, Capability, Block, Component* —
carry exactly their [hub README](../README.md#canonical-glossary-fixed-vocabulary)
meanings.

> **The signatures in this document are illustrative PHP-ish sketches**, not the
> literal source. The *shapes* — what you extend, what you must implement, what
> the kernel guarantees to call — are the contract; the exact bodies live in the
> code and evolve under the [BC policy](../03-Standards/).

---

## 1. WHAT / WHY / HOW at a glance

| | Today (ambient coupling) | SDK (typed contracts) |
|---|---|---|
| **A module is** | a class `extends Slate\Plugin` with a `boot()` that imperatively wires everything | a class `extends Slate\Sdk\Module` whose *static* wiring is declared in the manifest; `boot()` only registers *dynamic* behavior |
| **Cross-module use** | `if (class_exists('PassesAPI')) PassesAPI::foo()` — names a concrete class in another module | `$gw = $this->capability(PaymentGateway::class)` — names a *contract*; the kernel supplies whichever module provides it |
| **Data access** | free-hand `Database::rows("SELECT … FROM other_table")` | a `Repository` you own, tenant-scoped by the base class, reachable only through your `Service` |
| **A value** | associative arrays passed everywhere | an `Entity` (typed, validated) and `Money` for amounts |
| **A content unit** | `Hook::addFilter('blocks.register', …)` returning ad-hoc arrays | a `Block` subclass with a declared field schema and a `render()` that composes `Component`s |

**WHY it matters:** the class-name coupling of v1 means renaming or retiring any
module breaks its consumers, and the kernel cannot know what a module offers
without *executing* it. Base classes + capability interfaces turn every one of
those implicit couplings into a versioned, discoverable, testable contract. This
is *reveal, don't rebuild* ([00-Vision §2](../00-Vision/)): `PassesAPI` was the
right instinct — a module exposing a stable surface — it just needs to graduate
from a global class into a named capability.

---

## 2. The base classes

All base classes live in the `Slate\Sdk\` namespace and are the **only**
platform classes a module may extend. The kernel constructs them via the service
container (constructor injection), so a module never calls `new` across a
boundary — [invariant #5](../01-Architecture/#7-architectural-invariants-must-always-hold).

### 2.1 `Module` — the package entry point

The successor to `Slate\Plugin`. A `Module` is a *guest*: it declares what it
provides and requires (in the [manifest](manifest.md)), and its `boot()` does the
minimum imperative wiring that cannot be expressed as data.

```php
namespace Slate\Sdk;

abstract class Module
{
    /**
     * The kernel injects a scoped context: the container (capability
     * resolution), the event bus, the extension registry, this module's
     * settings + asset helpers, and the current tenant. A module reaches the
     * platform ONLY through $ctx — there is no ambient global state.
     */
    public function __construct(protected ModuleContext $ctx) {}

    /**
     * Called once per request when the module is active, AFTER the kernel has
     * already read the manifest and wired routes/nav/permissions/subscriptions
     * declaratively. Use boot() only for wiring that is genuinely dynamic —
     * e.g. a subscription whose target depends on a setting.
     *
     * MUST NOT: emit output, run row-scanning queries, require() files outside
     * this module, or touch another module's tables/classes. (Same boot()
     * discipline as Plugin v1 — now the manifest removes most of its work.)
     */
    public function boot(): void {}

    /** Lifecycle hooks the kernel calls at install/activate/upgrade/uninstall. */
    public function migrate(MigrationRunner $run): void {}   // see building-a-module.md
    public function onActivate(): void {}
    public function onDeactivate(): void {}

    // --- The three channels, typed and injected (see §4 for the shapes) ---

    /** Channel 1: resolve a capability contract from the container. */
    protected function capability(string $contract): object
    {
        return $this->ctx->container->get($contract);
    }

    /** Channel 2: emit a past-tense domain fact. Fire-and-forget, queue-safe. */
    protected function emit(DomainEvent $event): void
    {
        $this->ctx->events->dispatch($event);
    }

    /** Channel 3: contribute to a present-tense value pipeline. */
    protected function extend(string $point, callable $contributor, int $priority = 10): void
    {
        $this->ctx->extensions->add($point, $contributor, $priority);
    }
}
```

The three `protected` helpers are the load-bearing design: **they are the only
doors out of a module.** There is deliberately no `->database()`, no
`->otherModule()`, no `Hook::` static. If you want out of your box, it is a
capability, an event, or an extension point — nothing else
([01-Architecture §5](../01-Architecture/)).

### 2.2 `Service` — a capability implementation

A `Service` is a container-resolved capability that owns its data and exposes
behavior. Business logic lives here; controllers and API resources are thin.
A service typically *implements a capability interface* (§4) so consumers depend
on the contract, not on the class.

```php
namespace Slate\Sdk;

abstract class Service
{
    public function __construct(protected ServiceContext $ctx) {}

    /** Services emit events for the facts they produce. */
    protected function emit(DomainEvent $event): void { $this->ctx->events->dispatch($event); }
}

// A concrete module service that PROVIDES a capability:
final class StripeGateway extends Service implements PaymentGateway
{
    public function __construct(ServiceContext $ctx, private ChargeRepository $charges) { parent::__construct($ctx); }

    public function charge(Money $amount, PaymentMethod $method, ChargeOptions $opts): ChargeResult
    {
        // …call Stripe, persist to $this->charges (a table THIS module owns)…
        $this->emit(new PaymentSucceeded($result->id, $amount, $opts->reference));
        return $result;
    }
}
```

**Boundary:** other code reaches a service's data only through its interface or
its events. A service never exposes a `Repository` or an `Entity` from another
module.

### 2.3 `Repository` — tenant-scoped data access

The single place SQL lives for a table. The base class **auto-scopes every query
to the current tenant** — you opt *out* loudly with `crossTenant()`, satisfying
[invariant #2](../01-Architecture/#7-architectural-invariants-must-always-hold).
This retires the free-hand `Database::rows()` calls scattered through v1 pages,
where tenant scoping was a thing each author had to remember.

```php
namespace Slate\Sdk;

/** @template T of Entity */
abstract class Repository
{
    public function __construct(protected RepositoryContext $ctx) {}

    /** The table this repository owns. MUST be prefixed with the module slug. */
    abstract protected function table(): string;

    /** Map a DB row to a typed Entity and back. */
    abstract protected function hydrate(array $row): Entity;
    abstract protected function dehydrate(Entity $e): array;

    // Base methods; every one silently injects `WHERE tenant_id = :currentTenant`
    protected function find(int $id): ?Entity { /* … */ }
    protected function query(): QueryBuilder { /* tenant-scoped */ }
    protected function save(Entity $e): Entity { /* insert/update, sets tenant_id */ }
    protected function delete(Entity $e): void { /* … */ }

    /** Explicit, audited escape hatch. Logs a warning; used by platform tooling only. */
    protected function crossTenant(): QueryBuilder { /* … */ }
}
```

A `Repository` may only name **its own** table. Reading another module's table is
an [architecture violation](../01-Architecture/#7-architectural-invariants-must-always-hold),
caught in review and eventually by lint. Cross-module data comes through a
capability or an event.

### 2.4 `Entity` — a typed domain object

Replaces the associative arrays passed everywhere in v1. An `Entity` is the
module's own model; it is **never** handed across a boundary (capability methods
take/return SDK value objects like `Money`, `ContactRef`, or DTOs, not another
module's entities).

```php
namespace Slate\Sdk;

abstract class Entity
{
    public readonly ?int $id;
    public readonly int $tenantId;        // always present; set by the Repository

    /** Money is always Money — invariant #3. No float, no DECIMAL, ever. */
    // e.g. public readonly Money $total;

    abstract public function validate(): ValidationResult;
}
```

### 2.5 `Block`, `Component`, `Controller` — presentation & dispatch

These three sit in the [render stack](../05-Rendering/) and the request
lifecycle. Their boundaries are fixed by the
[layer-boundary table](../README.md#layer-boundary-table-what-belongs-where).

```php
namespace Slate\Sdk;

/**
 * Component — a presentational, server-rendered UI primitive. Consumes ONLY
 * design tokens (--slate-*). No domain knowledge, no data access, no editable
 * content. (Layer-boundary table: "a widget with no memory of content".)
 */
abstract class Component
{
    abstract public function render(array $props): string;   // returns escaped HTML
}

/**
 * Block — an editable content unit: a declared FIELD SCHEMA plus a render()
 * that COMPOSES Components. Registered in the core Block Registry via the
 * blocks.register extension point (or the manifest `blocks[]` key). A Block
 * never lays out other blocks (that's a Section) and never touches the DB.
 */
abstract class Block
{
    /** Declares the editable fields — this schema drives the editor form. */
    abstract public function schema(): BlockSchema;

    /** Compose Components from validated field values into HTML. */
    abstract public function render(BlockData $data, RenderContext $ctx): string;
}

/**
 * Controller — a THIN dispatch target for a route. It validates input, calls a
 * Service, and returns a Response (rendered page or serialized resource). No
 * business logic lives here. Routes are declared in the manifest; the kernel
 * maps them to controller methods.
 */
abstract class Controller
{
    public function __construct(protected ControllerContext $ctx) {}
    // e.g. public function show(Request $req): Response { … $this->service->…(); }
}
```

The schema-driven `Block` is *reveal, don't rebuild* again: v1's content-builder
already treats "the editor canvas is a form driven by field definitions"
(see the Content Builder work in [MEMORY](../../)). The SDK formalizes that
instinct into a base class the core Block Registry understands.

---

## 3. Base class → glossary → home

| Base class | Glossary term | Owns | Must NOT | Detailed home |
|---|---|---|---|---|
| `Module` | Module (≡ Plugin) | one vertical's logic, tables, blocks, routes, UI | other modules' classes/tables | [plugin-architecture](../01-Architecture/) |
| `Service` | Service / Capability | one capability + its data + behavior | UI, another service's data | [service-layer](../01-Architecture/) |
| `Repository` | (Data Layer) | SQL for one owned table, tenant-scoped | another module's tables; un-scoped reads | [11-Database](../11-Database/) |
| `Entity` | (domain model) | typed state for one owned record | cross-boundary hand-off; float money | [11-Database](../11-Database/) |
| `Block` | Block | field schema + render composing Components | layout of other blocks; direct DB | [05-Rendering](../05-Rendering/) |
| `Component` | Component | presentational markup from tokens | editable content; data access | [04-Design-System](../04-Design-System/) |
| `Controller` | (dispatch) | thin route handler → Service → Response | business logic | [request-lifecycle](../01-Architecture/) |

---

## 4. Capability interfaces (the typed contracts)

A **capability** is a named contract a module *provides* or *requires*
([glossary](../README.md#canonical-glossary-fixed-vocabulary)). The kernel is the
broker: exactly one active module *provides* each capability; any module may
*require* and resolve it. The consumer names the **interface**, never the
provider class — that is the whole point.

```php
// v1 (ambient):                          // SDK (contract):
if (class_exists('StripePaymentAPI')) {   $gw = $this->capability(PaymentGateway::class);
    StripePaymentAPI::createCheckout(…);  $gw->charge($amount, $method, $opts);
}                                         // provider could be Stripe, Adyen, a mock — caller can't tell
```

Providers and requirers are declared in the [manifest](manifest.md)
(`provides[]` / `requires[]`) with **version constraints**, so the kernel refuses
to activate a module whose required capability version isn't satisfied — the
class-existence guess of `class_exists` becomes a checked dependency.

Each interface below is *versioned as a unit*. Adding an **optional** method with
a default is a MINOR bump; removing or retyping a method is a MAJOR bump under the
[BC policy](../03-Standards/).

### 4.1 The eight founding capabilities

| Capability | One-line purpose | Canonical provider | Primary consumers |
|---|---|---|---|
| `PaymentGateway` | charge/refund money through one contract | `stripe-payment` | booking, shop, membership |
| `IdentityStore` | resolve/attach the single Contact model | Identity service (core) | every module with people |
| `MediaLibrary` | store, pick, and reference media assets | core `Media` | content-builder, shop, forms |
| `NotificationChannel` | deliver a message over some transport | mailer / SMS / push modules | booking, membership, shop |
| `ShippingRateProvider` | quote shipping for a cart | `shipping-flat-rate` | shop |
| `BlockProvider` | contribute renderable content blocks | content-builder, any module | the render pipeline |
| `SearchIndex` | index and query documents | core Search (file/DB or driver) | content-builder, shop, CRM |
| `SeoMetaProvider` | supply meta/OG/canonical for a URL | `seo` | content-builder, shop, booking |

### 4.2 Key interface sketches

```php
namespace Slate\Sdk\Capability;

/** Payments flow ONLY through this contract — invariant #7. No module calls a
 *  provider SDK directly. Amounts are always Money (invariant #3). */
interface PaymentGateway
{
    public function charge(Money $amount, PaymentMethod $method, ChargeOptions $opts): ChargeResult;
    public function refund(ChargeRef $charge, ?Money $amount = null): RefundResult;  // null = full
    public function createCheckout(CheckoutRequest $req): CheckoutSession;
    public function mode(): PaymentMode;          // TEST | LIVE
    public function isConfigured(): bool;
}

/** The single identity model — one contacts row per person (invariant #4).
 *  Modules attach profiles keyed by contact_id; they never copy the person. */
interface IdentityStore
{
    public function find(int $contactId): ?ContactRef;
    public function resolveByEmail(string $email): ?ContactRef;
    public function upsert(ContactDraft $draft): ContactRef;   // emits contact.created / contact.updated
    public function attachProfile(int $contactId, string $module, array $profile): void;
}

/** Media as a capability — the media-library shim already models this. */
interface MediaLibrary
{
    public function store(UploadedFile $file, MediaOptions $opts): MediaRef;
    public function get(int $mediaId): ?MediaRef;
    public function pickerHandle(PickerOptions $opts): string;  // token for the JS picker
    public function reference(int $mediaId, UsageRef $usage): void;   // blocks deletion of in-use files
}

/** A transport for outbound messages. Many providers, one contract; the caller
 *  picks a channel by capability, not by class. */
interface NotificationChannel
{
    public function key(): string;                       // 'email' | 'sms' | 'push' | …
    public function supports(NotificationEnvelope $e): bool;
    public function deliver(NotificationEnvelope $e): DeliveryResult;
}

/** Shop asks "what does shipping cost?" without knowing who answers. Returning
 *  [] means "I don't quote this cart" — the shop falls back gracefully. */
interface ShippingRateProvider
{
    public function key(): string;
    /** @return ShippingQuote[] */
    public function quote(Cart $cart, Address $destination): array;
}

/** A module that ships content blocks. Registered blocks land in the core Block
 *  Registry; the render pipeline resolves them by type. */
interface BlockProvider
{
    /** @return class-string<\Slate\Sdk\Block>[] keyed by block type */
    public function blocks(): array;
}

/** Full-text search behind a swappable driver (file/DB default → optional
 *  engine) — the optionality principle (00-Vision §4). */
interface SearchIndex
{
    public function index(SearchDocument $doc): void;
    public function remove(string $type, int $id): void;
    public function search(SearchQuery $q): SearchResults;
}

/** Contributes head metadata for a URL. Composed by the render pipeline via the
 *  seo.meta extension point; the seo module is the canonical provider. */
interface SeoMetaProvider
{
    public function metaFor(UrlContext $url): SeoMeta;   // title, description, canonical, og:*, robots
}
```

### 4.3 Providing vs. requiring — the rule

- **Provide** a capability by writing a `Service` that `implements` the interface
  and declaring it in `provides[]`. The kernel binds the interface to your class
  in the container.
- **Require** a capability by declaring it in `requires[]` (with a version
  constraint) and resolving it with `$this->capability(Interface::class)`.
- **Optional dependency?** Declare it in `requires[]` as `optional: true` and
  guard with `$this->ctx->container->has(Interface::class)` — the typed successor
  to `class_exists()` + `PluginLoader::isActive()`. The graceful-skip behavior of
  v1 survives; the class-name guessing does not.

Multiple providers of the *same* capability (v1's `shipping-flat-rate` vs.
`flat-rate-shipping` clash from [AUDIT-BRIEFING §6](../AUDIT-BRIEFING.md)) become
a declared, kernel-detected conflict rather than a silent
last-writer-wins race — or, where the capability is inherently many-provider
(notification channels, shipping quoters), the kernel exposes them as a *set* the
consumer iterates.

---

## 5. Definition of done for a contract

A base-class subclass or capability implementation is SDK-conformant when:

- [ ] It extends exactly one SDK base class / implements a documented capability
      interface — nothing internal.
- [ ] It reaches the platform only through the three channels (`capability` /
      `emit` / `extend`); no cross-module class or table names appear.
- [ ] Every persistence call is tenant-scoped (or an audited `crossTenant()`).
- [ ] All money is `Money`; all people are `ContactRef` / `contact_id`.
- [ ] Its capability version constraints are declared in the
      [manifest](manifest.md).
- [ ] It has unit tests against the *contract* (mock the capability, assert the
      emitted events) — see [building-a-module.md](building-a-module.md) and
      [12-Testing](../12-Testing/).

---

## 6. Related

- [manifest.md](manifest.md) — how `provides[]` / `requires[]` declare these
  contracts with version constraints.
- [building-a-module.md](building-a-module.md) — these classes assembled into a
  working module the right way.
- [event-catalogue.md](event-catalogue.md) — the events a `Service` emits and the
  extension points a `Module` contributes to.
- [03-Standards](../03-Standards/) — coding standards and the deprecation/BC
  policy that governs every signature here.
- [01-Architecture](../01-Architecture/) — the kernel, service layer, and the
  invariants these contracts enforce.
- [11-Database](../11-Database/) — `Repository`, `Entity`, migrations, `Money`.
