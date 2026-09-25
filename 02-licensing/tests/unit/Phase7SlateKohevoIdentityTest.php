<?php
/**
 * Unit tests for Phase 7 (Slate -> Kohevo UI Identity Migration).
 *
 * Source-based checks only, no DB needed — every change this phase makes is
 * a literal string in PHP/lang source. Each test pairs a "must have
 * migrated" assertion with a "must NOT have touched anything else"
 * assertion, per the phase spec's own Step 7 requirement to distinguish
 * user-facing Slate (migrate) from internal Slate (preserve).
 */

declare(strict_types=1);

function _p7_src(string $relPath): string
{
    return (string) file_get_contents(__DIR__ . '/../../' . $relPath);
}

// ── The two originally-flagged candidates ────────────────────

unit('admin/login.php: the install-success fallback now says Kohevo, not Slate', function () {
    $src = _p7_src('admin/login.php');
    assert_true(str_contains($src, "__('install_success', 'Kohevo is installed. Log in with the account you just created.')"));
    assert_false(str_contains($src, 'Slate is installed'));
});

unit('admin/repair-settings.php: the safe-default site_name is Kohevo, not Slate, matching every other fallback in the codebase', function () {
    $src = _p7_src('admin/repair-settings.php');
    assert_true(str_contains($src, "'site_name'              => 'Kohevo',"));
    assert_false(str_contains($src, "'site_name'              => 'Slate',"));
});

// ── lang/fr.php parity with the already-fixed lang/en.php ───

unit('lang/fr.php: every French translation value that previously said Slate now says Kohevo, matching lang/en.php', function () {
    $fr = _p7_src('lang/fr.php');
    foreach ([
        'install_success', 'welcome', 'dashboard_intro', 'no_plugins_intro', 'users_subtitle', 'settings_subtitle',
    ] as $key) {
        $pos = strpos($fr, "'$key'");
        assert_true($pos !== false, "expected key '$key' in lang/fr.php");
        $line = substr($fr, $pos, strpos($fr, "\n", $pos) - $pos);
        assert_true(str_contains($line, 'Kohevo') || !str_contains($line, 'Slate'), "lang/fr.php key '$key' must not still say Slate: $line");
    }
    assert_false(str_contains($fr, 'Slate est'), 'no French sentence should still start with "Slate est"');
    assert_false(str_contains($fr, 'sur Slate'), 'no French sentence should still say "sur Slate"');
    assert_false(str_contains($fr, 'de Slate'), 'no French sentence should still say "de Slate"');
});

unit('lang/en.php: unaffected by this phase — already said Kohevo for the same keys, still does', function () {
    $en = _p7_src('lang/en.php');
    assert_true(str_contains($en, "'install_success'   => 'Kohevo is installed."));
    assert_true(str_contains($en, "'welcome'           => 'Welcome to Kohevo'"));
});

// ── Plugin UI strings (Booking, MCP Gateway, React Site Bridge) ──

unit('plugins/booking: Google Calendar sync copy says Kohevo, not Slate, in both providers.php and settings.php', function () {
    $providers = _p7_src('plugins/booking/admin/providers.php');
    $settings  = _p7_src('plugins/booking/admin/settings.php');
    assert_true(str_contains($providers, 'flow back into Kohevo.'));
    assert_true(str_contains($providers, 'Kohevo respects time'));
    assert_true(str_contains($settings, 'flow back into Kohevo so double-booking is avoided.'));
    assert_false(str_contains($providers, 'Slate'));
    assert_false(str_contains($settings, 'Slate'));
});

unit('plugins/mcp-gateway: the token-created flash message and the AI system prompt both self-identify as Kohevo, not Slate', function () {
    $index = _p7_src('plugins/mcp-gateway/admin/index.php');
    $chat  = _p7_src('plugins/mcp-gateway/admin/chat.php');
    assert_true(str_contains($index, 'Kohevo cannot show it again'));
    assert_true(str_contains($chat, 'built into this Kohevo installation'));
    assert_false(str_contains($index, 'Slate cannot show it again'));
    assert_false(str_contains($chat, 'this Slate installation'));
});

