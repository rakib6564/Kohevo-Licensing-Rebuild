<?php
/**
 * Integration test for ContactSeeder — runs against a THROWAWAY tenant with
 * throwaway customers/tokens, so it never disturbs real data. Verifies
 * id-preservation, guest handling, hash-copy (login parity), token seed, and
 * idempotency. Cleans everything up.
 */

declare(strict_types=1);

use Slate\Services\Identity\ContactSeeder;
use Slate\Services\Identity\ContactRepository;
use Slate\Services\Identity\IdentityStore;
use Slate\Tenancy\TenantContext;

define('_SEED_TENANT', slate_test_tenant(917000));

function _seed_wipe(): void
{
    $t = _SEED_TENANT;
    Database::query('DELETE FROM identity_tokens WHERE tenant_id=?', [$t]);
    Database::query('DELETE FROM identities WHERE tenant_id=?', [$t]);
    Database::query('DELETE FROM contact_phones WHERE tenant_id=?', [$t]);
    Database::query('DELETE FROM contact_emails WHERE tenant_id=?', [$t]);
    Database::query('DELETE FROM contacts WHERE tenant_id=?', [$t]);
    // remove throwaway customers + their tokens
    foreach (Database::rows('SELECT id FROM customers WHERE tenant_id=?', [$t]) as $c) {
        Database::query('DELETE FROM customer_auth_tokens WHERE customer_id=?', [(int) $c['id']]);
    }
    Database::query('DELETE FROM customers WHERE tenant_id=?', [$t]);
}

unit('ContactSeeder seeds a tenant id-preserving, copies hashes, handles guests + tokens, idempotent', function () {
    $t = _SEED_TENANT;
    _seed_wipe();
    try {
        // Two throwaway customers: one registered (password), one guest (no hash).
        $regId = Database::insert('customers', [
            'tenant_id' => $t, 'email' => 'seed-reg@example.test',
            'password_hash' => password_hash('seed-pass-123', PASSWORD_DEFAULT),
            'name' => 'Seed Reg', 'phone' => '+1 555 700 0001',
            'status' => 'active', 'email_verified' => 1,
        ]);
        $guestId = Database::insert('customers', [
            'tenant_id' => $t, 'email' => 'seed-guest@example.test',
            'password_hash' => null, 'name' => 'Seed Guest', 'status' => 'guest', 'email_verified' => 0,
        ]);
        // A reset token for the registered customer.
        Database::insert('customer_auth_tokens', [
            'tenant_id' => $t, 'customer_id' => $regId, 'purpose' => 'password_reset',
            'token_hash' => hash('sha256', 'seed-raw-token'), 'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);

        // ── plan (dry-run) reports, writes nothing ──
        $plan = (new ContactSeeder())->plan($t);
        assert_eq(2, $plan['customers']);
        assert_eq(1, $plan['will_create_identities'], 'only the registered customer gets an identity');
        assert_eq(1, $plan['guests']);
        assert_eq(0, (int) Database::value('SELECT COUNT(*) FROM contacts WHERE tenant_id=?', [$t]), 'plan wrote nothing');

        // ── run ──
        $r = (new ContactSeeder())->run($t);
        assert_eq(2, $r['created']);
        assert_eq(1, $r['identities']);
        assert_eq(1, $r['tokens']);

        // id-preservation: contact.id == customers.id
        assert_true(Database::value('SELECT 1 FROM contacts WHERE id=? AND tenant_id=?', [$regId, $t]) !== null, 'reg contact id preserved');
        assert_true(Database::value('SELECT 1 FROM contacts WHERE id=? AND tenant_id=?', [$guestId, $t]) !== null, 'guest contact id preserved');

        // guest has NO identity; registered has exactly one
        assert_eq(0, (int) Database::value('SELECT COUNT(*) FROM identities WHERE contact_id=?', [$guestId]));
        assert_eq(1, (int) Database::value('SELECT COUNT(*) FROM identities WHERE contact_id=?', [$regId]));

        // login parity: the COPIED hash authenticates with the original password
        $tenants = new TenantContext();
        $ok = $tenants->runAs($t, function () use ($tenants) {
            $store = new IdentityStore($tenants, new ContactRepository($tenants));
            return $store->authenticate('password', 'seed-reg@example.test', 'seed-pass-123');
        });
        assert_true($ok !== null, 'copied hash authenticates → login parity');

        // token carried over and consumable
        $consumed = $tenants->runAs($t, function () use ($tenants) {
            $store = new IdentityStore($tenants, new ContactRepository($tenants));
            return $store->consumeToken('seed-raw-token', 'password_reset');
        });
        assert_true($consumed !== null, 'seeded token consumes');

        // ── idempotency: re-run skips, no duplicates ──
        $r2 = (new ContactSeeder())->run($t);
        assert_eq(0, $r2['created'], 're-run creates nothing');
        assert_eq(2, $r2['skipped']);
        assert_eq(2, (int) Database::value('SELECT COUNT(*) FROM contacts WHERE tenant_id=?', [$t]), 'still exactly 2 contacts');
    } finally {
        _seed_wipe();
    }
});
