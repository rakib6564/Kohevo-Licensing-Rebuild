<?php
/**
 * Integration tests for IdentityStore against the real identities/identity_tokens
 * tables. Creates a probe Contact + Identity and cleans everything up.
 */

declare(strict_types=1);

use Slate\Services\Identity\ContactRepository;
use Slate\Services\Identity\IdentityStore;
use Slate\Domain\Identity\Identity;
use Slate\Tenancy\TenantContext;

function _identity_cleanup(int $tid, string $email): void
{
    // Orphan-proof first. The parent-scoped sweep below only reaches identities
    // through their contact, so an identity whose contact is already gone — the
    // state a test leaves when it dies between register() and its assertions —
    // is never deleted. It then collides on uniq_tenant_provider_cred forever
    // after, and the NEXT run fails with a duplicate-key error that has nothing
    // to do with what it was testing.
    foreach (Database::rows('SELECT id FROM identities WHERE credential_ref = ?', [$email]) as $i) {
        Database::query('DELETE FROM identity_tokens WHERE identity_id = ?', [(int) $i['id']]);
    }
    Database::query('DELETE FROM identities WHERE credential_ref = ?', [$email]);

    foreach (Database::rows('SELECT id FROM contacts WHERE tenant_id=? AND primary_email=?', [$tid, $email]) as $c) {
        $cid = (int) $c['id'];
        foreach (Database::rows('SELECT id FROM identities WHERE contact_id=?', [$cid]) as $i) {
            Database::query('DELETE FROM identity_tokens WHERE identity_id=?', [(int) $i['id']]);
        }
        Database::query('DELETE FROM identities WHERE contact_id=?', [$cid]);
        Database::query('DELETE FROM contact_emails WHERE contact_id=?', [$cid]);
        Database::query('DELETE FROM contact_phones WHERE contact_id=?', [$cid]);
        Database::query('DELETE FROM contacts WHERE id=?', [$cid]);
    }
}

$tenants = new TenantContext();
$contacts = new ContactRepository($tenants);
$store = new IdentityStore($tenants, $contacts);
$tid = current_tenant_id();

unit('register creates an identity linked to a contact; findByCredential + contactFor resolve it', function () use ($store, $contacts, $tid) {
    $email = '__ids_reg@example.test';
    _identity_cleanup($tid, $email);
    try {
        $contact = $contacts->create(['email' => $email, 'display_name' => 'Reg']);
        $identity = $store->register($contact->id, $email, 'S3cret-pw', true);
        assert_true($identity->id > 0);
        assert_eq($contact->id, $identity->contactId);
        assert_true($identity->emailVerified);

        // Credential lookup is case-insensitive (normalized) and resolves back to the contact.
        $byCred = $store->findByCredential('password', '__IDS_REG@Example.Test');
        assert_eq($identity->id, $byCred->id);
        assert_eq($contact->id, $store->contactFor($identity->id)->id);
    } finally {
        _identity_cleanup($tid, $email);
    }
});

unit('authenticate: correct password succeeds, wrong/absent fails; last_login stamped', function () use ($store, $contacts, $tid) {
    $email = '__ids_auth@example.test';
    _identity_cleanup($tid, $email);
    try {
        $contact = $contacts->create(['email' => $email]);
        $store->register($contact->id, $email, 'correct-horse');

        assert_eq(null, $store->authenticate('password', $email, 'wrong-pw'), 'wrong password → null');
        assert_eq(null, $store->authenticate('password', '__nobody@example.test', 'x'), 'unknown cred → null');

        $ok = $store->authenticate('password', $email, 'correct-horse');
        assert_true($ok instanceof Identity, 'correct password → Identity');
        $lastLogin = Database::value('SELECT last_login_at FROM identities WHERE id=?', [$ok->id]);
        assert_true($lastLogin !== null, 'last_login_at stamped');
    } finally {
        _identity_cleanup($tid, $email);
    }
});

