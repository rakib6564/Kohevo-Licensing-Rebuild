<?php
/**
 * 0009_studio_occurrence_note — why a lesson was cancelled.
 *
 * studio_class_occurrences has carried a `status` column since 0005
 * (scheduled | cancelled | completed) and nothing ever set it to anything but
 * 'scheduled' — there was no way to cancel a single lesson. Adding that is
 * most of the value; this migration adds the one thing the existing schema
 * cannot express, which is WHY.
 *
 * A cancellation without a reason is close to useless: "snow" and "teacher
 * sick" and "hall double-booked" lead to different conversations with a
 * parent, and the studio needs to be able to say which it was weeks later.
 *
 * `cancelled_at` is separate from the note so the timeline is queryable —
 * "how many lessons did we lose in January" is a WHERE, not a string search.
 *
 * PURELY ADDITIVE: two nullable columns on an existing table. Existing rows
 * are untouched and read as "never cancelled", which is correct.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $s->table('studio_class_occurrences', function (Table $t) {
            $t->string('note', 190)->nullable();       // "Snow — Seaford closed"
            $t->datetime('cancelled_at')->nullable();
        });
    }

    public function down(Schema $s): void
    {
        $s->table('studio_class_occurrences', function (Table $t) {
            $t->drop('note');
            $t->drop('cancelled_at');
        });
    }
};
