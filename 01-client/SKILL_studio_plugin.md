# SKILL: Studio Plugin Development Guide

> **Target:** Slate Platform (Phase 2A → 2B transition)  
> **Scope:** Build a `plugins/studio/` vertical that delivers Dance-Studio-Pro parity by extending existing active/inactive plugins (Booking, Membership, Forms, Content-Builder, stripe-payment).  
> **Author:** Architecture Team  
> **Date:** 2026-08-11

---

## 0. Executive Summary

Your platform already owns **~70 %** of the required studio functionality:

| Studio Need | Already In-Tree | Gap |
|-------------|----------------|-----|
| Class scheduling | `booking` (services, providers, rooms, availability, conflict engine) | Recurring class-series generator |
| Enrollment / plans | `membership` (`plan_type='course'`, `course_id`, `session_quota`) | Proration for mid-term joins |
| Attendance / quotas | `membership` (`sessionStats()`, `recentAttendance()`, `courseAccess()`) | — |
| Instructors | `booking` providers + `timeclock` | Instructor↔class roster UI |
| Skill levels | `membership` `skillLevels()` + plan metadata | — |
| Billing | `stripe-payment` (checkout, webhooks, charges) | Proration engine |
| Forms / waivers | `forms` (JSON builder, e-signature, webhooks) | Contact prefill |
| Portal | Core `customer/*` + `customer_dashboard_widgets` filter | — |
| Content pages | `content-builder` (block registry, query-driven blocks) | — |
| **Family accounts** | **None** | `contact_relationships` / household (this plugin builds it) |
| **Contact roles** | **None** (admin RBAC only) | Contact tags/roles (this plugin builds it) |

**Therefore, this is NOT a greenfield build.** It is an **integration plugin** that:
1. Fills the two structural gaps (family grouping + contact roles).
2. Adds a thin "class series" layer on top of Booking.
3. Adds a proration layer on top of Membership + stripe-payment.
4. Exposes a parent/teacher portal via the existing `customer_dashboard_widgets` hook.

---

## 1. Plugin Bootstrap

### 1.1 Directory Layout

```
plugins/studio/
├── plugin.json
├── Studio.php                 # Main plugin class
├── StudioAPI.php              # Static facade for cross-plugin calls
├── install.sql                # Initial schema (self-heal; migrations are preferred)
├── public/
│   └── router.php             # Portal routes (clean URLs)
├── src/
│   ├── Domain/
│   │   ├── Classes/
│   │   │   ├── ClassSeries.php
│   │   │   ├── ClassOccurrence.php
│   │   │   └── ClassRepository.php
│   │   ├── Students/
│   │   │   ├── Student.php
│   │   │   ├── Family.php
│   │   │   └── FamilyRepository.php
│   │   ├── Enrollment/
│   │   │   ├── Enrollment.php
│   │   │   ├── Waitlist.php
│   │   │   └── TuitionCalculator.php
│   │   ├── Billing/
│   │   │   └── ProrationEngine.php
│   │   ├── Recital/
│   │   │   ├── Recital.php
│   │   │   ├── Act.php
│   │   │   └── LineupBuilder.php
│   │   └── Costumes/
│   │       ├── Costume.php
│   │       └── Measurement.php
│   ├── Infrastructure/
│   │   ├── Migrations/
│   │   │   ├── 0001_CreateStudioTables.php
│   │   │   └── 0002_CreateRecitalTables.php
│   │   └── Portal/
│   │       ├── ParentPortalController.php
│   │       ├── ClassCatalogController.php
│   │       └── SelfServiceEnroller.php
│   └── Integration/
│       ├── BookingAdapter.php
│       ├── MembershipAdapter.php
│       ├── FormsAdapter.php
│       └── ContentBuilderAdapter.php
├── resources/
│   └── views/                 # Server-rendered PHP templates
│       ├── portal/
│       │   ├── dashboard.php
│       │   ├── class-catalog.php
│       │   ├── enrollment-form.php
│       │   ├── schedule.php
│       │   └── recital-tickets.php
│       └── admin/
│           ├── class-manager.php
│           ├── recital-wizard.php
│           └── costume-console.php
└── tests/
    ├── unit/
    └── integration/
```

### 1.2 plugin.json

```json
{
  "name": "Studio Management",
  "slug": "studio",
  "version": "1.0.0",
  "requires_core": ">=2.0.0",
  "permissions": [
    "studio_manage_classes",
    "studio_manage_recitals",
    "studio_manage_costumes",
    "studio_view_reports",
    "studio_teacher_login"
  ],
  "requires_plugins": ["booking", "membership", "forms"],
  "suggests_plugins": ["stripe-payment", "content-builder", "timeclock"]
}
```

### 1.3 Main Plugin Class

```php
<?php
// plugins/studio/Studio.php

class Studio extends \Plugin
{
    public function boot(): void
    {
        // Admin Navigation
        Hook::addAction('admin_nav_items', [self::class, 'injectAdminNav']);

        // Portal Widgets
        Hook::addFilter('customer_dashboard_widgets', [self::class, 'injectPortalWidgets']);

        // Public Routes
        Hook::addFilter('public_routes', [self::class, 'registerPortalRoutes']);

        // Cross-Plugin Reactions
        Hook::addAction('booking_created', [self::class, 'onBookingCreated']);
        Hook::addAction('membership_activated', [self::class, 'onMembershipActivated']);
        Hook::addAction('forms_submitted', [self::class, 'onFormSubmitted']);

        // Content Builder Blocks
        Hook::addAction('register_blocks', [self::class, 'registerContentBlocks']);

        // Admin Dashboard Widgets
        Hook::addAction('admin_dashboard_widgets', [self::class, 'injectAdminWidgets']);

        // Teacher Portal (if timeclock active)
        if (class_exists('TimeclockAPI')) {
            Hook::addFilter('teacher_portal_widgets', [self::class, 'injectTeacherWidgets']);
        }
    }

    public static function injectAdminNav(array $nav): array
    {
        $nav['studio'] = [
            'label' => 'Studio',
            'icon'  => '💃',
            'items' => [
                ['label' => 'Classes',      'url' => '/admin/studio/classes'],
                ['label' => 'Students',     'url' => '/admin/studio/students'],
                ['label' => 'Families',     'url' => '/admin/studio/families'],
                ['label' => 'Recitals',     'url' => '/admin/studio/recitals'],
                ['label' => 'Costumes',     'url' => '/admin/studio/costumes'],
                ['label' => 'Reports',      'url' => '/admin/studio/reports'],
            ],
        ];
        return $nav;
    }

    public static function injectPortalWidgets(array $widgets): array
    {
        $widgets[] = ['title' => 'My Classes',    'view' => __DIR__.'/resources/views/portal/widgets/my-classes.php'];
        $widgets[] = ['title' => 'My Schedule',   'view' => __DIR__.'/resources/views/portal/widgets/schedule.php'];
        $widgets[] = ['title' => 'Quick Actions', 'view' => __DIR__.'/resources/views/portal/widgets/quick-actions.php'];
        return $widgets;
    }

    public static function registerPortalRoutes(array $routes): array
    {
        $routes['studio/catalog']       = __DIR__.'/public/catalog.php';
        $routes['studio/enroll']        = __DIR__.'/public/enroll.php';
        $routes['studio/schedule']      = __DIR__.'/public/schedule.php';
        $routes['studio/payments']      = __DIR__.'/public/payments.php';
        $routes['studio/tickets']       = __DIR__.'/public/tickets.php';
        $routes['studio/costumes']      = __DIR__.'/public/costumes.php';
        return $routes;
    }

    public static function onBookingCreated(array $data): void
    {
        if (!empty($data['meta']['studio_series_id'])) {
            StudioAPI::linkOccurrenceToSeries(
                appointmentId: $data['appointment_id'],
                seriesId:      $data['meta']['studio_series_id'],
                occurrenceDate: $data['starts_at']
            );
        }
    }

    public static function onMembershipActivated(array $data): void
    {
        $plan = MembershipAPI::getPlan($data['plan_id']);
        if ($plan && $plan['plan_type'] === 'course') {
            StudioAPI::activateEnrollment(
                studentId: $data['customer_id'],
                classId:   $plan['course_id'],
                subscriptionId: $data['subscription_id']
            );
        }
    }

    public static function onFormSubmitted(array $data): void
    {
        $formSlug = $data['form_slug'] ?? '';
        match ($formSlug) {
            'studio_waiver'      => StudioAPI::processWaiverSubmission($data),
            'studio_measurement' => StudioAPI::processMeasurementSubmission($data),
            default              => null,
        };
    }

    public static function registerContentBlocks(array $blocks): array
    {
        $blocks['studio_class_list']       = __DIR__.'/src/Integration/Blocks/ClassListBlock.php';
        $blocks['studio_recital_playbook'] = __DIR__.'/src/Integration/Blocks/RecitalPlaybookBlock.php';
        $blocks['studio_ticket_purchase']  = __DIR__.'/src/Integration/Blocks/TicketPurchaseBlock.php';
        return $blocks;
    }
}
```

