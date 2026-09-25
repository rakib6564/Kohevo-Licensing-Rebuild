<?php
/**
 * Slate — read-only admin-login diagnostic.
 *
 * Run:  php bin/diagnose-login.php you@example.com yourPassword
 *
 * Prints WHY a login is failing without changing anything: whether the
 * `users` table has that email at all, its status/tenant, whether the
 * given password actually matches its stored hash, and whether the
 * account is currently throttled/locked out. Never prints password
 * hashes or other secrets.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the CLI: php bin/diagnose-login.php email password\n");
    exit(1);
}

require __DIR__ . '/../config.php';

$email    = $argv[1] ?? null;
$password = $argv[2] ?? null;

if ($email === null) {
    fwrite(STDERR, "Usage: php bin/diagnose-login.php you@example.com yourPassword\n");
    exit(1);
}

echo "== Slate login diagnostic ==\n";
echo "Tenant ID (from .env): " . current_tenant_id() . "\n";
echo "Checking email: $email\n\n";

// 1. Does the users table exist / have any rows at all?
$total = Database::value("SELECT COUNT(*) FROM users");
echo "Total rows in `users` table: $total\n";

if ((int)$total > 0) {
    $all = Database::rows("SELECT id, email, tenant_id, status, role_id, created_at FROM users ORDER BY id");
    echo "All admin accounts currently in the database:\n";
    foreach ($all as $row) {
        echo "  - #{$row['id']}  {$row['email']}  tenant={$row['tenant_id']}  status={$row['status']}  role_id={$row['role_id']}  created={$row['created_at']}\n";
    }
    echo "\n";
} else {
    echo "No admin users exist yet — the install wizard's step 2 (create admin) was\n";
    echo "never completed, or the users table was never seeded.\n\n";
}

// 2. Exact row the login query would actually match.
$user = Database::row(
    "SELECT * FROM users WHERE email = ? AND tenant_id = ? AND status = 'active'",
    [$email, current_tenant_id()]
);

if (!$user) {
    echo "RESULT: No row matches email='$email' AND tenant_id=" . current_tenant_id() . " AND status='active'.\n";
    $anyEmailMatch = Database::row("SELECT id, tenant_id, status FROM users WHERE email = ?", [$email]);
    if ($anyEmailMatch) {
        echo "  (A user with that email DOES exist, but tenant_id={$anyEmailMatch['tenant_id']} or status={$anyEmailMatch['status']} doesn't match — that's why login fails.)\n";
    } else {
        echo "  (No user with that email exists at all — that's why login fails. Check the list above for the real email.)\n";
    }
} else {
    echo "RESULT: Found matching active user #{$user['id']} ({$user['email']}).\n";
    if ($password !== null) {
        $ok = password_verify($password, $user['password_hash']);
        echo "Password check: " . ($ok ? "MATCHES ✔" : "does NOT match ✘") . "\n";
    }
}

// 3. Throttle/lockout state.
try {
    $blocked = Auth::loginBlockedSeconds('admin');
} catch (\Throwable $e) {
    $blocked = null;
}
if ($blocked === null) {
    echo "\n(Could not check throttle state: " . "n/a" . ")\n";
} elseif ($blocked > 0) {
    echo "\nTHROTTLED: login is currently locked out for " . $blocked . " more second(s) (too many recent failed attempts from this IP/email).\n";
} else {
    echo "\nNot currently throttled.\n";
}
