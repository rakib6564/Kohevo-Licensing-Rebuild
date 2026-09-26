<?php
/**
 * Slate — client-facing license view model (Phase 8, Client License UI).
 *
 * A read-only PRESENTER over licensing state other layers already own. It
 * adds no licensing authority, no second trust source and no module-state
 * table. Every decision comes from an existing, already-verified source:
 *
 *   - lock / no-lock and its reason   → slate_license_guard_state()
 *                                       (Global License Guard, Phase 6)
 *   - plan / status / expiry           → SlateLicenseCacheStore::readTrustState()
 *                                       (Phase 4/5), shown ONLY when trusted
 *   - per-module entitlement           → ModuleGuard::isEntitled() (Phase 7,
 *                                       the same answer its enforcement uses)
 *                                       and EntitlementService::canAccessCapability()
 *                                       (the same answer minus the plugin-active check)
 *
 * This class only maps those answers to labels. It never decides that a
 * license is valid, expired or entitled on its own; when the trusted layer
 * has no trusted answer it shows no plan, no expiry and no enabled modules
 * (Phase 8 §17: never fabricate license information).
 *
 * Never part of the output: the raw license key, the installation ID,
 * signatures, server URL/public key, or any other env value.
 *
 * Expiry warnings, countdowns and grace-period notifications are Phase 9 —
 * the one expiry-related state here ("grace") is a plain label for the
 * already-existing Guard behaviour of allowing access after expires_at.
 */

declare(strict_types=1);

namespace Slate\Services\Licensing;

final class LicenseStatusPresenter
{
    /**
     * The OPTIONAL, purchasable modules (04-ENTITLEMENT-ARCHITECTURE.md §1).
     * Keys are the plugin slugs ModuleGuard enforces. Core (dashboard,
     * users, site settings) is deliberately absent: it is included with any
     * valid license and is never presented as a purchasable module.
     */
    public const OPTIONAL_MODULES = [
        'forms'      => 'Form Builder',
        'membership' => 'Membership',
        'booking'    => 'Booking',
    ];

    /** Commercial statuses the trusted cache may carry, mapped to display labels. */
    private const STATUS_LABELS = [
        'active'    => 'Active',
        'trial'     => 'Trial',
        'expired'   => 'Expired',
        'suspended' => 'Suspended',
        'revoked'   => 'Revoked',
        'cancelled' => 'Cancelled',
    ];

    /**
     * Build the view model from the live, trusted services for the current
     * installation.
     *
     * @return array<string,mixed> see fromInputs()
     */
    public static function current(): array
    {
        $tenantId = \current_tenant_id();

        try {
            $trust = (new SlateLicenseCacheStore($tenantId))->readTrustState();
        } catch (\Throwable $e) {
            $trust = ['found' => false, 'trusted' => false, 'data' => null];
            $guard = ['locked' => true, 'reason' => 'error'];
        }
        $guard ??= \function_exists('slate_license_guard_state')
            ? \slate_license_guard_state()
            : ['locked' => true, 'reason' => 'unavailable'];

        $moduleState = static function (string $key) use ($tenantId): array {
            try {
                return [
                    'entitled' => ModuleGuard::isEntitled($key),
                    'licensed' => EntitlementService::canAccessCapability($tenantId, $key),
                ];
            } catch (\Throwable $e) {
                return ['entitled' => false, 'licensed' => false];
            }
        };

        try {
            $mode = EntitlementService::authorityMode();
        } catch (\Throwable $e) {
            $mode = 'unconfigured';
        }

        return self::fromInputs($trust, $guard, $moduleState, $mode);
    }

    /**
     * Pure mapping from already-decided inputs to display data. No database
     * or environment access, so it is exhaustively unit-testable.
     *
     * @param array{found:bool,trusted:bool,data:?array} $trust  SlateLicenseCacheStore::readTrustState()
     * @param array{locked:bool,reason:?string}          $guard  slate_license_guard_state()
     * @param callable(string):array{entitled:bool,licensed:bool} $moduleState
     * @param string $authorityMode EntitlementService::authorityMode()
     *
     * @return array{
     *   state:string, label:string, tone:string, summary:string, locked:bool,
     *   show_details:bool, plan:?string, expires_at:?string, last_verified_at:?string,
     *   modules:list<array{key:string,label:string,state:string,state_label:string}>,
     *   notice:?string
     * }
     */
    public static function fromInputs(array $trust, array $guard, callable $moduleState, string $authorityMode = 'remote'): array
    {
        $locked = ($guard['locked'] ?? true) !== false; // anything but an explicit false is locked
        $reason = is_string($guard['reason'] ?? null) ? $guard['reason'] : null;
        $data   = (!empty($trust['found']) && !empty($trust['trusted']) && is_array($trust['data'] ?? null))
            ? $trust['data'] : null;

        [$state, $showDetails] = self::resolveState($locked, $reason, $data);

        [$label, $tone, $summary] = self::describe($state);

        $modules = [];
        foreach (self::OPTIONAL_MODULES as $key => $moduleLabel) {
            $modules[] = self::moduleRow($key, $moduleLabel, $state, $moduleState);
        }

        $notice = null;
        if (!$locked && $authorityMode === 'unconfigured') {
            $notice = 'License server settings are incomplete for this installation, so optional modules cannot be enabled. Contact your provider.';
        }

        return [
            'state'            => $state,
            'label'            => $label,
            'tone'             => $tone,
            'summary'          => $summary,
            'locked'           => $locked,
            'show_details'     => $showDetails,
            'plan'             => $showDetails ? self::cleanText($data['plan'] ?? null) : null,
            'expires_at'       => $showDetails ? self::cleanDate($data['expires_at'] ?? null) : null,
            'last_verified_at' => $data !== null ? self::cleanDate($data['fetched_at'] ?? null) : null,
            'modules'          => $modules,
            'notice'           => $notice,
        ];
    }

