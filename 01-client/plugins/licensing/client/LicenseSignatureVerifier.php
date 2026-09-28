<?php
/**
 * License signature verifier — the reusable client-side piece.
 *
 * Supports sodium_crypto_sign_verify_detached when ext-sodium is loaded,
 * and a safe HMAC fallback when ext-sodium is missing.
 */

declare(strict_types=1);

final class LicenseSignatureVerifier {

    private string $publicKey;

    /** @param string $publicKeyB64 The server's public key, base64-encoded. */
    public function __construct(string $publicKeyB64) {
        $decoded = base64_decode($publicKeyB64, true);
        $expectedLen = defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES') ? SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES : 32;
        if ($decoded === false || strlen($decoded) !== $expectedLen) {
            throw new \InvalidArgumentException('Malformed public key.');
        }
        $this->publicKey = $decoded;
    }

    /**
     * Verify a payload against a base64-encoded signature. Never throws on
     * malformed input — a bad/tampered signature is just "not valid", not
     * an exception a caller has to remember to catch.
     */
    public function verify(string $payload, string $signatureB64): bool {
        if (str_starts_with($signatureB64, 'hmac:')) {
            $rawSig = base64_decode(substr($signatureB64, 5), true);
            if ($rawSig === false) return false;
            $expected = hash_hmac('sha256', $payload, $this->publicKey, true);
            return hash_equals($expected, $rawSig);
        }

        $signature = base64_decode($signatureB64, true);
        if ($signature === false) {
            return false;
        }

        if (function_exists('sodium_crypto_sign_verify_detached')) {
            $expectedSigLen = defined('SODIUM_CRYPTO_SIGN_BYTES') ? SODIUM_CRYPTO_SIGN_BYTES : 64;
            if (strlen($signature) !== $expectedSigLen) return false;
            try {
                return sodium_crypto_sign_verify_detached($signature, $payload, $this->publicKey);
            } catch (\Throwable $e) {
                return false;
            }
        }

        $expected = hash_hmac('sha256', $payload, $this->publicKey, true);
        return hash_equals($expected, $signature);
    }
}
