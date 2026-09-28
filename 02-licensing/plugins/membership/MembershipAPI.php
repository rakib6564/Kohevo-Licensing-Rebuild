<?php
/**
 * Membership — public API facade.
 *
 * The single entry point other plugins (and this plugin's own pages) call.
 * Booking, for example, asks `MembershipAPI::isActive($customerId)` to enforce
 * the active-membership gate without knowing anything about our tables.
 *
 * Identity is core `customers`; everything here hangs off `customer_id`.
 * Payment money lives in the shared `stripepayment_charges` ledger.
 *
 * Phase 1 scope: schema self-heal, plan reads, subscription status reads,
 * profile/wallet provisioning, and i18n-aware formatting helpers. Purchase /
 * Stripe checkout (Phase 2), the onboarding wizard (Phase 3) and the Booking
 * gate wiring (Phase 4) build on these primitives.
 */

class MembershipAPI {

    /** Set true once per request after ensureSchema() has run. */
    private static bool $schemaChecked = false;

    // ── Schema self-heal ─────────────────────────────────────────────────
    // Idempotent CREATE TABLE IF NOT EXISTS for every membership_* table, so a
    // page works even if install.sql was never run (mirrors the Booking /
    // Stripe plugins). Cheap after the first call thanks to the static guard.
    public static function ensureSchema(): void {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;

        $file = __DIR__ . '/install.sql';
        if (!is_file($file)) return;
        $sql = (string) file_get_contents($file);
        $sql = preg_replace('/^--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt === '') continue;
            try {
                Database::query($stmt);
            } catch (\Throwable $e) {
                slate_log('Membership ensureSchema statement failed: ' . $e->getMessage(), 'error');
            }
        }

        // Additive columns for existing installs (CREATE TABLE IF NOT EXISTS
        // above won't alter tables that already exist). Each is idempotent.
        self::ensureColumn('membership_plans', 'insurance_mode',
            "ENUM('none','optional','required') NOT NULL DEFAULT 'none' AFTER requires_insurance");
        self::ensureColumn('membership_subscriptions', 'insurance_included',
            'TINYINT(1) NOT NULL DEFAULT 0 AFTER currency');
        self::ensureColumn('membership_subscriptions', 'insurance_fee_cents',
            'INT UNSIGNED NOT NULL DEFAULT 0 AFTER insurance_included');
        self::ensureColumn('membership_plans', 'session_quota',
            'INT UNSIGNED NOT NULL DEFAULT 0 AFTER duration_days');

        // One-time backfill: legacy requires_insurance=1 → insurance_mode='required'.
        try {
            Database::query(
                "UPDATE membership_plans SET insurance_mode = 'required'
                  WHERE requires_insurance = 1 AND insurance_mode = 'none'");
        } catch (\Throwable $e) { /* columns may be brand new — nothing to backfill */ }
    }

    /** Idempotently add a column if it's missing (mirrors the Booking plugin). */
    private static function ensureColumn(string $table, string $column, string $definition): void {
        try {
            $exists = (int) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$table, $column]
            );
            if ($exists === 0) {
                Database::query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        } catch (\Throwable $e) {
            slate_log("Membership ensureColumn {$table}.{$column} failed: " . $e->getMessage(), 'error');
        }
    }

    // ── Reference data ───────────────────────────────────────────────────

    /** Plan type key => i18n label key. */
    public static function planTypes(): array {
        return [
            'membership' => __('membership_type_membership', 'Membership'),
            'insurance'  => __('membership_type_insurance',  'Insurance'),
            'course'     => __('membership_type_course',     'Course-specific'),
        ];
    }

    public static function skillLevels(): array {
        return [
            'none'         => __('membership_skill_none',         'Not specified'),
            'beginner'     => __('membership_skill_beginner',     'Beginner'),
            'intermediate' => __('membership_skill_intermediate', 'Intermediate'),
            'advanced'     => __('membership_skill_advanced',     'Advanced'),
        ];
    }

    public static function genders(): array {
        return [
            'undisclosed' => __('membership_gender_undisclosed', 'Prefer not to say'),
            'female'      => __('membership_gender_female',      'Female'),
            'male'        => __('membership_gender_male',        'Male'),
            'other'       => __('membership_gender_other',       'Other'),
        ];
    }

    /**
     * Default currency for new plans. Delegates to the tenant-wide Settings
     * → General currency (slate_default_currency()) rather than keeping a
     * separate membership.currency setting — one place to configure it.
     */
    public static function currency(): string {
        return slate_default_currency();
    }

    /** Per-plan insurance add-on modes. */
    public static function insuranceModes(): array {
        return [
            'none'     => __('membership_ins_none',     'No insurance'),
            'optional' => __('membership_ins_optional', 'Optional add-on'),
            'required' => __('membership_ins_required', 'Required (always added)'),
        ];
    }

    /** The single global insurance add-on fee, in cents (Membership → Settings). */
    public static function insuranceFeeCents(): int {
        return max(0, (int) Database::setting('membership.insurance_fee_cents'));
    }

    /** True when the digital membership card & QR code are enabled (Membership → Settings). */
    public static function cardEnabled(): bool {
        return (string) (Database::setting('membership.enable_card') ?? '1') !== '0';
    }

    /** True when attendance tracking & Recent attendance are enabled (Membership → Settings). */
    public static function attendanceEnabled(): bool {
        return (string) (Database::setting('membership.enable_attendance') ?? '1') !== '0';
    }

    /** True when the "How to check in" banner is enabled (Membership → Settings). */
    public static function checkinHowtoEnabled(): bool {
        return (string) (Database::setting('membership.enable_checkin_howto') ?? '1') !== '0';
    }

    /**
     * Resolve whether a purchase of $plan should include the insurance add-on.
     * 'required' → always; 'optional' → only if the member opted in; else no.
     * Returns [bool $included, int $feeCents].
     */
    public static function resolveInsurance(array $plan, bool $optedIn): array {
        $mode = (string) ($plan['insurance_mode'] ?? 'none');
        $fee  = self::insuranceFeeCents();
        if ($mode === 'required')            return [$fee > 0, $fee];
        if ($mode === 'optional' && $optedIn) return [$fee > 0, $fee];
        return [false, 0];
    }

    /** Format integer cents for display using the global currency symbol + position. */
    public static function money(int $cents, ?string $currency = null): string {
        return slate_format_price_plain($cents, $currency ?? self::currency());
    }

