<?php
/**
 * License signature verifier — the reusable client-side piece.
 *
 * Ed25519 only, through ext-sodium. There is no fallback: a build without
 * sodium cannot verify a licence and therefore never trusts one (fail
 * closed). Nothing derived from the PUBLIC key may ever authenticate a
 * signature — no HMAC, no keyed hash, no "legacy" prefix.
 */

declare(strict_types=1);

final class LicenseSignatureVerifier {

    private string $publicKey;

    /**
     * @param string $publicKeyB64 The server's Ed25519 public key, base64-encoded.
     * @throws \RuntimeException         when ext-sodium is not available
     * @throws \InvalidArgumentException when the key is not a 32-byte Ed25519 public key
     */
    public function __construct(string $publicKeyB64) {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new \RuntimeException('ext-sodium is required to verify licence signatures.');
        }
        $decoded = base64_decode($publicKeyB64, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \InvalidArgumentException('Malformed public key.');
        }
        $this->publicKey = $decoded;
    }

    /**
     * Verify a payload against a base64-encoded Ed25519 detached signature.
     * Never throws on malformed input — a bad/tampered signature is just
     * "not valid", not an exception a caller has to remember to catch.
     * Anything that is not exactly a base64 64-byte signature (including any
     * value with a scheme prefix such as "hmac:") is rejected.
     */
    public function verify(string $payload, string $signatureB64): bool {
        $signature = base64_decode($signatureB64, true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($signature, $payload, $this->publicKey);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
