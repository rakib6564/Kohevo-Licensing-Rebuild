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
 * Phase 9 (expiry & commercial grace): the commercial timeline —
 * active → expiring soon (7 days before expires_at) → expired, in grace
 * (7 days after) → locked — comes from CommercialLicenseWindow, the same
 * pure evaluation the Guard and EntitlementService enforce with. This class
 * turns it into labels, exact UTC dates, remaining time and the admin
 * warning banner; it never re-derives a boundary itself. Whether the page
 * is locked is still taken from the Guard's own answer.
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
     * Phase 9: the compact expiry / grace banner for the admin chrome
     * (admin/partials/header.php), or null when there is nothing to warn
     * about. Deliberately lighter than current(): one trusted-cache read and
     * the pure window evaluation — no module or authority lookups — because
     * it runs on every admin page. Only ever non-null while access is still
     * allowed (warning or grace); a locked installation never reaches a
     * normal admin page, and its License page explains the lock itself.
     *
     * @return array{tone:string,title:string,message:string,phase:string}|null
     */
    public static function expiryBanner(?int $now = null): ?array
    {
        try {
            $trust = (new SlateLicenseCacheStore(\current_tenant_id()))->readTrustState();
        } catch (\Throwable $e) {
            return null;
        }
        return self::bannerFor(CommercialLicenseWindow::evaluate($trust, $now ?? time()));
    }

    /**
     * Pure mapping from already-decided inputs to display data. No database
     * or environment access, so it is exhaustively unit-testable.
     *
     * @param array{found:bool,trusted:bool,data:?array} $trust  SlateLicenseCacheStore::readTrustState()
     * @param array{locked:bool,reason:?string}          $guard  slate_license_guard_state()
     * @param callable(string):array{entitled:bool,licensed:bool} $moduleState
     * @param string $authorityMode EntitlementService::authorityMode()
     * @param int|null $now server clock (Unix seconds); defaults to time().
     *        Only used for the commercial timeline labels — the lock
     *        decision itself is always $guard's.
     *
     * @return array{
     *   state:string, label:string, tone:string, summary:string, locked:bool,
     *   show_details:bool, plan:?string, expires_at:?string, last_verified_at:?string,
     *   modules:list<array{key:string,label:string,state:string,state_label:string}>,
     *   notice:?string,
     *   expires_label:?string, grace_ends_at:?string, grace_ends_label:?string,
     *   time_remaining:?string, banner:?array{tone:string,title:string,message:string,phase:string}
     * }
     */
    public static function fromInputs(array $trust, array $guard, callable $moduleState, string $authorityMode = 'remote', ?int $now = null): array
    {
        $locked = ($guard['locked'] ?? true) !== false; // anything but an explicit false is locked
        $reason = is_string($guard['reason'] ?? null) ? $guard['reason'] : null;
        $data   = (!empty($trust['found']) && !empty($trust['trusted']) && is_array($trust['data'] ?? null))
            ? $trust['data'] : null;

        $window = CommercialLicenseWindow::evaluate($trust, $now ?? time());

        [$state, $showDetails] = self::resolveState($locked, $reason, $data, $window);

        [$label, $tone, $summary] = self::describe($state);

        // Commercial timeline details — only from a trusted snapshot, and
        // only for the states where they are meaningful.
        $expiresTs    = $showDetails ? $window['expires_at'] : null;
        $graceEndsTs  = $showDetails && in_array($state, ['grace', 'expired'], true) ? $window['grace_ends_at'] : null;
        $remaining    = null;
        if (!$locked && $state === 'expiring_soon') {
            $remaining = self::duration((int) $window['seconds_until_expiry']);
        } elseif (!$locked && $state === 'grace') {
            $remaining = self::duration((int) $window['grace_seconds_remaining']);
        }

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
            'expires_label'    => $expiresTs !== null ? self::dateLabel($expiresTs) : null,
            'grace_ends_at'    => $graceEndsTs !== null ? gmdate('Y-m-d H:i:s', $graceEndsTs) : null,
            'grace_ends_label' => $graceEndsTs !== null ? self::dateLabel($graceEndsTs) : null,
            'time_remaining'   => $remaining,
            'banner'           => $locked ? null : self::bannerFor($window),
        ];
    }

    /**
     * The one wording of the Phase 9 warning, shared by the admin banner and
     * the License page. Never says "active" once expires_at has passed, and
     * never attributes grace to connectivity — it is purely commercial.
     *
     * @param array<string,mixed> $window CommercialLicenseWindow::evaluate()
     * @return array{tone:string,title:string,message:string,phase:string}|null
     */
    private static function bannerFor(array $window): ?array
    {
        if (($window['allowed'] ?? false) !== true) return null;

        if ($window['phase'] === CommercialLicenseWindow::PHASE_EXPIRING_SOON && $window['expires_at'] !== null) {
            return [
                'phase'   => CommercialLicenseWindow::PHASE_EXPIRING_SOON,
                'tone'    => 'warning',
                'title'   => 'License expiring soon',
                'message' => 'Your license expires on ' . self::dateLabel($window['expires_at'])
                    . ' (in ' . self::duration((int) $window['seconds_until_expiry']) . ').'
                    . ' Contact your provider to renew and avoid any interruption.',
            ];
        }

        if ($window['phase'] === CommercialLicenseWindow::PHASE_GRACE
            && $window['expires_at'] !== null && $window['grace_ends_at'] !== null) {
            return [
                'phase'   => CommercialLicenseWindow::PHASE_GRACE,
                'tone'    => 'danger',
                'title'   => 'License expired — grace period',
                'message' => 'Your license expired on ' . self::dateLabel($window['expires_at']) . '.'
                    . ' This installation remains usable during a ' . intdiv(CommercialLicenseWindow::GRACE_SECONDS, 86400)
                    . '-day grace period ending ' . self::dateLabel($window['grace_ends_at'])
                    . ' (' . self::duration((int) $window['grace_seconds_remaining']) . ' remaining).'
                    . ' After that it will be locked until the license is renewed. Contact your provider to renew.',
            ];
        }

        return null;
    }

    /** Exact, timezone-explicit label: every expiry decision is made in UTC. */
    private static function dateLabel(int $ts): string
    {
        return gmdate('M j, Y H:i', $ts) . ' UTC';
    }

    /**
     * Remaining time, truncated (never rounded up, so it never overstates
     * how long access continues): "6 days 23 hours", "5 hours",
     * "12 minutes", "less than a minute".
     */
    private static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $plural  = static fn(int $n, string $unit): string => $n . ' ' . $unit . ($n === 1 ? '' : 's');
        if ($seconds >= 86400) {
            $days  = intdiv($seconds, 86400);
            $hours = intdiv($seconds % 86400, 3600);
            return $plural($days, 'day') . ($hours > 0 ? ' ' . $plural($hours, 'hour') : '');
        }
        if ($seconds >= 3600) return $plural(intdiv($seconds, 3600), 'hour');
        if ($seconds >= 60)   return $plural(intdiv($seconds, 60), 'minute');
        return 'less than a minute';
    }

    /**
     * @return array{0:string,1:bool} [state key, whether trusted plan/expiry may be shown]
     */
    private static function resolveState(bool $locked, ?string $reason, ?array $data, array $window): array
    {
        if (!$locked) {
            // The Guard only unlocks on a trusted, fresh snapshot inside the
            // commercial timeline; if the presenter's own read of that same
            // snapshot does not agree, show nothing rather than guess.
            if ($data === null || ($window['allowed'] ?? false) !== true) {
                return ['untrusted', false];
            }
            return match ($window['phase']) {
                CommercialLicenseWindow::PHASE_GRACE         => ['grace', true],
                CommercialLicenseWindow::PHASE_EXPIRING_SOON => ['expiring_soon', true],
                default => in_array((string) ($data['status'] ?? ''), ['active', 'trial'], true)
                    ? [(string) $data['status'], true]
                    : ['untrusted', false],
            };
        }

        switch ($reason) {
            case 'missing':
                return ['missing', false];
            case 'untrusted':
            case 'malformed':
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
            'expiring_soon' => ['Expiring soon', 'warning', 'Your license expires within the next 7 days.'],
            'grace'       => ['Expired — grace period', 'warning', 'Your license has expired. Access continues during the 7-day grace period.'],
            'expired'     => ['Expired', 'danger', 'Your license has expired and its 7-day grace period has ended.'],
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
        } elseif (in_array($licenseState, ['active', 'trial', 'expiring_soon', 'grace'], true)) {
            // A license that still grants access (including warning and
            // commercial grace, where entitlements remain live) can say
            // honestly what it does not include.
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
     * A parseable date normalised to UTC 'Y-m-d H:i:s', or null. Parsed by
     * the same CommercialLicenseWindow::parseUtc() the Guard and
     * EntitlementService enforce with, so display and enforcement agree.
     */
    private static function cleanDate(mixed $value): ?string
    {
        $ts = CommercialLicenseWindow::parseUtc($value);
        return $ts === null ? null : gmdate('Y-m-d H:i:s', $ts);
    }
}
