<?php
/**
 * 0004_content_revisions — Phase 3A content revision store (additive).
 *
 * The one gap the live content model has today: content-builder's savePost
 * overwrites `layout` in place, so there is no history and no separate "working"
 * draft to preview. This adds an immutable, append-only snapshot store.
 *
 * OWNER-AGNOSTIC by design (docs/09-Roadmap/phase3a-content-design.md §3): a
 * revision references its content via (owner_type, owner_id), NOT a foreign key to
 * one table. Today owner_type = 'content-builder:post' and owner_id is a
 * contentbuilder_posts.id; if a core `content_pages` store is introduced later
 * (design note Option B), revisions attach to it with zero schema change.
 *
 *   status = 'working'   the editable draft an author previews (pipeline §5)
 *          = 'published'  a live snapshot ("publish" copies working → new published)
 *          = 'archived'   retained history
 *
 * `document` is the canonical versioned envelope (DocumentSchema::VERSION in
 * `schema_version`). owner_id is BIGINT to be id-source-agnostic (contact ids are
 * BIGINT); author_id mirrors the users table (INT UNSIGNED) and is nullable.
 *
 * PURELY ADDITIVE: no existing table or reader is touched; contentbuilder_* is
 * untouched. Reversible: down() drops the table (empty at introduction).
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $s->create('content_revisions', function (Table $t) {
            $t->id();                                              // BIGINT UNSIGNED AUTO_INCREMENT PK
            $t->int('tenant_id')->unsigned()->default(1);
            $t->string('owner_type', 32);                          // 'content-builder:post', …
            $t->bigInt('owner_id')->unsigned();                    // e.g. contentbuilder_posts.id
            $t->int('revision')->unsigned();                       // monotonic per (tenant,owner_type,owner_id)
            $t->enum('status', ['working', 'published', 'archived'])->default('working');
            $t->json('document');                                  // canonical document envelope
            $t->int('schema_version')->unsigned()->default(1);
            $t->int('author_id')->unsigned()->nullable();
            $t->string('note', 190)->nullable();
            $t->datetime('created_at')->useCurrent();
            $t->unique(['tenant_id', 'owner_type', 'owner_id', 'revision'], 'uniq_owner_revision');
            $t->index(['tenant_id', 'owner_type', 'owner_id', 'status'], 'idx_owner_status');
        });
    }

    public function down(Schema $s): void
    {
        // Empty at this stage — clean rollback.
        $s->dropIfExists('content_revisions');
    }
};
