<?php
/**
 * PluginLoader re-runs every active plugin's boot() after any plugin is switched on or off. A plugin that adds its
 * payment listeners on every boot() then settles each payment two or three times (duplicate emails, audit rows,
 * stock moves). Each paying plugin must register its payment listeners once per process, however often boot() runs.
 */

declare(strict_types=1);

/** How many listeners are registered for an action, across all priorities. */
function plo_count(string $action): int
{
    $prop = new ReflectionProperty(Hook::class, 'actions');
    $all = $prop->getValue();
    $n = 0;
    foreach ($all[$action] ?? [] as $bucket) $n += count($bucket);
    return $n;
}

foreach (['booking' => 'Booking', 'membership' => 'Membership'] as $slug => $class) {
    unit("$slug: payment listeners are registered once, however often boot() runs", function () use ($slug, $class): void {
        $dir = dirname(__DIR__, 2) . '/plugins/' . $slug;
        require_once $dir . '/' . $class . '.php';
        $manifest = json_decode((string) file_get_contents($dir . '/plugin.json'), true) ?: [];
        $plugin = new $class($slug, $manifest, $dir);

        $plugin->boot();
        $after = [plo_count('payment_event'), plo_count('stripe_webhook_event')];
        $plugin->boot();
        $plugin->boot();
        assert_eq($after, [plo_count('payment_event'), plo_count('stripe_webhook_event')], 'a re-boot adds no payment listener');
    });
}
