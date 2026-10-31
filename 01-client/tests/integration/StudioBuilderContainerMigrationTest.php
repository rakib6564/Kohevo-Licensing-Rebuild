<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — the 1.1.0 schema migration on a database that really holds
 * section_preset rows.
 *
 * Own throw-away database, built from install.sql and turned back into the 1.0.0 ENUMs: the rows can only exist there.
 * Pages, templates and their categories move to the Container names, built-in presets take their new keys, a tenant's
 * own template keeps its key, nothing else changes, and running it again does nothing.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Infrastructure\StudioSchemaManager;

/** Run $fn with Database pointed at a fresh database of its own. */
function sbcm_in_fresh_db(callable $fn): void
{
    $name = 'slate_sbcm_' . slate_test_ns();
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $host = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '');
    $root = new \PDO($host . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `{$name}`");
    $root->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4");
    $pdo = new \PDO($host . ";dbname={$name};charset=" . DB_CHARSET, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    $property = new \ReflectionProperty(Database::class, 'pdo');
    $previous = $property->getValue();
    $property->setValue(null, $pdo);
    try {
        $fn($pdo);
    } finally {
        $property->setValue(null, $previous);
        $root->exec("DROP DATABASE IF EXISTS `{$name}`");
    }
}

function sbcm_column(\PDO $pdo, string $table, string $column): array
{
    return (array) $pdo->query("SELECT COLUMN_TYPE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$column}'")->fetch();
}

unit('container migration: section_preset rows become container_preset, the ENUMs narrow, and a second run does nothing', function (): void {
    sbcm_in_fresh_db(static function (\PDO $pdo): void {
        StudioSchemaManager::ensureBaselineTables();
        // The 1.0.0 columns, as a deployed site has them.
        $pdo->exec("ALTER TABLE studiobuilder_pages MODIFY page_type ENUM('page','landing','system','header_partial','footer_partial','section_preset') NOT NULL DEFAULT 'page'");
        $pdo->exec("ALTER TABLE studiobuilder_templates MODIFY template_type ENUM('page_template','section_preset','header_preset','footer_preset','block_preset') NOT NULL DEFAULT 'section_preset'");

        $page = static function (string $slug, string $type) use ($pdo): void {
            $pdo->prepare("INSERT INTO studiobuilder_pages (tenant_id, uuid, title, slug, page_type) VALUES (1, ?, ?, ?, ?)")->execute([sprintf('%08x-1111-4111-8111-111111111111', crc32($slug)), $slug, $slug, $type]);
        };
        $template = static function (int $tenant, string $key, string $type, string $category, int $system) use ($pdo): void {
            $pdo->prepare("INSERT INTO studiobuilder_templates (tenant_id, uuid, template_key, template_type, category, name, document_json, is_system) VALUES (?, ?, ?, ?, ?, ?, '{}', ?)")
                ->execute([$tenant, sprintf('%08x-2222-4222-8222-222222222222', crc32($tenant . $key)), $key, $type, $category, $key, $system]);
        };
        $page('promo-band', 'section_preset');
        $page('home', 'page');
        $page('site-header', 'header_partial');
        $template(1, 'system-section-faq', 'section_preset', 'section', 1);
        $template(2, 'system-section-faq', 'section_preset', 'content', 1);
        $template(1, 'my-section', 'section_preset', 'section', 0);
        $template(1, 'system-section-mine', 'section_preset', 'general', 0);
        $template(1, 'home-layout', 'page_template', 'general', 0);
        $template(1, 'system-header-classic', 'header_preset', 'header', 1);

        StudioSchemaManager::runMigrations('1.0.0');

        $types = static fn(): array => $pdo->query('SELECT slug, page_type FROM studiobuilder_pages ORDER BY slug')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $rows = static fn(): array => $pdo->query('SELECT tenant_id, template_key, template_type, category, is_system FROM studiobuilder_templates ORDER BY tenant_id, template_key')->fetchAll(\PDO::FETCH_NUM);
        assert_eq(['home' => 'page', 'promo-band' => 'container_preset', 'site-header' => 'header_partial'], $types(), 'a page keeps its row under the new type');
        $expected = [
            [1, 'home-layout', 'page_template', 'general', 0],
            [1, 'my-section', 'container_preset', 'container', 0],
            [1, 'system-container-faq', 'container_preset', 'container', 1],
            [1, 'system-header-classic', 'header_preset', 'header', 1],
            [1, 'system-section-mine', 'container_preset', 'general', 0],
            [2, 'system-container-faq', 'container_preset', 'content', 1],
        ];
        $asExpected = static fn(array $rows): array => array_map(static fn(array $r): array => array_map(static fn($v) => is_numeric($v) ? (int) $v : $v, $r), $rows);
        assert_eq($expected, $asExpected($rows()), 'built-in keys move, a tenant\'s own keys stay, categories follow');

        $pages = sbcm_column($pdo, 'studiobuilder_pages', 'page_type');
        assert_eq("enum('page','landing','system','header_partial','footer_partial','container_preset')", $pages['COLUMN_TYPE'], 'the old name is gone from the page column');
        assert_eq('page', trim((string) $pages['COLUMN_DEFAULT'], "'")); // MariaDB reports a default with its quotes
        $templates = sbcm_column($pdo, 'studiobuilder_templates', 'template_type');
        assert_eq("enum('page_template','container_preset','header_preset','footer_preset','block_preset')", $templates['COLUMN_TYPE'], 'and from the template column');
        assert_eq('container_preset', trim((string) $templates['COLUMN_DEFAULT'], "'"), 'with the default install.sql gives a new site');

        StudioSchemaManager::runMigrations('1.0.0');
        assert_eq($expected, $asExpected($rows()), 'running it again changes nothing');
        assert_eq($pages, sbcm_column($pdo, 'studiobuilder_pages', 'page_type'));

        $pdo->exec("UPDATE studiobuilder_templates SET category = 'section' WHERE template_key = 'home-layout'");
        StudioSchemaManager::runMigrations('1.1.0');
        assert_eq('section', $pdo->query("SELECT category FROM studiobuilder_templates WHERE template_key = 'home-layout'")->fetchColumn(), 'a site already on 1.1.0 does not run it');
    });
});

unit('container migration: a built-in preset that already has its new key is left to the seeder, not an error', function (): void {
    sbcm_in_fresh_db(static function (\PDO $pdo): void {
        StudioSchemaManager::ensureBaselineTables();
        $pdo->exec("ALTER TABLE studiobuilder_templates MODIFY template_type ENUM('page_template','section_preset','container_preset','header_preset','footer_preset','block_preset') NOT NULL DEFAULT 'section_preset'");
        foreach (['system-section-faq', 'system-container-faq'] as $i => $key) {
            $pdo->prepare("INSERT INTO studiobuilder_templates (tenant_id, uuid, template_key, template_type, category, name, document_json, is_system) VALUES (1, ?, ?, 'section_preset', 'content', ?, '{}', 1)")
                ->execute([sprintf('%08x-3333-4333-8333-333333333333', $i), $key, $key]);
        }
        StudioSchemaManager::runMigrations('1.0.0');
        assert_eq(['system-container-faq', 'system-section-faq'], $pdo->query('SELECT template_key FROM studiobuilder_templates ORDER BY template_key')->fetchAll(\PDO::FETCH_COLUMN), 'the unique key holds and the migration finishes');
    });
});
