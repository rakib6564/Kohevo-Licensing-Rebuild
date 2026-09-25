<?php
/**
 * 0011_login_attempts — persistent login throttling records.
 *
 * The authentication service uses this table for IP-bound, database-clocked
 * login lockout. It is intentionally tenant-scoped so cleanup and analysis do
 * not cross tenant boundaries.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $s->create('login_attempts', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->string('scope', 16);
            $t->string('ip', 45);
            $t->string('identifier', 190)->default('');
            $t->datetime('attempted_at')->useCurrent();
            $t->index(['scope', 'ip', 'attempted_at'], 'idx_scope_ip');
            $t->index(['tenant_id', 'attempted_at'], 'idx_tenant_time');
        });
    }

    public function down(Schema $s): void
    {
        $s->dropIfExists('login_attempts');
    }
};
