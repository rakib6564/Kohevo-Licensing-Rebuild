# Plugin protection (client admin → Plugins)

Deactivating or uninstalling a plugin can take features — and, for uninstall, **data** — away from a live business, and
the buttons used to sit one click apart. On the client application these actions are therefore **locked by default**
and enforced on the server (a forged request is refused, not just a hidden button).

| Action | Locked by default? | Notes |
|---|---|---|
| Activate a plugin | No | Only adds things; licensed modules are still license-checked |
| Deactivate a plugin | **Yes** | Also asks "Deactivate X?" |
| Uninstall a plugin | **Yes** | Deletes the plugin's data. Also requires **typing the plugin's name** (checked on the server) and the plugin must already be inactive |
| Upload a plugin ZIP | **Yes** | The upload box is hidden until unlocked |
| Capabilities, Download ZIP | No | Not destructive |

System plugins (e.g. the media library) can never be deactivated or uninstalled, locked or not.

## Unlocking

1. *Admin → Plugins* → **Unlock changes**.
2. Re-enter **your own password**. It unlocks for **15 minutes** for that admin only, then re-locks by itself; **Lock now**
   re-locks immediately.
3. Five wrong passwords start a 5-minute cooldown.

Everything is written to the audit log: `plugin.protection_unlocked`, `plugin.protection_unlock_failed`,
`plugin.protection_locked`, and `plugin.change_blocked` for refused attempts.

## Where it lives

- `includes/plugin_protection.php` — the lock rules (`slate_plugins_locked()`, `slate_plugins_unlocked_until()`) and the
  per-plugin icons.
- `admin/plugins.php` — enforcement (the lock is checked right after CSRF and before any handler), the unlock / lock
  actions, and the UI.
- Tests: `tests/unit/PluginProtectionTest.php`.

The central licensing server's own plugin page is unchanged.
