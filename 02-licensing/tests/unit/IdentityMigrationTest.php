<?php
/**
 * Unit test for 0002_identity_core — pure dry-run of up()/down() through a
 * recording Schema (no DB). Asserts the spine tables + key columns/keys compile.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;

/** Load the migration and capture the SQL its up() emits. @return string[] */
function _identity_up_sql(): array
{
    $out = [];
    $migration = require __DIR__ . '/../../db/migrations/0002_identity_core.php';
    $migration->up(new Schema(function (string $sql) use (&$out) { $out[] = $sql; }));
    return $out;
}

function _identity_down_sql(): array
{
    $out = [];
    $migration = require __DIR__ . '/../../db/migrations/0002_identity_core.php';
    $migration->down(new Schema(function (string $sql) use (&$out) { $out[] = $sql; }));
    return $out;
}

unit('0002 up() creates exactly the 5 spine tables', function () {
    $sql = implode("\n", _identity_up_sql());
    foreach (['contacts', 'contact_emails', 'contact_phones', 'identities', 'identity_tokens'] as $tbl) {
        assert_true(str_contains($sql, "CREATE TABLE IF NOT EXISTS `{$tbl}`"), "creates {$tbl}");
    }
    assert_eq(5, substr_count($sql, 'CREATE TABLE IF NOT EXISTS'), 'exactly 5 tables');
});

unit('contacts has the canonical columns + forward-compat (kind, merged_into_id)', function () {
    $sql = _identity_up_sql()[0];
    foreach (['`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', 'PRIMARY KEY (`id`)',
              "`kind` ENUM('person', 'organization')", '`display_name`', '`primary_email`',
              '`primary_phone`', "`status` ENUM('active', 'archived', 'merged')",
              '`merged_into_id` BIGINT UNSIGNED NULL', '`meta` JSON'] as $frag) {
        assert_true(str_contains($sql, $frag), "contacts has: {$frag}");
    }
});

unit('identities replaces the auth surface with a unique credential key', function () {
    $sql = implode("\n", _identity_up_sql());
    assert_true(str_contains($sql, "`provider` ENUM('password')"), 'password provider');
    assert_true(str_contains($sql, '`credential_ref`'), 'credential_ref');
    assert_true(str_contains($sql, '`password_hash` VARCHAR(255) NULL'), 'nullable password_hash (guests)');
    assert_true(str_contains($sql, 'UNIQUE KEY `uniq_tenant_provider_cred` (`tenant_id`, `provider`, `credential_ref`)'), 'unique credential');
});

unit('contact_emails enforces one email per tenant (dedup key)', function () {
    $sql = implode("\n", _identity_up_sql());
    assert_true(str_contains($sql, 'UNIQUE KEY `uniq_tenant_email` (`tenant_id`, `email`)'));
});

unit('identity_tokens carries the single-use hashed-token shape', function () {
    $sql = implode("\n", _identity_up_sql());
    assert_true(str_contains($sql, '`token_hash` CHAR(64)'), 'sha-256 hash');
    assert_true(str_contains($sql, 'KEY `idx_token_purpose` (`token_hash`, `purpose`)'));
    assert_true(str_contains($sql, '`identity_id`'), 'linked to identity');
});

unit('down() drops all 5 tables FK-safe (children first)', function () {
    $down = _identity_down_sql();
    assert_eq(['DROP TABLE IF EXISTS `identity_tokens`', 'DROP TABLE IF EXISTS `identities`',
               'DROP TABLE IF EXISTS `contact_phones`', 'DROP TABLE IF EXISTS `contact_emails`',
               'DROP TABLE IF EXISTS `contacts`'], $down);
});