### 1.4 Static API Facade

```php
<?php
// plugins/studio/StudioAPI.php

class StudioAPI
{
    // Class Series
    public static function createClassSeries(array $data): array { }
    public static function generateOccurrences(int $seriesId): array { }
    public static function getClassCatalog(int $tenantId, ?int $studentId = null): array { }

    // Enrollment
    public static function enrollStudent(int $studentId, int $classId, array $opts = []): array { }
    public static function addToWaitlist(int $studentId, int $classId): bool { }
    public static function dropEnrollment(int $enrollmentId, string $reason = ''): bool { }
    public static function activateEnrollment(int $studentId, int $classId, int $subscriptionId): void { }

    // Family / Roles
    public static function createFamily(int $primaryParentId, array $studentIds): int { }
    public static function assignContactRole(int $contactId, string $role): void { }
    public static function getFamilyMembers(int $familyId): array { }

    // Billing
    public static function calculateTuition(int $studentId, int $classId): Money { }
    public static function prorateTuition(Money $fullTuition, DateTimeImmutable $startDate, DateTimeImmutable $sessionEnd): Money { }
    public static function runAutoPay(int $tenantId): array { }

    // Attendance
    public static function markAttendance(int $occurrenceId, array $studentStatuses): void { }
    public static function getAttendanceReport(int $classId, string $from, string $to): array { }

    // Recital
    public static function buildRecitalLineup(int $recitalId): array { }
    public static function detectQuickChanges(int $recitalId): array { }
    public static function sellTicket(int $recitalId, int $familyId, int $qty): array { }

    // Costume
    public static function assignCostume(int $costumeId, int $studentId, int $classId): void { }
    public static function recommendSize(int $studentId, string $vendor): string { }
    public static function processMeasurementSubmission(array $formData): void { }

    // Forms
    public static function processWaiverSubmission(array $formData): void { }
    public static function linkOccurrenceToSeries(int $appointmentId, int $seriesId, string $occurrenceDate): void { }
}
```


---

## 2. Database Schema (Migrations)

Use the **core migration framework** (`Slate\Data\Migration`). Run via `bin/migrate`.

### 2.1 Base Migration

