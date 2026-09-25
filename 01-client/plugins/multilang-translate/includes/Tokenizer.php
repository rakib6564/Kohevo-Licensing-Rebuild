<?php
/**
 * MLT — Tokenizer.
 *
 * Splits an HTML string into alternating text/tag tokens without a full
 * DOM re-parse (keeps the original byte-for-byte markup intact, which a
 * DOMDocument round-trip cannot guarantee). Used by both Harvester
 * (read-only extraction) and Replacer (in-place substitution) so the two
 * always agree on what counts as "translatable text".
 */

class MLT_Tokenizer {

    /** Tags whose text content is never translatable. */
    // 'textarea' holds editable form data (e.g. an admin's raw HTML email-
    // body draft), not static UI copy — harvesting it pollutes the source
    // strings table with whatever content happens to be in the box.
    const SKIP_TAGS = ['script', 'style', 'noscript', 'template', 'code', 'pre', 'textarea'];

    /** Attributes that hold user-visible text worth translating. */
    const TEXT_ATTRS = ['placeholder', 'title', 'alt', 'aria-label', 'data-mlt'];

    /** value="" is only translatable on visible-label inputs. */
    const VALUE_INPUT_TYPES = ['submit', 'button', 'reset'];

    /**
     * Walk $html, calling $onText($normalized, $raw) for every
     * translatable text segment (node text + eligible attribute values).
     * Return value of $onText, if non-null, replaces that segment
     * (used by the Replacer). Returns the (possibly modified) html.
     */
    public static function walk(string $html, callable $onText): string {
        // Protect whole SKIP_TAGS blocks (script/style/...) BEFORE the
        // generic '<...>' tag split below, which has no real understanding
        // of HTML — it treats any '<' ... next '>' as a tag. A '<' used as a
        // JS comparison operator inside a <script> (e.g. `i < first`) reads
        // as the start of one; the regex then greedily consumes everything
        // up to the NEXT unrelated '>' anywhere later in the page as a
        // single bogus "tag" — very often swallowing the real closing
        // </script> along with it. Once that happens, the skip-tracking
        // below never sees a matching close tag, skipDepth never drops back
        // to 0, and every single text node for the REST of the page is then
        // silently treated as "still inside a skip zone" — never reaching
        // $onText at all, so neither harvesting nor translation-replacement
        // ever touches any of it. Symptom in production: a page translates
        // correctly right up to some inline <script>, then everything after
        // it stays in English no matter how many strings are published.
        // Protecting complete blocks up front removes their content from
        // the generic split entirely, so stray '<'/'>' inside them can't
        // corrupt anything past their own closing tag.
        $protected = [];
        $skipPattern = implode('|', array_map(fn(string $t): string => preg_quote($t, '/'), self::SKIP_TAGS));
        $protectedHtml = preg_replace_callback(
            '/<(' . $skipPattern . ')\b[^>]*>.*?<\/\1\s*>/is',
            function (array $m) use (&$protected): string {
                // Control-byte placeholder deliberately has NO letters, so
                // translateTextNode()'s own \p{L} check skips it unchanged —
                // it never reaches $onText, so it can't be mistaken for a
                // translatable string by the harvester or the replacer.
                $key = "\x01" . count($protected) . "\x02";
                $protected[$key] = $m[0];
                return $key;
            },
            $html
        );
        if ($protectedHtml !== null) $html = $protectedHtml;

        $tokens = preg_split('/(<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($tokens === false) return $html;

        $skipDepth = 0;
        $skipTag   = null;
        $out = '';

        foreach ($tokens as $tok) {
            if ($tok === '') continue;

            if ($tok[0] === '<') {
                // Tag token — check skip-zone enter/exit, then translate attrs.
                if (preg_match('/^<\/\s*([a-zA-Z0-9]+)/', $tok, $m)) {
                    $tag = strtolower($m[1]);
                    if ($skipDepth > 0 && $tag === $skipTag) { $skipDepth--; if ($skipDepth === 0) $skipTag = null; }
                    $out .= $tok;
                    continue;
                }
                if (preg_match('/^<\s*([a-zA-Z0-9]+)/', $tok, $m)) {
                    $tag = strtolower($m[1]);
                    if ($skipDepth === 0 && in_array($tag, self::SKIP_TAGS, true) && substr($tok, -2) !== '/>') {
                        $skipDepth = 1; $skipTag = $tag;
                    }
                    $tok = self::translateAttrs($tok, $tag, $onText);
                }
                $out .= $tok;
                continue;
            }

            // Text token.
            if ($skipDepth > 0) { $out .= $tok; continue; }

            $out .= self::translateTextNode($tok, $onText);
        }

        if ($protected) $out = strtr($out, $protected);

        return $out;
    }

    private static function translateTextNode(string $raw, callable $onText): string {
        if (trim($raw) === '') return $raw;
        // Preserve exact leading/trailing whitespace; only touch the core.
        if (!preg_match('/^(\s*)(.*?)(\s*)$/s', $raw, $m)) return $raw;
        [, $lead, $core, $trail] = $m;
        if ($core === '' || !preg_match('/\p{L}/u', $core)) return $raw;

        $normalized = self::normalize(html_entity_decode($core, ENT_QUOTES, 'UTF-8'));
        if ($normalized === '' || mb_strlen($normalized) > 2000) return $raw;

        $replacement = $onText($normalized, $core, 'text');
        // $replacement is a stored translation (untrusted: writable by anyone
        // holding mlt.manage) being spliced into a TEXT NODE position in live
        // page HTML — it must be HTML-escaped exactly like any other value
        // rendered into that context (the project's own e() helper, used
        // everywhere else text reaches markup, e.g. the translation grid's
        // own <textarea> at admin/index.php:166). $core is NOT re-escaped
        // here: it is already-live markup carried over unchanged from the
        // page that was passed in, not new output being constructed.
        return $lead . ($replacement !== null ? e($replacement) : $core) . $trail;
    }

    private static function translateAttrs(string $tagHtml, string $tag, callable $onText): string {
        $isValueEligible = ($tag === 'input');
        $attrList = self::TEXT_ATTRS;
        if ($isValueEligible && preg_match('/\btype\s*=\s*["\']?(' . implode('|', self::VALUE_INPUT_TYPES) . ')["\']?/i', $tagHtml)) {
            $attrList[] = 'value';
        }

        foreach ($attrList as $attr) {
            $tagHtml = preg_replace_callback(
                '/\b(' . preg_quote($attr, '/') . ')\s*=\s*"([^"]*)"/i',
                function ($m) use ($onText) {
                    $val = trim($m[2]);
                    if ($val === '' || !preg_match('/\p{L}/u', $val)) return $m[0];
                    $normalized = self::normalize(html_entity_decode($val, ENT_QUOTES, 'UTF-8'));
                    if ($normalized === '') return $m[0];
                    $replacement = $onText($normalized, $val, 'attr');
                    $final = $replacement !== null ? $replacement : $m[2];
                    return $m[1] . '="' . str_replace('"', '&quot;', $final) . '"';
                },
                $tagHtml
            );
        }
        return $tagHtml;
    }

    /** Collapse internal whitespace + trim, used as the dictionary key basis. */
    public static function normalize(string $s): string {
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    public static function hash(string $normalized): string {
        return sha1(mb_strtolower($normalized, 'UTF-8'));
    }
}
