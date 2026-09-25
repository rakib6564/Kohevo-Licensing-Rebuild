<?php
/**
 * 0007_studio_fees — the non-tuition money ledger (additive).
 *
 * Tuition is already modelled: an enrolment carries its own paid state in
 * studio_enrollments.meta. But a studio bills a parent for several things that
 * are not tuition and do not belong to any one enrolment:
 *
 *   registration   once per family, at sign-up
 *   recital        once per FAMILY at a taper (first child full, siblings
 *                  reduced) — so it cannot hang off a student row
 *   costume        once per costumed CLASS, split across instalments
 *   tights         once per student, however many costumes they need
 *
 * Each of those is a dated obligation with its own paid state, and the costume
 * ones arrive in instalments months apart (Company B: 50% Nov 1, 50% Feb 1).
 * That is a ledger, not a flag on another row — hence one table.
 *
 * Why a row per instalment rather than a schedule computed on read: a parent
 * pays instalment 1 and not instalment 2, and the studio needs to chase
 * exactly that. Storing the split makes "who owes what on Feb 1" a WHERE
 * clause instead of a recomputation that has to agree with what was quoted in
 * November — and the amounts must never drift after they are quoted.
 *
 * Money is integer minor units (ADR-0011). Contact FKs are BIGINT UNSIGNED to
 * match contacts.id, as in 0005 and 0006.
 *
 * PURELY ADDITIVE: nothing existing is read or altered.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $s->create('studio_fees', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);

            // Who owes it. family_id is always set — the bill goes to a family
            // even when it was raised for one child. student_id is nullable
            // because a registration or a family recital fee has no single
            // student behind it.
            $t->bigInt('family_id')->unsigned();
            $t->bigInt('student_id')->unsigned()->nullable();

            // What it is for. series_id ties a costume fee to the class that
            // generated it, which is what makes regeneration idempotent and
            // lets a dropped enrolment void the right row.
            $t->enum('kind', ['registration', 'recital', 'costume', 'tights', 'other'])
              ->default('other');
            $t->bigInt('series_id')->unsigned()->nullable();
            $t->bigInt('recital_id')->unsigned()->nullable();

            // What the parent sees on the statement. Stored, not derived: the
            // class could later be renamed or deactivated and a historic bill
            // must still read the way it did when it was raised.
            $t->string('label', 190);

            $t->int('amount_cents')->unsigned()->default(0);
            $t->string('currency', 3)->default('USD');
            $t->date('due_date')->nullable();

            // 1-of-2, 2-of-2 … for a split charge. Both default 1 so a
            // single-payment fee needs no special-casing on read.
            $t->tinyInt('instalment_no')->unsigned()->default(1);
            $t->tinyInt('instalment_of')->unsigned()->default(1);

            // waived = the studio forgave it (still visible, still auditable).
            // void   = it should never have been raised (dropped enrolment).
            // Neither is a delete: a parent asking "what happened to that $95"
            // deserves an answer.
            $t->enum('status', ['pending', 'paid', 'waived', 'void'])->default('pending');
            $t->datetime('paid_at')->nullable();
            $t->int('paid_cents')->unsigned()->default(0);
            $t->string('paid_method', 32)->nullable();        // stripe | offline
            $t->string('charge_id', 64)->nullable();          // shared payment ledger

            // Regeneration key. Two runs of the costume generator for the same
            // student+class+instalment must not raise two bills, and a UNIQUE
            // index is the only guard that survives a concurrent double-submit.
            $t->string('dedupe_key', 190)->nullable();

            $t->longText('meta')->nullable();
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();

            $t->unique(['tenant_id', 'dedupe_key'], 'uq_studio_fees_dedupe');
            $t->index(['tenant_id', 'family_id', 'status'], 'ix_studio_fees_family');
            $t->index(['tenant_id', 'status', 'due_date'], 'ix_studio_fees_due');
            $t->index(['tenant_id', 'student_id'], 'ix_studio_fees_student');
        });
    }

    public function down(Schema $s): void
    {
        $s->dropIfExists('studio_fees');
    }
};
