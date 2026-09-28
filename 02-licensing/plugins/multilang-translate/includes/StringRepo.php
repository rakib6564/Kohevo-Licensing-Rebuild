<?php
/**
 * MLT — StringRepo. All reads/writes against multilangtranslate_strings + multilangtranslate_translations.
 */

class MLT_StringRepo {

    /** Harvest one normalized source string. Upserts + bumps occurrence count. */
    public static function harvest(int $tid, string $normalized, string $area): void {
        $hash = MLT_Tokenizer::hash($normalized);
        $existing = Database::row("SELECT id FROM multilangtranslate_strings WHERE tenant_id = ? AND text_hash = ?", [$tid, $hash]);
        if ($existing) {
            Database::query(
                // anti-drift-ignore: TENANT — $existing['id'] came from the
                // tenant-scoped SELECT immediately above, so the row is already
                // proven to belong to this tenant.
                "UPDATE multilangtranslate_strings SET occurrences = occurrences + 1, last_seen_at = NOW() WHERE id = ?",
                [$existing['id']]
            );
            return;
        }
        Database::insert('multilangtranslate_strings', [
            'tenant_id'   => $tid,
            'text_hash'   => $hash,
            'source_text' => $normalized,
            'source_area' => $area,
            'occurrences' => 1,
        ]);
    }

    /** Bulk-harvest a deduped array of [normalized => count] for one page hit. */
    public static function harvestBatch(int $tid, array $countsByText, string $area): int {
        $new = 0;
        foreach ($countsByText as $text => $count) {
            $hash = Database::value("SELECT id FROM multilangtranslate_strings WHERE tenant_id = ? AND text_hash = ?", [$tid, MLT_Tokenizer::hash($text)]);
            if ($hash) {
                // anti-drift-ignore: TENANT — $hash is an id from the
                // tenant-scoped SELECT on the line above.
                Database::query("UPDATE multilangtranslate_strings SET occurrences = occurrences + ?, last_seen_at = NOW() WHERE id = ?", [$count, $hash]);
            } else {
                Database::insert('multilangtranslate_strings', [
                    'tenant_id' => $tid, 'text_hash' => MLT_Tokenizer::hash($text),
                    'source_text' => $text, 'source_area' => $area, 'occurrences' => $count,
                ]);
                $new++;
            }
        }
        return $new;
    }

    /** Translation lookup dict for one locale: normalized-source => translated text. Published only. */
    public static function publishedDict(int $tid, string $locale): array {
        $rows = Database::rows(
            "SELECT s.source_text, t.translated_text FROM multilangtranslate_translations t
             JOIN multilangtranslate_strings s ON s.id = t.string_id
             WHERE t.tenant_id = ? AND t.locale = ? AND t.status = 'published'
               AND t.translated_text IS NOT NULL AND t.translated_text <> ''",
            [$tid, $locale]
        );
        $dict = [];
        foreach ($rows as $r) $dict[mb_strtolower($r['source_text'], 'UTF-8')] = $r['translated_text'];
        return $dict;
    }

    /** Grid page: source rows + each target locale's translation, with search filter. */
    public static function grid(int $tid, array $localeCodes, string $search = '', string $filter = 'all', int $limit = 200, int $offset = 0): array {
        $where = "s.tenant_id = ? AND s.ignored = 0";
        $params = [$tid];
        if ($search !== '') {
            $where .= " AND s.source_text LIKE ?";
            $params[] = '%' . $search . '%';
        }
        if ($filter === 'untranslated' && $localeCodes) {
            $placeholders = implode(',', array_fill(0, count($localeCodes), '?'));
            // anti-drift-ignore: TENANT — correlated against the outer query's
            // s.id, which is tenant-scoped; string_id values are unique across
            // tenants so a foreign translation cannot match one of ours.
            $where .= " AND s.id NOT IN (
                SELECT string_id FROM multilangtranslate_translations
                WHERE locale IN ($placeholders) AND translated_text IS NOT NULL AND translated_text <> ''
                GROUP BY string_id HAVING COUNT(DISTINCT locale) = " . count($localeCodes) . "
            )";
            $params = array_merge($params, $localeCodes);
        }
        $rows = Database::rows(
            "SELECT s.* FROM multilangtranslate_strings s WHERE $where ORDER BY s.source_text ASC LIMIT $limit OFFSET $offset",
            $params
        );
        if (!$rows) return [];