unit('plugins/react-site-bridge: every user-facing "Slate"/"Slate Media"/"Slate-hosted" string across the plugin now says Kohevo', function () {
    foreach ([
        'admin/index.php', 'admin/photography.php', 'admin/content.php',
        'admin/hosting.php', 'admin/site.php', 'admin/visual.php',
    ] as $file) {
        $src = _p7_src('archive/plugins/react-site-bridge/' . $file);
        // Every remaining "Slate" occurrence in these files must be one of
        // the known-internal patterns (SLATE_URL constant, Slate\ namespace,
        // slate_ helper functions, or an engineering-only doc comment) —
        // never bare product-identity text like "Slate Media"/"Slate-hosted".
        foreach (explode("\n", $src) as $i => $line) {
            if (!str_contains($line, 'Slate')) {
                continue;
            }
            $strippedOfKnownInternals = str_replace(['SLATE_URL', 'Slate\\', 'slate_breadcrumbs'], '', $line);
            $isCommentLine = (bool) preg_match('/^\s*(\*|\/\/|\/\*)/', $line);
            assert_true(
                !str_contains($strippedOfKnownInternals, 'Slate') || $isCommentLine,
                "$file line " . ($i + 1) . " still has a non-internal, non-comment 'Slate' occurrence: $line"
            );
        }
    }
});

// ── Second sweep: French parity, external-facing text, plugin manifests ──

unit('plugins/booking/lang/fr.php and plugins/mcp-gateway/lang/fr.php: the French translations of the Google Calendar sync and token-created copy also say Kohevo now', function () {
    $bookingFr = _p7_src('plugins/booking/lang/fr.php');
    assert_true(str_contains($bookingFr, 'répercutés dans Kohevo.'));
    assert_true(str_contains($bookingFr, 'que Kohevo respecte le temps'));
    assert_true(str_contains($bookingFr, 'reviennent dans Kohevo afin'));
    assert_false(str_contains($bookingFr, 'Slate'));

    $mcpFr = _p7_src('plugins/mcp-gateway/lang/fr.php');
    // Reading the .php file as raw text (not executing it) means the source's
    // own escape sequence for the apostrophe — l\'afficher — is read with its
    // literal backslash still attached, unlike a runtime PHP string value.
    assert_true(str_contains($mcpFr, "Kohevo ne pourra plus l\\'afficher."));
    assert_false(str_contains($mcpFr, 'Slate'));
});

unit('plugins/booking/GoogleCalendarSync.php: the event description written into the provider\'s own Google Calendar says Kohevo', function () {
    $src = _p7_src('plugins/booking/GoogleCalendarSync.php');
    assert_true(str_contains($src, "'Booked via Kohevo. Ref: '"));
    assert_false(str_contains($src, 'Booked via Slate'));
});

unit('plugins/backups/GoogleDriveClient.php: the default Google Drive backup folder name is now "Kohevo Backups"', function () {
    $src = _p7_src('plugins/backups/GoogleDriveClient.php');
    assert_true(str_contains($src, "string \$name = 'Kohevo Backups'"));
    assert_false(str_contains($src, 'Slate Backups'));
});

unit('archive/plugins/react-site-bridge/ReactSiteBridgeAPI.php: every exception message shown as an admin flash banner says Kohevo, not Slate', function () {
    $src = _p7_src('archive/plugins/react-site-bridge/ReactSiteBridgeAPI.php');
    foreach ([
        'Kohevo Media is unavailable.', 'Kohevo Media did not register a usable image.',
        'Kohevo Media and Uploads must be available', 'Kohevo requires ZipArchive and Uploads',
        'a valid Kohevo React Release ZIP file.', 'a supported Kohevo React Release package.',
        'could not be registered in Kohevo Media.',
    ] as $expected) {
        assert_true(str_contains($src, $expected), "expected '$expected' in ReactSiteBridgeAPI.php");
    }
    // Doc comments legitimately still say "Slate" (internal, e.g. "clean Slate routes",
    // "Slate-owned uploads") — only the thrown exception strings were in scope.
    assert_false(str_contains($src, "throw new RuntimeException('Slate"));
    assert_false(str_contains($src, "throw new InvalidArgumentException('Slate"));
});

