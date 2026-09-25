<?php
/**
 * MLT — LangRepo. Language table CRUD + a small ISO-639-1 name/flag lookup
 * used to auto-fill the "add language" modal from just a code.
 */

class MLT_LangRepo {

    /** code => [name, native_name, flag] — trimmed common set, extend freely. */
    const CATALOG = [
        'en' => ['English', 'English', '🇬🇧'],
        'fr' => ['French', 'Français', '🇫🇷'],
        'de' => ['German', 'Deutsch', '🇩🇪'],
        'es' => ['Spanish', 'Español', '🇪🇸'],
        'it' => ['Italian', 'Italiano', '🇮🇹'],
        'pt' => ['Portuguese', 'Português', '🇵🇹'],
        'nl' => ['Dutch', 'Nederlands', '🇳🇱'],
        'pl' => ['Polish', 'Polski', '🇵🇱'],
        'ru' => ['Russian', 'Русский', '🇷🇺'],
        'tr' => ['Turkish', 'Türkçe', '🇹🇷'],
        'ar' => ['Arabic', 'العربية', '🇸🇦'],
        'zh' => ['Chinese', '中文', '🇨🇳'],
        'ja' => ['Japanese', '日本語', '🇯🇵'],
        'ko' => ['Korean', '한국어', '🇰🇷'],
        'hi' => ['Hindi', 'हिन्दी', '🇮🇳'],
        'bn' => ['Bengali', 'বাংলা', '🇧🇩'],
        'sv' => ['Swedish', 'Svenska', '🇸🇪'],
        'da' => ['Danish', 'Dansk', '🇩🇰'],
        'fi' => ['Finnish', 'Suomi', '🇫🇮'],
        'cs' => ['Czech', 'Čeština', '🇨🇿'],
        'el' => ['Greek', 'Ελληνικά', '🇬🇷'],
        'he' => ['Hebrew', 'עברית', '🇮🇱'],
        'uk' => ['Ukrainian', 'Українська', '🇺🇦'],
        'ro' => ['Romanian', 'Română', '🇷🇴'],
        'vi' => ['Vietnamese', 'Tiếng Việt', '🇻🇳'],
        'th' => ['Thai', 'ไทย', '🇹🇭'],
        'id' => ['Indonesian', 'Bahasa Indonesia', '🇮🇩'],
    ];

    public static function ensureDefault(int $tid): void {
        $exists = Database::value("SELECT COUNT(*) FROM multilangtranslate_languages WHERE tenant_id = ?", [$tid]);
        if ((int)$exists > 0) return;
        Database::insert('multilangtranslate_languages', [
            'tenant_id' => $tid, 'code' => 'en', 'name' => 'English',
            'native_name' => 'English', 'flag' => '🇬🇧',
            'is_default' => 1, 'enabled' => 1, 'sort_order' => 0,
        ]);
    }

    public static function all(int $tid): array {
        return Database::rows("SELECT * FROM multilangtranslate_languages WHERE tenant_id = ? ORDER BY is_default DESC, sort_order ASC, id ASC", [$tid]);
    }

    public static function enabled(int $tid): array {
        return Database::rows("SELECT * FROM multilangtranslate_languages WHERE tenant_id = ? AND enabled = 1 ORDER BY is_default DESC, sort_order ASC, id ASC", [$tid]);
    }

    /**
     * Every added non-default language — these are the grid's translation
     * columns, whether enabled or not. "Enabled" only controls whether a
     * language appears on the live front-of-site switcher (see enabled()
     * above); disabling a language must never hide its column or its saved
     * translations from the admin grid.
     */
    public static function targets(int $tid): array {
        return Database::rows("SELECT * FROM multilangtranslate_languages WHERE tenant_id = ? AND is_default = 0 ORDER BY sort_order ASC, id ASC", [$tid]);
    }

    public static function add(int $tid, string $code, string $name = '', string $native = '', string $flag = ''): int {
        $code = strtolower(trim($code));
        if ($code === '' || !preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $code)) {
            throw new \InvalidArgumentException('Invalid language code.');
        }
        $cat = self::CATALOG[$code] ?? [ucfirst($code), ucfirst($code), '🏳️'];
        $maxOrder = (int)Database::value("SELECT COALESCE(MAX(sort_order),0) FROM multilangtranslate_languages WHERE tenant_id = ?", [$tid]);
        return Database::insert('multilangtranslate_languages', [
            'tenant_id'   => $tid,
            'code'        => $code,
            'name'        => $name !== '' ? $name : $cat[0],
            'native_name' => $native !== '' ? $native : $cat[1],
            'flag'        => $flag !== '' ? $flag : $cat[2],
            'is_default'  => 0,
            'enabled'     => 1,
            'sort_order'  => $maxOrder + 1,
        ]);
    }

    public static function toggle(int $tid, int $id, bool $enabled): void {
        Database::update('multilangtranslate_languages', ['enabled' => $enabled ? 1 : 0], 'id = ? AND tenant_id = ? AND is_default = 0', [$id, $tid]);
    }

    public static function delete(int $tid, int $id): void {
        $lang = Database::row("SELECT code FROM multilangtranslate_languages WHERE id = ? AND tenant_id = ? AND is_default = 0", [$id, $tid]);
        if (!$lang) return;
        Database::delete('multilangtranslate_translations', 'tenant_id = ? AND locale = ?', [$tid, $lang['code']]);
        Database::delete('multilangtranslate_languages', 'id = ? AND tenant_id = ?', [$id, $tid]);
    }

    public static function byCode(int $tid, string $code): ?array {
        return Database::row("SELECT * FROM multilangtranslate_languages WHERE tenant_id = ? AND code = ?", [$tid, $code]);
    }

    public static function reorder(int $tid, array $orderedIds): void {
        foreach ($orderedIds as $i => $id) {
            Database::update('multilangtranslate_languages', ['sort_order' => $i], 'id = ? AND tenant_id = ? AND is_default = 0', [(int)$id, $tid]);
        }
    }
}
