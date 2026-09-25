<?php
/**
 * Phase 1D B1 — Booking's Twilio Auth Token was stored and read as plaintext.
 *
 * plugins/booking/admin/settings.php wrote `$_POST['twilio_token']` straight
 * into the `settings` table with no encryption, unlike the Google Calendar
 * client secret two fields above it in the same form/file, which was already
 * routed through slate_encrypt_secret()/slate_decrypt_secret() (AES-256-GCM,
 * envelope format 'enc:v1:'). BookingAPI::twilioSend() read it back the same
 * plain way. Anyone with read access to the `settings` table (a DB backup, a
 * misconfigured read replica, another vulnerability) got Twilio's actual Auth
 * Token — which authenticates as the account for every Twilio API call, not
 * a scoped/rotatable credential — in the clear.
 *
 * Fix applies the exact existing convention (same file, same helpers, same
 * envelope format) already used for the Google client secret. No new crypto,
 * no schema change. slate_decrypt_secret() already passes through anything
 * without the 'enc:v1:' prefix unchanged, so a token saved before this fix
 * keeps working with no migration step.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/booking/BookingAPI.php';

/** Snapshot every booking.* setting for this tenant, return the exact undo. */
function btte_snapshot_booking_settings(): callable
{
    $tid = current_tenant_id();
    $rows = Database::rows(
        "SELECT setting_key, setting_value FROM settings WHERE tenant_id = ? AND setting_key LIKE 'booking.%'",
        [$tid]
    );
    return static function () use ($tid, $rows): void {
        Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key LIKE 'booking.%'", [$tid]);
        foreach ($rows as $row) {
            Database::setSetting($row['setting_key'], $row['setting_value'], $tid);
        }
    };
}

unit('booking settings.php: saving a Twilio Auth Token stores it encrypted at rest, not plaintext', function (): void {
    $restore = btte_snapshot_booking_settings();
    $tid = current_tenant_id();
    $plaintext = 'AC_probe_token_' . bin2hex(random_bytes(12));

    try {
        $cmd = escapeshellarg(PHP_BINARY) . ' '
             . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-probe.php') . ' '
             . escapeshellarg('plugins/booking/admin/settings.php') . ' '
             . escapeshellarg((string) json_encode(['twilio_token' => $plaintext])) . ' '
             . escapeshellarg('1') . ' ' . escapeshellarg('0') . ' 2>/dev/null';
        $out = (string) shell_exec($cmd);
        if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
            throw new RuntimeException('probe did not return a STATUS line: ' . $out);
        }
        assert_eq(200, (int) $m[1], 'settings save must succeed');

        $stored = (string) Database::value(
            'SELECT setting_value FROM settings WHERE tenant_id = ? AND setting_key = ?',
            [$tid, 'booking.twilio_token']
        );

        assert_true($stored !== '', 'a value must have been stored');
        assert_true(str_starts_with($stored, 'enc:v1:'), 'the stored value must carry the standard encryption envelope, matching booking.google_client_secret');
        assert_false($stored === $plaintext, 'the raw plaintext token must never be the stored value');
        assert_false(str_contains($stored, $plaintext), 'the plaintext token must not appear anywhere in the stored ciphertext');

        assert_eq($plaintext, slate_decrypt_secret($stored), 'decrypting the stored value must recover the exact original token');
    } finally {
        $restore();
    }
});

unit('BookingAPI::twilioSend() decrypts the stored token before using it (fix present in the shipped file)', function (): void {
    $src = file_get_contents(dirname(__DIR__, 2) . '/plugins/booking/BookingAPI.php');

    $fnPos = strpos($src, 'private static function twilioSend(');
    assert_true($fnPos !== false, 'twilioSend() must still exist');

    $fnEnd = strpos($src, "\n    }", $fnPos);
    $fnBody = substr($src, $fnPos, ($fnEnd !== false ? $fnEnd - $fnPos : 400));

    assert_true(
        str_contains($fnBody, "slate_decrypt_secret((string) Database::setting('booking.twilio_token'))"),
        'twilioSend() must decrypt booking.twilio_token via slate_decrypt_secret(), matching GoogleCalendarSync::clientSecret()\'s convention'
    );
});

unit('a token saved before this fix (stored plaintext, no envelope) still decrypts to itself — no migration required', function (): void {
    // slate_decrypt_secret()'s own contract: anything without the 'enc:v1:'
    // prefix passes through unchanged. Pinned here specifically for this
    // finding so a future change to that contract can't silently break
    // every pre-existing installation's already-saved Twilio token.
    $legacyPlaintext = 'legacy_plaintext_token_' . bin2hex(random_bytes(8));
    assert_eq($legacyPlaintext, slate_decrypt_secret($legacyPlaintext), 'a pre-fix plaintext value must still decrypt to itself unchanged');
});
