<?php
/**
 * 0003_seed_contacts — backfill the identity spine from `customers`.
 *
 * Runs ContactSeeder::run() (idempotent, id-preserving; see the class + design
 * A1). Legacy `customers`/`customer_auth_tokens` are the READ authority until the
 * B4 cutover — this only *populates* the new tables; it does not switch any
 * reader. Existing password hashes are copied verbatim.
 *
 * PRE-APPLY GATE: run ContactSeeder::plan() first and review the counts (done in
 * B5 verification) before applying in any real environment.
 *
 * down() clears the seeded rows. Safe at this stage because the spine tables are
 * only populated by this seed (no runtime writers until B4/B6); it never touches
 * `customers`.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Services\Identity\ContactSeeder;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        (new ContactSeeder())->run();
    }

    public function down(Schema $s): void
    {
        // Seed rollback (pre-cutover only): empty the spine, leave customers intact.
        $s->raw('DELETE FROM identity_tokens');
        $s->raw('DELETE FROM identities');
        $s->raw('DELETE FROM contact_phones');
        $s->raw('DELETE FROM contact_emails');
        $s->raw('DELETE FROM contacts');
    }
};
