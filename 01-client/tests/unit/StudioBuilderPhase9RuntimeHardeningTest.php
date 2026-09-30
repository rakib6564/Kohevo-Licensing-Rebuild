<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Phase 9B public runtime
 * hardening.
 *
 * Autoloader only (no database): `SLATE_URL` fails closed (config.php's own
 * statement, run in a child process), SiteContext base-URL validation, the
 * canonical containment matrix, og:image / og:url with and without a base URL,
 * the ETag body-finality rule for output handlers, and PublicResponse's
 * validator stripping.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\RenderMode;
use Slate\Module\StudioBuilder\Render\Seo\SeoHead;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Runtime\PublicResponse;
use Slate\Module\StudioBuilder\Runtime\StudioHttpResponder;

/** A media resolver returning one site-relative image for id 7. */
function sbr9u_media(): MediaResolverInterface
{
    return new class implements MediaResolverInterface {
        public function resolveImage(int $mediaId): ?ResolvedMedia
        {
            return $mediaId === 7 ? new ResolvedMedia(7, '/uploads/media/2026/01/hero.jpg') : null;
        }
    };
}

function sbr9u_page(string $routeMode = 'standalone'): PageAddress
{
    return PageAddress::fromRow([
        'id' => 5, 'uuid' => '00000000-0000-4000-8000-000000000005', 'tenant_id' => 1, 'title' => 'About', 'slug' => 'about',
        'page_type' => 'page', 'status' => 'published', 'route_mode' => $routeMode,
        'active_draft_revision_id' => 9, 'published_revision_id' => 9,
    ]);
}

/** Evaluate config.php's own SLATE_URL statement in a child PHP with APP_URL set/unset. */
function sbr9u_slate_url(?string $appUrl): string
{
    $config = (string) file_get_contents(dirname(__DIR__, 2) . '/config.php');
    assert_true(preg_match("/^define\\('SLATE_URL', .*\\);$/m", $config, $m) === 1, 'config.php defines SLATE_URL in one statement');
    $code = 'function env($k, $d = "") { if (isset($_ENV[$k])) return $_ENV[$k]; $v = getenv($k); return $v !== false ? $v : $d; } '
        . ($appUrl !== null ? '$_ENV["APP_URL"] = ' . var_export($appUrl, true) . '; ' : '')
        . $m[0] . ' echo json_encode(SLATE_URL);';
    $cmd = 'env -u APP_URL ' . escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg($code);
    $out = shell_exec($cmd);
    $decoded = json_decode((string) $out, true);
    assert_true(is_string($decoded), 'child printed SLATE_URL: ' . var_export($out, true));
    return $decoded;
}

unit('phase9b slate_url: config.php never manufactures a real domain — no APP_URL means an empty SLATE_URL; a configured APP_URL is kept', function (): void {
    $config = (string) file_get_contents(dirname(__DIR__, 2) . '/config.php');
    assert_true(!str_contains($config, 'greenlightinduction') && !str_contains($config, 'rakibhasaan'), 'no hard-coded production host in config.php');
    assert_eq('', sbr9u_slate_url(null), 'APP_URL unset');
    assert_eq('', sbr9u_slate_url(''), 'APP_URL empty');
    assert_eq('https://site.example/slate', sbr9u_slate_url('https://site.example/slate/'), 'a valid APP_URL is kept (trailing slash trimmed, as before)');
    assert_eq('http://localhost:8099', sbr9u_slate_url('http://localhost:8099'), 'local development keeps working');
});

unit('phase9b slate_url: SiteContext only accepts an absolute http(s) base URL — anything else fails closed to no base URL', function (): void {
    foreach ([
        'https://site.example'        => 'https://site.example',
        'https://site.example/slate/' => 'https://site.example/slate',
        "  http://localhost:8099 \n"  => 'http://localhost:8099',
        ''                            => '',
        'site.example'                => '',
        '//site.example'              => '',
        'ftp://site.example'          => '',
        'javascript:alert(1)'         => '',
        'https://'                    => '',
        'https://site example.com'    => '',
        'https://site.example/"><x'   => '',
        "https://site.example/\nx"    => '',
    ] as $configured => $expected) {
        assert_eq($expected, SiteContext::configuredBaseUrl((string) $configured), 'configured ' . json_encode($configured));
    }
});

