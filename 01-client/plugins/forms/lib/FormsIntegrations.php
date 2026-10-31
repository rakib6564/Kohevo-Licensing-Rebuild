<?php
/**
 * Forms — Integrations: send a form's signups to an email marketing service.
 *
 * A signup is saved by Forms first, whatever happens here. `forms_submitted` then queues it and tries to send it, and a
 * failure stays in the queue: a later submission, `frequent_cron` or the Integrations screen tries again with growing
 * delays, so a service being down loses nobody and never holds up a visitor. A delivered row keeps no personal data.
 *
 * The queue holds what is sent, not a reference to the submission, so a retry works after the submission is deleted.
 */

declare(strict_types=1);

require_once __DIR__ . '/integrations/NewsletterConnector.php';
require_once __DIR__ . '/integrations/BrevoConnector.php';

class FormsIntegrations {
    public const MAX_ATTEMPTS = 6;
    /** Minutes before attempt 2, doubling each time. */
    private const FIRST_DELAY = 5;
    private const BATCH = 20;

    private static bool $schemaChecked = false;

    public static function register(): void {
        Hook::addAction('forms_submitted', [self::class, 'onSubmitted'], 10, 3);
        Hook::addAction('frequent_cron', [self::class, 'processQueue']);
    }

    public static function ensureSchema(): void {
        if (self::$schemaChecked) { return; }
        self::$schemaChecked = true;
        $pdo = Database::get();
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `forms_integrations` (
                `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `tenant_id`       INT UNSIGNED NOT NULL DEFAULT 1,
                `form_id`         INT UNSIGNED NOT NULL,
                `provider`        VARCHAR(40) NOT NULL,
                `list_id`         BIGINT UNSIGNED NOT NULL,
                `email_field`     VARCHAR(60) NOT NULL,
                `name_field`      VARCHAR(60) NOT NULL DEFAULT '',
                `consent_field`   VARCHAR(60) NOT NULL DEFAULT '',
                `doi_template_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `doi_redirect_url` VARCHAR(500) NOT NULL DEFAULT '',
                `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
                `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `tenant_form_provider` (`tenant_id`, `form_id`, `provider`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `forms_integration_queue` (
                `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `tenant_id`       INT UNSIGNED NOT NULL DEFAULT 1,
                `form_id`         INT UNSIGNED NOT NULL,
                `submission_id`   INT UNSIGNED NOT NULL,
                `provider`        VARCHAR(40) NOT NULL,
                `email`           VARCHAR(255) NOT NULL DEFAULT '',
                `payload_json`    TEXT NULL,
                `status`          VARCHAR(10) NOT NULL DEFAULT 'pending',
                `attempts`        INT UNSIGNED NOT NULL DEFAULT 0,
                `next_attempt_at` DATETIME NOT NULL,
                `last_error`      VARCHAR(255) NULL,
                `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `sent_at`         DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `tenant_submission_provider` (`tenant_id`, `submission_id`, `provider`),
                KEY `tenant_status_next` (`tenant_id`, `status`, `next_attempt_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** @return array<string, NewsletterConnector> */
    public static function connectors(): array {
        $connectors = ['brevo' => new BrevoConnector()];
        return (array) Hook::applyFilters('forms_newsletter_connectors', $connectors);
    }

    private static function keySetting(string $provider): string {
        return 'forms.integration.' . $provider . '.api_key';
    }

    /** The decrypted API key, or '' when none is saved or it cannot be read. */
    public static function apiKey(string $provider): string {
        $sealed = (string) Database::setting(self::keySetting($provider));
        return $sealed === '' ? '' : (string) slate_decrypt_secret($sealed);
    }

    /** Saves a key already sealed by slate_seal_posted_secrets(); '' removes it. */
    public static function saveSealedKey(string $provider, string $sealed): void {
        Database::setSetting(self::keySetting($provider), $sealed);
    }

    public static function keySettingName(string $provider): string {
        return self::keySetting($provider);
    }

    /** @return array<int, array<string, mixed>> rules by form id */
    public static function rules(string $provider): array {
        self::ensureSchema();
        $rows = Database::rows('SELECT * FROM forms_integrations WHERE tenant_id = ? AND provider = ?', [current_tenant_id(), $provider]);
        $byForm = [];
        foreach ($rows as $row) { $byForm[(int) $row['form_id']] = $row; }
        return $byForm;
    }

    /** @param array<string, mixed> $rule list_id 0 turns the form off */
    public static function saveRule(int $formId, string $provider, array $rule): void {
        self::ensureSchema();
        $tid = current_tenant_id();
        $listId = (int) ($rule['list_id'] ?? 0);
        $values = [
            'list_id' => max(0, $listId), 'email_field' => mb_substr((string) ($rule['email_field'] ?? ''), 0, 60),
            'name_field' => mb_substr((string) ($rule['name_field'] ?? ''), 0, 60), 'consent_field' => mb_substr((string) ($rule['consent_field'] ?? ''), 0, 60),
            'doi_template_id' => max(0, (int) ($rule['doi_template_id'] ?? 0)), 'doi_redirect_url' => mb_substr(trim((string) ($rule['doi_redirect_url'] ?? '')), 0, 500),
            'is_active' => $listId > 0 ? 1 : 0,
        ];
        $id = (int) Database::value('SELECT id FROM forms_integrations WHERE tenant_id = ? AND form_id = ? AND provider = ?', [$tid, $formId, $provider]);
        if ($id > 0) {
            Database::update('forms_integrations', $values, 'id = ? AND tenant_id = ?', [$id, $tid]);
        } else {
            Database::insert('forms_integrations', $values + ['tenant_id' => $tid, 'form_id' => $formId, 'provider' => $provider]);
        }
    }

    /**
     * What a submission sends under a rule, or null when it sends nothing: no valid address, or the consent box
     * the rule names was left unticked.
     *
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $data
     * @return array{email: string, attributes: array<string, string>, double_optin: array<string, mixed>}|null
     */
    public static function contactFrom(array $rule, array $data): ?array {
        $email = trim((string) ($data[(string) ($rule['email_field'] ?? '')] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) { return null; }
        $consent = (string) ($rule['consent_field'] ?? '');
        if ($consent !== '') {
            $given = $data[$consent] ?? '';
            if (is_array($given) ? $given === [] : !in_array(strtolower(trim((string) $given)), ['1', 'on', 'yes', 'oui', 'true'], true)) { return null; }
        }
        $attributes = [];
        $name = trim((string) ($data[(string) ($rule['name_field'] ?? '')] ?? ''));
        if ($name !== '') {
            $parts = preg_split('/\s+/u', $name, 2) ?: [$name];
            $attributes['FIRSTNAME'] = (string) $parts[0];
            if (isset($parts[1])) { $attributes['LASTNAME'] = (string) $parts[1]; }
        }
        $template = (int) ($rule['doi_template_id'] ?? 0);
        $double = $template > 0 ? ['template_id' => $template, 'redirect_url' => (string) ($rule['doi_redirect_url'] ?? '')] : [];
        return ['email' => $email, 'attributes' => $attributes, 'double_optin' => $double];
    }

    /** Minutes until the next attempt after `$attempts` failures. */
    public static function delayAfter(int $attempts): int {
        return self::FIRST_DELAY * (2 ** max(0, $attempts - 1));
    }

    /** @param array<string, mixed> $data */
    public static function onSubmitted($submissionId, $formId, $data): void {
        try {
            self::ensureSchema();
            $tid = current_tenant_id();
            $rows = Database::rows('SELECT * FROM forms_integrations WHERE tenant_id = ? AND form_id = ? AND is_active = 1', [$tid, (int) $formId]);
            foreach ($rows as $rule) {
                $contact = self::contactFrom($rule, is_array($data) ? $data : []);
                if ($contact === null) { continue; }
                Database::insert('forms_integration_queue', [
                    'tenant_id' => $tid, 'form_id' => (int) $formId, 'submission_id' => (int) $submissionId, 'provider' => (string) $rule['provider'],
                    'email' => $contact['email'], 'payload_json' => json_encode(['list_id' => (int) $rule['list_id']] + $contact, JSON_UNESCAPED_UNICODE),
                    'next_attempt_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
            if ($rows !== []) { self::processQueue(); }
        } catch (\Throwable $e) {
            if (function_exists('slate_log')) { slate_log('Forms integrations: ' . $e->getMessage(), 'error'); }
        }
    }

    /** Sends what is due for this tenant. Returns how many rows it tried. */
    public static function processQueue(): int {
        self::ensureSchema();
        $tid = current_tenant_id();
        $due = Database::rows(
            "SELECT * FROM forms_integration_queue WHERE tenant_id = ? AND status = 'pending' AND next_attempt_at <= UTC_TIMESTAMP() ORDER BY id LIMIT " . self::BATCH,
            [$tid]
        );
        $connectors = self::connectors();
        foreach ($due as $row) {
            self::attempt($row, $connectors[(string) $row['provider']] ?? null);
        }
        return count($due);
    }

    /** @param array<string, mixed> $row */
    private static function attempt(array $row, ?NewsletterConnector $connector): void {
        $id = (int) $row['id'];
        $tid = current_tenant_id();
        $attempts = (int) $row['attempts'] + 1;
        try {
            if ($connector === null) { throw new IntegrationException('This service is not available.', false); }
            $apiKey = self::apiKey($connector->key());
            if ($apiKey === '') { throw new IntegrationException('No API key is saved.'); }
            $job = json_decode((string) $row['payload_json'], true);
            if (!is_array($job)) { throw new IntegrationException('The queued signup cannot be read.', false); }
            $connector->subscribe($apiKey, (int) $job['list_id'], (string) $row['email'], (array) ($job['attributes'] ?? []), (array) ($job['double_optin'] ?? []));
            Database::update('forms_integration_queue', ['status' => 'sent', 'attempts' => $attempts, 'sent_at' => gmdate('Y-m-d H:i:s'), 'last_error' => null, 'payload_json' => null, 'email' => ''], 'id = ? AND tenant_id = ?', [$id, $tid]);
        } catch (IntegrationException $e) {
            $giveUp = !$e->retry || $attempts >= self::MAX_ATTEMPTS;
            Database::update('forms_integration_queue', [
                'status' => $giveUp ? 'failed' : 'pending', 'attempts' => $attempts, 'last_error' => mb_substr($e->getMessage(), 0, 255),
                'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + 60 * self::delayAfter($attempts)),
            ], 'id = ? AND tenant_id = ?', [$id, $tid]);
        }
    }

    /** Puts failed rows back in the queue. Returns how many. */
    public static function retryFailed(): int {
        self::ensureSchema();
        $tid = current_tenant_id();
        $n = (int) Database::value("SELECT COUNT(*) FROM forms_integration_queue WHERE tenant_id = ? AND status = 'failed'", [$tid]);
        if ($n > 0) {
            Database::update('forms_integration_queue', ['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => gmdate('Y-m-d H:i:s')], "tenant_id = ? AND status = 'failed'", [$tid]);
            self::processQueue();
        }
        return $n;
    }

    /** @return array<string, int> rows by status */
    public static function counts(): array {
        self::ensureSchema();
        $counts = ['pending' => 0, 'sent' => 0, 'failed' => 0];
        foreach (Database::rows('SELECT status, COUNT(*) AS c FROM forms_integration_queue WHERE tenant_id = ? GROUP BY status', [current_tenant_id()]) as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }
        return $counts;
    }

    /** @return list<array<string, mixed>> */
    public static function recentProblems(int $limit = 20): array {
        self::ensureSchema();
        return Database::rows(
            "SELECT q.*, f.title AS form_title FROM forms_integration_queue q LEFT JOIN forms_definitions f ON f.id = q.form_id AND f.tenant_id = q.tenant_id
              WHERE q.tenant_id = ? AND q.status <> 'sent' ORDER BY q.id DESC LIMIT " . max(1, $limit),
            [current_tenant_id()]
        );
    }
}
