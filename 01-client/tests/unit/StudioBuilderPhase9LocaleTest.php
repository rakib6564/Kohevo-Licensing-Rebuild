<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Phase 9A tenant public locale.
 *
 * Autoloader only (no database): the core I18n pin semantics, the Studio
 * public-locale boundary (StudioPublicLocale), and static guards that the pin
 * wraps exactly the public render and the publish-time compilation — never
 * the authoring (preview / editor) renders.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Runtime\StudioPublicLocale;
use Slate\Services\I18n\I18n;

/** Clear every visitor input and any pin, run $fn, clear again. */
function sbl9u_clean(callable $fn): void
{
    $reset = static function (): void {
        unset($_GET['lang'], $_SESSION['slate_lang'], $_SERVER['HTTP_X_SLATE_FORCE_LOCALE']);
        I18n::pinLocale(null);
        I18n::resetCache();
    };
    $reset();
    try {
        $fn();
    } finally {
        $reset();
    }
}

function sbl9u_source(string $relative): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
}

/** The body of one method, from its signature to the next method signature. */
function sbl9u_method(string $source, string $name): string
{
    $start = strpos($source, 'function ' . $name . '(');
    assert_true($start !== false, "method {$name} exists");
    $next = preg_match('/\n    (?:public|private|protected) (?:static )?function /', $source, $m, PREG_OFFSET_CAPTURE, $start + 10);
    return $next === 1 ? substr($source, $start, $m[0][1] - $start) : substr($source, $start);
}

unit('phase9a locale: a pinned locale outranks the force header, ?lang= and the session, and ?lang= is not persisted while pinned', function (): void {
    sbl9u_clean(function (): void {
        // 'en' is always supported, so every visitor input below is one core I18n would honour.
        $_SERVER['HTTP_X_SLATE_FORCE_LOCALE'] = 'en';
        $_GET['lang'] = 'en';
        $_SESSION['slate_lang'] = 'en';
        assert_eq('en', I18n::currentLocale(), 'unpinned: the visitor input wins, as before');

        unset($_SESSION['slate_lang'], $_SERVER['HTTP_X_SLATE_FORCE_LOCALE']);
        assert_eq(null, I18n::pinLocale('fr'), 'no previous pin');
        assert_eq('fr', I18n::currentLocale(), 'pinned beats ?lang=');
        assert_true(!isset($_SESSION['slate_lang']), '?lang= is not written to the session while pinned');
        $_SERVER['HTTP_X_SLATE_FORCE_LOCALE'] = 'en';
        $_SESSION['slate_lang'] = 'en';
        assert_eq('fr', I18n::currentLocale(), 'pinned beats the force header and the session');

        assert_eq('fr', I18n::pinLocale(null), 'un-pin returns the previous pin');
        assert_eq('en', I18n::currentLocale(), 'un-pinned: visitor precedence is back, unchanged');
    });
});

unit('phase9a locale: pinLocale() accepts only well-formed locale codes', function (): void {
    sbl9u_clean(function (): void {
        foreach (['fr"><script>', '../fr', 'x', 'fr-toolongcode', "fr\n", ''] as $bad) {
            I18n::pinLocale($bad);
            assert_eq(null, I18n::pinLocale(null), 'rejected: ' . json_encode($bad));
        }
        foreach (['fr', 'en', 'pt-BR', 'zh_Hant'] as $good) {
            I18n::pinLocale($good);
            assert_eq($good, I18n::pinLocale(null), 'accepted: ' . $good);
        }
    });
});

unit('phase9a locale: siteLocale() never reads the request and never throws — without a database it is English', function (): void {
    sbl9u_clean(function (): void {
        $_GET['lang'] = 'en';
        $_SERVER['HTTP_X_SLATE_FORCE_LOCALE'] = 'fr';
        $_SESSION['slate_lang'] = 'fr';
        $locale = I18n::siteLocale();
        assert_true(isset(I18n::supportedLanguages()[$locale]), 'a supported locale');
        assert_eq('en', StudioPublicLocale::resolve());
    });
});

unit('phase9a locale: StudioPublicLocale::run() pins the site locale for the call only and restores the previous pin, even on failure', function (): void {
    sbl9u_clean(function (): void {
        $_GET['lang'] = 'en';
        I18n::pinLocale('fr');
        $inside = StudioPublicLocale::run(static fn(): string => I18n::currentLocale());
        assert_eq(StudioPublicLocale::resolve(), $inside, 'inside: the site locale');
        assert_eq('fr', I18n::currentLocale(), 'after: the previous pin is back');
        try {
            StudioPublicLocale::run(static function (): void {
                throw new \RuntimeException('render failed');
            });
        } catch (\RuntimeException $expected) {
        }
        assert_eq('fr', I18n::currentLocale(), 'restored after an exception too');
        I18n::pinLocale(null);
        assert_eq('en', StudioPublicLocale::run(static fn() => SiteContext::fromEnvironment()->locale), 'SiteContext (<html lang> + compile fingerprint) reads the pinned locale');
    });
});

unit('phase9a locale: the pin wraps exactly the public render and the publish-time compile — never preview/editor — and the adapter pins only a served page', function (): void {
    $runtime = sbl9u_method(sbl9u_source('src/Module/StudioBuilder/Runtime/StudioPublicRuntime.php'), 'serve');
    assert_true(preg_match('/StudioPublicLocale::run\(function \(\) use \(\$tenantId, \$page\) \{\s*\$context = RenderContext::forPublic\(\s*\$tenantId,\s*\$this->renderer->siteContext\(\)/', $runtime) === 1, 'public: the site context is built inside the pin');
    assert_true(str_contains($runtime, 'return $this->renderer->renderPublished($page, $context);'), 'public: render inside the pin');

    $app = sbl9u_source('src/Module/StudioBuilder/Application/StudioApplicationService.php');
    assert_true(preg_match('/StudioPublicLocale::run\(fn\(\) => \$this->renderer->compilePublished\(/', sbl9u_method($app, 'publish')) === 1, 'publish: the stored artifact is compiled inside the pin');
    foreach (['renderPreview', 'renderForEditor'] as $authoring) {
        assert_true(!str_contains(sbl9u_method($app, $authoring), 'StudioPublicLocale'), "{$authoring}: authoring renders keep the editor's own locale");
    }
    assert_eq(1, substr_count($app, 'StudioPublicLocale::'), 'publish is the only application-layer pin');

    $plugin = sbl9u_source('plugins/studio-builder/StudioBuilder.php');
    assert_true(preg_match('/if \(\$response === null\) \{\s*return false;\s*\}\s*StudioPublicLocale::pin\(\);\s*StudioHttpResponder::sendPublic\(\$response\);/', sbl9u_method($plugin, 'send')) === 1, 'send(): pins only after a page is confirmed');
    foreach (['servePublicPath', 'servePublicHomepage'] as $hook) {
        $body = sbl9u_method($plugin, $hook);
        assert_true(str_contains($body, 'return self::send(self::publicResponse(') && !str_contains($body, 'sendPublic'), "{$hook}: serves only through send()");
    }
});
