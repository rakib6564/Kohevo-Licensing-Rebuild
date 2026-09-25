<?php
/**
 * 0024_remote_license_metadata — preserve the signed server timing fields
 * alongside the latest verified remote license state.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $s->table('remote_license_cache', function (Table $t) {
            $t->datetime('remote_checked_at')->nullable();
            $t->int('next_check_after')->nullable();
        });
    }

    public function down(Schema $s): void
    {
        $s->table('remote_license_cache', function (Table $t) {
            $t->drop('remote_checked_at');
            $t->drop('next_check_after');
        });
    }
};
