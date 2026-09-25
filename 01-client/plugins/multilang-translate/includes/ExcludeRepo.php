<?php
/**
 * MLT — ExcludeRepo. CRUD + matching for exclusion rules: patterns that
 * stop a string from ever being harvested (emails, order numbers, API
 * keys, boilerplate noise, etc). Checked by MLT_Harvester before a string
 * is written to multilangtranslate_strings.
 */

class MLT_ExcludeRepo {

    const TYPES  = ['contains', 'exact', 'regex'];
    const AREAS  = ['any', 'admin', 'customer', 'public'];

    /** Per-tenant, per-request cache of enabled rules — harvesting checks
     *  every string against every rule, so this avoids a DB hit per string. */
    private static array $enabledCache = [];

    public static function all(int $tid): array {
        return Database::rows(
            "SELECT * FROM multilangtranslate_exclude_rules WHERE tenant_id = ? ORDER BY id DESC",
            [$tid]
        );
    }

    /** Enabled rules for one tenant, cached for the lifetime of the request. */
    public static function enabled(int $tid): array {
        if (isset(self::$enabledCache[$tid])) return self::$enabledCache[$tid];
        return self::$enabledCache[$tid] = Database::rows(
            "SELECT * FROM multilangtranslate_exclude_rules WHERE tenant_id = ? AND enabled = 1 ORDER BY id ASC",
            [$tid]
        );
    }

    public static function add(int $tid, string $pattern, string $type = 'contains', string $area = 'any', bool $caseSensitive = false, string $note = ''): int {
        $pattern = trim($pattern);
        if ($pattern === '') {
            throw new \InvalidArgumentException('Pattern cannot be empty.');
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Invalid pattern type.');
        }
        if (!in_array($area, self::AREAS, true)) {
            throw new \InvalidArgumentException('Invalid target area.');
        }
        if ($type === 'regex') {
            // Validate the pattern compiles before it ever reaches the harvester —
            // a bad regex saved silently would otherwise throw on every page view.
            if (@preg_match(self::wrapRegex($pattern, $caseSensitive), '') === false) {
                throw new \InvalidArgumentException('That regex pattern is not valid.');
            }
        }
        $id = Database::insert('multilangtranslate_exclude_rules', [
            'tenant_id'      => $tid,
            'pattern'        => $pattern,
            'pattern_type'   => $type,
            'target_area'    => $area,
            'case_sensitive' => $caseSensitive ? 1 : 0,
            'enabled'        => 1,
            'note'           => trim($note),
        ]);
        self::invalidateCache($tid);
        return $id;
    }

    public static function toggle(int $tid, int $id, bool $enabled): void {
        Database::update('multilangtranslate_exclude_rules', ['enabled' => $enabled ? 1 : 0], 'id = ? AND tenant_id = ?', [$id, $tid]);
        self::invalidateCache($tid);
    }

    public static function delete(int $tid, int $id): void {
        Database::delete('multilangtranslate_exclude_rules', 'id = ? AND tenant_id = ?', [$id, $tid]);
        self::invalidateCache($tid);
    }

    public static function invalidateCache(int $tid): void {
        unset(self::$enabledCache[$tid]);
    }

    /**
     * Does any enabled rule match this (already-normalized) string in this
     * area? Used by the Harvester to decide whether to skip a string
     * entirely — matched text is never written to multilangtranslate_strings.
     */
    public static function isExcluded(int $tid, string $normalizedText, string $area): bool {
        foreach (self::enabled($tid) as $rule) {
            if ($rule['target_area'] !== 'any' && $rule['target_area'] !== $area) continue;
            if (self::ruleMatches($rule, $normalizedText)) return true;
        }
        return false;
    }

    private static function ruleMatches(array $rule, string $text): bool {
        $pattern = $rule['pattern'];
        $caseSensitive = !empty($rule['case_sensitive']);

        switch ($rule['pattern_type']) {
            case 'exact':
                return $caseSensitive
                    ? $text === $pattern
                    : mb_strtolower($text, 'UTF-8') === mb_strtolower($pattern, 'UTF-8');

            case 'contains':
                return $caseSensitive
                    ? str_contains($text, $pattern)
                    : mb_stripos($text, $pattern, 0, 'UTF-8') !== false;

            case 'regex':
                // Guard against a rule that was valid at save time but somehow
                // isn't now (e.g. hand-edited in the DB) — never let one bad
                // rule take down harvesting for every other string on the page.
                $result = @preg_match(self::wrapRegex($pattern, $caseSensitive), $text);
                return $result === 1;

            default:
                return false;
        }
    }

    /** Wrap a raw user-supplied regex body in delimiters + flags. */
    private static function wrapRegex(string $pattern, bool $caseSensitive): string {
        $flag = $caseSensitive ? 'u' : 'iu';
        return '/' . str_replace('/', '\/', $pattern) . '/' . $flag;
    }
}
