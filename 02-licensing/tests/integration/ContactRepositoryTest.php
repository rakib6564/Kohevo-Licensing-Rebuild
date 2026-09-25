<?php
/**
 * Integration tests for ContactRepository + resolveOrCreate against the real
 * spine tables (0002_identity_core). Read-mostly; every contact it creates is
 * cleaned up (children first) in a finally block.
 */

declare(strict_types=1);

use Slate\Services\Identity\ContactRepository;
use Slate\Tenancy\TenantContext;

/** Delete a probe contact + its children (test hygiene). */
function _contact_cleanup(int $tid, array $emails, array $phones): void
{
    // resolve ids via emails/phones then delete children + contacts
    $ids = [];
    foreach ($emails as $e) {
        foreach (Database::rows('SELECT contact_id FROM contact_emails WHERE tenant_id=? AND email=?', [$tid, $e]) as $r) {
            $ids[(int) $r['contact_id']] = true;
        }
    }
    foreach ($phones as $p) {
        foreach (Database::rows('SELECT contact_id FROM contact_phones WHERE tenant_id=? AND phone=?', [$tid, $p]) as $r) {
            $ids[(int) $r['contact_id']] = true;
        }
    }
    foreach (array_keys($ids) as $id) {
        Database::query('DELETE FROM contact_emails WHERE contact_id=?', [$id]);
        Database::query('DELETE FROM contact_phones WHERE contact_id=?', [$id]);
        Database::query('DELETE FROM contacts WHERE id=?', [$id]);
    }
}

$repo = new ContactRepository(new TenantContext());
$tid = current_tenant_id();

unit('resolveOrCreate creates once, then matches by email (the choke point)', function () use ($repo, $tid) {
    $email = '__probe_choke@example.test';
    _contact_cleanup($tid, [$email], []);
    try {
        $a = $repo->resolveOrCreate(['email' => '  __Probe_Choke@Example.Test ', 'display_name' => 'Probe', 'phone' => '555-000-1111']);
        assert_true($a->id > 0);
        assert_eq($email, $a->primaryEmail, 'email normalized + denormalized onto contact');

        // Second call, differently-cased email, no phone → must return the SAME contact, not a new one.
        $b = $repo->resolveOrCreate(['email' => '__PROBE_CHOKE@EXAMPLE.TEST']);
        assert_eq($a->id, $b->id, 'resolveOrCreate must not spawn a duplicate');

        // And by phone alone → still the same contact.
        $c = $repo->resolveOrCreate(['phone' => '(555) 000-1111']);
        assert_eq($a->id, $c->id, 'phone match resolves the same contact');

        // Exactly one contact + one email row exists.
        $n = (int) Database::value('SELECT COUNT(*) FROM contact_emails WHERE tenant_id=? AND email=?', [$tid, $email]);
        assert_eq(1, $n, 'exactly one email row');
    } finally {
        _contact_cleanup($tid, [$email], ['5550001111']);
    }
});

unit('findByEmail / findByPhone locate the contact; find() by id round-trips', function () use ($repo, $tid) {
    $email = '__probe_find@example.test';
    $phone = '+15559998888';
    _contact_cleanup($tid, [$email], ['15559998888', '5559998888']);
    try {
        $created = $repo->create(['email' => $email, 'phone' => $phone, 'display_name' => 'Finder']);
        assert_eq($created->id, $repo->findByEmail('__PROBE_FIND@example.test')->id);
        assert_eq($created->id, $repo->findByPhone('+1 555 999 8888')->id);
        assert_eq($created->id, $repo->find($created->id)->id);
        assert_eq(null, $repo->findByEmail('__nobody@example.test'));
    } finally {
        _contact_cleanup($tid, [$email], ['15559998888', '5559998888']);
    }
});

unit('contacts are tenant-isolated', function () use ($repo, $tid) {
    $email = '__probe_tenant@example.test';
    _contact_cleanup($tid, [$email], []);
    $other = $tid + 90000;
    _contact_cleanup($other, [$email], []);
    try {
        $mine = $repo->create(['email' => $email, 'display_name' => 'Mine']);
        // A repo scoped to another tenant must not see it.
        $tenants = new TenantContext();
        $seen = $tenants->runAs($other, fn () => (new ContactRepository(new TenantContext()))->findByEmail($email));
        assert_eq(null, $seen, 'other tenant cannot see this contact');
        // find() by id is also tenant-scoped.
        $seenById = $tenants->runAs($other, fn () => (new ContactRepository(new TenantContext()))->find($mine->id));
        assert_eq(null, $seenById);
    } finally {
        _contact_cleanup($tid, [$email], []);
        _contact_cleanup($other, [$email], []);
    }
});

unit('update patches whitelisted party columns only', function () use ($repo, $tid) {
    $email = '__probe_update@example.test';
    _contact_cleanup($tid, [$email], []);
    try {
        $c = $repo->create(['email' => $email, 'display_name' => 'Before']);
        $u = $repo->update($c->id, ['display_name' => 'After', 'status' => 'archived', 'id' => 999999, 'evil' => 'x']);
        assert_eq('After', $u->displayName);
        assert_eq('archived', $u->status);
        assert_eq($c->id, $u->id, 'id not overwritten by patch');
    } finally {
        _contact_cleanup($tid, [$email], []);
    }
});