unit('phase9b canonical: same-site absolute and path canonicals are honoured; foreign, protocol-relative, look-alike, malformed and empty ones fall back to the page URL', function (): void {
    $site = new SiteContext('https://site.example', 'Site');
    $own = 'https://site.example/about';
    foreach ([
        'https://site.example/team'                => 'https://site.example/team',
        'HTTPS://SITE.EXAMPLE/team'                => 'HTTPS://SITE.EXAMPLE/team',
        'http://site.example/team'                 => 'http://site.example/team',
        '/team'                                    => 'https://site.example/team',
        '/team?ref=x'                              => 'https://site.example/team?ref=x',
        'https://evil.example/team'                => $own,
        '//evil.example/team'                      => $own,
        'https://site.example.evil.example/team'   => $own,
        'https://site.example@evil.example/team'   => $own,
        'https://evil.example/?https://site.example' => $own,
        'javascript:alert(1)'                      => $own,
        'data:text/html,x'                         => $own,
        'mailto:a@site.example'                    => $own,
        '#top'                                     => $own,
        'https://'                                 => $own,
        'team'                                     => $own,
        ''                                         => $own,
        '   '                                      => $own,
    ] as $authored => $expected) {
        assert_eq($expected, SeoHead::canonical($authored, $site, '/about'), 'authored ' . json_encode($authored));
    }
    assert_eq($own, SeoHead::canonical(null, $site, '/about'), 'no authored canonical');
    assert_eq('https://site.example/', SeoHead::canonical(null, $site, SeoHead::publicPath(sbr9u_page('homepage'))), 'homepage route canonical is /');
});

unit('phase9b canonical: with no base URL there is never a canonical, og:url or relative og:image — whatever the document says', function (): void {
    $noBase = new SiteContext(SiteContext::configuredBaseUrl(''), 'Site');
    foreach (['https://evil.example/x', 'https://site.example/team', '/team', null] as $authored) {
        assert_null(SeoHead::canonical($authored, $noBase, '/about'), 'no base: ' . json_encode($authored));
    }
    $seo = ['title' => 'T', 'description' => 'D', 'robots' => 'index,follow', 'canonical_url' => 'https://evil.example/x', 'og_image_media_id' => 7];
    $tags = SeoHead::build($seo, sbr9u_page(), $noBase, RenderMode::Public, sbr9u_media())->tags();
    assert_true(!str_contains($tags, 'rel="canonical"') && !str_contains($tags, 'og:url') && !str_contains($tags, 'og:image'), 'fail closed: ' . $tags);
    assert_true(!str_contains($tags, 'evil.example') && str_contains($tags, 'name="twitter:card" content="summary"'));

    $withBase = SeoHead::build($seo, sbr9u_page(), new SiteContext('https://site.example', 'Site'), RenderMode::Public, sbr9u_media())->tags();
    assert_true(str_contains($withBase, '<link rel="canonical" href="https://site.example/about">'), 'foreign authored canonical replaced by the page URL');
    assert_true(str_contains($withBase, '<meta property="og:url" content="https://site.example/about">'));
    assert_true(str_contains($withBase, '<meta property="og:image" content="https://site.example/uploads/media/2026/01/hero.jpg">'), 'og:image made absolute from the configured base');
    assert_true(!str_contains($withBase, 'evil.example'));
});

unit('phase9b etag: only body-preserving output handlers let the Studio ETag stand — any rewriting buffer (e.g. multilang-translate\'s closure) suppresses it', function (): void {
    assert_true(StudioHttpResponder::bodyIsFinal([]), 'no buffer');
    assert_true(StudioHttpResponder::bodyIsFinal(['default output handler']), 'php.ini output_buffering');
    assert_true(StudioHttpResponder::bodyIsFinal(['default output handler', 'zlib output compression']), 'transport compression');
    foreach ([['Closure::__invoke'], ['default output handler', 'Closure::__invoke'], ['URL-Rewriter'], ['mb_output_handler'], ['MyPlugin::filter']] as $stack) {
        assert_true(!StudioHttpResponder::bodyIsFinal($stack), 'rewriting stack: ' . json_encode($stack));
    }
    $live = static function (): bool {
        ob_start(static fn(string $b): string => strtoupper($b));
        try {
            return StudioHttpResponder::bodyIsFinal(ob_list_handlers());
        } finally {
            ob_end_clean();
        }
    };
    assert_true(!$live(), 'a real userland ob_start callback is detected from the live handler stack');
});

unit('phase9b etag: withoutValidator() drops only the ETag — status, body and every other header are unchanged', function (): void {
    $ok = PublicResponse::ok(['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'public, no-cache', 'ETag' => '"' . str_repeat('a', 64) . '"'], '<p>x</p>');
    $bare = $ok->withoutValidator();
    assert_eq(200, $bare->status);
    assert_eq('<p>x</p>', $bare->body);
    assert_eq(['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'public, no-cache'], $bare->headers);
    assert_true(isset($ok->headers['ETag']), 'the original response is immutable');
});
