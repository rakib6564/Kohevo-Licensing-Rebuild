<?php
/**
 * Multilang Translate — MCP AI Gateway integration.
 *
 * Every write here calls the exact same repo methods the translation grid's
 * own save/publish buttons use (MLT_LangRepo::add, MLT_StringRepo::saveCell/
 * publish) — no separate, less-reviewed path for the AI. No delete or
 * enable/disable: adding a language and translating/publishing strings
 * covers what an AI assistant should do; removing a language customers may
 * already be using stays a manual admin action.
 */

declare(strict_types=1);

class MultilangTranslateMcpHandler {

    public static function register(): void {
        Hook::addFilter('slate_mcp_scopes',    [self::class, 'filterScopes']);
        Hook::addFilter('slate_mcp_tools',     [self::class, 'filterTools'], 10, 2);
        Hook::addFilter('slate_mcp_call_tool', [self::class, 'callTool'], 10, 4);
    }

    public static function filterScopes(array $scopes): array {
        $scopes['mlt.view']   = 'View languages and translation strings';
        $scopes['mlt.manage'] = 'Add languages, translate strings, and publish translations';
        return $scopes;
    }

    private static function has(array $context, string $scope): bool {
        return in_array($scope, (array)($context['scopes'] ?? []), true);
    }

    public static function filterTools(array $tools, array $context): array {
        if (self::has($context, 'mlt.view')) {
            $tools[] = [
                'name' => 'slate_i18n_list_languages',
                'description' => 'List every configured language (code, name, enabled/default status).',
                'inputSchema' => ['type' => 'object', 'properties' => (object)[]],
            ];
            $tools[] = [
                'name' => 'slate_i18n_list_strings',
                'description' => 'List translatable UI strings with their current translation status per locale. Use this to find what still needs translating before calling slate_i18n_translate_string.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'locales' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Locale codes to include, e.g. ["fr"]. Defaults to all enabled languages.'],
                    'search'  => ['type' => 'string', 'description' => 'Filter by source text substring.'],
                    'filter'  => ['type' => 'string', 'enum' => ['all', 'untranslated'], 'description' => 'Default all.'],
                    'limit'   => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
                ], 'additionalProperties' => false],
            ];
        }
        if (self::has($context, 'mlt.manage')) {
            $tools[] = [
                'name' => 'slate_i18n_add_language',
                'description' => 'Add a new language for translation. Name/native name/flag auto-fill from a built-in catalog for common language codes if omitted.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'code'        => ['type' => 'string', 'description' => 'ISO code, e.g. "es", "de", "pt-br".'],
                    'name'        => ['type' => 'string', 'description' => 'English name, e.g. "Spanish". Auto-filled if omitted and the code is recognized.'],
                    'native_name' => ['type' => 'string', 'description' => 'Name in that language, e.g. "Español".'],
                    'flag'        => ['type' => 'string', 'description' => 'Flag emoji.'],
                ], 'required' => ['code'], 'additionalProperties' => false],
            ];
            $tools[] = [
                'name' => 'slate_i18n_translate_string',
                'description' => 'Save a translation for one string in one locale. Saves as a draft by default; set publish=true to make it live immediately (same effect as saving then calling slate_i18n_publish_translations for just this string).',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'string_id' => ['type' => 'integer', 'description' => 'From slate_i18n_list_strings.'],
                    'locale'    => ['type' => 'string', 'description' => 'Target language code, e.g. "fr".'],
                    'text'      => ['type' => 'string', 'description' => 'The translated text.'],
                    'publish'   => ['type' => 'boolean', 'description' => 'Default false (saves as draft).'],
                ], 'required' => ['string_id', 'locale', 'text'], 'additionalProperties' => false],
            ];
            $tools[] = [
                'name' => 'slate_i18n_publish_translations',
                'description' => 'Publish all draft translations for a locale (or every locale if omitted) so they go live on the site.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'locale' => ['type' => 'string', 'description' => 'Omit to publish drafts across all locales.'],
                ], 'additionalProperties' => false],
            ];
        }
        return $tools;
    }

    public static function callTool($result, string $name, array $args, array $context): mixed {
        if ($result !== null) return $result;
        $tid = current_tenant_id();

        if ($name === 'slate_i18n_list_languages') {
            self::requireScope($context, 'mlt.view');
            return ['languages' => MLT_LangRepo::all($tid)];
        }

        if ($name === 'slate_i18n_list_strings') {
            self::requireScope($context, 'mlt.view');
            $locales = array_filter(array_map('strval', (array)($args['locales'] ?? [])));
            if (!$locales) {
                $locales = array_column(MLT_LangRepo::enabled($tid), 'code');
            }
            $filterArg = (string)($args['filter'] ?? 'all');
            $filter = in_array($filterArg, ['all', 'untranslated'], true) ? $filterArg : 'all';
            $limit  = max(1, min(200, (int)($args['limit'] ?? 50)));
            $rows = MLT_StringRepo::grid($tid, $locales, trim((string)($args['search'] ?? '')), $filter, $limit, 0);
            return ['strings' => $rows];
        }

        if ($name === 'slate_i18n_add_language') {
            self::requireScope($context, 'mlt.manage');
            $code = trim((string)($args['code'] ?? ''));
            if ($code === '') throw new InvalidArgumentException('code is required.');
            $id = MLT_LangRepo::add(
                $tid, $code,
                (string)($args['name'] ?? ''), (string)($args['native_name'] ?? ''), (string)($args['flag'] ?? '')
            );
            AuditLog::record('mlt.language_added', (string)$id, ['via' => 'mcp', 'code' => $code]);
            return ['ok' => true, 'language_id' => $id, 'language' => MLT_LangRepo::byCode($tid, strtolower($code))];
        }

        if ($name === 'slate_i18n_translate_string') {
            self::requireScope($context, 'mlt.manage');
            $stringId = (int)($args['string_id'] ?? 0);
            $locale   = trim((string)($args['locale'] ?? ''));
            $text     = (string)($args['text'] ?? '');
            if ($stringId <= 0 || $locale === '') throw new InvalidArgumentException('string_id and locale are required.');
            $status = !empty($args['publish']) ? 'published' : 'draft';
            MLT_StringRepo::saveCell($tid, $stringId, $locale, $text, $status);
            AuditLog::record('mlt.string_translated', (string)$stringId, ['via' => 'mcp', 'locale' => $locale, 'status' => $status]);
            return ['ok' => true, 'string_id' => $stringId, 'locale' => $locale, 'status' => $status];
        }

        if ($name === 'slate_i18n_publish_translations') {
            self::requireScope($context, 'mlt.manage');
            $locale = trim((string)($args['locale'] ?? ''));
            $n = MLT_StringRepo::publish($tid, $locale !== '' ? $locale : null);
            AuditLog::record('mlt.published', $locale ?: 'all', ['via' => 'mcp', 'count' => $n]);
            return ['ok' => true, 'published_count' => $n];
        }

        return null;
    }

    private static function requireScope(array $context, string $scope): void {
        if (!in_array($scope, (array)($context['scopes'] ?? []), true)) {
            throw new RuntimeException("This token does not grant the \"$scope\" scope.");
        }
    }
}