```php
<?php
// plugins/studio/src/Infrastructure/Migrations/0001_CreateStudioTables.php

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        // Contact Roles (fills Phase-2B gap)
        $s->create('studio_contact_roles', function (Table $t) {
            $t->id();
            $t->unsignedInteger('tenant_id');
            $t->unsignedInteger('contact_id');
            $t->string('role', 32); // 'student','parent','instructor','guardian'
            $t->json('meta')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'contact_id']);
            $t->index(['tenant_id', 'role']);
        });

        // Families / Households (fills Phase-2B gap)
        $s->create('studio_families', function (Table $t) {
            $t->id();
            $t->unsignedInteger('tenant_id');
            $t->unsignedInteger('primary_parent_id'); // contacts.id
            $t->string('family_name', 128)->nullable();
            $t->json('billing_address')->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'primary_parent_id']);
        });

        $s->create('studio_family_members', function (Table $t) {
            $t->id();
            $t->unsignedInteger('tenant_id');
            $t->unsignedInteger('family_id');
            $t->unsignedInteger('contact_id'); // student or secondary parent
            $t->string('relation', 32); // 'child','spouse','guardian'
            $t->timestamps();
            $t->index(['tenant_id', 'family_id']);
            $t->index(['tenant_id', 'contact_id']);
        });

        // Class Series (thin layer over booking)
        $s->create('studio_class_series', function (Table $t) {
            $t->id();
            $t->unsignedInteger('tenant_id');
            $t->string('name', 128);
            $t->string('style', 32); // 'ballet','jazz','hiphop','tap','contemporary','acro'
            $t->string('level', 32)->nullable(); // 'beginner','intermediate','advanced'
            $t->unsignedTinyInteger('age_min')->default(0);
            $t->unsignedTinyInteger('age_max')->default(99);
            $t->unsignedInteger('capacity')->default(20);
            $t->unsignedInteger('instructor_id'); // contacts.id (role='instructor')
            $t->unsignedInteger('room_id'); // booking_resources.id
            $t->string('recurrence', 32); // 'weekly','biweekly'
            $t->tinyInteger('day_of_week'); // 0=Sun … 6=Sat
            $t->time('start_time');
            $t->time('end_time');
            $t->date('session_start');
            $t->date('session_end');
            $t->unsignedInteger('price_cents');
            $t->string('currency', 8)->default('USD');
            $t->boolean('is_pro_rated')->default(true);
            $t->boolean('is_active')->default(true);
            $t->json('meta')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'style', 'level']);
            $t->index(['tenant_id', 'day_of_week', 'start_time']);
        });

        // Class Occurrences (link to booking appointments)
        $s->create('studio_class_occurrences', function (Table $t) {
            $t->id();
            $t->unsignedInteger('tenant_id');
            $t->unsignedInteger('series_id');
            $t->unsignedInteger('appointment_id')->nullable(); // booking_appointments.id
            $t->date('occurrence_date');
            $t->time('start_time');
            $t->time('end_time');
            $t->string('status', 16)->default('scheduled'); // 'scheduled','cancelled','completed'
            $t->timestamps();
            $t->index(['tenant_id', 'series_id', 'occurrence_date']);
        });

        // Enrollments (links to membership_subscriptions)
        $s->create('studio_enrollments', function (Table $t) {
            $t->id();
            $t->unsignedInteger('tenant_id');
            $t->unsignedInteger('student_id'); // contacts.id
            $t->unsignedInteger('series_id');
            $t->unsignedInteger('subscription_id')->nullable(); // membership_subscriptions.id
            $t->date('enrolled_at');
            $t->date('dropped_at')->nullable();
            $t->string('status', 16)->default('active'); // 'active','inactive','waitlist','trial','dropped'
            $t->json('meta')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'student_id', 'status']);
            $t->index(['tenant_id', 'series_id', 'status']);
        });

        // Waitlist
        $s->create('studio_waitlists', function (Table $t) {
            $t->id();
            $t->unsignedInteger('tenant_id');
            $t->unsignedInteger('student_id');
            $t->unsignedInteger('series_id');
            $t->timestamp('requested_at');
            $t->timestamp('notified_at')->nullable();
            $t->boolean('is_converted')->default(false);
            $t->timestamps();
            $t->index(['tenant_id', 'series_id', 'requested_at']);
        });

        // Attendance
        $s->create('studio_attendance', function (Table $t) {
            $t->id();
            $t->unsignedInteger('tenant_id');
            $t->unsignedInteger('occurrence_id');
            $t->unsignedInteger('student_id');
            $t->string('status', 16)->default('present'); // 'present','absent','excused','late'
            $t->text('notes')->nullable();
            $t->unsignedInteger('marked_by')->nullable(); // contacts.id (instructor)
            $t->timestamp('marked_at');
            $t->timestamps();
            $t->unique(['tenant_id', 'occurrence_id', 'student_id']);
        });

        // Make-ups
        $s->create('studio_makeups', function (Table $t) {
            $t->id();
            $t->unsignedInteger('tenant_id');
            $t->unsignedInteger('student_id');
            $t->unsignedInteger('original_occurrence_id');
            $t->unsignedInteger('makeup_occurrence_id')->nullable();
            $t->string('status', 16)->default('pending'); // 'pending','used','expired'
            $t->date('expires_at');
            $t->timestamps();
        });

        // Recitals
        $s->create('studio_recitals', function (Table $t) {
            $t->id();
            $t->unsignedInteger('tenant_id');
            $t->string('name', 128);
            $t->date('performance_date');
            $t->time('performance_time');
            $t->string('venue', 128)->nullable();
            $t->boolean('ticket_sales_open')->default(false);
            $t->unsignedInteger('ticket_price_cents')->default(0);
            $t->string('ticket_currency', 8)->default('USD');
            $t->json('meta')->nullable();
            $t->timestamps();
        });

        $s->create('studio_recital_acts', function (Table $t) {
            $t->id();
            $t->unsignedInteger('tenant_id');
            $t->unsignedInteger('recital_id');
            $t->unsignedInteger('series_id')->nullable();
            $t->string('act_name', 128);
            $t->unsignedSmallInteger('order')->default(0);
            $t->unsignedInteger('stage_manager_id')->nullable(); // contacts.id
            $t->unsignedInteger('act_manager_id')->nullable();   // contacts.id
            $t->boolean('has_quick_change')->default(false);
            $t->json('meta')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'recital_id', 'order']);
        });

        $s->create('studio_recital_performers', function (Table $t) {
            $t->id();
            $t->unsignedInteger('tenant_id');
            $t->unsignedInteger('act_id');
            $t->unsignedInteger('student_id');
            $t->unsignedInteger('costume_id')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'act_id', 'student_id']);
        });

        // Costumes
        $s->create('studio_costumes', function (Table $t) {
            $t->id();
            $t->unsignedInteger('tenant_id');
            $t->string('name', 128);
            $t->string('vendor', 64)->nullable();
            $t->string('vendor_item_number', 32)->nullable();
            $t->string('image_url', 255)->nullable();
            $t->unsignedInteger('price_cents')->default(0);
            $t->string('currency', 8)->default('USD');
            $t->json('size_chart')->nullable();
            $t->timestamps();
        });

        $s->create('studio_costume_assignments', function (Table $t) {
            $t->id();
            $t->unsignedInteger('tenant_id');
            $t->unsignedInteger('costume_id');
            $t->unsignedInteger('student_id');
            $t->unsignedInteger('series_id')->nullable();
            $t->unsignedInteger('act_id')->nullable();
            $t->string('assigned_size', 8)->nullable();
            $t->string('actual_size', 8)->nullable();
            $t->string('status', 16)->default('ordered'); // 'ordered','in_stock','distributed','returned'
            $t->timestamps();
            $t->index(['tenant_id', 'student_id']);
        });

        $s->create('studio_measurements', function (Table $t) {
            $t->id();
            $t->unsignedInteger('tenant_id');
            $t->unsignedInteger('student_id');
            $t->unsignedInteger('measured_by')->nullable();
            $t->date('measured_on');
            $t->unsignedSmallInteger('chest_cm')->nullable();
            $t->unsignedSmallInteger('waist_cm')->nullable();
            $t->unsignedSmallInteger('hip_cm')->nullable();
            $t->unsignedSmallInteger('inseam_cm')->nullable();
            $t->unsignedSmallInteger('girth_cm')->nullable();
            $t->unsignedSmallInteger('height_cm')->nullable();
            $t->timestamps();
        });

        // Tickets
        $s->create('studio_tickets', function (Table $t) {
            $t->id();
            $t->unsignedInteger('tenant_id');
            $t->unsignedInteger('recital_id');
            $t->unsignedInteger('family_id');
            $t->string('ticket_code', 32)->unique();
            $t->boolean('is_scanned')->default(false);
            $t->timestamp('scanned_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(Schema $s): void
    {
        $tables = [
            'studio_tickets','studio_measurements','studio_costume_assignments',
            'studio_costumes','studio_recital_performers','studio_recital_acts',
            'studio_recitals','studio_makeups','studio_attendance','studio_waitlists',
            'studio_enrollments','studio_class_occurrences','studio_class_series',
            'studio_family_members','studio_families','studio_contact_roles',
        ];
        foreach ($tables as $t) {
            $s->dropIfExists($t);
        }
    }
};
```

### 2.2 Migration Runner Command

```bash
# Add to your CLI or run manually
php bin/migrate --plugin=studio --up
```

If your `MigrationRunner` currently only handles core, extend it:

```php
// In your MigrationRunner or a plugin-specific runner
$pluginMigrations = glob(__DIR__.'/plugins/studio/src/Infrastructure/Migrations/*.php');
foreach ($pluginMigrations as $file) {
    $migration = require $file;
    $runner->run($migration);
}
```


---

## 3. Domain Layer

### 3.1 Value Objects

Reuse existing ones. Add only what is missing:

```php
<?php
// plugins/studio/src/Domain/ValueObjects/ClassLevel.php

enum ClassLevel: string
{
    case BEGINNER = 'beginner';
    case INTERMEDIATE = 'intermediate';
    case ADVANCED = 'advanced';
}

// plugins/studio/src/Domain/ValueObjects/DanceStyle.php

enum DanceStyle: string
{
    case BALLET = 'ballet';
    case JAZZ = 'jazz';
    case HIPHOP = 'hiphop';
    case TAP = 'tap';
    case CONTEMPORARY = 'contemporary';
    case ACRO = 'acro';
}
```

