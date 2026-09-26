<?php
// D2 reproduction: first activation of a never-activated license whose expires_at already passed.
declare(strict_types=1);
require __DIR__ . '/lib.php';
foreach (['1 day ago (inside grace)' => 86400, '30 days ago (beyond grace)' => 30 * 86400] as $label => $age) {
    fresh_client();
    $lic = central('issue', json_encode(['modules' => ['forms'], 'expires_at' => gmdate('Y-m-d H:i:s', time() - $age)]));
    step1(); installer_post(2, []);
    installer_post(3, ['license_key' => $lic['license_key']]);
    [$s] = installer_step();
    $c = central('license', (string) $lic['license_id']);
    $signed = json_decode((string) cval('SELECT raw_payload FROM remote_license_cache'), true);
    check(($c['status'] ?? '') !== 'active', "expired-at-issue license, expiry $label: central does not store it as active", 'central status=' . ($c['status'] ?? '?'));
    check(($signed['status'] ?? null) !== 'active', "expiry $label: signed status is not 'active'", 'signed status=' . ($signed['status'] ?? 'none'));
    check($s === 3, "expiry $label: installer does not continue to admin creation", "got step $s");
    check((int) cval('SELECT COUNT(*) FROM users') === 0, "expiry $label: no admin");
}
summary();
