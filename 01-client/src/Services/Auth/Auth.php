<?php
/**
 * Slate — Auth.
 *
 * Session-based auth for both admins (the `users` table) and
 * customers (the `customers` table). Granular permissions via
 * the `role_permissions` table, looked up by role_id.
 *
 * Super Admin (role_id = 1) gets everything via short-circuit in
 * Auth::can() — never query role_permissions for them. isSuperAdmin() also
 * recognizes `platform_admins` membership (Auth::isPlatformAdmin()), the
 * platform-scoped authority table introduced as a role_id-independent
 * successor — see the isSuperAdmin() docblock.
 *
 * Patterns:
 *   Auth::require()          ← redirect to /admin/login if not logged in
 *   Auth::requirePerm($k)    ← require + 403 if permission missing
 *   Auth::can($k)            ← returns bool, no side effect
 *   Auth::requireCustomer()  ← redirect to /customer/login if no customer session
 *
 * Phase 1 (customer auth):
 *   Auth::registerCustomer(...)
 *   Auth::sendCustomerVerification($customerId)
 *   Auth::verifyCustomerEmail($token)
 *   Auth::sendCustomerPasswordReset($email)
 *   Auth::resetCustomerPassword($token, $newPassword)
 *
 * All customer tokens are SHA-256 hashed, single-use, time-bounded,
 * stored in `customer_auth_tokens` (created on demand). The plaintext
 * token only ever appears in the email link.
 */

declare(strict_types=1);

namespace Slate\Services\Auth;

use Slate\Services\Auth\MfaRepository;
use Slate\Tenancy\TenantContext;

// Phase 1 A3: migrated from includes/Auth.php into Slate\Services\Auth.
// The global name `Auth` is provided by a class_alias in src/compat/aliases.php.
// Behavior is identical to the pre-move class (migrated WHOLE — no SRP split;
// see docs/09-Roadmap/a3-core-reviews/auth.md).

class Auth {
    /** Permission cache for the current request, keyed by role_id. */
    private static array $permCache = [];

    /** platform_admins membership cache for the current request, keyed by user_id. */
    private static array $platformAdminCache = [];

    /** Set true once per request after the customer auth tokens table is ensured. */
    private static bool $customerTokenSchemaChecked = false;

    /** Set true once per request after the login_attempts table is ensured. */
    private static bool $loginAttemptsSchemaChecked = false;

    /** True if the most recent attemptLogin/attemptCustomerLogin was rejected
     *  because the client is currently locked out (not a bad password). */
    private static bool $lastLoginThrottled = false;
    private static bool $lastLoginNeedsMfa = false;

    /** Reusable bcrypt hash for timing-equalisation on the no-such-user path. */
    private static ?string $dummyHash = null;

    /** Lifetimes for the issued customer tokens. */
    const VERIFY_TOKEN_TTL_SECONDS = 86400 * 3;   // 3 days
    const RESET_TOKEN_TTL_SECONDS  = 3600 * 2;    // 2 hours

    /** Defaults applied when the Security settings are blank (feature on by default). */
    const DEFAULT_MAX_LOGIN_ATTEMPTS = 10;
    const DEFAULT_LOCKOUT_MINUTES    = 15;

    // ── Session lifecycle ─────────────────────────────────────

    /**
     * Explicit session-cookie policy. Reverse proxies terminate TLS before PHP,
     * so X-Forwarded-Proto is accepted only for the secure-cookie decision;
     * forwarded host/IP headers are never trusted for authorization.
     */
    public static function sessionCookieParams(): array {
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        return [
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    public static function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            // CLI integration harnesses may have already emitted assertion output;
            // avoid header/session-ini warnings there while preserving full setup
            // for real web requests, where headers are still available.
            if (PHP_SAPI === 'cli' && headers_sent()) return;
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_set_cookie_params(self::sessionCookieParams());
            session_name('SLATE_SID');
            session_start();
            self::enforceIdleTimeout();
        }
    }

    /**
     * Idle-timeout enforcement for the `session_timeout_minutes` Security
     * setting. If a logged-in session has been idle longer than the
     * configured window, its auth state is dropped (the session itself
     * survives so CSRF tokens etc. persist for the login page).
     */
    private static function enforceIdleTimeout(): void {
        try {
            $mins = (int) \Database::setting('session_timeout_minutes');
        } catch (\Throwable $e) {
            return; // pre-install / DB down
        }
        if ($mins <= 0) return;

        $now  = time();
        $last = (int)($_SESSION['slate_last_activity'] ?? 0);
        if (!empty($_SESSION['slate_user']) || !empty($_SESSION['slate_customer'])) {
            if ($last > 0 && ($now - $last) > $mins * 60) {
                unset($_SESSION['slate_user'], $_SESSION['slate_customer']);
                self::regenerateSessionId();
            }
        }
        $_SESSION['slate_last_activity'] = $now;
    }

    // ── Admin auth ────────────────────────────────────────────

