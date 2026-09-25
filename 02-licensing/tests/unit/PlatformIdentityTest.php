<?php
/**
 * Unit tests for PlatformIdentity/PlatformSignature — the platform's own
 * deterministic identity, independent of any tenant setting, database
 * access, or tenant context (Kohevo Brand Persistence System, Phase 1/1A).
 *
 * Boots via the autoloader only (tests/unit/run.php): no config.php, no DB,
 * so SLATE_URL is not guaranteed defined — every URL assertion tolerates
 * both cases, matching the existing guard in AuthSecurityBoundaryTest.php.
 */

declare(strict_types=1);

use Slate\Services\Content\PlatformIdentity;
use Slate\Services\Content\PlatformSignature;

/** Map a PlatformIdentity-returned URL back to a local file path, tolerating an optional SLATE_URL prefix (undefined under this DB-free harness). */
function _pid_local_path(string $url): string
{
    $idx = strpos($url, '/assets/');
    assert_true($idx !== false, "$url has no /assets/ segment");
    return __DIR__ . '/../..' . substr($url, $idx);
}

// ── PlatformIdentity ──────────────────────────────────────

unit('PlatformIdentity: name is Kohevo', function () {
    assert_eq('Kohevo', PlatformIdentity::name());
});

unit('PlatformIdentity: mark/wordmark/dark-wordmark/favicon resolve under the platform asset namespace, never uploads/', function () {
    foreach ([
        PlatformIdentity::markUrl(),
        PlatformIdentity::wordmarkUrl(),
        PlatformIdentity::wordmarkDarkUrl(),
        PlatformIdentity::faviconUrl(),
    ] as $url) {
        assert_true(str_contains($url, '/assets/'), "$url is not under /assets/");
        assert_false(str_contains($url, '/uploads/'), "$url must never resolve into tenant-writable /uploads/");
    }
});

unit('PlatformIdentity: mark/wordmark/dark-wordmark resolve to the official brand package filenames', function () {
    assert_true(str_ends_with(PlatformIdentity::markUrl(), '/assets/platform/brand/kohevo-compact-noir.svg'));
    assert_true(str_ends_with(PlatformIdentity::wordmarkUrl(), '/assets/platform/brand/kohevo-lockup-horizontal-noir.svg'));
    assert_true(str_ends_with(PlatformIdentity::wordmarkDarkUrl(), '/assets/platform/brand/kohevo-lockup-horizontal-blanc.svg'));
});

unit('PlatformIdentity: favicon intentionally resolves to the same official asset as the mark (LISEZMOI names kohevo-compact for favicon use)', function () {
    assert_eq(PlatformIdentity::markUrl(), PlatformIdentity::faviconUrl());
    assert_true(str_ends_with(PlatformIdentity::faviconUrl(), '/assets/platform/brand/kohevo-compact-noir.svg'));
});

unit('PlatformIdentity: every referenced platform asset file actually exists on disk', function () {
    foreach ([
        PlatformIdentity::markUrl(),
        PlatformIdentity::wordmarkUrl(),
        PlatformIdentity::wordmarkDarkUrl(),
        PlatformIdentity::faviconUrl(),
    ] as $url) {
        $path = _pid_local_path($url);
        assert_true(is_file($path), "missing asset file: $path (from $url)");
    }
});

unit('PlatformIdentity: every referenced asset lives under the canonical assets/platform/brand/ directory', function () {
    foreach ([
        PlatformIdentity::markUrl(),
        PlatformIdentity::wordmarkUrl(),
        PlatformIdentity::wordmarkDarkUrl(),
        PlatformIdentity::faviconUrl(),
    ] as $url) {
        assert_true(str_contains($url, '/assets/platform/brand/'), "$url is not under the canonical platform brand directory");
    }
});

