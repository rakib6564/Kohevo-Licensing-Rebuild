<?php
/**
 * Slate — 0019_content_pages: the owning row for Phase 2 structured-document
 * content, replacing the archived content-builder plugin's contentbuilder_posts
 * (which no longer exists in plugins/ or the database on this branch).
 *
 * Addressing data only — tenant, type, title, slug, status. The document tree
 * itself lives exclusively in content_revisions via RevisionStore; this table
 * never carries a layout/document column (Phase 2 spec: "the row remains
 * authoritative for tenant, route, locale, slug, status, and ownership").
 *
 * owner_type = 'content_pages' is what RevisionStore/ContentPublicationService/
 * CompilationStore/DependencyStore key their rows against for this admin editor.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $s->create('content_pages', function (Table $t): void {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->string('type', 32);
            $t->string('title', 190);
            $t->string('slug', 190);
            $t->enum('status', ['draft', 'published'])->default('draft');
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at')->useCurrent();
            $t->unique(['tenant_id', 'type', 'slug'], 'uniq_content_page_slug');
            $t->index(['tenant_id', 'type', 'status'], 'idx_content_page_listing');
        });
    }

    public function down(Schema $s): void
    {
        $s->dropIfExists('content_pages');
    }
};
