<?php
/**
 * License signature verifier — the reusable client-side piece.
 *
 * Deliberately has NO dependency on Slate/Kohevo: no `Database::`, no
 * `Auth::`, no `require config.php`, nothing beyond PHP's core ext-sodium.
 * This is the file a future product (built on this codebase or a totally
 * different one) drops in as-is to verify what the license server signs —
 * see LicensingAPI::sign() on the server side, which this is the other
 * half of.
 *
 * Holds only a PUBLIC key. It cannot create a valid signature, only check
 * one — the private key never leaves the license server.
 */

declare(strict_types=1);

final class LicenseSignatureVerifier {

    private string $publicKey;

    /** @param string $publicKeyB64 The server's Ed25519 public key, base64-encoded. */
    public function __construct(string $publicKeyB64) {
        $decoded = base64_decode($publicKeyB64, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \InvalidArgumentException('Malformed Ed25519 public key.');
        }
        $this->publicKey = $decoded;
    }

    /**
     * Verify a payload against a base64-encoded signature. Never throws on
     * malformed input — a bad/tampered signature is just "not valid", not
     * an exception a caller has to remember to catch.
     */
    public function verify(string $payload, string $signatureB64): bool {
        $signature = base64_decode($signatureB64, true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($signature, $payload, $this->publicKey);
        } catch (\SodiumException $e) {
            return false;
        }
    }
}