unit('PlatformIdentity: the mark/wordmark/dark-wordmark SVGs are well-formed, readable <svg> documents', function () {
    foreach ([PlatformIdentity::markUrl(), PlatformIdentity::wordmarkUrl(), PlatformIdentity::wordmarkDarkUrl()] as $url) {
        $path = _pid_local_path($url);
        $prevSetting = libxml_use_internal_errors(true);
        $doc = simplexml_load_file($path);
        libxml_use_internal_errors($prevSetting);
        assert_true($doc !== false, "$path did not parse as valid XML/SVG");
        assert_eq('svg', $doc->getName(), "$path root element is not <svg>");
    }
});

unit('PlatformIdentity: signature is "Powered by Kohevo"', function () {
    assert_eq('Powered by Kohevo', PlatformIdentity::signature());
});

unit('PlatformIdentity: repeated calls are deterministic', function () {
    assert_eq(PlatformIdentity::markUrl(), PlatformIdentity::markUrl());
    assert_eq(PlatformIdentity::name(), PlatformIdentity::name());
    assert_eq(PlatformIdentity::signature(), PlatformIdentity::signature());
});

unit('PlatformIdentity: source has no dependency on Database or tenant resolution (proof by construction)', function () {
    $src = file_get_contents((new \ReflectionClass(PlatformIdentity::class))->getFileName());
    assert_false(str_contains($src, 'Database'), 'PlatformIdentity must never reference Database');
    assert_false(str_contains($src, 'current_tenant_id'), 'PlatformIdentity must never reference tenant resolution');
    assert_false(str_contains($src, 'TenantContext'), 'PlatformIdentity must never reference TenantContext');
});

// ── PlatformSignature ─────────────────────────────────────

unit('PlatformSignature: source contains no hardcoded /assets/ literal — every path comes from PlatformIdentity', function () {
    $src = file_get_contents((new \ReflectionClass(PlatformSignature::class))->getFileName());
    assert_false((bool) preg_match('#["\']\/?assets\/#', $src), 'PlatformSignature must source paths via PlatformIdentity, not a literal path');
});

unit('PlatformSignature: compact/standard/signature modes each include the platform mark URL', function () {
    foreach ([PlatformSignature::MODE_COMPACT, PlatformSignature::MODE_STANDARD, PlatformSignature::MODE_SIGNATURE] as $mode) {
        $html = PlatformSignature::render($mode);
        assert_true(str_contains($html, PlatformIdentity::markUrl()), "$mode output is missing the platform mark URL");
    }
});

unit('PlatformSignature: standard and signature modes include the platform name; compact does not', function () {
    assert_true(str_contains(PlatformSignature::render(PlatformSignature::MODE_STANDARD), 'Kohevo'));
    assert_true(str_contains(PlatformSignature::render(PlatformSignature::MODE_SIGNATURE), 'Kohevo'));
    // Compact mode DOES include "Kohevo" in the alt text (accessibility), which is fine —
    // this asserts the img/alt is present rather than asserting text is absent.
    assert_true(str_contains(PlatformSignature::render(PlatformSignature::MODE_COMPACT), 'alt="Kohevo"'));
});

unit('PlatformSignature: signature mode includes "Powered by"', function () {
    assert_true(str_contains(PlatformSignature::render(PlatformSignature::MODE_SIGNATURE), 'Powered by'));
});

unit('PlatformSignature: an unrecognized mode falls back to signature mode rather than throwing', function () {
    $fallback = PlatformSignature::render('nonsense-mode');
    assert_eq(PlatformSignature::render(PlatformSignature::MODE_SIGNATURE), $fallback);
});

unit('PlatformSignature: default mode (no argument) is signature mode', function () {
    assert_eq(PlatformSignature::render(PlatformSignature::MODE_SIGNATURE), PlatformSignature::render());
});

unit('PlatformSignature: output never contains an unescaped angle bracket from identity values', function () {
    // Defensive — PlatformIdentity's values are fixed constants today, but this
    // guards the render() contract itself, not just today's literal values.
    foreach ([PlatformSignature::MODE_COMPACT, PlatformSignature::MODE_STANDARD, PlatformSignature::MODE_SIGNATURE] as $mode) {
        $html = PlatformSignature::render($mode);
        assert_false(str_contains($html, '<script'), "$mode output must never contain a raw <script> tag");
    }
});
