<?php
/**
 * Kohevo Studio — web fonts from Google Fonts, set on Site & SEO and loaded by every Studio page (the editor canvas
 * too, whose policy allows the Google Fonts hosts), so the fonts the theme's font tokens name are the ones visitors get.
 *
 * Setting `studio_web_fonts`: one font per line, written as the Site & SEO screen takes it:
 *   Karla: 400, 500, 700, 800            a family and its weights (normal style)
 *   Lora: 400, 400 italic, 700           italic weights too
 *   Fraunces:opsz,wght@9..144,500;9..144,700   Google's own axis notation, passed through as it is
 * Only letters, digits and spaces make a family name, and only the characters of Google's notation make the rest, so a
 * line can never reach outside the font address. Unknown lines are left out (and reported on save).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Seo;

final class WebFonts
{
    public const SETTING = 'studio_web_fonts';
    public const MAX_FAMILIES = 8;

    /**
     * Read the lines into Google `family=` values.
     *
     * @return array{families: list<string>, bad: list<string>}
     */
    public static function parse(string $text): array
    {
        $families = [];
        $bad = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $spec = self::spec($line);
            if ($spec === null || count($families) >= self::MAX_FAMILIES) {
                $bad[] = mb_substr($line, 0, 80);
                continue;
            }
            $families[$spec] = $spec;
        }
        return ['families' => array_values($families), 'bad' => $bad];
    }

    /** One line as a Google `family=` value (spaces as +), or null. */
    public static function spec(string $line): ?string
    {
        // Google's own notation: Family:axis,axis@values;values
        if (preg_match('/^([A-Za-z0-9 ]{1,60}):([a-z]{3,4}(?:,[a-z]{3,4})*)@([0-9.;,]{1,200})$/', $line, $m) === 1) {
            return str_replace(' ', '+', trim($m[1])) . ':' . $m[2] . '@' . $m[3];
        }
        // Friendly: "Family" or "Family: 400, 700, 400 italic"
        if (preg_match('/^([A-Za-z0-9 ]{1,60})(?::\s*(.*))?$/', $line, $m) !== 1) {
            return null;
        }
        $family = str_replace(' ', '+', trim(preg_replace('/\s+/', ' ', $m[1]) ?? ''));
        if ($family === '') {
            return null;
        }
        $list = trim((string) ($m[2] ?? ''));
        if ($list === '') {
            return $family;
        }
        $normal = [];
        $italic = [];
        foreach (explode(',', $list) as $w) {
            if (preg_match('/^\s*([1-9]00)\s*(italic)?\s*$/i', $w, $mm) !== 1) {
                return null;
            }
            if (!empty($mm[2])) {
                $italic[(int) $mm[1]] = true;
            } else {
                $normal[(int) $mm[1]] = true;
            }
        }
        ksort($normal);
        ksort($italic);
        if ($italic === []) {
            return $family . ':wght@' . implode(';', array_keys($normal));
        }
        $tuples = [];
        foreach (array_keys($normal) as $w) {
            $tuples[] = '0,' . $w;
        }
        foreach (array_keys($italic) as $w) {
            $tuples[] = '1,' . $w;
        }
        return $family . ':ital,wght@' . implode(';', $tuples);
    }

    /** The stylesheet address for families from parse(), or '' with none. */
    public static function url(array $families): string
    {
        $families = array_values(array_filter($families, static fn($f): bool => is_string($f) && preg_match('/^[A-Za-z0-9+]+(?::[a-z,]+@[0-9.;,]+)?$/', $f) === 1));
        if ($families === []) {
            return '';
        }
        return 'https://fonts.googleapis.com/css2?' . implode('&', array_map(static fn(string $f): string => 'family=' . $f, $families)) . '&display=swap';
    }

    /** The <head> tags for a tenant's fonts, or ''. */
    public static function tags(int $tenantId): string
    {
        $url = self::url(self::families($tenantId));
        if ($url === '') {
            return '';
        }
        return '<link rel="preconnect" href="https://fonts.googleapis.com">'
            . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            . '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">';
    }

    /** @return list<string> */
    public static function families(int $tenantId): array
    {
        try {
            $raw = \Database::setting(self::SETTING, $tenantId);
        } catch (\Throwable $e) {
            return [];
        }
        return is_string($raw) && trim($raw) !== '' ? self::parse($raw)['families'] : [];
    }
}