### 3.2 Class Series & Occurrence Generator

This is the **thin layer** on top of Booking.

```php
<?php
// plugins/studio/src/Domain/Classes/ClassSeries.php

class ClassSeries
{
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly string $name,
        public readonly DanceStyle $style,
        public readonly ?ClassLevel $level,
        public readonly int $ageMin,
        public readonly int $ageMax,
        public readonly int $capacity,
        public readonly int $instructorId,   // contacts.id
        public readonly int $roomId,         // booking_resources.id
        public readonly int $dayOfWeek,      // 0-6
        public readonly string $startTime,   // "17:00"
        public readonly string $endTime,     // "18:00"
        public readonly DateTimeImmutable $sessionStart,
        public readonly DateTimeImmutable $sessionEnd,
        public readonly Money $tuition,
        public readonly bool $isProRated,
        public readonly bool $isActive,
    ) {}

    public function durationMinutes(): int
    {
        $s = DateTime::createFromFormat('H:i', $this->startTime);
        $e = DateTime::createFromFormat('H:i', $this->endTime);
        return ($e->getTimestamp() - $s->getTimestamp()) / 60;
    }

    public function isAgeEligible(DateTimeImmutable $dob): bool
    {
        $age = $dob->diff(new DateTimeImmutable())->y;
        return $age >= $this->ageMin && $age <= $this->ageMax;
    }
}
```

```php
<?php
// plugins/studio/src/Domain/Classes/ClassOccurrenceGenerator.php

class ClassOccurrenceGenerator
{
    public function generate(ClassSeries $series): array
    {
        $occurrences = [];
        $cursor = clone $series->sessionStart;

        // Advance to first matching day-of-week
        while ((int)$cursor->format('w') !== $series->dayOfWeek) {
            $cursor = $cursor->modify('+1 day');
        }

        while ($cursor <= $series->sessionEnd) {
            $occurrences[] = [
                'tenant_id'        => $series->tenantId,
                'series_id'        => $series->id,
                'occurrence_date'  => $cursor->format('Y-m-d'),
                'start_time'       => $series->startTime,
                'end_time'         => $series->endTime,
                'status'           => 'scheduled',
            ];
            $cursor = $cursor->modify('+7 days');
        }

        return $occurrences;
    }
}
```

### 3.3 Enrollment & Waitlist

```php
<?php
// plugins/studio/src/Domain/Enrollment/Enrollment.php

class Enrollment
{
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $studentId,
        public readonly int $seriesId,
        public readonly ?int $subscriptionId, // membership_subscriptions.id
        public readonly DateTimeImmutable $enrolledAt,
        public readonly ?DateTimeImmutable $droppedAt,
        public readonly string $status, // active, inactive, waitlist, trial, dropped
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->droppedAt === null;
    }
}
```

```php
<?php
// plugins/studio/src/Domain/Enrollment/Waitlist.php

class Waitlist
{
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $studentId,
        public readonly int $seriesId,
        public readonly DateTimeImmutable $requestedAt,
        public readonly ?DateTimeImmutable $notifiedAt,
        public readonly bool $isConverted,
    ) {}
}
```

### 3.4 Tuition Calculator (uses Money)

```php
<?php
// plugins/studio/src/Domain/Billing/TuitionCalculator.php

class TuitionCalculator
{
    public function __construct(
        private EnrollmentRepository $enrollmentRepo,
        private FamilyRepository $familyRepo,
    ) {}

    public function calculate(int $studentId, ClassSeries $class): Money
    {
        $base = $class->tuition;

        // 1. Multi-class discount
        $activeCount = $this->enrollmentRepo->activeCountForStudent($studentId);
        $base = $this->applyMultiClassDiscount($base, $activeCount);

        // 2. Family discount
        $family = $this->familyRepo->findByStudent($studentId);
        if ($family) {
            $siblingCount = $this->enrollmentRepo->activeCountForFamily($family->id) - 1;
            $base = $this->applyFamilyDiscount($base, max(0, $siblingCount));
        }

        // 3. Pro-ration
        if ($class->isProRated && $class->sessionStart < new DateTimeImmutable()) {
            $base = $this->prorate($base, $class);
        }

        return $base;
    }

    private function applyMultiClassDiscount(Money $base, int $count): Money
    {
        return match (true) {
            $count >= 4 => $base->multiply(0.80), // 4th class = 20% off
            $count >= 3 => $base->multiply(0.85),
            $count >= 2 => $base->multiply(0.90),
            default     => $base,
        };
    }

    private function applyFamilyDiscount(Money $base, int $siblings): Money
    {
        return match (true) {
            $siblings >= 2 => $base->multiply(0.85),
            $siblings >= 1 => $base->multiply(0.90),
            default        => $base,
        };
    }

    private function prorate(Money $base, ClassSeries $class): Money
    {
        $now = new DateTimeImmutable();
        $totalWeeks = $class->sessionStart->diff($class->sessionEnd)->days / 7;
        $remainingWeeks = max(1, $now->diff($class->sessionEnd)->days / 7);
        $ratio = $remainingWeeks / $totalWeeks;
        return $base->multiply($ratio);
    }
}
```

> **Note:** `Money::multiply()` must return a new `Money` instance (immutable). If your `Slate\Support\Money` does not have this yet, add it:
> ```php
> public function multiply(float $factor): self {
>     return new self((int) round($this->cents * $factor), $this->currency);
> }
> ```

### 3.5 Family Entity (fills Phase-2B gap)

```php
<?php
// plugins/studio/src/Domain/Students/Family.php

class Family
{
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $primaryParentId,
        public readonly ?string $familyName,
        public readonly array $members, // FamilyMember[]
    ) {}

    public function studentIds(): array
    {
        return array_map(
            fn(FamilyMember $m) => $m->contactId,
            array_filter($this->members, fn(FamilyMember $m) => $m->relation === 'child')
        );
    }
}

class FamilyMember
{
    public function __construct(
        public readonly int $contactId,
        public readonly string $relation, // 'child','spouse','guardian'
    ) {}
}
```


---

## 4. Infrastructure Layer

### 4.1 Repository (adopt the new core pattern)

Since core has `Slate\Data\{Repository, QueryBuilder}` but plugins still use raw `Database::`, **this plugin should pilot the Repository pattern** for plugins.

```php
<?php
// plugins/studio/src/Infrastructure/Repositories/ClassSeriesRepository.php

class ClassSeriesRepository extends \Slate\Data\Repository
{
    protected string $table = 'studio_class_series';

    public function findActiveForTenant(int $tenantId): array
    {
        return $this->query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('style')
            ->orderBy('level')
            ->get();
    }

    public function findById(int $id, int $tenantId): ?ClassSeries
    {
        $row = $this->query()
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->first();

        return $row ? $this->hydrate($row) : null;
    }

    public function create(array $data): int
    {
        $data['tenant_id'] = $data['tenant_id'] ?? current_tenant_id();
        return $this->query()->insert($data);
    }

    private function hydrate(array $row): ClassSeries
    {
        return new ClassSeries(
            id:            (int) $row['id'],
            tenantId:      (int) $row['tenant_id'],
            name:          $row['name'],
            style:         DanceStyle::from($row['style']),
            level:         $row['level'] ? ClassLevel::from($row['level']) : null,
            ageMin:        (int) $row['age_min'],
            ageMax:        (int) $row['age_max'],
            capacity:      (int) $row['capacity'],
            instructorId:  (int) $row['instructor_id'],
            roomId:        (int) $row['room_id'],
            dayOfWeek:     (int) $row['day_of_week'],
            startTime:     $row['start_time'],
            endTime:       $row['end_time'],
            sessionStart:  new DateTimeImmutable($row['session_start']),
            sessionEnd:    new DateTimeImmutable($row['session_end']),
            tuition:       new Money((int) $row['price_cents'], $row['currency']),
            isProRated:    (bool) $row['is_pro_rated'],
            isActive:      (bool) $row['is_active'],
        );
    }
}
```

