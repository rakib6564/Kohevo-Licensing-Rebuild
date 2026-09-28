<?php
/**
 * Forms — public API.
 *
 * Static methods so other plugins can reach in without instantiating
 * the Forms bootstrap class:
 *
 *   FormsAPI::getForm($slug)
 *   FormsAPI::parseFieldDsl($text)
 *   FormsAPI::renderField($field, $value, $error)
 *   FormsAPI::validateSubmission($form, $input)
 *   FormsAPI::generateRef()
 *   FormsAPI::slugify($title, $excludeId = null)
 *   FormsAPI::dispatchWebhooks($formId, $submissionId, $payload)
 *
 * Field DSL (one field per line, pipe-separated):
 *   type|name|label|required|placeholder|options
 *
 * Examples:
 *   text|full_name|Your name|required
 *   email|email|Email|required|you@example.com
 *   tel|phone|Phone
 *   textarea|message|Tell us more|required
 *   select|reason|Reason for contact||Choose…|sales,support,other
 *   radio|priority|Priority|required||low,normal,high
 *   checkbox|consent|I agree to be contacted|required
 *   number|budget|Budget (USD)|required||1000
 *   date|when|Preferred date
 *
 * Lines starting with `#` are comments. Blank lines are ignored.
 *
 * Supported types: text, email, tel, url, number, date, textarea,
 * select, radio, checkbox, file, signature.
 * Deferred to follow-up sessions: multi-step, conditional logic,
 * payment.
 */

require_once __DIR__ . '/lib/FormsSpamGuard.php';
require_once __DIR__ . '/lib/FormTemplates.php';
require_once __DIR__ . '/lib/FormDesigns.php';

class FormsAPI {

    const SUPPORTED_TYPES = [
        'text', 'email', 'tel', 'intlphone', 'url', 'number', 'date', 'time',
        'address',
        'textarea', 'select', 'radio', 'checkbox', 'checkboxes', 'range', 'rating',
        'file', 'signature', 'heading', 'hidden', 'calc', 'step', 'disclaimer',
    ];

    /**
     * Country dial codes for the international phone field.
     * `p` = input mask ('#' = digit, anything else literal — used for live
     * formatting and as the maxlength hint); `ex` = an example local number
     * shown as the placeholder when that country is selected.
     */
    public static function phoneCountries(): array {
        return [
            ['f'=>'🇺🇸','d'=>'+1','n'=>'United States','p'=>'(###) ###-####','ex'=>'(555) 123-4567','def'=>true],
            ['f'=>'🇨🇦','d'=>'+1','n'=>'Canada',       'p'=>'(###) ###-####','ex'=>'(416) 555-0182'],
            ['f'=>'🇬🇧','d'=>'+44','n'=>'United Kingdom','p'=>'#### ######','ex'=>'7700 900123'],
            ['f'=>'🇦🇺','d'=>'+61','n'=>'Australia',   'p'=>'### ### ###','ex'=>'412 345 678'],
            ['f'=>'🇳🇿','d'=>'+64','n'=>'New Zealand', 'p'=>'## ### ####','ex'=>'21 123 4567'],
            ['f'=>'🇮🇪','d'=>'+353','n'=>'Ireland',    'p'=>'## ### ####','ex'=>'85 123 4567'],
            ['f'=>'🇩🇪','d'=>'+49','n'=>'Germany',     'p'=>'### #######','ex'=>'151 1234567'],
            ['f'=>'🇫🇷','d'=>'+33','n'=>'France',      'p'=>'# ## ## ## ##','ex'=>'6 12 34 56 78'],
            ['f'=>'🇪🇸','d'=>'+34','n'=>'Spain',       'p'=>'### ### ###','ex'=>'612 345 678'],
            ['f'=>'🇮🇹','d'=>'+39','n'=>'Italy',       'p'=>'### #######','ex'=>'320 1234567'],
            ['f'=>'🇳🇱','d'=>'+31','n'=>'Netherlands', 'p'=>'## #######','ex'=>'6 12345678'],
            ['f'=>'🇸🇪','d'=>'+46','n'=>'Sweden',      'p'=>'## ### ####','ex'=>'70 123 4567'],
            ['f'=>'🇳🇴','d'=>'+47','n'=>'Norway',      'p'=>'### ## ###','ex'=>'412 34 567'],
            ['f'=>'🇩🇰','d'=>'+45','n'=>'Denmark',     'p'=>'## ## ## ##','ex'=>'20 12 34 56'],
            ['f'=>'🇨🇭','d'=>'+41','n'=>'Switzerland', 'p'=>'## ### ## ##','ex'=>'78 123 45 67'],
            ['f'=>'🇵🇹','d'=>'+351','n'=>'Portugal',   'p'=>'### ### ###','ex'=>'912 345 678'],
            ['f'=>'🇲🇽','d'=>'+52','n'=>'Mexico',      'p'=>'### ### ####','ex'=>'55 1234 5678'],
            ['f'=>'🇧🇷','d'=>'+55','n'=>'Brazil',      'p'=>'(##) #####-####','ex'=>'(11) 91234-5678'],
            ['f'=>'🇦🇷','d'=>'+54','n'=>'Argentina',   'p'=>'## ####-####','ex'=>'11 1234-5678'],
            ['f'=>'🇮🇳','d'=>'+91','n'=>'India',       'p'=>'##### #####','ex'=>'98765 43210'],
            ['f'=>'🇵🇰','d'=>'+92','n'=>'Pakistan',    'p'=>'### #######','ex'=>'300 1234567'],
            ['f'=>'🇧🇩','d'=>'+880','n'=>'Bangladesh', 'p'=>'#### ######','ex'=>'1712 345678'],
            ['f'=>'🇦🇪','d'=>'+971','n'=>'UAE',        'p'=>'## ### ####','ex'=>'50 123 4567'],
            ['f'=>'🇸🇦','d'=>'+966','n'=>'Saudi Arabia','p'=>'## ### ####','ex'=>'50 123 4567'],
            ['f'=>'🇿🇦','d'=>'+27','n'=>'South Africa','p'=>'## ### ####','ex'=>'71 123 4567'],
            ['f'=>'🇸🇬','d'=>'+65','n'=>'Singapore',   'p'=>'#### ####','ex'=>'8123 4567'],
            ['f'=>'🇯🇵','d'=>'+81','n'=>'Japan',       'p'=>'##-####-####','ex'=>'90-1234-5678'],
            ['f'=>'🇨🇳','d'=>'+86','n'=>'China',       'p'=>'### #### ####','ex'=>'131 1234 5678'],
        ];
    }

    /** Field types that collect no submission value (display/layout only). */
    const DISPLAY_TYPES = ['heading', 'step'];

    /** Condition operators usable in a field's show_if rule. */
    const CONDITION_OPS = ['eq', 'ne', 'filled', 'empty', 'gt', 'lt'];

    /** Max decoded bytes for a captured signature PNG (~700 KB). */
    const SIGNATURE_MAX_BYTES = 700 * 1024;

    /** Bumped to cache-bust public JS/CSS assets. */
    const ASSET_VERSION = '0.52.6';

    /** Webhook signature: HMAC-SHA256 of the raw JSON body, keyed by per-form secret. */
    const WEBHOOK_SIGNATURE_HEADER = 'X-Slate-Signature';

    /** True once per request after we've confirmed the schema exists. */
    private static bool $schemaChecked = false;

    /** True once per request after the intlphone live-format JS has been printed. */
    private static bool $intlAssetPrinted = false;

    /** True once per request after the address-autocomplete JS has been printed. */
    private static bool $addrAssetPrinted = false;

