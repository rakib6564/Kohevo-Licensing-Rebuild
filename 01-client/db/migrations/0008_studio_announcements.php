<?php
/**
 * 0008_studio_announcements — targeted broadcast email with a send history.
 *
 * A studio emails its families constantly: snow day, hall change, recital call
 * times, "costume money due Friday". Until now Studio could only send
 * transactional mail triggered by an enrolment or a payment — there was no way
 * to say something to a chosen group of people.
 *
 * Two tables, because "what did we send" and "who actually got it" are
 * different questions and a studio needs both:
 *
 *   studio_announcements            one composed message and its audience
 *   studio_announcement_recipients  one row per person, with the delivery
 *                                   result for THAT person
 *
 * Storing a row per recipient rather than a count is what makes the history
 * useful: a parent who says "I never got that email" is answered by a lookup,
 * not a shrug. It also makes a partial failure visible — 38 of 41 sent is a
 * very different situation from "sent", and a single counter hides it.
 *
 * Recipients are snapshotted (email and name copied at send time) rather than
 * joined on read. The address a message actually went to is a historical fact;
 * if a family later changes their email, the record must still say where it
 * was sent, not where it would be sent today.
 *
 * PURELY ADDITIVE.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $s->create('studio_announcements', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);

            $t->string('subject', 190);
            $t->longText('body');                     // plain text as composed

            // How the audience was chosen, kept so the history explains itself
            // and so a message can be re-sent to the same group later.
            $t->string('audience_kind', 32)->default('all');   // all|class|unpaid|age
            $t->bigInt('audience_ref')->unsigned()->nullable(); // series id, when kind=class
            $t->string('audience_label', 190)->nullable();      // "Beginner Ballet", frozen

            $t->int('recipient_count')->unsigned()->default(0);
            $t->int('sent_count')->unsigned()->default(0);
            $t->int('failed_count')->unsigned()->default(0);

            $t->enum('status', ['draft', 'sending', 'sent', 'failed'])->default('draft');
            $t->int('created_by')->unsigned()->nullable();      // users.id
            $t->datetime('sent_at')->nullable();
            $t->longText('meta')->nullable();
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();

            $t->index(['tenant_id', 'status', 'created_at'], 'ix_studio_ann_status');
        });

        $s->create('studio_announcement_recipients', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->bigInt('announcement_id')->unsigned();
            $t->bigInt('contact_id')->unsigned()->nullable();

            // Snapshotted at send time — see the note above.
            $t->string('email', 190);
            $t->string('name', 190)->nullable();

            $t->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $t->string('error', 255)->nullable();
            $t->datetime('sent_at')->nullable();

            $t->index(['tenant_id', 'announcement_id'], 'ix_studio_annr_ann');
            $t->index(['tenant_id', 'email'], 'ix_studio_annr_email');
        });
    }

    public function down(Schema $s): void
    {
        $s->dropIfExists('studio_announcement_recipients');
        $s->dropIfExists('studio_announcements');
    }
};