    public static function attemptLogin(string $email, string $password): bool {
        self::$lastLoginThrottled = false;
        self::$lastLoginNeedsMfa = false;
        // Same normalization attemptCustomerLogin() already applies — the
        // `users.email` column is case-insensitive collation so this never
        // changes which row the lookup below matches; it only makes the
        // identifier used for login_attempts consistent regardless of the
        // casing/whitespace this particular request happened to submit.
        $email = strtolower(trim($email));
        if (self::loginBlockedSeconds('admin') > 0) {
            self::$lastLoginThrottled = true;
            return false;
        }

        $user = \Database::row(
            "SELECT * FROM users WHERE email = ? AND tenant_id = ? AND status = 'active'",
            [$email, current_tenant_id()]
        );
        if (!$user) {
            self::dummyVerify($password);   // equalise timing vs. the real path
            self::recordLoginFailure('admin', $email);
            return false;
        }
        if (!password_verify($password, $user['password_hash'])) {
            self::recordLoginFailure('admin', $email);
            return false;
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            \Database::update('users',
                ['password_hash' => password_hash($password, PASSWORD_DEFAULT)],
                'id = ?', [$user['id']]
            );
        }

        $mfa = new MfaRepository(new TenantContext());
        $factor = $mfa->factorForUser((int) $user['id']);
        if ($factor !== null && !empty($factor['enabled_at'])) {
            self::startSession();
            self::regenerateSessionId();
            $_SESSION['slate_mfa_pending'] = ['id' => (int)$user['id'], 'email' => $user['email']];
            self::$lastLoginNeedsMfa = true;
            return false;
        }

        \Database::update('users', ['last_login_at' => slate_db_now()], 'id = ?', [$user['id']]);

        self::startSession();
        $previousSessionId = session_id();
        self::regenerateSessionId();
        $_SESSION['slate_user'] = [
            'id'       => (int)$user['id'],
            'email'    => $user['email'],
            'name'     => $user['name'],
            'role_id'  => (int)$user['role_id'],
            'tenant_id'=> (int)$user['tenant_id'],
        ];
        self::recordAdminSession((int)$user['id'], $previousSessionId);

        self::clearLoginFailures('admin', $user['email']);
        \Hook::doAction('user_logged_in', (int)$user['id']);
        return true;
    }

    public static function logout(): void {
        self::startSession();
        $uid = self::userId();
        if ($uid !== null) (new SessionRepository(new TenantContext()))->revokeBySession($uid, session_id());
        unset($_SESSION['slate_user']);
        self::regenerateSessionId();
        if ($uid !== null) \Hook::doAction('user_logged_out', $uid);
    }

    public static function listSessions(): array {
        $uid = self::userId();
        return $uid === null ? [] : (new SessionRepository(new TenantContext()))->activeForUser($uid);
    }

    public static function revokeSession(int $sessionId): bool {
        $uid = self::userId();
        if ($uid === null) return false;
        return (new SessionRepository(new TenantContext()))->revokeById($uid, $sessionId) === 1;
    }

    public static function revokeOtherSessions(): int {
        $uid = self::userId();
        return $uid === null ? 0 : (new SessionRepository(new TenantContext()))->revokeOthers($uid, session_id());
    }