unit('suspended identity cannot authenticate', function () use ($store, $contacts, $tid) {
    $email = '__ids_susp@example.test';
    _identity_cleanup($tid, $email);
    try {
        $contact = $contacts->create(['email' => $email]);
        $identity = $store->register($contact->id, $email, 'pw12345678');
        $store->suspend($identity->id);
        assert_eq(null, $store->authenticate('password', $email, 'pw12345678'), 'suspended → null even with right pw');
    } finally {
        _identity_cleanup($tid, $email);
    }
});

unit('token issue/consume is single-use and purpose-scoped', function () use ($store, $contacts, $tid) {
    $email = '__ids_token@example.test';
    _identity_cleanup($tid, $email);
    try {
        $contact = $contacts->create(['email' => $email]);
        $identity = $store->register($contact->id, $email, 'pw12345678');

        $tok = $store->issueToken($identity->id, 'password_reset', 3600);
        assert_true(strlen($tok) === 64, 'plaintext token is 64 hex chars');

        // wrong purpose → null; correct purpose → the identity; then burned.
        assert_eq(null, $store->consumeToken($tok, 'verify_email'), 'purpose mismatch → null');
        $consumed = $store->consumeToken($tok, 'password_reset');
        assert_eq($identity->id, $consumed->id, 'valid token resolves the identity');
        assert_eq(null, $store->consumeToken($tok, 'password_reset'), 'single-use: second consume → null');
    } finally {
        _identity_cleanup($tid, $email);
    }
});

unit('markEmailVerified flips the soft flag', function () use ($store, $contacts, $tid) {
    $email = '__ids_verify@example.test';
    _identity_cleanup($tid, $email);
    try {
        $contact = $contacts->create(['email' => $email]);
        $identity = $store->register($contact->id, $email, 'pw12345678', false);
        assert_false($identity->emailVerified);
        $store->markEmailVerified($identity->id);
        assert_true($store->findByCredential('password', $email)->emailVerified);
    } finally {
        _identity_cleanup($tid, $email);
    }
});

unit('identities are tenant-isolated', function () use ($store, $contacts, $tid) {
    $email = '__ids_tenant@example.test';
    _identity_cleanup($tid, $email);
    $tenants = new TenantContext();
    try {
        $contact = $contacts->create(['email' => $email]);
        $store->register($contact->id, $email, 'pw12345678');
        $other = $tid + 90000;
        $seen = $tenants->runAs($other, function () use ($tenants, $email) {
            $s = new IdentityStore($tenants, new ContactRepository($tenants));
            return $s->findByCredential('password', $email);
        });
        assert_eq(null, $seen, 'other tenant cannot see this identity');
    } finally {
        _identity_cleanup($tid, $email);
    }
});

unit('token issuance rejects foreign and nonexistent identity IDs without writes', function () use ($store, $contacts, $tid) {
    $email = '__ids_token_owner@example.test';
    _identity_cleanup($tid, $email);
    $tenants = new TenantContext();
    try {
        $contact = $contacts->create(['email' => $email]);
        $identity = $store->register($contact->id, $email, 'pw12345678');
        $before = (int) Database::value('SELECT COUNT(*) FROM identity_tokens WHERE tenant_id=?', [$tid]);
        $other = $tid + 90001;
        assert_throws(\InvalidArgumentException::class, function () use ($tenants, $identity, $other) {
            $tenants->runAs($other, function () use ($tenants, $identity) {
                (new IdentityStore($tenants, new ContactRepository($tenants)))->issueToken($identity->id, 'password_reset', 3600);
            });
        });
        assert_throws(\InvalidArgumentException::class, fn () => $store->issueToken(999999999, 'password_reset', 3600));
        $after = (int) Database::value('SELECT COUNT(*) FROM identity_tokens WHERE tenant_id=?', [$tid]);
        assert_eq($before, $after, 'rejected ownership attempts do not create token rows');
    } finally {
        _identity_cleanup($tid, $email);
    }
});