> **Tenant scoping is automatic** if your `QueryBuilder` already applies `->where('tenant_id', TenantContext::id())` by default. If not, add it in the Repository base or manually in each query.

### 4.2 Portal Controllers

```php
<?php
// plugins/studio/src/Infrastructure/Portal/ClassCatalogController.php

class ClassCatalogController
{
    public function __construct(
        private ClassSeriesRepository $classRepo,
        private EnrollmentRepository $enrollmentRepo,
        private FamilyRepository $familyRepo,
    ) {}

    public function index(): void
    {
        Auth::requireCustomer();

        $tenantId = current_tenant_id();
        $parentId = Auth::customerId();

        // Get parent's family + students
        $family = $this->familyRepo->findByParent($parentId, $tenantId);
        $studentIds = $family ? $family->studentIds() : [];

        // Load catalog with enrollment status per student
        $classes = $this->classRepo->findActiveForTenant($tenantId);
        $catalog = [];
        foreach ($classes as $class) {
            $item = [
                'class'      => $class,
                'spots_left' => $class->capacity - $this->enrollmentRepo->activeCountForClass($class->id),
                'eligible_students' => [],
            ];
            foreach ($studentIds as $sid) {
                $student = IdentityStore::findById($sid); // or Contact repo
                if ($class->isAgeEligible(new DateTimeImmutable($student['meta']['dob'] ?? 'now'))) {
                    $item['eligible_students'][] = $sid;
                }
            }
            $catalog[] = $item;
        }

        // Render server-side
        include __DIR__.'/../../../resources/views/portal/class-catalog.php';
    }
}
```

### 4.3 Public Router

```php
<?php
// plugins/studio/public/router.php

// Called by Slate\Kernel\Http\PublicRouter when URI matches studio/*

$uri = $_SERVER['REQUEST_URI'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Simple dispatcher
match (true) {
    str_contains($uri, 'studio/catalog')  => (new ClassCatalogController())->index(),
    str_contains($uri, 'studio/enroll')   => (new SelfServiceEnroller())->handle(),
    str_contains($uri, 'studio/schedule') => (new ScheduleController())->index(),
    str_contains($uri, 'studio/payments') => (new PaymentController())->index(),
    str_contains($uri, 'studio/tickets')  => (new TicketController())->index(),
    default => http_response_code(404),
};
```

---

## 5. Integration Adapters

### 5.1 Booking Adapter

```php
<?php
// plugins/studio/src/Integration/BookingAdapter.php

class BookingAdapter
{
    public function createAppointmentForOccurrence(array $occurrence, ClassSeries $series): array
    {
        if (!class_exists('BookingAPI')) {
            return ['ok' => false, 'error' => 'Booking plugin not active'];
        }

        $startsAt = $occurrence['occurrence_date'] . ' ' . $occurrence['start_time'];
        $endsAt   = $occurrence['occurrence_date'] . ' ' . $occurrence['end_time'];

        // Check room availability first
        $freeRoom = BookingAPI::freeResourceId(
            resourceId: $series->roomId,
            start:      $startsAt,
            end:        $endsAt
        );

        if ($freeRoom === null) {
            return ['ok' => false, 'error' => 'Room conflict'];
        }

        // Create the appointment
        $result = BookingAPI::createAppointment([
            'service_id'     => $this->ensureServiceExists($series), // Map series to booking_service
            'provider_id'    => $series->instructorId,
            'starts_at'      => $startsAt,
            'ends_at'        => $endsAt,
            'customer_name'  => $series->name . ' Class',
            'customer_email' => '', // Internal class, no customer
            'party_size'     => 0,
            'resource_id'    => $series->roomId,
            'meta'           => [
                'studio_series_id' => $series->id,
                'is_class_occurrence' => true,
            ],
        ]);

        if ($result['ok'] ?? false) {
            // Link occurrence to appointment
            Database::update('studio_class_occurrences', [
                'appointment_id' => $result['appointment_id'],
            ], 'id = ?', [$occurrence['id']]);
        }

        return $result;
    }

    private function ensureServiceExists(ClassSeries $series): int
    {
        // Map each unique class series to a booking_service row
        // so Booking's provider hours / availability engine works.
        $existing = Database::row(
            "SELECT id FROM booking_services WHERE tenant_id = ? AND meta->>'studio_series_id' = ?",
            [$series->tenantId, $series->id]
        );
        if ($existing) return (int) $existing['id'];

        return BookingAPI::createService([
            'tenant_id'   => $series->tenantId,
            'name'        => $series->name,
            'duration'    => $series->durationMinutes(),
            'price_cents' => 0, // Tuition is handled by Membership, not Booking
            'meta'        => json_encode(['studio_series_id' => $series->id]),
        ])['service_id'] ?? 0;
    }
}
```

### 5.2 Membership Adapter

```php
<?php
// plugins/studio/src/Integration/MembershipAdapter.php

class MembershipAdapter
{
    public function purchaseCoursePlan(int $customerId, int $planId): array
    {
        if (!class_exists('MembershipAPI')) {
            return ['ok' => false, 'error' => 'Membership plugin not active'];
        }

        return MembershipAPI::purchase(
            customerId: $customerId,
            planId:     $planId,
            addInsurance: false
        );
    }

    public function createCoursePlan(ClassSeries $series): int
    {
        // A "class" in Studio IS a "course plan" in Membership
        return MembershipAPI::createPlan([
            'tenant_id'      => $series->tenantId,
            'plan_type'      => 'course',
            'course_id'      => $series->id, // Links back to studio_class_series
            'name'           => $series->name,
            'name_fr'        => null,
            'price_cents'    => $series->tuition->cents,
            'currency'       => $series->tuition->currency,
            'duration_days'  => 30, // Monthly
            'session_quota'  => 8,  // e.g. 2 classes/week x 4 weeks
            'grace_days'     => 7,
            'skill_levels'   => $series->level ? [$series->level->value] : [],
        ])['plan_id'] ?? 0;
    }

    public function getSubscriptionStatus(int $subscriptionId): string
    {
        $sub = MembershipAPI::getSubscription($subscriptionId);
        return $sub['status'] ?? 'unknown';
    }
}
```

### 5.3 Forms Adapter

