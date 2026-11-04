<?php
/**
 * Slate — the shared charges ledger and the checkout-session map, for every
 * payment gateway.
 *
 * The ledger table keeps its historical name, `stripepayment_charges`: Shop,
 * Membership, Booking, Restaurant and Studio rows already point at its ids
 * (charge_id columns), so it is widened in place rather than moved. A
 * `gateway` column says who took the money. Stripe rows keep using their
 * stripe_payment_intent_id / stripe_session_id columns (and the unique keys
 * on them); every other gateway uses gateway_txn_id / gateway_ref, with
 * their own unique keys. columnsFor() is the one place that decides which.
 *
 *   txn  the refundable money movement (Stripe PaymentIntent, PayPal capture)
 *   ref  the checkout it came from (Stripe session / invoice, PayPal order)
 *
 * `payment_sessions` maps a gateway's checkout ref back to the plugin record
 * that started it, so a webhook never has to trust gateway metadata for
 * routing (PayPal only carries 127 characters of it).
 */

declare(strict_types=1);

namespace Slate\Services\Payments;

final class PaymentLedger
{
    public const TABLE = 'stripepayment_charges';
    public const STATUSES = ['succeeded', 'refunded', 'partial_refund', 'failed'];

    private static bool $schemaChecked = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;
        $pdo = \Database::get();
        try {
            // Same shape StripePaymentAPI::ensureChargesSchema() creates, so
            // whichever runs first leaves an identical table.
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `stripepayment_charges` (
                    `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`                INT UNSIGNED NOT NULL DEFAULT 1,
                    `mode`                     ENUM('test','live') NOT NULL DEFAULT 'test',
                    `gateway`                  VARCHAR(32) NOT NULL DEFAULT 'stripe',
                    `source_plugin`            VARCHAR(80) NOT NULL,
                    `source_id`                VARCHAR(190) NULL,
                    `stripe_payment_intent_id` VARCHAR(255) NULL,
                    `stripe_session_id`        VARCHAR(255) NULL,
                    `gateway_txn_id`           VARCHAR(255) NULL,
                    `gateway_ref`              VARCHAR(255) NULL,
                    `customer_email`           VARCHAR(200) NULL,
                    `amount_cents`             INT UNSIGNED NOT NULL DEFAULT 0,
                    `refunded_cents`           INT UNSIGNED NOT NULL DEFAULT 0,
                    `currency`                 VARCHAR(8) NOT NULL DEFAULT 'USD',
                    `status`                   ENUM('succeeded','refunded','partial_refund','failed') NOT NULL DEFAULT 'succeeded',
                    `meta_json`                LONGTEXT NULL,
                    `created_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_tenant_created`   (`tenant_id`, `created_at`),
                    KEY `idx_source`           (`tenant_id`, `source_plugin`, `source_id`),
                    UNIQUE KEY `uniq_pi`       (`tenant_id`, `stripe_payment_intent_id`),
                    UNIQUE KEY `uniq_session`  (`tenant_id`, `stripe_session_id`),
                    UNIQUE KEY `uniq_gateway_txn` (`tenant_id`, `gateway`, `gateway_txn_id`),
                    UNIQUE KEY `uniq_gateway_ref` (`tenant_id`, `gateway`, `gateway_ref`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            self::ensureColumn('gateway', "VARCHAR(32) NOT NULL DEFAULT 'stripe' AFTER `mode`");
            self::ensureColumn('gateway_txn_id', 'VARCHAR(255) NULL AFTER `stripe_session_id`');
            self::ensureColumn('gateway_ref', 'VARCHAR(255) NULL AFTER `gateway_txn_id`');
            self::ensureIndex('uniq_gateway_txn', 'UNIQUE KEY `uniq_gateway_txn` (`tenant_id`, `gateway`, `gateway_txn_id`)');
            self::ensureIndex('uniq_gateway_ref', 'UNIQUE KEY `uniq_gateway_ref` (`tenant_id`, `gateway`, `gateway_ref`)');

            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `payment_sessions` (
                    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`     INT UNSIGNED NOT NULL DEFAULT 1,
                    `gateway`       VARCHAR(32)  NOT NULL,
                    `gateway_ref`   VARCHAR(255) NOT NULL,
                    `mode`          ENUM('test','live') NOT NULL DEFAULT 'test',
                    `source_plugin` VARCHAR(80)  NOT NULL,
                    `source_id`     VARCHAR(190) NULL,
                    `amount_cents`  INT UNSIGNED NOT NULL DEFAULT 0,
                    `currency`      VARCHAR(8)   NOT NULL DEFAULT 'USD',
                    `billing_interval` VARCHAR(8) NOT NULL DEFAULT '',
                    `customer_email` VARCHAR(200) NULL,
                    `status`        ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
                    `meta_json`     LONGTEXT NULL,
                    `created_at`    DATETIME NOT NULL,
                    `completed_at`  DATETIME NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_gateway_ref` (`tenant_id`, `gateway`, `gateway_ref`),
                    KEY `idx_source` (`tenant_id`, `source_plugin`, `source_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            // One row per refund the gateway confirmed, so a replayed refund
            // (same idempotency key → same gateway refund id) is counted once.
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `payment_refunds` (
                    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`     INT UNSIGNED NOT NULL DEFAULT 1,
                    `charge_id`     INT UNSIGNED NOT NULL,
                    `gateway`       VARCHAR(32)  NOT NULL,
                    `refund_ref`    VARCHAR(255) NOT NULL,
                    `amount_cents`  INT UNSIGNED NOT NULL,
                    `created_at`    DATETIME NOT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_refund` (`tenant_id`, `gateway`, `refund_ref`),
                    KEY `idx_charge` (`tenant_id`, `charge_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            slate_log('PaymentLedger: ensure schema failed: ' . $e->getMessage(), 'error');
        }
    }

    /** Test hook: forget that the schema was checked this request. */
    public static function resetSchemaCheck(): void
    {
        self::$schemaChecked = false;
    }

    /** [txnColumn, refColumn] for a gateway. */
    public static function columnsFor(string $gateway): array
    {
        return $gateway === 'stripe'
            ? ['stripe_payment_intent_id', 'stripe_session_id']
            : ['gateway_txn_id', 'gateway_ref'];
    }

    /** The refundable money-movement id stored on a ledger row. */
    public static function txnOf(array $row): string
    {
        [$txnCol] = self::columnsFor((string) ($row['gateway'] ?? 'stripe'));
        return (string) ($row[$txnCol] ?? '');
    }

    /** The checkout reference stored on a ledger row. */
    public static function refOf(array $row): string
    {
        [, $refCol] = self::columnsFor((string) ($row['gateway'] ?? 'stripe'));
        return (string) ($row[$refCol] ?? '');
    }

    /**
     * Insert a ledger row unless one already exists for the same txn or ref
     * (per gateway, per tenant). Safe against concurrent webhook + return-page
     * writes: the unique keys decide, and the loser re-reads the winner.
     *
     * Required: gateway, source_plugin, amount_cents, and txn_id and/or ref.
     * Optional: mode, source_id, customer_email, currency, status, meta.
     *
     * Returns ['id' => ?int, 'new' => bool]; id is null only on a failed write.
     */
    public static function record(array $args): array
    {
        self::ensureSchema();
        $tid     = current_tenant_id();
        $gateway = mb_substr(trim((string) ($args['gateway'] ?? '')), 0, 32);
        if ($gateway === '') {
            slate_log('PaymentLedger::record called without a gateway', 'error');
            return ['id' => null, 'new' => false];
        }
        [$txnCol, $refCol] = self::columnsFor($gateway);
        $txn = trim((string) ($args['txn_id'] ?? ''));
        $ref = trim((string) ($args['ref'] ?? ''));

        $status = (string) ($args['status'] ?? 'succeeded');
        $existing = self::findExisting($tid, $gateway, $txnCol, $txn, $refCol, $ref);
        if ($existing !== null) {
            // A declined attempt that was retried and then paid (same intent):
            // the row becomes the successful charge, and counts as new.
            if ($status === 'succeeded') {
                $upgraded = \Database::update(self::TABLE, [
                    'status'       => 'succeeded',
                    'amount_cents' => max(0, (int) ($args['amount_cents'] ?? 0)),
                ], "id = ? AND tenant_id = ? AND status = 'failed'", [$existing, $tid]);
                if ($upgraded === 1) return ['id' => $existing, 'new' => true];
            }
            return ['id' => $existing, 'new' => false];
        }

        $meta   = $args['meta'] ?? null;
        try {
            $id = \Database::insert(self::TABLE, [
                'tenant_id'      => $tid,
                'mode'           => ($args['mode'] ?? 'test') === 'live' ? 'live' : 'test',
                'gateway'        => $gateway,
                'source_plugin'  => mb_substr((string) ($args['source_plugin'] ?? '') ?: 'unknown', 0, 80),
                'source_id'      => ($args['source_id'] ?? '') !== '' ? mb_substr((string) $args['source_id'], 0, 190) : null,
                $txnCol          => $txn !== '' ? $txn : null,
                $refCol          => $ref !== '' ? $ref : null,
                'customer_email' => ($args['customer_email'] ?? '') !== '' ? mb_substr((string) $args['customer_email'], 0, 200) : null,
                'amount_cents'   => max(0, (int) ($args['amount_cents'] ?? 0)),
                'currency'       => strtoupper(mb_substr((string) ($args['currency'] ?? '') ?: 'USD', 0, 8)),
                'status'         => in_array($status, self::STATUSES, true) ? $status : 'succeeded',
                'meta_json'      => $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (\PDOException $e) {
            // The table was recreated from an older definition after this
            // request checked it (a plugin reinstall): widen it and retry once.
            if ($e->getCode() === '42S22' && empty($args['_schema_retry'])) {
                self::$schemaChecked = false;
                return self::record(['_schema_retry' => true] + $args);
            }
            // A concurrent writer won the race on a unique key.
            if ($e->getCode() === '23000') {
                $winner = self::findExisting($tid, $gateway, $txnCol, $txn, $refCol, $ref);
                if ($winner !== null) return ['id' => $winner, 'new' => false];
            }
            slate_log('PaymentLedger::record failed: ' . $e->getMessage(), 'error');
            return ['id' => null, 'new' => false];
        } catch (\Throwable $e) {
            slate_log('PaymentLedger::record failed: ' . $e->getMessage(), 'error');
            return ['id' => null, 'new' => false];
        }

        // Fired once per real charge, whichever path (webhook, return page,
        // a plugin's own record call) got there first.
        try {
            \Hook::doAction('payment_charge_recorded', $id, $args);
        } catch (\Throwable $e) {
            slate_log('payment_charge_recorded listener failed: ' . $e->getMessage(), 'error');
        }
        return ['id' => $id, 'new' => true];
    }

    private static function findExisting(int $tid, string $gateway, string $txnCol, string $txn, string $refCol, string $ref): ?int
    {
        // Stripe rows predate the gateway column and are found by their own
        // unique columns alone; other gateways are matched on gateway too.
        $gw = $gateway === 'stripe' ? '' : ' AND gateway = ?';
        foreach ([[$txnCol, $txn], [$refCol, $ref]] as [$col, $val]) {
            if ($val === '') continue;
            $params = [$tid, $val];
            if ($gw !== '') $params[] = $gateway;
            $id = \Database::value(
                "SELECT id FROM stripepayment_charges WHERE tenant_id = ? AND `" . $col . "` = ?" . $gw . " LIMIT 1",
                $params
            );
            if ($id) return (int) $id;
        }
        return null;
    }

    public static function get(int $id): ?array
    {
        self::ensureSchema();
        return \Database::row(
            "SELECT * FROM stripepayment_charges WHERE id = ? AND tenant_id = ?",
            [$id, current_tenant_id()]
        );
    }

    /** The ledger row for a gateway's txn id, or null. */
    public static function findByTxn(string $gateway, string $txn): ?array
    {
        if ($txn === '') return null;
        self::ensureSchema();
        [$txnCol] = self::columnsFor($gateway);
        return \Database::row(
            "SELECT * FROM stripepayment_charges WHERE tenant_id = ? AND gateway = ? AND `" . $txnCol . "` = ? LIMIT 1",
            [current_tenant_id(), $gateway, $txn]
        );
    }

    /** The ledger row for a gateway's checkout ref, or null. */
    public static function findByRef(string $gateway, string $ref): ?array
    {
        if ($ref === '') return null;
        self::ensureSchema();
        [, $refCol] = self::columnsFor($gateway);
        return \Database::row(
            "SELECT * FROM stripepayment_charges WHERE tenant_id = ? AND gateway = ? AND `" . $refCol . "` = ? LIMIT 1",
            [current_tenant_id(), $gateway, $ref]
        );
    }

    /**
     * Fill in the txn id of a row recorded before its money movement was
     * known (a subscription activated before its first sale). Never
     * overwrites a txn that is already set.
     */
    public static function attachTxn(int $id, string $txn): bool
    {
        $row = self::get($id);
        if ($row === null || $txn === '') return false;
        [$txnCol] = self::columnsFor((string) $row['gateway']);
        return \Database::update(self::TABLE, [$txnCol => $txn],
            "id = ? AND tenant_id = ? AND `" . $txnCol . "` IS NULL", [$id, current_tenant_id()]) === 1;
    }

    /**
     * Charges, newest first.
     * $filters: gateway, source_plugin, mode, status, email_like, days
     */
    public static function list(array $filters = [], int $limit = 100): array
    {
        self::ensureSchema();
        $where  = ['tenant_id = ?'];
        $params = [current_tenant_id()];
        if (!empty($filters['gateway']))       { $where[] = 'gateway = ?';       $params[] = (string) $filters['gateway']; }
        if (!empty($filters['source_plugin'])) { $where[] = 'source_plugin = ?'; $params[] = (string) $filters['source_plugin']; }
        if (!empty($filters['mode']) && in_array($filters['mode'], ['test', 'live'], true)) {
            $where[] = 'mode = ?'; $params[] = $filters['mode'];
        }
        if (!empty($filters['status'])) { $where[] = 'status = ?'; $params[] = (string) $filters['status']; }
        if (!empty($filters['email_like'])) {
            $where[] = 'customer_email LIKE ?'; $params[] = '%' . $filters['email_like'] . '%';
        }
        if (!empty($filters['days']) && (int) $filters['days'] > 0) {
            $where[] = 'created_at >= NOW() - INTERVAL ' . (int) $filters['days'] . ' DAY';
        }
        return \Database::rows(
            "SELECT * FROM stripepayment_charges WHERE " . implode(' AND ', $where)
            . " ORDER BY created_at DESC, id DESC LIMIT " . max(1, min(500, $limit)),
            $params
        );
    }

    /**
     * Set a row's running refunded total. Never lowers it, so a late or
     * replayed refund webhook cannot undo a newer refund.
     */
    public static function setRefunded(int $id, int $refundedCents): ?array
    {
        // The gateway's own running total (a refund webhook): never lowers
        // what is recorded, in one statement so concurrent writers can't.
        \Database::query(
            "UPDATE stripepayment_charges SET refunded_cents = LEAST(amount_cents, GREATEST(refunded_cents, ?))
              WHERE tenant_id = ? AND id = ?", [max(0, $refundedCents), current_tenant_id(), $id]);
        return self::syncRefundStatus($id);
    }

    /**
     * Count one confirmed refund of $amountCents. $refundRef is the gateway's
     * refund id. The recorded total becomes the larger of what is already
     * recorded (which a refund webhook may have set) and the sum of the
     * refunds confirmed here, so a replayed refund (same id) or one the
     * webhook already counted is not added twice, and two refunds finishing
     * together both count.
     */
    public static function addRefund(int $id, int $amountCents, string $refundRef = ''): ?array
    {
        self::ensureSchema();
        $row = self::get($id);
        if (!$row) return null;
        $tid = current_tenant_id();
        try {
            \Database::insert('payment_refunds', [
                'tenant_id' => $tid, 'charge_id' => $id, 'gateway' => (string) $row['gateway'],
                'refund_ref' => $refundRef !== '' ? mb_substr($refundRef, 0, 255) : 'noid-' . bin2hex(random_bytes(8)),
                'amount_cents' => max(0, $amountCents), 'created_at' => slate_db_now(),
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;   // this refund is already counted
        }
        \Database::query(
            "UPDATE stripepayment_charges c
                SET c.refunded_cents = LEAST(c.amount_cents, GREATEST(c.refunded_cents,
                    (SELECT COALESCE(SUM(r.amount_cents), 0) FROM payment_refunds r WHERE r.tenant_id = c.tenant_id AND r.charge_id = c.id)))
              WHERE c.tenant_id = ? AND c.id = ?", [$tid, $id]);
        return self::syncRefundStatus($id);
    }

    private static function syncRefundStatus(int $id): ?array
    {
        \Database::query(
            "UPDATE stripepayment_charges
                SET status = CASE WHEN refunded_cents >= amount_cents THEN 'refunded'
                                  WHEN refunded_cents > 0 THEN 'partial_refund' ELSE status END
              WHERE tenant_id = ? AND id = ?", [current_tenant_id(), $id]);
        return self::get($id);
    }

    // ── Checkout sessions ─────────────────────────────────────

    public static function createSession(array $args): int
    {
        self::ensureSchema();
        return \Database::insert('payment_sessions', [
            'tenant_id'      => current_tenant_id(),
            'gateway'        => (string) $args['gateway'],
            'gateway_ref'    => (string) $args['gateway_ref'],
            'mode'           => ($args['mode'] ?? 'test') === 'live' ? 'live' : 'test',
            'source_plugin'  => mb_substr((string) $args['source_plugin'], 0, 80),
            'source_id'      => ($args['source_id'] ?? '') !== '' ? mb_substr((string) $args['source_id'], 0, 190) : null,
            'amount_cents'   => max(0, (int) ($args['amount_cents'] ?? 0)),
            'currency'       => strtoupper((string) ($args['currency'] ?? 'USD')),
            'billing_interval' => (string) ($args['interval'] ?? ''),
            'customer_email' => ($args['customer_email'] ?? '') !== '' ? mb_substr((string) $args['customer_email'], 0, 200) : null,
            'status'         => 'pending',
            'meta_json'      => isset($args['meta']) ? json_encode($args['meta'], JSON_UNESCAPED_UNICODE) : null,
            'created_at'     => slate_db_now(),
        ]);
    }

    public static function findSession(string $gateway, string $ref): ?array
    {
        if ($ref === '') return null;
        self::ensureSchema();
        return \Database::row(
            "SELECT * FROM payment_sessions WHERE tenant_id = ? AND gateway = ? AND gateway_ref = ? LIMIT 1",
            [current_tenant_id(), $gateway, $ref]
        );
    }

    /** Move a pending session to completed|failed. A completed session never goes back. */
    public static function settleSession(int $id, string $status): void
    {
        if (!in_array($status, ['completed', 'failed'], true)) return;
        \Database::update('payment_sessions', [
            'status'       => $status,
            'completed_at' => slate_db_now(),
        ], "id = ? AND tenant_id = ? AND status <> 'completed'", [$id, current_tenant_id()]);
    }

    // ── Schema helpers ────────────────────────────────────────

    private static function ensureColumn(string $column, string $definition): void
    {
        $has = (int) \Database::value(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stripepayment_charges' AND COLUMN_NAME = ?",
            [$column]
        );
        if ($has === 0) {
            \Database::get()->exec("ALTER TABLE `stripepayment_charges` ADD COLUMN `{$column}` {$definition}");
        }
    }

    private static function ensureIndex(string $name, string $definition): void
    {
        $has = (int) \Database::value(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stripepayment_charges' AND INDEX_NAME = ?",
            [$name]
        );
        if ($has === 0) {
            \Database::get()->exec("ALTER TABLE `stripepayment_charges` ADD {$definition}");
        }
    }
}
