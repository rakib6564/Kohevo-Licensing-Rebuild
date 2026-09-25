<?php
/**
 * Studio — Audience (pure) unit tests.
 *
 * The one that matters: a parent with three dancers must not get the snow-day
 * notice three times.
 */

declare(strict_types=1);

use Slate\Module\Studio\Domain\Audience as A;

unit('a parent appearing once per dancer is emailed once', function () {
    $rows = [
        ['email' => 'alex@example.com', 'name' => 'Alex Morgan', 'contact_id' => 1],
        ['email' => 'alex@example.com', 'name' => 'Alex Morgan', 'contact_id' => 1],
        ['email' => 'alex@example.com', 'name' => 'Alex Morgan', 'contact_id' => 1],
    ];
    assert_eq(1, count(A::recipients($rows)), 'three dancers, one email');
});

unit('addresses are matched case-insensitively and trimmed', function () {
    $rows = [
        ['email' => 'Alex@Example.com',   'name' => 'Alex'],
        ['email' => '  alex@example.com ', 'name' => 'Alex again'],
        ['email' => 'ALEX@EXAMPLE.COM',    'name' => 'Alex thrice'],
    ];
    $out = A::recipients($rows);
    assert_eq(1, count($out));
    assert_eq('alex@example.com', $out[0]['email'], 'normalised to lowercase');
    assert_eq('Alex', $out[0]['name'], 'first occurrence wins, so preview and send agree');
});

unit('unreachable rows are dropped, not counted', function () {
    $rows = [
        ['email' => 'ok@example.com', 'name' => 'Fine'],
        ['email' => '',               'name' => 'No address'],
        ['email' => 'not-an-email',   'name' => 'No at-sign'],
        ['email' => 'nobody@localhost', 'name' => 'No dot in domain'],
        ['email' => '@example.com',   'name' => 'No local part'],
    ];
    $out = A::recipients($rows);
    assert_eq(1, count($out));
    assert_eq('ok@example.com', $out[0]['email']);
});

unit('deliverable is permissive — it filters, it does not validate', function () {
    assert_true(A::deliverable('a+tag@sub.example.co.uk'));
    assert_true(A::deliverable("o'brien@example.com"), 'a legitimate apostrophe must not drop a family');
    assert_false(A::deliverable('  '));
    assert_false(A::deliverable('two@@example.com') === false, 'permissive by design');
});

unit('summarise separates duplicates from unreachable so both can be fixed', function () {
    $rows = [
        ['email' => 'a@example.com'],
        ['email' => 'a@example.com'],   // duplicate
        ['email' => 'b@example.com'],
        ['email' => ''],                // unreachable
        ['email' => 'nope'],            // unreachable
    ];
    $s = A::summarise($rows);
    assert_eq(5, $s['total']);
    assert_eq(2, $s['deliverable']);
    assert_eq(1, $s['duplicates']);
    assert_eq(2, $s['unreachable']);
});

unit('summarise and recipients always agree on the count', function () {
    $rows = [
        ['email' => 'a@example.com'], ['email' => 'A@EXAMPLE.COM'],
        ['email' => 'b@example.com'], ['email' => 'bad'], ['email' => ''],
    ];
    assert_eq(count(A::recipients($rows)), A::summarise($rows)['deliverable'],
        'a preview that disagrees with the send is worse than no preview');
});

unit('non-array rows are ignored rather than fataling', function () {
    assert_eq([], A::recipients(['nonsense', 42, null]));
    assert_eq(0, A::summarise(['nonsense'])['deliverable']);
});

unit('an empty candidate list yields no recipients', function () {
    assert_eq([], A::recipients([]));
    assert_eq(['total' => 0, 'deliverable' => 0, 'duplicates' => 0, 'unreachable' => 0],
        A::summarise([]));
});

unit('kinds normalise, and anything unknown falls back to all', function () {
    assert_eq('class',  A::normaliseKind('class'));
    assert_eq('unpaid', A::normaliseKind(' UNPAID '));
    assert_eq('all',    A::normaliseKind('everyone'));
    assert_eq('all',    A::normaliseKind(null));
});

unit('personalise replaces known tokens and leaves unknown ones visible', function () {
    $body = 'Hi {first_name}, you owe {balance}. See you {when}.';
    $out  = A::personalise($body, ['first_name' => 'Alex', 'balance' => '$95.00']);
    assert_eq('Hi Alex, you owe $95.00. See you {when}.', $out,
        'an unreplaced token is a visible bug; a blank one looks like zero owed');
});