    /* ─────────────────── Content Builder integration (Phase D, D3) ───────────
     * A NEW block, not a conversion: membership never had one. It advertises the
     * plans on any page — names, descriptions, prices, term — as real document
     * content that is indexable and themeable.
     *
     * It deliberately does NOT sell. Buying a plan runs through /member, which
     * owns the login gate, the profile-completion gate, insurance add-on rules
     * and the Stripe hand-off. A public page block that POSTed a purchase would
     * step around all of that, and put purchase initiation into page content —
     * the same reason D2 left booking's wizard on /book rather than inlining it.
     * The card links; the portal sells. */

    /** Active, sellable plans as editor select options. */
    public static function blockPickerOptions(): array {
        $out = [['v' => '', 'l' => 'Show all plans']];
        try {
            foreach (self::sellablePlans() as $p) {
                $out[] = ['v' => (string)$p['id'], 'l' => self::planName($p)];
            }
        } catch (\Throwable $e) {
            // schema not ready — just offer "all".
        }
        return $out;
    }

    /**
     * Plans worth advertising.
     *
     * Insurance is excluded: it is an add-on bought alongside a membership, not
     * a thing anyone buys on its own, so listing it as a standalone card would
     * misrepresent what it is.
     */
    public static function sellablePlans(): array {
        return array_values(array_filter(
            self::plans(true),
            static fn (array $p): bool => ($p['plan_type'] ?? '') !== 'insurance'
        ));
    }

    /**
     * The plan cards, as HTML.
     *
     * Prices go through money(), which takes INTEGER minor units — price_cents —
     * so no amount is ever reconstructed from a float here. The only division is
     * inside money()'s final formatting step, on an integer it was handed.
     */
    public static function renderPlanCards(callable $href, ?int $onlyPlanId = null): string {
        $plans = self::sellablePlans();
        if ($onlyPlanId !== null) {
            $plans = array_values(array_filter(
                $plans,
                static fn (array $p): bool => (int)($p['id'] ?? 0) === $onlyPlanId
            ));
        }
        if (!$plans) {
            return '<div class="cb-mplans-empty">'
                 . ($onlyPlanId !== null ? 'That plan is no longer available.' : 'No plans are available yet.')
                 . '</div>';
        }

        $out = '<div class="cb-mplans">';
        foreach ($plans as $p) {
            $days = (int)($p['duration_days'] ?? 0);
            $out .= '<div class="cb-mplan">'
                  . '<div class="cb-mplan-name">' . e(self::planName($p)) . '</div>'
                  . '<div class="cb-mplan-price">'
                  . e(self::money((int)$p['price_cents'], (string)$p['currency']))
                  . ($days > 0 ? '<span class="cb-mplan-term"> / ' . $days . ' days</span>' : '')
                  . '</div>';
            $desc = trim((string) self::planDescription($p));
            if ($desc !== '') {
                $out .= '<div class="cb-mplan-desc">' . nl2br(e($desc)) . '</div>';
            }
            $out .= '<a class="cb-mplan-cta" href="' . e((string) $href((int)$p['id'])) . '">Choose plan</a>'
                  . '</div>';
        }
        return $out . '</div>';
    }

    /**
     * Minimal stylesheet for the block.
     *
     * Membership has no shared public stylesheet to reuse — the portal's card
     * styles are an inline <style> in landing.php, scoped to the portal's own
     * chrome — so dragging those onto a public page would import layout that has
     * nothing to do with this block.
     *
     * Written against --slate-* directly rather than a new --mb-* family: this is
     * new code, and Phase E's whole aim is one vocabulary, so adding a sixth
     * would be work created for the sake of removing it later.
     */
    public static function blockCss(): string {
        return '<style id="cb-mplans-css">'
             . '.cb-mplans{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));'
             . 'gap:var(--slate-space-6,1.5rem);margin:var(--slate-space-8,2rem) 0}'
             . '.cb-mplan{border:1px solid var(--slate-color-border,#e2e8f0);'
             . 'border-radius:var(--slate-radius-lg,16px);padding:var(--slate-space-6,1.5rem);'
             . 'background:var(--slate-color-surface,#fff);display:flex;flex-direction:column;'
             . 'gap:var(--slate-space-2,.5rem)}'
             . '.cb-mplan-name{font-weight:var(--slate-font-weight-bold,700);'
             . 'font-size:var(--slate-font-size-lg,1.125rem);color:var(--slate-color-text,#0f172a)}'
             . '.cb-mplan-price{font-size:var(--slate-font-size-xl,1.25rem);'
             . 'color:var(--slate-color-text,#0f172a)}'
             // Also full text colour, for the same reason as the description: at
             // 14px this is normal text and needs 4.5:1, which muted does not
             // reach on four of the six palettes. The term still reads as
             // secondary because it is SMALLER — hierarchy carried by size
             // rather than by a contrast level that fails.
             . '.cb-mplan-term{font-size:var(--slate-font-size-sm,.875rem);'
             . 'color:var(--slate-color-text,#0f172a)}'
             // Full text colour, not muted. A plan description is content, not
             // de-emphasised metadata, so full contrast is the more correct
             // choice on its own terms — and it is the only one that holds at
             // 14px on every tenant palette. Measured on the warm-editorial
             // theme, muted-on-surface is 3.76:1 against a 4.5:1 requirement.
             // text-secondary would not be safe either: it sits between text and
             // muted and can still fail on a low-contrast brand.
             . '.cb-mplan-desc{color:var(--slate-color-text,#0f172a);'
             . 'font-size:var(--slate-font-size-sm,.875rem)}'
             . '.cb-mplan-cta{margin-top:auto;display:inline-block;text-align:center;'
             . 'padding:var(--slate-space-3,.75rem) var(--slate-space-4,1rem);'
             . 'border-radius:var(--slate-radius-control,8px);text-decoration:none;'
             . 'background:var(--slate-color-accent,#2563eb);color:var(--slate-color-on-accent,#fff)}'
             . '.cb-mplans-empty{color:var(--slate-color-text,#0f172a);'
             . 'padding:var(--slate-space-6,1.5rem);text-align:center}'
             . '</style>';
    }

