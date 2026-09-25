<?php
/**
 * 0006_studio_recitals — recitals, costumes and tickets (additive).
 *
 * The end-of-year show is what a dance studio organises its calendar around,
 * and it is three jobs the core schema can't express: which classes perform in
 * which show, what each dancer must be measured for and has paid for, and who
 * bought seats.
 *
 * Four tenant-scoped tables:
 *   studio_recitals          a show: name, date, venue, ticket price
 *   studio_recital_pieces    a class performing in it (the running order)
 *   studio_costumes          a costume owed by one student for one piece,
 *                            with sizing and its own paid state
 *   studio_recital_tickets   a ticket order against a recital
 *
 * Contact FKs are BIGINT UNSIGNED to match contacts.id, as in 0005. Money is
 * integer minor units (ADR-0011) — never a float, never a DECIMAL we would then
 * have to round consistently in two languages.
 *
 * PURELY ADDITIVE: nothing existing is read or altered. down() drops
 * children-first so a rollback is clean.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        // A show. Several per year is normal (winter showcase, spring recital),
        // so this is not a singleton settings row.
        $s->create('studio_recitals', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->string('name', 190);
            $t->date('recital_date')->nullable();
            $t->string('venue', 190)->nullable();
            $t->string('call_time', 16)->nullable();          // "16:30" — dancers arrive
            $t->string('doors_time', 16)->nullable();         // "18:00" — audience arrive
            $t->int('ticket_price_cents')->unsigned()->default(0);
            $t->string('currency', 3)->default('USD');
            $t->int('seats_total')->unsigned()->nullable();   // null = untracked seating
            $t->enum('status', ['draft', 'published', 'cancelled', 'done'])->default('draft');
            $t->text('notes')->nullable();
            $t->json('meta')->nullable();
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at')->useCurrent();
            $t->index(['tenant_id', 'status'], 'idx_tenant_status');
            $t->index(['tenant_id', 'recital_date'], 'idx_tenant_date');
        });

        // One class performing in one recital. `position` is the running order;
        // a class can appear in several shows, hence a join table rather than a
        // column on the series.
        $s->create('studio_recital_pieces', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->bigInt('recital_id')->unsigned();
            $t->bigInt('series_id')->unsigned();              // studio_class_series.id
            $t->string('title', 190)->nullable();             // defaults to the class name
            $t->string('music', 190)->nullable();
            $t->int('position')->unsigned()->default(0);
            $t->json('meta')->nullable();
            $t->datetime('created_at')->useCurrent();
            $t->unique(['tenant_id', 'recital_id', 'series_id'], 'uniq_recital_series');
            $t->index(['tenant_id', 'recital_id', 'position'], 'idx_running_order');
        });

        // What one dancer owes for one piece. Sizing is free text on purpose:
        // costume houses size by their own charts ("Child L", "AXS", "6x"), and
        // an enum would be wrong within a season.
        $s->create('studio_costumes', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->bigInt('piece_id')->unsigned();               // studio_recital_pieces.id
            $t->bigInt('student_id')->unsigned();             // contacts.id
            $t->string('size', 40)->nullable();
            $t->json('measurements')->nullable();             // girth/height/etc, studio's own keys
            $t->int('cost_cents')->unsigned()->default(0);
            $t->string('currency', 3)->default('USD');
            $t->enum('status', ['pending', 'measured', 'ordered', 'received', 'distributed'])->default('pending');
            $t->boolean('paid')->default(false);
            $t->datetime('paid_at')->nullable();
            $t->text('notes')->nullable();
            $t->json('meta')->nullable();
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at')->useCurrent();
            $t->unique(['tenant_id', 'piece_id', 'student_id'], 'uniq_piece_student');
            $t->index(['tenant_id', 'status'], 'idx_tenant_status');
            $t->index(['tenant_id', 'student_id'], 'idx_tenant_student');
        });

        // A ticket order. Quantity rather than a row per seat: studio shows are
        // general admission far more often than reserved seating, and a seat map
        // is a much bigger feature than this earns.
        $s->create('studio_recital_tickets', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->bigInt('recital_id')->unsigned();
            $t->bigInt('family_id')->unsigned()->nullable();  // studio_families.id
            $t->bigInt('purchaser_id')->unsigned()->nullable(); // contacts.id (walk-ups have neither)
            $t->string('purchaser_name', 190)->nullable();
            $t->string('purchaser_email', 190)->nullable();
            $t->int('quantity')->unsigned()->default(1);
            $t->int('amount_cents')->unsigned()->default(0);
            $t->string('currency', 3)->default('USD');
            $t->enum('status', ['reserved', 'paid', 'cancelled', 'refunded'])->default('reserved');
            $t->string('reference', 64)->nullable();          // what the door checks
            $t->int('charge_id')->unsigned()->nullable();     // stripepayment_charges.id
            $t->json('meta')->nullable();
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at')->useCurrent();
            $t->index(['tenant_id', 'recital_id', 'status'], 'idx_tenant_recital_status');
            $t->index(['tenant_id', 'reference'], 'idx_tenant_reference');
        });
    }

    public function down(Schema $s): void
    {
        // Children before parents.
        $s->dropIfExists('studio_recital_tickets');
        $s->dropIfExists('studio_costumes');
        $s->dropIfExists('studio_recital_pieces');
        $s->dropIfExists('studio_recitals');
    }
};
