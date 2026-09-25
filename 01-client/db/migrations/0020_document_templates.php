<?php
/**
 * Slate — 0020_document_templates: reusable, tenant-owned page templates for
 * admin/templates.php's Template Library.
 *
 * Stores a full schema-1 DocumentSchema document (validated before insert, see
 * Slate\Services\Content\DocumentTemplateRepository), so "Use Template" seeds a
 * new editor draft from real, previously-authored content instead of a static
 * placeholder card. `document` is the same JSON shape RevisionStore persists for
 * a page — no bespoke template format.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $s->create('document_templates', function (Table $t): void {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->string('name', 190);
            $t->string('type', 32)->default('page');
            $t->json('document');
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at')->useCurrent();
            $t->index(['tenant_id', 'type'], 'idx_document_template_listing');
        });
    }

    public function down(Schema $s): void
    {
        $s->dropIfExists('document_templates');
    }
};