    /** The `membership-plans` block. Renders cards; never initiates a purchase. */
    public static function renderContentBlock(array $props, array $block = []): string {
        // Phase 11: the block is a public Membership surface on any page — an
        // unentitled installation renders nothing, like /member?view=plans (07 §4).
        if (!\ModuleGuard::allows('membership')) return '';

        $planId = (int)($props['plan'] ?? 0);
        $base   = rtrim(SLATE_URL, '/') . '/member';

        return '<div class="cb-membership-plans">'
             . self::renderPlanCards(
                   static fn (int $id): string => $base . '?view=plans&plan=' . $id,
                   $planId > 0 ? $planId : null
               )
             . '</div>';
    }

    // ── Plans ────────────────────────────────────────────────────────────

    public static function plan(int $id): ?array {
        self::ensureSchema();
        return Database::row(
            "SELECT * FROM membership_plans WHERE id = ? AND tenant_id = ?",
            [$id, current_tenant_id()]
        ) ?: null;
    }

    /** All plans for the tenant; pass true to limit to active ones. */
    public static function plans(bool $activeOnly = false): array {
        self::ensureSchema();
        $sql = "SELECT * FROM membership_plans WHERE tenant_id = ?";
        if ($activeOnly) $sql .= " AND is_active = 1";
        $sql .= " ORDER BY sort_order, name";
        return Database::rows($sql, [current_tenant_id()]);
    }

    /**
     * True when a Booking service is gated by an active course-specific plan
     * tied to it via `course_id`. This is the per-service source of truth for
     * the "membership required" badge and booking gate — a general
     * (non-course) plan does NOT blanket-gate every service, only the ones an
     * admin actually attached a course plan to.
     */
    public static function serviceRequiresMembership(int $serviceId): bool {
        if ($serviceId <= 0) return false;
        self::ensureSchema();
        return (bool) Database::value(
            "SELECT 1 FROM membership_plans
              WHERE tenant_id = ? AND plan_type = 'course' AND course_id = ? AND is_active = 1
              LIMIT 1",
            [current_tenant_id(), $serviceId]
        );
    }

    /**
     * A customer's access to a specific course-gated service: do they hold an
     * active, unexpired course-specific subscription for it, and — if that
     * plan carries a session_quota — do they still have credits left?
     *
     * `used` only counts appointments booked since the subscription's own
     * starts_at, so renewing a plan resets the counter rather than carrying
     * over a previous term's usage.
     *
     * Returns:
     *   has_access — true if the customer can book this service right now
     *   plan_id    — the course plan backing access, or null if none is active
     *                (distinguishes "never had a plan / it expired" from
     *                "plan is active but the session quota ran out")
     *   quota      — session_quota on the plan (0 = unlimited)
     *   used       — confirmed/completed bookings against this service since starts_at
     *   remaining  — quota - used, or null when unlimited
     */
    public static function courseSubscriptionStatus(int $customerId, int $serviceId): array {
        $none = ['has_access' => false, 'plan_id' => null, 'quota' => 0, 'used' => 0, 'remaining' => null, 'sub_id' => null];
        if ($customerId <= 0 || $serviceId <= 0) return $none;
        self::ensureSchema();
        $tid = current_tenant_id();

        $sub = Database::row(
            "SELECT s.id AS sub_id, s.starts_at, p.id AS plan_id, p.session_quota
               FROM membership_subscriptions s
               JOIN membership_plans p ON p.id = s.plan_id
              WHERE s.tenant_id = ? AND s.customer_id = ? AND s.status = 'active'
                AND p.plan_type = 'course' AND p.course_id = ?
                AND (s.expires_at IS NULL OR COALESCE(s.grace_until, s.expires_at) >= NOW())
           ORDER BY s.expires_at DESC, s.id DESC
              LIMIT 1",
            [$tid, $customerId, $serviceId]
        );
        if (!$sub) return $none;

        $quota = (int) $sub['session_quota'];
        $used  = 0;
        if ($quota > 0) {
            $used = (int) Database::value(
                "SELECT COUNT(*) FROM booking_appointments
                  WHERE tenant_id = ? AND customer_id = ? AND service_id = ?
                    AND status IN ('confirmed','completed') AND created_at >= ?",
                [$tid, $customerId, $serviceId, $sub['starts_at']]
            );
        }
        $remaining = $quota > 0 ? max(0, $quota - $used) : null;

        return [
            'has_access' => $quota <= 0 || $remaining > 0,
            'plan_id'    => (int) $sub['plan_id'],
            'quota'      => $quota,
            'used'       => $used,
            'remaining'  => $remaining,
            'sub_id'     => (int) $sub['sub_id'],
        ];
    }

    /** Locale-aware plan name (FR primary when locale is fr and a name_fr exists). */
    public static function planName(array $plan): string {
        $locale = class_exists('I18n') ? I18n::currentLocale() : 'en';
        if ($locale === 'fr' && !empty($plan['name_fr'])) return (string) $plan['name_fr'];
        return (string) ($plan['name'] ?? '');
    }

    public static function planDescription(array $plan): string {
        $locale = class_exists('I18n') ? I18n::currentLocale() : 'en';
        if ($locale === 'fr' && !empty($plan['description_fr'])) return (string) $plan['description_fr'];
        return (string) ($plan['description'] ?? '');
    }

    // ── Subscriptions / lifecycle ────────────────────────────────────────

    /**
     * The member's current active subscription row, or null. "Active" means
     * status='active' and we're still inside the term (or its grace window).
     * Newest term wins if somehow more than one is live.
     */
    public static function activeSubscription(int $customerId): ?array {
        self::ensureSchema();
        return Database::row(
            "SELECT s.*, p.name AS plan_name, p.name_fr AS plan_name_fr, p.plan_type
               FROM membership_subscriptions s
               JOIN membership_plans p ON p.id = s.plan_id
              WHERE s.tenant_id = ? AND s.customer_id = ? AND s.status = 'active'
                AND (s.expires_at IS NULL OR COALESCE(s.grace_until, s.expires_at) >= NOW())
           ORDER BY s.expires_at DESC, s.id DESC
              LIMIT 1",
            [current_tenant_id(), $customerId]
        ) ?: null;
    }

    /**
     * Does this member hold an active base membership? Insurance/course plans
     * don't satisfy the gate on their own. This is the method Booking calls.
     */
    public static function isActive(int $customerId): bool {
        $sub = self::activeSubscription($customerId);
        if (!$sub) return false;
        return ($sub['plan_type'] ?? 'membership') === 'membership';
    }

