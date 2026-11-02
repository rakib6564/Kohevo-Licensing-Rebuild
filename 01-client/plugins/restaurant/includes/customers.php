<?php
/**
 * Restaurant — customer history, archiving, and CSV import / export.
 *
 * History is computed, not counted: an aggregate over the customer's paid
 * and closed orders (spend = order total − comp − refunds), so it always
 * agrees with the orders after a void, refund, merge or reopen. The
 * (tenant_id, customer_id, status) index keeps it quick at restaurant
 * volumes. History imported from another system (Total Spend, visits,
 * First Seen, Last Visit) sits in the hist_* columns and is merged on read:
 * spend and visits add, last visit takes the later date, first seen the
 * earlier. Average spend is always derived (spend ÷ visits), never stored.
 *
 * The import accepts the customer export of common POS systems (Name,
 * Member ID, Email, Phone, Avg. Spend, Total Spend, Last Visit, First Seen)
 * and the export writes the same columns, so data leaves the way it came.
 */

require_once __DIR__ . '/reports.php';

class RestaurantCustomers {

    public const COLUMNS = ['Name', 'Member ID', 'Email', 'Phone', 'Avg. Spend', 'Total Spend', 'Last Visit', 'First Seen'];

    /** Digits only, without a leading US/Canada 1 on an 11-digit number. '' when there are none. */
    public static function normalizePhone(?string $phone): string {
        $d = preg_replace('/\D+/', '', (string)$phone);
        return (strlen($d) === 11 && $d[0] === '1') ? substr($d, 1) : $d;
    }

    /** What to show for a customer: the name, else the email, else the phone, else the record number. */
    public static function displayName(array $c): string {
        foreach (['name', 'email', 'phone'] as $k) {
            if (trim((string)($c[$k] ?? '')) !== '') return trim((string)$c[$k]);
        }
        return '#' . (int)($c['id'] ?? 0);
    }

    /** SQL for the per-customer aggregate over Kohevo orders (bind the tenant id once). */
    private const HISTORY_SQL = "SELECT customer_id, COUNT(*) AS k_orders,
                                        SUM(CAST(total_cents AS SIGNED) - CAST(comp_cents AS SIGNED) - CAST(refunded_cents AS SIGNED)) AS k_spend,
                                        MIN(opened_at) AS k_first, MAX(COALESCE(closed_at, opened_at)) AS k_last
                                   FROM restaurant_orders
                                  WHERE tenant_id = ? AND customer_id IS NOT NULL AND status IN ('paid','closed')
                               GROUP BY customer_id";

    /** Add the merged figures (orders, spend, avg, first_seen, last_visit) to a row carrying k_* and hist_* columns. */
    private static function merge(array $c): array {
        $orders = (int)($c['k_orders'] ?? 0) + (int)$c['hist_orders'];
        $spend  = (int)($c['k_spend'] ?? 0) + (int)$c['hist_spend_cents'];
        $dates  = fn(array $v) => array_values(array_filter($v, fn($x) => $x !== null && $x !== ''));
        $last   = $dates([isset($c['k_last']) ? substr((string)$c['k_last'], 0, 10) : null, $c['hist_last_visit'] ?? null]);
        $first  = $dates([isset($c['k_first']) ? substr((string)$c['k_first'], 0, 10) : null, $c['hist_first_seen'] ?? null]);
        $c['orders']     = $orders;
        $c['spend']      = $spend;
        $c['avg']        = $orders > 0 ? (int) round($spend / $orders) : 0;
        $c['last_visit'] = $last ? max($last) : null;
        $c['first_seen'] = $first ? min($first) : null;
        $c['display']    = self::displayName($c);
        return $c;
    }

