<?php
/**
 * Slate — reset (or create) an admin account's password from the CLI.
 *
 * You run this yourself, choosing your own email/password as arguments —
 * it never asks Claude or anything else for credentials, and it prints
 * no secrets back.
 *
 * Run:  php bin/reset-admin-password.php you@example.com yourNewPassword12
 *
 * - If a user with that email already exists for this tenant, its
 *   password_hash is updated in place (nothing else about the account
 *   changes).
 * - If no such user exists yet, a new active, role_id=1 (Super Admin)
 *   account is created with that email/password.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the CLI: php bin/reset-admin-password.php email newPassword\n");
    exit(1);
}

require __DIR__ . '/../config.php';

$email    = $argv[1] ?? null;
$password = $argv[2] ?? null;

if ($email === null || $password === null) {
    fwrite(STDERR, "Usage: php bin/reset-admin-password.php you@example.com yourNewPassword\n");
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "That doesn't look like a valid email address.\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$existing = Database::row(
    "SELECT id FROM users WHERE email = ? AND tenant_id = ?",
    [$email, current_tenant_id()]
);

if ($existing) {
    Database::update('users',
        ['password_hash' => $hash, 'status' => 'active'],
        'id = ?', [$existing['id']]
    );
    echo "Updated password for existing user #{$existing['id']} ($email). Status set to active.\n";
} else {
    $id = Database::insert('users', [
        'tenant_id'     => current_tenant_id(),
        'email'         => $email,
        'password_hash' => $hash,
        'name'          => 'Admin',
        'role_id'       => 1,
        'status'        => 'active',
    ]);
    echo "Created new admin user #$id ($email) with role_id=1 (Super Admin).\n";
}

echo "You can now log in at " . SLATE_URL . "/admin/login.php with that email and password.\n";