    /**
     * Does the member currently hold active insurance? True if they hold a
     * standalone insurance-type plan, OR a membership whose purchase bundled
     * the insurance add-on (insurance_included).
     */
    public static function hasInsurance(int $customerId): bool {
        self::ensureSchema();
        $row = Database::row(
            "SELECT s.id FROM membership_subscriptions s
               JOIN membership_plans p ON p.id = s.plan_id
              WHERE s.tenant_id = ? AND s.customer_id = ? AND s.status = 'active'
                AND (p.plan_type = 'insurance' OR s.insurance_included = 1)
                AND (s.expires_at IS NULL OR COALESCE(s.grace_until, s.expires_at) >= NOW())
              LIMIT 1",
            [current_tenant_id(), $customerId]
        );
        return (bool) $row;
    }

    /**
     * True when the insurance feature is actually configured for this
     * tenant — an admin has either required insurance on at least one
     * Booking service, or made it available (optional/required) on an
     * active plan, or there's a standalone insurance-type plan to buy.
     * When none of that is true, insurance is dead weight in the UI (an
     * "Add insurance" card that unlocks nothing) and dashboards should
     * hide it rather than show a permanently-inactive card.
     */
    public static function insuranceInUse(): bool {
        self::ensureSchema();
        $tid = current_tenant_id();

        $requiredServices = array_filter(array_map('intval',
            explode(',', (string) Database::setting('membership.insurance_required_services'))));
        if ($requiredServices) return true;

        return (bool) Database::value(
            "SELECT 1 FROM membership_plans
              WHERE tenant_id = ? AND is_active = 1
                AND (plan_type = 'insurance' OR insurance_mode IN ('optional','required'))
              LIMIT 1",
            [$tid]
        );
    }

    /**
     * Compact status summary for dashboards / the booking gate.
     * Returns ['state' => 'active'|'expiring'|'expired'|'none', 'sub' => ?array,
     *          'days_left' => ?int, 'has_insurance' => bool].
     */
    public static function status(int $customerId): array {
        $sub = self::activeSubscription($customerId);
        $out = [
            'state'         => 'none',
            'sub'           => $sub,
            'days_left'     => null,
            'has_insurance' => self::hasInsurance($customerId),
        ];
        if (!$sub) return $out;

        $out['state'] = 'active';
        if (!empty($sub['expires_at'])) {
            $secs = strtotime($sub['expires_at']) - slate_db_time();
            $days = (int) floor($secs / 86400);
            $out['days_left'] = $days;
            if ($days <= 7) $out['state'] = 'expiring';
        }
        return $out;
    }

    // ── Profile + wallet provisioning ────────────────────────────────────

    /**
     * Ensure a member profile row exists for a customer (idempotent). Called
     * when a customer registers and lazily by member-facing pages. Returns the
     * profile row.
     */
    public static function ensureProfile(int $customerId): array {
        self::ensureSchema();
        $tid = current_tenant_id();
        $row = Database::row(
            "SELECT * FROM membership_profiles WHERE tenant_id = ? AND customer_id = ?",
            [$tid, $customerId]
        );
        if ($row) return $row;

        try {
            Database::insert('membership_profiles', [
                'tenant_id'   => $tid,
                'customer_id' => $customerId,
                'qr_token'    => bin2hex(random_bytes(16)),
                'locale'      => class_exists('I18n') ? I18n::currentLocale() : 'fr',
            ]);
        } catch (\Throwable $e) {
            // A concurrent request won the unique (tenant, customer) race.
            slate_log('Membership ensureProfile insert: ' . $e->getMessage(), 'warning');
        }
        return Database::row(
            "SELECT * FROM membership_profiles WHERE tenant_id = ? AND customer_id = ?",
            [$tid, $customerId]
        ) ?: [];
    }

    /**
     * Whether the member-profile completion step is switched on
     * (Membership → Settings → Member portal features). On by default; when off, members
     * are never forced through the welcome wizard and the Booking gate stops asking for a
     * completed profile.
     */
    public static function profileCompletionEnabled(): bool {
        return (string) Database::setting('membership.enable_profile_completion') !== '0'; // default on
    }

    public static function profile(int $customerId): ?array {
        self::ensureSchema();
        return Database::row(
            "SELECT * FROM membership_profiles WHERE tenant_id = ? AND customer_id = ?",
            [current_tenant_id(), $customerId]
        ) ?: null;
    }

    /** Ensure a wallet row exists; returns it. */
    public static function ensureWallet(int $customerId): array {
        self::ensureSchema();
        $tid = current_tenant_id();
        $row = Database::row(
            "SELECT * FROM membership_wallet WHERE tenant_id = ? AND customer_id = ?",
            [$tid, $customerId]
        );
        if ($row) return $row;
        try {
            Database::insert('membership_wallet', [
                'tenant_id'   => $tid,
                'customer_id' => $customerId,
                'currency'    => self::currency(),
            ]);
        } catch (\Throwable $e) {
            slate_log('Membership ensureWallet insert: ' . $e->getMessage(), 'warning');
        }
        return Database::row(
            "SELECT * FROM membership_wallet WHERE tenant_id = ? AND customer_id = ?",
            [$tid, $customerId]
        ) ?: [];
    }

    /**
     * Apply a signed delta to a member's wallet and write a ledger row.
     * Returns the new balance in cents. $type ∈ purchase|refund|adjustment|
     * failed|topup.
     */
    public static function walletAdjust(int $customerId, int $deltaCents, string $type, string $description = '', ?string $ref = null): int {
        $wallet = self::ensureWallet($customerId);
        $tid    = current_tenant_id();
        $newBal = (int) ($wallet['balance_cents'] ?? 0) + $deltaCents;

        Database::update('membership_wallet',
            ['balance_cents' => $newBal],
            'tenant_id = ? AND customer_id = ?', [$tid, $customerId]);

        Database::insert('membership_wallet_txns', [
            'tenant_id'           => $tid,
            'customer_id'         => $customerId,
            'delta_cents'         => $deltaCents,
            'balance_after_cents' => $newBal,
            'type'                => in_array($type, ['purchase','refund','adjustment','failed','topup'], true) ? $type : 'adjustment',
            'description'         => mb_substr($description, 0, 255),
            'ref'                 => $ref !== null ? mb_substr($ref, 0, 120) : null,
        ]);
        return $newBal;
    }

    public static function walletTxns(int $customerId, int $limit = 50): array {
        self::ensureSchema();
        return Database::rows(
            "SELECT * FROM membership_wallet_txns
              WHERE tenant_id = ? AND customer_id = ?
           ORDER BY id DESC LIMIT " . max(1, min(200, $limit)),
            [current_tenant_id(), $customerId]
        );
    }

