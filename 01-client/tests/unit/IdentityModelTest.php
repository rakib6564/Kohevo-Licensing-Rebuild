<?php
/**
 * Unit tests for the Identity domain model (no DB). Verifies it never carries a
 * password hash and hydrates the soft-flag correctly.
 */

declare(strict_types=1);

use Slate\Domain\Identity\Identity;

unit('Identity::fromRow hydrates and ignores password_hash', function () {
    $i = Identity::fromRow([
        'id' => '3', 'contact_id' => '7', 'tenant_id' => '1',
        'provider' => 'password', 'credential_ref' => 'a@b.io',
        'password_hash' => '$2y$secret', 'email_verified' => '1',
        'status' => 'active', 'last_login_at' => '2026-01-01 00:00:00',
    ]);
    assert_eq(3, $i->id);
    assert_eq(7, $i->contactId);
    assert_eq('a@b.io', $i->credentialRef);
    assert_true($i->emailVerified);
    assert_true($i->isActive());
    // The model exposes no password hash property.
    assert_false(property_exists($i, 'password_hash') || property_exists($i, 'passwordHash'));
});

unit('Identity email_verified is a soft boolean; suspended is not active', function () {
    $guest = Identity::fromRow(['id'=>1,'contact_id'=>1,'tenant_id'=>1,'provider'=>'password','credential_ref'=>'x','email_verified'=>0,'status'=>'suspended']);
    assert_false($guest->emailVerified);
    assert_false($guest->isActive());
});
