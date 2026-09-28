<?php
/**
 * MLT — Harvester. Walks a rendered HTML page and stores every unique
 * translatable text string it finds. Runs passively on every real page
 * view (see MultilangTranslate::obCallback) and is also driven by
 * MLT_Crawler for the manual "Scan" button.
 */

class MLT_Harvester {

    public static function harvest(int $tid, string $html, string $area): int {
        if (stripos($html, '<html') === false && stripos($html, '<body') === false && strlen($html) < 20) {
            return 0; // not a full HTML page (JSON/XML/asset response) — skip
        }
        $seen = [];
        MLT_Tokenizer::walk($html, function (string $normalized) use (&$seen) {
            $seen[$normalized] = ($seen[$normalized] ?? 0) + 1;
            return null; // read-only, no replacement
        });
        foreach (array_keys($seen) as $text) {
            if (self::looksLikeFormattedDate((string)$text)) unset($seen[$text]);
        }
        if (!$seen) return 0;
        return MLT_StringRepo::harvestBatch($tid, $seen, $area);
    }

    /**
     * A rendered date/time instant ("Fri 18 Sep", "Friday, 11 September
     * 2026, 15:00", "Sep 11, 1:01am") looks nothing like static UI copy
     * once it's baked into the page — it's a fresh, one-off string on
     * every single harvest, so capturing it just floods the strings
     * table with rows that can never be translated ahead of time. This
     * is a best-effort heuristic (weekday/month name, EN or FR, next to
     * a day number or a clock time), not exhaustive. The name must sit
     * directly beside the number so ordinary copy that merely contains
     * "may", "mon" or "sept" plus some count ("You may book up to 3
     * sessions") is still harvested.
     */
    private static function looksLikeFormattedDate(string $text): bool {
        static $namePattern = null;
        if ($namePattern === null) {
            $names = [
                'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday',
                'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat',
                'January', 'February', 'March', 'April', 'May', 'June', 'July',
                'August', 'September', 'October', 'November', 'December',
                'Jan', 'Feb', 'Mar', 'Apr', 'Jun', 'Jul', 'Aug', 'Sep', 'Sept', 'Oct', 'Nov', 'Dec',
                'dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi',
                'janvier', 'février', 'fevrier', 'mars', 'avril', 'mai', 'juin', 'juillet',
                'août', 'aout', 'septembre', 'octobre', 'novembre', 'décembre', 'decembre',
                'janv', 'févr', 'fevr', 'avr', 'juil', 'sept', 'déc', 'dec',
            ];
            $namePattern = implode('|', array_map(fn(string $w): string => preg_quote($w, '/'), $names));
        }
        if (!preg_match('/(?<!\pL)(' . $namePattern . ')(?!\pL)/iu', $text)) return false;
        // Name then day/time ("Sep 11", "vendredi 18", "Fri · 15:00"), or
        // day then name ("18 Sep", "1er mai", "11 September").
        return (bool)preg_match('/(?<!\pL)(' . $namePattern . ')\.?[\s,·]+\d{1,2}(?!\d)/iu', $text)
            || (bool)preg_match('/(?<!\d)\d{1,2}(?:er)?[\s,.·]+(' . $namePattern . ')(?!\pL)/iu', $text);
    }
}
