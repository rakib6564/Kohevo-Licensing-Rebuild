<?php
/**
 * Phase 1E B1 — Tenant Management (admin/tenants.php, TenantService).
 *
 * `tenants` is a live production table (id, name, slug, status, created_at)
 * — this finding never alters it. All new SaaS metadata lives in the new,
 * purely additive `tenant_profiles` companion table (migration 0014),
 * keyed 1:1 on tenant_id. TenantService owns every read/write to both
 * tables; this suite drives the real admin page + service directly.
 */

declare(strict_types=1);

use Slate\Services\Tenancy\TenantService;

function tmt_probe_get(string $page, string $query = '', int $roleId = 1, bool $platformAdmin = false): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-probe.php') . ' '
         . escapeshellarg($page) . ' ' . escapeshellarg($query) . ' '
         . escapeshellarg((string) $roleId) . ' ' . escapeshellarg($platformAdmin ? '1' : '0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
        throw new RuntimeException('probe did not return a STATUS line: ' . $out);
    }
    return ['status' => (int) $m[1], 'body' => substr($out, strlen($m[0]))];
}

function tmt_probe_post(array $fields, int $roleId = 1, bool $platformAdmin = false): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-probe.php') . ' '
         . escapeshellarg('admin/tenants.php') . ' '
         . escapeshellarg((string) json_encode($fields)) . ' '
         . escapeshellarg((string) $roleId) . ' ' . escapeshellarg($platformAdmin ? '1' : '0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
        throw new RuntimeException('probe did not return a STATUS line: ' . $out);
    }
    return ['status' => (int) $m[1], 'body' => substr($out, strlen($m[0]))];
}

function tmt_cleanup(int $tenantId): void
{
    Database::query("DELETE FROM tenant_profiles WHERE tenant_id = ?", [$tenantId]);
    Database::query("DELETE FROM tenants WHERE id = ?", [$tenantId]);
}

unit('admin/tenants.php: an ordinary tenant admin is refused (403) — tenant management is platform-only', function (): void {
    $res = tmt_probe_get('admin/tenants.php', '', 5101, false);
    assert_eq(403, $res['status']);
});

unit('TenantService::create() creates both the tenants row and its tenant_profiles companion, and rejects a duplicate slug', function (): void {
    $slug = 'probe-tenant-' . bin2hex(random_bytes(4));
    $id = TenantService::create([
        'name' => 'Probe Tenant', 'slug' => $slug,
        'owner_name' => 'Jane Owner', 'owner_email' => 'jane@example.test',
        'lifecycle_status' => 'trial', 'timezone' => 'America/New_York', 'locale' => 'fr',
    ]);
    try {
        assert_true($id > 0);

        $tenantRow = Database::row("SELECT name, slug, status FROM tenants WHERE id = ?", [$id]);
        assert_eq('Probe Tenant', $tenantRow['name']);
        assert_eq($slug, $tenantRow['slug']);
        assert_eq('active', $tenantRow['status'], 'the legacy tenants.status column is untouched by the new lifecycle concept');

        $profile = Database::row("SELECT owner_name, owner_email, lifecycle_status, timezone, locale FROM tenant_profiles WHERE tenant_id = ?", [$id]);
        assert_true($profile !== null, 'a tenant_profiles row must be created alongside the tenants row');
        assert_eq('Jane Owner', $profile['owner_name']);
        assert_eq('jane@example.test', $profile['owner_email']);
        assert_eq('trial', $profile['lifecycle_status']);
        assert_eq('America/New_York', $profile['timezone']);
        assert_eq('fr', $profile['locale']);

        assert_throws(\InvalidArgumentException::class, fn() => TenantService::create(['name' => 'Duplicate', 'slug' => $slug]), 'creating a tenant with an already-used slug must be rejected');

        $audit = Database::row("SELECT 1 FROM audit_log WHERE action = 'tenant.created' AND target = ? ORDER BY id DESC LIMIT 1", ["tenant#$id"]);
        assert_true($audit !== null, 'creating a tenant must record an audit_log entry');
    } finally {
        tmt_cleanup($id);
    }
});

unit('TenantService::create() rejects an invalid slug', function (): void {
    assert_throws(\InvalidArgumentException::class, fn() => TenantService::create(['name' => 'Bad Slug', 'slug' => 'Not Valid!']));
    assert_throws(\InvalidArgumentException::class, fn() => TenantService::create(['name' => '', 'slug' => 'no-name']));
});

