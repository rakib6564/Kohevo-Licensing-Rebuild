# 06 — Building a Module (Walkthrough)

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

A step-by-step build of an example module — a simple **Donations** module — done
the right way. It demonstrates the three communication channels, the definition
of done, and how the pieces from the rest of the SDK fit together. Follow this
shape and your module is decoupled, tenant-safe, and upgradeable by construction.

---

## 0. Scaffold

```bash
php bin/make module donations
```

Generates the standard layout ([plugin-architecture.md](../01-Architecture/plugin-architecture.md#1-what-a-module-is)):
`module.json`, `src/DonationsModule.php`, `migrations/`, `views/`, `assets/`,
`tests/`.

## 1. Declare wiring in the manifest

Everything static is data ([manifest.md](manifest.md)) — this module *consumes*
identity + payments and *provides* a donation block:

```jsonc
{
  "slug": "donations", "name": "Donations", "version": "1.0.0",
  "requires_core": ">=1.0.0",
  "requires": [ "identity@1", "payments@1" ],
  "permissions": [ { "key": "donations.view", "label": "View donations" } ],
  "routes":  [ { "prefix": "/donate", "handler": "DonateController", "methods": ["GET","POST"] } ],
  "blocks":  [ "donation-form" ],
  "subscribes": [ "payment.succeeded" ]
}
```

## 2. Migration (owns its own table)

```php
// migrations/0001_create_donations.php
final class CreateDonations extends Migration {
    public function up(Schema $s): void {
        $s->create('donations_donations', function (Table $t) {
            $t->id();
            $t->tenantId();                 // every domain table is tenant-scoped
            $t->foreignId('contact_id');    // the donor is a core Contact — never a copy
            $t->money('amount');            // Money value object → integer minor units
            $t->string('status', 20);
            $t->timestamps();
        });
    }
    public function down(Schema $s): void { $s->drop('donations_donations'); }
}
```

## 3. Repository + Service

```php
// src/DonationRepository.php  — persistence, auto tenant-scoped by the base class
final class DonationRepository extends Repository {
    protected string $table = 'donations_donations';
}

// src/DonationService.php  — business logic; resolves capabilities from the container
final class DonationService {
    public function __construct(
        private DonationRepository $repo,
        private IdentityStore $identity,      // requires identity@1
        private PaymentGateway $payments,     // requires payments@1  (NOT StripePaymentAPI)
    ) {}

    public function start(string $email, Money $amount): PaymentIntent {
        $contact = $this->identity->resolveOrCreate($email);   // one contacts row
        $id = $this->repo->insert(['contact_id'=>$contact->id, 'amount'=>$amount, 'status'=>'pending']);
        return $this->payments->createIntent($amount, ['ref' => "donation:$id"]); // generic context
    }
}
```

Note what's absent: no `class_exists('StripePaymentAPI')`, no other module's
tables, no manual `AND tenant_id`. The gateway is asked for by *contract*.

## 4. Controller (thin) + Block

```php
final class DonateController extends Controller {
    public function post(Request $r): Response {
        $intent = $this->service->start($r->input('email'), Money::fromMinor($r->int('amount'), 'USD'));
        return $this->json(['client_secret' => $intent->clientSecret]);
    }
}
```

The `donation-form` block ([05-Rendering/blocks-and-sections.md](../05-Rendering/blocks-and-sections.md))
is a field-schema + a render that composes Components — no bespoke HTML document.

## 5. React to the fact (async channel)

Reconcile by **listening**, not by the gateway calling back into this module:

```php
// in boot() — the only imperative wiring
Events::on('payment.succeeded', function (PaymentSucceeded $e) {
    if (str_starts_with($e->ref, 'donation:')) {
        $this->service->markPaid((int) explode(':', $e->ref)[1]);
    }
});
```

## 6. Tests (definition of done)

```php
it('creates one contact and a Money-typed donation', function () {
    $svc = app(DonationService::class);
    $intent = $svc->start('a@b.com', Money::fromMinor(2500, 'USD'));
    expect(Contacts::count())->toBe(1);              // invariant #4
    expect($repo->latest()->amount)->toBeInstanceOf(Money::class); // invariant #3
});
```

Cover money, auth, and tenancy paths; pass architecture-conformance
([12-Testing](../12-Testing/architecture-conformance.md)).

---

## The three channels, seen in one module

| Channel | Where above |
|---|---|
| **Capability contract** (sync) | `PaymentGateway`, `IdentityStore` injected (§3) |
| **Event** (async fact) | `payment.succeeded` listener (§5) |
| **Extension point** (shape data) | the `donation-form` block via `blocks.register` (§4) |

No fourth channel exists. If your module needs another module's *class* or
*table*, stop — the design is wrong.

---

## Related

- [manifest.md](manifest.md) · [base-classes-and-contracts.md](base-classes-and-contracts.md) · [event-catalogue.md](event-catalogue.md)
- [03-Standards/module-development-standards.md](../03-Standards/module-development-standards.md) · [15-Contributing](../15-Contributing/)
