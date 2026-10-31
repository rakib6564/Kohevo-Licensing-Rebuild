<?php
/**
 * Unit tests for the Forms integrations: the Brevo requests, what a submission sends, and the retry rules. No network or database.
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
require_once SLATE_ROOT . '/plugins/forms/lib/integrations/BrevoConnector.php';
require_once SLATE_ROOT . '/plugins/forms/lib/FormsIntegrations.php';

/** A Brevo that records calls and answers with `$status`. */
function fi_brevo(int $status, string $body, array &$calls): BrevoConnector
{
    return new BrevoConnector(static function (string $method, string $url, string $key, ?string $payload) use ($status, $body, &$calls): array {
        $calls[] = [$method, $url, $key, json_decode((string) $payload, true)];
        return [$status, $body];
    });
}

unit('brevo: a plain signup updates the contact and joins the list, and only goes to Brevo', function (): void {
    $calls = [];
    fi_brevo(201, '{"id":1}', $calls)->subscribe('k', 7, 'a@b.test', ['FIRSTNAME' => 'Ana', 'LASTNAME' => ''], []);
    assert_eq(['POST', 'https://api.brevo.com/v3/contacts', 'k'], array_slice($calls[0], 0, 3));
    assert_eq(['email' => 'a@b.test', 'listIds' => [7], 'updateEnabled' => true, 'attributes' => ['FIRSTNAME' => 'Ana']], $calls[0][3], 'an empty attribute is left out');
});

unit('brevo: double opt-in uses its own request with the template and the confirmation page', function (): void {
    $calls = [];
    fi_brevo(201, '', $calls)->subscribe('k', 7, 'a@b.test', [], ['template_id' => 12, 'redirect_url' => 'https://s.test/merci']);
    assert_eq('https://api.brevo.com/v3/contacts/doubleOptinConfirmation', $calls[0][1]);
    assert_eq(['email' => 'a@b.test', 'includeListIds' => [7], 'templateId' => 12, 'redirectionUrl' => 'https://s.test/merci'], $calls[0][3]);
});

unit('brevo: lists are read, and a refusal says whether a retry can work', function (): void {
    $calls = [];
    assert_eq([['id' => 3, 'name' => 'News']], fi_brevo(200, '{"lists":[{"id":3,"name":"News"}]}', $calls)->lists('k'));
    foreach ([[400, false], [401, false], [425, true], [429, true], [500, true], [0, true]] as [$status, $retry]) {
        try {
            fi_brevo($status, '{"message":"no"}', $calls)->lists('k');
            assert_true(false, 'must throw');
        } catch (IntegrationException $e) {
            assert_eq($retry, $e->retry, "status {$status}");
        }
    }
});

unit('integrations: a signup sends only a valid address, split name and given consent', function (): void {
    $rule = ['email_field' => 'email', 'name_field' => 'nom', 'consent_field' => 'ok', 'doi_template_id' => 0];
    $sent = FormsIntegrations::contactFrom($rule, ['email' => ' a@b.test ', 'nom' => 'Ana de Souza', 'ok' => 'on']);
    assert_eq('a@b.test', $sent['email']);
    assert_eq(['FIRSTNAME' => 'Ana', 'LASTNAME' => 'de Souza'], $sent['attributes']);
    assert_eq([], $sent['double_optin']);
    assert_eq(null, FormsIntegrations::contactFrom($rule, ['email' => 'a@b.test']), 'no consent, nothing sent');
    assert_eq(null, FormsIntegrations::contactFrom($rule, ['email' => 'nope', 'ok' => 'on']));
    unset($rule['consent_field']);
    assert_true(FormsIntegrations::contactFrom($rule, ['email' => 'a@b.test']) !== null, 'a rule with no consent box sends');
});

unit('integrations: double opt-in comes from the rule, and retries wait longer each time', function (): void {
    $sent = FormsIntegrations::contactFrom(['email_field' => 'e', 'doi_template_id' => 4, 'doi_redirect_url' => 'https://s.test/'], ['e' => 'a@b.test']);
    assert_eq(['template_id' => 4, 'redirect_url' => 'https://s.test/'], $sent['double_optin']);
    assert_eq([5, 10, 20, 40], array_map([FormsIntegrations::class, 'delayAfter'], [1, 2, 3, 4]));
});