    private static function recordAdminSession(int $userId, string $previousSessionId = ''): void {
        try {
            $repo = new SessionRepository(new TenantContext());
            if ($previousSessionId !== '') {
                $repo->rotate($userId, $previousSessionId, session_id(), 'Admin browser',
                    (string)($_SERVER['REMOTE_ADDR'] ?? ''), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
            } else {
                $repo->register(
                $userId, session_id(), 'Admin browser',
                (string)($_SERVER['REMOTE_ADDR'] ?? ''), (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
                );
            }
        } catch (\Throwable $e) { /* registry failure must not block login */ }
    }

    private static function regenerateSessionId(): void {
        if (!headers_sent()) session_regenerate_id(true);
    }

    public static function check(): bool {
        self::startSession();
        $user = $_SESSION['slate_user'] ?? null;
        if (!is_array($user) || empty($user['id'])) return false;
        try {
            // Phase 1E B2 — pin session-row lookup to the SESSION'S OWN
            // recorded home tenant (set at login, never client-controlled),
            // not to whatever current_tenant_id() would otherwise resolve
            // to. Without this, activating the platform-admin tenant-switch
            // override ($_SESSION['slate_override_tenant']) breaks session
            // validation on the very next request: SessionRepository's
            // admin_sessions lookup is tenant-scoped via Repository::query(),
            // which reads current_tenant_id() — and once the override is
            // active that resolves to the TARGET tenant, not the tenant the
            // admin_sessions row was actually registered under (the admin's
            // own home tenant), so validateAndTouch() would find no row and
            // silently log the platform admin out mid-switch. A session's
            // identity is tied to where it was created, independent of
            // whatever tenant context it's currently viewing — runAs() (the
            // same primitive current_tenant_id()'s own override-authorization
            // branch already uses) makes that explicit rather than implicit.
            $homeTenant = (int)($user['tenant_id'] ?? 0);
            $tenants    = new TenantContext();
            $valid = $homeTenant > 0
                ? $tenants->runAs($homeTenant, fn() => (new SessionRepository($tenants))->validateAndTouch((int)$user['id'], session_id()))
                : (new SessionRepository($tenants))->validateAndTouch((int)$user['id'], session_id());
            if (!$valid) {
                unset($_SESSION['slate_user']);
                return false;
            }
        } catch (\Throwable $e) {
            // Fail closed: an unvalidated admin session must not remain usable.
            unset($_SESSION['slate_user']);
            return false;
        }
        return true;
    }

    public static function user(): ?array {
        return self::check() ? $_SESSION['slate_user'] : null;
    }

    // Both read through isset() rather than trusting the shape of $u: a
    // session serialised by an older deploy outlives the code that wrote it,
    // so a live session can be missing a key this version takes for granted.
    // Returning null then costs the request a 403; reading the key blind cost
    // it a PHP warning and a role of 0, which `can()` would have treated as a
    // real role.
    public static function userId(): ?int {
        $u = self::user();
        return isset($u['id']) ? (int)$u['id'] : null;
    }

    public static function roleId(): ?int {
        $u = self::user();
        return isset($u['role_id']) ? (int)$u['role_id'] : null;
    }

    /**
     * True if the current admin has platform-wide authority (bypasses every
     * tenant permission check — see can()'s short-circuit).
     *
     * LEGACY COMPATIBILITY: `role_id === 1` identifies the single, schema-
     * seeded "Super Admin" role row. `roles.id` is a global (not per-tenant)
     * primary key, so this only ever means "tenant 1's original admin" — it
     * predates any platform/tenant distinction (Phase 0E RBAC audit) and is
     * kept here only so every existing install and every existing
     * isSuperAdmin() call site keeps working unchanged during the
     * transition. Do NOT treat role_id===1 as the permanent definition of
     * platform authority, and do not add new checks against it directly —
     * `platform_admins` membership (isPlatformAdmin()) is the intended
     * long-term source of truth, decoupled from any specific role id.
     */
    public static function isSuperAdmin(): bool {
        return self::roleId() === 1 || self::isPlatformAdmin();
    }

    /**
     * True if the current admin may perform a genuinely platform-wide
     * operation — one that affects every tenant on the install (installing
     * or uninstalling plugin code, server diagnostics, opcache control,
     * viewing another tenant's data, granting the legacy Super Admin role).
     *
     * Currently identical to isSuperAdmin() (legacy role_id=1 OR
     * platform_admins membership) — kept under its own name so these call
     * sites read as platform-only BY INTENT, not merely as one more place
     * the generic isSuperAdmin() bypass happens to reach. isSuperAdmin()
     * itself is also used at ~25 other call sites purely as a bypass for the
     * CURRENT tenant's permission checks; those are a different thing and
     * must NOT be migrated to this method. See the Phase 0E/0G RBAC audit.
     */
    public static function isPlatformSuperAdmin(): bool {
        return self::isSuperAdmin();
    }

    /** require() + 403 if the current admin lacks platform-wide authority (see isPlatformSuperAdmin()). */
    public static function requirePlatformAdmin(): void {
        self::require();
        if (!self::isPlatformSuperAdmin()) {
            http_response_code(403);
            echo '<h1>403 Forbidden</h1><p>This action requires platform administrator access.</p>';
            exit;
        }
    }

    /**
     * True if the current admin is a member of `platform_admins` — the
     * platform-scoped (not tenant-scoped) authority table. Fails closed
     * (false) if the table doesn't exist yet, so an install that hasn't
     * applied migration 0013_platform_admins behaves identically to before
     * this method existed.
     */
    public static function isPlatformAdmin(): bool {
        $uid = self::userId();
        if ($uid === null) return false;
        if (!array_key_exists($uid, self::$platformAdminCache)) {
            try {
                self::$platformAdminCache[$uid] = (bool) \Database::value(
                    'SELECT 1 FROM platform_admins WHERE user_id = ? LIMIT 1',
                    [$uid]
                );
            } catch (\Throwable $e) {
                self::$platformAdminCache[$uid] = false;
            }
        }
        return self::$platformAdminCache[$uid];
    }

    /** Grant platform-admin status. Idempotent — granting an existing member is a no-op update. */
    public static function grantPlatformAdmin(int $userId, ?int $grantedBy = null): void {
        \Database::query(
            "INSERT INTO platform_admins (user_id, granted_by) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE granted_by = VALUES(granted_by)",
            [$userId, $grantedBy]
        );
        unset(self::$platformAdminCache[$userId]);
    }

    /** Revoke platform-admin status. A no-op if the user wasn't a member. */
    public static function revokePlatformAdmin(int $userId): void {
        \Database::query('DELETE FROM platform_admins WHERE user_id = ?', [$userId]);
        unset(self::$platformAdminCache[$userId]);
    }

    public static function require(): void {
        if (!self::check()) {
            $next = $_SERVER['REQUEST_URI'] ?? '/admin/';
            header('Location: ' . SLATE_URL . '/admin/login.php?next=' . urlencode($next));
            exit;
        }
    }

    // ── Permissions ───────────────────────────────────────────

    public static function can(string $key): bool {
        if (!self::check()) return false;
        if (self::isSuperAdmin()) return true;

        $roleId = self::roleId();
        if ($roleId === null) return false;

        $tenantId = current_tenant_id();
        $cacheKey = $tenantId . ':' . $roleId;
        if (!isset(self::$permCache[$cacheKey])) {
            $rows = \Database::rows(
                "SELECT rp.perm_key
                   FROM role_permissions rp
                   JOIN roles r ON r.id = rp.role_id AND r.tenant_id = ?
                  WHERE rp.role_id = ? AND rp.granted = 1",
                [$tenantId, $roleId]
            );
            self::$permCache[$cacheKey] = array_fill_keys(array_column($rows, 'perm_key'), true);
        }

        return isset(self::$permCache[$cacheKey][$key]);
    }

    public static function requirePerm(string $key): void {
        self::require();
        if (!self::can($key)) {
            http_response_code(403);
            echo '<h1>403 Forbidden</h1><p>You do not have permission to access this page.</p>';
            echo '<p>Required permission: <code>' . e($key) . '</code></p>';
            exit;
        }
    }

    public static function invalidatePermCache(): void {
        self::$permCache = [];
    }

    // ── Login throttling ──────────────────────────────────────
    //
    // Brute-force protection wired to the Security settings
    // (`max_login_attempts`, `lockout_minutes`). Failures are counted
    // per client IP within the lockout window; once the cap is reached
    // the IP is blocked until `lockout_minutes` after its last attempt.
    // Keying on IP (not email) means an attacker hammering many accounts
    // from one host gets blocked, without letting an attacker lock a
    // victim out by spamming that victim's email from elsewhere.

    /** True when the last login attempt was rejected for lockout, not a bad password. */
    public static function lastLoginWasThrottled(): bool {
        return self::$lastLoginThrottled;
    }

    public static function lastLoginNeedsMfa(): bool {
        return self::$lastLoginNeedsMfa;
    }

    public static function mfaPending(): bool {
        self::startSession();
        return !empty($_SESSION['slate_mfa_pending']['id']);
    }

    /** Complete the pending privileged-user challenge using TOTP or one recovery code. */
    public static function completeMfa(string $code = '', string $recoveryCode = ''): bool {
        self::startSession();
        $pending = $_SESSION['slate_mfa_pending'] ?? null;
        if (!is_array($pending) || empty($pending['id'])) return false;
        $userId = (int) $pending['id'];
        $repo = new MfaRepository(new TenantContext());
        $factor = $repo->factorForUser($userId);
        if ($factor === null || empty($factor['enabled_at'])) return false;
        $ok = Mfa::verifyTotp((string) $factor['secret'], $code)
            || ($recoveryCode !== '' && $repo->consumeRecoveryCode($userId, $recoveryCode));
        if (!$ok) return false;
        $user = \Database::row("SELECT * FROM users WHERE id=? AND tenant_id=? AND status='active'", [$userId, current_tenant_id()]);
        if (!$user) { unset($_SESSION['slate_mfa_pending']); return false; }
        $previousSessionId = session_id();
        self::regenerateSessionId();
        $_SESSION['slate_user'] = [
            'id' => (int)$user['id'], 'email' => $user['email'], 'name' => $user['name'],
            'role_id' => (int)$user['role_id'], 'tenant_id' => (int)$user['tenant_id'],
        ];
        self::recordAdminSession($userId, $previousSessionId);
        unset($_SESSION['slate_mfa_pending']);
        \Database::update('users', ['last_login_at' => slate_db_now()], 'id = ?', [$userId]);
        // Same reset-scoping as attemptLogin() — this is the MFA-gated
        // completion of the same login, not a separate mechanism; leaving
        // this call unscoped would keep the reset-bypass open for every
        // MFA-enrolled (typically higher-privilege) admin account.
        self::clearLoginFailures('admin', $user['email']);
        \Hook::doAction('user_logged_in', $userId);
        return true;
    }

    /** [maxAttempts, lockoutMinutes]; maxAttempts <= 0 means throttling is disabled. */
    private static function throttleConfig(): array {
        $maxRaw  = \Database::setting('max_login_attempts');
        $lockRaw = \Database::setting('lockout_minutes');
        $max  = ($maxRaw  === null || $maxRaw  === '') ? self::DEFAULT_MAX_LOGIN_ATTEMPTS : (int)$maxRaw;
        $lock = ($lockRaw === null || $lockRaw === '') ? self::DEFAULT_LOCKOUT_MINUTES    : (int)$lockRaw;
        if ($lock < 1) $lock = self::DEFAULT_LOCKOUT_MINUTES;
        return [$max, $lock];
    }

    private static function clientIp(): string {
        // REMOTE_ADDR only — never trust X-Forwarded-* (spoofable).
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** A real bcrypt verify on a throwaway hash, to flatten the timing
     *  difference between "no such user" and "wrong password". */
    private static function dummyVerify(string $password): void {
        if (self::$dummyHash === null) {
            self::$dummyHash = password_hash('slate-throttle-timing-equaliser', PASSWORD_DEFAULT);
        }
        password_verify($password, self::$dummyHash);
    }

    /**
     * Seconds the current client IP must wait before another login in
     * this scope ('admin'|'customer'). 0 = not blocked / disabled.
     */
    public static function loginBlockedSeconds(string $scope): int {
        [$max, $lockMin] = self::throttleConfig();
        if ($max <= 0) return 0;
        self::ensureLoginAttemptsSchema();

        // Every timestamp here is produced AND compared by MySQL. attempted_at
        // is written by the column default (CURRENT_TIMESTAMP), so building the
        // window from PHP's clock silently breaks the whole throttle whenever
        // the two disagree — and they do here: PHP runs UTC, MySQL runs SYSTEM.
        // A PHP-built `$since` sat 4 hours ahead of every stored row, so the
        // count was always 0 and lockout never engaged. Keep the arithmetic in
        // SQL and the skew cannot matter.
        $row = \Database::row(
            "SELECT COUNT(*) AS c,
                    TIMESTAMPDIFF(SECOND, NOW(), MAX(attempted_at) + INTERVAL ? MINUTE) AS remaining
               FROM login_attempts
              WHERE scope = ? AND ip = ? AND attempted_at > NOW() - INTERVAL ? MINUTE",
            [$lockMin, $scope, self::clientIp(), $lockMin]
        );
        if (!$row || (int)$row['c'] < $max) return 0;

        $remaining = (int)$row['remaining'];
        return $remaining > 0 ? $remaining : 0;
    }

    /**
     * Same trim()+lowercase normalization attemptCustomerLogin() already
     * applies to its own $email before using it — reused here so every
     * identifier written to or matched against login_attempts is
     * consistent, regardless of which scope or which caller's original
     * casing/whitespace produced it.
     */
    private static function normalizeIdentifier(string $identifier): string {
        return mb_strtolower(trim($identifier), 'UTF-8');
    }

    public static function recordLoginFailure(string $scope, string $identifier): void {
        [$max] = self::throttleConfig();
        if ($max <= 0) return;
        self::ensureLoginAttemptsSchema();
        try {
            \Database::insert('login_attempts', [
                'tenant_id'  => current_tenant_id(),
                'scope'      => $scope,
                'ip'         => self::clientIp(),
                'identifier' => mb_substr(self::normalizeIdentifier($identifier), 0, 190),
            ]);
        } catch (\Throwable $e) {
            // Throttling must never block a login on infra error.
        }
    }

    /**
     * Clears recorded failures for this scope+IP after a successful login.
     *
     * $identifier, when given, narrows the clear to the account that just
     * authenticated: `scope + ip + identifier`, never just `scope + ip`.
     * Without it, a successful login anywhere on a shared IP (including an
     * attacker's own throwaway account) wiped out every OTHER account's
     * accumulated failures on that IP too — the account dimension was
     * recorded (see recordLoginFailure()) but never used to scope the
     * reset, so the shared bucket could be cleared by anyone who could log
     * into anything from that IP, letting a brute-force attempt against one
     * victim be repeated indefinitely between the attacker's own successful
     * logins. The failure COUNT/lockout-check query (loginBlockedSeconds())
     * is deliberately left keyed by scope+ip only — narrowing counting to
     * scope+ip+account as well would let an attacker spray many distinct
     * target identifiers from one IP without ever tripping the existing
     * IP-wide lock, which is the opposite of what this fix is for.
     *
     * $identifier defaults to '' (clear everything for this scope+ip, the
     * historical behavior) only to keep existing test/utility call sites
     * that intentionally want a full reset working unchanged; every
     * production login-success call site now always passes its own
     * account's identifier.
     */
    public static function clearLoginFailures(string $scope, string $identifier = ''): void {
        if (!self::$loginAttemptsSchemaChecked) self::ensureLoginAttemptsSchema();
        try {
            if ($identifier === '') {
                \Database::query(
                    "DELETE FROM login_attempts WHERE scope = ? AND ip = ?",
                    [$scope, self::clientIp()]
                );
            } else {
                // anti-drift-ignore: TENANT — login_attempts is intentionally
                // not tenant-scoped on read (see tests/isolation.php and the
                // sibling scope+ip-only DELETE just above, already baselined):
                // brute-force throttling is meant to apply per IP across the
                // whole install, not reset itself at a tenant boundary. This
                // query adds identifier-scoping for the reset-bypass fix; it
                // does not change that pre-existing, documented characteristic.
                \Database::query(
                    "DELETE FROM login_attempts WHERE scope = ? AND ip = ? AND identifier = ?",
                    [$scope, self::clientIp(), self::normalizeIdentifier($identifier)]
                );
            }
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    private static function ensureLoginAttemptsSchema(): void {
        if (self::$loginAttemptsSchemaChecked) return;
        self::$loginAttemptsSchemaChecked = true;
        try {
            \Database::get()->exec(
                "CREATE TABLE IF NOT EXISTS `login_attempts` (
                    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
                    `scope`        VARCHAR(16)  NOT NULL,
                    `ip`           VARCHAR(45)  NOT NULL,
                    `identifier`   VARCHAR(190) NOT NULL DEFAULT '',
                    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_scope_ip` (`scope`, `ip`, `attempted_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            if (function_exists('slate_log')) {
                slate_log('Auth: failed to ensure login_attempts table: ' . $e->getMessage(), 'error');
            }
        }
    }

    public static function corePermissions(): array {
        return [
            'Users & roles' => [
                ['key' => 'users.view',     'label' => 'View users'],
                ['key' => 'users.edit',     'label' => 'Create / edit / delete users'],
                ['key' => 'roles.manage',   'label' => 'Create / edit / delete tenant roles and their permissions'],
            ],
            'Plugins' => [
                ['key' => 'plugins.manage', 'label' => 'View, install, activate, uninstall plugins'],
            ],
            'Contact forms' => [
                ['key' => 'contact.view',   'label' => 'View contact forms and submissions'],
                ['key' => 'contact.manage', 'label' => 'Create / edit / delete contact forms'],
            ],
            'Settings' => [
                ['key' => 'settings.view',  'label' => 'View settings'],
                ['key' => 'settings.edit',  'label' => 'Modify settings'],
            ],
            'Media' => [
                ['key' => 'media.view',   'label' => 'View the media library'],
                ['key' => 'media.upload', 'label' => 'Upload media (images & documents)'],
                ['key' => 'media.delete', 'label' => 'Delete media (blocked if a file is in use)'],
            ],
            'Customers' => [
                ['key' => 'customers.view', 'label' => 'View customer records'],
                ['key' => 'customers.edit', 'label' => 'Create / edit customer records'],
            ],
            'Audit & logs' => [
                ['key' => 'audit.view',     'label' => 'View the audit log'],
                ['key' => 'audit.manage',   'label' => 'Clear the audit log'],
            ],
        ];
    }

    public static function knownPermissions(): array {
        $groups = self::corePermissions();
        $rows = class_exists('Database')
              ? \Database::rows("SELECT slug, name, manifest_json FROM plugins WHERE status IN ('active','inactive','installed')")
              : [];
        foreach ($rows as $row) {
            $manifest = json_decode($row['manifest_json'] ?? '{}', true) ?: [];
            $perms = $manifest['permissions'] ?? [];
            if (!is_array($perms) || empty($perms)) continue;
            $groupName = $row['name'] . ' (plugin)';
            foreach ($perms as $perm) {
                if (is_string($perm) && $perm !== '') {
                    $groups[$groupName][] = ['key' => $perm, 'label' => $perm];
                } elseif (is_array($perm) && !empty($perm['key'])) {
                    $groups[$groupName][] = [
                        'key'   => $perm['key'],
                        'label' => $perm['label'] ?? $perm['key'],
                    ];
                }
            }
        }
        return $groups;
    }

    // ── Customer auth ─────────────────────────────────────────
    //
    // Phase 2A B4 cutover: customer authentication now reads through
    // IdentityStore (the `identities` table), and every `customers` mutation is
    // DUAL-WRITTEN into the spine via ContactSeeder::syncCustomer so `customers`
    // stays a valid rollback target. Signatures, the session shape
    // ($_SESSION['slate_customer']), throttling, and timing-equalisation are
    // unchanged. id == contact_id == old customer_id (id-preservation), so every
    // caller and Auth::customerId() keep returning the same value.

    /** A per-request IdentityStore wired to the current tenant. */
    private static function identityStore(): \Slate\Services\Identity\IdentityStore {
        $tenants = new \Slate\Tenancy\TenantContext();
        return new \Slate\Services\Identity\IdentityStore(
            $tenants,
            new \Slate\Services\Identity\ContactRepository($tenants)
        );
    }

    /** Dual-write mirror: keep the spine in sync after a `customers` change.
     *  Best-effort — a mirror failure must never break the legacy write. */
    private static function syncCustomerToSpine(int $customerId): void {
        try {
            (new \Slate\Services\Identity\ContactSeeder())->syncCustomer($customerId);
        } catch (\Throwable $e) {
            slate_log('Auth: customer→spine dual-write failed for ' . $customerId . ': ' . $e->getMessage(), 'warning');
        }
    }

    public static function attemptCustomerLogin(string $email, string $password): bool {
        self::$lastLoginThrottled = false;
        if (self::loginBlockedSeconds('customer') > 0) {
            self::$lastLoginThrottled = true;
            return false;
        }

        $email = strtolower(trim($email));
        $store    = self::identityStore();
        $identity = $store->authenticate('password', $email, $password);
        if ($identity === null) {
            self::dummyVerify($password);   // equalise timing vs. the real path (incl. suspended/unknown)
            self::recordLoginFailure('customer', $email);
            return false;
        }

        $contact = $store->contactFor($identity->id);
        if ($contact === null) {                 // defensive: identity without a contact
            self::recordLoginFailure('customer', $email);
            return false;
        }

        // Dual-write last_login to the legacy table (rollback consistency).
        \Database::update('customers', ['last_login_at' => slate_db_now()], 'id = ?', [$contact->id]);

        self::startSession();
        self::regenerateSessionId();
        $_SESSION['slate_customer'] = [
            'id'        => $contact->id,
            'email'     => $contact->primaryEmail,
            'name'      => $contact->displayName,
            'tenant_id' => $contact->tenantId,
        ];

        self::clearLoginFailures('customer', $email);
        \Hook::doAction('customer_logged_in', $contact->id);
        return true;
    }

    public static function customer(): ?array {
        self::startSession();
        return $_SESSION['slate_customer'] ?? null;
    }

    public static function customerId(): ?int {
        $c = self::customer();
        return $c ? (int)$c['id'] : null;
    }

    public static function requireCustomer(): void {
        if (!self::customer()) {
            $next = $_SERVER['REQUEST_URI'] ?? '/customer/';
            header('Location: ' . SLATE_URL . '/customer/login.php?next=' . urlencode($next));
            exit;
        }
    }

    public static function logoutCustomer(): void {
        self::startSession();
        unset($_SESSION['slate_customer']);
        self::regenerateSessionId();
    }

    /**
     * Register a customer. Returns ['ok'=>true, 'customer_id'=>int]
     * on success or ['ok'=>false, 'error'=>string] on failure.
     *
     * Sends the verification email automatically. Does NOT log the
     * customer in — registration and login are deliberately separate
     * so an attacker who guesses the registration form can't
     * impersonate an existing email.
     */
    public static function registerCustomer(
        string $email,
        string $password,
        string $name = '',
        string $phone = ''
    ): array {
        $email = strtolower(trim($email));
        $name  = trim($name);
        $phone = trim($phone);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Please enter a valid email address.'];
        }
        if (strlen($password) < 8) {
            return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
        }
        if (mb_strlen($email) > 190) {
            return ['ok' => false, 'error' => 'Email is too long.'];
        }

        $tid = current_tenant_id();

        // Existing record? If a verified active customer already
        // owns this email, refuse. If it's an unverified record we
        // can re-use (resend verify rather than create a duplicate).
        $existing = \Database::row(
            "SELECT * FROM customers WHERE tenant_id = ? AND email = ?",
            [$tid, $email]
        );

        if ($existing) {
            if (!empty($existing['email_verified']) || !empty($existing['password_hash'])) {
                // Account exists. Tell the user generically — don't leak
                // whether the email is verified or has a password set.
                return ['ok' => false, 'error' => 'This email is already registered. Try logging in or resetting your password.'];
            }
            // Guest/unverified shell — promote it to a real account.
            \Database::update('customers', [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'name'          => $name !== '' ? $name : ($existing['name'] ?? null),
                'phone'         => $phone !== '' ? $phone : ($existing['phone'] ?? null),
                'status'        => 'active',
            ], 'id = ?', [$existing['id']]);
            $customerId = (int)$existing['id'];
        } else {
            $customerId = \Database::insert('customers', [
                'tenant_id'      => $tid,
                'email'          => $email,
                'password_hash'  => password_hash($password, PASSWORD_DEFAULT),
                'name'           => $name !== '' ? $name : null,
                'phone'          => $phone !== '' ? $phone : null,
                'status'         => 'active',
                'email_verified' => 0,
            ]);
        }

        self::syncCustomerToSpine($customerId);   // dual-write: mirror into contacts/identities
        \AuditLog::record('customer.registered', (string)$customerId, ['email' => $email]);
        \Hook::doAction('customer_registered', $customerId);

        $sent = self::sendCustomerVerification($customerId);
        return [
            'ok'           => true,
            'customer_id'  => $customerId,
            'email_sent'   => $sent,
        ];
    }

    /**
     * Issue and email a fresh email-verification token.
     * Returns true if the email was handed to the Mailer successfully.
     */
    public static function sendCustomerVerification(int $customerId): bool {
        $cust = \Database::row(
            "SELECT * FROM customers WHERE id = ? AND tenant_id = ?",
            [$customerId, current_tenant_id()]
        );
        if (!$cust) return false;
        if (!empty($cust['email_verified'])) return false;

        $token = self::issueCustomerToken($customerId, 'verify_email', self::VERIFY_TOKEN_TTL_SECONDS);

        $verifyUrl = SLATE_URL . '/customer/verify-email.php?token=' . urlencode($token);
        $siteName  = \Database::setting('site_name') ?: 'Kohevo';

        $subject   = __('auth_email_verify_subject', 'Confirm your email address');
        $verifyIns = __('auth_email_verify_instructions', 'Please confirm your email address by clicking the link below. The link is valid for 3 days.');
        $inner = \Slate\Services\Notifications\EmailTemplate::heading($subject)
               . \Slate\Services\Notifications\EmailTemplate::greeting((string)($cust['name'] ?? ''))
               . \Slate\Services\Notifications\EmailTemplate::paragraph(sprintf(__('auth_email_welcome_to', 'Welcome to %s.'), e($siteName)))
               . \Slate\Services\Notifications\EmailTemplate::paragraph(e($verifyIns), '0 0 4px')
               . \Slate\Services\Notifications\EmailTemplate::button($verifyUrl, __('auth_email_verify_cta', 'Confirm my email address'))
               . \Slate\Services\Notifications\EmailTemplate::hint(e(__('auth_email_link_fallback', "If the button doesn't work, copy and paste this link into your browser:")) . '<br>'
                        . '<a href="' . e($verifyUrl) . '" style="color:#8a7d61;word-break:break-all;">' . e($verifyUrl) . '</a>')
               . \Slate\Services\Notifications\EmailTemplate::paragraph(e(__('auth_email_verify_ignore', "If you didn't create an account, you can safely ignore this email.")), '22px 0 0');

        // Same on-brand template as the booking emails (logo band, card, footer + platform signature).
        $html = \Slate\Services\Notifications\EmailTemplate::shell($inner, $verifyIns);

        return (bool) \Mailer::send($cust['email'], $subject, $html, $cust['name'] ?? '');
    }

    /**
     * Verify the email-verification token. Returns customer_id on
     * success or null on invalid/expired/used token.
     */
    public static function verifyCustomerEmail(string $token): ?int {
        $customerId = self::consumeCustomerToken($token, 'verify_email');
        if ($customerId === null) return null;

        \Database::update('customers', [
            'email_verified' => 1,
        ], 'id = ? AND tenant_id = ?', [$customerId, current_tenant_id()]);
        self::syncCustomerToSpine($customerId);   // dual-write: mirror verified flag

        \AuditLog::record('customer.email_verified', (string)$customerId);
        \Hook::doAction('customer_email_verified', $customerId);
        return $customerId;
    }

    /**
     * Issue a password reset token and email the link. Returns true
     * if a reset email was sent. Always returns true to callers
     * (callers should not branch on this — we don't want to leak
     * whether an email exists in the database).
     */
    public static function sendCustomerPasswordReset(string $email): bool {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return true; // pretend ok

        $cust = \Database::row(
            "SELECT * FROM customers WHERE tenant_id = ? AND email = ? AND status = 'active'",
            [current_tenant_id(), $email]
        );
        if (!$cust) return true;
        if (empty($cust['password_hash'])) {
            // Guest record with no password — silently no-op (the
            // attacker can't tell us apart from the no-match case).
            return true;
        }

        $token = self::issueCustomerToken((int)$cust['id'], 'password_reset', self::RESET_TOKEN_TTL_SECONDS);

        $resetUrl = SLATE_URL . '/customer/reset-password.php?token=' . urlencode($token);
        $siteName = \Database::setting('site_name') ?: 'Kohevo';

        $subject  = __('auth_email_reset_subject', 'Reset your password');
        $resetIns = __('auth_email_reset_instructions', 'Click the link below to set a new password. The link is valid for 2 hours.');
        $inner = \Slate\Services\Notifications\EmailTemplate::heading($subject)
               . \Slate\Services\Notifications\EmailTemplate::greeting((string)($cust['name'] ?? ''))
               . \Slate\Services\Notifications\EmailTemplate::paragraph(sprintf(__('auth_email_reset_requested', 'You requested a password reset for your %s account.'), e($siteName)))
               . \Slate\Services\Notifications\EmailTemplate::paragraph(e($resetIns), '0 0 4px')
               . \Slate\Services\Notifications\EmailTemplate::button($resetUrl, __('auth_email_reset_cta', 'Reset my password'))
               . \Slate\Services\Notifications\EmailTemplate::hint(e(__('auth_email_link_fallback', "If the button doesn't work, copy and paste this link into your browser:")) . '<br>'
                        . '<a href="' . e($resetUrl) . '" style="color:#8a7d61;word-break:break-all;">' . e($resetUrl) . '</a>')
               . \Slate\Services\Notifications\EmailTemplate::paragraph(e(__('auth_email_reset_ignore', "If you didn't request this, you can safely ignore this email — your password won't change.")), '22px 0 0');

        // Same on-brand template as the booking emails — see sendCustomerVerification().
        $html = \Slate\Services\Notifications\EmailTemplate::shell($inner, $resetIns);

        \Mailer::send($cust['email'], $subject, $html, $cust['name'] ?? '');

        \AuditLog::record('customer.password_reset_requested', (string)$cust['id']);
        return true;
    }

    /**
     * Consume a password reset token and set a new password.
     * Returns ['ok'=>true, 'customer_id'=>int] or ['ok'=>false, 'error'=>string].
     */
    public static function resetCustomerPassword(string $token, string $newPassword): array {
        if (strlen($newPassword) < 8) {
            return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
        }
        $customerId = self::consumeCustomerToken($token, 'password_reset');
        if ($customerId === null) {
            return ['ok' => false, 'error' => 'This reset link is invalid or has expired.'];
        }

        \Database::update('customers', [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ], 'id = ? AND tenant_id = ?', [$customerId, current_tenant_id()]);
        self::syncCustomerToSpine($customerId);   // dual-write: mirror new password into identities

        // Burn any other outstanding reset tokens for this customer.
        \Database::query(
            "UPDATE customer_auth_tokens
                SET used_at = NOW()
              WHERE customer_id = ? AND purpose = 'password_reset' AND used_at IS NULL",
            [$customerId]
        );

        \AuditLog::record('customer.password_reset', (string)$customerId);
        return ['ok' => true, 'customer_id' => $customerId];
    }

    // ── Customer token helpers (internal) ─────────────────────

    /**
     * Lazily create the customer_auth_tokens table the first time
     * we need it. Cheap CREATE TABLE IF NOT EXISTS — runs once per
     * request thanks to the static flag.
     */
    private static function ensureCustomerTokenSchema(): void {
        if (self::$customerTokenSchemaChecked) return;
        self::$customerTokenSchemaChecked = true;

        try {
            \Database::get()->exec(
                "CREATE TABLE IF NOT EXISTS `customer_auth_tokens` (
                    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
                    `customer_id`  INT UNSIGNED NOT NULL,
                    `purpose`      VARCHAR(32) NOT NULL,
                    `token_hash`   CHAR(64) NOT NULL,
                    `expires_at`   DATETIME NOT NULL,
                    `used_at`      DATETIME NULL,
                    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_token_purpose`    (`token_hash`, `purpose`),
                    KEY `idx_customer_purpose` (`customer_id`, `purpose`),
                    KEY `idx_expires`          (`expires_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            if (function_exists('slate_log')) {
                slate_log('Auth: failed to ensure customer_auth_tokens table: ' . $e->getMessage(), 'error');
            }
        }
    }

    /**
     * Mint a random token, store SHA-256 of it, and return the
     * plaintext for embedding in an email link.
     */
    private static function issueCustomerToken(int $customerId, string $purpose, int $ttlSeconds): string {
        self::ensureCustomerTokenSchema();

        // Invalidate prior outstanding tokens for the same purpose,
        // so a reissued verification email supersedes the old one.
        \Database::query(
            "UPDATE customer_auth_tokens
                SET used_at = NOW()
              WHERE customer_id = ? AND purpose = ? AND used_at IS NULL",
            [$customerId, $purpose]
        );

        $plaintext = bin2hex(random_bytes(32));   // 64 hex chars
        $hash      = hash('sha256', $plaintext);  // 64 hex chars
        // consumeCustomerToken() tests this against MySQL's NOW(), so it must be
        // MySQL's clock that sets it. Built from PHP's clock it inherited the
        // UTC-vs-SYSTEM skew and every token outlived its TTL by that offset.
        $expires   = (string)\Database::value('SELECT NOW() + INTERVAL ? SECOND', [$ttlSeconds]);

        \Database::insert('customer_auth_tokens', [
            'tenant_id'   => current_tenant_id(),
            'customer_id' => $customerId,
            'purpose'     => $purpose,
            'token_hash'  => $hash,
            'expires_at'  => $expires,
        ]);

        return $plaintext;
    }

    /**
     * Validate + burn a token. Returns the customer_id if the token
     * was valid (not expired, not used, purpose matches); null otherwise.
     */
    private static function consumeCustomerToken(string $plaintext, string $purpose): ?int {
        if ($plaintext === '' || strlen($plaintext) > 128) return null;
        self::ensureCustomerTokenSchema();

        $hash = hash('sha256', $plaintext);
        $row  = \Database::row(
            "SELECT * FROM customer_auth_tokens
              WHERE token_hash = ? AND purpose = ? AND tenant_id = ?
                AND used_at IS NULL
                AND expires_at > NOW()
              LIMIT 1",
            [$hash, $purpose, current_tenant_id()]
        );
        if (!$row) return null;

        \Database::update('customer_auth_tokens',
            ['used_at' => slate_db_now()],
            'id = ?', [(int)$row['id']]
        );

        return (int)$row['customer_id'];
    }
}