    // ── Subscriptions: reads ─────────────────────────────────────────────

    public static function subscription(int $id): ?array {
        self::ensureSchema();
        return Database::row(
            "SELECT s.*, p.name AS plan_name, p.name_fr AS plan_name_fr, p.plan_type, p.duration_days, p.grace_days
               FROM membership_subscriptions s
               JOIN membership_plans p ON p.id = s.plan_id
              WHERE s.id = ? AND s.tenant_id = ?",
            [$id, current_tenant_id()]
        ) ?: null;
    }

    public static function subscriptionsForCustomer(int $customerId, int $limit = 50): array {
        self::ensureSchema();
        return Database::rows(
            "SELECT s.*, p.name AS plan_name, p.name_fr AS plan_name_fr, p.plan_type
               FROM membership_subscriptions s
               JOIN membership_plans p ON p.id = s.plan_id
              WHERE s.tenant_id = ? AND s.customer_id = ?
           ORDER BY s.id DESC LIMIT " . max(1, min(200, $limit)),
            [current_tenant_id(), $customerId]
        );
    }

    // ── Subscriptions: term math ─────────────────────────────────────────

    /**
     * Compute [starts_at, expires_at, grace_until] for a plan term starting at
     * $startTs (defaults to now). Returns Y-m-d H:i:s strings.
     *
     * "Now" is read from the database, not from PHP. Everything that decides
     * whether a term is still live compares these columns against MySQL NOW()
     * (see Membership.php and membership/admin/index.php), and PHP runs UTC on
     * this host while MySQL runs SYSTEM. Measuring the term on PHP's clock made
     * every subscription outlive its own expiry by that offset — four hours of
     * unpaid access per term, silently, on every plan.
     */
    public static function termDates(array $plan, ?int $startTs = null): array {
        $startTs  = $startTs ?? strtotime((string)\Database::value('SELECT NOW()'));
        $duration = max(1, (int)($plan['duration_days'] ?? 365));
        $grace    = max(0, (int)($plan['grace_days'] ?? 0));
        $expires  = $startTs + $duration * 86400;
        $grcUntil = $expires + $grace * 86400;
        $fmt = 'Y-m-d H:i:s';
        return [date($fmt, $startTs), date($fmt, $expires), date($fmt, $grcUntil)];
    }

    // ── Purchase (online, via Stripe) ────────────────────────────────────

    /**
     * Begin an online purchase of a plan for a customer. Creates a pending
     * subscription, opens a Stripe Checkout session, and returns its URL.
     *
     * Free plans (price 0) are activated immediately with no Stripe round-trip.
     *
     * Returns ['ok'=>true,'url'=>…]  or  ['ok'=>true,'free'=>true,'sub_id'=>…]
     *      or ['ok'=>false,'error'=>…].
     */
    public static function purchase(int $customerId, int $planId, bool $addInsurance = false): array {
        self::ensureSchema();
        $tid  = current_tenant_id();
        $plan = self::plan($planId);
        if (!$plan || empty($plan['is_active'])) {
            return ['ok' => false, 'error' => __('mb_err_plan_not_available', 'Plan not available.')];
        }

        // Insurance add-on (per-plan mode × global fee).
        [$insIncluded, $insFee] = self::resolveInsurance($plan, $addInsurance);
        $planPrice = (int)$plan['price_cents'];
        $total     = $planPrice + ($insIncluded ? $insFee : 0);

        $subOpts = [
            'insurance_included'  => $insIncluded ? 1 : 0,
            'insurance_fee_cents' => $insIncluded ? $insFee : 0,
        ];

        // Nothing to charge (free plan + no insurance fee) — activate at once.
        if ($total <= 0) {
            $subId = self::createSubscription($customerId, $plan, $subOpts + ['activation' => 'manual', 'amount_cents' => 0]);
            if ($subId) self::activateSubscription($subId);
            return ['ok' => true, 'free' => true, 'sub_id' => $subId];
        }

        if (!class_exists('StripePaymentAPI') || !StripePaymentAPI::isConfigured()) {
            return ['ok' => false, 'error' => __('mb_err_online_payment_is_not_available_right_now', 'Online payment is not available right now.')];
        }

        // Pending subscription — activated by the webhook on payment success.
        $subId = self::createSubscription($customerId, $plan, $subOpts + [
            'status'       => 'pending',
            'activation'   => 'online',
            'amount_cents' => $total,
        ]);
        if (!$subId) return ['ok' => false, 'error' => __('mb_err_could_not_start_purchase', 'Could not start purchase.')];

        $cust     = self::customerRow($customerId);
        $currency = strtolower((string)($plan['currency'] ?? self::currency()));
        $base     = SLATE_URL . '/member';

        $lineItems = [[
            'name'         => self::planName($plan),
            'amount_cents' => $planPrice,
            'description'  => self::planTypes()[$plan['plan_type']] ?? '',
        ]];
        if ($insIncluded && $insFee > 0) {
            $lineItems[] = [
                'name'         => __('membership_insurance_line', 'Insurance'),
                'amount_cents' => $insFee,
            ];
        }

        try {
            $sess = StripePaymentAPI::createCheckout(
                $lineItems,
                [
                    'currency'       => $currency,
                    'customer_email' => (string)($cust['email'] ?? ''),
                    'success_url'    => $base . '?view=return&sub=' . $subId . '&session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url'     => $base . '?view=plans&cancelled=1',
                    'metadata'       => [
                        'source_plugin'     => 'membership',
                        'membership_sub_id' => (string)$subId,
                        'customer_id'       => (string)$customerId,
                    ],
                ]
            );
            Database::update('membership_subscriptions',
                ['stripe_session_id' => $sess['session_id']],
                'id = ? AND tenant_id = ?', [$subId, $tid]);
            return ['ok' => true, 'url' => $sess['url'], 'sub_id' => $subId];
        } catch (\Throwable $e) {
            slate_log('Membership: purchase checkout failed: ' . $e->getMessage(), 'error');
            return ['ok' => false, 'error' => __('mb_err_could_not_start_payment', 'Could not start payment.')];
        }
    }

