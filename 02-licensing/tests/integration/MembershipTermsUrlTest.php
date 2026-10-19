<?php
/**
 * S-12: Membership → Settings "Terms & consent URL" is printed as a link on the
 * customer onboarding page, so only an absolute http(s) address may be stored,
 * and a bad value already stored must not become a link.
 */

declare(strict_types=1);

if (!class_exists('MembershipAPI')) {
    require_once dirname(__DIR__, 2) . '/plugins/membership/MembershipAPI.php';
}

unit('membership terms URL: only an absolute http(s) address is accepted', function (): void {
    assert_eq('', MembershipAPI::cleanTermsUrl(''), 'empty means no link');
    assert_eq('', MembershipAPI::cleanTermsUrl('   '), 'blank means no link');
    assert_eq('https://example.com/terms', MembershipAPI::cleanTermsUrl('  https://example.com/terms '), 'https is kept, trimmed');
    assert_eq('http://example.com/terms?x=1#c', MembershipAPI::cleanTermsUrl('http://example.com/terms?x=1#c'), 'http is kept');
    foreach ([
        'javascript:alert(1)', 'JavaScript:alert(1)', ' javascript:alert(1)', "java\tscript:alert(1)", "javascript\n:alert(1)",
        'data:text/html,<script>alert(1)</script>', 'vbscript:msgbox(1)', 'file:///etc/passwd', 'ftp://example.com/x',
        '/terms', '//evil.example/terms', 'example.com/terms', 'https://', 'https:///terms',
        'https://example.com/' . str_repeat('a', 500),
    ] as $bad) {
        assert_null(MembershipAPI::cleanTermsUrl($bad), 'refused: ' . json_encode($bad));
    }
});

unit('membership terms URL: a bad value already stored is never handed out as a link', function (): void {
    $before = Database::setting('membership.terms_url');
    try {
        Database::setSetting('membership.terms_url', 'javascript:alert(document.cookie)');
        assert_eq('', MembershipAPI::termsUrl(), 'a stored javascript: URL reads as no link');
        Database::setSetting('membership.terms_url', 'https://example.com/consent');
        assert_eq('https://example.com/consent', MembershipAPI::termsUrl(), 'a good stored URL is used');
    } finally {
        Database::setSetting('membership.terms_url', (string) ($before ?? ''));
    }
});

unit('membership terms URL: the settings screen and the onboarding page go through the check', function (): void {
    $root = dirname(__DIR__, 2) . '/plugins/membership';
    $settings = (string) file_get_contents($root . '/admin/settings.php');
    assert_true(str_contains($settings, "MembershipAPI::cleanTermsUrl((string)(\$_POST['terms_url'] ?? ''))"), 'the posted value is checked');
    assert_false(str_contains($settings, "setSetting('membership.terms_url', trim("), 'and never stored raw');
    assert_true((bool) preg_match('/if \(\$termsUrl !== null\) \{\s*Database::setSetting\(\'membership\.terms_url\', \$termsUrl\);/', $settings), 'a refused value is not stored');
    $view = (string) file_get_contents($root . '/public/views/onboarding.php');
    assert_true(str_contains($view, '$termsUrl = MembershipAPI::termsUrl();'), 'onboarding links only a checked URL');
    assert_false(str_contains($view, "Database::setting('membership.terms_url')"), 'and never reads the raw setting');
});