        $ids = array_column($rows, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        // anti-drift-ignore: TENANT — $ids come from the tenant-scoped strings
        // query above; this only fetches their translations.
        $trans = Database::rows("SELECT * FROM multilangtranslate_translations WHERE string_id IN ($ph)", $ids);
        $byString = [];
        foreach ($trans as $t) $byString[$t['string_id']][$t['locale']] = $t;

        foreach ($rows as &$r) {
            $r['translations'] = [];
            foreach ($localeCodes as $loc) {
                $r['translations'][$loc] = $byString[$r['id']][$loc] ?? ['translated_text' => '', 'status' => 'blank'];
            }
        }
        return $rows;
    }

    public static function totalCount(int $tid, string $search = ''): int {
        $where = "tenant_id = ? AND ignored = 0";
        $params = [$tid];
        if ($search !== '') { $where .= " AND source_text LIKE ?"; $params[] = '%' . $search . '%'; }
        return (int)Database::value("SELECT COUNT(*) FROM multilangtranslate_strings WHERE $where", $params);
    }

    /** Same "untranslated in every target locale" definition grid() uses, as a plain count — for the tab badge. */
    public static function untranslatedCount(int $tid, array $localeCodes, string $search = ''): int {
        if (!$localeCodes) return 0;
        $where = "s.tenant_id = ? AND s.ignored = 0";
        $params = [$tid];
        if ($search !== '') { $where .= " AND s.source_text LIKE ?"; $params[] = '%' . $search . '%'; }
        $placeholders = implode(',', array_fill(0, count($localeCodes), '?'));
        $where .= " AND s.id NOT IN (
            SELECT string_id FROM multilangtranslate_translations
            WHERE locale IN ($placeholders) AND translated_text IS NOT NULL AND translated_text <> ''
            GROUP BY string_id HAVING COUNT(DISTINCT locale) = " . count($localeCodes) . "
        )";
        $params = array_merge($params, $localeCodes);
        return (int)Database::value("SELECT COUNT(*) FROM multilangtranslate_strings s WHERE $where", $params);
    }

    /**
     * Report bar counters for one language column.
     *   total      — every harvested row (this is the same for every language;
     *                shown per-column so each card is self-contained).
     *   published  — has translated text AND is live on the site.
     *   draft      — has translated text but hasn't been published yet.
     *   not_translated — draft + blank combined (i.e. "not live yet"),
     *                the number editors actually need to work through.
     *   blank      — no text entered at all for this language.
     */
    public static function report(int $tid, string $locale): array {
        $total = (int)Database::value("SELECT COUNT(*) FROM multilangtranslate_strings WHERE tenant_id = ? AND ignored = 0", [$tid]);
        $published = (int)Database::value(
            "SELECT COUNT(*) FROM multilangtranslate_translations
             WHERE tenant_id = ? AND locale = ? AND status = 'published'
               AND translated_text IS NOT NULL AND translated_text <> ''",
            [$tid, $locale]
        );
        $draft = (int)Database::value(
            "SELECT COUNT(*) FROM multilangtranslate_translations
             WHERE tenant_id = ? AND locale = ? AND status = 'draft'
               AND translated_text IS NOT NULL AND translated_text <> ''",
            [$tid, $locale]
        );
        $blank = max(0, $total - $published - $draft);
        $notTranslated = $blank + $draft;
        return [
            'total'          => $total,
            'published'      => $published,
            'draft'          => $draft,
            'blank'          => $blank,
            'not_translated' => $notTranslated,
            // Kept for any older caller expecting the old shape.
            'translated'     => $published + $draft,
        ];
    }

    public static function saveCell(int $tid, int $stringId, string $locale, string $text, string $status = 'draft'): void {
        // The method is handed $tid and must use it: $stringId arrives from a
        // caller (grid POST, CSV import) and is not otherwise proven to belong
        // to this tenant. The UNIQUE KEY is (string_id, locale) with no
        // tenant_id, so one row serves that pair globally — an unscoped lookup
        // here finds and then overwrites another tenant's translation.
        $row = Database::row(
            "SELECT id FROM multilangtranslate_translations
              WHERE " . slate_tenant_clause() . " AND string_id = ? AND locale = ?",
            [$tid, $stringId, $locale]
        );
        $status = $text === '' ? 'blank' : $status;
        if ($row) {
            Database::update('multilangtranslate_translations',
                ['translated_text' => $text, 'status' => $status],
                'id = ? AND ' . slate_tenant_clause(), [$row['id'], $tid]);
        } else {
            Database::insert('multilangtranslate_translations', [
                'tenant_id' => $tid, 'string_id' => $stringId, 'locale' => $locale,
                'translated_text' => $text, 'status' => $status,
            ]);
        }
    }

    public static function publish(int $tid, ?string $locale = null): int {
        $where = "tenant_id = ? AND status = 'draft'";
        $params = [$tid];
        if ($locale) { $where .= " AND locale = ?"; $params[] = $locale; }
        return Database::query("UPDATE multilangtranslate_translations SET status = 'published' WHERE $where", $params)->rowCount();
    }

    public static function findReplace(int $tid, string $locale, string $find, string $replace, bool $caseSensitive = false): int {
        $flag = $caseSensitive ? '' : 'i';
        $rows = Database::rows(
            "SELECT t.id, t.translated_text FROM multilangtranslate_translations t WHERE t.tenant_id = ? AND t.locale = ? AND t.translated_text LIKE ?",
            [$tid, $locale, '%' . $find . '%']
        );
        $n = 0;
        foreach ($rows as $r) {
            $pattern = '/' . preg_quote($find, '/') . '/u' . $flag;
            $new = preg_replace($pattern, $replace, $r['translated_text']);
            if ($new !== $r['translated_text']) {
                Database::update('multilangtranslate_translations', ['translated_text' => $new, 'status' => 'draft'], 'id = ?', [$r['id']]);
                $n++;
            }
        }
        return $n;
    }

    public static function ignore(int $tid, int $stringId, bool $ignored = true): void {
        Database::update('multilangtranslate_strings', ['ignored' => $ignored ? 1 : 0], 'id = ? AND tenant_id = ?', [$stringId, $tid]);
    }

    // ── Bulk / multi-row operations (Phase 2) ──────────────────────────
    // All take an array of string IDs (from row checkboxes in the grid).
    // Every method scopes every write to `tenant_id = ?` even though the
    // IDs already came from a tenant-scoped SELECT, as defense in depth —
    // an ID list is just integers by the time it reaches here and callers
    // shouldn't have to re-prove tenancy on every call site.

    private static function placeholders(array $ids): string {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    /** Sanitize a caller-supplied ID list to a clean array of positive ints. */
    private static function cleanIds(array $ids): array {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn($n) => $n > 0)));
    }

    public static function bulkIgnore(int $tid, array $stringIds, bool $ignored = true): int {
        $ids = self::cleanIds($stringIds);
        if (!$ids) return 0;
        $ph = self::placeholders($ids);
        return Database::query(
            "UPDATE multilangtranslate_strings SET ignored = ? WHERE tenant_id = ? AND id IN ($ph)",
            array_merge([$ignored ? 1 : 0, $tid], $ids)
        )->rowCount();
    }

    public static function bulkDelete(int $tid, array $stringIds): int {
        $ids = self::cleanIds($stringIds);
        if (!$ids) return 0;
        $ph = self::placeholders($ids);
        Database::query("DELETE FROM multilangtranslate_translations WHERE tenant_id = ? AND string_id IN ($ph)", array_merge([$tid], $ids));
        return Database::query("DELETE FROM multilangtranslate_strings WHERE tenant_id = ? AND id IN ($ph)", array_merge([$tid], $ids))->rowCount();
    }

    /** Mark every draft translation for the given rows (one locale, or all locales if $locale is null) as published. */
    public static function bulkPublish(int $tid, array $stringIds, ?string $locale = null): int {
        $ids = self::cleanIds($stringIds);
        if (!$ids) return 0;
        $ph = self::placeholders($ids);
        $where = "tenant_id = ? AND status = 'draft' AND string_id IN ($ph)";
        $params = array_merge([$tid], $ids);
        if ($locale) { $where .= " AND locale = ?"; $params[] = $locale; }
        return Database::query("UPDATE multilangtranslate_translations SET status = 'published' WHERE $where", $params)->rowCount();
    }

    /**
     * Bulk auto-translate: runs MLT_AutoTranslate over every row in
     * $stringIds for one target locale, saving each result as a draft
     * (never auto-publishes — a human still reviews before it goes live).
     *
     * $overwrite = false (default) skips rows that already have non-blank
     * text for this locale, so re-running a bulk translate after manual
     * edits won't clobber a translator's work.
     *
     * Returns ['translated' => n, 'skipped' => n, 'failed' => n, 'errors' => [string_id => message]].
     */
    public static function bulkAutoTranslate(int $tid, array $stringIds, string $sourceLocale, string $targetLocale, bool $overwrite = false): array {
        $ids = self::cleanIds($stringIds);
        $result = ['translated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        if (!$ids) return $result;

        $ph = self::placeholders($ids);
        $rows = Database::rows("SELECT id, source_text FROM multilangtranslate_strings WHERE tenant_id = ? AND id IN ($ph) AND ignored = 0", array_merge([$tid], $ids));
        if (!$rows) return $result;

        $existing = [];
        if (!$overwrite) {
            $trans = Database::rows("SELECT string_id, translated_text FROM multilangtranslate_translations WHERE tenant_id = ? AND locale = ? AND string_id IN ($ph)", array_merge([$tid, $targetLocale], $ids));
            foreach ($trans as $t) {
                if (trim((string)$t['translated_text']) !== '') $existing[$t['string_id']] = true;
            }
        }

        foreach ($rows as $row) {
            if (!$overwrite && isset($existing[$row['id']])) {
                $result['skipped']++;
                continue;
            }
            $outcome = MLT_AutoTranslate::translate($row['source_text'], $sourceLocale, $targetLocale);
            if ($outcome['ok']) {
                self::saveCell($tid, (int)$row['id'], $targetLocale, $outcome['text'], 'draft');
                $result['translated']++;
            } else {
                $result['failed']++;
                $result['errors'][$row['id']] = $outcome['error'];
                // One provider outage/misconfig will fail every remaining row
                // identically — stop after a few consecutive failures instead
                // of burning through the whole batch on a request that's
                // never going to succeed.
                if ($result['failed'] >= 3 && $result['translated'] === 0) break;
            }
        }
        return $result;
    }

    /**
     * CSV export: id + source_text (English) + one column per requested
     * locale. Always includes id and source_text regardless of $locales,
     * so this doubles as the "send to an AI to translate" export — every
     * row of English text, one clean column per language, ready to hand
     * to any AI tool and get a filled-in file back for re-import.
     */
    public static function exportCsv(int $tid, array $locales): string {
        $rows = Database::rows("SELECT * FROM multilangtranslate_strings WHERE tenant_id = ? AND ignored = 0 ORDER BY source_text ASC", [$tid]);
        $ids = array_column($rows, 'id');
        $byString = [];
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            // anti-drift-ignore: TENANT — $ids are ids from the tenant-scoped
            // query above; this only widens the export to their translations.
            foreach (Database::rows("SELECT * FROM multilangtranslate_translations WHERE string_id IN ($ph)", $ids) as $t) {
                $byString[$t['string_id']][$t['locale']] = $t['translated_text'];
            }
        }
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, array_merge(['id', 'source_text'], $locales));
        foreach ($rows as $r) {
            $line = [$r['id'], $r['source_text']];
            foreach ($locales as $loc) $line[] = $byString[$r['id']][$loc] ?? '';
            fputcsv($fh, $line);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    /**
     * CSV import: expects header id and/or source_text, plus one column
     * per locale code. Returns [updated, skipped].
     *
     * Matches each row by `id` when present and valid (the reliable path —
     * this is what our own export produces, and what a round-trip through
     * an AI tool should preserve even if it lightly reflows the English
     * text). Falls back to hashing `source_text` when there's no id column
     * or the id doesn't resolve, so hand-built CSVs without an id column
     * still work exactly as before.
     */
    public static function importCsv(int $tid, string $csvContent, array $onlyLocales = []): array {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, $csvContent);
        rewind($fh);
        $header = fgetcsv($fh);
        if (!$header) { fclose($fh); return [0, 0]; }
        $header = array_map('trim', $header);
        $idIdx = array_search('id', $header, true);
        $srcIdx = array_search('source_text', $header, true);
        if ($idIdx === false && $srcIdx === false) { fclose($fh); return [0, 0]; }

        $updated = 0; $skipped = 0;
        while (($line = fgetcsv($fh)) !== false) {
            $stringId = null;

            if ($idIdx !== false) {
                $rawId = (int)($line[$idIdx] ?? 0);
                if ($rawId > 0) {
                    $exists = Database::value("SELECT id FROM multilangtranslate_strings WHERE tenant_id = ? AND id = ?", [$tid, $rawId]);
                    if ($exists) $stringId = (int)$exists;
                }
            }
            if ($stringId === null && $srcIdx !== false) {
                $source = trim((string)($line[$srcIdx] ?? ''));
                if ($source !== '') {
                    $hash = MLT_Tokenizer::hash(MLT_Tokenizer::normalize($source));
                    $found = Database::value("SELECT id FROM multilangtranslate_strings WHERE tenant_id = ? AND text_hash = ?", [$tid, $hash]);
                    if ($found) $stringId = (int)$found;
                }
            }
            if ($stringId === null) { $skipped++; continue; }

            foreach ($header as $i => $col) {
                if ($col === 'id' || $col === 'source_text') continue;
                if ($onlyLocales && !in_array($col, $onlyLocales, true)) continue;
                $val = trim((string)($line[$i] ?? ''));
                if ($val === '') continue;
                // Don't touch a cell whose incoming value is identical to what's
                // already stored — an AI-returned file often round-trips columns
                // it didn't actually need to change (e.g. one you'd already
                // published), and saveCell() always resets status to 'draft'.
                // Re-importing an unchanged, already-published cell shouldn't
                // silently knock it back out of "published".
                // $stringId comes straight from the uploaded CSV, so this has
                // to be scoped or a crafted file reads another tenant's text.
                $existing = Database::row(
                    "SELECT translated_text FROM multilangtranslate_translations
                      WHERE " . slate_tenant_clause() . " AND string_id = ? AND locale = ?",
                    [$tid, $stringId, $col]
                );
                if ($existing !== null && ($existing['translated_text'] ?? null) === $val) continue;
                self::saveCell($tid, $stringId, $col, $val, 'draft');
                $updated++;
            }
        }
        fclose($fh);
        return [$updated, $skipped];
    }
}
