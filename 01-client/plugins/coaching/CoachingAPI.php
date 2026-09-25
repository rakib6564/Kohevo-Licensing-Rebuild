<?php
/**
 * Coaching — public API.
 *
 * DB access + auto-computed metric derivation live here. Other plugins
 * (or later Coaching pages / waves) go through CoachingAPI::* and
 * never touch tables directly.
 *
 * Access control: no `isEnrolled()` gating at the API layer — the API
 * exposes reads and writes, page controllers gate. This keeps API
 * calls composable when the practitioner acts on behalf of a client.
 */

class CoachingAPI {

    // ── Schema self-heal ─────────────────────────────────────────────────
    // CREATE TABLE IF NOT EXISTS is idempotent for brand-new tables, but it
    // is a silent no-op on a table that already exists — so a column added
    // to install.sql in a later coaching "wave" never actually reaches an
    // install whose coaching_profile/coaching_goal tables were created by
    // an earlier version of this file. Unlike Booking/Shop/Membership etc.,
    // this plugin had no ensureColumn()-style healing at all, so that drift
    // was permanent until reinstalled from scratch. `coaching_profile` and
    // `coaching_goal` are the two tables client.php reads unconditionally
    // (getProfile()/listGoals(), not wrapped in the page's own $optional()
    // fail-closed helper — see admin/client.php), so a column missing from
    // either turns the whole per-client page into an uncaught PDOException
    // -> HTTP 500 the moment that column is referenced by name (e.g.
    // listGoals()'s `ORDER BY sort_order`), even though the row/table
    // itself exists. Healing both explicitly here closes that gap.
    public static function ensureSchema(): void {
        $sql = @file_get_contents(__DIR__ . '/install.sql');
        if ($sql === false || $sql === '') return;
        foreach (self::splitSqlStatements($sql) as $stmt) {
            try { Database::query($stmt); }
            catch (\Throwable $e) {
                slate_log('Coaching schema replay failed: ' . $e->getMessage(), 'warning');
            }
        }
        self::healColumns();
    }

    /** Add any column declared in install.sql's coaching_profile/coaching_goal
     *  tables that a pre-existing (older) copy of either table is missing. */
    private static function healColumns(): void {
        $columns = [
            'coaching_profile' => [
                'dob'                  => 'DATE NULL',
                'gender'               => "ENUM('female','male','other','undisclosed') NULL",
                'height_cm'            => 'DECIMAL(5,1) NULL',
                'weight_kg'            => 'DECIMAL(5,1) NULL',
                'body_measurements'    => 'JSON NULL',
                'body_type'            => 'VARCHAR(80) NULL',
                'intolerances'         => 'JSON NULL',
                'dietary_preferences'  => 'JSON NULL',
                'pathologies'          => 'TEXT NULL',
                'ongoing_care'         => 'TEXT NULL',
                'alternative_medicine' => 'TEXT NULL',
                'personal_issues'      => 'TEXT NULL',
                'therapist_contact'    => 'JSON NULL',
                'bmi'                  => 'DECIMAL(5,2) NULL',
                'bmr'                  => 'INT UNSIGNED NULL',
                'tdee'                 => 'INT UNSIGNED NULL',
                'show_computed'        => 'TINYINT(1) NOT NULL DEFAULT 0',
                'activity_factor'      => 'DECIMAL(3,2) NOT NULL DEFAULT 1.40',
                'has_meal_structure'   => 'TINYINT(1) NOT NULL DEFAULT 0',
                'has_shopping_list'    => 'TINYINT(1) NOT NULL DEFAULT 0',
                'has_recipes'          => 'TINYINT(1) NOT NULL DEFAULT 0',
            ],
            'coaching_goal' => [
                'target_count' => 'INT UNSIGNED NULL',
                'sort_order'   => 'INT NOT NULL DEFAULT 0',
                'is_active'    => 'TINYINT(1) NOT NULL DEFAULT 1',
                'retired_at'   => 'DATETIME NULL',
            ],
        ];
        foreach ($columns as $table => $cols) {
            foreach ($cols as $col => $def) {
                self::ensureColumn($table, $col, $def);
            }
        }
    }