unit('TenantService::list() supports search and status filtering', function (): void {
    $suffix = bin2hex(random_bytes(4));
    $id1 = TenantService::create(['name' => "Findme Alpha $suffix", 'slug' => "findme-alpha-$suffix", 'lifecycle_status' => 'trial']);
    $id2 = TenantService::create(['name' => "Findme Beta $suffix", 'slug' => "findme-beta-$suffix", 'lifecycle_status' => 'active']);
    try {
        $bySearch = TenantService::list(['search' => "Findme Alpha $suffix"]);
        assert_eq(1, count($bySearch));
        assert_eq($id1, (int) $bySearch[0]['id']);

        $byStatus = TenantService::list(['status' => 'active', 'search' => $suffix]);
        assert_eq(1, count($byStatus));
        assert_eq($id2, (int) $byStatus[0]['id']);

        $all = TenantService::list(['search' => $suffix]);
        assert_eq(2, count($all), 'search with no status filter must return both');
    } finally {
        tmt_cleanup($id1);
        tmt_cleanup($id2);
    }
});

unit('TenantService::transitionStatus() validates the target status, is a no-op when unchanged, and audits real transitions', function (): void {
    $id = TenantService::create(['name' => 'Transition Probe', 'slug' => 'transition-probe-' . bin2hex(random_bytes(4)), 'lifecycle_status' => 'trial']);
    try {
        assert_throws(\InvalidArgumentException::class, fn() => TenantService::transitionStatus($id, 'not-a-real-status'));
        assert_throws(\InvalidArgumentException::class, fn() => TenantService::transitionStatus(999999999, 'active'));

        assert_false(TenantService::transitionStatus($id, 'trial'), 'transitioning to the same status must be a no-op returning false');

        assert_true(TenantService::transitionStatus($id, 'active'));
        $status = Database::value("SELECT lifecycle_status FROM tenant_profiles WHERE tenant_id = ?", [$id]);
        assert_eq('active', $status);

        $audit = Database::row("SELECT meta_json FROM audit_log WHERE action = 'tenant.status_changed' AND target = ? ORDER BY id DESC LIMIT 1", ["tenant#$id"]);
        assert_true($audit !== null);
        $meta = json_decode((string) $audit['meta_json'], true);
        assert_eq('trial', $meta['from']);
        assert_eq('active', $meta['to']);
    } finally {
        tmt_cleanup($id);
    }
});

unit('admin/tenants.php: creating a tenant through the real page persists it and redirects', function (): void {
    $slug = 'page-create-' . bin2hex(random_bytes(4));
    $res = tmt_probe_post([
        '_action' => 'create', 'name' => 'Page Created Tenant', 'slug' => $slug,
        'owner_email' => 'owner@example.test', 'timezone' => 'UTC', 'locale' => 'en',
        'lifecycle_status' => 'trial',
    ]);
    $id = (int) Database::value("SELECT id FROM tenants WHERE slug = ?", [$slug]);
    try {
        assert_eq(302, $res['status'], 'creating via the real page must redirect on success: ' . $res['body']);
        assert_true($id > 0, 'the tenant must actually exist after the POST');
    } finally {
        if ($id > 0) tmt_cleanup($id);
    }
});

unit('admin/tenants.php: an invalid slug is rejected inline (no redirect, nothing created)', function (): void {
    $before = (int) Database::value("SELECT COUNT(*) FROM tenants");
    $res = tmt_probe_post(['_action' => 'create', 'name' => 'Bad', 'slug' => 'Not A Slug!']);
    $after = (int) Database::value("SELECT COUNT(*) FROM tenants");

    assert_eq(200, $res['status'], 'a validation failure must render inline, not redirect');
    assert_eq($before, $after, 'no tenant must be created on validation failure');
});

unit('admin/tenants.php: a CSRF-invalid create request is rejected', function (): void {
    $slug = 'csrf-create-' . bin2hex(random_bytes(4));
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-badcsrf-probe.php') . ' '
         . escapeshellarg('admin/tenants.php') . ' '
         . escapeshellarg((string) json_encode(['_action' => 'create', 'name' => 'CSRF Bypass', 'slug' => $slug])) . ' '
         . escapeshellarg('1') . ' ' . escapeshellarg('0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
        throw new RuntimeException('probe did not return a STATUS line: ' . $out);
    }
    assert_null(Database::value("SELECT id FROM tenants WHERE slug = ?", [$slug]), 'no tenant must be created when csrf_verify() rejects the request');
});
