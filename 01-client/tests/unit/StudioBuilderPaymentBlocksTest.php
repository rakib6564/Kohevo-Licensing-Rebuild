<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the `payment.button` and `payment.link` blocks.
 *
 * Autoloader only, no database and no Stripe: the Stripe plugin is a small stand-in, the signer is a local HMAC. Verifies the
 * signed offer (a visitor cannot change the price, currency or description), the whole pay-request flow (method, setup,
 * throttle, tenant, the Stripe session is built from the signed terms only, a hostile referer, a non-Stripe checkout URL), the
 * throttle, the link rule, and that the editor canvas never receives a <form>.
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/src/autoload.php';
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
}

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Payment\PayRequest;
use Slate\Module\StudioBuilder\Payment\PaymentLinkUrl;
use Slate\Module\StudioBuilder\Payment\PaymentOffer;
use Slate\Module\StudioBuilder\Payment\PayThrottle;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\ProviderBindingResolver;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Tenancy\TenantContext;

const SBPB_TENANT = 101;
const SBPB_SECRET = 'unit-test-secret';

if (!defined('APP_SECRET')) {
    define('APP_SECRET', SBPB_SECRET);
}

if (!class_exists('StripePaymentAPI', false)) {
    /** Stand-in for the Stripe plugin: `isConfigured` is switchable. */
    final class StripePaymentAPI
    {
        public static bool $configured = true;
        public static function isConfigured(): bool { return self::$configured; }
    }
}
if (!function_exists('slate_sign')) {
    function slate_sign(string $context, string $value, ?int $length = null): ?string
    {
        return hash_hmac('sha256', $context . '|' . $value, SBPB_SECRET);
    }
}

function sbpb_signer(): callable { return static fn(string $c, string $v): ?string => hash_hmac('sha256', $c . '|' . $v, SBPB_SECRET); }
function sbpb_verifier(): callable { return static fn(string $c, string $v, string $s): bool => hash_equals(hash_hmac('sha256', $c . '|' . $v, SBPB_SECRET), $s); }

/** The form fields a button would post for these props. @return array<string, string> */
function sbpb_post(array $props = ['amount' => 49.99, 'currency' => 'EUR', 'description' => 'Consulting', 'success_path' => 'thank-you']): array
{
    $offer = PaymentOffer::fromProps($props, SBPB_TENANT);
    assert_true($offer !== null, 'the props make an offer');
    return ['t' => (string) $offer['t'], 'a' => (string) $offer['a'], 'c' => $offer['c'], 'd' => $offer['d'], 's' => $offer['s'], 'g' => (string) PaymentOffer::sign($offer, sbpb_signer())];
}

unit('payment blocks: an offer is made from the props in cents, only when it can be offered', function (): void {
    $o = PaymentOffer::fromProps(['amount' => 49.99, 'currency' => 'eur', 'description' => "  Two\n  hours ", 'success_path' => '/thank-you/'], SBPB_TENANT);
    assert_eq(['t' => SBPB_TENANT, 'a' => 4999, 'c' => 'EUR', 'd' => 'Two hours', 's' => 'thank-you'], $o);
    assert_eq(4999, PaymentOffer::fromProps(['amount' => 49.99], SBPB_TENANT)['a'] ?? null, '49.99 is exactly 4999 cents, not 4998');
    assert_true(PaymentOffer::fromProps(['amount' => 0.4], SBPB_TENANT) === null, 'below the minimum');
    assert_true(PaymentOffer::fromProps(['amount' => 1_000_000], SBPB_TENANT) === null, 'above the cap');
    assert_true(PaymentOffer::fromProps(['amount' => 10, 'currency' => 'XXX'], SBPB_TENANT) === null, 'unknown currency');
    assert_true(PaymentOffer::fromProps(['amount' => '10'], SBPB_TENANT) === null, 'an amount must be a number');
    assert_true(PaymentOffer::fromProps(['currency' => 'USD'], SBPB_TENANT) === null, 'no amount');
    assert_eq('', PaymentOffer::fromProps(['amount' => 10, 'success_path' => 'https://evil.test/x'], SBPB_TENANT)['s'], 'a success path is a site path only');
    assert_eq('', PaymentOffer::path('../admin'), 'no ..');
    assert_eq('shop/thanks', PaymentOffer::path('shop/thanks'));
    assert_eq('49.99 EUR', PaymentOffer::display(4999, 'EUR'));
});

