<?php
/**
 * 0012_contentbuilder_draft_published — Draft vs Published data columns for contentbuilder_posts.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        if ($s->hasTable('contentbuilder_posts')) {
            $s->table('contentbuilder_posts', function (Table $t) {
                $t->text('draft_data')->nullable();
                $t->text('published_data')->nullable();
            });
        }
    }

    public function down(Schema $s): void
    {
        if ($s->hasTable('contentbuilder_posts')) {
            $s->table('contentbuilder_posts', function (Table $t) {
                $t->dropColumn('draft_data');
                $t->dropColumn('published_data');
            });
        }
    }
};