```php
<?php
// plugins/studio/src/Integration/FormsAdapter.php

class FormsAdapter
{
    public function createWaiverForm(int $tenantId): string
    {
        if (!class_exists('FormsAPI')) {
            return '';
        }

        $slug = 'studio_waiver_' . $tenantId;

        // Check if exists
        $existing = Database::row("SELECT slug FROM forms_definitions WHERE slug = ?", [$slug]);
        if ($existing) return $slug;

        FormsAPI::createDefinition([
            'slug'            => $slug,
            'title'           => 'Studio Liability Waiver',
            'fields_json'     => json_encode([
                ['type' => 'paragraph', 'label' => 'Release of Liability', 'content' => '...'],
                ['type' => 'paragraph', 'label' => 'Photo Release', 'content' => '...'],
                ['type' => 'signature', 'label' => 'Parent/Guardian Signature', 'required' => true],
                ['type' => 'date',      'label' => 'Date Signed', 'required' => true],
            ]),
            'submit_label'    => 'I Agree',
            'success_message' => 'Waiver accepted. You may now enroll.',
            'notify_email'    => '', // Admin notification handled by hook
        ]);

        return $slug;
    }

    public function prefillFromContact(string $formSlug, int $contactId): array
    {
        $contact = IdentityStore::findById($contactId);
        if (!$contact) return [];

        // Return prefilled values for the form renderer
        return [
            'parent_name'  => $contact['display_name'] ?? '',
            'parent_email' => $contact['primary_email'] ?? '',
            'parent_phone' => $contact['primary_phone'] ?? '',
        ];
    }
}
```

### 5.4 Content-Builder Adapter

```php
<?php
// plugins/studio/src/Integration/ContentBuilderAdapter.php

class ContentBuilderAdapter
{
    public function buildRecitalPlaybook(int $recitalId): array
    {
        if (!class_exists('ContentBuilderAPI')) {
            return ['ok' => false];
        }

        $recital = StudioAPI::getRecital($recitalId);
        $acts = StudioAPI::getRecitalActs($recitalId);

        $blocks = [];
        $blocks[] = ContentBuilderAPI::block('heading', ['text' => $recital['name']]);
        $blocks[] = ContentBuilderAPI::block('paragraph', ['text' => 'Date: ' . $recital['performance_date']]);

        foreach ($acts as $act) {
            $blocks[] = ContentBuilderAPI::block('act_card', [
                'act_number'    => $act['order'],
                'title'         => $act['act_name'],
                'students'      => $act['student_count'],
                'quick_change'  => $act['has_quick_change'],
                'stage_manager' => $act['stage_manager_name'] ?? 'TBD',
            ]);
        }

        return ContentBuilderAPI::createPage([
            'title'   => $recital['name'] . ' — Playbook',
            'blocks'  => $blocks,
            'status'  => 'published',
            'meta'    => ['studio_recital_id' => $recitalId],
        ]);
    }
}
```


---

## 6. Portal Views (Server-Rendered PHP)

Since your stack is **server-rendered PHP with no JS framework**, keep views simple and data-heavy.

### 6.1 Class Catalog View

```php
<?php
// plugins/studio/resources/views/portal/class-catalog.php
// Expects: $catalog (array), $family (Family|null), $studentIds (array)

Auth::requireCustomer();
$parent = Auth::customer();
?>
<div class="portal-section">
  <h2>🩰 Enroll in a Class</h2>

  <?php if (!$family || empty($studentIds)): ?>
    <div class="notice notice-warning">
      No students linked to your account. Please contact the studio.
    </div>
  <?php else: ?>

    <div class="student-selector">
      <?php foreach ($studentIds as $sid): 
        $student = IdentityStore::findById($sid);
        $dob = new DateTimeImmutable($student['meta']['dob'] ?? 'now');
        $age = $dob->diff(new DateTimeImmutable())->y;
      ?>
        <div class="student-chip" data-student-id="<?= $sid ?>">
          <strong><?= htmlspecialchars($student['display_name']) ?></strong>
          <span>Age <?= $age ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="class-grid">
      <?php foreach ($catalog as $item): 
        $class = $item['class'];
        $spots = $item['spots_left'];
        $eligible = $item['eligible_students'];
      ?>
        <div class="class-card <?= $spots <= 0 ? 'waitlist' : '' ?>">
          <div class="class-header" style="background: <?= match($class->style) {
            DanceStyle::BALLET => 'linear-gradient(135deg,#fbcfe8,#fce7f3)',
            DanceStyle::JAZZ => 'linear-gradient(135deg,#fde68a,#fef3c7)',
            DanceStyle::HIPHOP => 'linear-gradient(135deg,#bae6fd,#e0f2fe)',
            default => 'linear-gradient(135deg,#ddd6fe,#ede9fe)',
          } ?>">
            <span class="class-emoji"><?= match($class->style) {
              DanceStyle::BALLET => '🩰',
              DanceStyle::JAZZ => '🎵',
              DanceStyle::HIPHOP => '🕺',
              DanceStyle::TAP => '👠',
              default => '💃',
            } ?></span>
            <?php if (in_array($studentIds[0] ?? 0, $eligible)): ?>
              <span class="badge recommended">⭐ Recommended</span>
            <?php endif; ?>
          </div>
          <div class="class-body">
            <h4><?= htmlspecialchars($class->name) ?></h4>
            <p class="meta">📍 Room <?= $class->roomId ?> · <?= $class->dayOfWeekName() ?> · <?= $class->startTime ?></p>
            <p class="meta">👩‍🏫 Instructor #<?= $class->instructorId ?> · Ages <?= $class->ageMin ?>–<?= $class->ageMax ?></p>
            <div class="class-footer">
              <span class="price">$<?= number_format($class->tuition->cents / 100, 2) ?>/mo</span>
              <?php if ($spots > 0): ?>
                <span class="spots open">✓ <?= $spots ?> spots left</span>
              <?php else: ?>
                <span class="spots waitlist">⏳ Waitlist</span>
              <?php endif; ?>
            </div>

            <?php if (!empty($eligible)): ?>
              <form action="/studio/enroll" method="POST" class="enroll-form">
                <input type="hidden" name="series_id" value="<?= $class->id ?>">
                <input type="hidden" name="student_id" value="<?= $eligible[0] ?>">
                <button type="submit" class="btn btn-primary">
                  <?= $spots > 0 ? 'Enroll' : 'Join Waitlist' ?>
                </button>
              </form>
            <?php else: ?>
              <button class="btn" disabled>Not eligible (age)</button>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
```

### 6.2 Portal Widget: My Classes

```php
<?php
// plugins/studio/resources/views/portal/widgets/my-classes.php
// Injected via customer_dashboard_widgets filter

$parentId = Auth::customerId();
$tenantId = current_tenant_id();
$family = StudioAPI::getFamilyByParent($parentId, $tenantId);
if (!$family) return;

foreach ($family->studentIds() as $sid):
  $student = IdentityStore::findById($sid);
  $enrollments = StudioAPI::getActiveEnrollments($sid, $tenantId);
?>
  <div class="widget-card">
    <h4><?= htmlspecialchars($student['display_name']) ?></h4>
    <?php foreach ($enrollments as $enr): 
      $series = StudioAPI::getClassSeries($enr['series_id']);
    ?>
      <div class="enrollment-row">
        <span class="style-emoji"><?= match($series['style']) {
          'ballet' => '🩰', 'jazz' => '🎵', 'hiphop' => '🕺',
          'tap' => '👠', default => '💃'
        } ?></span>
        <div class="info">
          <strong><?= htmlspecialchars($series['name']) ?></strong>
          <span><?= $series['day_of_week_name'] ?> · <?= $series['start_time'] ?></span>
        </div>
        <span class="badge active">Active</span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
```