    /**
     * @return array{0:string,1:bool} [state key, whether trusted plan/expiry may be shown]
     */
    private static function resolveState(bool $locked, ?string $reason, ?array $data): array
    {
        if (!$locked) {
            // The Guard only unlocks on a trusted, fresh trial/active row;
            // if the presenter somehow lacks that row, show nothing.
            $status = $data !== null ? (string) ($data['status'] ?? '') : '';
            if (!in_array($status, ['active', 'trial'], true)) {
                return ['untrusted', false];
            }
            $expires = self::cleanDate($data['expires_at'] ?? null);
            if ($expires !== null && strtotime($expires) <= time()) {
                // Past expires_at but the Guard still allows access: the
                // existing commercial grace window (08 §2). Label only.
                return ['grace', true];
            }
            return [$status, true];
        }

        switch ($reason) {
            case 'missing':
                return ['missing', false];
            case 'untrusted':
                return ['untrusted', false];
            case 'stale':
                return ['stale', false];
            case 'unavailable':
            case 'error':
            case null:
                return ['unavailable', false];
        }

        // A commercial status (suspended/revoked/expired/...) delivered by a
        // signed check-in. Only a known status keeps its name; anything else
        // is shown generically rather than echoing an unrecognised value.
        $known = isset(self::STATUS_LABELS[$reason]) && !in_array($reason, ['active', 'trial'], true);
        $state = $known ? $reason : 'inactive';
        return [$state, $data !== null];
    }

    /** @return array{0:string,1:string,2:string} [label, tone, summary] */
    private static function describe(string $state): array
    {
        return match ($state) {
            'active'      => ['Active', 'success', 'Your license is active.'],
            'trial'       => ['Trial', 'success', 'Your trial license is active.'],
            'grace'       => ['Expired — grace period', 'warning', 'Your license has expired. Access continues for a limited grace period.'],
            'expired'     => ['Expired', 'danger', 'Your license has expired.'],
            'suspended'   => ['Suspended', 'danger', 'Your license has been suspended.'],
            'revoked'     => ['Revoked', 'danger', 'Your license has been revoked.'],
            'cancelled'   => ['Cancelled', 'danger', 'Your license has been cancelled.'],
            'inactive'    => ['Inactive', 'danger', 'Your license is not currently active.'],
            'missing'     => ['Not activated', 'muted', 'No license has been activated for this installation yet.'],
            'untrusted'   => ['Unverified', 'danger', 'The stored license information could not be verified for this installation.'],
            'stale'       => ['Verification overdue', 'warning', 'This installation has not been able to confirm its license with the license server recently.'],
            default       => ['Unavailable', 'danger', 'License information is currently unavailable.'],
        };
    }

    /**
     * @param callable(string):array{entitled:bool,licensed:bool} $moduleState
     * @return array{key:string,label:string,state:string,state_label:string}
     */
    private static function moduleRow(string $key, string $label, string $licenseState, callable $moduleState): array
    {
        $answer   = $moduleState($key);
        $entitled = is_array($answer) && ($answer['entitled'] ?? false) === true;
        $licensed = is_array($answer) && ($answer['licensed'] ?? false) === true;

        if ($entitled) {
            $state = 'enabled';
        } elseif ($licensed) {
            // Included in the license, but the plugin itself is switched off.
            $state = 'inactive';
        } elseif (in_array($licenseState, ['active', 'trial'], true)) {
            $state = 'not_included';
        } else {
            // With no fully valid license we cannot honestly say what the
            // license would include — only that the module is not available.
            $state = 'unavailable';
        }

        $stateLabel = match ($state) {
            'enabled'      => 'Enabled',
            'inactive'     => 'Included — not activated',
            'not_included' => 'Not included',
            default        => 'Unavailable',
        };

        return ['key' => $key, 'label' => $label, 'state' => $state, 'state_label' => $stateLabel];
    }

    /** Plain display text, or null. Rejects anything that is not a short scalar string. */
    private static function cleanText(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 64 || preg_match('/[\x00-\x1F\x7F]/', $value)) return null;
        return $value;
    }

    /**
     * A parseable date normalised to 'Y-m-d H:i:s', or null. Parsed and
     * re-formatted in the same timezone, exactly as the Guard and
     * EntitlementService read these columns (plain strtotime()).
     */
    private static function cleanDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') return null;
        $ts = strtotime($value);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }
}
