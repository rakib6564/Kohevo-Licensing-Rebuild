<?php
/**
 * 0013_platform_admins — platform-scoped authority, independent of role_id.
 *
 * Prerequisite for separating "Platform Super Admin" from any tenant's role
 * system (Phase 0E RBAC audit). Today `Auth::isSuperAdmin()` is defined as
 * `role_id === 1`, which only works because `roles.id` is a single global
 * primary key — no other tenant can ever have a role with id 1. That's fine
 * for a single-tenant install but has no way to express a platform
 * administrator once a second tenant exists.
 *
 * This table is purely additive and deliberately has no `tenant_id` column —
 * platform-admin status is not scoped to any tenant. Membership is checked by
 * existence (unique on user_id), so granting/revoking is idempotent.
 *
 * Nothing is auto-populated here. The existing role_id=1 Super Admin remains
 * fully functional via the legacy branch in Auth::isSuperAdmin() — assigning
 * someone into this table is a deliberate, separate operator action.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $s->create('platform_admins', function (Table $t) {
            $t->id();
            $t->int('user_id')->unsigned();
            $t->int('granted_by')->unsigned()->nullable();
            $t->datetime('granted_at')->useCurrent();
            $t->unique('user_id', 'uniq_platform_admin_user');
        });
    }

    public function down(Schema $s): void
    {
        $s->dropIfExists('platform_admins');
    }
};