---

## 7. Admin Views

### 7.1 Class Manager

```php
<?php
// plugins/studio/resources/views/admin/class-manager.php

Auth::requireUser();
if (!Auth::user()->can('studio_manage_classes')) {
    abort(403);
}

$tenantId = current_tenant_id();
$classes = StudioAPI::getAllClassSeries($tenantId);
?>
<div class="admin-page">
  <header>
    <h1>🩰 Class Manager</h1>
    <a href="/admin/studio/classes/new" class="btn btn-primary">+ Create Class</a>
  </header>

  <table class="data-table">
    <thead>
      <tr>
        <th>Class</th>
        <th>Schedule</th>
        <th>Instructor</th>
        <th>Enrolled</th>
        <th>Capacity</th>
        <th>Waitlist</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($classes as $c): 
        $instructor = IdentityStore::findById($c['instructor_id']);
        $enrolled = StudioAPI::countEnrollments($c['id'], 'active');
        $waitlist = StudioAPI::countWaitlist($c['id']);
      ?>
        <tr>
          <td>
            <strong><?= htmlspecialchars($c['name']) ?></strong>
            <span class="tag"><?= $c['style'] ?> · <?= $c['level'] ?></span>
          </td>
          <td><?= $c['day_of_week_name'] ?> · <?= $c['start_time'] ?>–<?= $c['end_time'] ?></td>
          <td><?= htmlspecialchars($instructor['display_name'] ?? 'Unknown') ?></td>
          <td><?= $enrolled ?></td>
          <td><?= $c['capacity'] ?></td>
          <td><?= $waitlist ?></td>
          <td>
            <span class="badge <?= $c['is_active'] ? 'bg-green' : 'bg-gray' ?>">
              <?= $c['is_active'] ? 'Active' : 'Inactive' ?>
            </span>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
```

---

## 8. Automation & Hooks

### 8.1 Event Reactions

```php
<?php
// plugins/studio/Studio.php — inside boot() or a dedicated listener class

// When a class occurrence appointment is created, mark attendance slots
Hook::addAction('booking_created', function (array $data) {
    if (($data['meta']['is_class_occurrence'] ?? false) && !empty($data['meta']['studio_series_id'])) {
        $occurrence = Database::row(
            "SELECT id FROM studio_class_occurrences WHERE appointment_id = ?",
            [$data['appointment_id']]
        );
        if ($occurrence) {
            // Pre-create attendance records for all enrolled students
            StudioAPI::prepareAttendanceSheet((int) $occurrence['id']);
        }
    }
});

// When a payment succeeds via stripe-payment, activate the membership
Hook::addAction('stripe_payment_succeeded', function (array $data) {
    if (!empty($data['metadata']['studio_enrollment_id'])) {
        $enrollmentId = (int) $data['metadata']['studio_enrollment_id'];
        Database::update('studio_enrollments', [
            'status' => 'active',
            'meta->payment_confirmed' => true,
        ], 'id = ?', [$enrollmentId]);
    }
});

// Daily cron: check waitlists when a spot opens
Hook::addAction('cron_daily', function () {
    $openings = StudioAPI::findNewClassOpenings();
    foreach ($openings as $opening) {
        $waitlist = StudioAPI::getWaitlistForClass($opening['series_id'], limit: 1);
        if ($waitlist) {
            StudioAPI::notifyWaitlistEntry($waitlist['id']);
            // Auto-convert if they respond within 24h (configurable)
        }
    }
});
```

### 8.2 Robo-Automation (Personal Assistant)

```php
<?php
// plugins/studio/src/Domain/Automation/RoboAssistant.php

class RoboAssistant
{
    public function runDaily(int $tenantId): void
    {
        $this->sendClassReminders($tenantId);
        $this->sendLateTuitionNotices($tenantId);
        $this->sendBirthdayWishes($tenantId);
        $this->checkCostumeDeadlines($tenantId);
    }

    private function sendClassReminders(int $tenantId): void
    {
        $tomorrow = (new DateTimeImmutable())->modify('+1 day')->format('Y-m-d');
        $occurrences = Database::rows(
            "SELECT o.*, s.name as class_name 
             FROM studio_class_occurrences o
             JOIN studio_class_series s ON o.series_id = s.id
             WHERE o.tenant_id = ? AND o.occurrence_date = ? AND o.status = 'scheduled'",
            [$tenantId, $tomorrow]
        );

        foreach ($occurrences as $occ) {
            $students = StudioAPI::getEnrolledStudents($occ['series_id']);
            foreach ($students as $student) {
                $family = StudioAPI::getFamilyByStudent($student['id'], $tenantId);
                if (!$family) continue;

                $parent = IdentityStore::findById($family->primaryParentId);
                if (!$parent) continue;

                // SMS
                if (!empty($parent['primary_phone'])) {
                    Hook::doAction('robo_texter_send', [
                        'to'      => $parent['primary_phone'],
                        'body'    => "Reminder: {$student['display_name']} has {$occ['class_name']} tomorrow at {$occ['start_time']}.",
                    ]);
                }

                // Email
                if (!empty($parent['primary_email'])) {
                    Hook::doAction('robo_mailer_send', [
                        'to'       => $parent['primary_email'],
                        'subject'  => 'Class Reminder',
                        'template' => 'class_reminder',
                        'vars'     => ['student_name' => $student['display_name'], 'class_name' => $occ['class_name']],
                    ]);
                }
            }
        }
    }

    private function sendLateTuitionNotices(int $tenantId): void
    {
        $overdueFamilies = Database::rows(
            "SELECT f.id, f.primary_parent_id, SUM(ms.price_cents) as due_cents
             FROM studio_families f
             JOIN studio_enrollments e ON e.student_id IN (
                 SELECT contact_id FROM studio_family_members WHERE family_id = f.id
             )
             JOIN membership_subscriptions ms ON e.subscription_id = ms.id
             WHERE f.tenant_id = ? AND ms.status = 'overdue'
             GROUP BY f.id
             HAVING due_cents > 0",
            [$tenantId]
        );

        foreach ($overdueFamilies as $fam) {
            $parent = IdentityStore::findById($fam['primary_parent_id']);
            if (!$parent) continue;

            Hook::doAction('robo_mailer_send', [
                'to'       => $parent['primary_email'],
                'subject'  => 'Tuition Payment Reminder',
                'template' => 'late_tuition',
                'vars'     => ['balance' => Money::fromCents((int)$fam['due_cents'], 'USD')->format()],
            ]);
        }
    }
}
```


---

## 9. Testing Strategy

Use your existing harness (no PHPUnit).

### 9.1 Unit Test: Tuition Calculator

