<?php
/**
 * Unit tests for prices in the page's number style: I18n::amount() and the formatters built on it.
 *
 * Autoloader only (no database): English stays "1,234.50", French writes "1 234,50" with a non-breaking space,
 * an unlisted language falls back to English, and the machine-readable amounts in a pay form stay plain numbers.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Payment\PaymentOffer;
use Slate\Module\StudioBuilder\Render\Block\ModuleRenderers\CatalogFormat;
use Slate\Services\I18n\I18n;

function lat_in(string $locale, callable $run): mixed
{
    $previous = I18n::pinLocale($locale);
    try {
        return $run();
    } finally {
        I18n::pinLocale($previous);
    }
}

unit('local amount: each language writes the number its own way', function (): void {
    assert_eq('1,234.50', lat_in('en', static fn() => I18n::amount(123450)), 'English');
    assert_eq("1\u{00A0}234,50", lat_in('fr', static fn() => I18n::amount(123450)), 'French');
    assert_eq("1\u{00A0}234,50", lat_in('fr-be', static fn() => I18n::amount(123450)), 'a regional code follows its language');
    assert_eq('1.234,50', lat_in('de', static fn() => I18n::amount(123450)), 'German');
    assert_eq('1,234.50', lat_in('ja', static fn() => I18n::amount(123450)), 'an unlisted language writes it the English way');
    assert_eq('0,50', lat_in('fr', static fn() => I18n::amount(50)), 'cents below one unit');
});

unit('local amount: the formatters follow the language', function (): void {
    assert_eq('49.00 USD', lat_in('en', static fn() => PaymentOffer::display(4900, 'USD')));
    assert_eq('49,00 EUR', lat_in('fr', static fn() => PaymentOffer::display(4900, 'EUR')));
    assert_eq('10.00 EUR', lat_in('en', static fn() => CatalogFormat::price(1000, 'eur')));
    assert_eq('10,00 EUR', lat_in('fr', static fn() => CatalogFormat::price(1000, 'eur')));
});

unit('local amount: no price formatter keeps its own number style', function (): void {
    $root = dirname(__DIR__, 2);
    foreach (['src/Module/StudioBuilder/Payment/PaymentOffer.php', 'src/Module/StudioBuilder/Render/Block/ModuleRenderers/CatalogFormat.php'] as $file) {
        assert_true(!str_contains((string) file_get_contents($root . '/' . $file), 'number_format'), $file);
    }
    assert_eq(2, substr_count((string) file_get_contents($root . '/includes/helpers.php'), 'I18n::amount($cents)'), 'both global price formatters');
});
