<?php
/**
 * Remote license check-in client — the reusable core, alongside
 * LicenseSignatureVerifier. Zero Slate/Kohevo dependency: no `Database::`,
 * no `Auth::`, no `require config.php`. A future product drops this file
 * (and LicenseSignatureVerifier.php, and LicenseCacheStoreInterface.php)
 * in as-is, writes its own ~30-line storage adapter, and is done.
 *
 * The one rule everything else here serves: on ANY failure — network
 * down, a non-200, a malformed response, a signature that doesn't verify
 * — the cache is left completely untouched. Silence is never treated as
 * "suspended"; only an explicit, verified, signed "suspended" from the
 * server is. See LicenseCacheStoreInterface for the storage seam.
 */

declare(strict_types=1);

require_once __DIR__ . '/LicenseSignatureVerifier.php';
require_once __DIR__ . '/LicenseCacheStoreInterface.php';

final class RemoteLicenseClient {

    /**
     * QA Fix Round 1 (Phase 4, Fix 2): the canonical Installation ID format
     * — lowercase hex, exactly 32 characters.
     */
    private const INSTALLATION_ID_PATTERN = '/^[a-f0-9]{32}$/';

    private string $serverUrl;
    private string $productSlug;
    private string $licenseKey;
    private string $installId;
    private string $domain;
    private string $appVersion;
    private LicenseSignatureVerifier $verifier;
    private LicenseCacheStoreInterface $store;

    /** @var callable(string, string): ?array{0:int,1:string} */
    private $transport;

    /** Phase 10: see lastFailure(). */
    private ?string $lastFailure = null;

    /**
     * @param array{server_url:string, public_key:string, product:string,
     *              license_key:string, install_id:string, domain:string,
     *              app_version?:string} $config
     * @param callable(string $url, string $jsonBody): ?array{0:int,1:string} $transport
     *        Optional override of the HTTP call itself — [status, body] on
     *        a completed request, null on any transport-level failure.
     *        Defaults to a real cURL POST. Tests inject a fake here so the
     *        rest of this class's logic is verifiable with no network.
     */
    public function __construct(array $config, LicenseCacheStoreInterface $store, ?callable $transport = null) {
        $this->serverUrl   = rtrim((string) $config['server_url'], '/');
        $this->productSlug = (string) $config['product'];
        $this->licenseKey  = (string) $config['license_key'];
        $this->installId   = (string) $config['install_id'];
        $this->domain      = (string) $config['domain'];
        $this->appVersion  = (string) ($config['app_version'] ?? '');
        $this->verifier    = new LicenseSignatureVerifier((string) $config['public_key']);
        $this->store       = $store;
        $this->transport   = $transport ?? [self::class, 'curlPost'];
    }

    /**
     * Attempt one check-in. Returns true only when the cache was actually
     * updated with a freshly verified status — false covers every failure
     * mode uniformly (network, bad status, malformed body, bad signature),
     * on purpose: the caller never needs to distinguish them, because the
     * response is identical either way — leave the cache alone.
     */
    public function checkIn(): bool {
        return $this->runCheckIn()['ok'];
    }

    /**
     * Same check-in attempt as checkIn(), but with a coarse-grained reason
     * code for a caller that talks directly to an operator (the installer's
     * License Key step, docs/02-architecture/05-INSTALLATION-ACTIVATION.md
     * §5) and must show a DIFFERENT message for "couldn't reach the server
     * at all" vs. "the server responded, but this wasn't accepted" — a
     * distinction checkIn() itself deliberately does not expose, because a
     * routine/cron caller never needs to act on it differently (the cache
     * is left untouched either way regardless).
     *
     * Never returns any more detail than this one coarse split: an
     * installer showing "invalid key" vs. "bad signature" vs. "wrong
     * installation" to the operator would leak nothing an attacker could
     * use (this is the operator's own request, on their own install), but
     * it would blur the one distinction that actually matters to them
     * behind noise that only makes sense to a developer.
     *
     * @return array{ok:bool, reason:?string} reason is 'network' (no HTTP
     *         response was ever received), 'rejected' (a response WAS
     *         received but failed validation at any stage — bad status,
     *         malformed envelope, bad signature, or an installation_id that
     *         doesn't match this install), or null when ok is true.
     */
    public function checkInDetailed(): array {
        return $this->runCheckIn();
    }

    /**
     * Phase 10: the specific, stable category of the most recent failed
     * check-in, for server-side diagnostics only (bin/license-check.php and
     * the admin re-check log it via slate_log()). Never shown to an
     * operator or a browser -- checkInDetailed()'s coarse split is the only
     * user-facing distinction. Null after a successful check-in.
     *
     * One of: network, http_status, malformed_envelope, signature_invalid,
     * malformed_payload, installation_mismatch, stale_response,
     * cache_write_failed, request_encoding.
     *
     * A wrong product or a wrong/unknown license key cannot be told apart
     * from each other here by design: the Central Server answers both with
     * the same anti-enumeration HTTP error (11-LICENSING-API-CONTRACT.md
     * §1), so both surface as http_status.
     */
    public function lastFailure(): ?string {
        return $this->lastFailure;
    }

    /** @return array{ok:bool, reason:?string} */
    private function fail(string $reason, string $category): array {
        $this->lastFailure = $category;
        return ['ok' => false, 'reason' => $reason];
    }

