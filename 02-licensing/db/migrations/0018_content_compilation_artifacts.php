<?php
declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $s->create('content_compilations', function (Table $t): void {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->string('owner_type', 32);
            $t->bigInt('owner_id')->unsigned();
            $t->int('revision_id')->unsigned();
            $t->longText('content_html');
            $t->char('content_fingerprint', 64);
            $t->string('renderer_version', 32);
            $t->string('theme_version', 32);
            $t->datetime('created_at')->useCurrent();
            $t->unique(['tenant_id', 'owner_type', 'owner_id', 'revision_id'], 'uniq_compilation_revision');
            $t->index(['tenant_id', 'owner_type', 'owner_id'], 'idx_compilation_owner');
            $t->index(['tenant_id', 'content_fingerprint'], 'idx_compilation_fingerprint');
        });

        $s->create('content_dependencies', function (Table $t): void {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->string('owner_type', 32);
            $t->bigInt('owner_id')->unsigned();
            $t->int('revision_id')->unsigned();
            $t->string('dependency_key', 190);
            $t->datetime('created_at')->useCurrent();
            $t->unique(['tenant_id', 'owner_type', 'owner_id', 'revision_id', 'dependency_key'], 'uniq_content_dependency');
            $t->index(['tenant_id', 'dependency_key'], 'idx_dependency_key');
        });
    }

    public function down(Schema $s): void
    {
        $s->dropIfExists('content_dependencies');
        $s->dropIfExists('content_compilations');
    }
};