unit('payment blocks: only a signature the server made verifies, and changing any term breaks it', function (): void {
    $post = sbpb_post();
    $offer = PaymentOffer::fromPost($post, sbpb_verifier());
    assert_true($offer !== null && $offer['a'] === 4999 && $offer['c'] === 'EUR', 'the genuine form verifies');

    foreach (['a' => '1', 'c' => 'USD', 'd' => 'Free', 's' => 'other', 't' => '202'] as $key => $value) {
        $tampered = $post;
        $tampered[$key] = $value;
        assert_true(PaymentOffer::fromPost($tampered, sbpb_verifier()) === null, "changing '{$key}' is refused");
    }
    $noSig = $post;
    $noSig['g'] = '';
    assert_true(PaymentOffer::fromPost($noSig, sbpb_verifier()) === null, 'an empty signature');
    $missing = $post;
    unset($missing['d']);
    assert_true(PaymentOffer::fromPost($missing, sbpb_verifier()) === null, 'a missing field');
    $arrayValue = $post;
    $arrayValue['a'] = ['4999'];
    assert_true(PaymentOffer::fromPost($arrayValue, sbpb_verifier()) === null, 'an array value');
    $hugeAmount = $post;
    $hugeAmount['a'] = '999999999999';
    assert_true(PaymentOffer::fromPost($hugeAmount, static fn(): bool => true) === null, 'out-of-range terms are refused even if a signature were accepted');
    assert_true(PaymentOffer::sign(['t' => 1, 'a' => 100, 'c' => 'USD', 'd' => '', 's' => ''], static fn(): ?string => null) === null, 'no secret, no signature');
});

/** @return array{tenantId: int, siteUrl: string, referer: string, visitor: string, configured: bool} */
function sbpb_env(array $over = []): array
{
    return $over + ['tenantId' => SBPB_TENANT, 'siteUrl' => 'https://acme.test/solaya', 'referer' => 'https://acme.test/solaya/pricing', 'visitor' => '203.0.113.7', 'configured' => true];
}

unit('payment blocks: the pay request builds the Stripe session from the signed terms only', function (): void {
    $calls = [];
    $checkout = static function (int $cents, string $currency, string $name, string $success, string $cancel) use (&$calls): string {
        $calls[] = [$cents, $currency, $name, $success, $cancel];
        return 'https://checkout.stripe.com/c/pay/cs_test_abc';
    };
    $allow = static fn(string $v): bool => true;

    $post = sbpb_post() + ['amount' => '1', 'price' => '1', 'currency' => 'USD'];
    $r = PayRequest::handle('POST', $post, sbpb_env(), sbpb_verifier(), $checkout, $allow);
    assert_eq(303, $r['status']);
    assert_eq('https://checkout.stripe.com/c/pay/cs_test_abc', $r['location']);
    assert_eq([[4999, 'EUR', 'Consulting', 'https://acme.test/solaya/thank-you', 'https://acme.test/solaya/pricing']], $calls, 'extra posted fields change nothing; return URLs are on this site (a subfolder install keeps its folder)');

    $calls = [];
    $r = PayRequest::handle('POST', sbpb_post(['amount' => 10]), sbpb_env(['referer' => 'https://evil.test/phish']), sbpb_verifier(), $checkout, $allow);
    assert_eq(303, $r['status']);
    assert_eq('https://acme.test/solaya/', $calls[0][4], 'a referer from another site is not used as the cancel address');
    assert_eq('https://acme.test/solaya/', $calls[0][3], 'no thank-you page set: back to the site');
    assert_eq('Payment', $calls[0][2], 'no description: a neutral product name');

    assert_eq(405, PayRequest::handle('GET', $post, sbpb_env(), sbpb_verifier(), $checkout, $allow)['status'], 'GET is refused');
    assert_eq(503, PayRequest::handle('POST', $post, sbpb_env(['configured' => false]), sbpb_verifier(), $checkout, $allow)['status'], 'Stripe not set up');
    assert_eq(429, PayRequest::handle('POST', $post, sbpb_env(), sbpb_verifier(), $checkout, static fn(string $v): bool => false)['status'], 'throttled');
    $forged = $post;
    $forged['a'] = '100';
    assert_eq(400, PayRequest::handle('POST', $forged, sbpb_env(), sbpb_verifier(), $checkout, $allow)['status'], 'a changed amount');
    assert_eq(400, PayRequest::handle('POST', $post, sbpb_env(['tenantId' => 202]), sbpb_verifier(), $checkout, $allow)['status'], 'an offer for another tenant');
    assert_eq(502, PayRequest::handle('POST', $post, sbpb_env(), sbpb_verifier(), static fn(): string => 'https://evil.test/pay', $allow)['status'], 'a checkout URL that is not Stripe is never followed');
    assert_eq(502, PayRequest::handle('POST', $post, sbpb_env(), sbpb_verifier(), static function (): string { throw new RuntimeException('Stripe down'); }, $allow)['status'], 'Stripe failing');
});

