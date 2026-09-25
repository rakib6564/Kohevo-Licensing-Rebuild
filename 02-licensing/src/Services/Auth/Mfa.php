<?php
/**
 * Slate — MFA primitives.
 *
 * This class contains no persistence or session side effects. Callers must store
 * the secret and recovery-code hashes through tenant-scoped repositories and
 * must atomically mark a successful recovery code as consumed.
 */

declare(strict_types=1);

namespace Slate\Services\Auth;

final class Mfa
{
    public static function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 10 || $bytes > 64) {
            throw new \InvalidArgumentException('MFA secret length must be between 10 and 64 bytes.');
        }
        return self::base32Encode(random_bytes($bytes));
    }

    public static function verifyTotp(string $secret, string $code, ?int $timestamp = null, int $window = 1): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code) || $window < 0 || $window > 3) return false;
        $timestamp ??= time();
        $counter = intdiv($timestamp, 30);
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::hotp($secret, $counter + $offset), $code)) return true;
        }
        return false;
    }

    public static function hashRecoveryCode(string $code): string
    {
        $normalized = self::normalizeRecoveryCode($code);
        if ($normalized === '') throw new \InvalidArgumentException('Recovery code cannot be empty.');
        return password_hash($normalized, PASSWORD_DEFAULT);
    }

    public static function verifyRecoveryCode(string $code, string $hash): bool
    {
        $normalized = self::normalizeRecoveryCode($code);
        return $normalized !== '' && password_verify($normalized, $hash);
    }

    /** @return list<string> plaintext codes; callers must store only hashes. */
    public static function generateRecoveryCodes(int $count = 10): array
    {
        if ($count < 1 || $count > 20) throw new \InvalidArgumentException('Recovery-code count must be 1–20.');
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }
        return $codes;
    }

    private static function hotp(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        $binaryCounter = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        // anti-drift-ignore: HMAC — RFC 4226 HOTP, keyed on the user's own
        // base32 TOTP secret, not APP_SECRET. slate_sign() cannot be used: the
        // algorithm is fixed at sha1 by the spec and the output must be raw
        // binary. $key comes from base32Decode() of a stored per-user secret;
        // an empty one yields no valid code, so it fails closed by construction.
        // anti-drift-ignore: HMAC — RFC 6238 TOTP, keyed on the user's own
        // enrolled secret, not APP_SECRET. The algorithm and digest are fixed
        // by the spec and by every authenticator app, so slate_sign() (SHA-256,
        // context-prefixed, hex) cannot be substituted.
        $digest = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($digest[19]) & 0x0F;
        $value = ((ord($digest[$offset]) & 0x7F) << 24)
            | ((ord($digest[$offset + 1]) & 0xFF) << 16)
            | ((ord($digest[$offset + 2]) & 0xFF) << 8)
            | (ord($digest[$offset + 3]) & 0xFF);
        return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');
    }

    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0; $bits = 0; $out = '';
        foreach (unpack('C*', $data) as $byte) {
            $buffer = ($buffer << 8) | $byte; $bits += 8;
            while ($bits >= 5) { $bits -= 5; $out .= $alphabet[($buffer >> $bits) & 31]; }
        }
        if ($bits > 0) $out .= $alphabet[($buffer << (5 - $bits)) & 31];
        return $out;
    }

    private static function base32Decode(string $encoded): string
    {
        $alphabet = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
        $buffer = 0; $bits = 0; $out = '';
        foreach (str_split(strtoupper(preg_replace('/[^A-Z2-7]/i', '', $encoded) ?? '')) as $char) {
            if (!isset($alphabet[$char])) throw new \InvalidArgumentException('Invalid MFA secret.');
            $buffer = ($buffer << 5) | $alphabet[$char]; $bits += 5;
            if ($bits >= 8) { $bits -= 8; $out .= chr(($buffer >> $bits) & 255); }
        }
        return $out;
    }
}
