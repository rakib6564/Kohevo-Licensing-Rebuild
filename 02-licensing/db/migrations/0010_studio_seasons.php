<?php
/**
 * 0010_studio_seasons — group a term's classes, so the next one can be cloned.
 *
 * A studio rebuilds its whole timetable every term. Today that means fifteen
 * manual forms, each re-typing the same session dates, because nothing groups
 * "the classes we run in Fall/Winter 2026" — every series carries its own
 * session_start/session_end and knows nothing about its neighbours.
 *
 * A season is that grouping, and it is what makes duplication possible: "copy
 * last term, shift the dates" is one action once the set is nameable.
 *
 * season_id is NULLABLE on purpose. Every existing class predates this and
 * belongs to no season; forcing them into a synthetic "Unsorted" season would
 * invent history the studio never had. Unassigned classes keep working exactly
 * as before and simply do not appear in a season view.
 *
 * The dates live on the season AND stay on each series. The season's dates are
 * a default for new classes and the anchor for shifting a clone; the series
 * dates remain authoritative for generation, because a studio legitimately
 * runs one class on a different schedule to the rest of the term (an intensive
 * that finishes early, a class added in week three).
 *
 * PURELY ADDITIVE: one new table, one nullable column.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $s->create('studio_seasons', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);

            $t->string('name', 120);                    // "Fall/Winter 2026–27"
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();

            // Exactly one season is "current" per tenant — it seeds the date
            // fields on a new class and is what the admin defaults to showing.
            // Enforced in StudioAPI rather than by a unique index, because the
            // natural write is "make this one current", which under a unique
            // index would need a delete-then-insert dance.
            $t->boolean('is_current')->default(0);

            $t->string('notes', 255)->nullable();
            $t->longText('meta')->nullable();
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();

            $t->index(['tenant_id', 'is_current'], 'ix_studio_seasons_current');
            $t->index(['tenant_id', 'starts_on'], 'ix_studio_seasons_start');
        });

        $s->table('studio_class_series', function (Table $t) {
            $t->bigInt('season_id')->unsigned()->nullable();
        });
    }

    public function down(Schema $s): void
    {
        $s->table('studio_class_series', function (Table $t) {
            $t->drop('season_id');
        });
        $s->dropIfExists('studio_seasons');
    }
};
