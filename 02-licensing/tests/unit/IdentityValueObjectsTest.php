<?php
/**
 * Unit tests for the identity value objects + Contact model (no DB).
 */

declare(strict_types=1);

use Slate\Domain\Identity\EmailAddress;
use Slate\Domain\Identity\PhoneNumber;
use Slate\Domain\Identity\Contact;

// ── EmailAddress ──────────────────────────────────────────
unit('EmailAddress::normalize lower-cases and trims', function () {
    assert_eq('a@b.com', EmailAddress::normalize('  A@B.CoM '));
});

unit('EmailAddress::fromString validates', function () {
    assert_eq('x@y.io', (string) EmailAddress::fromString('X@Y.IO'));
    assert_throws(\InvalidArgumentException::class, fn () => EmailAddress::fromString('not-an-email'));
    assert_throws(\InvalidArgumentException::class, fn () => EmailAddress::fromString('  '));
});

unit('EmailAddress::tryFrom returns null on invalid', function () {
    assert_eq(null, EmailAddress::tryFrom('nope'));
    assert_true(EmailAddress::tryFrom('a@b.com') instanceof EmailAddress);
});

// ── PhoneNumber ───────────────────────────────────────────
unit('PhoneNumber::normalize strips formatting, keeps leading +', function () {
    assert_eq('+15551234567', PhoneNumber::normalize('+1 (555) 123-4567'));
    assert_eq('5551234567', PhoneNumber::normalize('555.123.4567'));
    assert_eq('', PhoneNumber::normalize('  '));
});

unit('PhoneNumber::tryFrom null on empty', function () {
    assert_eq(null, PhoneNumber::tryFrom('---'));
    assert_eq('+441234', (string) PhoneNumber::tryFrom('+44 1234'));
});

// ── Contact ───────────────────────────────────────────────
unit('Contact::fromRow hydrates + helpers', function () {
    $c = Contact::fromRow([
        'id' => '5', 'tenant_id' => '1', 'kind' => 'person',
        'display_name' => 'Ada', 'primary_email' => 'ada@x.io',
        'primary_phone' => null, 'status' => 'active',
    ]);
    assert_eq(5, $c->id);
    assert_eq('Ada', $c->displayName);
    assert_true($c->isPerson());
    assert_true($c->isActive());
});

unit('Contact defaults kind=person, status=active when absent', function () {
    $c = Contact::fromRow(['id' => 1, 'tenant_id' => 1]);
    assert_eq('person', $c->kind);
    assert_eq('active', $c->status);
});
