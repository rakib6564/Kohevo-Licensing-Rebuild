<?php
/**
 * 0005_studio_core — the Studio plugin's core schema (additive).
 *
 * Seven tenant-scoped tables backing dance/arts-studio management: contact
 * roles, families + members, class series, generated occurrences, enrollments,
 * and attendance. People are core `contacts` (Phase 2A) — every contact FK is
 * BIGINT UNSIGNED to match contacts.id. Cross-plugin FKs into the booking and
 * membership tables stay INT UNSIGNED (legacy) and NULLABLE — they are filled by
 * the booking/membership adapters in later batches, not at row-creation time.
 *
 * PURELY ADDITIVE: no existing table or reader is touched. The Studio plugin is
 * inert on disk until installed + activated, so these tables carry no live
 * traffic yet. Reversible: down() drops all seven children-first (they are empty
 * at creation), so rollback is clean.
 *
 * NB: numbered 0005 because 0004 is 0004_content_revisions (Phase 3A).
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        // A contact's role(s) within the studio. A person can hold several
        // (e.g. an instructor who is also a parent) — hence a row per role.
        $s->create('studio_contact_roles', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->bigInt('contact_id')->unsigned();                 // contacts.id (BIGINT)
            $t->enum('role', ['student', 'parent', 'instructor', 'guardian'])->default('student');
            $t->json('meta')->nullable();
            $t->datetime('created_at')->useCurrent();
            $t->unique(['tenant_id', 'contact_id', 'role'], 'uniq_tenant_contact_role');
            $t->index(['tenant_id', 'role'], 'idx_tenant_role');
        });

        // A household / billing unit, anchored on the primary paying parent.
        $s->create('studio_families', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->bigInt('primary_parent_id')->unsigned();          // contacts.id
            $t->string('family_name', 128)->nullable();
            $t->json('billing_address')->nullable();
            $t->json('meta')->nullable();
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at')->useCurrent();
            $t->index(['tenant_id', 'primary_parent_id'], 'idx_tenant_parent');
        });

        // Membership of a family: which contacts belong, and how.
        $s->create('studio_family_members', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->bigInt('family_id')->unsigned();                  // studio_families.id
            $t->bigInt('contact_id')->unsigned();                 // contacts.id
            $t->enum('relation', ['child', 'spouse', 'guardian'])->default('child');
            $t->datetime('created_at')->useCurrent();
            $t->unique(['tenant_id', 'family_id', 'contact_id'], 'uniq_family_contact');
            $t->index(['tenant_id', 'contact_id'], 'idx_tenant_contact');
        });

        // A recurring weekly class definition (the "series"); its dated sessions
        // live in studio_class_occurrences.
        $s->create('studio_class_series', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->string('name', 128);
            $t->string('style', 32);                              // DanceStyle value
            $t->string('level', 32)->nullable();                  // ClassLevel value
            $t->tinyInt('age_min')->unsigned()->default(0);
            $t->tinyInt('age_max')->unsigned()->default(99);
            $t->int('capacity')->unsigned()->default(20);
            $t->bigInt('instructor_id')->unsigned();              // contacts.id (role=instructor)
            $t->int('room_id')->unsigned()->nullable();           // booking_resources.id (INT) — wired Batch 2
            $t->int('booking_service_id')->unsigned()->nullable();// booking_services.id — wired Batch 2
            $t->int('membership_plan_id')->unsigned()->nullable();// membership_plans.id (plan_type=course) — Batch 3
            $t->tinyInt('day_of_week');                           // 0=Sun … 6=Sat (PHP date('w'))
            $t->string('start_time', 8);                          // 'HH:MM' (no time() builder type)
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
            $t->index(['tenant_id', 'style', 'level'], 'idx_tenant_style_level');
            $t->index(['tenant_id', 'is_active'], 'idx_tenant_active');
            $t->index(['tenant_id', 'day_of_week'], 'idx_tenant_dow');
        });

        // One dated session of a series. appointment_id links to a booking row
        // once the BookingAdapter (Batch 2) books it.
        $s->create('studio_class_occurrences', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->bigInt('series_id')->unsigned();                  // studio_class_series.id
            $t->int('appointment_id')->unsigned()->nullable();    // booking_appointments.id (INT) — Batch 2
            $t->date('occurrence_date');
            $t->string('start_time', 8);
            $t->string('end_time', 8);
            $t->enum('status', ['scheduled', 'cancelled', 'completed'])->default('scheduled');
            $t->datetime('created_at')->useCurrent();
            $t->unique(['tenant_id', 'series_id', 'occurrence_date'], 'uniq_series_date');
            $t->index(['tenant_id', 'series_id', 'occurrence_date'], 'idx_tenant_series_date');
        });

        // A student's place in a series. subscription_id links to a membership
        // subscription once billing is wired (Batch 3).
        $s->create('studio_enrollments', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->bigInt('student_id')->unsigned();                 // contacts.id
            $t->bigInt('series_id')->unsigned();                  // studio_class_series.id
            $t->int('subscription_id')->unsigned()->nullable();   // membership_subscriptions.id (INT) — Batch 3
            $t->date('enrolled_at');
            $t->date('dropped_at')->nullable();
            $t->enum('status', ['active', 'inactive', 'waitlist', 'trial', 'dropped'])->default('active');
            $t->json('meta')->nullable();
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at')->useCurrent();
            $t->index(['tenant_id', 'student_id', 'status'], 'idx_tenant_student_status');
            $t->index(['tenant_id', 'series_id', 'status'], 'idx_tenant_series_status');
        });

        // Per-occurrence, per-student attendance. Unique so re-marking updates
        // in place rather than duplicating.
        $s->create('studio_attendance', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->bigInt('occurrence_id')->unsigned();              // studio_class_occurrences.id
            $t->bigInt('student_id')->unsigned();                 // contacts.id
            $t->enum('status', ['present', 'absent', 'excused', 'late'])->default('present');
            $t->text('notes')->nullable();
            $t->bigInt('marked_by')->unsigned()->nullable();      // instructor contact id
            $t->datetime('marked_at')->useCurrent();
            $t->unique(['tenant_id', 'occurrence_id', 'student_id'], 'uniq_occurrence_student');
        });
    }

    public function down(Schema $s): void
    {
        // Children before parents (all empty at this stage anyway).
        $s->dropIfExists('studio_attendance');
        $s->dropIfExists('studio_enrollments');
        $s->dropIfExists('studio_class_occurrences');
        $s->dropIfExists('studio_class_series');
        $s->dropIfExists('studio_family_members');
        $s->dropIfExists('studio_families');
        $s->dropIfExists('studio_contact_roles');
    }
};
