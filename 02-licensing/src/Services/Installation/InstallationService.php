<?php
/**
 * QA Fix Round 1 (Phase 4, Fix 5) schema/behavior parity with 01-client's
 * Slate\Services\Installation\InstallationService.
 *
 * This product (the central licensing server) is not itself a licensed
 * client of anything, so it has no installer step that provisions an
 * `installation_identity` row (unlike 01-client's provision(), which is a
 * genuine Phase 4 installer concern out of scope here — see FIX 6's report
 * for why that stays a 01-client-only concept). This class exists solely
 * so Slate\Services\Licensing\SlateLicenseCacheStore on this side reads a
 * local Installation ID through the exact same shape of accessor the
 * 01-client copy uses, so the two copies of that class can never diverge
 * in security behavior: with no row ever written here, the table read is
 * always empty and currentInstallationId() always returns null, which
 * correctly makes any cached row here permanently untrusted (fail-closed,
 * never silently accepted) -- consistent with this product never actually
 * being remote-licensed in production.
 */

declare(strict_types=1);

namespace Slate\Services\Installation;

final class InstallationService
{
    /**
     * The canonical Installation ID format — lowercase hex, exactly 32
     * characters. Kept identical to 01-client's constant of the same name.
     */
    public const INSTALLATION_ID_PATTERN = '/^[a-f0-9]{32}$/';

    public static function isValidInstallationId(string $installationId): bool
    {
        return preg_match(self::INSTALLATION_ID_PATTERN, $installationId) === 1;
    }

    /**
     * The single authoritative accessor for this deployment's own
     * Installation ID, mirroring 01-client's method of the same name and
     * signature exactly. Returns null both when no row exists (the normal,
     * expected case here) and when a row exists but is malformed —
     * fail-safe, never a raw/untrusted value.
     */
    public static function currentInstallationId(): ?string
    {
        if (!class_exists('\Database')) return null;
        $value = (string) \Database::value(
            'SELECT installation_id FROM installation_identity WHERE singleton_id = 1 LIMIT 1'
        );
        return self::isValidInstallationId($value) ? $value : null;
    }
}