unit('payment blocks: the throttle allows a burst, then waits for the window', function (): void {
    $dir = sys_get_temp_dir() . '/kohevo-pay-test-' . bin2hex(random_bytes(4));
    $t = new PayThrottle($dir);
    for ($i = 0; $i < 3; $i++) {
        assert_true($t->allow('203.0.113.9', 3, 60, 1000 + $i), "attempt {$i} is within the limit");
    }
    assert_false($t->allow('203.0.113.9', 3, 60, 1010), 'the fourth in the window is refused');
    assert_true($t->allow('198.51.100.2', 3, 60, 1010), 'another visitor is not affected');
    assert_true($t->allow('203.0.113.9', 3, 60, 1100), 'after the window it is allowed again');
    foreach (glob($dir . '/*') ?: [] as $f) {
        unlink($f);
    }
    rmdir($dir);
    assert_true((new PayThrottle('/proc/definitely/not/writable'))->allow('x'), 'an unwritable directory never blocks a customer');
});

unit('payment blocks: a payment link is only ever a Stripe-hosted https address', function (): void {
    foreach (['https://buy.stripe.com/test_abc123', 'https://buy.stripe.com/14k4gK?prefilled_email=a%40b.co', 'https://checkout.stripe.com/c/pay/cs_live_a1#frag', 'https://donate.stripe.com/xyz', 'https://billing.stripe.com/p/login/abc'] as $ok) {
        assert_true(PaymentLinkUrl::isAllowed($ok), $ok);
    }
    foreach (['', 'http://buy.stripe.com/x', 'https://evil.test/buy.stripe.com/x', 'https://buy.stripe.com.evil.test/x', 'https://buy.stripe.com/x"onmouseover="a', 'javascript:alert(1)', 'https://buy.stripe.com/<script>', 'https://user@buy.stripe.com/x', 'https://sub.buy.stripe.com/x', 'https://buy.stripe.com/'] as $bad) {
        assert_false(PaymentLinkUrl::isAllowed($bad), $bad);
    }
});

final class _SbpbNoMedia implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia { return null; }
}

