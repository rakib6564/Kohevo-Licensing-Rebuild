<?php
/**
 * Storefront cart API.
 *
 * One POST endpoint for every cart mutation (add / qty / remove / clear).
 * The menu page calls it with fetch() and re-renders the cart in place from
 * the JSON it returns. The very same forms also work without JavaScript: a
 * non-AJAX hit performs the action and 302s back, so the storefront degrades
 * gracefully.
 */
if (!defined('SLATE_ROOT')) { http_response_code(404); exit; }

$ajax = (stripos((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'fetch') !== false)
     || (stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false);

/** Send JSON (AJAX) or redirect (no-JS), then stop. */
function sf_api_respond(bool $ajax, array $payload, string $redirect): void {
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
    } else {
        header('Location: ' . $redirect);
    }
    exit;
}

/** The fresh cart state every successful mutation returns. */
function sf_cart_state(array $extra = []): array {
    return array_merge([
        'ok'       => true,
        'count'    => sf_cart_count(),
        'subtotal' => sf_money(sf_cart_subtotal()),
        'body'     => sf_cart_body_html(),
    ], $extra);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . sf_url()); exit; }
if (!RestaurantAPI::onlineOrderingEnabled())
    sf_api_respond($ajax, ['ok' => false, 'error' => 'Online ordering is closed right now.'], sf_url());
if (!csrf_verify())
    sf_api_respond($ajax, ['ok' => false, 'error' => 'Your session expired — please refresh the page.'], sf_url());

$action = (string)($_POST['_action'] ?? '');
$cart   = sf_cart();

switch ($action) {

    case 'add':
        $id   = (int)($_POST['item_id'] ?? 0);
        $item = sf_item($id);
        if (!$item)
            sf_api_respond($ajax, ['ok' => false, 'error' => 'That item is unavailable.'], sf_url());
        if (!empty($item['is_86']))
            sf_api_respond($ajax, ['ok' => false, 'error' => $item['name'] . ' is sold out right now.'], sf_url());

        // Validate the chosen modifiers against each group's required / min / max.
        $groups = RestaurantAPI::getItemModifierGroups($id);
        $chosen = array_map('intval', (array)($_POST['modifiers'] ?? []));
        foreach ($groups as $g) {
            $ids    = array_map('intval', array_column($g['modifiers'], 'id'));
            $picked = array_values(array_intersect($chosen, $ids));
            $min = (int)$g['min_select']; $max = (int)$g['max_select'];
            if (!empty($g['is_required']) && count($picked) < max(1, $min))
                sf_api_respond($ajax, ['ok' => false, 'error' => 'Please choose an option for: ' . $g['name']], sf_url());
            if ($max > 0 && count($picked) > $max)
                sf_api_respond($ajax, ['ok' => false, 'error' => 'Choose at most ' . $max . ' for: ' . $g['name']], sf_url());
        }

        // Keep only modifier ids that actually belong to this item's groups.
        $valid = [];
        foreach ($groups as $g) foreach ($g['modifiers'] as $m)
            if (in_array((int)$m['id'], $chosen, true)) $valid[] = (int)$m['id'];

        $qty = max(1, (int)($_POST['qty'] ?? 1));
        $cart[] = ['item_id' => $id, 'qty' => $qty, 'modifiers' => $valid,
                   'notes' => mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255)];
        sf_cart_set($cart);
        sf_api_respond($ajax, sf_cart_state(['added' => $item['name']]),
            sf_url() . '?added=' . rawurlencode($item['name']));
        break;

    case 'qty':
        $i = (int)($_POST['i'] ?? -1);
        if (isset($cart[$i])) {
            $q = (int)($_POST['qty'] ?? 1);
            if ($q < 1) unset($cart[$i]); else $cart[$i]['qty'] = $q;
            sf_cart_set($cart);
        }
        sf_api_respond($ajax, sf_cart_state(), sf_url('cart'));
        break;

    case 'remove':
        $i = (int)($_POST['i'] ?? -1);
        if (isset($cart[$i])) { unset($cart[$i]); sf_cart_set($cart); }
        sf_api_respond($ajax, sf_cart_state(), sf_url('cart'));
        break;

    case 'clear':
        sf_cart_clear();
        sf_api_respond($ajax, sf_cart_state(), sf_url('cart'));
        break;

    default:
        sf_api_respond($ajax, ['ok' => false, 'error' => 'Unknown action.'], sf_url());
}
