# SKILL: Studio Plugin — Build Guide (v2, reconciled to real code)

> **Supersedes** `SKILL_studio_plugin.md` (v1). v1's design is sound; its APIs were
> written against the architecture *doc*, not the implementation. This v2 is
> copy-paste-runnable against the actual Slate codebase (Phase 2A baseline
> `fbb6906`). Every signature/schema/hook below is quoted from live code.
> **Author:** reconciliation pass · **Date:** 2026-08-11

---

## 0. Ground rules (what changed from v1)

- **Flat PHP, custom kernel** — not Laravel/WordPress. No Eloquent, no Blade, no build step.
- **`requires_core: ">=1.0.0"`** (SLATE_VERSION is `1.0.0`; v1's `>=2.0.0` fails the install gate).
- **Hooks are WordPress-style POSITIONAL** (`doAction('e', $a,$b,$c)` → callback gets `$a,$b,$c`), not single `$data` arrays.
- **Contact ids are BIGINT** (Phase 2A). Every `*_id` that references a person is `bigInt()->unsigned()`.
- **Schema builder is custom** — no `unsignedInteger/timestamps()/time()`. Use `int()->unsigned()`, `datetime()->useCurrent()`, etc.
- **Money** = `Money::of()`, `->times()`, `->minor`, `->toDecimal()` (no `multiply/fromCents/cents/format`).
- **No `createService/createPlan/createDefinition/createPage`** — insert rows directly or use the real methods.
- **Members are core `customers`/`contacts`** (id-preserved). Don't build a separate student identity table — use `contact_id`.

The five hardest breaks from v1, fixed throughout:
1. `booking_created` = `($id,$serviceId,$providerId)` — no meta. Link occurrences yourself after `createAppointment` returns `['id'=>…]`.
2. Membership emits **no** events — drive activation from your own code + `stripe_webhook_event`.
3. Money uses `->times()`, not `->multiply()`.
4. Insert `membership_plans`/`booking_services`/`forms_definitions` rows directly (no create* facades).
5. `Contact` has no `meta` accessor — read `contacts.meta` raw if you must store DOB.

---

## 1. Bootstrap

### 1.1 `plugins/studio/plugin.json`
```json
{
  "name": "Studio Management",
  "slug": "studio",
  "version": "1.0.0",
  "description": "Dance/arts studio: classes, enrollment, families, recitals, costumes.",
  "author": "…",
  "requires_core": ">=1.0.0",
  "permissions": [
    "studio.manage_classes", "studio.manage_recitals",
    "studio.manage_costumes", "studio.view_reports"
  ]
}
```
> Manifest keys the loader validates: `slug, name, version, description, author` (+ optional `author_url, requires_core, works_better_with, permissions`). `requires_plugins` is **not** enforced — guard cross-plugin calls with `class_exists('BookingAPI')` at runtime instead.

### 1.2 `plugins/studio/Studio.php` (main class — real hook names + arities)
```php
<?php
class Studio extends \Plugin
{
    public function boot(): void
    {
        \Hook::addFilter('admin_nav_items',            [self::class, 'adminNav']);
        \Hook::addFilter('admin_dashboard_widgets',    [self::class, 'adminWidgets']);
        \Hook::addFilter('customer_dashboard_widgets', [self::class, 'portalWidgets']);
        \Hook::addFilter('public_routes',              [self::class, 'publicRoutes']);
        \Hook::addAction('content_register_blocks',    [self::class, 'registerBlocks']);   // NOT 'register_blocks'

        // Cross-plugin reactions — POSITIONAL args:
        \Hook::addAction('booking_created',    [self::class, 'onBookingCreated']);  // ($id,$serviceId,$providerId)
        \Hook::addAction('customer_registered',[self::class, 'onCustomerRegistered']); // ($customerId)
        \Hook::addAction('forms_submitted',    [self::class, 'onFormSubmitted']);   // ($submissionId,$formId,$data)
        \Hook::addAction('stripe_webhook_event',[self::class,'onStripeEvent']);     // ($event array)  ← membership emits nothing
    }

    public static function adminNav(array $nav): array {
        $nav['studio'] = ['label' => 'Studio', 'icon' => '💃', 'items' => [
            ['label' => 'Classes',  'url' => SLATE_URL.'/admin/studio/classes.php'],
            ['label' => 'Families', 'url' => SLATE_URL.'/admin/studio/families.php'],
            ['label' => 'Recitals', 'url' => SLATE_URL.'/admin/studio/recitals.php'],
        ]];
        return $nav;
    }

    public static function portalWidgets(array $w): array {
        $w[] = ['title' => 'My Classes', 'view' => __DIR__.'/resources/views/portal/my-classes.php'];
        return $w;
    }

    public static function publicRoutes(array $routes): array {
        // PublicRouter passes $_GET['_route_prefix'] + $_GET['_route_path'] to the handler.
        $routes['studio'] = ['handler' => __DIR__.'/public/router.php', 'methods' => ['GET','POST']];
        return $routes;
    }

    public static function registerBlocks(string $registry): void {
        // $registry === BlockRegistry::class — register into it:
        // \BlockRegistry::register('studio_class_list', [...]);
    }

    // POSITIONAL signatures — match the emitter exactly:
    public static function onBookingCreated(int $id, int $serviceId, int $providerId): void {
        // booking_created carries no meta. If this appointment is a class occurrence,
        // you linked it yourself when you created it (see BookingAdapter below).
    }
    public static function onCustomerRegistered(int $customerId): void { /* … */ }
    public static function onFormSubmitted(int $submissionId, int $formId, array $data): void {
        $form = \FormsAPI::getForm(''); // or Database::row('SELECT slug FROM forms_definitions WHERE id=?',[$formId])
        // match on the form's slug, then StudioAPI::processWaiver($data) etc.
    }
    public static function onStripeEvent(array $event): void {
        // $event = raw Stripe event; branch on $event['type'] for studio checkouts.
    }
}
```

### 1.3 `plugins/studio/StudioAPI.php` (facade — signatures)
```php
<?php
class StudioAPI
{
    // Families / roles (studio-local; see §0 family decision)
    public static function createFamily(int $primaryParentId, array $studentIds): int { }
    public static function assignContactRole(int $contactId, string $role): void { }     // student|parent|instructor|guardian
    public static function getFamilyByParent(int $parentId): ?array { }
    public static function familyStudentIds(int $familyId): array { }

    // Class series
    public static function createClassSeries(array $data): int { }
    public static function generateOccurrences(int $seriesId): int { }                    // returns count created
    public static function getActiveClassSeries(): array { }

    // Enrollment
    public static function enrollStudent(int $studentId, int $seriesId, array $opts = []): array { } // ['ok'=>bool,...]
    public static function dropEnrollment(int $enrollmentId, string $reason = ''): bool { }
    public static function addToWaitlist(int $studentId, int $seriesId): bool { }

    // Billing (uses Money + MembershipAPI + StripePaymentAPI)
    public static function calculateTuition(int $studentId, int $seriesId): \Slate\Support\Money { }

    // Attendance
    public static function markAttendance(int $occurrenceId, array $studentStatuses): void { }
}
```

---

## 2. Migration — `db/migrations/0004_studio_core.php` (real Schema API)

> Lives in **`db/migrations/`** (global, numeric-ordered), NOT inside the plugin — the runner
> discovers `db/migrations/NNNN_*.php`. Apply with `php bin/migrate migrate`. No `--plugin` flag.

```php
<?php
declare(strict_types=1);
use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $s->create('studio_contact_roles', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->bigInt('contact_id')->unsigned();                 // contacts.id is BIGINT
            $t->enum('role', ['student','parent','instructor','guardian'])->default('student');
            $t->json('meta')->nullable();
            $t->datetime('created_at')->useCurrent();
            $t->index(['tenant_id','contact_id'], 'idx_tenant_contact');
            $t->index(['tenant_id','role'], 'idx_tenant_role');
        });

        $s->create('studio_families', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->bigInt('primary_parent_id')->unsigned();          // contacts.id
            $t->string('family_name', 128)->nullable();
            $t->json('billing_address')->nullable();
            $t->json('meta')->nullable();
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at')->useCurrent();
            $t->index(['tenant_id','primary_parent_id'], 'idx_tenant_parent');
        });

        $s->create('studio_family_members', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->bigInt('family_id')->unsigned();
            $t->bigInt('contact_id')->unsigned();
            $t->enum('relation', ['child','spouse','guardian'])->default('child');
            $t->datetime('created_at')->useCurrent();
            $t->index(['tenant_id','family_id'], 'idx_tenant_family');
            $t->index(['tenant_id','contact_id'], 'idx_tenant_contact');
        });

        $s->create('studio_class_series', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->string('name', 128);
            $t->string('style', 32);                              // ballet|jazz|hiphop|tap|contemporary|acro
            $t->string('level', 32)->nullable();                  // beginner|intermediate|advanced
            $t->tinyInt('age_min')->unsigned()->default(0);
            $t->tinyInt('age_max')->unsigned()->default(99);
            $t->int('capacity')->unsigned()->default(20);
            $t->bigInt('instructor_id')->unsigned();              // contacts.id (role=instructor)
            $t->int('room_id')->unsigned();                       // booking_resources.id (INT)
            $t->int('booking_service_id')->unsigned()->nullable();// mapped booking_services.id
            $t->int('membership_plan_id')->unsigned()->nullable();// mapped membership_plans.id (plan_type=course)
            $t->tinyInt('day_of_week');                           // 0=Sun … 6=Sat (PHP date('w'))
            $t->string('start_time', 8);                          // 'HH:MM' (builder has no time() type)
            $t->string('end_time', 8);
            $t->date('session_start');
            $t->date('session_end');
            $t->int('price_cents')->unsigned();
            $t->string('currency', 8)->default('USD');
            $t->boolean('is_pro_rated')->default(true);
            $t->boolean('is_active')->default(true);
            $t->json('meta')->nullable();
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at')->useCurrent();
            $t->index(['tenant_id','style','level'], 'idx_tenant_style_level');
            $t->index(['tenant_id','day_of_week'], 'idx_tenant_dow');
        });

        $s->create('studio_class_occurrences', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->bigInt('series_id')->unsigned();
            $t->int('appointment_id')->unsigned()->nullable();    // booking_appointments.id (INT)
            $t->date('occurrence_date');
            $t->string('start_time', 8);
            $t->string('end_time', 8);
            $t->enum('status', ['scheduled','cancelled','completed'])->default('scheduled');
            $t->datetime('created_at')->useCurrent();
            $t->index(['tenant_id','series_id','occurrence_date'], 'idx_tenant_series_date');
        });

        $s->create('studio_enrollments', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->bigInt('student_id')->unsigned();                 // contacts.id
            $t->bigInt('series_id')->unsigned();
            $t->int('subscription_id')->unsigned()->nullable();   // membership_subscriptions.id (INT)
            $t->date('enrolled_at');
            $t->date('dropped_at')->nullable();
            $t->enum('status', ['active','inactive','waitlist','trial','dropped'])->default('active');
            $t->json('meta')->nullable();
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at')->useCurrent();
            $t->index(['tenant_id','student_id','status'], 'idx_tenant_student_status');
            $t->index(['tenant_id','series_id','status'], 'idx_tenant_series_status');
        });

        $s->create('studio_attendance', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->bigInt('occurrence_id')->unsigned();
            $t->bigInt('student_id')->unsigned();
            $t->enum('status', ['present','absent','excused','late'])->default('present');
            $t->text('notes')->nullable();
            $t->bigInt('marked_by')->unsigned()->nullable();      // instructor contact id
            $t->datetime('marked_at')->useCurrent();
            $t->unique(['tenant_id','occurrence_id','student_id'], 'uniq_occurrence_student');
        });
    }

    public function down(Schema $s): void
    {
        foreach (['studio_attendance','studio_enrollments','studio_class_occurrences',
                  'studio_class_series','studio_family_members','studio_families',
                  'studio_contact_roles'] as $t) $s->dropIfExists($t);
    }
};
```
> Recital/costume/ticket tables go in a later `0005_studio_recitals.php` — same API. Note `booking_resources.id`, `booking_appointments.id`, `booking_services.id`, `membership_*.id`, `membership_subscriptions.id` are all **INT UNSIGNED** (legacy), while contact ids are **BIGINT** — type each FK to match its target.

---

## 3. Domain layer

### 3.1 Value objects (PHP 8.4 enums — fine)
```php
namespace Slate\Module\Studio\Domain;
enum DanceStyle: string { case BALLET='ballet'; case JAZZ='jazz'; case HIPHOP='hiphop'; case TAP='tap'; case CONTEMPORARY='contemporary'; case ACRO='acro'; }
enum ClassLevel: string { case BEGINNER='beginner'; case INTERMEDIATE='intermediate'; case ADVANCED='advanced'; }
```

### 3.2 Occurrence generator (thin layer over the series)
```php
final class ClassOccurrenceGenerator {
    /** @return array[] rows ready to insert into studio_class_occurrences */
    public function generate(array $series): array {   // $series = a studio_class_series row
        $out = [];
        $cursor = new \DateTimeImmutable($series['session_start']);
        $end    = new \DateTimeImmutable($series['session_end']);
        while ((int)$cursor->format('w') !== (int)$series['day_of_week']) $cursor = $cursor->modify('+1 day');
        while ($cursor <= $end) {
            $out[] = [
                'tenant_id' => (int)$series['tenant_id'], 'series_id' => (int)$series['id'],
                'occurrence_date' => $cursor->format('Y-m-d'),
                'start_time' => $series['start_time'], 'end_time' => $series['end_time'],
                'status' => 'scheduled',
            ];
            $cursor = $cursor->modify('+7 days');
        }
        return $out;
    }
}
```

### 3.3 Tuition (Money — real API)
```php
use Slate\Support\Money;
final class TuitionCalculator {
    public function calculate(array $series, int $activeCountForStudent, int $siblingCount): Money {
        $base = new Money((int)$series['price_cents'], $series['currency']);   // ctor(minor, currency)
        $base = match (true) {                                                 // multi-class → ->times()
            $activeCountForStudent >= 4 => $base->times(0.80),
            $activeCountForStudent >= 3 => $base->times(0.85),
            $activeCountForStudent >= 2 => $base->times(0.90),
            default                     => $base,
        };
        $base = match (true) {                                                 // family sibling discount
            $siblingCount >= 2 => $base->times(0.85),
            $siblingCount >= 1 => $base->times(0.90),
            default            => $base,
        };
        return $base;   // ->minor for cents, ->toDecimal() / (string) for display
    }
    // Proration (Membership has NONE — Studio owns it):
    public function prorate(Money $full, \DateTimeImmutable $sessionStart, \DateTimeImmutable $sessionEnd): Money {
        $total     = max(1, (int)$sessionStart->diff($sessionEnd)->days);
        $remaining = max(0, (int)(new \DateTimeImmutable())->diff($sessionEnd)->days);
        return $full->times(min(1.0, $remaining / $total));
    }
}
```

---

## 4. Integration adapters (REAL signatures + return shapes)

### 4.1 Booking
```php
final class BookingAdapter {
    /** Create the booking appointment for a class occurrence + link it back. */
    public function bookOccurrence(array $series, array $occ): array {
        if (!class_exists('BookingAPI')) return ['ok'=>false,'error'=>'booking inactive'];

        $starts = $occ['occurrence_date'].' '.$occ['start_time'].':00';
        // createAppointment REQUIRES a valid customer_email (no '' internal bookings) and returns key 'id'.
        $res = \BookingAPI::createAppointment([
            'service_id'     => (int)$series['booking_service_id'],   // you created/linked this row
            'provider_id'    => $this->providerIdFor((int)$series['instructor_id']), // booking_providers.id, not contacts.id
            'starts_at'      => $starts,
            'customer_name'  => $series['name'].' (class)',
            'customer_email' => $this->classInboxEmail($series),      // a real address you own
            'source'         => 'admin',
            'recurrence_group' => 'studio_series_'.$series['id'],     // supported; ignore ends_at/resource_id/meta (not read)
        ]);
        if (!empty($res['ok'])) {
            \Database::update('studio_class_occurrences', ['appointment_id'=>(int)$res['id']], 'id = ?', [(int)$occ['id']]);
        }
        return $res;   // ['ok'=>true,'id'=>int,'ref'=>string,'status'=>...] | ['ok'=>false,'error'=>string]
    }
}
```
> ⚠️ Booking `provider_id` is a **`booking_providers.id`**, not a contact id — maintain a mapping (or store `booking_provider_id` on the series). Room enforcement needs the mapped `booking_services` row to have `requires_resource=1` + a `booking_service_resources(service_id, resource_id)` link; conflicts are then checked via `BookingAPI::freeResourceId(int $serviceId, int $startTs, int $endTs, int $partySize=1)` (unix timestamps).

### 4.2 Membership (no create-plan facade; no events)
```php
final class MembershipAdapter {
    /** A studio class ↔ a membership 'course' plan. Insert the plan row directly. */
    public function ensureCoursePlan(array $series): int {
        $existing = \Database::value(
            "SELECT id FROM membership_plans WHERE tenant_id=? AND plan_type='course' AND course_id=?",
            [(int)$series['tenant_id'], (int)$series['id']]
        );
        if ($existing) return (int)$existing;
        return \Database::insert('membership_plans', [
            'tenant_id'    => (int)$series['tenant_id'],
            'name'         => $series['name'],
            'plan_type'    => 'course',
            'course_id'    => (int)$series['id'],          // loose link → studio_class_series.id
            'price_cents'  => (int)$series['price_cents'],
            'currency'     => $series['currency'],
            'duration_days'=> 30,
            'session_quota'=> 8,
            'grace_days'   => 7,
            'is_active'    => 1,
        ]);
    }
    public function purchaseFor(int $customerId, int $planId): array {
        return \MembershipAPI::purchase($customerId, $planId, false);  // real signature; check its return keys
    }
}
```
> Membership emits **no hooks**. To react to activation, either (a) call `MembershipAPI::activateSubscription($subId)` from your own flow after payment, or (b) subscribe to `stripe_webhook_event` and match your studio metadata. `membership_subscriptions.customer_id == contact_id` (id-preserved).

### 4.3 Forms (contact linking is real; no createDefinition)
```php
final class FormsAdapter {
    public function ensureWaiver(int $tenantId): string {
        $slug = "studio_waiver_{$tenantId}";
        if (\Database::row("SELECT id FROM forms_definitions WHERE tenant_id=? AND slug=?", [$tenantId,$slug])) return $slug;
        \Database::insert('forms_definitions', [
            'tenant_id' => $tenantId, 'slug' => $slug, 'title' => 'Studio Liability Waiver',
            'fields_json' => json_encode([
                ['type'=>'signature','label'=>'Parent/Guardian Signature','required'=>true],
                ['type'=>'date','label'=>'Date Signed','required'=>true],
            ]),
            'submit_label' => 'I Agree',
        ]);
        return $slug;
    }
}
// Contact linking on submit: FormsAPI::upsertContact(int $tenantId, ?string $email, array $data): void
// React via forms_submitted($submissionId, $formId, $data) — resolve the form by id/slug (no 'form_slug' in payload).
```

### 4.4 Payments → the shared ledger
```php
// Charge through stripe-payment; it records into stripepayment_charges and emits stripe_webhook_event.
if (class_exists('StripePaymentAPI') && \StripePaymentAPI::isConfigured()) {
    $sess = \StripePaymentAPI::createCheckout($lineItems, $opts);   // createCheckout(array $lineItems, array $opts): array
    // on webhook: \StripePaymentAPI::recordCharge([...]) → ledger id; membership_subscriptions.charge_id points at it.
}
```

---

## 5. Data access, portal & auth (real primitives)

- **Fetch a contact:** `$tenants = new \Slate\Tenancy\TenantContext(); $contacts = new \Slate\Services\Identity\ContactRepository($tenants); $c = $contacts->find($id);` → a `Contact` object (`->id, ->displayName, ->primaryEmail, ->primaryPhone` — **no `meta`**; read `contacts.meta` raw if needed).
- **New tenant-scoped tables:** either raw `Database::rows/row/value/insert/update/delete` (what all plugins do today) **or** pilot `Slate\Data\Repository` (auto-scopes `tenant_id`): `final class EnrollmentRepository extends \Slate\Data\Repository { protected string $table='studio_enrollments'; }`.
- **Portal gate:** `Auth::requireCustomer();` `$parentId = Auth::customerId();` (== contact id). Scope every query by `Auth::customerId()` + `current_tenant_id()` (no auto family-scoping exists yet).
- **Admin gate:** `Auth::requirePerm('studio.manage_classes');` (emits 403 itself — no `abort()`, no `Auth::user()->can()`).
- **Public routes:** handler reads `$_GET['_route_prefix']` / `$_GET['_route_path']`; `config.php` is already loaded.

---

## 6. Testing (dependency-free harness — no PHPUnit)

- **Unit** → `tests/unit/StudioTuitionTest.php` (pure Money math; autoloader-only, no DB):
```php
use Slate\Support\Money;
unit('multi-class + sibling discounts stack (Money::times)', function () {
    $calc = new \Slate\Module\Studio\Domain\TuitionCalculator();
    $base = ['price_cents'=>10000,'currency'=>'USD'];
    assert_eq(9000, $calc->calculate($base, 2, 0)->minor());   // 2nd class → 10% off
    assert_eq(8100, $calc->calculate($base, 2, 1)->minor());   // + 1 sibling → 10% off
});
```
- **Integration** → `tests/integration/StudioEnrollmentTest.php` (boots app+DB; throwaway tenant; create family → series → course plan → enroll → assert; clean up). Wire nothing new — the runner globs `tests/integration/*Test.php`. `bash tests/run.sh` must stay green (currently 89 / 23 / 21, growing).

---

## 7. Build roadmap (verified batches)

| Batch | Deliverable | Gate |
|---|---|---|
| **0** | Activate `booking` + `stripe-payment`; confirm Stripe config | admin plugins |
| **1** | `0004_studio_core` migration + `Studio.php`/`StudioAPI` skeleton + families/roles + class-series CRUD | dry-run + suite green |
| **2** | Occurrence generator + `BookingAdapter` (provider mapping, room link) | integration parity |
| **3** | Enrollment flow + `MembershipAdapter` (course plan, purchase) + `stripe_webhook_event` activation | end-to-end test |
| **4** | Tuition + **proration** (studio-owned) | unit parity |
| **5** | Parent portal (catalog/enroll/schedule) via `customer_dashboard_widgets` + `public_routes` | live gate |
| **6** | Attendance + teacher view | — |
| **7** | `0005_studio_recitals` (recitals/costumes/tickets) + admin wizards | — |
| **8** | Robo-automation (needs a scheduler — there is no `cron_daily` hook; add one) | — |

**Postponed (per owner):** Elementor freeform, JS widgets, collab/realtime, animation builder, marketplace, dozens of blocks, AI generation, framework migration.

---

*Canonical build reference for the Studio plugin against the real Slate codebase. Keep in sync with the API deltas in the reconciliation notes.*
