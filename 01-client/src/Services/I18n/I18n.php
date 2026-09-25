<?php
/**
 * Slate — I18n.
 *
 * Three-layer merge: core lang file → plugin lang packs → DB overrides.
 * Cached per-request per-locale. Supports plugin-registered languages via the
 * `i18n_supported_languages` filter.
 *
 * The bare `__()` and `__js()` shims live in helpers.php and delegate to this
 * class once it's loaded, so plugin code can use `__()` even before I18n is
 * wired up.
 *
 * Phase 1 A3: migrated from includes/I18n.php into Slate\Services\I18n. The old
 * global name `I18n` continues to resolve via a class_alias in
 * src/compat/aliases.php. Behavior is identical — the only code changes are
 * qualifying the cross-class static calls (`Hook::`/`Database::`) to the global
 * aliases (`\Hook::`/`\Database::`) so they resolve outside this namespace.
 */

declare(strict_types=1);

namespace Slate\Services\I18n;

class I18n
{
    /** @var array<string, string>|null Cached strings for the current locale. */
    private static ?array $cache = null;
    private static ?string $cachedLocale = null;

    public const DEFAULT_LANGUAGES = [
        'en' => 'English',
    ];

    public static function supportedLanguages(): array
    {
        $core = self::DEFAULT_LANGUAGES;
        // Plugins can add languages via the filter
        if (class_exists('Hook')) {
            return \Hook::applyFilters('i18n_supported_languages', $core);
        }
        return $core;
    }

    public static function currentLocale(): string
    {
        $supported = self::supportedLanguages();

        // Generic, core-level escape hatch for a same-origin internal
        // request that needs a specific locale regardless of the
        // requester's own session state -- e.g. a content crawler
        // rendering the source-language version of a page to harvest
        // strings, without touching $_SESSION (so the scan never inherits
        // -- or leaves behind -- whatever language the initiating admin's
        // session was on). Deliberately not named after any one caller:
        // core has no business knowing which plugin sends it. Currently
        // used by the multilang-translate crawler (MLT_Crawler::fetch()).
        $forced = (string)($_SERVER['HTTP_X_SLATE_FORCE_LOCALE'] ?? '');
        if ($forced !== '' && isset($supported[$forced])) {
            return $forced;
        }

        if (!empty($_GET['lang']) && isset($supported[$_GET['lang']])) {
            $_SESSION['slate_lang'] = $_GET['lang'];
            return $_GET['lang'];
        }
        if (!empty($_SESSION['slate_lang']) && isset($supported[$_SESSION['slate_lang']])) {
            return $_SESSION['slate_lang'];
        }

        $setting = class_exists('Database') ? (\Database::setting('default_language') ?: 'en') : 'en';
        $locale  = strtolower(trim((string)$setting));
        if (!preg_match('/^[a-z]{2}(?:[-_][a-z]{2,4})?$/i', $locale)) $locale = 'en';
        return $locale;
    }

    public static function resetCache(): void
    {
        self::$cache = null;
        self::$cachedLocale = null;
    }

    /**
     * The real implementation of __(). helpers.php's __() shim delegates here
     * when this class is loaded.
     */
    public static function translate(string $key, string $fallback = ''): string
    {
        $locale = self::currentLocale();

        if (self::$cache === null || self::$cachedLocale !== $locale) {
            self::$cache = self::loadStrings($locale);
            self::$cachedLocale = $locale;
        }

        if (isset(self::$cache[$key])) return self::$cache[$key];
        if ($fallback !== '') return $fallback;
        return str_replace('_', ' ', ucfirst($key));
    }

    /**
     * Returns the entire (locale, key) → value map as a JSON-encoded string
     * suitable for embedding in a <script> tag for client-side use. Same
     * three-layer merge as translate().
     */
    public static function asJsObject(): string
    {
        $locale = self::currentLocale();
        if (self::$cache === null || self::$cachedLocale !== $locale) {
            self::$cache = self::loadStrings($locale);
            self::$cachedLocale = $locale;
        }
        return json_encode(self::$cache ?? [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Three-layer merge:
     *   1. Core lang file: lang/<locale>.php (falls back to en)
     *   2. Plugin lang packs registered via `i18n_lang_paths` filter
     *   3. DB overrides from `lang_overrides`
     */
    private static function loadStrings(string $locale): array
    {
        $lang = [];

        // 1. Core lang file
        $core = SLATE_ROOT . '/lang/' . $locale . '.php';
        if (!file_exists($core)) $core = SLATE_ROOT . '/lang/en.php';
        if (file_exists($core)) {
            try {
                $included = include $core;
                if (is_array($included)) $lang = $included;
            } catch (\Throwable $e) {}
        }

        // 2. Plugin lang packs
        if (class_exists('Hook')) {
            $paths = \Hook::applyFilters('i18n_lang_paths', []);
            foreach (($paths[$locale] ?? []) as $dir) {
                $file = rtrim($dir, '/') . '/' . $locale . '.php';
                if (!file_exists($file)) continue;
                try {
                    $extra = include $file;
                    if (is_array($extra)) $lang = array_merge($lang, $extra);
                } catch (\Throwable $e) {}
            }
        }

        // 3. DB overrides
        if (class_exists('Database') && function_exists('current_tenant_id')) {
            try {
                $rows = \Database::rows(
                    "SELECT lang_key, lang_value FROM lang_overrides WHERE tenant_id = ? AND locale = ?",
                    [current_tenant_id(), $locale]
                );
                foreach ($rows as $r) {
                    $lang[$r['lang_key']] = $r['lang_value'];
                }
            } catch (\Throwable $e) {}
        }

        return $lang;
    }

    /**
     * Locale-aware date formatter.
     * Tokens: l (full weekday), D (short weekday), F (full month),
     *         M (short month), j (day no-zero), d (day zero), Y (year).
     * Every other character is passed through date(), so time tokens
     * (H, i, g, a, ...) work exactly as they do there; backslash escapes.
     */
    public static function localDate(string $format, ?int $ts = null): string
    {
        $ts = $ts ?? time();
        $wdEn = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $moEn = ['','January','February','March','April','May','June','July',
                 'August','September','October','November','December'];
        $w = (int)date('w', $ts);
        $m = (int)date('n', $ts);
        $full_wd  = self::translate(strtolower($wdEn[$w]), $wdEn[$w]);
        $short_wd = mb_substr($full_wd, 0, 3);
        $full_mo  = self::translate('month_' . $m, $moEn[$m]);
        // A language without month_short_N keys falls back to the first three letters.
        $short_mo = self::translate('month_short_' . $m, mb_substr($full_mo, 0, 3));

        $out = '';
        $len = strlen($format);
        for ($i = 0; $i < $len; $i++) {
            switch ($format[$i]) {
                case 'l': $out .= $full_wd;            break;
                case 'D': $out .= $short_wd;           break;
                case 'F': $out .= $full_mo;            break;
                case 'M': $out .= $short_mo;           break;
                case 'j': $out .= (int)date('j', $ts); break;
                case 'd': $out .= date('d', $ts);      break;
                case 'Y': $out .= date('Y', $ts);      break;
                case '\\': if ($i + 1 < $len) { $out .= $format[++$i]; } break;
                default:  $out .= date($format[$i], $ts);
            }
        }
        return $out;
    }
}
