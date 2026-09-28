<?php
/**
 * MLT — Replacer. Swaps source text for the active non-default locale's
 * published translation, string-for-string, without touching markup.
 */

class MLT_Replacer {

    private static ?array $dict = null;
    private static ?string $dictKey = null;

    public static function dict(int $tid, string $locale): array {
        $key = $tid . ':' . $locale;
        if (self::$dict !== null && self::$dictKey === $key) return self::$dict;
        self::$dict = MLT_StringRepo::publishedDict($tid, $locale);
        self::$dictKey = $key;
        return self::$dict;
    }

    public static function replace(int $tid, string $html, string $locale): string {
        $dict = self::dict($tid, $locale);
        if (!$dict) return $html;

        return MLT_Tokenizer::walk($html, function (string $normalized) use ($dict) {
            $key = mb_strtolower($normalized, 'UTF-8');
            return $dict[$key] ?? null;
        });
    }
}
