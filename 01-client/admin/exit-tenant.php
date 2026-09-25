<?php
/**
 * Slate — Exit Tenant (Phase 1E B2).
 *
 * Tiny, single-purpose companion to the "Enter Tenant" action in
 * admin/tenants.php: unsets $_SESSION['slate_override_tenant'], returning
 * current_tenant_id() to its normal resolution (the admin's own home
 * tenant / the CLI/default), and records the exit.
 *
 * Route: POST /admin/exit-tenant.php
 */
require_once dirname(__DIR__) . '/config.php';
Auth::require();
// Tenant override is internal support tooling, never a tenant-admin feature.
Auth::requirePlatformAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header('Location: ' . SLATE_URL . '/admin/');
    exit;
}

if (isset($_SESSION['slate_override_tenant'])) {
    $exitedTenantId = (int) $_SESSION['slate_override_tenant'];
    unset($_SESSION['slate_override_tenant']);
    // Recorded AFTER unsetting, so current_tenant_id() has already returned
    // to the admin's own home tenant by the time this is written — the
    // mirror image of "enter", which is intentionally recorded while still
    // inside the entered tenant's context.
    AuditLog::record('tenant.exited', "tenant#$exitedTenantId", ['ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
}

header('Location: ' . SLATE_URL . '/admin/tenants.php');
exit;
