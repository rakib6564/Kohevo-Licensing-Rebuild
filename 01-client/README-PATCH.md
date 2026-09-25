# Slate core patch — accompanies shop-v1.2.5.zip + stripe-payment-v1.0.0.zip

Five core files. Drop-in replacements; no DB changes; no core version bump.

This patch is unchanged from the previous build — it carries the
1.2.2/1.2.3/1.2.4 design-system primitives, overflow defenses, and
the manifest auto-refresh + Uploads MIME fix.

If you applied the previous slate-core-patch already, you do NOT
need to apply this one again; the Stripe and Shop plugins both work
against the existing core patch.

## Files

    includes/ui_components.php   — design primitives + overflow defenses
    includes/PluginLoader.php    — manifest auto-refresh
    includes/Uploads.php         — accepts allowed_mimes + allowed_mime
    admin/settings.php           — uses documented allowed_mimes
    admin/partials/header.php    — main.content min-width:0
