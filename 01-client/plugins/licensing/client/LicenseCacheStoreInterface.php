<?php
/**
 * The storage seam for RemoteLicenseClient — deliberately tiny and
 * deliberately dumb. RemoteLicenseClient never touches a database
 * directly; it only ever calls load()/save() on whatever implements
 * this. Each product wires its own adapter against its own storage
 * however fits (Kohevo's is Slate\Services\Licensing\SlateLicenseCacheStore,
 * a small class over a single DB table) — the HTTP + signature-
 * verification logic in RemoteLicenseClient never changes across products.
 */

declare(strict_types=1);

interface LicenseCacheStoreInterface {

    /**
     * The last successfully verified status, or null if none has ever
     * been stored. Shape: {status, plan, entitlements, expires_at,
     * fetched_at, installation_id, remote_checked_at, next_check_after} —
     * the latter three fields are copied only from the verified signed
     * server payload. `installation_id` (Phase 4) is the payload's own
     * server-resolved identity for this install; an implementation MAY
     * additionally treat a stored value that no longer matches this
     * install's own local identity as untrusted at read time too (see
     * SlateLicenseCacheStore for the reference implementation).
     */
    public function load(): ?array;

    /**
     * Persist a newly verified status. Only ever called after a response
     * has been signature-verified and bound to this installation.
     *
     * Phase 10: $status also carries `raw_payload` (the exact signed JSON
     * string) and `raw_signature` (its base64 Ed25519 signature) so an
     * implementation can retain them and re-verify at read time
     * (docs/02-architecture/10-CLIENT-LICENSING-DATABASE-DESIGN.md §3–§4).
     *
     * A save must be all-or-nothing: on any failure it throws and leaves
     * the previously stored state exactly as it was. An implementation
     * that already holds a state signed LATER than this one (by the
     * payload's own server-issued `checked_at`) throws
     * LicenseCacheStaleException instead of overwriting newer state with
     * older state.
     */
    public function save(array $status): void;
}

/**
 * Thrown by LicenseCacheStoreInterface::save() when the store already holds
 * a verified state the Central Server issued later than the one offered —
 * e.g. two overlapping check-ins (cron + an admin re-check) completing out
 * of order. The newer state is kept; the older one is discarded.
 */
final class LicenseCacheStaleException extends \RuntimeException {}