    private static function ensureColumn(string $table, string $column, string $definition): void {
        try {
            $exists = Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$table, $column]
            );
            if ((int)$exists === 0) {
                Database::query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        } catch (\Throwable $e) {
            slate_log("Coaching ensureColumn {$table}.{$column} failed: " . $e->getMessage(), 'error');
        }
    }

    private static function splitSqlStatements(string $sql): array {
        $out = [];
        foreach (preg_split("/;\s*[\r\n]/", $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '' && stripos($stmt, 'CREATE') !== false) $out[] = $stmt;
        }
        return $out;
    }

    // ── Enrollment (delegates to membership) ─────────────────────────────

    /**
     * Is this customer currently enrolled in the 3-month program?
     * Enrollment means an active, unexpired subscription to the specific
     * plan an admin picked in Coaching → Settings ("Program access gate"),
     * not just any active membership — MembershipAPI::isActive() only
     * recognizes 'membership'-type plans and ignores the configured plan
     * entirely, which is wrong when (as here) the gate plan is course-type.
     * Without a plan configured, no client is enrolled (see admin/settings.php).
     */
    public static function isEnrolled(int $customerId): bool {
        if ($customerId <= 0) return false;
        if (!class_exists('MembershipAPI')) return false;
        $gatePlanId = (int) Database::setting('coaching.program_plan_id');
        if ($gatePlanId <= 0) return false;
        try {
            $sub = MembershipAPI::activeSubscription($customerId);
            return $sub !== null && (int)$sub['plan_id'] === $gatePlanId;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ── Profile CRUD ─────────────────────────────────────────────────────

    public static function getProfile(int $customerId): ?array {
        if ($customerId <= 0) return null;
        $tid = current_tenant_id();
        $row = Database::row(
            "SELECT * FROM coaching_profile
              WHERE tenant_id = ? AND customer_id = ? LIMIT 1",
            [$tid, $customerId]
        );
        if (!$row) return null;
        foreach (['body_measurements','intolerances','dietary_preferences','therapist_contact'] as $k) {
            if (isset($row[$k]) && is_string($row[$k]) && $row[$k] !== '') {
                $row[$k] = json_decode($row[$k], true) ?? [];
            }
        }
        return $row;
    }

    /** Provision an empty profile row on customer_registered. */
    public static function provisionProfile(int $customerId): int {
        if ($customerId <= 0) return 0;
        $tid = current_tenant_id();
        $existing = Database::row(
            "SELECT id FROM coaching_profile WHERE tenant_id = ? AND customer_id = ?",
            [$tid, $customerId]
        );
        if ($existing) return (int)$existing['id'];
        return (int) Database::insert('coaching_profile', [
            'tenant_id'   => $tid,
            'customer_id' => $customerId,
        ]);
    }

    /**
     * Upsert profile fields. JSON columns are re-encoded here.
     * Auto-computed BMI / BMR / TDEE are refreshed whenever height, weight,
     * dob or gender change.
     */
    public static function saveProfile(int $customerId, array $fields): int {
        if ($customerId <= 0) return 0;
        $tid = current_tenant_id();

        $allowed = [
            'dob','gender','height_cm','weight_kg',
            'body_measurements','body_type','intolerances','dietary_preferences',
            'pathologies','ongoing_care','alternative_medicine','personal_issues','therapist_contact',
            'show_computed','activity_factor',
            'has_meal_structure','has_shopping_list','has_recipes',
        ];
        $data = [];
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $fields)) continue;
            $v = $fields[$k];
            if (in_array($k, ['body_measurements','intolerances','dietary_preferences','therapist_contact'], true)) {
                $data[$k] = $v === null ? null : json_encode($v);
            } elseif (in_array($k, ['show_computed','has_meal_structure','has_shopping_list','has_recipes'], true)) {
                $data[$k] = $v ? 1 : 0;
            } else {
                $data[$k] = $v === '' ? null : $v;
            }
        }

        // Ensure a row exists.
        $existingId = self::provisionProfile($customerId);

        if ($data) {
            Database::update('coaching_profile', $data, 'id = ?', [$existingId]);
        }

        // Recompute derived metrics after write.
        self::recomputeMetrics($customerId);
        return $existingId;
    }

    /**
     * BMI = weight / height^2   (weight kg, height m)
     * BMR = Mifflin-St Jeor
     * TDEE = BMR × activity_factor
     */
    public static function recomputeMetrics(int $customerId): void {
        $tid = current_tenant_id();
        $p = Database::row(
            "SELECT id, dob, gender, height_cm, weight_kg, activity_factor
               FROM coaching_profile WHERE tenant_id = ? AND customer_id = ?",
            [$tid, $customerId]
        );
        if (!$p) return;

        $bmi = null; $bmr = null; $tdee = null;
        $h = (float)($p['height_cm'] ?? 0);
        $w = (float)($p['weight_kg'] ?? 0);
        if ($h > 0 && $w > 0) {
            $hm = $h / 100;
            $bmi = round($w / ($hm * $hm), 2);
        }

        $age = self::ageFromDob((string)($p['dob'] ?? ''));
        if ($h > 0 && $w > 0 && $age !== null) {
            $base = 10 * $w + 6.25 * $h - 5 * $age;
            $offset = ($p['gender'] === 'female') ? -161 : (($p['gender'] === 'male') ? 5 : -78); // "other/undisclosed" midpoint
            $bmr = (int) round($base + $offset);
            $af  = (float) ($p['activity_factor'] ?? 1.4);
            $tdee = (int) round($bmr * ($af > 0 ? $af : 1.4));
        }

        Database::update('coaching_profile',
            ['bmi' => $bmi, 'bmr' => $bmr, 'tdee' => $tdee],
            'id = ?', [(int)$p['id']]
        );
    }

    private static function ageFromDob(string $dob): ?int {
        if ($dob === '' || $dob === '0000-00-00') return null;
        $ts = strtotime($dob);
        if ($ts === false) return null;
        $years = (int) ((time() - $ts) / (365.25 * 86400));
        return $years > 0 && $years < 130 ? $years : null;
    }

    // ── Goals ────────────────────────────────────────────────────────────

    public static function listGoals(int $customerId, ?string $scope = null, bool $activeOnly = true): array {
        $tid = current_tenant_id();
        $sql = "SELECT * FROM coaching_goal WHERE tenant_id = ? AND customer_id = ?";
        $args = [$tid, $customerId];
        if ($activeOnly) $sql .= " AND is_active = 1";
        if ($scope !== null) { $sql .= " AND scope = ?"; $args[] = $scope; }
        $sql .= " ORDER BY sort_order, id";
        return Database::rows($sql, $args);
    }

    public static function saveGoal(array $fields): int {
        $tid = current_tenant_id();
        $id  = (int)($fields['id'] ?? 0);
        $data = [
            'customer_id'  => (int)($fields['customer_id'] ?? 0),
            'scope'        => (string)($fields['scope'] ?? 'daily'),
            'title'        => mb_substr(trim((string)($fields['title'] ?? '')), 0, 200),
            'description'  => trim((string)($fields['description'] ?? '')) ?: null,
            'target_count' => isset($fields['target_count']) && $fields['target_count'] !== '' ? max(0, (int)$fields['target_count']) : null,
            'sort_order'   => (int)($fields['sort_order'] ?? 0),
            'is_active'    => !empty($fields['is_active']) ? 1 : 0,
        ];
        if ($data['title'] === '') return 0;

        if ($id > 0) {
            Database::update('coaching_goal', $data, 'id = ? AND tenant_id = ?', [$id, $tid]);
            return $id;
        }
        $data['tenant_id'] = $tid;
        return (int) Database::insert('coaching_goal', $data);
    }

    public static function retireGoal(int $goalId): bool {
        $tid = current_tenant_id();
        return (bool) Database::update('coaching_goal',
            ['is_active' => 0, 'retired_at' => slate_db_now()],
            'id = ? AND tenant_id = ?', [$goalId, $tid]
        );
    }

    public static function recordCheckIn(int $customerId, int $goalId, string $day, string $status, string $note = ''): int {
        if (!in_array($status, ['not_achieved','partial','achieved','exceeded'], true)) {
            $status = 'not_achieved';
        }
        $tid = current_tenant_id();

        // goal_id is caller-supplied and otherwise unchecked — verify the
        // goal itself belongs to this customer/tenant before touching any
        // check-in row for it. Without this, the lookup below matched on
        // goal_id+day alone, so submitting someone else's goal_id let a
        // customer overwrite that goal's existing check-in (status/note)
        // for that day, no matter who it actually belonged to.
        $goal = Database::row(
            'SELECT id FROM coaching_goal WHERE id = ? AND customer_id = ? AND tenant_id = ?',
            [$goalId, $customerId, $tid]
        );
        if (!$goal) return 0;

        $existing = Database::row(
            'SELECT id FROM coaching_goal_checkin WHERE goal_id = ? AND customer_id = ? AND tenant_id = ? AND day = ?',
            [$goalId, $customerId, $tid, $day]
        );
        if ($existing) {
            Database::update('coaching_goal_checkin',
                ['status' => $status, 'note' => $note ?: null],
                'id = ? AND customer_id = ? AND tenant_id = ?', [(int)$existing['id'], $customerId, $tid]
            );
            return (int)$existing['id'];
        }
        return (int) Database::insert('coaching_goal_checkin', [
            'tenant_id'   => $tid,
            'customer_id' => $customerId,
            'goal_id'     => $goalId,
            'day'         => $day,
            'status'      => $status,
            'note'        => $note ?: null,
        ]);
    }

    public static function recordExtraAction(int $customerId, string $day, string $actionText): int {
        $actionText = trim($actionText);
        if ($actionText === '') return 0;
        $tid = current_tenant_id();
        return (int) Database::insert('coaching_extra_action', [
            'tenant_id'   => $tid,
            'customer_id' => $customerId,
            'day'         => $day,
            'action_text' => mb_substr($actionText, 0, 500),
        ]);
    }

    // ── Diary (Wave 2) ───────────────────────────────────────────────────

    /** Categories used for manual food classification (Option 2 in the spec). */
    public static function foodCategories(): array {
        return [
            'fruits_vegetables' => 'Fruits / Vegetables',
            'starches'          => 'Starches',
            'proteins'          => 'Proteins',
            'dairy'             => 'Dairy',
            'fats'              => 'Fats',
            'pleasure'          => 'Pleasure',
            'other'             => 'Other',
        ];
    }

    /** Emotion vocabulary — matches the spec's fixed list. */
    public static function emotions(): array {
        return [
            'joy'         => 'Joy',
            'stress'      => 'Stress',
            'fatigue'     => 'Fatigue',
            'anxiety'     => 'Anxiety',
            'boredom'     => 'Boredom',
            'anger'       => 'Anger',
            'sadness'     => 'Sadness',
            'serenity'    => 'Serenity',
            'neutrality'  => 'Neutral',
            'other'       => 'Other',
        ];
    }

    public static function contexts(): array {
        return [
            'home'       => 'Home',
            'work'       => 'Work',
            'friends'    => 'With friends',
            'family'     => 'With family',
            'restaurant' => 'Restaurant',
            'commute'    => 'Commute',
            'other'      => 'Other',
        ];
    }

    /**
     * Upsert a diary entry. When $fields['id'] is set, updates that row;
     * otherwise inserts. Returns the entry id.
     * Foods are replaced wholesale via $fields['foods'] (array of
     * ['name' => ..., 'category' => ..., 'is_pleasure_food' => bool]).
     */
    public static function saveDiaryEntry(int $customerId, array $fields): int {
        if ($customerId <= 0) return 0;
        $tid = current_tenant_id();
        $id  = (int)($fields['id'] ?? 0);

        $data = [
            'day'            => (string)($fields['day'] ?? date('Y-m-d')),
            'meal_type'      => in_array($fields['meal_type'] ?? '', ['breakfast','lunch','dinner','snack','binge','drink','other'], true)
                              ? (string)$fields['meal_type'] : 'other',
            'started_at'     => !empty($fields['started_at']) ? (string)$fields['started_at'] : null,
            'duration_min'   => isset($fields['duration_min']) && $fields['duration_min'] !== '' ? max(0, (int)$fields['duration_min']) : null,
            'emotion'        => in_array($fields['emotion'] ?? '', array_keys(self::emotions()), true) ? (string)$fields['emotion'] : null,
            'emotion_note'   => !empty($fields['emotion_note']) ? mb_substr((string)$fields['emotion_note'], 0, 500) : null,
            'hunger_before'  => isset($fields['hunger_before']) && $fields['hunger_before'] !== '' ? max(1, min(5, (int)$fields['hunger_before'])) : null,
            'satiety_after'  => isset($fields['satiety_after']) && $fields['satiety_after'] !== '' ? max(1, min(5, (int)$fields['satiety_after'])) : null,
            'context'        => in_array($fields['context'] ?? '', array_keys(self::contexts()), true) ? (string)$fields['context'] : null,
            'context_note'   => !empty($fields['context_note']) ? mb_substr((string)$fields['context_note'], 0, 200) : null,
            'quantity_note'  => !empty($fields['quantity_note']) ? mb_substr((string)$fields['quantity_note'], 0, 300) : null,
            'notes'          => !empty($fields['notes']) ? (string)$fields['notes'] : null,
        ];

        if ($id > 0) {
            // Verify ownership BEFORE touching anything, rather than relying on
            // the UPDATE's own WHERE clause: Database::update()'s rowCount()
            // reflects rows CHANGED, not rows matched (a PDO/MySQL quirk), so a
            // no-op re-save of unchanged fields against an owned row would read
            // as "0 affected" and be indistinguishable from a foreign id. A
            // foreign $id must stop here — the foods replace below used to run
            // unconditionally on $id regardless of whether this matched.
            $owned = Database::row(
                'SELECT id FROM coaching_diary_entry WHERE id = ? AND customer_id = ? AND tenant_id = ?',
                [$id, $customerId, $tid]
            );
            if (!$owned) return 0;
            Database::update('coaching_diary_entry', $data, 'id = ? AND customer_id = ? AND tenant_id = ?', [$id, $customerId, $tid]);
        } else {
            $data['tenant_id']   = $tid;
            $data['customer_id'] = $customerId;
            $id = (int) Database::insert('coaching_diary_entry', $data);
        }

        // Foods — replace-all.
        if (isset($fields['foods']) && is_array($fields['foods'])) {
            Database::delete('coaching_diary_food', 'entry_id = ?', [$id]);
            $i = 0;
            foreach ($fields['foods'] as $f) {
                $name = trim((string)($f['name'] ?? ''));
                if ($name === '') continue;
                Database::insert('coaching_diary_food', [
                    'tenant_id'        => $tid,
                    'entry_id'         => $id,
                    'name'             => mb_substr($name, 0, 200),
                    'category'         => in_array($f['category'] ?? '', array_keys(self::foodCategories()), true) ? $f['category'] : null,
                    'is_pleasure_food' => !empty($f['is_pleasure_food']) ? 1 : 0,
                    'sort_order'       => $i++,
                ]);
            }
        }

        // Denormalize a short summary for feed views.
        self::refreshEntrySummary($id);
        return $id;
    }

    private static function refreshEntrySummary(int $entryId): void {
        $foods = Database::rows(
            "SELECT name FROM coaching_diary_food WHERE entry_id = ? ORDER BY sort_order LIMIT 4",
            [$entryId]
        );
        if (!$foods) return;
        $names = array_column($foods, 'name');
        $summary = mb_substr(implode(', ', $names), 0, 300);
        Database::update('coaching_diary_entry', ['summary' => $summary], 'id = ?', [$entryId]);
    }

    /**
     * $customerId is required, not optional: every caller is customer-facing
     * (plugins/coaching/customer/router.php) and already has the current
     * customer's own id in scope. An unscoped lookup here previously let the
     * AJAX save-entry response return ANY entry_id's full detail (notes,
     * foods, photos) regardless of who submitted the request.
     */
    public static function getDiaryEntry(int $entryId, int $customerId): ?array {
        $tid = current_tenant_id();
        $row = Database::row(
            'SELECT * FROM coaching_diary_entry WHERE id = ? AND customer_id = ? AND tenant_id = ?',
            [$entryId, $customerId, $tid]
        );
        if (!$row) return null;
        $row['foods']  = Database::rows("SELECT * FROM coaching_diary_food WHERE entry_id = ? ORDER BY sort_order", [$entryId]);
        $row['photos'] = Database::rows("SELECT * FROM coaching_diary_photo WHERE entry_id = ? ORDER BY sort_order", [$entryId]);
        return $row;
    }

    public static function listDiaryEntries(int $customerId, string $fromDay, string $toDay): array {
        $tid = current_tenant_id();
        return Database::rows(
            "SELECT * FROM coaching_diary_entry
              WHERE tenant_id = ? AND customer_id = ? AND day BETWEEN ? AND ?
              ORDER BY day DESC, COALESCE(started_at, '23:59:59') DESC, id DESC",
            [$tid, $customerId, $fromDay, $toDay]
        );
    }

    public static function deleteDiaryEntry(int $entryId, int $customerId): bool {
        $tid = current_tenant_id();
        $row = Database::row("SELECT id FROM coaching_diary_entry WHERE id = ? AND customer_id = ? AND tenant_id = ?",
            [$entryId, $customerId, $tid]);
        if (!$row) return false;
        Database::delete('coaching_diary_food',  'entry_id = ?', [$entryId]);
        // Photos: unlink files too, then rows.
        foreach (Database::rows("SELECT file_path FROM coaching_diary_photo WHERE entry_id = ?", [$entryId]) as $p) {
            $abs = SLATE_ROOT . '/' . ltrim((string)$p['file_path'], '/');
            if (is_file($abs)) @unlink($abs);
        }
        Database::delete('coaching_diary_photo', 'entry_id = ?', [$entryId]);
        Database::delete('coaching_diary_entry', 'id = ?', [$entryId]);
        return true;
    }

    /**
     * Save an uploaded photo file against a diary entry. $file is the
     * usual $_FILES['x'] shape. Returns the new photo id (or 0 on failure).
     */
    public static function saveDiaryPhoto(int $entryId, array $file, string $caption = ''): int {
        if ($entryId <= 0 || empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return 0;
        if (($file['size'] ?? 0) > 10 * 1024 * 1024) return 0; // 10MB cap

        // MIME sniff — only accept common image types.
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : mime_content_type($file['tmp_name']);
        if ($finfo) finfo_close($finfo);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($allowed[$mime])) return 0;
        $ext = $allowed[$mime];

        $tid = current_tenant_id();
        $entry = Database::row("SELECT customer_id, day FROM coaching_diary_entry WHERE id = ? AND tenant_id = ?", [$entryId, $tid]);
        if (!$entry) return 0;

        // Destination: uploads/coaching-diary/YYYY/MM/<uniq>.ext
        $day  = strtotime((string)$entry['day']) ?: time();
        $sub  = 'uploads/coaching-diary/' . date('Y/m', $day);
        $abs  = SLATE_ROOT . '/' . $sub;
        if (!is_dir($abs) && !@mkdir($abs, 0775, true)) return 0;
        $name = date('d-His', $day) . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $rel  = $sub . '/' . $name;
        if (!@move_uploaded_file($file['tmp_name'], SLATE_ROOT . '/' . $rel)) return 0;

        return (int) Database::insert('coaching_diary_photo', [
            'tenant_id'  => $tid,
            'entry_id'   => $entryId,
            'file_path'  => $rel,
            'caption'    => $caption !== '' ? mb_substr($caption, 0, 200) : null,
            'sort_order' => (int) Database::value("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM coaching_diary_photo WHERE entry_id = ?", [$entryId]),
        ]);
    }

    public static function deleteDiaryPhoto(int $photoId, int $customerId): bool {
        $tid = current_tenant_id();
        $p = Database::row(
            "SELECT p.id, p.file_path
               FROM coaching_diary_photo p
               JOIN coaching_diary_entry e ON e.id = p.entry_id
              WHERE p.id = ? AND e.customer_id = ? AND p.tenant_id = ?",
            [$photoId, $customerId, $tid]
        );
        if (!$p) return false;
        $abs = SLATE_ROOT . '/' . ltrim((string)$p['file_path'], '/');
        if (is_file($abs)) @unlink($abs);
        Database::delete('coaching_diary_photo', 'id = ?', [$photoId]);
        return true;
    }

    // ── Hydration (Wave 2) ───────────────────────────────────────────────

    public static function getHydration(int $customerId, string $day): ?array {
        $tid = current_tenant_id();
        return Database::row(
            "SELECT * FROM coaching_hydration WHERE tenant_id = ? AND customer_id = ? AND day = ?",
            [$tid, $customerId, $day]
        );
    }

    public static function upsertHydration(int $customerId, string $day, float $liters, int $glassCount, string $otherDrinks = ''): int {
        $tid = current_tenant_id();
        $existing = Database::row(
            "SELECT id FROM coaching_hydration WHERE tenant_id = ? AND customer_id = ? AND day = ?",
            [$tid, $customerId, $day]
        );
        $data = [
            'liters'       => max(0, $liters),
            'glass_count'  => max(0, $glassCount),
            'other_drinks' => $otherDrinks !== '' ? mb_substr($otherDrinks, 0, 500) : null,
        ];
        if ($existing) {
            Database::update('coaching_hydration', $data, 'id = ?', [(int)$existing['id']]);
            return (int)$existing['id'];
        }
        $data['tenant_id']   = $tid;
        $data['customer_id'] = $customerId;
        $data['day']         = $day;
        return (int) Database::insert('coaching_hydration', $data);
    }

    // ── Activity (Wave 2) ────────────────────────────────────────────────

    public static function listActivity(int $customerId, string $day): array {
        $tid = current_tenant_id();
        return Database::rows(
            "SELECT * FROM coaching_activity WHERE tenant_id = ? AND customer_id = ? AND day = ? ORDER BY id",
            [$tid, $customerId, $day]
        );
    }

    public static function addActivity(int $customerId, string $day, string $kind, ?int $durationMin, string $notes = ''): int {
        $kind = trim($kind);
        if ($kind === '') return 0;
        $tid = current_tenant_id();
        return (int) Database::insert('coaching_activity', [
            'tenant_id'    => $tid,
            'customer_id'  => $customerId,
            'day'          => $day,
            'kind'         => mb_substr($kind, 0, 60),
            'duration_min' => $durationMin !== null && $durationMin >= 0 ? $durationMin : null,
            'notes'        => $notes !== '' ? mb_substr($notes, 0, 300) : null,
        ]);
    }

    public static function deleteActivity(int $activityId, int $customerId): bool {
        $tid = current_tenant_id();
        return (bool) Database::delete('coaching_activity', 'id = ? AND customer_id = ? AND tenant_id = ?',
            [$activityId, $customerId, $tid]);
    }

    // ── Chat (Wave 4) ────────────────────────────────────────────────────

    /** Ensure a thread exists for the customer; return the thread id. */
    public static function ensureThread(int $customerId): int {
        if ($customerId <= 0) return 0;
        $tid = current_tenant_id();
        $row = Database::row(
            "SELECT id FROM coaching_thread WHERE tenant_id = ? AND customer_id = ?",
            [$tid, $customerId]
        );
        if ($row) return (int)$row['id'];
        return (int) Database::insert('coaching_thread', [
            'tenant_id'   => $tid,
            'customer_id' => $customerId,
        ]);
    }

    public static function getThread(int $customerId): ?array {
        $tid = current_tenant_id();
        return Database::row(
            "SELECT * FROM coaching_thread WHERE tenant_id = ? AND customer_id = ?",
            [$tid, $customerId]
        );
    }

    /** List all threads with client info + preview of the last message. */
    public static function listThreads(): array {
        $tid = current_tenant_id();
        return Database::rows(
            "SELECT t.id AS thread_id, t.customer_id, t.last_message_at,
                    t.unread_practitioner, t.unread_customer,
                    c.name AS customer_name, c.email AS customer_email,
                    (SELECT body FROM coaching_message m
                       WHERE m.thread_id = t.id AND m.sent_at IS NOT NULL
                       ORDER BY m.sent_at DESC LIMIT 1) AS last_body,
                    (SELECT sender FROM coaching_message m
                       WHERE m.thread_id = t.id AND m.sent_at IS NOT NULL
                       ORDER BY m.sent_at DESC LIMIT 1) AS last_sender
               FROM coaching_thread t
               JOIN customers c ON c.id = t.customer_id
              WHERE t.tenant_id = ?
              ORDER BY t.last_message_at IS NULL, t.last_message_at DESC",
            [$tid]
        );
    }

    /**
     * List messages in a thread. Returns only delivered messages (sent_at
     * set) unless $includePending is true (practitioner viewing scheduled).
     */
    public static function listMessages(int $threadId, bool $includePending = false, int $limit = 200): array {
        $tid = current_tenant_id();
        $sql = "SELECT * FROM coaching_message WHERE tenant_id = ? AND thread_id = ?";
        if (!$includePending) $sql .= " AND sent_at IS NOT NULL";
        $sql .= " ORDER BY COALESCE(sent_at, send_at, created_at) ASC LIMIT ?";
        return Database::rows($sql, [$tid, $threadId, max(1, min(1000, $limit))]);
    }

    /**
     * Send / schedule a message. $sendAt null = live send (delivered
     * immediately, sent_at stamped, thread bumped). Non-null $sendAt in
     * the future = scheduled; cron will deliver.
     */
    public static function sendMessage(int $threadId, string $sender, string $body, ?string $photoPath = null, ?string $sendAt = null): int {
        if ($threadId <= 0) return 0;
        if (!in_array($sender, ['practitioner','customer'], true)) return 0;
        $body = trim($body);
        if ($body === '' && !$photoPath) return 0;

        $tid = current_tenant_id();
        // The scheduler that later releases these (send_at <= NOW()) runs on the
        // database clock, so the "is it already due?" decision has to use the
        // same clock. PHP runs UTC here and MySQL runs SYSTEM — PHP's now sits
        // four hours ahead, so anything scheduled inside that window looked
        // already-past and went out immediately instead of being queued.
        $now = slate_db_now();
        $isLive = ($sendAt === null || $sendAt === '' || strtotime($sendAt) === false || strtotime($sendAt) <= strtotime($now));

        $messageId = (int) Database::insert('coaching_message', [
            'tenant_id'  => $tid,
            'thread_id'  => $threadId,
            'sender'     => $sender,
            'body'       => $body !== '' ? $body : null,
            'photo_path' => $photoPath ?: null,
            'send_at'    => $isLive ? null : $sendAt,
            'sent_at'    => $isLive ? $now : null,
        ]);

        if ($isLive) self::onMessageDelivered($threadId, $sender);
        return $messageId;
    }

    /**
     * Called when a message is delivered (either live-send or by the cron
     * flipping a scheduled message). Bumps the thread's last_message_at
     * and the recipient's unread counter.
     */
    public static function onMessageDelivered(int $threadId, string $sender): void {
        $recipientCol = $sender === 'practitioner' ? 'unread_customer' : 'unread_practitioner';
        Database::query(
            "UPDATE coaching_thread
                SET last_message_at = NOW(),
                    $recipientCol = $recipientCol + 1
              WHERE id = ?",
            [$threadId]
        );
    }

    /**
     * Cron worker: deliver scheduled messages whose send_at is now due.
     * Idempotent — runs on every frequent_cron tick.
     */
    public static function deliverScheduled(): int {
        $due = Database::rows(
            "SELECT id, thread_id, sender FROM coaching_message
              WHERE sent_at IS NULL AND send_at IS NOT NULL AND send_at <= NOW()
              LIMIT 200"
        );
        if (!$due) return 0;
        foreach ($due as $m) {
            Database::update('coaching_message',
                ['sent_at' => slate_db_now()],
                'id = ? AND sent_at IS NULL', [(int)$m['id']]
            );
            self::onMessageDelivered((int)$m['thread_id'], (string)$m['sender']);
        }
        return count($due);
    }

    /** Mark all delivered messages in a thread read by the given side. */
    public static function markThreadRead(int $threadId, string $reader): void {
        if (!in_array($reader, ['practitioner','customer'], true)) return;
        $col = $reader === 'practitioner' ? 'unread_practitioner' : 'unread_customer';
        Database::query("UPDATE coaching_thread SET $col = 0 WHERE id = ?", [$threadId]);
        // Stamp seen_at on messages from the OTHER side.
        $otherSender = $reader === 'practitioner' ? 'customer' : 'practitioner';
        Database::query(
            "UPDATE coaching_message
                SET seen_at = NOW()
              WHERE thread_id = ? AND sender = ? AND sent_at IS NOT NULL AND seen_at IS NULL",
            [$threadId, $otherSender]
        );
    }

    /** Delete a scheduled (not-yet-sent) message. */
    public static function cancelScheduledMessage(int $messageId): bool {
        return (bool) Database::query(
            "DELETE FROM coaching_message WHERE id = ? AND sent_at IS NULL AND send_at IS NOT NULL",
            [$messageId]
        );
    }

    /**
     * Save an uploaded photo file for a chat message. Same shape as
     * saveDiaryPhoto but writes to uploads/coaching-chat/.
     */
    public static function saveChatPhoto(array $file): ?string {
        if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
        if (($file['size'] ?? 0) > 10 * 1024 * 1024) return null;

        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : mime_content_type($file['tmp_name']);
        if ($finfo) finfo_close($finfo);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($allowed[$mime])) return null;
        $ext = $allowed[$mime];

        $sub  = 'uploads/coaching-chat/' . date('Y/m');
        $abs  = SLATE_ROOT . '/' . $sub;
        if (!is_dir($abs) && !@mkdir($abs, 0775, true)) return null;
        $name = date('d-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $rel  = $sub . '/' . $name;
        if (!@move_uploaded_file($file['tmp_name'], SLATE_ROOT . '/' . $rel)) return null;

        return $rel;
    }

    public static function totalUnreadForPractitioner(): int {
        $tid = current_tenant_id();
        return (int) Database::value(
            "SELECT COALESCE(SUM(unread_practitioner), 0) FROM coaching_thread WHERE tenant_id = ?",
            [$tid]
        );
    }

    public static function unreadForCustomer(int $customerId): int {
        $tid = current_tenant_id();
        return (int) Database::value(
            "SELECT COALESCE(unread_customer, 0) FROM coaching_thread WHERE tenant_id = ? AND customer_id = ?",
            [$tid, $customerId]
        );
    }

    /**
     * Feed of the most recent diary entries across all enrolled clients,
     * for the practitioner-side "today across all clients" inbox.
     */
    public static function recentEntriesAcrossClients(int $limit = 50): array {
        $tid = current_tenant_id();
        return Database::rows(
            "SELECT e.id, e.customer_id, e.day, e.meal_type, e.emotion, e.summary, e.created_at,
                    c.name AS customer_name, c.email AS customer_email
               FROM coaching_diary_entry e
               JOIN customers c ON c.id = e.customer_id
              WHERE e.tenant_id = ?
              ORDER BY e.created_at DESC
              LIMIT ?",
            [$tid, max(1, min(200, $limit))]
        );
    }

    // ── Wave 5 — Meal structure / Shopping list / Recipes ───────────────
    // All three tables use "customer_id NULL = library template" semantics.

    public static function listMealStructure(?int $customerId): array {
        $tid = current_tenant_id();
        if ($customerId === null) {
            $rows = Database::rows("SELECT * FROM coaching_meal_structure WHERE tenant_id = ? AND customer_id IS NULL ORDER BY sort_order, id", [$tid]);
        } else {
            $rows = Database::rows("SELECT * FROM coaching_meal_structure WHERE tenant_id = ? AND customer_id = ? ORDER BY sort_order, id", [$tid, $customerId]);
        }
        foreach ($rows as &$r) {
            $r['tags'] = !empty($r['tags_json']) ? (json_decode((string)$r['tags_json'], true) ?: []) : [];
        }
        return $rows;
    }

    public static function saveMealStructure(array $fields): int {
        $tid = current_tenant_id();
        $id  = (int)($fields['id'] ?? 0);
        $data = [
            'customer_id' => isset($fields['customer_id']) && (int)$fields['customer_id'] > 0 ? (int)$fields['customer_id'] : null,
            'title'       => mb_substr(trim((string)($fields['title'] ?? '')), 0, 200),
            'slot'        => in_array($fields['slot'] ?? '', ['breakfast','lunch','dinner','snack','note'], true) ? (string)$fields['slot'] : 'note',
            'notes_html'  => trim((string)($fields['notes_html'] ?? '')) ?: null,
            'tags_json'   => !empty($fields['tags']) && is_array($fields['tags']) ? json_encode(array_values(array_filter($fields['tags']))) : null,
            'sort_order'  => (int)($fields['sort_order'] ?? 0),
        ];
        if ($data['title'] === '') return 0;
        if ($id > 0) {
            Database::update('coaching_meal_structure', $data, 'id = ? AND tenant_id = ?', [$id, $tid]);
            return $id;
        }
        $data['tenant_id'] = $tid;
        return (int) Database::insert('coaching_meal_structure', $data);
    }

    public static function deleteMealStructure(int $id): bool {
        $tid = current_tenant_id();
        return (bool) Database::delete('coaching_meal_structure', 'id = ? AND tenant_id = ?', [$id, $tid]);
    }

    public static function copyMealStructureToClient(int $libraryId, int $customerId): int {
        $tid = current_tenant_id();
        $src = Database::row("SELECT * FROM coaching_meal_structure WHERE id = ? AND tenant_id = ? AND customer_id IS NULL", [$libraryId, $tid]);
        if (!$src || $customerId <= 0) return 0;
        return (int) Database::insert('coaching_meal_structure', [
            'tenant_id'   => $tid,
            'customer_id' => $customerId,
            'title'       => $src['title'],
            'slot'        => $src['slot'],
            'notes_html'  => $src['notes_html'],
            'tags_json'   => $src['tags_json'],
            'sort_order'  => (int)$src['sort_order'],
        ]);
    }

    public static function listShoppingLists(?int $customerId): array {
        $tid = current_tenant_id();
        if ($customerId === null) {
            $rows = Database::rows("SELECT * FROM coaching_shopping_list WHERE tenant_id = ? AND customer_id IS NULL ORDER BY name", [$tid]);
        } else {
            $rows = Database::rows("SELECT * FROM coaching_shopping_list WHERE tenant_id = ? AND customer_id = ? ORDER BY name", [$tid, $customerId]);
        }
        foreach ($rows as &$r) {
            $r['sections'] = !empty($r['sections_json']) ? (json_decode((string)$r['sections_json'], true) ?: []) : [];
            $r['tags']     = !empty($r['tags_json'])     ? (json_decode((string)$r['tags_json'], true) ?: [])     : [];
        }
        return $rows;
    }

    public static function saveShoppingList(array $fields): int {
        $tid = current_tenant_id();
        $id  = (int)($fields['id'] ?? 0);
        $sections = [];
        foreach ((array)($fields['sections'] ?? []) as $sec) {
            $heading = trim((string)($sec['heading'] ?? ''));
            if ($heading === '') continue;
            $items = [];
            foreach ((array)($sec['items'] ?? []) as $it) {
                $it = trim((string)$it);
                if ($it !== '') $items[] = mb_substr($it, 0, 200);
            }
            $sections[] = ['heading' => mb_substr($heading, 0, 100), 'items' => $items];
        }
        $data = [
            'customer_id'   => isset($fields['customer_id']) && (int)$fields['customer_id'] > 0 ? (int)$fields['customer_id'] : null,
            'name'          => mb_substr(trim((string)($fields['name'] ?? '')), 0, 200),
            'sections_json' => $sections ? json_encode($sections) : null,
            'tags_json'     => !empty($fields['tags']) && is_array($fields['tags']) ? json_encode(array_values(array_filter($fields['tags']))) : null,
        ];
        if ($data['name'] === '') return 0;
        if ($id > 0) {
            Database::update('coaching_shopping_list', $data, 'id = ? AND tenant_id = ?', [$id, $tid]);
            return $id;
        }
        $data['tenant_id'] = $tid;
        return (int) Database::insert('coaching_shopping_list', $data);
    }

    public static function deleteShoppingList(int $id): bool {
        $tid = current_tenant_id();
        return (bool) Database::delete('coaching_shopping_list', 'id = ? AND tenant_id = ?', [$id, $tid]);
    }

    public static function copyShoppingListToClient(int $libraryId, int $customerId): int {
        $tid = current_tenant_id();
        $src = Database::row("SELECT * FROM coaching_shopping_list WHERE id = ? AND tenant_id = ? AND customer_id IS NULL", [$libraryId, $tid]);
        if (!$src || $customerId <= 0) return 0;
        return (int) Database::insert('coaching_shopping_list', [
            'tenant_id'     => $tid,
            'customer_id'   => $customerId,
            'name'          => $src['name'],
            'sections_json' => $src['sections_json'],
            'tags_json'     => $src['tags_json'],
        ]);
    }

    public static function listRecipes(?int $customerId): array {
        $tid = current_tenant_id();
        if ($customerId === null) {
            $rows = Database::rows("SELECT * FROM coaching_recipe WHERE tenant_id = ? AND customer_id IS NULL AND author = 'practitioner' ORDER BY title", [$tid]);
        } else {
            $rows = Database::rows("SELECT * FROM coaching_recipe WHERE tenant_id = ? AND customer_id = ? ORDER BY updated_at DESC", [$tid, $customerId]);
        }
        foreach ($rows as &$r) {
            $r['ingredients'] = !empty($r['ingredients_json']) ? (json_decode((string)$r['ingredients_json'], true) ?: []) : [];
            $r['tags']        = !empty($r['tags_json'])        ? (json_decode((string)$r['tags_json'], true) ?: [])        : [];
        }
        return $rows;
    }

    public static function getRecipe(int $id, ?int $customerViewerId = null): ?array {
        $tid = current_tenant_id();
        $r = Database::row("SELECT * FROM coaching_recipe WHERE id = ? AND tenant_id = ?", [$id, $tid]);
        if (!$r) return null;
        if ($customerViewerId !== null && (int)($r['customer_id'] ?? 0) !== $customerViewerId) return null;
        $r['ingredients'] = !empty($r['ingredients_json']) ? (json_decode((string)$r['ingredients_json'], true) ?: []) : [];
        $r['tags']        = !empty($r['tags_json'])        ? (json_decode((string)$r['tags_json'], true) ?: [])        : [];
        return $r;
    }

    public static function recentCustomerRecipes(int $limit = 20): array {
        $tid = current_tenant_id();
        return Database::rows(
            "SELECT r.*, c.name AS customer_name, c.email AS customer_email
               FROM coaching_recipe r
               JOIN customers c ON c.id = r.customer_id
              WHERE r.tenant_id = ? AND r.author = 'customer'
              ORDER BY r.created_at DESC
              LIMIT ?",
            [$tid, max(1, min(200, $limit))]);
    }

    public static function saveRecipe(array $fields): int {
        $tid = current_tenant_id();
        $id  = (int)($fields['id'] ?? 0);
        $ingredients = [];
        foreach ((array)($fields['ingredients'] ?? []) as $line) {
            $line = trim((string)$line);
            if ($line !== '') $ingredients[] = mb_substr($line, 0, 200);
        }
        $author = in_array($fields['author'] ?? '', ['practitioner','customer'], true) ? (string)$fields['author'] : 'practitioner';
        $data = [
            'customer_id'      => isset($fields['customer_id']) && (int)$fields['customer_id'] > 0 ? (int)$fields['customer_id'] : null,
            'author'           => $author,
            'title'            => mb_substr(trim((string)($fields['title'] ?? '')), 0, 200),
            'photo_path'       => !empty($fields['photo_path']) ? (string)$fields['photo_path'] : null,
            'ingredients_json' => $ingredients ? json_encode($ingredients) : null,
            'instructions_html'=> trim((string)($fields['instructions_html'] ?? '')) ?: null,
            'video_url'        => !empty($fields['video_url']) ? mb_substr((string)$fields['video_url'], 0, 500) : null,
            'notes'            => trim((string)($fields['notes'] ?? '')) ?: null,
            'tags_json'        => !empty($fields['tags']) && is_array($fields['tags']) ? json_encode(array_values(array_filter($fields['tags']))) : null,
        ];
        if ($data['title'] === '') return 0;
        if ($id > 0) {
            Database::update('coaching_recipe', $data, 'id = ? AND tenant_id = ?', [$id, $tid]);
            return $id;
        }
        $data['tenant_id'] = $tid;
        return (int) Database::insert('coaching_recipe', $data);
    }

    public static function deleteRecipe(int $id): bool {
        $tid = current_tenant_id();
        return (bool) Database::delete('coaching_recipe', 'id = ? AND tenant_id = ?', [$id, $tid]);
    }

    public static function copyRecipeToClient(int $libraryId, int $customerId): int {
        $tid = current_tenant_id();
        $src = Database::row("SELECT * FROM coaching_recipe WHERE id = ? AND tenant_id = ? AND customer_id IS NULL", [$libraryId, $tid]);
        if (!$src || $customerId <= 0) return 0;
        return (int) Database::insert('coaching_recipe', [
            'tenant_id'        => $tid,
            'customer_id'      => $customerId,
            'author'           => 'practitioner',
            'title'            => $src['title'],
            'photo_path'       => $src['photo_path'],
            'ingredients_json' => $src['ingredients_json'],
            'instructions_html'=> $src['instructions_html'],
            'video_url'        => $src['video_url'],
            'notes'            => $src['notes'],
            'tags_json'        => $src['tags_json'],
        ]);
    }

    public static function saveRecipePhoto(array $file): ?string {
        if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
        if (($file['size'] ?? 0) > 10 * 1024 * 1024) return null;
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : mime_content_type($file['tmp_name']);
        if ($finfo) finfo_close($finfo);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($allowed[$mime])) return null;
        $ext = $allowed[$mime];
        $sub  = 'uploads/coaching-recipes/' . date('Y/m');
        $abs  = SLATE_ROOT . '/' . $sub;
        if (!is_dir($abs) && !@mkdir($abs, 0775, true)) return null;
        $name = date('d-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $rel  = $sub . '/' . $name;
        if (!@move_uploaded_file($file['tmp_name'], SLATE_ROOT . '/' . $rel)) return null;
        return $rel;
    }

    public static function isModuleEnabled(int $customerId, string $module): bool {
        $flag = match ($module) {
            'meal_structure' => 'has_meal_structure',
            'shopping'       => 'has_shopping_list',
            'recipes'        => 'has_recipes',
            default          => null,
        };
        if ($flag === null) return false;
        $p = self::getProfile($customerId);
        return $p && !empty($p[$flag]);
    }

    // ── Wave 6 — Challenges & exercises ─────────────────────────────────

    public static function listChallenges(int $customerId, bool $activeOnly = true): array {
        $tid = current_tenant_id();
        $sql = "SELECT * FROM coaching_challenge WHERE tenant_id = ? AND customer_id = ?";
        if ($activeOnly) $sql .= " AND completed_at IS NULL AND (ends_at IS NULL OR ends_at >= CURDATE())";
        $sql .= " ORDER BY completed_at IS NULL DESC, starts_at DESC";
        return Database::rows($sql, [$tid, $customerId]);
    }

    public static function saveChallenge(array $fields): int {
        $tid = current_tenant_id();
        $id  = (int)($fields['id'] ?? 0);
        $data = [
            'customer_id'      => (int)($fields['customer_id'] ?? 0),
            'kind'             => in_array($fields['kind'] ?? '', ['challenge','exercise'], true) ? (string)$fields['kind'] : 'challenge',
            'title'            => mb_substr(trim((string)($fields['title'] ?? '')), 0, 200),
            'description_html' => trim((string)($fields['description_html'] ?? '')) ?: null,
            'video_url'        => !empty($fields['video_url']) ? mb_substr((string)$fields['video_url'], 0, 500) : null,
            'starts_at'        => (string)($fields['starts_at'] ?? date('Y-m-d')),
            'ends_at'          => !empty($fields['ends_at']) ? (string)$fields['ends_at'] : null,
        ];
        if ($data['title'] === '' || $data['customer_id'] <= 0) return 0;
        if ($id > 0) {
            Database::update('coaching_challenge', $data, 'id = ? AND tenant_id = ?', [$id, $tid]);
            return $id;
        }
        $data['tenant_id'] = $tid;
        return (int) Database::insert('coaching_challenge', $data);
    }

    public static function deleteChallenge(int $id): bool {
        $tid = current_tenant_id();
        return (bool) Database::delete('coaching_challenge', 'id = ? AND tenant_id = ?', [$id, $tid]);
    }

    /** Client marks a challenge complete. */
    public static function completeChallenge(int $challengeId, int $customerId, string $note = ''): bool {
        $tid = current_tenant_id();
        return (bool) Database::update('coaching_challenge',
            ['completed_at' => slate_db_now(), 'client_note' => $note !== '' ? mb_substr($note, 0, 500) : null],
            'id = ? AND customer_id = ? AND tenant_id = ?',
            [$challengeId, $customerId, $tid]
        );
    }

    // ── Wave 6 — End-of-program summary ─────────────────────────────────

    /**
     * Compute a rich summary for a customer's full program period. Reads
     * their diary, hydration, activity, goals and challenges and packs
     * the highlights into a structured array.
     */
    public static function computeSummary(int $customerId, ?string $periodStart = null, ?string $periodEnd = null): array {
        $tid = current_tenant_id();

        // Default period: from the earliest diary entry to today.
        if ($periodStart === null) {
            $periodStart = (string) Database::value(
                "SELECT MIN(day) FROM coaching_diary_entry WHERE tenant_id = ? AND customer_id = ?",
                [$tid, $customerId]);
            if ($periodStart === '') $periodStart = date('Y-m-d', strtotime('-90 days'));
        }
        if ($periodEnd === null) $periodEnd = date('Y-m-d');

        $days = max(1, (int) ((strtotime($periodEnd) - strtotime($periodStart)) / 86400) + 1);

        $meals = (int) Database::value(
            "SELECT COUNT(*) FROM coaching_diary_entry WHERE tenant_id = ? AND customer_id = ? AND day BETWEEN ? AND ?",
            [$tid, $customerId, $periodStart, $periodEnd]);
        $daysLogged = (int) Database::value(
            "SELECT COUNT(DISTINCT day) FROM coaching_diary_entry WHERE tenant_id = ? AND customer_id = ? AND day BETWEEN ? AND ?",
            [$tid, $customerId, $periodStart, $periodEnd]);
        $consistency = $days > 0 ? round(($daysLogged / $days) * 100) : 0;

        $avgHydration = (float) Database::value(
            "SELECT AVG(liters) FROM coaching_hydration WHERE tenant_id = ? AND customer_id = ? AND day BETWEEN ? AND ?",
            [$tid, $customerId, $periodStart, $periodEnd]);

        $totalActivityMin = (int) Database::value(
            "SELECT COALESCE(SUM(duration_min), 0) FROM coaching_activity WHERE tenant_id = ? AND customer_id = ? AND day BETWEEN ? AND ?",
            [$tid, $customerId, $periodStart, $periodEnd]);

        // Goal outcomes.
        $goalStats = ['achieved' => 0, 'exceeded' => 0, 'partial' => 0, 'not_achieved' => 0];
        foreach (Database::rows(
            "SELECT status, COUNT(*) AS n FROM coaching_goal_checkin
              WHERE tenant_id = ? AND customer_id = ? AND day BETWEEN ? AND ?
              GROUP BY status",
            [$tid, $customerId, $periodStart, $periodEnd]) as $r) {
            $goalStats[$r['status']] = (int)$r['n'];
        }

        // Dominant emotion.
        $emoRows = Database::rows(
            "SELECT emotion, COUNT(*) AS n FROM coaching_diary_entry
              WHERE tenant_id = ? AND customer_id = ? AND day BETWEEN ? AND ? AND emotion IS NOT NULL
              GROUP BY emotion ORDER BY n DESC LIMIT 1",
            [$tid, $customerId, $periodStart, $periodEnd]);
        $dominantEmotion = $emoRows ? (string) $emoRows[0]['emotion'] : null;

        $challengesDone = (int) Database::value(
            "SELECT COUNT(*) FROM coaching_challenge WHERE tenant_id = ? AND customer_id = ? AND completed_at IS NOT NULL",
            [$tid, $customerId]);
        $extraActions = (int) Database::value(
            "SELECT COUNT(*) FROM coaching_extra_action WHERE tenant_id = ? AND customer_id = ? AND day BETWEEN ? AND ?",
            [$tid, $customerId, $periodStart, $periodEnd]);

        // Compose narrative "successes".
        $successes = [];
        if ($consistency >= 70) $successes[] = "You showed up — {$consistency}% consistency logging meals over {$days} days.";
        elseif ($consistency >= 40) $successes[] = "You logged meals on {$daysLogged} of {$days} days — a real body of data to learn from.";
        if ($goalStats['exceeded'] > 0) $successes[] = "You went past your daily goals {$goalStats['exceeded']} times — you're pushing yourself.";
        if ($goalStats['achieved'] > 5) $successes[] = "You fully achieved a daily goal {$goalStats['achieved']} times.";
        if ($challengesDone > 0) $successes[] = "You completed {$challengesDone} challenge" . ($challengesDone === 1 ? '' : 's') . " I set for you.";
        if ($extraActions > 0)  $successes[] = "You added {$extraActions} unplanned action" . ($extraActions === 1 ? '' : 's') . " on your own initiative.";
        if ($avgHydration >= 1.5) $successes[] = "Average hydration " . number_format($avgHydration, 1) . " L/day — solid.";
        if ($totalActivityMin >= 300) $successes[] = "Logged " . round($totalActivityMin / 60, 1) . "h of physical activity in total.";
        if (!$successes) $successes[] = "You started the program — the first step is always the hardest.";

        $recommendation = $consistency >= 70
            ? "Keep the habit alive — 3 diary entries a week is enough to keep learning."
            : "For next time: log at least three meals a week. Small data still tells us a lot.";

        return [
            'period'          => ['start' => $periodStart, 'end' => $periodEnd, 'days' => $days],
            'metrics'         => [
                'meals_logged'      => $meals,
                'days_logged'       => $daysLogged,
                'consistency_pct'   => $consistency,
                'avg_hydration_l'   => round($avgHydration, 2),
                'total_activity_min'=> $totalActivityMin,
                'dominant_emotion'  => $dominantEmotion,
                'goals'             => $goalStats,
                'challenges_done'   => $challengesDone,
                'extra_actions'     => $extraActions,
            ],
            'successes'       => $successes,
            'recommendation'  => $recommendation,
            'message'         => "Thank you for trusting me with these three months. Every entry, every check-in was seen. Take care of yourself — we can pick this back up anytime.",
        ];
    }

    public static function generateSummary(int $customerId, ?string $periodStart = null, ?string $periodEnd = null): int {
        $tid = current_tenant_id();
        $data = self::computeSummary($customerId, $periodStart, $periodEnd);
        $existing = Database::row(
            "SELECT id FROM coaching_summary WHERE tenant_id = ? AND customer_id = ?",
            [$tid, $customerId]);
        $row = [
            'period_start'  => $data['period']['start'],
            'period_end'    => $data['period']['end'],
            'summary_json'  => json_encode($data),
            'generated_at'  => slate_db_now(),
        ];
        if ($existing) {
            Database::update('coaching_summary', $row, 'id = ?', [(int)$existing['id']]);
            return (int)$existing['id'];
        }
        $row['tenant_id']   = $tid;
        $row['customer_id'] = $customerId;
        return (int) Database::insert('coaching_summary', $row);
    }

    public static function getSummary(int $customerId): ?array {
        $tid = current_tenant_id();
        $row = Database::row(
            "SELECT * FROM coaching_summary WHERE tenant_id = ? AND customer_id = ?",
            [$tid, $customerId]);
        if (!$row) return null;
        $row['data'] = !empty($row['summary_json']) ? (json_decode((string)$row['summary_json'], true) ?: []) : [];
        return $row;
    }

    /**
     * Called by cron. For every membership expiring within 3 days, ensure
     * a summary exists. Idempotent — runs on every tick, only generates
     * once per customer (unique key on tenant_id, customer_id).
     */
    public static function generateSummariesForExpiringMemberships(): int {
        if (!class_exists('MembershipAPI')) return 0;
        $tid = current_tenant_id();
        // Find memberships expiring in the next 3 days that don't have a summary yet.
        $rows = Database::rows(
            "SELECT s.customer_id FROM membership_subscriptions s
        LEFT JOIN coaching_summary cs ON cs.customer_id = s.customer_id AND cs.tenant_id = s.tenant_id
             WHERE s.tenant_id = ?
               AND s.status = 'active'
               AND s.expires_at IS NOT NULL
               AND s.expires_at BETWEEN NOW() AND NOW() + INTERVAL 3 DAY
               AND cs.id IS NULL",
            [$tid]);
        $count = 0;
        foreach ($rows as $r) {
            try {
                self::generateSummary((int)$r['customer_id']);
                $count++;
            } catch (\Throwable $e) {
                slate_log('Coaching summary generation failed for customer ' . $r['customer_id'] . ': ' . $e->getMessage(), 'warning');
            }
        }
        return $count;
    }

    // ── Roster helpers ───────────────────────────────────────────────────

    /**
     * Enrolled clients — filters via membership plugin when present.
     * Returns customers with their profile + membership status.
     */
    public static function listEnrolledClients(): array {
        $tid = current_tenant_id();
        $rows = Database::rows(
            "SELECT c.id, c.name, c.email, p.bmi, p.height_cm, p.weight_kg,
                    COALESCE(p.updated_at, p.created_at) AS profile_updated_at
               FROM customers c
          LEFT JOIN coaching_profile p ON p.customer_id = c.id AND p.tenant_id = c.tenant_id
              WHERE c.tenant_id = ?
              ORDER BY c.name",
            [$tid]
        );
        // Enrichment: keep only currently-enrolled clients when membership is present.
        if (class_exists('MembershipAPI')) {
            $rows = array_values(array_filter($rows, fn($r) => self::isEnrolled((int)$r['id'])));
        }
        return $rows;
    }
}
