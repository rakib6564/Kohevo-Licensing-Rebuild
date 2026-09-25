<?php
/**
 * 0023_installation_identity — one durable identity for one deployed site.
 *
 * This is deliberately a new forward migration. Historical migrations remain
 * untouched. `singleton_id=1` makes the one-install invariant explicit while
 * unique tenant_id and installation_id protect the relationship at the DB
 * boundary as well as in the installer service.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $s->create('installation_identity', function (Table $t) {
            $t->tinyInt('singleton_id')->unsigned()->default(1);
            $t->int('tenant_id')->unsigned();
            $t->char('installation_id', 32);
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at')->useCurrent();
            $t->primary('singleton_id');
            $t->unique('tenant_id', 'uniq_installation_identity_tenant');
            $t->unique('installation_id', 'uniq_installation_identity_value');
        });
    }

    public function down(Schema $s): void
    {
        $s->dropIfExists('installation_identity');
    }
};
