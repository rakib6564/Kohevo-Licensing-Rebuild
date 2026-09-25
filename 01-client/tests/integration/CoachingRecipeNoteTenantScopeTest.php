<?php
/**
 * Phase 1B H3 — coaching recipe-note tenant scope.
 *
 * plugins/coaching/admin/library.php's save_customer_recipe_note action
 * looked up and updated `coaching_recipe` by `id = ?` alone — every sibling
 * action in the same file (saveRecipe/deleteRecipe and their meal-structure/
 * shopping-list equivalents) scopes by `tenant_id = ?` too. A staff member
 * holding coaching.manage_library in ANY tenant could overwrite another
 * tenant's customer-submitted recipe note by id.
 *
 * Reachability note: this install serves one ambient tenant per request
 * (TENANT_ID), so "another tenant's staff" isn't reachable over a normal
 * HTTP request today — but current_tenant_id() CAN legitimately differ from
 * a row's owning tenant within one process (TenantContext::runAs()/
 * with_tenant(), already live for e.g. the MCP gateway's per-token tenant
 * scoping). This test drives the real page via admin-page-post-probe.php's
 * tenantOverride param — the same $GLOBALS['SLATE_TENANT_OVERRIDE']
 * mechanism runAs()/with_tenant() use — to prove the query itself enforces
 * the boundary, independent of how a caller ever ends up running under a
 * foreign tenant.
 */

declare(strict_types=1);

use Slate\Tenancy\TenantContext;

/** Run admin/library.php's POST handler in a child process under a given tenant. Returns [status, body]. */
function crnts_post(array $fields, int $roleId, int $tenantOverride): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-probe.php') . ' '
         . escapeshellarg('plugins/coaching/admin/library.php') . ' '
         . escapeshellarg((string) json_encode($fields)) . ' '
         . escapeshellarg((string) $roleId) . ' 0 '
         . escapeshellarg((string) $tenantOverride) . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) return [0, $out];
    return [(int) $m[1], substr($out, strlen($m[0]))];
}

/** A throwaway role, scoped to $tid, granting coaching.manage_library. Returns role id. */
function crnts_make_library_role(int $tid): int
{
    $roleId = Database::insert('roles', [
        'tenant_id' => $tid, 'name' => 'Probe CRNTS', 'slug' => '__probe-crnts-' . bin2hex(random_bytes(4)), 'is_system' => 0,
    ]);
    Database::insert('role_permissions', ['role_id' => $roleId, 'perm_key' => 'coaching.manage_library', 'granted' => 1]);
    return $roleId;
}

function crnts_drop_role(int $roleId): void
{
    Database::query('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
    Database::query('DELETE FROM roles WHERE id = ?', [$roleId]);
}

require_once dirname(__DIR__, 2) . '/plugins/coaching/CoachingAPI.php';
CoachingAPI::ensureSchema();

$tenantA = current_tenant_id();
$tenantB = $tenantA + 97300; // synthetic foreign tenant, same shape as RbacTenantIsolationTest
$tenants = new TenantContext();

unit('coaching library: a staff member in a different tenant cannot overwrite this tenant\'s customer recipe note', function () use ($tenantA, $tenantB, $tenants): void {
    $recipeId = CoachingAPI::saveRecipe(['author' => 'customer', 'title' => 'Probe Recipe', 'notes' => 'original note']);
    assert_true($recipeId > 0, 'setup: recipe must be created under tenant A');

    $foreignRole = $tenants->runAs($tenantB, fn () => crnts_make_library_role($tenantB));

    try {
        [$status] = crnts_post(
            ['_action' => 'save_customer_recipe_note', 'id' => $recipeId, 'notes' => 'OVERWRITTEN BY TENANT B'],
            $foreignRole,
            $tenantB
        );
        assert_eq(200, $status, 'the page itself still renders (no crash) for a denied cross-tenant id');

        $row = Database::row('SELECT tenant_id, notes FROM coaching_recipe WHERE id = ?', [$recipeId]);
        assert_true($row !== null, 'the recipe must still exist');
        assert_eq($tenantA, (int) $row['tenant_id'], 'tenant ownership must not change');
        assert_eq('original note', $row['notes'], 'a different tenant\'s staff must not be able to overwrite this note');
    } finally {
        $tenants->runAs($tenantB, fn () => crnts_drop_role($foreignRole));
        Database::query('DELETE FROM coaching_recipe WHERE id = ?', [$recipeId]);
    }
});

unit('coaching library: the owning tenant\'s staff can still save the customer recipe note', function () use ($tenantA): void {
    $recipeId = CoachingAPI::saveRecipe(['author' => 'customer', 'title' => 'Probe Recipe 2', 'notes' => 'original note']);
    assert_true($recipeId > 0);

    $roleId = crnts_make_library_role($tenantA);

    try {
        [$status] = crnts_post(
            ['_action' => 'save_customer_recipe_note', 'id' => $recipeId, 'notes' => 'updated by owning tenant'],
            $roleId,
            $tenantA
        );
        assert_eq(200, $status);

        $row = Database::row('SELECT notes FROM coaching_recipe WHERE id = ?', [$recipeId]);
        assert_eq('updated by owning tenant', $row['notes'] ?? null, 'the existing sibling-action pattern (same-tenant staff can edit) must keep working');
    } finally {
        crnts_drop_role($roleId);
        Database::query('DELETE FROM coaching_recipe WHERE id = ?', [$recipeId]);
    }
});