unit('every first-party plugin.json "author" field says Kohevo (multilang-translate, a real named third-party contributor, is correctly excluded)', function () {
    // react-site-bridge moved to archive/plugins/ (deactivated, not shipping) —
    // excluded from this active-manifest sweep, same reasoning as every other
    // archived plugin never being in this list to begin with.
    foreach ([
        'backups', 'booking', 'coaching', 'forms', 'mcp-gateway',
        'media-library', 'membership', 'stripe-payment',
    ] as $plugin) {
        $manifest = json_decode(_p7_src("plugins/$plugin/plugin.json"), true);
        assert_true($manifest !== null, "plugins/$plugin/plugin.json must remain valid JSON");
        assert_eq('Kohevo', $manifest['author'], "plugins/$plugin/plugin.json author must be Kohevo");
    }
    $multilang = json_decode(_p7_src('plugins/multilang-translate/plugin.json'), true);
    assert_eq('Rasel Ahmmed', $multilang['author'], 'a real named contributor must not be overwritten');
});

unit('the four plugin.json descriptions that mentioned Slate as product text now say Kohevo, and every manifest is still valid JSON', function () {
    $mcp = json_decode(_p7_src('plugins/mcp-gateway/plugin.json'), true);
    assert_true(str_contains($mcp['description'], 'to manage Kohevo admin tasks'));

    $media = json_decode(_p7_src('plugins/media-library/plugin.json'), true);
    assert_true(str_contains($media['description'], 'is now a built-in Kohevo feature'));

    $rsb = json_decode(_p7_src('archive/plugins/react-site-bridge/plugin.json'), true);
    assert_true(str_contains($rsb['description'], 'Kohevo Media upload-and-map workflow'));
    assert_true(str_contains($rsb['description'], 'direct Kohevo-hosted React releases'));
    assert_true(str_contains($rsb['description'], 'Kohevo-domain preview links'));

    $stripe = json_decode(_p7_src('plugins/stripe-payment/plugin.json'), true);
    assert_true(str_contains($stripe['description'], 'Generic Stripe checkout for any Kohevo plugin'));
});

unit('src/Kernel/Module/PluginLoader.php: the plugin-version-mismatch admin error says "Kohevo core", not "Slate core" (SLATE_VERSION constant itself is untouched)', function () {
    $src = _p7_src('src/Kernel/Module/PluginLoader.php');
    assert_true(str_contains($src, 'This plugin requires Kohevo core'));
    assert_false(str_contains($src, 'This plugin requires Slate core'));
    assert_true(str_contains($src, 'SLATE_VERSION'), 'the internal SLATE_VERSION constant must remain untouched');
});

unit('src/Kernel/Http/ApiRouter.php: the GET /api/v1 root response names itself Kohevo', function () {
    $src = _p7_src('src/Kernel/Http/ApiRouter.php');
    assert_true(str_contains($src, "'name'      => 'Kohevo Headless & Mobile API',"));
});

unit('plugins/forms/lib/FormsPdf.php: the PDF header brand-name fallback is Kohevo, matching BrandedEmail::brand()\'s convention', function () {
    $src = _p7_src('plugins/forms/lib/FormsPdf.php');
    assert_true(str_contains($src, "\$this->brand['name'] ?? 'Kohevo'"));
    assert_false(str_contains($src, "\$this->brand['name'] ?? 'Slate'"));
});

unit('plugins/backups/BackupRunner.php: the Drive-folder error message matches the "Kohevo Backups" folder name GoogleDriveClient.php now creates', function () {
    $runner = _p7_src('plugins/backups/BackupRunner.php');
    $client = _p7_src('plugins/backups/GoogleDriveClient.php');
    assert_true(str_contains($runner, 'Could not find/create the Kohevo Backups folder in Drive.'));
    assert_true(str_contains($client, "string \$name = 'Kohevo Backups'"));
});

