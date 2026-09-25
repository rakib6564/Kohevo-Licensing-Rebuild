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
        $requestBody = json_encode([
            'product'     => $this->productSlug,
            'license_key' => $this->licenseKey,
            'install_id'  => $this->installId,
            'domain'      => $this->domain,
            'app_version' => $this->appVersion,
            'checked_at'  => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES);
        if ($requestBody === false) return false;

        $response = ($this->transport)($this->serverUrl . '/licensing/check', $requestBody);
        if ($response === null) return false;

        [$httpStatus, $responseBody] = $response;
        if ($httpStatus !== 200) return false;

        $envelope = json_decode($responseBody, true);
        if (!is_array($envelope) || !isset($envelope['payload'], $envelope['signature'])
            || !is_string($envelope['payload']) || !is_string($envelope['signature'])) {
            return false;
        }

        if (!$this->verifier->verify($envelope['payload'], $envelope['signature'])) {
            return false;
        }

        $status = json_decode($envelope['payload'], true);
        if (!is_array($status) || !isset($status['status'])) {
            return false; // signature was valid, but the signed content itself is malformed
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
        if (!isset($status['installation_id']) || !is_string($status['installation_id'])
            || $status['installation_id'] !== $this->installId) {
            return false;
        }

        $this->store->save([
            'status'       => (string) $status['status'],
            'plan'         => $status['plan'] ?? null,
            'entitlements' => is_array($status['entitlements'] ?? null) ? $status['entitlements'] : [],
            'expires_at'   => $status['expires_at'] ?? null,
            'installation_id' => $status['installation_id'],
            'fetched_at'   => gmdate('c'),
        ]);
        return true;
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