```php
<?php
// plugins/studio/tests/unit/TuitionCalculatorTest.php

require_once __DIR__.'/../../src/Domain/Billing/TuitionCalculator.php';

function assertEquals($expected, $actual, $msg = '') {
    if ($expected !== $actual) {
        echo "FAIL: $msg
Expected: $expected
Actual: $actual
";
        exit(1);
    }
}

// Mock repositories
$enrollmentRepo = new class {
    public int $activeCount = 1;
    public function activeCountForStudent(int $id): int { return $this->activeCount; }
    public function activeCountForFamily(int $id): int { return $this->activeCount; }
};

$familyRepo = new class {
    public ?Family $family = null;
    public function findByStudent(int $id): ?Family { return $this->family; }
};

$calc = new TuitionCalculator($enrollmentRepo, $familyRepo);

$base = new Money(10000, 'USD'); // $100.00
$class = new ClassSeries(
    id: 1, tenantId: 1, name: 'Test', style: DanceStyle::BALLET, level: null,
    ageMin: 5, ageMax: 10, capacity: 10, instructorId: 1, roomId: 1,
    dayOfWeek: 1, startTime: '17:00', endTime: '18:00',
    sessionStart: new DateTimeImmutable('+1 week'),
    sessionEnd: new DateTimeImmutable('+3 months'),
    tuition: $base, isProRated: false, isActive: true,
);

// Test 1: No discounts
$enrollmentRepo->activeCount = 1;
$familyRepo->family = null;
$result = $calc->calculate(1, $class);
assertEquals(10000, $result->cents, 'Base tuition should be unchanged');

// Test 2: Multi-class discount (2nd class = 10% off)
$enrollmentRepo->activeCount = 2;
$result = $calc->calculate(1, $class);
assertEquals(9000, $result->cents, '2nd class should get 10% discount');

// Test 3: Family discount (1 sibling)
$enrollmentRepo->activeCount = 1;
$familyRepo->family = new Family(id: 1, tenantId: 1, primaryParentId: 10, familyName: 'Test', members: [
    new FamilyMember(contactId: 1, relation: 'child'),
    new FamilyMember(contactId: 2, relation: 'child'),
]);
$enrollmentRepo->activeCount = 2; // 2 students in family enrolled
$result = $calc->calculate(1, $class);
assertEquals(9000, $result->cents, 'Family with 1 sibling should get 10% discount');

echo "PASS: TuitionCalculatorTest (3/3)
";
```

### 9.2 Integration Test: Enrollment Flow

```php
<?php
// plugins/studio/tests/integration/EnrollmentFlowTest.php

require_once __DIR__.'/../../Studio.php';

// Boot app + DB (your harness handles this)
bootApp();
$tenantId = 1;

// 1. Create a parent contact
$parentId = IdentityStore::create([
    'tenant_id'      => $tenantId,
    'kind'           => 'person',
    'display_name'   => 'Test Parent',
    'primary_email'  => 'parent@test.com',
    'primary_phone'  => '555-0001',
]);

// 2. Create a student contact
$studentId = IdentityStore::create([
    'tenant_id'      => $tenantId,
    'kind'           => 'person',
    'display_name'   => 'Test Student',
    'meta'           => json_encode(['dob' => '2018-01-01']),
]);

// 3. Create family
$familyId = StudioAPI::createFamily($parentId, [$studentId]);
assert($familyId > 0, 'Family should be created');

// 4. Create class series
$seriesId = StudioAPI::createClassSeries([
    'tenant_id'     => $tenantId,
    'name'          => 'Ballet I',
    'style'         => 'ballet',
    'level'         => 'beginner',
    'age_min'       => 6,
    'age_max'       => 8,
    'capacity'      => 10,
    'instructor_id' => 99, // assume exists
    'room_id'       => 1,  // assume exists
    'day_of_week'   => 1,
    'start_time'    => '16:00',
    'end_time'      => '17:00',
    'session_start' => (new DateTimeImmutable())->format('Y-m-d'),
    'session_end'   => (new DateTimeImmutable('+3 months'))->format('Y-m-d'),
    'price_cents'   => 8500,
    'currency'      => 'USD',
]);
assert($seriesId > 0, 'Class series should be created');

// 5. Enroll student
$result = StudioAPI::enrollStudent($studentId, $seriesId);
assert($result['ok'] === true, 'Enrollment should succeed');
assert($result['status'] === 'active', 'Enrollment should be active');

// 6. Verify membership plan was created
$plan = Database::row("SELECT * FROM membership_plans WHERE course_id = ?", [$seriesId]);
assert($plan !== false, 'Course plan should exist in membership_plans');
assert($plan['plan_type'] === 'course', 'Plan type should be course');

echo "PASS: EnrollmentFlowTest
";
```

---

## 10. Implementation Roadmap

| Phase | Deliverable | Dependencies | Effort |
|-------|-------------|--------------|--------|
| **Phase 0** | Activate `booking`, `membership`, `stripe-payment` plugins | Plugin admin UI | 1 day |
| **Phase 1** | Schema + Family/Roles + Class Series CRUD | Migration framework | 1 week |
| **Phase 2** | Booking adapter + occurrence generator | Booking plugin active | 3 days |
| **Phase 3** | Enrollment flow + Membership adapter | Membership + stripe-payment | 1 week |
| **Phase 4** | Parent portal (catalog, enroll, schedule) | Portal auth + widgets | 1 week |
| **Phase 5** | Attendance + Teacher portal (Class Manager) | Timeclock | 3 days |
| **Phase 6** | Recital Wizard + Costume Console | Content-Builder | 1 week |
| **Phase 7** | Robo automation + Reports | Event hooks + cron | 3 days |
| **Phase 8** | POS / Online store integration | Shop plugin | 1 week |

---

## 11. Key Design Decisions

1. **Don't duplicate Contact/Identity.** Students, parents, and instructors are all `Contact` records with role tags. Use your existing identity architecture.

2. **Let Membership handle the money flow.** Tuition is just a subscription. Your Membership plugin already handles recurring billing, failed payments, and dunning.

3. **Use Booking for the calendar.** Don't build a second calendar system. Extend Booking's resource/instructor model to support recurring weekly classes.

4. **Portal = SiteHub + Auth.** The parent portal is a specialized SiteHub site with IdentityStore authentication and scoped data queries.

5. **Forms for all data collection.** Registration, waivers, measurements, and even ticket purchases can flow through your Forms plugin, storing submissions as structured data linked to Contacts.

6. **Pilot the Repository pattern.** This plugin should be the first plugin to adopt `Slate\Data\{Repository, QueryBuilder}` instead of raw `Database::` calls. Set the standard for Phase 3.

---

## 12. Glossary

| Term | Definition |
|------|------------|
| **Class Series** | A recurring class definition (e.g. "Ballet II, Mon/Wed 5PM, Aug–Dec") |
| **Occurrence** | A single instance of a class series on a specific date |
| **Enrollment** | A student's active subscription to a class series |
| **Waitlist** | A queue of students who want a full class |
| **Family** | A household grouping of parent(s) + student(s) |
| **Contact Role** | A tag on a Contact: student, parent, instructor, guardian |
| **Robo-Sizer** | Algorithm that recommends costume sizes from measurements |
| **Quick Change** | When a student has back-to-back acts in a recital |
| **Proration** | Calculating partial-month tuition for mid-term enrollments |

---

*This SKILL guide is tailored specifically for the Slate Platform architecture (Phase 2A) and should be treated as the canonical reference for the Studio plugin build.*