    /** @return array{ok:bool, reason:?string} */
    private function runCheckIn(): array {
        $this->lastFailure = null;
        $requestBody = json_encode([
            'product'     => $this->productSlug,
            'license_key' => $this->licenseKey,
            'install_id'  => $this->installId,
            'domain'      => $this->domain,
            'app_version' => $this->appVersion,
            'checked_at'  => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES);
        if ($requestBody === false) return $this->fail('rejected', 'request_encoding');

        $response = ($this->transport)($this->serverUrl . '/licensing/check', $requestBody);
        if ($response === null) return $this->fail('network', 'network');

        [$httpStatus, $responseBody] = $response;
        if ($httpStatus !== 200) return $this->fail('rejected', 'http_status');

        $envelope = is_string($responseBody) ? json_decode($responseBody, true) : null;
        if (!is_array($envelope) || !isset($envelope['payload'], $envelope['signature'])
            || !is_string($envelope['payload']) || !is_string($envelope['signature'])) {
            return $this->fail('rejected', 'malformed_envelope');
        }

        if (!$this->verifier->verify($envelope['payload'], $envelope['signature'])) {
            return $this->fail('rejected', 'signature_invalid');
        }

        $status = json_decode($envelope['payload'], true);
        if (!is_array($status) || !isset($status['status']) || !is_string($status['status']) || $status['status'] === '') {
            return $this->fail('rejected', 'malformed_payload'); // signature was valid, but the signed content itself is malformed
        }

        // Phase 4 (docs/02-architecture/15-PHASE-1-DECISIONS.md D14,
        // 10-CLIENT-LICENSING-DATABASE-DESIGN.md §4): a cryptographically
        // valid signature only proves the Central Server produced this
        // payload -- it does NOT prove it was produced for THIS
        // installation, because every install shares the same embedded
        // public key. A payload+signature pair lifted verbatim from a
        // different installation's cache would still verify here. The
        // payload's own installation_id (server-resolved, §11 §2) must
        // therefore match this install's own configured install_id, or the
        // whole payload is untrusted -- treated exactly like a failed
        // signature check, not merely a soft warning.
        //
        // QA Fix Round 1 (Phase 4, Fix 2D): format is checked FIRST and
        // independently of the equality check below, on BOTH sides of the
        // comparison -- a malformed payload value is rejected on its own
        // terms (never merely "happens to not equal" a well-formed local
        // id), and a malformed LOCAL $this->installId (a caller/config bug)
        // must never be treated as though it could legitimately match
        // anything, however the payload is shaped.
        $payloadInstallationId = $status['installation_id'] ?? null;
        if (!is_string($payloadInstallationId) || preg_match(self::INSTALLATION_ID_PATTERN, $payloadInstallationId) !== 1) {
            return $this->fail('rejected', 'installation_mismatch');
        }
        if (preg_match(self::INSTALLATION_ID_PATTERN, $this->installId) !== 1) {
            return $this->fail('rejected', 'installation_mismatch');
        }
        if ($payloadInstallationId !== $this->installId) {
            return $this->fail('rejected', 'installation_mismatch');
        }

        // Phase 10: the rest of the signed shape must be what the contract
        // (11 §2) says it is, rather than silently coerced -- a signed
        // payload whose fields are the wrong type is malformed, and never
        // replaces a trusted cache. checked_at is required: it is the
        // server-issued timestamp the store orders concurrent writes by.
        $entitlements = $status['entitlements'] ?? [];
        if (!is_array($entitlements) || !array_is_list($entitlements)) {
            return $this->fail('rejected', 'malformed_payload');
        }
        foreach ($entitlements as $key) {
            if (!is_string($key)) return $this->fail('rejected', 'malformed_payload');
        }
        foreach (['plan', 'expires_at'] as $field) {
            if (isset($status[$field]) && !is_string($status[$field])) return $this->fail('rejected', 'malformed_payload');
        }
        if (!isset($status['checked_at']) || !is_string($status['checked_at']) || strtotime($status['checked_at']) === false) {
            return $this->fail('rejected', 'malformed_payload');
        }
        if (isset($status['next_check_after']) && !is_int($status['next_check_after'])) {
            return $this->fail('rejected', 'malformed_payload');
        }

        // Atomic by contract (LicenseCacheStoreInterface::save()): a store
        // failure leaves the previous trusted state untouched, and is
        // reported here as a failed check-in rather than an exception that
        // escapes into a cron run or an admin page.
        try {
            $this->store->save([
                'status'       => $status['status'],
                'plan'         => $status['plan'] ?? null,
                'entitlements' => $entitlements,
                'expires_at'   => $status['expires_at'] ?? null,
                'installation_id' => $payloadInstallationId,
                'remote_checked_at' => $status['checked_at'],
                'next_check_after' => $status['next_check_after'] ?? null,
                'fetched_at'   => gmdate('c'),
                'raw_payload'  => $envelope['payload'],
                'raw_signature' => $envelope['signature'],
            ]);
        } catch (\LicenseCacheStaleException $e) {
            return $this->fail('rejected', 'stale_response');
        } catch (\Throwable $e) {
            return $this->fail('rejected', 'cache_write_failed');
        }
        return ['ok' => true, 'reason' => null];
    }

    /** Real transport. Returns null on anything that isn't a completed HTTP round trip. */
    private static function curlPost(string $url, string $jsonBody): ?array {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw     = curl_exec($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errored = curl_errno($ch) !== 0;
        if ($errored || !is_string($raw)) return null;
        return [$status, $raw];
    }
}