    /** Insert a subscription row (no activation). Returns its id or null. */
    private static function createSubscription(int $customerId, array $plan, array $opts = []): ?int {
        try {
            return Database::insert('membership_subscriptions', [
                'tenant_id'           => current_tenant_id(),
                'customer_id'         => $customerId,
                'plan_id'             => (int)$plan['id'],
                'status'              => (string)($opts['status'] ?? 'pending'),
                'amount_cents'        => (int)($opts['amount_cents'] ?? $plan['price_cents'] ?? 0),
                'currency'            => strtoupper((string)($plan['currency'] ?? self::currency())),
                'insurance_included'  => !empty($opts['insurance_included']) ? 1 : 0,
                'insurance_fee_cents' => (int)($opts['insurance_fee_cents'] ?? 0),
                'activation'          => (string)($opts['activation'] ?? 'online'),
            ]);
        } catch (\Throwable $e) {
            slate_log('Membership: createSubscription failed: ' . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Activate a subscription: stamp the term dates, set status='active', and
     * attach the charge. Idempotent — re-running on an already-active row is a
     * no-op. Sends the confirmation email + logs a wallet ledger entry.
     */
    public static function activateSubscription(int $subId, array $opts = []): bool {
        self::ensureSchema();
        $tid = current_tenant_id();
        $sub = self::subscription($subId);
        if (!$sub) return false;
        if (($sub['status'] ?? '') === 'active' && !empty($sub['starts_at'])) {
            return true; // already activated — webhook retry / double click
        }

        $plan = self::plan((int)$sub['plan_id']);
        if (!$plan) return false;
        [$starts, $expires, $grace] = self::termDates($plan);

        $fields = [
            'status'      => 'active',
            'starts_at'   => $starts,
            'expires_at'  => $expires,
            'grace_until' => $grace,
        ];
        if (isset($opts['charge_id']))    $fields['charge_id']    = (int)$opts['charge_id'];
        if (isset($opts['amount_cents'])) $fields['amount_cents'] = (int)$opts['amount_cents'];

        Database::update('membership_subscriptions', $fields, 'id = ? AND tenant_id = ?', [$subId, $tid]);

        // Renewal: superseding any other still-active term of the SAME plan
        // for this customer. Without this, buying the same plan again (e.g.
        // after using up the session quota) stacked a second "Active" row —
        // two duplicate cards on the dashboard, and quota usage kept
        // accumulating across both terms instead of resetting.
        Database::update(
            'membership_subscriptions',
            ['status' => 'expired'],
            'tenant_id = ? AND customer_id = ? AND plan_id = ? AND status = ? AND id != ?',
            [$tid, (int)$sub['customer_id'], (int)$sub['plan_id'], 'active', $subId]
        );

        AuditLog::record('membership.activated', (string)$subId, [
            'customer_id' => (int)$sub['customer_id'],
            'plan_id'     => (int)$sub['plan_id'],
            'activation'  => $sub['activation'],
        ]);

        // Unified ledger entry (informational — card purchases don't move the
        // stored-value balance; delta 0).
        self::walletAdjust(
            (int)$sub['customer_id'], 0, 'purchase',
            self::planName($plan) . ' — ' . self::money((int)($fields['amount_cents'] ?? $sub['amount_cents']), $sub['currency']),
            'sub#' . $subId
        );

        try { self::sendPurchaseEmail($subId); } catch (\Throwable $e) {
            slate_log('Membership: purchase email failed: ' . $e->getMessage(), 'warning');
        }
        // Only a member's own online signup needs the admin's attention here —
        // an admin's own manual activation doesn't need to notify the admin
        // who just did it.
        if (($sub['activation'] ?? '') === 'online' && class_exists('Notifications')) {
            $cust = self::customerRow((int)$sub['customer_id']);
            Notifications::add(__('membership_notif_new_member', 'New member') . ' · ' . ($cust['name'] ?? __('membership_notif_a_customer', 'A customer')), [
                'body' => sprintf(
                    __('membership_notif_new_member_body', '%s signed up for %s'),
                    ($cust['name'] ?? __('membership_notif_a_customer', 'A customer')),
                    (self::planName(['name' => $sub['plan_name'] ?? '', 'name_fr' => $sub['plan_name_fr'] ?? '']) ?: __('membership_notif_a_plan', 'a plan'))
                ),
                'url'  => function_exists('plugin_url') ? plugin_url('membership', 'admin/members.php') . '?id=' . (int)$sub['customer_id'] : '',
                'icon' => 'user-plus',
            ]);
        }
        return true;
    }

    /**
     * Admin offline/manual activation (cash / in-person). Creates an active
     * subscription immediately with activation='manual'.
     * Returns the new subscription id, or null.
     */
    public static function manualActivate(int $customerId, int $planId, string $note = '', bool $addInsurance = false): ?int {
        self::ensureSchema();
        $plan = self::plan($planId);
        if (!$plan) return null;

        [$insIncluded, $insFee] = self::resolveInsurance($plan, $addInsurance);
        $total = (int)$plan['price_cents'] + ($insIncluded ? $insFee : 0);

        $subId = self::createSubscription($customerId, $plan, [
            'status'              => 'pending',
            'activation'          => 'manual',
            'amount_cents'        => $total,
            'insurance_included'  => $insIncluded ? 1 : 0,
            'insurance_fee_cents' => $insIncluded ? $insFee : 0,
        ]);
        if (!$subId) return null;

        self::activateSubscription($subId, ['amount_cents' => $total]);
        AuditLog::record('membership.manual_activated', (string)$subId, [
            'customer_id' => $customerId, 'plan_id' => $planId, 'note' => mb_substr($note, 0, 255),
            'insurance'   => $insIncluded ? 1 : 0,
        ]);
        return $subId;
    }

    /**
     * Cancel a subscription. $byMember distinguishes a member self-cancel from
     * an admin cancel for the audit trail. Returns true on success.
     */
    public static function cancelSubscription(int $subId, bool $byMember = false): bool {
        self::ensureSchema();
        $tid = current_tenant_id();
        $sub = self::subscription($subId);
        if (!$sub) return false;
        if (in_array($sub['status'], ['cancelled', 'expired'], true)) return true;

        Database::update('membership_subscriptions',
            ['status' => 'cancelled', 'cancelled_at' => slate_db_now()],
            'id = ? AND tenant_id = ?', [$subId, $tid]);
        AuditLog::record($byMember ? 'membership.self_cancelled' : 'membership.cancelled', (string)$subId, [
            'customer_id' => (int)$sub['customer_id'],
        ]);
        try { self::sendCancelEmail($subId); } catch (\Throwable $e) {
            slate_log('Membership: cancel email failed: ' . $e->getMessage(), 'warning');
        }
        if ($byMember && class_exists('Notifications')) {
            $cust = self::customerRow((int)$sub['customer_id']);
            Notifications::add(__('membership_notif_cancelled', 'Membership cancelled by member') . ' · ' . ($cust['name'] ?? __('membership_notif_a_customer', 'A customer')), [
                'body' => sprintf(
                    __('membership_notif_cancelled_body', '%s cancelled %s'),
                    ($cust['name'] ?? __('membership_notif_a_customer', 'A customer')),
                    (self::planName(['name' => $sub['plan_name'] ?? '', 'name_fr' => $sub['plan_name_fr'] ?? '']) ?: __('membership_notif_their_plan', 'their plan'))
                ),
                'url'  => function_exists('plugin_url') ? plugin_url('membership', 'admin/members.php') . '?id=' . (int)$sub['customer_id'] : '',
                'icon' => 'x-circle',
            ]);
        }
        return true;
    }

    // ── Stripe webhook entry point ───────────────────────────────────────

    /**
     * Fired by the stripe-payment plugin for every webhook event. Activates a
     * pending membership when its checkout session completes. Idempotent: the
     * Stripe plugin de-dupes the charge and activateSubscription() no-ops once
     * active.
     */
    public static function handleStripeEvent(array $event): void {
        $type = (string)($event['type'] ?? '');
        $ok   = in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true);
        $failed = in_array($type, ['checkout.session.async_payment_failed', 'payment_intent.payment_failed'], true);
        if (!$ok && !$failed) return;

        $obj  = $event['data']['object'] ?? [];
        if (!is_array($obj)) return;
        $meta = is_array($obj['metadata'] ?? null) ? $obj['metadata'] : [];
        if (($meta['source_plugin'] ?? '') !== 'membership') return;
        $subId = (int)($meta['membership_sub_id'] ?? 0);
        if ($subId <= 0) return;

        $sub = self::subscription($subId);
        if (!$sub) return;

        if ($failed) {
            self::walletAdjust((int)$sub['customer_id'], 0, 'failed',
                'Payment failed — ' . self::planName($sub), 'sub#' . $subId);
            AuditLog::record('membership.payment_failed', (string)$subId);
            if (class_exists('Notifications')) {
                $cust = self::customerRow((int)$sub['customer_id']);
                Notifications::add(__('membership_notif_payment_failed', 'Membership payment failed') . ' · ' . ($cust['name'] ?? __('membership_notif_a_customer', 'A customer')), [
                    'body' => sprintf(
                        __('membership_notif_payment_failed_body', 'Payment for %s did not go through.'),
                        (self::planName(['name' => $sub['plan_name'] ?? '', 'name_fr' => $sub['plan_name_fr'] ?? '']) ?: __('membership_notif_a_plan', 'a plan'))
                    ),
                    'url'  => function_exists('plugin_url') ? plugin_url('membership', 'admin/members.php') . '?id=' . (int)$sub['customer_id'] : '',
                    'icon' => 'alert-triangle',
                ]);
            }
            return;
        }

        $amount = (int)($obj['amount_total'] ?? $sub['amount_cents']);
        $piId   = (string)($obj['payment_intent'] ?? '');
        $sessId = (string)($obj['id'] ?? '');
        $email  = (string)($obj['customer_details']['email'] ?? ($obj['customer_email'] ?? ''));

        $chargeId = null;
        if (class_exists('StripePaymentAPI')) {
            $chargeId = StripePaymentAPI::recordCharge([
                'source_plugin'            => 'membership',
                'source_id'                => (string)$subId,
                'stripe_session_id'        => $sessId,
                'stripe_payment_intent_id' => $piId,
                'customer_email'           => $email,
                'amount_cents'             => $amount,
                'currency'                 => strtoupper((string)($obj['currency'] ?? $sub['currency'] ?? 'USD')),
                'status'                   => 'succeeded',
            ]);
        }

        self::activateSubscription($subId, [
            'charge_id'    => $chargeId,
            'amount_cents' => $amount,
        ]);
    }

    // ── Emails ───────────────────────────────────────────────────────────

    private static function customerRow(int $customerId): array {
        return Database::row(
            "SELECT id, email, name FROM customers WHERE id = ? AND tenant_id = ?",
            [$customerId, current_tenant_id()]
        ) ?: [];
    }

    private static function sendPurchaseEmail(int $subId): void {
        if (!class_exists('Mailer')) return;
        $sub  = self::subscription($subId);
        if (!$sub) return;
        $cust = self::customerRow((int)$sub['customer_id']);
        if (empty($cust['email'])) return;

        $site    = Database::setting('site_name') ?: 'Kohevo';
        $name    = self::planName($sub);
        $expires = !empty($sub['expires_at']) ? slate_format_date($sub['expires_at']) : '—';
        $subject = sprintf('%s — %s', $site, __('membership_email_active_subject', 'Your membership is active'));
        $body = \Slate\Services\Notifications\EmailTemplate::shell(
            \Slate\Services\Notifications\EmailTemplate::heading(__('membership_email_active_subject', 'Your membership is active'))
          . \Slate\Services\Notifications\EmailTemplate::greeting((string)($cust['name'] ?? ''))
          . \Slate\Services\Notifications\EmailTemplate::paragraph(e(__('membership_email_active_body', 'Your membership is now active.')), '0 0 4px')
          . \Slate\Services\Notifications\EmailTemplate::infoCard([
                [__('membership_plan', 'Plan'), e($name)],
                [__('membership_expires', 'Expires'), e($expires)],
                [__('membership_price', 'Price'), e(self::money((int)$sub['amount_cents'], $sub['currency']))],
            ])
          . (defined('SLATE_URL') ? \Slate\Services\Notifications\EmailTemplate::button(SLATE_URL . '/member', __('membership_email_open_portal', 'Open my member area')) : ''),
            __('membership_email_active_body', 'Your membership is now active.')
        );
        Mailer::send((string)$cust['email'], $subject, $body, (string)($cust['name'] ?? ''));
    }

    private static function sendCancelEmail(int $subId): void {
        if (!class_exists('Mailer')) return;
        $sub  = self::subscription($subId);
        if (!$sub) return;
        $cust = self::customerRow((int)$sub['customer_id']);
        if (empty($cust['email'])) return;

        $site    = Database::setting('site_name') ?: 'Kohevo';
        $subject = sprintf('%s — %s', $site, __('membership_email_cancel_subject', 'Your membership was cancelled'));
        $body = \Slate\Services\Notifications\EmailTemplate::shell(
            \Slate\Services\Notifications\EmailTemplate::heading(__('membership_email_cancel_subject', 'Your membership was cancelled'))
          . \Slate\Services\Notifications\EmailTemplate::greeting((string)($cust['name'] ?? ''))
          . \Slate\Services\Notifications\EmailTemplate::paragraph(e(__('membership_email_cancel_body', 'Your membership has been cancelled.')), '0 0 4px')
          . \Slate\Services\Notifications\EmailTemplate::infoCard([[__('membership_plan', 'Plan'), e(self::planName($sub))]]),
            __('membership_email_cancel_body', 'Your membership has been cancelled.')
        );
        Mailer::send((string)$cust['email'], $subject, $body, (string)($cust['name'] ?? ''));
    }

    // ── Member dashboard data (reads the Booking plugin; all guarded) ─────

    private static function bookingInstalled(): bool {
        static $has = null;
        if ($has !== null) return $has;
        try {
            $has = (int) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_appointments'") > 0;
        } catch (\Throwable $e) { $has = false; }
        return $has;
    }

    /** A friendly member number derived from the QR token. */
    public static function memberNumber(int $customerId): string {
        $p   = self::profile($customerId);
        $tok = (string)($p['qr_token'] ?? '');
        $tail = $tok !== '' ? mb_strtoupper(mb_substr($tok, 0, 8)) : str_pad((string)$customerId, 6, '0', STR_PAD_LEFT);
        return 'MBR-' . $tail;
    }

    /** Attendance counters: total attended, this month, missed (no-show). */
    public static function sessionStats(int $customerId): array {
        $out = ['total' => 0, 'month' => 0, 'missed' => 0];
        if (!self::bookingInstalled()) return $out;
        $tid = current_tenant_id();
        try {
            $out['total'] = (int) Database::value(
                "SELECT COUNT(*) FROM booking_appointments WHERE tenant_id=? AND customer_id=? AND status IN('confirmed','completed')", [$tid, $customerId]);
            $out['month'] = (int) Database::value(
                "SELECT COUNT(*) FROM booking_appointments WHERE tenant_id=? AND customer_id=? AND status IN('confirmed','completed') AND YEAR(starts_at)=YEAR(NOW()) AND MONTH(starts_at)=MONTH(NOW())", [$tid, $customerId]);
            $out['missed'] = (int) Database::value(
                "SELECT COUNT(*) FROM booking_appointments WHERE tenant_id=? AND customer_id=? AND status='no_show'", [$tid, $customerId]);
        } catch (\Throwable $e) { /* leave zeros */ }
        return $out;
    }

    /** Recent attendance rows (past + imminent), newest first. */
    public static function recentAttendance(int $customerId, int $limit = 6): array {
        if (!self::bookingInstalled()) return [];
        try {
            return Database::rows(
                "SELECT a.starts_at, a.status, s.name AS service_name
                   FROM booking_appointments a
                   LEFT JOIN booking_services s ON s.id = a.service_id
                  WHERE a.tenant_id = ? AND a.customer_id = ?
               ORDER BY a.starts_at DESC LIMIT " . max(1, min(20, $limit)),
                [current_tenant_id(), $customerId]);
        } catch (\Throwable $e) { return []; }
    }

    /** Attended-session counts grouped by course category (for the donut). */
    public static function sessionBreakdown(int $customerId): array {
        if (!self::bookingInstalled()) return [];
        try {
            return Database::rows(
                "SELECT COALESCE(c.name, s.name, 'Other') AS label, COUNT(*) AS cnt
                   FROM booking_appointments a
                   LEFT JOIN booking_services   s ON s.id = a.service_id
                   LEFT JOIN booking_categories c ON c.id = s.category_id
                  WHERE a.tenant_id = ? AND a.customer_id = ? AND a.status IN('confirmed','completed')
               GROUP BY label ORDER BY cnt DESC LIMIT 6",
                [current_tenant_id(), $customerId]);
        } catch (\Throwable $e) { return []; }
    }

    /** Attended sessions per week-of-month for the current month (1..5). */
    public static function weeklyActivity(int $customerId): array {
        $weeks = [1=>0, 2=>0, 3=>0, 4=>0, 5=>0];
        if (!self::bookingInstalled()) return $weeks;
        try {
            $rows = Database::rows(
                "SELECT FLOOR((DAYOFMONTH(starts_at)-1)/7)+1 AS wk, COUNT(*) AS cnt
                   FROM booking_appointments
                  WHERE tenant_id = ? AND customer_id = ? AND status IN('confirmed','completed')
                    AND YEAR(starts_at)=YEAR(NOW()) AND MONTH(starts_at)=MONTH(NOW())
               GROUP BY wk", [current_tenant_id(), $customerId]);
            foreach ($rows as $r) { $w = (int)$r['wk']; if ($w >= 1 && $w <= 5) $weeks[$w] = (int)$r['cnt']; }
        } catch (\Throwable $e) {}
        return $weeks;
    }

    /** Courses (booking services) with the member's lock/enrolled state. */
    public static function courseAccess(int $customerId): array {
        if (!self::bookingInstalled()) return [];
        $tid = current_tenant_id();
        try {
            $svcs = Database::rows("SELECT id, name FROM booking_services WHERE tenant_id=? AND is_active=1 ORDER BY name LIMIT 12", [$tid]);
        } catch (\Throwable $e) { return []; }
        $insReq = array_filter(array_map('intval', explode(',', (string) Database::setting('membership.insurance_required_services'))));
        $hasIns = self::hasInsurance($customerId);
        $out = [];
        foreach ($svcs as $s) {
            $needsIns = in_array((int)$s['id'], $insReq, true);
            $used = 0;
            try {
                $used = (int) Database::value(
                    "SELECT COUNT(*) FROM booking_appointments WHERE tenant_id=? AND customer_id=? AND service_id=? AND status IN('confirmed','completed')",
                    [$tid, $customerId, (int)$s['id']]);
            } catch (\Throwable $e) {}
            $out[] = [
                'id'    => (int)$s['id'],
                'name'  => (string)$s['name'],
                'locked'=> ($needsIns && !$hasIns),
                'needs_insurance' => $needsIns,
                'used'  => $used,
            ];
        }
        return $out;
    }
}