    /**
     * Customers with their merged history. $o: q (name, phone, email or
     * member ID), sort (name | spend | visit), dir (asc | desc), archived
     * (true lists archived customers instead of active ones), limit, offset.
     * Returns ['rows', 'count'].
     */
    public static function list(array $o = []): array {
        $tid   = current_tenant_id();
        $where = ['c.tenant_id = ?', 'c.is_active = ' . (!empty($o['archived']) ? '0' : '1')];
        $args  = [$tid, $tid];
        $q = trim((string)($o['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $where[] = '(c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.member_ref LIKE ?)';
            array_push($args, $like, $like, $like, $like);
            $digits = self::normalizePhone($q);
            if (strlen($digits) >= 4 && strlen($digits) === strlen(preg_replace('/[\s().+-]/', '', $q))) {
                // A phone typed in another format still finds the customer.
                $where[count($where) - 1] = substr($where[count($where) - 1], 0, -1)
                    . " OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.phone,' ',''),'-',''),'(',''),')',''),'.','') LIKE ?)";
                $args[] = '%' . $digits . '%';
            }
        }
        $dir  = strtolower((string)($o['dir'] ?? '')) === 'asc' ? 'ASC' : 'DESC';
        $sort = match ($o['sort'] ?? 'name') {
            'spend' => "(COALESCE(h.k_spend,0) + c.hist_spend_cents) {$dir}, c.id",
            'visit' => "GREATEST(COALESCE(DATE(h.k_last),'1000-01-01'), COALESCE(c.hist_last_visit,'1000-01-01')) {$dir}, c.id",
            default => "CASE WHEN c.name = '' THEN 1 ELSE 0 END, c.name " . (($o['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC') . ", c.id",
        };
        $from = "FROM restaurant_customers c LEFT JOIN (" . self::HISTORY_SQL . ") h ON h.customer_id = c.id
                WHERE " . implode(' AND ', $where);
        $count = (int) Database::value("SELECT COUNT(*) {$from}", $args);
        $rows  = Database::rows("SELECT c.*, h.k_orders, h.k_spend, h.k_first, h.k_last {$from} ORDER BY {$sort}
                                 LIMIT " . max(1, (int)($o['limit'] ?? 50)) . " OFFSET " . max(0, (int)($o['offset'] ?? 0)), $args);
        return ['rows' => array_map([self::class, 'merge'], $rows), 'count' => $count];
    }

    /** One customer with merged history, or null. */
    public static function get(int $id): ?array {
        $tid = current_tenant_id();
        $row = Database::row(
            "SELECT c.*, h.k_orders, h.k_spend, h.k_first, h.k_last
               FROM restaurant_customers c LEFT JOIN (" . self::HISTORY_SQL . ") h ON h.customer_id = c.id
              WHERE c.tenant_id = ? AND c.id = ?", [$tid, $tid, $id]);
        return $row ? self::merge($row) : null;
    }

    /** A customer's latest orders of any status, newest first. */
    public static function recentOrders(int $customerId, int $limit = 5): array {
        return Database::rows(
            "SELECT id, order_no, type, source, status, total_cents, refunded_cents, comp_cents, opened_at, closed_at
               FROM restaurant_orders WHERE tenant_id = ? AND customer_id = ?
           ORDER BY opened_at DESC, id DESC LIMIT " . max(1, $limit), [current_tenant_id(), $customerId]);
    }

    /**
     * Delete a customer without losing history: one that any order points at
     * is archived (kept, marked inactive, hidden from the guest lookup), one
     * with no orders is removed. Returns 'archived', 'deleted' or 'missing'.
     */
    public static function delete(int $id): string {
        $tid = current_tenant_id();
        if (!Database::value("SELECT 1 FROM restaurant_customers WHERE id = ? AND tenant_id = ?", [$id, $tid])) return 'missing';
        if (Database::value("SELECT 1 FROM restaurant_orders WHERE customer_id = ? AND tenant_id = ? LIMIT 1", [$id, $tid])) {
            Database::update('restaurant_customers', ['is_active' => 0, 'archived_at' => slate_db_now()],
                'id = ? AND tenant_id = ?', [$id, $tid]);
            AuditLog::record('restaurant.customer_archived', (string)$id);
            return 'archived';
        }
        Database::delete('restaurant_customers', 'id = ? AND tenant_id = ?', [$id, $tid]);
        AuditLog::record('restaurant.customer_deleted', (string)$id);
        return 'deleted';
    }

    public static function restore(int $id): void {
        Database::update('restaurant_customers', ['is_active' => 1, 'archived_at' => null],
            'id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
        AuditLog::record('restaurant.customer_restored', (string)$id);
    }

    // ── Import / export ────────────────────────────────────

    /** "$1,234.50", "1234.5" or "" (= 0) to cents; null when it is not an amount. */
    public static function parseMoney(string $v): ?int {
        $v = str_replace(['$', ',', ' ', "\u{00A0}"], '', trim($v));
        if ($v === '') return 0;
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $v)) return null;
        return (int) round((float)$v * 100);
    }

    /** "2024-03-09" or "3/9/2024" to Y-m-d; '' for blank; null when it is not a date. */
    public static function parseDate(string $v): ?string {
        $v = trim($v);
        if ($v === '') return '';
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m))       [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m))   [$mo, $d, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        else return null;
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /**
     * Read a CSV and decide what each row would do, writing nothing.
     * Returns ['rows' => planned rows, 'errors' => [[line, reason]],
     * 'creates', 'updates', 'skipped', 'fatal' => message|null]. Line
     * numbers are those of the file (a quoted name that wraps over two lines
     * counts both), so the owner can find each bad row. A bad row is
     * reported and left out; it never stops the good ones.
     *
     * Matching, per row: same phone (digits compared, so "(555) 010-2000"
     * and "5550102000" match), else same Member ID, else same email
     * (ignoring case) → update that customer; otherwise create one. Rows
     * earlier in the same file count, so a file never creates two customers
     * with one phone.
     */
    public static function plan(string $csv): array {
        $out = ['rows' => [], 'errors' => [], 'creates' => 0, 'updates' => 0, 'skipped' => 0, 'fatal' => null];
        if (str_starts_with($csv, "\xEF\xBB\xBF")) $csv = substr($csv, 3);
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, $csv);
        rewind($fh);

        $line   = 1;
        $header = fgetcsv($fh, 0, ',', '"', '');
        $line  += substr_count(substr($csv, 0, (int)ftell($fh)), "\n");
        if (!$header) { $out['fatal'] = __('restaurant_imp_err_empty', 'The file is empty.'); return $out; }
        $col = [];
        foreach ($header as $i => $h) $col[strtolower(trim((string)$h))] = $i;
        $idx = fn(string $name) => $col[strtolower($name)] ?? null;
        if ($idx('Name') === null && $idx('Email') === null && $idx('Phone') === null && $idx('Member ID') === null) {
            $out['fatal'] = __('restaurant_imp_err_header', 'The first row must name the columns (Name, Member ID, Email, Phone, Avg. Spend, Total Spend, Last Visit, First Seen).');
            return $out;
        }

        // Existing customers, keyed the three ways a row can match.
        $tid = current_tenant_id();
        $byPhone = $byRef = $byEmail = [];
        foreach (Database::rows("SELECT id, phone, member_ref, email FROM restaurant_customers WHERE tenant_id = ? ORDER BY id", [$tid]) as $c) {
            $p = self::normalizePhone($c['phone']);
            if ($p !== '' && !isset($byPhone[$p])) $byPhone[$p] = ['id' => (int)$c['id']];
            if (trim((string)$c['member_ref']) !== '' && !isset($byRef[$c['member_ref']])) $byRef[trim((string)$c['member_ref'])] = ['id' => (int)$c['id']];
            $e = strtolower(trim((string)$c['email']));
            if ($e !== '' && !isset($byEmail[$e])) $byEmail[$e] = ['id' => (int)$c['id']];
        }

        while (true) {
            $start = $line;
            $pos   = (int) ftell($fh);
            $cells = fgetcsv($fh, 0, ',', '"', '');
            if ($cells === false) break;
            $line += substr_count(substr($csv, $pos, (int)ftell($fh) - $pos), "\n");
            $get = fn(string $name) => ($i = $idx($name)) !== null ? trim((string)($cells[$i] ?? '')) : '';
            if (implode('', array_map('trim', array_map('strval', $cells))) === '') { $out['skipped']++; continue; }

            $name  = preg_replace('/\s+/u', ' ', $get('Name'));
            $email = $get('Email');
            $phone = $get('Phone');
            $ref   = $get('Member ID');
            $total = self::parseMoney($get('Total Spend'));
            $avg   = self::parseMoney($get('Avg. Spend'));
            $last  = self::parseDate($get('Last Visit'));
            $first = self::parseDate($get('First Seen'));

            $why = [];
            if ($name === '' && $email === '' && $phone === '' && $ref === '') $why[] = __('restaurant_imp_err_blank', 'no name, email, phone or member ID');
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) $why[] = sprintf(__('restaurant_imp_err_email', 'email "%s" is not valid'), $email);
            if ($phone !== '' && strlen(self::normalizePhone($phone)) < 7) $why[] = sprintf(__('restaurant_imp_err_phone', 'phone "%s" has too few digits'), $phone);
            if ($total === null) $why[] = sprintf(__('restaurant_imp_err_money', '%1$s "%2$s" is not an amount'), 'Total Spend', $get('Total Spend'));
            if ($avg === null)   $why[] = sprintf(__('restaurant_imp_err_money', '%1$s "%2$s" is not an amount'), 'Avg. Spend', $get('Avg. Spend'));
            if ($last === null)  $why[] = sprintf(__('restaurant_imp_err_date', '%1$s "%2$s" is not a date (use 2024-03-09 or 3/9/2024)'), 'Last Visit', $get('Last Visit'));
            if ($first === null) $why[] = sprintf(__('restaurant_imp_err_date', '%1$s "%2$s" is not a date (use 2024-03-09 or 3/9/2024)'), 'First Seen', $get('First Seen'));
            if ($why) { $out['errors'][] = [$start, implode('; ', $why)]; continue; }

            // Visits are not in the file; they follow from Total ÷ Avg. (at least one when anything was spent).
            $visits = $total > 0 ? ($avg > 0 ? max(1, (int) round($total / $avg)) : 1) : 0;

            $p = self::normalizePhone($phone);
            $e = strtolower($email);
            $hit = ($p !== '' ? ($byPhone[$p] ?? null) : null) ?? ($ref !== '' ? ($byRef[$ref] ?? null) : null) ?? ($e !== '' ? ($byEmail[$e] ?? null) : null);
            $row = ['line' => $start, 'name' => mb_substr($name, 0, 160), 'email' => mb_substr($email, 0, 200),
                    'phone' => mb_substr($phone, 0, 40), 'member_ref' => mb_substr($ref, 0, 80),
                    'hist_spend_cents' => $total, 'hist_orders' => $visits,
                    'hist_last_visit' => $last ?: null, 'hist_first_seen' => $first ?: null];
            if ($hit) {
                $row['action'] = 'update';
                $row['target'] = $hit;           // an existing id, or ['new' => n] for an earlier row of this file
                $out['updates']++;
            } else {
                $row['action'] = 'create';
                $hit = ['new' => count($out['rows'])];
                $out['creates']++;
            }
            if ($p !== '' && !isset($byPhone[$p])) $byPhone[$p] = $hit;
            if ($ref !== '' && !isset($byRef[$ref])) $byRef[$ref] = $hit;
            if ($e !== '' && !isset($byEmail[$e])) $byEmail[$e] = $hit;
            $out['rows'][] = $row;
        }
        fclose($fh);
        return $out;
    }

    /**
     * Import a CSV: plan it, then write every good row in one transaction and
     * audit the counts. An update overwrites the imported history with the
     * file's (so importing the same file twice changes nothing), fills in a
     * name, email or member ID the file has, and adds a phone only where the
     * customer had none. Returns the plan's counts plus 'written'.
     */
    public static function import(string $csv): array {
        $plan = self::plan($csv);
        if ($plan['fatal'] !== null) return $plan + ['written' => 0];
        $tid = current_tenant_id();
        $ids = [];
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            foreach ($plan['rows'] as $n => $r) {
                $hist = array_intersect_key($r, array_flip(['hist_spend_cents', 'hist_orders', 'hist_last_visit', 'hist_first_seen']));
                if ($r['action'] === 'create') {
                    $ids[$n] = Database::insert('restaurant_customers', $hist + [
                        'tenant_id' => $tid, 'name' => $r['name'], 'email' => $r['email'] ?: null,
                        'phone' => $r['phone'] ?: null, 'member_ref' => $r['member_ref'] ?: null,
                    ]);
                    continue;
                }
                $id  = isset($r['target']['new']) ? $ids[$r['target']['new']] : (int)$r['target']['id'];
                $cur = Database::row("SELECT name, email, phone, member_ref FROM restaurant_customers WHERE id = ? AND tenant_id = ?", [$id, $tid]);
                $set = $hist;
                if ($r['name'] !== '')       $set['name'] = $r['name'];
                if ($r['email'] !== '')      $set['email'] = $r['email'];
                if ($r['member_ref'] !== '') $set['member_ref'] = $r['member_ref'];
                if ($r['phone'] !== '' && trim((string)($cur['phone'] ?? '')) === '') $set['phone'] = $r['phone'];
                Database::update('restaurant_customers', $set, 'id = ? AND tenant_id = ?', [$id, $tid]);
                $ids[$n] = $id;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        AuditLog::record('restaurant.customers_imported', (string)count($plan['rows']), [
            'created' => $plan['creates'], 'updated' => $plan['updates'], 'errors' => count($plan['errors'])]);
        return $plan + ['written' => count($plan['rows'])];
    }

    /**
     * Every customer (archived ones too) in the import's column shape, with
     * merged history: Total Spend and visits include Kohevo orders, so the
     * file is the full record to take elsewhere. Importing it into another
     * install recreates the same file; importing it back into this one would
     * count the Kohevo orders twice.
     */
    public static function exportCsv(): string {
        $tid = current_tenant_id();
        $fh  = fopen('php://temp', 'w+');
        fputcsv($fh, self::COLUMNS, ',', '"', '');
        $m = fn(int $c) => number_format($c / 100, 2, '.', '');
        foreach (Database::rows(
            "SELECT c.*, h.k_orders, h.k_spend, h.k_first, h.k_last
               FROM restaurant_customers c LEFT JOIN (" . self::HISTORY_SQL . ") h ON h.customer_id = c.id
              WHERE c.tenant_id = ? ORDER BY c.id", [$tid, $tid]) as $c) {
            $c = self::merge($c);
            fputcsv($fh, array_map([RestaurantReports::class, 'csvSafe'], [
                (string)$c['name'], (string)($c['member_ref'] ?? ''), (string)($c['email'] ?? ''), (string)($c['phone'] ?? ''),
                $c['orders'] > 0 ? $m($c['avg']) : '', $c['spend'] > 0 || $c['orders'] > 0 ? $m($c['spend']) : '',
                (string)($c['last_visit'] ?? ''), (string)($c['first_seen'] ?? ''),
            ]));
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }
}