    /**
     * Lazily create the forms_* tables if they don't already exist.
     * Defensive: if install.sql didn't run during plugin activation
     * (some hosts swallow CREATE TABLE failures), this ensures every
     * admin page and the public router can still operate.
     */
    public static function ensureSchema(): void {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;
        try {
            $pdo = Database::get();
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `forms_definitions` (
                    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`        INT UNSIGNED NOT NULL DEFAULT 1,
                    `slug`             VARCHAR(80) NOT NULL,
                    `title`            VARCHAR(200) NOT NULL,
                    `description`      TEXT NULL,
                    `fields_json`      LONGTEXT NOT NULL,
                    `submit_label`     VARCHAR(80) NOT NULL DEFAULT 'Submit',
                    `success_message`  TEXT NULL,
                    `redirect_url`     VARCHAR(500) NULL,
                    `notify_email`     VARCHAR(200) NULL,
                    `confirm_submitter` TINYINT(1) NOT NULL DEFAULT 0,
                    `confirm_subject`  VARCHAR(200) NULL,
                    `confirm_body`     TEXT NULL,
                    `status`           ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
                    `submission_limit` INT UNSIGNED NULL,
                    `settings_json`    TEXT NULL,
                    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `tenant_slug` (`tenant_id`, `slug`),
                    KEY `tenant_status` (`tenant_id`, `status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `forms_submissions` (
                    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`       INT UNSIGNED NOT NULL DEFAULT 1,
                    `form_id`         INT UNSIGNED NOT NULL,
                    `ref`             VARCHAR(32) NOT NULL,
                    `data_json`       LONGTEXT NOT NULL,
                    `submitter_email` VARCHAR(200) NULL,
                    `ip`              VARCHAR(60) NULL,
                    `user_agent`      VARCHAR(255) NULL,
                    `read_at`         DATETIME NULL,
                    `email_sent`      TINYINT(1) NOT NULL DEFAULT 0,
                    `email_error`     TEXT NULL,
                    `country`         VARCHAR(2) NULL,
                    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `tenant_ref` (`tenant_id`, `ref`),
                    KEY `tenant_form` (`tenant_id`, `form_id`),
                    KEY `tenant_read` (`tenant_id`, `read_at`),
                    KEY `tenant_created` (`tenant_id`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `forms_webhooks` (
                    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
                    `form_id`    INT UNSIGNED NOT NULL,
                    `url`        VARCHAR(500) NOT NULL,
                    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `tenant_form` (`tenant_id`, `form_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `forms_webhook_log` (
                    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`     INT UNSIGNED NOT NULL DEFAULT 1,
                    `webhook_id`    INT UNSIGNED NOT NULL,
                    `submission_id` INT UNSIGNED NULL,
                    `status_code`   INT NULL,
                    `response_body` TEXT NULL,
                    `error`         TEXT NULL,
                    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `tenant_webhook` (`tenant_id`, `webhook_id`),
                    KEY `tenant_created` (`tenant_id`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `forms_spam_log` (
                    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
                    `form_id`    INT UNSIGNED NOT NULL,
                    `code`       VARCHAR(40) NOT NULL,
                    `reason`     VARCHAR(255) NULL,
                    `ip`         VARCHAR(60) NULL,
                    `country`    VARCHAR(2) NULL,
                    `user_agent` VARCHAR(255) NULL,
                    `snippet`    TEXT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `tenant_form` (`tenant_id`, `form_id`),
                    KEY `tenant_created` (`tenant_id`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            // Contacts — a CRM-style roll-up of unique submitters (by email),
            // built from submissions. Kept separate from core `customers`
            // (which is auth/account-bearing) so form leads don't pollute
            // real customer accounts.
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `forms_contacts` (
                    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`         INT UNSIGNED NOT NULL DEFAULT 1,
                    `email`             VARCHAR(200) NOT NULL,
                    `name`              VARCHAR(190) NULL,
                    `phone`             VARCHAR(40)  NULL,
                    `submissions_count` INT UNSIGNED NOT NULL DEFAULT 0,
                    `first_seen_at`     DATETIME NULL,
                    `last_seen_at`      DATETIME NULL,
                    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `tenant_email` (`tenant_id`, `email`),
                    KEY `tenant_last` (`tenant_id`, `last_seen_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            // CREATE TABLE IF NOT EXISTS is a no-op on a table that predates
            // a newly-added column, so reconcile columns that older installs
            // may be missing. This is what surfaced as "Undefined array key
            // 'status'" on the public router and admin list.
            self::reconcileColumns($pdo, 'forms_definitions', [
                // fields_json was missing on older installs, fataling every
                // save (edit.php) and the admin form list (submission.php).
                // Added as NULL so the ALTER succeeds on tables with rows.
                'fields_json'       => "LONGTEXT NULL",
                'description'       => "TEXT NULL",
                'submit_label'      => "VARCHAR(80) NOT NULL DEFAULT 'Submit'",
                'success_message'   => "TEXT NULL",
                'redirect_url'      => "VARCHAR(500) NULL",
                'notify_email'      => "VARCHAR(200) NULL",
                'confirm_submitter' => "TINYINT(1) NOT NULL DEFAULT 0",
                'confirm_subject'   => "VARCHAR(200) NULL",
                'confirm_body'      => "TEXT NULL",
                'status'            => "ENUM('draft','published','archived') NOT NULL DEFAULT 'draft'",
                'submission_limit'  => "INT UNSIGNED NULL",
                'settings_json'     => "TEXT NULL",
            ]);
            // Same problem on the submissions table: tables created by an
            // older Forms version are missing data_json + the metadata
            // columns, which fatals the public submit INSERT. data_json is
            // reconciled as NULL (the CREATE defines it NOT NULL) so the
            // ALTER succeeds on tables that already hold legacy rows.
            self::reconcileColumns($pdo, 'forms_submissions', [
                'data_json'       => "LONGTEXT NULL",
                'submitter_email' => "VARCHAR(200) NULL",
                'ip'              => "VARCHAR(60) NULL",
                'user_agent'      => "VARCHAR(255) NULL",
                'read_at'         => "DATETIME NULL",
                'email_sent'      => "TINYINT(1) NOT NULL DEFAULT 0",
                'email_error'     => "TEXT NULL",
                'country'         => "VARCHAR(2) NULL",
                'created_at'      => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
                // Triage status pipeline (new → in_progress → done). Existing
                // rows take the default 'new' when the column is added.
                'status'          => "ENUM('new','in_progress','done') NOT NULL DEFAULT 'new'",
            ]);

            // The earliest Forms schema stored the payload in a `data` column
            // before it was renamed to `data_json`. If that legacy column is
            // present, backfill it into `data_json` so old submissions still
            // render in the admin. Idempotent + cheap: the WHERE is a no-op
            // once every legacy row has been copied.
            $hasLegacy = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forms_submissions'
                    AND COLUMN_NAME = 'data'"
            );
            $hasLegacy->execute();
            if ((int) $hasLegacy->fetchColumn() === 1) {
                $pdo->exec(
                    "UPDATE `forms_submissions`
                        SET `data_json` = `data`
                      WHERE `data_json` IS NULL AND `data` IS NOT NULL"
                );
            }
        } catch (\Throwable $e) {
            if (function_exists('slate_log')) {
                slate_log('FormsAPI::ensureSchema failed: ' . $e->getMessage(), 'error');
            }
        }
    }

    /**
     * Add any columns absent from an existing table. CREATE TABLE IF NOT
     * EXISTS won't alter a table that already exists, so columns introduced
     * after first install would never appear. Idempotent. Column names and
     * definitions are hardcoded literals (never user input), so the inline
     * identifiers are safe; the lookup is a prepared statement.
     */
    private static function reconcileColumns(\PDO $pdo, string $table, array $columns): void {
        $stmt = $pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->execute([$table]);
        $existing = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $col) {
            $existing[strtolower((string)$col)] = true;
        }
        if (!$existing) return; // table absent — the CREATE above handles it
        foreach ($columns as $name => $definition) {
            if (isset($existing[strtolower($name)])) continue;
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$definition}");
        }
    }

    /**
     * Best-effort display name for a submitter, derived from field data.
     * Looks for explicit name fields, then first/last pairs, then any
     * "*name*" field, and finally a title-cased email local-part.
     */
    public static function deriveContactName(array $data, string $email = ''): string {
        $norm = static fn(string $k): string => strtolower(preg_replace('/[^a-z]/i', '', $k));
        $full = ['name', 'fullname', 'yourname', 'customername', 'contactname'];
        foreach ($data as $k => $v) {
            if (!is_string($v) || trim($v) === '') continue;
            if (in_array($norm($k), $full, true)) return trim($v);
        }
        $first = $last = '';
        foreach ($data as $k => $v) {
            if (!is_string($v) || trim($v) === '') continue;
            $kn = $norm($k);
            if ($first === '' && in_array($kn, ['firstname', 'fname', 'givenname'], true)) $first = trim($v);
            if ($last  === '' && in_array($kn, ['lastname', 'lname', 'surname', 'familyname'], true)) $last = trim($v);
        }
        if ($first !== '' || $last !== '') return trim($first . ' ' . $last);
        foreach ($data as $k => $v) {
            if (!is_string($v) || trim($v) === '') continue;
            $kl = strtolower($k);
            if (strpos($kl, 'name') !== false && !preg_match('/(company|business|file|user|nick)/', $kl)) {
                return trim($v);
            }
        }
        if ($email !== '') {
            $lp = explode('@', $email)[0];
            return ucwords(trim(str_replace(['.', '_', '-', '+'], ' ', $lp)));
        }
        return '';
    }

    /** Up-to-two-letter initials for an avatar, from a name (or email). */
    public static function initials(string $name, string $email = ''): string {
        $src = trim($name) !== '' ? trim($name) : $email;
        if ($src === '') return '?';
        $parts = preg_split('/[\s@._-]+/', $src, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) >= 2) return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
        return mb_strtoupper(mb_substr($src, 0, 2));
    }

    /** Gravatar URL for an email (d=404 → caller falls back to initials). */
    public static function gravatarUrl(string $email, int $size = 38): string {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) return '';
        return 'https://www.gravatar.com/avatar/' . md5($email) . '?s=' . ($size * 2) . '&d=404';
    }

    /**
     * Stable CSS background for an initials avatar, hashed from a key (email
     * or name) so every contact gets a distinct-but-deterministic gradient
     * instead of the same indigo→purple block. Tuned to stay legible against
     * white initials (high saturation, medium-dark luminance).
     */
    public static function avatarGradient(string $key): string {
        $key = strtolower(trim($key));
        if ($key === '') return 'linear-gradient(135deg,#6366F1,#8B5CF6)';
        $h = crc32($key);
        $hue1 = $h % 360;
        $hue2 = ($hue1 + 28 + (($h >> 9) % 18)) % 360;       // nearby hue for a smooth gradient
        $sat  = 62 + (($h >> 3) % 14);                        // 62–75 %
        $lit  = 46 + (($h >> 5) % 8);                         // 46–53 %
        return sprintf(
            'linear-gradient(135deg,hsl(%d,%d%%,%d%%),hsl(%d,%d%%,%d%%))',
            $hue1, $sat, $lit, $hue2, $sat, max(38, $lit - 6)
        );
    }

    /** Best-effort phone number from field data (by key, then by shape). */
    public static function deriveContactPhone(array $data): string {
        foreach ($data as $k => $v) {
            if (!is_string($v) || trim($v) === '') continue;
            $kl = strtolower($k);
            if (preg_match('/(phone|tel|mobile|cell|whatsapp)/', $kl)) return trim($v);
        }
        return '';
    }

    /**
     * Upsert a contact from a submission. No-op without a valid email.
     * Increments the submission counter and rolls last_seen forward; only
     * overwrites name/phone when the new submission carries a value.
     */
    public static function upsertContact(int $tid, ?string $email, array $data): void {
        $email = strtolower(trim((string)$email));
        if ($email === '' || !str_contains($email, '@')) return;
        $email = mb_substr($email, 0, 200);
        $name  = mb_substr(self::deriveContactName($data, $email), 0, 190);
        $phone = mb_substr(self::deriveContactPhone($data), 0, 40);
        try {
            $pdo  = Database::get();
            $stmt = $pdo->prepare(
                "INSERT INTO `forms_contacts`
                    (tenant_id, email, name, phone, submissions_count, first_seen_at, last_seen_at)
                 VALUES (?, ?, ?, ?, 1, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    submissions_count = submissions_count + 1,
                    last_seen_at = NOW(),
                    name  = COALESCE(NULLIF(?, ''), name),
                    phone = COALESCE(NULLIF(?, ''), phone)"
            );
            $stmt->execute([
                $tid, $email,
                $name  !== '' ? $name  : null,
                $phone !== '' ? $phone : null,
                $name, $phone,
            ]);
        } catch (\Throwable $e) {
            if (function_exists('slate_log')) {
                slate_log('FormsAPI::upsertContact failed: ' . $e->getMessage(), 'warning');
            }
        }
    }

    /**
     * One-time backfill: aggregate existing submissions into forms_contacts.
     * Guarded by a per-tenant settings flag (not "table empty") so it still
     * runs even if a live submission already created a contact row before an
     * admin first opened the Contacts page. Authoritative + idempotent: it
     * SETS the true aggregate counts/dates rather than incrementing, so a
     * submission already counted by the live upsert is not double-counted.
     */
    public static function backfillContacts(int $tid): int {
        try {
            $flagKey = 'forms.contacts_backfilled';
            if ((string) Database::setting($flagKey, $tid) === '1') return 0;

            $rows = Database::rows(
                "SELECT submitter_email, data_json, created_at
                   FROM forms_submissions
                  WHERE tenant_id = ? AND submitter_email IS NOT NULL AND submitter_email <> ''
               ORDER BY created_at ASC", [$tid]);

            $map = [];
            foreach ($rows as $r) {
                $email = mb_substr(strtolower(trim((string)$r['submitter_email'])), 0, 200);
                if ($email === '' || !str_contains($email, '@')) continue;
                $data  = json_decode($r['data_json'] ?? '{}', true) ?: [];
                $name  = mb_substr(self::deriveContactName($data, $email), 0, 190);
                $phone = mb_substr(self::deriveContactPhone($data), 0, 40);
                if (!isset($map[$email])) {
                    $map[$email] = ['count' => 0, 'first' => $r['created_at'],
                                    'last' => $r['created_at'], 'name' => '', 'phone' => ''];
                }
                $map[$email]['count']++;
                $map[$email]['last'] = $r['created_at']; // ASC order → latest wins
                if ($name  !== '') $map[$email]['name']  = $name;
                if ($phone !== '') $map[$email]['phone'] = $phone;
            }

            $pdo  = Database::get();
            $stmt = $pdo->prepare(
                "INSERT INTO `forms_contacts`
                    (tenant_id, email, name, phone, submissions_count, first_seen_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    submissions_count = ?,
                    first_seen_at = LEAST(COALESCE(first_seen_at, ?), ?),
                    last_seen_at  = GREATEST(COALESCE(last_seen_at, ?), ?),
                    name  = COALESCE(NULLIF(?, ''), name),
                    phone = COALESCE(NULLIF(?, ''), phone)"
            );
            $n = 0;
            foreach ($map as $email => $c) {
                $stmt->execute([
                    $tid, $email,
                    $c['name']  !== '' ? $c['name']  : null,
                    $c['phone'] !== '' ? $c['phone'] : null,
                    $c['count'], $c['first'], $c['last'],
                    // UPDATE branch:
                    $c['count'],
                    $c['first'], $c['first'],
                    $c['last'],  $c['last'],
                    $c['name'],  $c['phone'],
                ]);
                $n++;
            }
            Database::setSetting($flagKey, '1', $tid);
            return $n;
        } catch (\Throwable $e) {
            if (function_exists('slate_log')) {
                slate_log('FormsAPI::backfillContacts failed: ' . $e->getMessage(), 'warning');
            }
            return 0;
        }
    }

    /** Load a form by slug (tenant-scoped). Decodes fields_json. */
    public static function getForm(string $slug): ?array {
        $row = Database::row(
            "SELECT * FROM forms_definitions WHERE tenant_id = ? AND slug = ?",
            [current_tenant_id(), $slug]
        );
        if (!$row) return null;
        $row['fields'] = self::decodeFields($row['fields_json'] ?? '');
        return $row;
    }

    public static function getFormById(int $id): ?array {
        $row = Database::row(
            "SELECT * FROM forms_definitions WHERE id = ? AND tenant_id = ?",
            [$id, current_tenant_id()]
        );
        if (!$row) return null;
        $row['fields'] = self::decodeFields($row['fields_json'] ?? '');
        return $row;
    }

    /**
     * Parse the field DSL into a normalised array of field defs.
     * Returns ['fields' => [...], 'errors' => [...]] so callers can
     * show line-level parse errors in the editor.
     */
    public static function parseFieldDsl(string $text): array {
        $out    = [];
        $errors = [];
        $usedNames = [];
        $lineNum = 0;
        foreach (preg_split('/\r\n|\n|\r/', $text) as $raw) {
            $lineNum++;
            $line = trim($raw);
            if ($line === '' || str_starts_with($line, '#')) continue;

            $parts = array_map('trim', explode('|', $line));
            $type        = strtolower($parts[0] ?? '');
            $name        = $parts[1] ?? '';
            $label       = $parts[2] ?? '';
            $required    = strtolower($parts[3] ?? '') === 'required';
            $placeholder = $parts[4] ?? '';
            $optionsRaw  = $parts[5] ?? '';

            if (!in_array($type, self::SUPPORTED_TYPES, true)) {
                $errors[] = "Line {$lineNum}: unsupported field type '" . $type . "'.";
                continue;
            }
            if (!preg_match('/^[a-z][a-z0-9_]{0,49}$/i', $name)) {
                $errors[] = "Line {$lineNum}: name must start with a letter and contain only letters/digits/underscores.";
                continue;
            }
            $name = strtolower($name);
            if (isset($usedNames[$name])) {
                $errors[] = "Line {$lineNum}: duplicate field name '{$name}'.";
                continue;
            }
            $usedNames[$name] = true;

            $field = [
                'type'     => $type,
                'name'     => $name,
                'label'    => $label !== '' ? $label : ucwords(str_replace('_', ' ', $name)),
                'required' => $required,
            ];
            if ($placeholder !== '') $field['placeholder'] = $placeholder;

            if (in_array($type, ['select', 'radio', 'checkboxes'], true)) {
                $opts = $optionsRaw !== ''
                      ? array_values(array_filter(array_map('trim', explode(',', $optionsRaw)), fn($s) => $s !== ''))
                      : [];
                if (empty($opts)) {
                    $errors[] = "Line {$lineNum}: {$type} field '{$name}' needs an options list (comma-separated).";
                    continue;
                }
                $field['options'] = $opts;
            } elseif (in_array($type, ['range', 'rating'], true)) {
                // Numeric config rides in the options column: range = "min,max,step", rating = "maxStars".
                $opts = $optionsRaw !== ''
                      ? array_values(array_filter(array_map('trim', explode(',', $optionsRaw)), fn($s) => $s !== ''))
                      : [];
                if (!empty($opts)) $field['options'] = $opts;
            }

            $out[] = $field;
        }
        return ['fields' => $out, 'errors' => $errors];
    }

    /**
     * Serialise the parsed field list back to DSL text — used by the
     * edit page to round-trip safely after a save.
     */
    public static function fieldsToDsl(array $fields): string {
        $lines = [];
        foreach ($fields as $f) {
            $parts = [
                $f['type'] ?? 'text',
                $f['name'] ?? '',
                $f['label'] ?? '',
                !empty($f['required']) ? 'required' : '',
                $f['placeholder'] ?? '',
                isset($f['options']) ? implode(',', array_map(fn($o) => is_array($o) ? (string)($o['value'] ?? '') : (string)$o, (array)$f['options'])) : '',
            ];
            // Trim trailing empties for readability.
            while (count($parts) > 3 && end($parts) === '') array_pop($parts);
            $lines[] = implode('|', $parts);
        }
        return implode("\n", $lines);
    }

    private static function decodeFields(string $json): array {
        $arr = json_decode($json, true);
        return is_array($arr) ? $arr : [];
    }

    /**
     * Normalise a JSON field list from the visual builder into the canonical
     * field array. This is the richer counterpart to parseFieldDsl(): it
     * preserves advanced metadata that the 6-column DSL can't carry —
     * `show_if` (conditional logic), `formula` (calculated), `step`
     * (multi-step). Returns ['fields'=>[...], 'errors'=>[...]].
     */
    public static function normalizeFields($arr): array {
        $out = []; $errors = []; $used = [];
        if (!is_array($arr)) return ['fields' => [], 'errors' => ['Invalid field data.']];

        foreach ($arr as $f) {
            if (!is_array($f)) continue;
            $type = strtolower((string)($f['type'] ?? ''));
            if (!in_array($type, self::SUPPORTED_TYPES, true)) continue;

            $name = strtolower(trim((string)($f['name'] ?? '')));
            if (!preg_match('/^[a-z][a-z0-9_]{0,49}$/', $name) || isset($used[$name])) {
                $name = self::uniqueFieldName((string)($f['label'] ?? $type), $used);
            }
            $used[$name] = true;

            $field = [
                'type'     => $type,
                'name'     => $name,
                'label'    => (string)($f['label'] ?? ucwords(str_replace('_', ' ', $name))),
                'required' => !empty($f['required']),
            ];
            $ph = trim((string)($f['placeholder'] ?? ''));
            if ($ph !== '') $field['placeholder'] = $ph;

            // Field-level leading icon for text-like inputs ('' / 'auto' = by type).
            $ic = (string)($f['icon'] ?? '');
            if ($ic !== '' && $ic !== 'auto' && in_array($type, ['text','email','tel','url','number','date','time'], true)) {
                $field['icon'] = ($ic === 'none') ? 'none' : preg_replace('/[^a-z0-9-]/i', '', $ic);
            }

            if (isset($f['options']) && is_array($f['options'])) {
                $opts = [];
                foreach ($f['options'] as $o) {
                    if (is_array($o)) {
                        // Choice-card option: {value, label?, icon?, desc?}.
                        $val = trim((string)($o['value'] ?? $o['label'] ?? ''));
                        if ($val === '') continue;
                        $opt = ['value' => $val];
                        if (!empty($o['label']) && trim((string)$o['label']) !== $val) $opt['label'] = trim((string)$o['label']);
                        if (!empty($o['icon'])) $opt['icon'] = preg_replace('/[^a-z0-9-]/i', '', (string)$o['icon']);
                        if (!empty($o['desc'])) $opt['desc'] = trim((string)$o['desc']);
                        $opts[] = (count($opt) === 1) ? $val : $opt;   // collapse to plain string if no extras
                    } else {
                        $s = trim((string)$o);
                        if ($s !== '') $opts[] = $s;
                    }
                }
                if ($opts) $field['options'] = $opts;
            }
            if (in_array($type, ['select', 'radio', 'checkboxes'], true)) {
                if (empty($field['options'])) $errors[] = "Field '{$name}' needs an options list.";
                if (!empty($f['card']) && $type !== 'checkboxes') $field['card'] = true;   // render as choice cards
            }

            // Conditional logic — show this field only when a rule matches.
            if (!empty($f['show_if']) && is_array($f['show_if']) && !empty($f['show_if']['field'])) {
                $op = (string)($f['show_if']['op'] ?? 'eq');
                if (in_array($op, self::CONDITION_OPS, true)) {
                    $field['show_if'] = [
                        'field' => strtolower(preg_replace('/[^a-z0-9_]/i', '', (string)$f['show_if']['field'])),
                        'op'    => $op,
                        'value' => (string)($f['show_if']['value'] ?? ''),
                    ];
                }
            }
            // Address autocomplete — optional country bias (ISO 3166-1 alpha-2
            // codes, comma-separated). Empty = global. Used as the
            // `countrycodes` param on the Nominatim search to narrow results.
            if ($type === 'address') {
                $cc = strtolower(preg_replace('/[^a-z,]/i', '', (string)($f['addr_countries'] ?? '')));
                $cc = implode(',', array_filter(array_map('trim', explode(',', $cc)),
                    fn($c) => preg_match('/^[a-z]{2}$/', $c)));
                if ($cc !== '') $field['addr_countries'] = $cc;
            }
            // International phone — admin can lock the dial-code dropdown to a
            // subset of countries and choose which one is selected by default.
            // `intl_countries` is a list of valid dial codes ("+1", "+44", …);
            // empty / missing = allow every country in phoneCountries().
            // `intl_default` is the dial code to pre-select (must be in the
            // allowlist when one is set, otherwise falls back to first allowed).
            if ($type === 'intlphone') {
                $valid = array_column(self::phoneCountries(), 'd');
                $allow = [];
                if (isset($f['intl_countries']) && is_array($f['intl_countries'])) {
                    foreach ($f['intl_countries'] as $code) {
                        $c = trim((string)$code);
                        if (in_array($c, $valid, true) && !in_array($c, $allow, true)) $allow[] = $c;
                    }
                }
                if ($allow) $field['intl_countries'] = $allow;
                $def = trim((string)($f['intl_default'] ?? ''));
                if ($def !== '' && in_array($def, $valid, true)) {
                    if (!$allow || in_array($def, $allow, true)) $field['intl_default'] = $def;
                }
            }
            // Calculated field — a formula over numeric fields.
            if ($type === 'calc') {
                $field['formula'] = self::sanitizeFormula((string)($f['formula'] ?? ''));
            }
            // Acceptance / disclaimer — the terms body + the agreement label.
            if ($type === 'disclaimer') {
                $terms = trim((string)($f['terms'] ?? ''));
                if ($terms !== '') $field['terms'] = mb_substr($terms, 0, 8000);
                $agree = trim((string)($f['agree'] ?? ''));
                if ($agree !== '') $field['agree'] = mb_substr($agree, 0, 300);
            }
            // Acceptance / legal disclaimer — a titled, scrollable terms box with
            // an "I agree" checkbox. `title` = heading, `placeholder` = subtitle,
            // `terms` = the body text, `label` = the checkbox agreement line.
            if ($type === 'disclaimer') {
                $field['title'] = trim((string)($f['title'] ?? ''));
                $field['terms'] = trim((string)($f['terms'] ?? ''));
            }
            // Multi-step grouping (1-based step index).
            if (isset($f['step']) && is_numeric($f['step'])) {
                $field['step'] = max(1, (int)$f['step']);
            }
            // Column width in twelfths (2..12). Full width (12) is the
            // default and is omitted from the stored field. Legacy
            // `half:true` maps to 6. Display-only, signature/file, and
            // choice-card fields always span full.
            $noWidth = in_array($type, self::DISPLAY_TYPES, true)
                    || in_array($type, ['signature', 'file', 'disclaimer'], true)
                    || !empty($field['card']);
            if (!$noWidth) {
                $w = null;
                // Width 0/1 (or absent) means "unset" → fall back to legacy half.
                if (isset($f['width']) && is_numeric($f['width']) && (int)$f['width'] >= 2) {
                    $w = (int)$f['width'];
                } elseif (!empty($f['half'])) {
                    $w = 6;
                }
                if ($w !== null) {
                    $w = max(2, min(12, $w));
                    if ($w < 12) $field['width'] = $w;
                }
            }

            $out[] = $field;
        }
        if (!$out) $errors[] = 'Add at least one field.';
        return ['fields' => $out, 'errors' => $errors];
    }

    /** Unique snake_case field name derived from a label, avoiding $used keys. */
    private static function uniqueFieldName(string $label, array $used): string {
        $base = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($label)));
        $base = trim($base, '_');
        if ($base === '' || !preg_match('/^[a-z]/', $base)) $base = 'field_' . $base;
        $base = substr(trim($base, '_'), 0, 50) ?: 'field';
        $name = $base; $i = 2;
        while (isset($used[$name])) { $name = $base . '_' . $i++; }
        return $name;
    }

    /**
     * Evaluate a field's show_if rule against the submitted/known input.
     * Returns true when the field should be shown (and therefore validated).
     * A field with no rule is always shown. Mirrors the public-form JS.
     */
    public static function conditionMet(array $field, array $input): bool {
        $cond = $field['show_if'] ?? null;
        if (!is_array($cond) || empty($cond['field'])) return true;
        $other = $input[$cond['field']] ?? null;
        $cur   = is_array($other) ? '' : trim((string)($other ?? ''));
        $val   = trim((string)($cond['value'] ?? ''));
        switch ($cond['op']) {
            case 'eq':     return $cur === $val;
            case 'ne':     return $cur !== $val;
            case 'filled': return $cur !== '';
            case 'empty':  return $cur === '';
            case 'gt':     return is_numeric($cur) && is_numeric($val) && (float)$cur >  (float)$val;
            case 'lt':     return is_numeric($cur) && is_numeric($val) && (float)$cur <  (float)$val;
        }
        return true;
    }

    /** Keep only chars allowed in a calc formula: field refs, numbers, + - * / ( ) . */
    public static function sanitizeFormula(string $formula): string {
        return trim(preg_replace('/[^a-z0-9_{}+\-*\/().,\s]/i', '', $formula));
    }

    /**
     * Safely evaluate a calc formula like "{qty} * {price}" against $data.
     * Field references {name} are replaced with their numeric value (0 if
     * missing/non-numeric). Supports + - * / ( ) only — no eval, a tiny
     * shunting-yard parser. Returns a float, or null on a malformed formula.
     */
    public static function evalFormula(string $formula, array $data): ?float {
        $formula = self::sanitizeFormula($formula);
        if ($formula === '') return null;
        // Substitute {field} refs with their numeric value.
        $expr = preg_replace_callback('/\{([a-z0-9_]+)\}/i', function ($m) use ($data) {
            $v = $data[$m[1]] ?? 0;
            if (is_bool($v)) $v = $v ? 1 : 0;
            return is_numeric($v) ? (string)(float)$v : '0';
        }, $formula);
        if (!preg_match('/^[0-9+\-*\/().\s]*$/', $expr)) return null;

        // Tokenise.
        preg_match_all('/\d+\.?\d*|[+\-*\/()]/', $expr, $tok);
        $tokens = $tok[0];
        if (!$tokens) return null;

        // Shunting-yard → RPN.
        $prec = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];
        $out = []; $ops = [];
        foreach ($tokens as $t) {
            if (is_numeric($t)) {
                $out[] = (float)$t;
            } elseif (isset($prec[$t])) {
                while ($ops && end($ops) !== '(' && isset($prec[end($ops)]) && $prec[end($ops)] >= $prec[$t]) {
                    $out[] = array_pop($ops);
                }
                $ops[] = $t;
            } elseif ($t === '(') {
                $ops[] = $t;
            } elseif ($t === ')') {
                while ($ops && end($ops) !== '(') $out[] = array_pop($ops);
                if (!$ops) return null;
                array_pop($ops);
            }
        }
        while ($ops) { $op = array_pop($ops); if ($op === '(') return null; $out[] = $op; }

        // Evaluate RPN.
        $stack = [];
        foreach ($out as $t) {
            if (is_float($t)) { $stack[] = $t; continue; }
            if (count($stack) < 2) return null;
            $b = array_pop($stack); $a = array_pop($stack);
            switch ($t) {
                case '+': $stack[] = $a + $b; break;
                case '-': $stack[] = $a - $b; break;
                case '*': $stack[] = $a * $b; break;
                case '/': $stack[] = $b != 0.0 ? $a / $b : 0.0; break;
            }
        }
        return count($stack) === 1 ? (float)$stack[0] : null;
    }

    /**
     * Validate a $_POST-style input array against a form's fields.
     * Returns ['ok'=>bool, 'errors'=>['name'=>'message'], 'data'=>[...]].
     */
    public static function validateSubmission(array $form, array $input): array {
        $errors = [];
        $data   = [];

        foreach (($form['fields'] ?? []) as $f) {
            $name = $f['name'] ?? '';
            if ($name === '') continue;
            $type     = $f['type'] ?? 'text';
            if (in_array($type, self::DISPLAY_TYPES, true)) continue; // display-only, no value
            if ($type === 'calc') continue;                          // recomputed after the loop
            if (!self::conditionMet($f, $input)) continue;            // hidden by its show_if rule
            $required = !empty($f['required']);

            $raw = $input[$name] ?? null;

            // Checkboxes: a single checkbox = presence boolean; missing key = unchecked.
            if ($type === 'checkbox') {
                $checked = !empty($raw);
                if ($required && !$checked) {
                    $errors[$name] = sprintf(__('forms_field_required', '%s is required.'), ($f['label'] ?? $name));
                }
                $data[$name] = $checked;
                continue;
            }

            // Multi-select checkbox group → array of chosen options, each
            // whitelisted against the field's option list.
            if ($type === 'checkboxes') {
                $opts = array_map(
                    fn($o) => is_array($o) ? (string)($o['value'] ?? '') : (string)$o,
                    (array)($f['options'] ?? [])
                );
                $chosen = [];
                foreach ((array)$raw as $r) {
                    $r = is_string($r) ? trim($r) : '';
                    if ($r !== '' && in_array($r, $opts, true)) $chosen[] = $r;
                }
                $chosen = array_values(array_unique($chosen));
                if ($required && !$chosen) {
                    $errors[$name] = sprintf(__('forms_field_required', '%s is required.'), ($f['label'] ?? $name));
                }
                $data[$name] = $chosen;
                continue;
            }

            $value = is_string($raw) ? trim($raw) : '';
            if ($value === '' && $raw !== null && !is_string($raw)) {
                // Defensive: arrays etc. aren't accepted for non-checkbox.
                $value = '';
            }

            if ($value === '') {
                if ($required) {
                    $errors[$name] = sprintf(__('forms_field_required', '%s is required.'), ($f['label'] ?? $name));
                }
                $data[$name] = '';
                continue;
            }

            switch ($type) {
                case 'email':
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $errors[$name] = 'Please enter a valid email address.';
                    }
                    break;
                case 'url':
                    if (!filter_var($value, FILTER_VALIDATE_URL)) {
                        $errors[$name] = 'Please enter a valid URL.';
                    }
                    break;
                case 'number':
                    if (!is_numeric($value)) {
                        $errors[$name] = 'Please enter a number.';
                    }
                    break;
                case 'date':
                    // Accept YYYY-MM-DD; let the browser do the heavy work
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                        $errors[$name] = 'Please enter a date.';
                    }
                    break;
                case 'time':
                    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
                        $errors[$name] = 'Please enter a valid time.';
                    }
                    break;
                case 'range':
                case 'rating':
                    if (!is_numeric($value)) {
                        $errors[$name] = 'Please choose a value.';
                    } else {
                        // Clamp to the configured bounds (don't trust the client).
                        [$min, $max] = self::rangeBounds($f);
                        $n = (float)$value;
                        if ($n < $min) $n = $min;
                        if ($n > $max) $n = $max;
                        $value = (string)($type === 'rating' ? (int)round($n) : $n);
                    }
                    break;
                case 'hidden':
                    // Preset / passthrough value — accept as-is, capped.
                    if (mb_strlen($value) > 2000) $value = mb_substr($value, 0, 2000);
                    break;
                case 'select':
                case 'radio':
                    $opts = array_map(
                        fn($o) => is_array($o) ? (string)($o['value'] ?? '') : (string)$o,
                        (array)($f['options'] ?? [])
                    );
                    if (!in_array($value, $opts, true)) {
                        $errors[$name] = 'Please choose one of the listed options.';
                    }
                    break;
                case 'tel':
                    if (!preg_match('/^[0-9+()\-.\s]{4,40}$/', $value)) {
                        $errors[$name] = 'Please enter a valid phone number.';
                    }
                    break;
                case 'intlphone':
                    // Validate the number, then prepend the chosen dial code so
                    // the stored value is the full international number.
                    if (!preg_match('/^[0-9+()\-.\s]{4,40}$/', $value)) {
                        $errors[$name] = 'Please enter a valid phone number.';
                    } else {
                        $cc = trim((string)($input[$name . '__cc'] ?? ''));
                        if (preg_match('/^\+\d{1,4}$/', $cc) && strpos($value, '+') !== 0) {
                            $value = $cc . ' ' . $value;
                        }
                    }
                    break;
                case 'textarea':
                    if (mb_strlen($value) > 8000) {
                        $errors[$name] = 'Please keep this under 8000 characters.';
                    }
                    break;
                case 'address':
                    // Autocompleted from Nominatim but freeform-editable, so
                    // we just sanity-cap length. The submitted value is the
                    // formatted address string as displayed in the dropdown.
                    if (mb_strlen($value) > 400) {
                        $errors[$name] = 'Please keep the address under 400 characters.';
                    }
                    break;
                case 'file':
                    // File validation happens out-of-band — see
                    // FormsAPI::handleFileUploads() called from the
                    // public router. The DSL only records "file is
                    // required"; the actual file lives in $_FILES.
                    $value = ''; // placeholder; real path set later
                    break;
                case 'signature':
                    // The pad posts a PNG data URL in this field. Verify the
                    // shape here (cheap reject of junk); the decode + save to
                    // disk happens in handleSignatures() from the router.
                    if (!preg_match('#^data:image/png;base64,[A-Za-z0-9+/=\s]+$#', $value)) {
                        $errors[$name] = 'Please add your signature.';
                    }
                    // Keep the raw data URL in $value for handleSignatures().
                    break;
                case 'text':
                default:
                    if (mb_strlen($value) > 500) {
                        $errors[$name] = 'Please keep this under 500 characters.';
                    }
                    break;
            }

            $data[$name] = $value;
        }

        // Calculated fields: recompute server-side from the assembled data
        // (never trust the client-submitted result).
        foreach (($form['fields'] ?? []) as $f) {
            if (($f['type'] ?? '') !== 'calc') continue;
            $name = $f['name'] ?? '';
            if ($name === '' || !self::conditionMet($f, $input)) continue;
            $r = self::evalFormula((string)($f['formula'] ?? ''), $data);
            $data[$name] = $r === null ? '' : (string)(round($r, 4) + 0);
        }

        return [
            'ok'     => empty($errors),
            'errors' => $errors,
            'data'   => $data,
        ];
    }

    /**
     * Bounds for range/rating fields, read from the field's options list.
     * range  → [min, max, step] (options: "min,max,step", default 0,100,1)
     * rating → [1, maxStars, 1] (options: "maxStars", default 5)
     */
    private static function rangeBounds(array $f): array {
        $opts = array_values((array)($f['options'] ?? []));
        if (($f['type'] ?? '') === 'rating') {
            $max = (isset($opts[0]) && (int)$opts[0] > 0) ? min(10, (int)$opts[0]) : 5;
            return [1, $max, 1];
        }
        $min  = (isset($opts[0]) && is_numeric($opts[0])) ? (float)$opts[0] : 0;
        $max  = (isset($opts[1]) && is_numeric($opts[1])) ? (float)$opts[1] : 100;
        $step = (isset($opts[2]) && is_numeric($opts[2]) && (float)$opts[2] > 0) ? (float)$opts[2] : 1;
        if ($max <= $min) $max = $min + 1;
        return [$min, $max, $step];
    }

    /** Curated inline-SVG icon set for choice cards. Unknown → 'check'. */
    public static function cardIcon(string $name): string {
        static $icons = [
            'check'     => '<polyline points="20 6 9 17 4 12"/>',
            'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'users'     => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 21a6.5 6.5 0 0 1 13 0"/><path d="M16 5.5a3.5 3.5 0 0 1 0 6.9"/><path d="M22 21a6.5 6.5 0 0 0-5-6.3"/>',
            'building'  => '<rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M9 21v-4h6v4M8 7h2M14 7h2M8 11h2M14 11h2"/>',
            'anchor'    => '<circle cx="12" cy="5" r="2.5"/><path d="M12 7.5V21M5 13a7 7 0 0 0 14 0M3 13h3M18 13h3"/>',
            'boat'      => '<path d="M3 14l9-4 9 4-2.5 6H5.5L3 14z"/><path d="M12 10V4l5 3"/>',
            'star'      => '<polygon points="12 2 15 9 22 9.3 16.5 14 18.5 21 12 17 5.5 21 7.5 14 2 9.3 9 9"/>',
            'heart'     => '<path d="M12 21s-7-4.5-9.5-9A5 5 0 0 1 12 6a5 5 0 0 1 9.5 6c-2.5 4.5-9.5 9-9.5 9z"/>',
            'tag'       => '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><circle cx="7" cy="7" r="1.4"/>',
            'box'       => '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8zM3 8l9 5 9-5"/>',
            'card'      => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
            'globe'     => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
            'phone'     => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
            'mail'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
            'home'      => '<path d="M3 11l9-7 9 7M5 10v10h14V10"/>',
            'shield'    => '<path d="M12 2l8 3v6c0 5-3.5 9-8 11-4.5-2-8-6-8-11V5z"/>',
            'truck'     => '<path d="M3 6h11v9H3zM14 9h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>',
            'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
            'dollar'    => '<line x1="12" y1="2" x2="12" y2="22"/><path d="M17 6.5A4 4 0 0 0 13 4h-2a3.5 3.5 0 0 0 0 7h2a3.5 3.5 0 0 1 0 7h-2a4 4 0 0 1-4-2.5"/>',
            'briefcase' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 13h18"/>',
            'wrench'    => '<path d="M14 7a4 4 0 0 1 5 5l-7 7-3-3 7-7a4 4 0 0 0-5-5z"/>',
            'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'hash'      => '<line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="10" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="14" y2="21"/>',
            'map-pin'   => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
            'lock'      => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
            'search'    => '<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.7" y2="16.7"/>',
            'type'      => '<polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/>',
        ];
        $p = $icons[$name] ?? $icons['check'];
        return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" '
             . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
    }

    /** Inline 18px stroke icon for the wizard nav buttons (arrows / check). */
    public static function navIcon(string $name): string {
        static $icons = [
            'arrow-left'  => '<path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/>',
            'arrow-right' => '<path d="M5 12h14"/><path d="M12 5l7 7-7 7"/>',
            'check'       => '<polyline points="20 6 9 17 4 12"/>',
        ];
        $p = $icons[$name] ?? $icons['arrow-right'];
        return '<svg class="fbtn-ico" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" '
             . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
    }

    /**
     * Resolve the leading icon for a text-like input. Order: explicit field
     * icon ('none' = off) → icon by type (email→mail, tel→phone, …) →
     * a light name/label heuristic for text fields → '' (no icon).
     */
    public static function fieldInputIcon(array $f): string {
        $set = (string)($f['icon'] ?? '');
        if ($set === 'none') return '';
        if ($set !== '' && $set !== 'auto') return $set;

        $type = $f['type'] ?? 'text';
        $byType = ['email' => 'mail', 'tel' => 'phone', 'url' => 'globe',
                   'date' => 'calendar', 'time' => 'clock', 'number' => 'hash'];
        if (isset($byType[$type])) return $byType[$type];

        if ($type === 'text') {
            $h = strtolower(((string)($f['name'] ?? '')) . ' ' . ((string)($f['label'] ?? '')));
            $map = ['name' => 'user', 'phone' => 'phone', 'mobile' => 'phone', 'email' => 'mail',
                    'compan' => 'building', 'business' => 'building', 'organi' => 'building',
                    'address' => 'map-pin', 'street' => 'map-pin', 'city' => 'map-pin', 'zip' => 'map-pin', 'postal' => 'map-pin',
                    'price' => 'dollar', 'amount' => 'dollar', 'cost' => 'dollar', 'budget' => 'dollar', 'total' => 'dollar', 'pay' => 'dollar',
                    'card' => 'card', 'web' => 'globe', 'site' => 'globe', 'search' => 'search', 'password' => 'lock'];
            foreach ($map as $kw => $ic) { if (strpos($h, $kw) !== false) return $ic; }
        }
        return '';
    }

    /** Render select/radio as rich choice cards (icon + title + description). */
    private static function renderChoiceCards(array $field, $value, string $id, string $name, bool $required): string {
        ob_start();
        echo '<div class="forms-cards" role="radiogroup">';
        foreach ((array)($field['options'] ?? []) as $i => $o) {
            $val  = is_array($o) ? (string)($o['value'] ?? '') : (string)$o;
            if ($val === '') continue;
            $lbl  = is_array($o) ? (string)($o['label'] ?? $o['value'] ?? '') : (string)$o;
            $icon = is_array($o) ? (string)($o['icon'] ?? '') : '';
            $desc = is_array($o) ? (string)($o['desc'] ?? '') : '';
            $oid  = $id . '_' . $i;
            $sel  = (is_string($value) && $value === $val) ? ' checked' : '';
            echo '<div class="forms-card-wrap">';
            echo   '<input type="radio" id="' . e($oid) . '" name="' . e($name) . '" value="' . e($val) . '"' . $sel . ($required ? ' required' : '') . '>';
            echo   '<label class="forms-card" for="' . e($oid) . '">';
            if ($icon !== '') echo '<span class="forms-card-ico">' . self::cardIcon($icon) . '</span>';
            echo     '<span class="forms-card-body"><span class="forms-card-title">' . e($lbl) . '</span>';
            if ($desc !== '') echo '<span class="forms-card-desc">' . e($desc) . '</span>';
            echo     '</span>';
            echo     '<span class="forms-card-check" aria-hidden="true">' . self::cardIcon('check') . '</span>';
            echo   '</label>';
            echo '</div>';
        }
        echo '</div>';
        return (string) ob_get_clean();
    }

    /**
     * Render one field as HTML for the public form. Uses the shared
     * design-system classes (.field / .field-label / etc.) so the
     * markup is identical between admin previews and the live form.
     */
    public static function renderField(array $field, $value = null, ?string $error = null): string {
        // Display-only + special-shell fields are rendered before the
        // standard .field wrapper (no label row).
        $t0 = $field['type'] ?? 'text';
        // Conditional-visibility hooks for the public-form JS.
        $cond = ' data-fname="' . e((string)($field['name'] ?? '')) . '"';
        if (!empty($field['show_if'])) $cond .= ' data-showif="' . e(json_encode($field['show_if'])) . '"';

        if ($t0 === 'heading') {
            $sub = trim((string)($field['placeholder'] ?? ''));
            return '<div class="forms-heading"' . $cond . '><h3>' . e((string)($field['label'] ?? ''))
                 . '</h3>' . ($sub !== '' ? '<p>' . e($sub) . '</p>' : '') . '</div>';
        }
        if ($t0 === 'hidden') {
            $hv = is_string($value) && $value !== '' ? $value : (string)($field['placeholder'] ?? '');
            return '<input type="hidden" name="' . e((string)($field['name'] ?? '')) . '" value="' . e($hv) . '">';
        }
        if ($t0 === 'disclaimer') {
            // Acceptance/indemnity field: a titled box with a scrollable terms
            // panel and an "I agree" checkbox (the actual required form input).
            $dname  = (string)($field['name'] ?? '');
            if ($dname === '') return '';
            $did    = 'f_' . preg_replace('/[^a-z0-9_]/i', '', $dname);
            $dreq   = !empty($field['required']);
            $dtitle = trim((string)($field['label'] ?? '')) ?: __('forms_disclaimer_default_title', 'Disclaimer');
            $dsub   = trim((string)($field['placeholder'] ?? ''));
            $dterms = (string)($field['terms'] ?? '');
            $dagree = trim((string)($field['agree'] ?? '')) ?: __('forms_disclaimer_agree_default', 'I have read and agree to the terms above.');
            $warn   = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.5 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.5a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
            ob_start();
            echo '<div class="field forms-disclaimer"' . $cond . '>';
            echo   '<div class="forms-disclaimer-head"><span class="forms-disclaimer-ico">' . $warn . '</span>';
            echo     '<div class="forms-disclaimer-headtext"><div class="forms-disclaimer-title">' . e($dtitle) . '</div>';
            if ($dsub !== '') echo '<div class="forms-disclaimer-sub">' . e($dsub) . '</div>';
            echo     '</div></div>';
            if ($dterms !== '') echo '<div class="forms-disclaimer-body" tabindex="0">' . nl2br(e($dterms)) . '</div>';
            echo   '<label class="forms-disclaimer-agree" for="' . e($did) . '">';
            echo     '<input type="checkbox" id="' . e($did) . '" name="' . e($dname) . '" value="I agree"'
                   . ((bool)$value ? ' checked' : '') . ($dreq ? ' required' : '') . '>';
            echo     '<span>' . e($dagree) . ($dreq ? ' <span class="field-required">*</span>' : '') . '</span>';
            echo   '</label>';
            echo '</div>';
            return (string) ob_get_clean();
        }
        $name        = $field['name'] ?? '';
        $type        = $field['type'] ?? 'text';
        $label       = $field['label'] ?? $name;
        $required    = !empty($field['required']);
        $placeholder = $field['placeholder'] ?? '';

        if ($name === '') return '';

        $id   = 'f_' . preg_replace('/[^a-z0-9_]/i', '', $name);
        $req  = $required ? ' required' : '';
        $star = $required ? ' <span class="field-required">*</span>' : '';

        $valueAttr = is_string($value) ? e($value) : '';
        $checked   = (bool)$value;

        // Column width class (f-w-2 … f-w-11). Full width (or unset) gets
        // no class; legacy `half` still maps to the .field--half span.
        $wCls = '';
        if (!empty($field['width'])) {
            $fw = max(2, min(12, (int)$field['width']));
            if ($fw < 12) $wCls = ' f-w-' . $fw;
        } elseif (!empty($field['half'])) {
            $wCls = ' field--half';
        }

        ob_start();
        echo '<div class="field' . $wCls . '"' . $cond . '>';

        if ($type === 'disclaimer') {
            // Acceptance box: warning header, scrollable terms, agree checkbox.
            $dTitle = trim((string)($field['title'] ?? '')) ?: 'Terms & Conditions';
            $dSub   = trim((string)($placeholder));
            $dTerms = (string)($field['terms'] ?? '');
            $warnSvg = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.2 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.2a2 2 0 0 0-3.4 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>';
            echo '<div class="forms-disclaimer">';
            echo   '<div class="forms-disc-head"><span class="forms-disc-ico">' . $warnSvg . '</span>'
                 . '<div class="forms-disc-heads"><div class="forms-disc-title">' . e($dTitle) . '</div>'
                 . ($dSub !== '' ? '<div class="forms-disc-sub">' . e($dSub) . '</div>' : '') . '</div></div>';
            echo   '<div class="forms-disc-body" tabindex="0">' . nl2br(e($dTerms)) . '</div>';
            echo   '<label class="forms-disc-agree" for="' . e($id) . '">'
                 . '<input type="checkbox" id="' . e($id) . '" name="' . e($name) . '" value="1"' . ($checked ? ' checked' : '') . $req . '>'
                 . '<span>' . e($label) . $star . '</span></label>';
            echo '</div>';
            echo '</div>';
            return (string) ob_get_clean();
        }

        if ($type === 'checkbox') {
            // Modern selectable card (reference style): hidden native input,
            // the whole row is the click target, and an animated check badge
            // on the right appears when selected.
            echo '<label class="forms-check" for="' . e($id) . '">';
            echo   '<input type="checkbox" id="' . e($id) . '" name="' . e($name) . '" value="1"'
                 . ($checked ? ' checked' : '') . $req . '>';
            echo   '<span class="forms-check-label">' . e($label) . $star . '</span>';
            echo   '<span class="forms-check-badge" aria-hidden="true">' . self::cardIcon('check') . '</span>';
            echo '</label>';
        } else {
            echo '<label class="field-label" for="' . e($id) . '">' . e($label) . $star . '</label>';

            $phAttr = $placeholder !== '' ? ' placeholder="' . e($placeholder) . '"' : '';

            switch ($type) {
                case 'textarea':
                    echo '<textarea id="' . e($id) . '" name="' . e($name) . '"' . $req . $phAttr . '>'
                       . $valueAttr . '</textarea>';
                    break;

                case 'select':
                    if (!empty($field['card'])) { echo self::renderChoiceCards($field, $value, $id, $name, $required); break; }
                    echo '<select id="' . e($id) . '" name="' . e($name) . '"' . $req . '>';
                    if ($placeholder !== '') {
                        echo '<option value="">' . e($placeholder) . '</option>';
                    } elseif (!$required) {
                        echo '<option value="">—</option>';
                    }
                    foreach ((array)($field['options'] ?? []) as $opt) {
                        $sel = (is_string($value) && $value === $opt) ? ' selected' : '';
                        echo '<option value="' . e($opt) . '"' . $sel . '>' . e($opt) . '</option>';
                    }
                    echo '</select>';
                    break;

                case 'file':
                    echo '<input type="file" id="' . e($id) . '" name="' . e($name) . '"'
                       . $req . ' accept="image/*,application/pdf,.doc,.docx,.txt">';
                    echo '<div class="field-hint">Max 10 MB. Images, PDF, or document files.</div>';
                    break;

                case 'signature':
                    // Draw-or-type signature pad. The hidden input holds a PNG
                    // data URL produced client-side; handleSignatures() decodes
                    // and stores it. Required is enforced server-side and by the
                    // pad's own submit guard (hidden inputs skip native required).
                    // New minimal header: two text tabs with a sliding underline
                    // indicator on the left, a single "Clear" ghost button on
                    // the right. No fixed widths, no pill backgrounds, no grid
                    // — nothing that can drift on toggle. Each tab keeps its
                    // natural width; only colour + underline change on switch.
                    $reqAttr = $required ? ' data-sig-required="1"' : '';
                    echo '<div class="sig-pad" data-sig>';
                    echo   '<div class="sig-tabs">';
                    echo     '<div class="sig-seg" data-sig-active="draw" role="tablist">';
                    echo       '<button type="button" class="sig-tab is-active" data-sig-mode-btn="draw" role="tab" aria-selected="true">'
                            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><circle cx="11" cy="11" r="1.5"/></svg>'
                            . '<span>Draw</span></button>';
                    echo       '<button type="button" class="sig-tab" data-sig-mode-btn="type" role="tab" aria-selected="false">'
                            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>'
                            . '<span>Type</span></button>';
                    echo     '</div>';
                    echo     '<button type="button" class="sig-clear" data-sig-clear aria-label="Clear signature">'
                            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>'
                            . '<span>Clear</span></button>';
                    echo   '</div>';
                    echo   '<div class="sig-stage">';
                    echo     '<canvas id="' . e($id) . '" class="sig-canvas" width="640" height="200" role="img" aria-label="' . e($label) . ' signature pad"></canvas>';
                    echo     '<input type="text" class="sig-type-input" maxlength="60" placeholder="Type your full name" autocomplete="name" hidden>';
                    echo   '</div>';
                    // Repopulate after a failed submit so the signature isn't lost.
                    $sigInitial = (is_string($value) && str_starts_with($value, 'data:image/png;base64,')) ? $value : '';
                    echo   '<input type="hidden" name="' . e($name) . '" value="' . e($sigInitial) . '" data-sig-value' . $reqAttr . '>';
                    echo   '<input type="hidden" name="' . e($name) . '__mode" value="draw" data-sig-mode>';
                    echo   '<input type="hidden" name="' . e($name) . '__name" value="" data-sig-name>';
                    echo '</div>';   // close .sig-pad (hint goes BELOW the pad, not inside it)
                    echo   '<div class="field-hint">Sign with your mouse or finger, or switch to <strong>Type</strong>.</div>';
                    echo self::signatureAssetTag();
                    break;

                case 'radio':
                    if (!empty($field['card'])) { echo self::renderChoiceCards($field, $value, $id, $name, $required); break; }
                    echo '<div class="form-radio-group forms-check-group">';
                    foreach ((array)($field['options'] ?? []) as $i => $opt) {
                        $optId = $id . '_' . $i;
                        $sel = (is_string($value) && $value === $opt) ? ' checked' : '';
                        echo '<label class="forms-check" for="' . e($optId) . '">';
                        echo '<input type="radio" id="' . e($optId) . '" name="' . e($name) . '" value="' . e($opt) . '"'
                           . $sel . ($required ? ' required' : '') . '>';
                        echo '<span class="forms-check-label">' . e($opt) . '</span>';
                        echo '<span class="forms-check-badge" aria-hidden="true">' . self::cardIcon('check') . '</span>';
                        echo '</label>';
                    }
                    echo '</div>';
                    break;

                case 'checkboxes':
                    // Multi-select group: each option is its own checkbox, so
                    // several can be ticked. No `required` attribute on the
                    // inputs (that would force EVERY box); "at least one" is
                    // enforced server-side in validateSubmission().
                    $vals = is_array($value)
                        ? array_map('strval', $value)
                        : (($value !== '' && $value !== null) ? [(string)$value] : []);
                    echo '<div class="form-checkbox-group forms-check-group">';
                    foreach ((array)($field['options'] ?? []) as $i => $opt) {
                        $ov = is_array($opt) ? (string)($opt['value'] ?? '') : (string)$opt;
                        $ol = is_array($opt) ? (string)($opt['label'] ?? $ov) : (string)$opt;
                        $optId = $id . '_' . $i;
                        $sel = in_array($ov, $vals, true) ? ' checked' : '';
                        echo '<label class="forms-check" for="' . e($optId) . '">';
                        echo '<input type="checkbox" id="' . e($optId) . '" name="' . e($name) . '[]" value="' . e($ov) . '"' . $sel . '>';
                        echo '<span class="forms-check-label">' . e($ol) . '</span>';
                        echo '<span class="forms-check-badge" aria-hidden="true">' . self::cardIcon('check') . '</span>';
                        echo '</label>';
                    }
                    echo '</div>';
                    break;

                case 'calc':
                    $cv = is_string($value) ? $value : '';
                    echo '<output class="forms-calc-out" data-calc-out>' . e($cv !== '' ? $cv : '—') . '</output>';
                    echo '<input type="hidden" name="' . e($name) . '" value="' . e($cv) . '"'
                       . ' data-formula="' . e((string)($field['formula'] ?? '')) . '">';
                    echo '<div class="field-hint">Calculated automatically.</div>';
                    break;

                case 'range':
                    [$rmin, $rmax, $rstep] = self::rangeBounds($field);
                    $rval = is_string($value) && $value !== '' ? (float)$value : ($rmin + ($rmax - $rmin) / 2);
                    echo '<div class="forms-range">';
                    echo   '<input type="range" id="' . e($id) . '" name="' . e($name) . '"'
                       . ' min="' . e((string)$rmin) . '" max="' . e((string)$rmax) . '" step="' . e((string)$rstep) . '"'
                       . ' value="' . e((string)$rval) . '" oninput="this.nextElementSibling.value=this.value">';
                    echo   '<output>' . e((string)$rval) . '</output>';
                    echo '</div>';
                    break;

                case 'rating':
                    [, $rmaxStars] = self::rangeBounds($field);
                    $cur = is_string($value) && $value !== '' ? (int)$value : 0;
                    echo '<div class="forms-rating" role="radiogroup" aria-label="' . e($label) . '">';
                    for ($s = (int)$rmaxStars; $s >= 1; $s--) {
                        $rid = $id . '_' . $s;
                        echo '<input type="radio" id="' . e($rid) . '" name="' . e($name) . '" value="' . $s . '"'
                           . ($cur === $s ? ' checked' : '') . ($required ? ' required' : '') . '>';
                        echo '<label for="' . e($rid) . '" title="' . $s . ' / ' . (int)$rmaxStars . '">★</label>';
                    }
                    echo '</div>';
                    break;

                case 'intlphone':
                    // Country dial-code dropdown + number; combined server-side
                    // in validateSubmission(). Honours `intl_countries` (admin
                    // allowlist) and `intl_default` (preselected dial code).
                    // Each <option> carries data-p (input mask) and data-ex
                    // (example) so the inline JS can live-format the number
                    // and swap the placeholder when the country changes.
                    $allow = (array)($field['intl_countries'] ?? []);
                    $def   = (string)($field['intl_default'] ?? '');
                    $list  = self::phoneCountries();
                    if ($allow) {
                        $list = array_values(array_filter($list, fn($c) => in_array($c['d'], $allow, true)));
                    }
                    if (!$list) $list = self::phoneCountries();   // safety: never render an empty dropdown
                    // Pick the selected dial code: explicit default → first allowed → built-in def flag.
                    $sel = '';
                    if ($def !== '') {
                        foreach ($list as $c) if ($c['d'] === $def) { $sel = $def; break; }
                    }
                    if ($sel === '') {
                        foreach ($list as $c) if (!empty($c['def'])) { $sel = $c['d']; break; }
                    }
                    if ($sel === '') $sel = (string)$list[0]['d'];

                    // Resolve the selected country's pattern + example for the
                    // initial placeholder, so the field looks "filled in" even
                    // before any JS runs.
                    $selRow = null;
                    foreach ($list as $c) if ($c['d'] === $sel) { $selRow = $c; break; }
                    $selEx = $selRow['ex'] ?? '';
                    $selPh = $phAttr;                              // admin placeholder takes priority…
                    if ($selPh === '' && $selEx !== '') {          // …otherwise show the example.
                        $selPh = ' placeholder="' . e($selEx) . '"';
                    }

                    $selFlag = (string)($selRow['f'] ?? '');
                    echo '<div class="forms-intl">';
                    // Visible chip: flag + dial code + caret. Kept in sync by JS.
                    echo   '<span class="forms-intl-chip" aria-hidden="true">'
                         .   '<span class="forms-intl-flag">' . e($selFlag) . '</span>'
                         .   '<span class="forms-intl-code">' . e($sel) . '</span>'
                         .   '<svg class="forms-intl-caret" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto"><polyline points="6 9 12 15 18 9"/></svg>'
                         . '</span>';
                    // Invisible native select layered over the chip — picker UX.
                    echo   '<select class="forms-intl-cc" name="' . e($name) . '__cc" aria-label="Country dial code">';
                    foreach ($list as $c) {
                        $isSel = ($c['d'] === $sel);
                        echo '<option value="' . e($c['d']) . '"'
                           . ' data-p="' . e((string)($c['p'] ?? '')) . '"'
                           . ' data-ex="' . e((string)($c['ex'] ?? '')) . '"'
                           . ' data-flag="' . e((string)($c['f'] ?? '')) . '"'
                           . ($isSel ? ' selected' : '')
                           . '>' . e($c['f'] . ' ' . $c['d'] . ' · ' . $c['n']) . '</option>';
                    }
                    echo   '</select>';
                    echo   '<input type="tel" id="' . e($id) . '" name="' . e($name) . '" inputmode="tel"'
                         . ($valueAttr !== '' ? ' value="' . $valueAttr . '"' : '') . $req . $selPh . '>';
                    echo '</div>';
                    // Emit the live-format script once per page (idempotent: the
                    // outer `if (window.__slIntlInit) return` guards re-runs when
                    // multiple intlphone fields share a page).
                    if (empty(self::$intlAssetPrinted)) {
                        self::$intlAssetPrinted = true;
                        echo '<script>(function(){if(window.__slIntlInit)return;window.__slIntlInit=1;'
                           . 'function fmt(d,p){if(!p)return d;var o="",i=0;for(var k=0;k<p.length&&i<d.length;k++){'
                           . 'var ch=p[k];if(ch=="#"){o+=d[i++];}else{o+=ch;}}return o;}'
                           . 'function apply(sel){var wrap=sel.parentNode;if(!wrap)return;'
                           . 'var inp=wrap.querySelector("input[type=tel]");var chip=wrap.querySelector(".forms-intl-chip");'
                           . 'var opt=sel.options[sel.selectedIndex];if(!opt)return;'
                           . 'var p=opt.dataset.p||"",ex=opt.dataset.ex||"",fl=opt.dataset.flag||"",cd=opt.value||"";'
                           . 'if(inp){inp.dataset.p=p;if(ex&&!inp.dataset.phLocked)inp.placeholder=ex;'
                           . 'inp.value=fmt(inp.value.replace(/\\D/g,""),p);}'
                           . 'if(chip){var f=chip.querySelector(".forms-intl-flag"),c=chip.querySelector(".forms-intl-code");'
                           . 'if(f)f.textContent=fl;if(c)c.textContent=cd;'
                           // Size the invisible select to match the chip so its
                           // click target is exactly the chip — not the whole row.
                           . 'sel.style.width=chip.offsetWidth+"px";}}'
                           . 'document.addEventListener("input",function(e){var t=e.target;'
                           . 'if(t&&t.matches&&t.matches(".forms-intl input[type=tel]")){'
                           . 'var p=t.dataset.p||"";if(!p)return;var d=t.value.replace(/\\D/g,"");'
                           . 'var max=(p.match(/#/g)||[]).length;if(max)d=d.slice(0,max);'
                           . 'var caretEnd=t.selectionStart===t.value.length;'
                           . 't.value=fmt(d,p);if(caretEnd)try{t.setSelectionRange(t.value.length,t.value.length);}catch(_){}}});'
                           . 'document.addEventListener("change",function(e){if(e.target&&e.target.classList&&e.target.classList.contains("forms-intl-cc"))apply(e.target);});'
                           . 'document.querySelectorAll(".forms-intl-cc").forEach(apply);'
                           . 'window.addEventListener("resize",function(){document.querySelectorAll(".forms-intl-cc").forEach(apply);});'
                           . '})();</script>';
                    }
                    break;

                case 'address':
                    // Address autocomplete via the free OpenStreetMap Nominatim
                    // API (no key required, ~1 req/s policy enforced by the
                    // 300ms debounce in the inline JS below). The input remains
                    // freeform-editable; selecting a suggestion just fills it.
                    $cc = trim((string)($field['addr_countries'] ?? ''));
                    echo '<div class="forms-addr" data-cc="' . e($cc) . '">';
                    echo   '<span class="forms-inp-ico">'
                         .   '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s-7-6.2-7-12a7 7 0 1 1 14 0c0 5.8-7 12-7 12z"/><circle cx="12" cy="10" r="2.6"/></svg>'
                         . '</span>';
                    echo   '<input type="text" id="' . e($id) . '" name="' . e($name) . '"'
                         . ($valueAttr !== '' ? ' value="' . $valueAttr . '"' : '')
                         . $req . $phAttr . ' class="has-ico forms-addr-input" autocomplete="off"'
                         . ' aria-autocomplete="list" aria-expanded="false" role="combobox">';
                    echo   '<div class="forms-addr-list" role="listbox" hidden></div>';
                    echo '</div>';
                    if (empty(self::$addrAssetPrinted)) {
                        self::$addrAssetPrinted = true;
                        echo '<script>(function(){if(window.__slAddrInit)return;window.__slAddrInit=1;'
                           // Per-input state held in a WeakMap so multiple address
                           // fields on one page don't trample each other.
                           . 'var S=new WeakMap();var URL_="https://nominatim.openstreetmap.org/search";'
                           . 'function debounce(fn,ms){var t;return function(){var a=arguments,c=this;clearTimeout(t);t=setTimeout(function(){fn.apply(c,a);},ms);};}'
                           . 'function esc(s){return String(s).replace(/[&<>\"]/g,function(m){return{"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[m];});}'
                           . 'function close_(st){st.list.hidden=true;st.list.innerHTML="";st.idx=-1;st.input.setAttribute("aria-expanded","false");}'
                           . 'function paint(st){var rows=st.list.children;for(var i=0;i<rows.length;i++){rows[i].setAttribute("aria-selected",i===st.idx?"true":"false");rows[i].classList.toggle("is-on",i===st.idx);}}'
                           . 'function pick(st,r){st.input.value=r.display_name;close_(st);st.input.dispatchEvent(new Event("input",{bubbles:true}));st.input.dispatchEvent(new Event("change",{bubbles:true}));}'
                           . 'function search(st,q){if(!q||q.length<3){close_(st);return;}'
                           . 'if(st.ctrl)st.ctrl.abort();st.ctrl=("AbortController" in window)?new AbortController():null;'
                           . 'var p=new URLSearchParams({q:q,format:"json",addressdetails:"0",limit:"6","accept-language":(navigator.language||"en")});'
                           . 'var cc=st.wrap.dataset.cc||"";if(cc)p.set("countrycodes",cc);'
                           . 'fetch(URL_+"?"+p.toString(),{signal:st.ctrl&&st.ctrl.signal,headers:{"Accept":"application/json"}})'
                           . '.then(function(r){return r.ok?r.json():[];}).then(function(arr){'
                           . 'if(!Array.isArray(arr)||!arr.length){close_(st);return;}'
                           . 'st.list.innerHTML=arr.map(function(r){return "<div role=\\"option\\" class=\\"forms-addr-row\\">"+esc(r.display_name)+"</div>";}).join("");'
                           // Wire click/hover after the rows mount.
                           . 'Array.prototype.forEach.call(st.list.children,function(el,i){'
                           . 'el.addEventListener("mousedown",function(e){e.preventDefault();pick(st,arr[i]);});'
                           . 'el.addEventListener("mouseenter",function(){st.idx=i;paint(st);});});'
                           . 'st.results=arr;st.idx=-1;st.list.hidden=false;st.input.setAttribute("aria-expanded","true");'
                           . '}).catch(function(){});'
                           . '}'
                           . 'function init(wrap){if(S.has(wrap))return;var inp=wrap.querySelector(".forms-addr-input"),list=wrap.querySelector(".forms-addr-list");if(!inp||!list)return;'
                           . 'var st={wrap:wrap,input:inp,list:list,idx:-1,results:[],ctrl:null};S.set(wrap,st);'
                           . 'var run=debounce(function(){search(st,inp.value.trim());},320);'
                           . 'inp.addEventListener("input",run);'
                           . 'inp.addEventListener("focus",function(){if(inp.value.trim().length>=3)run();});'
                           . 'inp.addEventListener("keydown",function(e){if(list.hidden)return;'
                           . 'var n=st.list.children.length;'
                           . 'if(e.key==="ArrowDown"){e.preventDefault();st.idx=(st.idx+1)%n;paint(st);}'
                           . 'else if(e.key==="ArrowUp"){e.preventDefault();st.idx=(st.idx-1+n)%n;paint(st);}'
                           . 'else if(e.key==="Enter"){if(st.idx>=0){e.preventDefault();pick(st,st.results[st.idx]);}}'
                           . 'else if(e.key==="Escape"){close_(st);}});'
                           . 'document.addEventListener("click",function(e){if(!wrap.contains(e.target))close_(st);});'
                           . '}'
                           . 'document.querySelectorAll(".forms-addr").forEach(init);'
                           // Late-mounted fields (conditional show_if, multi-step): re-scan on visibility change.
                           . 'document.addEventListener("forms:rerender",function(){document.querySelectorAll(".forms-addr").forEach(init);});'
                           . '})();</script>';
                    }
                    break;

                default:
                    $inputType = $type;
                    if (!in_array($type, ['text','email','tel','url','number','date','time'], true)) {
                        $inputType = 'text';
                    }
                    $ico = self::fieldInputIcon($field);
                    // date/time have a native picker control on the right, so the
                    // validation check would overlap it — skip the check there.
                    $native = in_array($inputType, ['date', 'time'], true);
                    echo '<div class="forms-inp' . ($native ? ' forms-inp-native' : '') . '">';
                    if ($ico !== '') echo '<span class="forms-inp-ico">' . self::cardIcon($ico) . '</span>';
                    echo '<input type="' . e($inputType) . '" id="' . e($id) . '" name="' . e($name) . '"'
                       . ($valueAttr !== '' ? ' value="' . $valueAttr . '"' : '')
                       . $req . $phAttr . ($ico !== '' ? ' class="has-ico"' : '') . '>';
                    if (!$native) echo '<span class="forms-inp-status" aria-hidden="true"></span>';
                    echo '</div>';
                    break;
            }
        }

        if ($error !== null && $error !== '') {
            echo '<div class="field-error">' . e($error) . '</div>';
        }

        echo '</div>';
        return (string) ob_get_clean();
    }

    /**
     * Per-form appearance settings (decoded from forms_definitions.settings_json),
     * with sane defaults. Controls the public form's look + behaviour.
     */
    public static function formSettings(?string $json): array {
        $s = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        if (!is_array($s)) $s = [];
        return [
            'density'      => (($s['density'] ?? 'compact') === 'comfortable') ? 'comfortable' : 'compact',
            'animate'      => array_key_exists('animate',  $s) ? (bool)$s['animate']  : true,
            'validate'     => array_key_exists('validate', $s) ? (bool)$s['validate'] : true,
            'rail'         => array_key_exists('rail',     $s) ? (bool)$s['rail']     : true,
            'accent'       => self::sanitizeHex((string)($s['accent'] ?? '')),
            'accent_hover' => self::sanitizeHex((string)($s['accent_hover'] ?? '')),
            'field_style'  => (($s['field_style'] ?? 'outline') === 'filled') ? 'filled' : 'outline',
            'shape'        => in_array($s['shape'] ?? 'rounded', ['rounded', 'pill', 'square'], true) ? ($s['shape'] ?? 'rounded') : 'rounded',
            'labels'       => (($s['labels'] ?? 'show') === 'hide') ? 'hide' : 'show',
            // Full-width form: drop the centred max-width so the form fills its
            // container (best when embedded in a wide page column).
            'full_width'   => array_key_exists('full_width', $s) ? (bool)$s['full_width'] : false,
            // Scrollable fields region: when ON (default) the fields sit in a
            // fixed-height scroll area (nice for long multi-step wizards). Turn
            // OFF to let the form flow at its full natural height (recommended
            // for embeds, and to avoid a reserved-height gap under the button).
            'scroll_fields'=> array_key_exists('scroll_fields', $s) ? (bool)$s['scroll_fields'] : true,
            // ── Spacing controls (all null = use the theme defaults). Let the
            //    owner dial in padding/gaps so the form fits any layout. ──
            'pad'       => (isset($s['pad'])       && is_numeric($s['pad']))       ? max(0, min(80, (int)$s['pad']))       : null,
            'field_gap' => (isset($s['field_gap']) && is_numeric($s['field_gap'])) ? max(0, min(60, (int)$s['field_gap'])) : null,
            'col_gap'   => (isset($s['col_gap'])   && is_numeric($s['col_gap']))   ? max(0, min(80, (int)$s['col_gap']))   : null,
            // Review/summary step shown before final submit (admin toggle).
            'summary'      => array_key_exists('summary', $s) ? (bool)$s['summary'] : false,
            // Attach a branded PDF of the submission to the admin + submitter
            // emails. Off by default so existing forms are unchanged.
            'pdf_attach'   => array_key_exists('pdf_attach', $s) ? (bool)$s['pdf_attach'] : false,
            // PDF page size for the generated submission document.
            'pdf_page'     => in_array($s['pdf_page'] ?? 'a4', ['a4', 'letter', 'legal'], true) ? ($s['pdf_page'] ?? 'a4') : 'a4',
            // When to expose the "Save PDF" download on the submission view.
            'pdf_save_btn' => in_array($s['pdf_save_btn'] ?? 'always', ['always', 'never'], true) ? ($s['pdf_save_btn'] ?? 'always') : 'always',
            // Always print a signature area on the PDF. When on (default), a form
            // with NO signature field still gets a blank signature line so the
            // document can be signed by hand. When off, the execution block is
            // omitted unless a signature was actually captured on the form.
            'pdf_sign'      => array_key_exists('pdf_sign', $s) ? (bool)$s['pdf_sign'] : true,
            // Require signatures from BOTH parties. When on, the PDF prints the
            // two-column Client + Company execution block (the original layout).
            // When off, only a single signer line is printed.
            'pdf_sign_both' => array_key_exists('pdf_sign_both', $s) ? (bool)$s['pdf_sign_both'] : false,
            // E-Signature sender profile (printed on the agreement PDF).
            'sender'       => [
                'name'    => trim((string)($s['sender']['name']    ?? '')),
                'company' => trim((string)($s['sender']['company'] ?? '')),
                'email'   => trim((string)($s['sender']['email']   ?? '')),
                'sig'     => (is_string($s['sender']['sig'] ?? null) && str_starts_with((string)($s['sender']['sig'] ?? ''), 'data:image/png;base64,')) ? $s['sender']['sig'] : '',
            ],
            // Admin notification email template — per-form overrides for the
            // subject + the body chrome (intro paragraph above the field table,
            // outro below it, CTA button label, eyebrow header). Empty strings
            // mean "use the defaults" baked into submissionEmailHtml().
            // Supports {{form.title}}, {{form.slug}}, {{ref}}, {{date}},
            // {{submitter}}, {{data.field_name}} placeholders.
            'email_subject'      => trim((string)($s['email_subject']      ?? '')),
            'email_header_label' => trim((string)($s['email_header_label'] ?? '')),
            'email_intro'        => trim((string)($s['email_intro']        ?? '')),
            'email_outro'        => trim((string)($s['email_outro']        ?? '')),
            'email_cta_label'    => trim((string)($s['email_cta_label']    ?? '')),
            'email_show_table'   => array_key_exists('email_show_table', $s) ? (bool)$s['email_show_table'] : true,
            // PDF customizer — per-form overrides for the title, the recital
            // paragraph under the title, and an optional footer note printed
            // alongside the business line.
            'pdf_heading'        => trim((string)($s['pdf_heading']     ?? '')),
            'pdf_intro'          => trim((string)($s['pdf_intro']       ?? '')),
            'pdf_footer_note'    => trim((string)($s['pdf_footer_note'] ?? '')),
            // Anti-spam / security (CAPTCHA, country, content filter, rate
            // limit, time-trap). Normalised to a safe shape — see FormsSpamGuard.
            'spam'         => FormsSpamGuard::normalize($s['spam'] ?? []),
        ];
    }

    /**
     * Substitute {{form.title}}, {{form.slug}}, {{ref}}, {{date}},
     * {{submitter}}, {{data.field_name}} placeholders in an admin-authored
     * email/PDF template. Unknown placeholders are left as-is so a typo
     * doesn't silently disappear. Values are returned verbatim — the caller
     * is responsible for HTML-escaping if the surrounding context demands it.
     */
    public static function renderTemplate(string $tpl, array $form, array $data, string $ref, string $submitterEmail = '', string $submittedAt = ''): string {
        if ($tpl === '') return '';
        $ts = $submittedAt !== '' ? strtotime($submittedAt) : time();
        if ($ts === false) $ts = time();
        $vars = [
            'form.title' => (string)($form['title'] ?? ''),
            'form.slug'  => (string)($form['slug']  ?? ''),
            'ref'        => $ref,
            'date'       => I18n::localDate('j M Y, H:i', $ts),
            'submitter'  => $submitterEmail,
        ];
        return preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', function ($m) use ($vars, $data) {
            $key = $m[1];
            if (array_key_exists($key, $vars)) return $vars[$key];
            if (str_starts_with($key, 'data.')) {
                $field = substr($key, 5);
                $v = $data[$field] ?? null;
                if ($v === null) return '';
                if (is_bool($v))  return $v ? 'Yes' : 'No';
                if (is_array($v)) {
                    if (!empty($v['name']))  return (string)$v['name'];
                    if (isset($v['path']))   return (string)$v['path'];
                    return implode(', ', array_map('strval', $v));
                }
                return (string)$v;
            }
            return $m[0]; // unknown — leave the literal so the typo is visible
        }, $tpl) ?? '';
    }

    /**
     * Build a branded submission PDF (bytes) for a form + decoded data row.
     * Single entry point used by both the public submit path (email
     * attachment) and the admin "Download PDF" action. Branding is pulled
     * from the global site settings; the accent prefers the form's own
     * appearance accent, then the brand accent.
     *
     * @param array $form forms_definitions row (with `fields` or `fields_json`)
     */
    public static function submissionPdf(array $form, array $data, string $ref): string {
        require_once __DIR__ . '/lib/FormsPdf.php';

        if (!isset($form['fields']) || !is_array($form['fields'])) {
            $form['fields'] = self::decodeFields((string)($form['fields_json'] ?? ''));
        }

        $set   = self::formSettings($form['settings_json'] ?? null);
        $brand = FormsPdf::brandFromSettings();

        // Resolve placeholder-bearing PDF strings here so FormsPdf stays
        // ignorant of the template engine.
        $submitter = '';
        foreach (($form['fields'] ?? []) as $f) {
            if (($f['type'] ?? '') === 'email') {
                $v = $data[$f['name'] ?? ''] ?? '';
                if (is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL)) { $submitter = $v; break; }
            }
        }
        $renderTpl = fn(string $t): string => $t === ''
            ? '' : self::renderTemplate($t, $form, $data, $ref, $submitter);

        return FormsPdf::render($form, $data, $ref, [
            'brand'       => $brand,
            'accent'      => $set['accent'] !== '' ? $set['accent'] : ($brand['accent'] ?? ''),
            'sender'      => $set['sender'] ?? [],
            'page'        => $set['pdf_page'] ?? 'a4',
            'sign'        => $set['pdf_sign'] ?? true,
            'sign_both'   => $set['pdf_sign_both'] ?? false,
            'heading'     => $renderTpl((string)($set['pdf_heading']     ?? '')),
            'intro'       => $renderTpl((string)($set['pdf_intro']       ?? '')),
            'footer_note' => $renderTpl((string)($set['pdf_footer_note'] ?? '')),
        ]);
    }

    /**
     * Branded HTML email for a new form submission (admin notification).
     *
     * A self-contained, email-client-safe document (table layout + inline
     * styles): an accent header band with the site logo, the form title +
     * reference + date, an optional "View submission" button, and a
     * zebra-striped table of every submitted field. Branding is pulled from
     * the global site settings; the accent prefers the form's own accent.
     *
     * @param array $form forms_definitions row (with `fields` or `fields_json`)
     * @param array $opts ['brand'=>[...], 'accent'=>'#hex', 'submitted_at'=>'Y-m-d H:i:s', 'admin_url'=>'…']
     */
    public static function submissionEmailHtml(array $form, array $data, string $ref, array $opts = []): string {
        require_once __DIR__ . '/lib/FormsPdf.php';

        if (!isset($form['fields']) || !is_array($form['fields'])) {
            $form['fields'] = self::decodeFields((string)($form['fields_json'] ?? ''));
        }

        [$brand, $accent, $accentDeep, $onAccent] = self::emailAccent($opts);
        $sansFont = '-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif';

        $title = (string)($form['title'] ?? __('forms_email_default_title', 'Form submission'));
        $when  = I18n::localDate('j M Y, H:i', strtotime((string)($opts['submitted_at'] ?? 'now')) ?: time());
        $base  = defined('SLATE_URL') ? rtrim(SLATE_URL, '/') : '';
        $admin = trim((string)($opts['admin_url'] ?? ''));

        // Submitter email (shown as a meta chip when present).
        $submitter = '';
        foreach ($form['fields'] as $f) {
            if (($f['type'] ?? '') === 'email') {
                $v = $data[$f['name'] ?? ''] ?? '';
                if (is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL)) { $submitter = $v; break; }
            }
        }

        // Admin-authored template overrides (intro/outro/header/CTA/show-table).
        // Each is run through renderTemplate() so {{form.title}}, {{ref}},
        // {{data.field_name}} etc. resolve. Defaults preserve the original
        // notification design exactly.
        $tpl       = is_array($opts['tpl'] ?? null) ? $opts['tpl'] : [];
        $submittedAt = (string)($opts['submitted_at'] ?? 'now');
        $headerLbl = trim((string)($tpl['header_label'] ?? '')) !== ''
                   ? self::renderTemplate((string)$tpl['header_label'], $form, $data, $ref, $submitter, $submittedAt)
                   : __('forms_email_new_submission', 'New form submission');
        $introHtml = trim((string)($tpl['intro'] ?? '')) !== ''
                   ? self::renderTemplate((string)$tpl['intro'], $form, $data, $ref, $submitter, $submittedAt)
                   : '';
        $outroHtml = trim((string)($tpl['outro'] ?? '')) !== ''
                   ? self::renderTemplate((string)$tpl['outro'], $form, $data, $ref, $submitter, $submittedAt)
                   : '';
        $ctaLabel  = trim((string)($tpl['cta_label'] ?? '')) !== ''
                   ? self::renderTemplate((string)$tpl['cta_label'], $form, $data, $ref, $submitter, $submittedAt)
                   : __('forms_email_view_submission', 'View submission');
        $showTable = !array_key_exists('show_table', $tpl) || (bool)$tpl['show_table'];

        // ── Field rows (zebra-striped) ──────────────────────────────────────
        $rows = ''; $i = 0;
        foreach ($form['fields'] as $f) {
            $type = (string)($f['type'] ?? '');
            if (in_array($type, ['hidden', 'disclaimer'], true)) continue;

            // Render structural headings/steps as full-width section rows.
            if ($type === 'heading' || $type === 'step') {
                $h = trim((string)($f['label'] ?? ''));
                if ($h === '') continue;
                $rows .= '<tr><td colspan="2" class="fe-sep" style="padding:18px 24px 6px;font:700 11px/1.4 -apple-system,BlinkMacSystemFont,'
                       . '&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif;letter-spacing:0.08em;text-transform:uppercase;'
                       . 'color:' . e($accentDeep) . ';border-bottom:1px solid #eef0f4;">' . e($h) . '</td></tr>';
                continue;
            }

            $name = (string)($f['name'] ?? '');
            if ($name === '') continue;
            $label = trim((string)($f['label'] ?? '')) ?: $name;
            $cell  = self::emailFieldCell($data[$name] ?? '', $base);
            $bg    = ($i % 2 === 1) ? '#f9fafb' : '#ffffff';
            $zeb   = ($i % 2 === 1) ? 'fe-z1' : 'fe-z0';
            $rows .= '<tr class="' . $zeb . '">'
                  . '<td class="fe-label" style="padding:11px 24px;width:38%;vertical-align:top;background:' . $bg . ';'
                  . 'font:600 13px/1.5 -apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif;'
                  . 'color:#475569;border-bottom:1px solid #eef0f4;">' . e($label) . '</td>'
                  . '<td class="fe-val" style="padding:11px 24px;vertical-align:top;background:' . $bg . ';'
                  . 'font:400 14px/1.55 -apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif;'
                  . 'color:#0f172a;border-bottom:1px solid #eef0f4;">' . $cell . '</td>'
                  . '</tr>';
            $i++;
        }
        if ($rows === '') {
            $rows = '<tr><td style="padding:16px 24px;font:400 14px/1.5 -apple-system,Helvetica,Arial,sans-serif;color:#94a3b8;">'
                  . e(__('forms_email_no_fields', 'No fields were submitted.')) . '</td></tr>';
        }

        // ── Meta chips (reference · date · submitter) ───────────────────────
        $chip = function (string $label, string $value) {
            return '<span class="fe-chip" style="display:inline-block;margin:0 8px 8px 0;padding:5px 11px;background:#f1f5f9;border-radius:999px;'
                 . 'font:600 12px/1.3 -apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif;'
                 . 'color:#334155;">' . e($label) . ' <span style="color:#0f172a;font-weight:700;">' . e($value) . '</span></span>';
        };
        $chips = $chip(__('forms_email_ref', 'Ref'), $ref) . $chip(__('forms_email_received', 'Received'), $when)
               . ($submitter !== '' ? $chip(__('forms_email_from', 'From'), $submitter) : '');

        // ── CTA button (only when we have an admin link) ────────────────────
        $cta = '';
        if ($admin !== '') {
            $cta = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0 4px;">'
                 . '<tr><td bgcolor="' . e($accent) . '" style="border-radius:10px;">'
                 . '<a href="' . e($admin) . '" style="display:inline-block;padding:12px 22px;'
                 . 'font:700 14px/1 -apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif;'
                 . 'color:' . $onAccent . ';text-decoration:none;border-radius:10px;">' . e($ctaLabel) . ' &rarr;</a>'
                 . '</td></tr></table>';
        }

        // Admin-authored intro/outro paragraphs. Newlines collapse to <br> so
        // simple multi-line copy looks right without forcing the admin to
        // write HTML.
        $nl2br = static function (string $s): string { return nl2br(e($s)); };
        $introBlock = $introHtml !== ''
            ? '<tr><td class="fe-pad fe-text" style="padding:0 28px 14px;font:400 14.5px/1.6 ' . $sansFont . ';color:#334155;">' . $nl2br($introHtml) . '</td></tr>'
            : '';
        $outroBlock = $outroHtml !== ''
            ? '<tr><td class="fe-pad fe-text" style="padding:14px 28px 4px;font:400 14.5px/1.6 ' . $sansFont . ';color:#334155;">' . $nl2br($outroHtml) . '</td></tr>'
            : '';
        $tableBlock = $showTable
            ? '<tr><td class="fe-pad" style="padding:14px 28px 0;">'
              . '<div class="fe-sep" style="font:700 11px/1.4 ' . $sansFont . ';letter-spacing:0.08em;text-transform:uppercase;color:#94a3b8;border-top:1px solid #eef0f4;padding-top:16px;">' . e(__('forms_email_submission_details', 'Submission details')) . '</div>'
              . '</td></tr>'
              . '<tr><td style="padding:10px 4px 6px;">'
              . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">' . $rows . '</table>'
              . '</td></tr>'
            : '';

        // ── Inner card rows: header + title + meta + CTA, intro, table, outro ─
        $inner =
              '<tr><td class="fe-pad" style="padding:26px 28px 8px;">'
            . '<div style="font:700 11px/1.4 ' . $sansFont . ';letter-spacing:0.1em;text-transform:uppercase;color:' . e($accentDeep) . ';margin-bottom:6px;">' . e($headerLbl) . '</div>'
            . '<h1 class="fe-title" style="margin:0 0 12px;font:700 22px/1.25 ' . $sansFont . ';color:#0f172a;letter-spacing:-0.02em;">' . e($title) . '</h1>'
            . '<div>' . $chips . '</div>'
            . $cta
            . '</td></tr>'
            . $introBlock
            . $tableBlock
            . $outroBlock;

        return self::emailShell([
            'brand'      => $brand,
            'accent'     => $accent,
            'title'      => $title,
            'preheader'  => sprintf(__('forms_email_preheader', 'New submission on %s (%s)'), $title, $ref),
            'footer_ref' => sprintf(__('forms_email_footer_ref', 'Reference %s'), $ref),
        ], $inner);
    }

    /**
     * Branded submitter confirmation email. Wraps the admin-authored (or
     * default) confirmation message in the same branded shell as the
     * notification, so the reply the submitter receives is on-brand and
     * consistent. The body HTML is admin-authored and inserted as trusted.
     *
     * @param string $bodyHtml confirmation message HTML (admin-authored)
     * @param array  $opts ['brand'=>[...], 'accent'=>'#hex', 'heading'=>'…']
     */
    public static function confirmationEmailHtml(array $form, string $ref, string $bodyHtml, array $opts = []): string {
        [$brand, $accent, $accentDeep] = self::emailAccent($opts);
        $sansFont = '-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif';

        $title = trim((string)($opts['heading'] ?? '')) ?: (string)($form['title'] ?? __('forms_email_thank_you', 'Thank you'));
        $bodyHtml = trim($bodyHtml);
        if ($bodyHtml === '') $bodyHtml = '<p>' . e(__('forms_email_default_thanks', 'Thanks — we\'ve received your submission.')) . '</p>';

        $inner =
              '<tr><td class="fe-pad" style="padding:26px 28px 2px;">'
            . '<div style="font:700 11px/1.4 ' . $sansFont . ';letter-spacing:0.1em;text-transform:uppercase;color:' . e($accentDeep) . ';margin-bottom:6px;">' . e(__('forms_email_confirmation', 'Confirmation')) . '</div>'
            . '<h1 class="fe-title" style="margin:0;font:700 22px/1.25 ' . $sansFont . ';color:#0f172a;letter-spacing:-0.02em;">' . e($title) . '</h1>'
            . '</td></tr>'
            . '<tr><td class="fe-pad fe-text" style="padding:8px 28px 22px;font:400 15px/1.65 ' . $sansFont . ';color:#334155;">'
            . $bodyHtml
            . '<div class="fe-sep" style="margin-top:18px;padding-top:14px;border-top:1px solid #eef0f4;font-size:13px;color:#94a3b8;">'
            . e(__('forms_email_your_reference', 'Your reference:')) . ' <strong style="color:#475569;">' . e($ref) . '</strong></div>'
            . '</td></tr>';

        return self::emailShell([
            'brand'        => $brand,
            'accent'       => $accent,
            'title'        => $title,
            'preheader'    => $title,
            'header_label' => __('forms_email_confirmation', 'Confirmation'),
            'footer_ref'   => sprintf(__('forms_email_footer_ref', 'Reference %s'), $ref),
        ], $inner);
    }

    /**
     * Resolve the email accent palette from $opts/brand.
     * @return array{0:array,1:string,2:string,3:string} [brand, accent#hex, accentDeep#hex, onAccent(#fff|#0f172a)]
     */
    private static function emailAccent(array $opts): array {
        require_once __DIR__ . '/lib/FormsPdf.php';
        $brand = is_array($opts['brand'] ?? null) ? $opts['brand'] : FormsPdf::brandFromSettings();
        return \BrandedEmail::accent(array_merge($opts, ['brand' => $brand]));
    }

    /**
     * Shared branded email chrome used by both the submission notification and
     * the submitter confirmation: <head> + styles, the accent header band
     * (logo or site name + a header label), the caller's inner <tr> rows, and
     * the footer (business line + "Sent by X Forms"). $inner must be a run of
     * <tr>…</tr> rows that slot into the 600px card table.
     *
     * Delegates to the shared \BrandedEmail core component (promoted out of
     * this exact method) so Forms' emails render byte-for-byte the same as
     * before the promotion. 'Form submission' preserves this plugin's own
     * historical header-band fallback (BrandedEmail's own generic default is
     * the site name), and 'sender_label' => 'Forms' reproduces the existing
     * "Sent by {site} Forms" footer line.
     *
     * @param array $opts ['brand','accent','title','preheader','header_label','footer_ref']
     */
    private static function emailShell(array $opts, string $inner): string {
        require_once __DIR__ . '/lib/FormsPdf.php';
        $brand    = is_array($opts['brand'] ?? null) ? $opts['brand'] : FormsPdf::brandFromSettings();
        $hdrLabel = trim((string)($opts['header_label'] ?? ''));
        if ($hdrLabel === '') {
            $hdrLabel = trim((string)($brand['sublabel'] ?? '')) ?: __('forms_email_default_title', 'Form submission');
        }
        return \BrandedEmail::shell(array_merge($opts, [
            'brand'        => $brand,
            'header_label' => $hdrLabel,
            'sender_label' => __('forms', 'Forms'),
        ]), $inner);
    }

    /** Format one submission value as an email-safe HTML cell. */
    private static function emailFieldCell($val, string $base): string {
        if (is_bool($val)) {
            return $val ? e(__('yes', 'Yes')) : e(__('no', 'No'));
        }
        if (is_array($val) && !empty($val['signature'])) {
            $url  = $base . (string)($val['path'] ?? '');
            $html = '<img src="' . e($url) . '" alt="' . e(__('forms_email_signature', 'Signature')) . '" style="display:block;max-width:240px;height:auto;border:1px solid #e6e9ef;border-radius:8px;">';
            if (!empty($val['name'])) {
                $html .= '<div style="margin-top:4px;color:#94a3b8;font-size:12px;">' . e((string)$val['name']) . '</div>';
            }
            return $html;
        }
        if (is_array($val) && isset($val['path'])) {
            $url  = $base . (string)$val['path'];
            $name = (string)($val['original'] ?? basename((string)$val['path']));
            return '<a href="' . e($url) . '" style="color:inherit;font-weight:600;">' . e($name) . '</a>';
        }
        if (is_array($val)) {
            return nl2br(e(implode(', ', array_map(fn($v) => is_scalar($v) ? (string)$v : json_encode($v), $val))));
        }
        $s = trim((string)$val);
        return $s === '' ? '<span style="color:#cbd5e1;">—</span>' : nl2br(e($s));
    }

    /** Lighten (positive) or darken (negative) a #rrggbb colour by a 0–1 ratio. */
    private static function shadeHex(string $hex, float $ratio): string {
        [$r, $g, $b] = self::hexToRgb(self::sanitizeHex($hex) ?: '#111111');
        $adj = function (int $c) use ($ratio): int {
            $c = $ratio < 0 ? $c * (1 + $ratio) : $c + (255 - $c) * $ratio;
            return max(0, min(255, (int)round($c)));
        };
        return sprintf('#%02x%02x%02x', $adj($r), $adj($g), $adj($b));
    }

    /** Validate a #rrggbb colour; return '' if not a valid hex. */
    public static function sanitizeHex(string $s): string {
        $s = trim($s);
        return preg_match('/^#?([0-9a-f]{6})$/i', $s, $m) ? '#' . strtolower($m[1]) : '';
    }

    private static function hexToRgb(string $hex): array {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /** Darken a hex colour by a fraction (0..1). */
    public static function darkenHex(string $hex, float $amt): string {
        [$r, $g, $b] = self::hexToRgb($hex);
        return sprintf('#%02x%02x%02x', max(0, (int)round($r * (1 - $amt))),
                       max(0, (int)round($g * (1 - $amt))), max(0, (int)round($b * (1 - $amt))));
    }

    /**
     * Build the public-form card's class + style attributes from settings:
     * density / field style / corner shape / hidden labels + custom accent
     * (overrides the --f-accent / --f-accent-d / --f-ring CSS variables).
     */
    public static function publicCardAttrs(array $set): string {
        $cls = 'forms-public-card';
        if (($set['density'] ?? '') === 'compact')   $cls .= ' forms-density-compact';
        if (($set['field_style'] ?? '') === 'filled') $cls .= ' forms-fields-filled';
        if (($set['shape'] ?? '') === 'pill')         $cls .= ' forms-shape-pill';
        elseif (($set['shape'] ?? '') === 'square')   $cls .= ' forms-shape-square';
        if (($set['labels'] ?? '') === 'hide')        $cls .= ' forms-hide-labels';
        if (!empty($set['full_width']))               $cls .= ' forms-fullwidth';
        if (isset($set['scroll_fields']) && !$set['scroll_fields']) $cls .= ' forms-noscroll';

        $style = '';
        $a = (string)($set['accent'] ?? '');
        if ($a !== '') {
            $d = ($set['accent_hover'] ?? '') !== '' ? $set['accent_hover'] : self::darkenHex($a, 0.12);
            [$r, $g, $b] = self::hexToRgb($a);
            $style = '--f-accent:' . $a . ';--f-accent-d:' . $d . ';--f-ring:rgba(' . $r . ',' . $g . ',' . $b . ',.18);';
        }
        // Spacing overrides — emit only when set, else the CSS falls back to
        // the theme defaults.
        if (isset($set['pad'])       && $set['pad']       !== null) $style .= '--f-pad:' . (int)$set['pad'] . 'px;';
        if (isset($set['field_gap']) && $set['field_gap'] !== null) $style .= '--f-field-gap:' . (int)$set['field_gap'] . 'px;';
        if (isset($set['col_gap'])   && $set['col_gap']   !== null) $style .= '--f-col-gap:' . (int)$set['col_gap'] . 'px;';
        return 'class="' . e($cls) . '"' . ($style !== '' ? ' style="' . e($style) . '"' : '');
    }

    /**
     * Render the inner body of the public form: every field, plus — when the
     * field list contains `step` markers — a multi-step wizard (numbered rail,
     * progress bar, grouped step fieldsets, Back/Next nav). The submit button
     * is included. $opts: ['rail' => bool]. Single-step forms render flat.
     */
    public static function renderFormBody(array $fields, array $values, array $errors, string $submitLabel, array $opts = []): string {
        $hasSteps = false;
        foreach ($fields as $f) { if (($f['type'] ?? '') === 'step') { $hasSteps = true; break; } }

        // HTML injected just above the submit button (CAPTCHA widget +
        // time-trap field on the public form; empty in the admin preview).
        $beforeSubmit = (string)($opts['before_submit'] ?? '');

        ob_start();

        if (!$hasSteps && empty($opts['summary'])) {
            // Long flat forms (e.g. 14 fields) get a capped, internally-scrolling
            // field region so the card stays a sensible height and the submit
            // button stays in view. Short forms don't reach the cap → no scroll.
            echo '<div class="forms-step-fields forms-flat-fields" data-forms-scroll>';
            foreach ($fields as $f) {
                $name = $f['name'] ?? '';
                echo self::renderField($f, $values[$name] ?? '', $errors[$name] ?? null);
            }
            echo '</div>';
            echo '<div class="forms-flat-foot">';
            echo $beforeSubmit;
            echo '<button type="submit" class="btn btn-primary btn-lg btn-block fbtn fbtn-go" data-forms-submit>'
               . '<span>' . e($submitLabel) . '</span>' . self::navIcon('arrow-right') . '</button>';
            echo '</div>';
            return (string) ob_get_clean();
        }

        // Group into steps: a `step` marker starts a new step (label = title, placeholder = subtitle).
        $steps = [];
        $cur   = ['title' => '', 'sub' => '', 'fields' => []];
        $seen  = false;
        foreach ($fields as $f) {
            if (($f['type'] ?? '') === 'step') {
                if ($seen || $cur['fields']) $steps[] = $cur;
                $cur  = ['title' => (string)($f['label'] ?? ''), 'sub' => (string)($f['placeholder'] ?? ''), 'fields' => []];
                $seen = true;
            } else {
                $cur['fields'][] = $f;
            }
        }
        $steps[] = $cur;
        $steps = array_values(array_filter($steps, fn($s) => !empty($s['fields']) || $s['title'] !== ''));
        $total = max(1, count($steps));

        // Optional read-only review/summary as the final step (admin toggle).
        $summaryOn = !empty($opts['summary']);
        $navTotal  = $total + ($summaryOn ? 1 : 0);
        $railOn   = ($opts['rail'] ?? true) && $navTotal > 1;
        $wizTitle = (string)($opts['title'] ?? '');
        $brand    = is_array($opts['brand'] ?? null) ? $opts['brand'] : [];
        $brandLogo = trim((string)($brand['logo'] ?? ''));
        $brandName = trim((string)($brand['name'] ?? ''));

        echo '<div class="forms-steps" data-forms-steps data-total="' . $navTotal . '">';
        // Top header row: brand mark (left) + step rail (right) on one line.
        // Falls back to the form title when no brand logo/name is configured.
        if ($brandLogo !== '' || $brandName !== '' || $wizTitle !== '' || $railOn) {
            echo '<div class="forms-wizhead">';
            if ($brandLogo !== '') {
                echo '<img class="forms-brand-logo" src="' . e($brandLogo) . '" alt="' . e($brandName ?: $wizTitle) . '">';
            } elseif ($brandName !== '') {
                echo '<div class="forms-brand-name">' . e($brandName) . '</div>';
            } elseif ($wizTitle !== '') {
                echo '<div class="forms-public-kicker">' . e($wizTitle) . '</div>';
            }
            if ($railOn) {
                echo '<ol class="forms-rail" data-forms-rail>';
                for ($i = 0; $i < $navTotal; $i++) {
                    echo '<li class="forms-rail-item' . ($i === 0 ? ' is-active' : '') . '" data-rail="' . $i . '">'
                       . '<span class="forms-rail-dot">' . ($i + 1) . '</span></li>';
                }
                echo '</ol>';
            }
            echo '</div>';
        }
        // Top row: the current step's title (left) + Back (right, appears from
        // step 2). The title is updated per step by JS via [data-forms-steptitle].
        echo   '<div class="forms-wiztop">';
        echo     '<span class="forms-steptitle-top" data-forms-steptitle></span>';
        echo     '<button type="button" class="forms-back-top" data-forms-back hidden>'
               .   self::navIcon('arrow-left') . '<span>' . e(__('forms_back', 'Back')) . '</span></button>';
        echo   '</div>';
        foreach ($steps as $i => $st) {
            echo '<fieldset class="forms-step" data-forms-step="' . $i . '" data-step-title="' . e($st['title']) . '"' . ($i > 0 ? ' hidden' : '') . '>';
            if ($st['sub'] !== '') {
                echo '<div class="forms-step-head"><p class="forms-step-sub">' . e($st['sub']) . '</p></div>';
            }
            // Fields live in their own region so a tall step scrolls internally
            // while the step header (above) and nav (below) stay put. The 12-col
            // grid moves here from .forms-step (see .forms-step-fields in CSS).
            echo   '<div class="forms-step-fields">';
            foreach ($st['fields'] as $f) {
                $name = $f['name'] ?? '';
                echo self::renderField($f, $values[$name] ?? '', $errors[$name] ?? null);
            }
            echo   '</div>';
            echo '</fieldset>';
        }
        // Read-only review step — JS fills it with every answer when reached.
        if ($summaryOn) {
            echo '<fieldset class="forms-step forms-summary-step" data-forms-step="' . $total . '" data-step-title="' . e(__('forms_review', 'Review')) . '" data-forms-summary hidden>';
            echo   '<div class="forms-step-head"><p class="forms-step-sub">' . e(__('forms_review_sub', 'Please review your answers before submitting.')) . '</p></div>';
            echo   '<div class="forms-step-fields"><div class="forms-summary" data-forms-summary-body></div></div>';
            echo '</fieldset>';
        }
        echo $beforeSubmit;
        echo   '<div class="forms-steps-nav">';
        // Circular progress ring with the step number inside (fill driven by JS).
        echo     '<span class="forms-ring" data-forms-ring style="--p:' . (int)round(100 / $navTotal) . '">'
               .   '<b class="forms-ring-num" data-forms-stepnum>1</b>'
               . '</span>';
        echo     '<button type="button" class="btn btn-primary fbtn fbtn-go" data-forms-next>'
               .   '<span>' . e(__('forms_next', 'Next')) . '</span>' . self::navIcon('arrow-right') . '</button>';
        echo     '<button type="submit" class="btn btn-primary btn-lg fbtn fbtn-go" data-forms-submit hidden>'
               .   '<span>' . e($submitLabel) . '</span>' . self::navIcon('check') . '</button>';
        echo   '</div>';
        echo '</div>';
        return (string) ob_get_clean();
    }

    /** Pretty submission reference: SUB-XXXXXXXX (8 hex chars). */
    public static function generateRef(): string {
        return 'SUB-' . strtoupper(bin2hex(random_bytes(4)));
    }

    /**
     * Return a slug unique within the tenant's forms_definitions
     * table. $excludeId lets the edit page keep its own slug.
     */
    public static function slugify(string $title, ?int $excludeId = null): string {
        $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
        if ($base === '') $base = 'form';
        if (mb_strlen($base) > 60) $base = substr($base, 0, 60);

        $tid = current_tenant_id();
        $slug = $base;
        $i = 2;
        while (true) {
            $clash = Database::row(
                "SELECT id FROM forms_definitions
                  WHERE tenant_id = ? AND slug = ?" . ($excludeId ? " AND id <> ?" : ""),
                $excludeId ? [$tid, $slug, $excludeId] : [$tid, $slug]
            );
            if (!$clash) return $slug;
            $slug = $base . '-' . $i++;
            if ($i > 999) return $base . '-' . bin2hex(random_bytes(3));
        }
    }

    /**
     * Process file fields after a successful validateSubmission.
     * Writes each file via Uploads::handle into uploads/forms/.
     * Updates $data in place (returns merged data + errors).
     */
    public static function handleFileUploads(array $form, array $data): array {
        $errors = [];
        foreach (($form['fields'] ?? []) as $f) {
            if (($f['type'] ?? '') !== 'file') continue;
            $name     = $f['name']  ?? '';
            $required = !empty($f['required']);
            if ($name === '') continue;

            $fileMeta = $_FILES[$name] ?? null;
            if (!$fileMeta || (int)($fileMeta['error'] ?? 4) === UPLOAD_ERR_NO_FILE) {
                if ($required) {
                    $errors[$name] = sprintf(__('forms_field_required', '%s is required.'), ($f['label'] ?? $name));
                }
                continue;
            }

            $result = Uploads::handle($name, 'forms', [
                'max_bytes'     => 10 * 1024 * 1024,
                'allowed_exts'  => ['jpg','jpeg','png','gif','webp','pdf','doc','docx','txt'],
            ]);

            if (!empty($result['ok'])) {
                $data[$name] = [
                    'path'     => $result['path'],
                    'original' => $result['original'] ?? '',
                    'size'     => $result['size']     ?? 0,
                    'mime'     => $result['mime']     ?? '',
                ];
            } else {
                $errors[$name] = $result['error'] ?? 'Upload failed.';
            }
        }
        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * Emit the signature-pad <script> exactly once per request, no matter
     * how many signature fields a form has. Returns '' on subsequent calls.
     */
    private static function signatureAssetTag(): string {
        static $emitted = false;
        if ($emitted) return '';
        $emitted = true;
        return '<script src="' . e(plugin_url('forms', 'assets/js/signature.js'))
             . '?v=' . self::ASSET_VERSION . '" defer></script>';
    }

    /**
     * Process signature fields after validateSubmission. Each signature
     * arrives as a PNG data URL in $data[$name]; decode it, enforce a size
     * cap, write a hardened PNG into uploads/forms/, and replace the value
     * with a structured array {signature, path, mode, name}. Mirrors
     * handleFileUploads so the router can treat them the same way.
     */
    public static function handleSignatures(array $form, array $data): array {
        $errors = [];
        foreach (($form['fields'] ?? []) as $f) {
            if (($f['type'] ?? '') !== 'signature') continue;
            $name     = $f['name'] ?? '';
            $required = !empty($f['required']);
            if ($name === '') continue;

            $raw = is_string($data[$name] ?? null) ? trim((string)$data[$name]) : '';
            if ($raw === '') {
                if ($required) $errors[$name] = sprintf(__('forms_field_required', '%s is required.'), ($f['label'] ?? $name));
                $data[$name] = '';
                continue;
            }

            if (!preg_match('#^data:image/png;base64,(.+)$#s', $raw, $m)) {
                $errors[$name] = 'Signature could not be read. Please sign again.';
                continue;
            }
            $bytes = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
            if ($bytes === false || $bytes === '') {
                $errors[$name] = 'Signature could not be read. Please sign again.';
                continue;
            }
            if (strlen($bytes) > self::SIGNATURE_MAX_BYTES) {
                $errors[$name] = 'Signature image is too large.';
                continue;
            }
            // Confirm it really is a PNG (magic bytes) before trusting it.
            if (substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                $errors[$name] = 'Signature must be a PNG image.';
                continue;
            }

            $dir = Uploads::publicUploadDir('forms');
            $file = 'sig_' . bin2hex(random_bytes(8)) . '.png';
            if (@file_put_contents($dir . '/' . $file, $bytes) === false) {
                $errors[$name] = 'Could not save the signature.';
                continue;
            }

            $mode = (($_POST[$name . '__mode'] ?? '') === 'type') ? 'type' : 'draw';
            $typed = trim((string)($_POST[$name . '__name'] ?? ''));
            $data[$name] = [
                'signature' => true,
                'path'      => '/uploads/forms/' . $file,
                'mode'      => $mode,
                'name'      => $mode === 'type' ? mb_substr($typed, 0, 60) : '',
                'size'      => strlen($bytes),
            ];
        }
        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * POST the submission payload to every active webhook for the
     * form. Best-effort: failures are logged to forms_webhook_log
     * but don't fail the submit.
     *
     * Adds an HMAC-SHA256 signature header so receivers can verify
     * the payload. The shared secret is APP_SECRET (set in .env).
     */
    public static function dispatchWebhooks(int $formId, int $submissionId, array $payload): void {
        $tid = current_tenant_id();
        $hooks = Database::rows(
            "SELECT * FROM forms_webhooks
              WHERE tenant_id = ? AND form_id = ? AND is_active = 1",
            [$tid, $formId]
        );
        if (!$hooks) return;

        $body      = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // CORE-1: unsigned rather than signed-with-a-known-key when unset.
        $signature = slate_sign('forms-webhook', $body) ?? '';

        foreach ($hooks as $hook) {
            $statusCode = null;
            $response   = null;
            $error      = null;

            // SSRF guard: only POST to a public http(s) endpoint. Refuse
            // private/loopback/link-local/reserved targets and non-http
            // schemes, and pin curl to the vetted IP to defeat DNS
            // rebinding between this check and curl's own resolution.
            $vet = self::vetWebhookUrl((string)$hook['url']);
            if ($vet === null) {
                $error = 'Refused: webhook URL is not a public http(s) endpoint.';
            } else {
                try {
                    $ch = curl_init($hook['url']);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => $body,
                        CURLOPT_HTTPHEADER     => array_filter([
                            'Content-Type: application/json',
                            'User-Agent: Slate-Forms/0.1',
                            'X-Slate-Form: ' . $formId,
                            'X-Slate-Submission: ' . $submissionId,
                            $signature !== '' ? self::WEBHOOK_SIGNATURE_HEADER . ': sha256=' . $signature : null,
                        ]),
                        CURLOPT_TIMEOUT        => 10,
                        CURLOPT_CONNECTTIMEOUT => 5,
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                        CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTP | CURLPROTO_HTTPS,
                        CURLOPT_RESOLVE        => [$vet['host'] . ':' . $vet['port'] . ':' . $vet['ip']],
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                    ]);
                    $response = curl_exec($ch);
                    if ($response === false) {
                        $error = curl_error($ch);
                    } else {
                        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        // Cap body size we log to keep the log table sane.
                        if (is_string($response) && strlen($response) > 4000) {
                            $response = substr($response, 0, 4000) . '...[truncated]';
                        }
                    }
                    curl_close($ch);
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }

            try {
                Database::insert('forms_webhook_log', [
                    'tenant_id'     => $tid,
                    'webhook_id'    => (int)$hook['id'],
                    'submission_id' => $submissionId,
                    'status_code'   => $statusCode,
                    'response_body' => $response,
                    'error'         => $error,
                ]);
            } catch (\Throwable $e) {
                if (function_exists('slate_log')) {
                    slate_log('Forms: webhook log insert failed: ' . $e->getMessage(), 'error');
                }
            }
        }
    }

    /**
     * SSRF guard for an outbound webhook URL. Returns
     * ['host'=>, 'port'=>, 'ip'=>] for a safe, public http(s) target, or
     * null if it must be refused: non-http(s) scheme, no host, unresolvable,
     * or resolves to any private / loopback / link-local / reserved IP.
     */
    private static function vetWebhookUrl(string $url): ?array {
        $parts = parse_url($url);
        if (!is_array($parts)) return null;

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') return null;

        $host = (string)($parts['host'] ?? '');
        if ($host === '') return null;
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        // Collect every IP the host resolves to (or the literal IP itself).
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) $ips = $v4;
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $rec) {
                    if (!empty($rec['ipv6'])) $ips[] = $rec['ipv6'];
                }
            }
        }
        if (!$ips) return null;

        // Every resolved address must be a public, non-reserved IP.
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }

        // Pin to the first vetted address so curl talks to exactly what we
        // validated (defeats DNS rebinding between this check and the call).
        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /** Best-effort client IP, respecting common proxy headers. */
    public static function clientIp(): string {
        $candidates = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($candidates as $key) {
            if (!empty($_SERVER[$key])) {
                $val = (string)$_SERVER[$key];
                if (str_contains($val, ',')) {
                    $val = trim(explode(',', $val)[0]);
                }
                if (filter_var($val, FILTER_VALIDATE_IP)) return $val;
            }
        }
        return '';
    }

    /* ───────────────────── Content Builder integration ─────────────────────
     * Lets a Form be dropped into any page as a block. Published forms are
     * offered in the block's picker; the block renders the public form inside
     * a same-origin iframe (?embed=1) so its own CSS/JS/CSRF and submission
     * flow keep working untouched. The iframe auto-sizes via postMessage. */

    /** Published forms as editor select options: [['v'=>slug,'l'=>title], …]. */
    public static function pickerOptions(): array {
        try {
            $rows = Database::rows(
                "SELECT slug, title FROM forms_definitions
                  WHERE tenant_id = ? AND status = 'published' ORDER BY title ASC",
                [current_tenant_id()]
            );
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['v' => $r['slug'], 'l' => ($r['title'] !== '' ? $r['title'] : $r['slug'])];
        }
        return $out;
    }

    /** Render the Content Builder "Form" block (iframe embed + auto-resize). */
    /**
     * The `form` block, rendered INLINE into the host page (Phase D).
     *
     * It used to be an <iframe> pointing at /forms/<slug>?embed=1, with a
     * postMessage height dance. That satisfied "a form appears on the page" and
     * nothing else the block model is for: the document carried {formSlug,
     * minHeight} rather than content, so the Content API returned a reference to
     * another page and a second renderer would receive an iframe instead of
     * fields; --slate-* stopped dead at the frame boundary, so a branded tenant
     * got an unbranded form and Phase E could never reach inside; and the markup
     * was not server-rendered, so it was invisible to search engines.
     *
     * Rendering inline fixes all four. The form body comes from renderFormBody(),
     * the same call the standalone page uses, so the fields cannot drift between
     * the two surfaces.
     *
     * Submission still POSTs to /forms/<slug>, which owns CSRF, the spam guard,
     * validation, storage and dispatch — none of that is duplicated here. A
     * hidden return_to brings a successful submit back to this page instead of
     * stranding the visitor on the standalone thanks page.
     *
     * KNOWN LIMIT: a submission that fails validation still lands on the
     * standalone form page with its errors, rather than returning here. It is
     * functional and correct, just not seamless; returning would need the errors
     * and values flashed through the session, which is a bigger change than this
     * one and deserves its own commit.
     */
    public static function renderContentBlock(array $props, array $block = []): string {
        // Phase 11: the block is a public Forms surface on any page — an
        // unentitled installation renders nothing, like /forms/<slug> (07 §4).
        if (!\ModuleGuard::allows('forms')) return '';

        $slug = trim((string)($props['formSlug'] ?? ''));
        if ($slug === '' && !empty($props['formId'])) {
            $f = self::getFormById((int)$props['formId']);
            $slug = (string)($f['slug'] ?? '');
        }
        $placeholder = function (string $msg): string {
            return '<div class="cb-form-embed" style="max-width:720px;margin:1.5rem auto;border:1px dashed #cbd2da;'
                 . 'border-radius:12px;padding:1.75rem;text-align:center;color:#667085;'
                 . 'font:14px/1.5 system-ui,-apple-system,sans-serif">' . e($msg) . '</div>';
        };

        if ($slug === '') return $placeholder('No form selected yet — pick one in this block’s settings.');

        $form = self::getForm($slug);
        if (!$form || ($form['status'] ?? '') !== 'published') {
            return $placeholder('That form isn’t published, so it can’t be shown here.');
        }

        $set = self::formSettings($form['settings_json'] ?? null);

        // Returning from a successful submit (see return_to in the public
        // router). Without this the visitor lands back on the page and is shown
        // an empty form again, which reads as "it didn't go through".
        if ((string)($_GET['form_submitted'] ?? '') === $slug) {
            $msg = trim((string)($form['success_message'] ?? ''));
            if ($msg === '') $msg = 'Thanks — your response has been received.';
            return '<div class="cb-form-block forms-public-shell">'
                 . '<article ' . self::publicCardAttrs($set) . '>'
                 . '<div class="alert alert-success" role="status">' . nl2br(e($msg)) . '</div>'
                 . '</article></div>';
        }

        // Multi-step forms render their own step headings; a second title here
        // would compete with them, exactly as on the standalone page.
        $multi = false;
        foreach (($form['fields'] ?? []) as $f) {
            if (($f['type'] ?? '') === 'step') { $multi = true; break; }
        }

        $beforeSubmit = FormsSpamGuard::timeTrapField() . FormsSpamGuard::captchaWidget($set['spam']);
        $brandLogoPath = (string) Database::setting('brand_logo_path');
        $brand = [
            'name' => (string)(Database::setting('site_name') ?: ''),
            'logo' => $brandLogoPath !== '' ? SLATE_URL . '/' . ltrim($brandLogoPath, '/') : '',
        ];

        $action = rtrim(SLATE_URL, '/') . '/forms/' . rawurlencode($slug);

        ob_start(); ?>
<div class="cb-form-block forms-public-shell">
    <article <?= self::publicCardAttrs($set) ?>>
        <?php if (!$multi): ?>
        <header class="forms-public-header">
            <?php /* h2, not h1: the host page already has one. The standalone
                     page uses h1 because there it IS the page. */ ?>
            <h2><?= e($form['title']) ?></h2>
            <?php if (!empty($form['description'])): ?>
            <p class="forms-public-desc"><?= nl2br(e($form['description'])) ?></p>
            <?php endif; ?>
        </header>
        <?php endif; ?>

        <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" novalidate
              data-animate="<?= $set['animate'] ? '1' : '0' ?>" data-validate="<?= $set['validate'] ? '1' : '0' ?>">
            <?= csrf_field() ?>
            <div style="display:none !important;" aria-hidden="true">
                <label><?= __('forms_leave_empty', 'Leave this field empty') ?>
                    <input type="text" name="hp_field" tabindex="-1" autocomplete="off">
                </label>
            </div>
            <input type="hidden" name="return_to" value="<?= e(self::returnToPath()) ?>">
            <?= self::renderFormBody(
                    $form['fields'],
                    [],
                    [],
                    (string)($form['submit_label'] ?: __('forms_submit', 'Submit')),
                    [
                        'rail'          => $set['rail'],
                        'before_submit' => $beforeSubmit,
                        'title'         => (string)$form['title'],
                        'summary'       => !empty($set['summary']),
                        'brand'         => $brand,
                    ]
                ) ?>
        </form>
    </article>
</div>
<?php
        return ob_get_clean();
    }

    /**
     * The current request path, for return_to.
     *
     * PATH ONLY — the query and any host are dropped. The receiving end refuses
     * anything that is not a single-slash-rooted path, so this cannot be turned
     * into an open redirect by a crafted host page.
     */
    public static function returnToPath(): string {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');

        // Refuse anything that NAMES a destination — a scheme or a host — rather
        // than reducing it to its path. Reducing would be safe here, but it means
        // the function quietly accepts hostile input and returns something usable,
        // and the next caller to trust it slightly differently gets an open
        // redirect. Refusing keeps the contract "a path from this site, or
        // nothing".
        if (parse_url($uri, PHP_URL_SCHEME) !== null || parse_url($uri, PHP_URL_HOST) !== null) {
            return '';
        }

        $path = (string) parse_url($uri, PHP_URL_PATH);
        return ($path !== '' && $path[0] === '/' && !str_starts_with($path, '//')) ? $path : '';
    }

    /** @deprecated thin delegate — ContentBuilderAPI owns the walk now. */
    public static function layoutHasBlock($layout, string $type): bool {
        return class_exists('ContentBuilderAPI')
            && ContentBuilderAPI::layoutHasBlock($layout, $type);
    }
}
