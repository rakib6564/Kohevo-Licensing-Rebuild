<?php
/**
 * PayPal Payment Gateway — bootstrap.
 *
 * Registers PayPal with the shared payment layer (Payments). Modules never
 * call PayPal themselves: they offer whatever gateways Payments::available()
 * returns, and react to `payment_event`. This plugin owns only what is
 * PayPal's: credentials, the return page that captures an approved order,
 * and the signed webhook.
 */

class PaypalPayment extends Plugin {

    public function boot(): void {
        require_once $this->dir('PayPalPaymentAPI.php');

        Hook::addFilter('admin_nav_items', [$this, 'addAdminNav']);
        Hook::addFilter('i18n_lang_paths', [$this, 'addLangPath']);
    }

    public function addAdminNav(array $items): array {
        if (!Auth::can('paypal.manage_settings') && !Auth::isSuperAdmin()) return $items;
        // Next to Stripe: under SHOP when Shop is active, otherwise SYSTEM.
        $shopActive = PluginLoader::isActive('shop');
        $items[] = [
            'slug'  => 'paypal-settings',
            'label' => __('paypal_nav_settings', 'PayPal'),
            'href'  => $this->url('admin/settings.php'),
            'icon'  => 'credit-card',
            'perm'  => 'paypal.manage_settings',
            'order' => $shopActive ? 520 : 720,
            'group' => $shopActive ? 'shop' : 'system',
        ];
        return $items;
    }

    public function addLangPath(array $paths): array {
        foreach (['fr', 'en'] as $loc) {
            $paths[$loc][] = $this->dir('lang');
        }
        return $paths;
    }
}