function sbpb_render(string $type, array $props, string $mode): string
{
    $tenants = new TenantContext();
    $documents = new DocumentRenderer(ModuleBlockDefinitions::studioRegistry(), BlockRendererRegistry::withStudioRenderers(), new _SbpbNoMedia(), new ProviderBindingResolver(new DataProviderRegistry(), $tenants));
    $site = new SiteContext('https://acme.test/solaya', 'Acme');
    $entitled = static fn(string $m): bool => true;
    $ctx = match ($mode) {
        'public' => RenderContext::forPublic(SBPB_TENANT, $site, $entitled),
        'editor' => RenderContext::forEditor(SBPB_TENANT, $site, StudioActor::authenticated(3, [StudioPermissions::VIEW, StudioPermissions::EDIT]), $entitled),
    };
    $block = ['id' => CanonicalDocumentSchema::newBlockId(), 'type' => $type, 'version' => 1, 'props' => $props, 'style' => CanonicalDocumentSchema::defaultBlockStyle(),
              'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [], 'animation' => [], 'interactions' => []];
    return $tenants->runAs(SBPB_TENANT, static fn(): string => $documents->renderBlock($block, $ctx, (new ThemeResolver())->resolve('default'), new RenderCollector(), false));
}

unit('payment blocks: the button renders a signed form publicly, a card in the editor, and nothing when it cannot work', function (): void {
    $props = ['label' => 'Buy <now>', 'amount' => 25, 'currency' => 'GBP', 'description' => 'A "quoted" thing', 'success_path' => 'thanks'];
    if (class_exists('StripePaymentAPI', false)) {
        StripePaymentAPI::$configured = true;
    }
    \Slate\Module\StudioBuilder\Render\Block\ModuleRenderers\PaymentButtonRenderer::$stripeReadyOverride = true;

    $public = sbpb_render('payment.button', $props, 'public');
    assert_true(str_contains($public, '<form class="sb-pay" method="post" action="https://acme.test/solaya/plugins/studio-builder/public/pay.php">'), 'posts to the endpoint under the site base');
    assert_true(str_contains($public, 'name="a" value="2500"') && str_contains($public, 'name="c" value="GBP"'), 'the terms are hidden fields');
    assert_true(str_contains($public, 'Buy &lt;now&gt;') && !str_contains($public, '<now>'), 'the label is escaped');
    assert_true(!str_contains($public, '"quoted"'), 'the description is escaped');
    $fields = [];
    preg_match_all('/<input type="hidden" name="([a-z])" value="([^"]*)">/', $public, $m, PREG_SET_ORDER);
    foreach ($m as $row) {
        $fields[$row[1]] = html_entity_decode($row[2], ENT_QUOTES);
    }
    assert_true(PaymentOffer::fromPost($fields, sbpb_verifier()) !== null, 'the rendered form verifies at the endpoint');

    $editor = sbpb_render('payment.button', $props, 'editor');
    assert_true(!str_contains($editor, '<form') && !str_contains($editor, '<input'), 'the editor canvas gets no form');
    assert_true(str_contains($editor, '25.00 GBP'), 'the card says what visitors will pay');

    assert_true(!str_contains(sbpb_render('payment.button', ['amount' => 0.1], 'public'), '<form'), 'an amount that cannot be charged renders no form publicly');
    if (class_exists('StripePaymentAPI', false)) {
        StripePaymentAPI::$configured = false;
    }
    \Slate\Module\StudioBuilder\Render\Block\ModuleRenderers\PaymentButtonRenderer::$stripeReadyOverride = false;
    assert_true(!str_contains(sbpb_render('payment.button', $props, 'public'), '<form'), 'Stripe not set up: no form publicly');
    assert_true(str_contains(sbpb_render('payment.button', $props, 'editor'), 'Stripe is not set up'), 'the editor says why');
    if (class_exists('StripePaymentAPI', false)) {
        StripePaymentAPI::$configured = true;
    }
    \Slate\Module\StudioBuilder\Render\Block\ModuleRenderers\PaymentButtonRenderer::$stripeReadyOverride = null;
});

unit('payment blocks: the link block renders only a Stripe address, safely', function (): void {
    $ok = sbpb_render('payment.link', ['label' => 'Donate', 'url' => 'https://buy.stripe.com/test_1?x=1&y=2'], 'public');
    assert_true(str_contains($ok, '<a class="sb-button sb-button--primary" href="https://buy.stripe.com/test_1?x=1&amp;y=2" rel="noopener noreferrer">Donate</a>'), $ok);
    assert_true(!str_contains(sbpb_render('payment.link', ['label' => 'x', 'url' => 'https://evil.test/'], 'public'), '<a '), 'a foreign address renders no link');
    assert_true(!str_contains(sbpb_render('payment.link', ['label' => 'x', 'url' => ''], 'public'), '<a '), 'no address renders no link publicly');
    $editor = sbpb_render('payment.link', ['label' => 'x', 'url' => ''], 'editor');
    assert_true(str_contains($editor, 'Paste a Stripe Payment Link') && !str_contains($editor, '<a '), 'the editor asks for the address');
});

unit('payment blocks: the definitions are in the registry and validate their props', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    assert_true($registry->has('payment.button') && $registry->has('payment.link'));
    $button = $registry->get('payment.button');
    assert_true($button->requiredEntitlement() === null, 'the Stripe plugin is not a licensed module');
    $good = $button->schema()->validate(['label' => 'Pay', 'amount' => 49.99, 'currency' => 'EUR', 'description' => 'x', 'success_path' => 'thank-you']);
    assert_true($good->isValid(), json_encode($good->errors()));
    foreach ([['amount' => 0.1], ['amount' => 5_000_000], ['currency' => 'XXX'], ['success_path' => 'https://evil.test'], ['success_path' => '../x']] as $bad) {
        assert_false($button->schema()->validate($bad + ['label' => 'Pay', 'amount' => 10, 'currency' => 'USD', 'description' => '', 'success_path' => ''])->isValid(), 'refused: ' . json_encode($bad));
    }
    $link = $registry->get('payment.link');
    assert_false($link->schema()->validate(['label' => 'Pay', 'url' => 'https://evil.test/'])->isValid(), 'a foreign address is refused when the document is saved');
    assert_true($link->schema()->validate(['label' => 'Pay', 'url' => 'https://buy.stripe.com/abc'])->isValid());
});