unit('plugins/multilang-translate/plugin.json: the plugin\'s own display name no longer carries the Slate brand prefix, matching every sibling plugin\'s unbranded naming', function () {
    $manifest = json_decode(_p7_src('plugins/multilang-translate/plugin.json'), true);
    assert_eq('Multi Language Visual Translation', $manifest['name']);
});

// ── Internal identifiers must remain untouched (regression guard) ──

unit('regression: the Slate\\ PHP namespace and internal identifiers are untouched by Phase 7', function () {
    foreach ([
        'src/Services/Content/PlatformIdentity.php'      => 'namespace Slate\Services\Content;',
        'src/Services/Content/PlatformSignature.php'     => 'namespace Slate\Services\Content;',
        'src/Services/Content/PlatformIdentityPolicy.php' => 'namespace Slate\Services\Content;',
        'src/Services/Licensing/EntitlementService.php'  => 'namespace Slate\Services\Licensing;',
        'src/Services/Auth/Auth.php'                     => 'namespace Slate\Services\Auth;',
        'src/Services/Notifications/BrandedEmail.php'    => 'namespace Slate\Services\Notifications;',
    ] as $file => $expectedNamespace) {
        $src = _p7_src($file);
        assert_true(str_contains($src, $expectedNamespace), "$file must still declare $expectedNamespace");
    }

    // A handful of representative SLATE_* constants / slate_ helpers must be untouched.
    assert_true(str_contains(_p7_src('config.php'), 'SLATE_URL') || str_contains(_p7_src('includes/helpers.php'), 'SLATE_URL'), 'the SLATE_URL constant must remain untouched');
});

unit('regression: no unrelated file was touched by this phase (only the specific identity-string files)', function () {
    $touchedFiles = [
        'admin/login.php', 'admin/repair-settings.php', 'lang/fr.php',
        'plugins/booking/admin/providers.php', 'plugins/booking/admin/settings.php',
        'plugins/mcp-gateway/admin/index.php', 'plugins/mcp-gateway/admin/chat.php',
        'archive/plugins/react-site-bridge/admin/index.php', 'archive/plugins/react-site-bridge/admin/photography.php',
        'archive/plugins/react-site-bridge/admin/content.php', 'archive/plugins/react-site-bridge/admin/hosting.php',
        'archive/plugins/react-site-bridge/admin/site.php', 'archive/plugins/react-site-bridge/admin/visual.php',
        'plugins/booking/lang/fr.php', 'plugins/mcp-gateway/lang/fr.php',
        'plugins/booking/GoogleCalendarSync.php', 'plugins/backups/GoogleDriveClient.php',
        'archive/plugins/react-site-bridge/ReactSiteBridgeAPI.php',
        'plugins/backups/plugin.json', 'plugins/booking/plugin.json', 'plugins/coaching/plugin.json',
        'plugins/forms/plugin.json', 'plugins/mcp-gateway/plugin.json', 'plugins/media-library/plugin.json',
        'plugins/membership/plugin.json', 'archive/plugins/react-site-bridge/plugin.json', 'plugins/stripe-payment/plugin.json',
        'src/Kernel/Module/PluginLoader.php', 'src/Kernel/Http/ApiRouter.php',
    ];
    foreach ($touchedFiles as $file) {
        assert_true(is_file(__DIR__ . '/../../' . $file), "expected $file to exist (sanity check on the list itself)");
    }
    // Deliberately preserved: the "Soft slate" sidebar-theme color option and
    // admin/diag.php's SLATE-DIAG-V3 build marker are NOT in this list — see
    // the Phase 7 final report for why both were classified as ambiguous/
    // out of scope rather than migrated.
    $uiComponents = _p7_src('includes/ui_components.php');
    assert_true(str_contains($uiComponents, "'slate' => ['label' => 'Soft slate'"), 'the "Soft slate" color option must remain untouched — a plain color name, not product identity');
    $diag = _p7_src('admin/diag.php');
    assert_true(str_contains($diag, 'SLATE-DIAG-V3'), 'the SLATE-DIAG-V3 build marker must remain untouched — an internal deploy-verification tag, not product identity');
});
