# Slate Multi Language Visual Translation — build tracker

Say "continue" to resume from the next unchecked item.

## Phase 1 — Core engine + grid (DONE)
- [x] plugin.json, install.sql/uninstall.sql (multilangtranslate_languages, multilangtranslate_strings, multilangtranslate_translations)
- [x] Tokenizer: tag-aware text+attribute segment walker (safe in-place replace, no DOM re-parse)
- [x] Harvester: text extraction → multilangtranslate_strings (admin+customer+public)
- [x] Replacer: output-buffer swap of published translations for active locale
- [x] Crawler: internal loopback "Scan" that visits every admin/customer/public nav URL
- [x] LangRepo / StringRepo: languages CRUD, grid query, report counts, find&replace, CSV import/export
- [x] Admin grid page: English (fixed source col) + dynamic language columns, add/enable/disable/remove language,
      report bar (total / published / draft / blank / not-translated per language), search bar, filter (untranslated),
      save (draft), publish, find & replace modal, import modal (with optional single-column target), export modal
      (column picker)
- [x] Language switcher widget (dropdown/flags/list style) + customize panel (position, colors) rendered
      site-wide via site_footer/admin_footer/customer_footer hooks + auto-inject fallback
- [x] i18n_supported_languages integration so core I18n knows our enabled languages

## v1.3.0 — switcher shows "FR" but text stays English: not a bug, a coverage gap — now fixed
- [x] **Verified the mechanism itself first, with a real test, before assuming anything was broken.** Ran
      `MLT_Replacer::replace()` through the harness twice, as two separate "requests": once with nothing
      published for a locale (page correctly stays unchanged) and once with real published French translations
      seeded (page correctly swaps to French). Confirmed `activeLocale()` → `publishedDict()` → `Tokenizer::walk()`
      → in-place swap all agree on normalization and all work correctly end to end. The mechanism was never the
      problem.
- [x] **Actual root cause:** `MLT_Crawler::discoverUrls()` can only find pages that are registered nav-menu
      items, plus the homepage/admin/customer dashboards. It has no way to discover a page that isn't in any
      menu (e.g. a registration flow reached via a direct link) or a page on a *completely different site or
      domain* built under the same account. Both screenshots were exactly that: `customer/register.php` isn't a
      nav item, and the second site is a different domain entirely — so Scan never visited either, meaning the
      English text was never harvested in the first place. Nothing to translate, so nothing changes when you
      switch language — 100% expected given zero data, not a replacement bug.
- [x] **Fix:** added a "🔗 Scan URLs" button next to Scan site — paste any full URL, one per line (same site or
      a totally different domain), Save, then click Scan site and those pages get fetched and harvested exactly
      like a discovered one. Verified via harness: a same-host URL (`register.php`) correctly buckets into the
      `customer` area and receives the session cookie for auth; cross-domain URLs correctly bucket into `public`
      and get the cookie **withheld** — a manually-added third-party URL is now fetched anonymously, the way a
      real visitor would see it, and never receives your admin session cookie.
- [x] Small correctness fix found along the way: `MLT_Replacer`'s static per-request dictionary cache was keyed
      only by locale, not by locale *and* tenant. Never actually reachable under this platform's per-request PHP
      model (confirmed via `config.php` — no persistent worker state between requests), but cheap, harmless
      insurance to fix properly rather than leave as a latent trap for some future execution model.
- [x] Full regression suite re-run before packaging: the v1.1.1 site-blanking crash repro, the 1-language
      early-return path, the v1.2.0 export/import round trip, and the new v1.3.0 replace-mechanism +
      discoverUrls tests — all clean.


- [x] **Root cause:** the switcher's CSS/JS were only ever loaded via `enqueueStyle()`/`enqueueScript()`, which
      just queue the files — actually outputting a `<link>`/`<script>` tag depends entirely on the current page
      template calling `PluginLoader::renderQueuedStyles()`/`renderQueuedScripts()`. Confirmed `customer/login.php`
      (the page in the screenshot) is a fully standalone template that calls neither of those nor any of our
      `site_footer`/`customer_footer` hooks — so on that page (and any other page shaped like it) the widget's
      HTML got auto-injected by our own fallback, but its CSS and JS never loaded at all: unstyled block-level
      markup dumped at the bottom of the page, dropdown always expanded (no CSS to hide it), toggle button
      inert (no JS attached). Exactly the reported symptom.
- [x] **Fix:** `switcherHtml()` now bundles the widget's CSS and JS inline, directly alongside its HTML
      (`<style>…</style>` + markup + `<script>…</script>`), instead of depending on the page template to load
      separate files. This makes the widget fully self-contained — it now renders and works correctly on
      literally any page, regardless of what that page's template does or doesn't call.
- [x] Removed the `enqueueStyle('switcher.css')`/`enqueueScript('switcher.js')` calls from `boot()` — keeping
      them alongside the new inline bundling would have caused `switcher.js` to load *twice* on any page that
      *does* properly support queued assets (e.g. the admin area, `customer/partials/header.php`), which would
      double-attach its click handler and make the toggle silently no-op (open then instantly re-close).
      `switcher-render.php`'s existing `mlt_switch_url()` function-exists guard already covered the
      redeclare-function half of this; removing the enqueue calls covers the duplicate-listener half.
- [x] Verified via harness: re-ran the full request-boot simulation and confirmed the returned page HTML now
      contains one `<style id="mlt-switcher-inline-css">`, one `.mlt-switcher` widget, and one
      `<script id="mlt-switcher-inline-js">` block, all inline — then re-ran every earlier regression test
      (the v1.1.1 site-blanking crash repro, the 1-language early-return path, and the v1.2.0 export/import
      round trip) to confirm none of them broke.


- [x] Export CSV now always includes an `id` column (in addition to `source_text`), and always includes every
      row — that combination is what makes a plain export usable as a "send to an AI to translate" file.
- [x] Import now matches rows by `id` first when the column is present and resolves, falling back to the old
      `source_text` hash match otherwise. This is the reliability fix: an AI tool round-tripping a CSV will
      often lightly reflow whitespace in columns it wasn't even asked to touch, which silently broke hash-based
      matching before. Proved this with a harness test — a row whose `source_text` came back as
      `"  Contact  us  "` (extra internal whitespace) still matched and imported correctly via `id`, where the
      old hash-only path would have skipped it.
- [x] Import no longer demotes an already-`published` cell back to `draft` when the incoming value is byte-identical
      to what's already stored — an AI-returned file often round-trips columns (like one you'd already published)
      that it didn't need to change. Verified via the same harness test: French stayed `published` after a
      re-import where the French column round-tripped unchanged; only the genuinely new Spanish cells came in
      as `draft`, as expected.
- [x] Export modal: added a free-text field to include a blank column for a language you haven't added to the
      plugin yet — no need to click Add Language first just to generate a translation file for it. Once the
      filled-in file comes back and is imported, clicking Add Language with that same code immediately surfaces
      the imported translations (confirmed: `LangRepo::add()` only inserts a language row, never touches
      `multilangtranslate_translations`, and the grid pulls translations by `string_id` regardless of when they
      were saved — so import-before-add is a safe order).
- [x] Export modal: added a "Copy AI prompt" button that copies ready-to-paste instructions (preserve id/
      source_text, preserve placeholders/HTML, fill only blank target cells, return same CSV shape) alongside
      the exported file — so handing a file to an AI tool is copy-paste-attach, not a made-up prompt each time.
- [x] `admin/api.php`'s `export` action now sanitizes the `locales` query param to ISO-code-shaped strings only
      (it accepts freeform typed input now, via the new field above, so it can no longer be trusted uncritically
      the way it could when the only source was a fixed checkbox list).


- [x] **Root cause found and fixed.** `autoInjectSwitcher()` called `ob_start()`/`ob_get_clean()` to
      capture the switcher widget — but it runs *inside* `obCallback()`, which is itself the display-handler
      callback of the plugin's own page-wide `ob_start()` registered in `boot()`. PHP hard-forbids starting a
      second output buffer from inside another buffer's display handler ("Cannot use output buffering in
      output buffering display handlers") — that combination is a fatal error, not a warning. Since
      `display_errors` is off in production (correctly, for security), the fatal produced a blank white page
      instead of a visible error.
      Confirmed this fires on *every* full HTML page, not just admin: grepped Slate core and the theme for the
      `site_footer` / `admin_footer` / `customer_footer` action names this plugin relies on to know when the
      switcher was already rendered inline — **core never calls any of them**, so the "already rendered via a
      real hook" short-circuit never engages, and every single request fell through to the crashing code path.
      Reproduced the exact crash in an isolated harness (`ob_start(): Cannot use output buffering in output
      buffering display handlers`), applied the fix, then re-ran the identical reproduction to confirm a clean
      page render with the switcher correctly injected before `</body>`.
      **Fix:** `assets/switcher-render.php` no longer echoes — it builds the widget markup as a string and
      `return`s it (files loaded via `require` can return values). `renderSwitcher()` is now a thin
      `echo $this->switcherHtml()` wrapper for the (currently theoretical, since core never fires them) real
      hook path; `switcherHtml(): string` does the actual work and is what `autoInjectSwitcher()` calls
      directly — no nested buffer anywhere in the chain anymore. Also hardened `mlt_switch_url()`'s declaration
      with a `function_exists()` guard as defense-in-depth against any future double-require.
- [x] Verified with `php -l` across every file, then a from-scratch process re-run of the crash reproduction
      (1-language early-return path, 3-language full-render path) — both come back clean with no fatal and
      correct switcher markup.


- [x] Fixed: `Auth::can()` gate hid the "Translations" nav item for any role that hadn't been granted `mlt.view`
      on Admin → Roles (plugin activation never auto-grants manifest permissions — that's by design in
      PluginLoader::activate(); Super Admin is exempt via the isSuperAdmin() bypass, everyone else needs the
      checkbox ticked once). Not a code bug — documented here so it doesn't get "fixed" again by accident.
- [x] Harvesting switched from passive-per-request to **on-demand only**: MultilangTranslate::obCallback() no
      longer calls MLT_Harvester::harvest() on every visitor page view. The only way strings enter the grid now
      is clicking "Scan site" (MLT_Crawler → MLT_Harvester). Live-translation swapping (MLT_Replacer) and the
      switcher widget are untouched — those still run every request, that's a different feature from scanning.
- [x] Grid now shows **every added language as a column**, enabled or not (MLT_LangRepo::targets() dropped its
      `enabled = 1` filter). "Enabled" now purely controls whether that language appears on the live front-of-site
      switcher — disabling a language no longer hides its column or its saved translations from the admin grid.
      Fixed a latent bug this exposed: the column header's enable checkbox was hardcoded `checked` (harmless
      before, since only enabled rows were ever queried) — now reflects the real per-language `enabled` state.
- [x] Report bar per language now shows total / blank / not-translated (draft+blank) / published, not just a
      single "translated" number — `StringRepo::report()` splits draft vs. published so you can see what's
      written but not yet live, separately from what's untouched.
- [x] Import modal gained a language-column selector (StringRepo::importCsv() already accepted an `$onlyLocales`
      scope — it just wasn't wired to the UI). Pick one language to only touch that column even if the CSV has
      others, or leave it on "All columns" for the original behavior.

## Phase 2 — Not yet built
- [ ] Per-row "ignore" toggle exposed in the grid UI (backend `ignore_row` action already exists)
- [ ] Bulk-select rows + bulk ignore/publish/delete
- [ ] Auto-translate integration (pluggable provider: DeepL/Google — needs API key setting UI)
- [ ] Roles/permissions smoke test against the Roles editor (mlt.view / mlt.manage / mlt.import_export)
- [ ] Admin dashboard widget: translation completion % per language
- [ ] Per-language "download only untranslated rows" export shortcut
- [ ] Undo/version history on translation edits (mirror core's revision pattern)
- [ ] Switcher: sync selected language into `<html lang>` attribute + hreflang tags for SEO
- [ ] Exclude/allowlist rules (regex) for strings that should never be harvested (emails, numbers already skipped)
- [ ] Batch textarea auto-grow polish + keyboard nav (Excel-style arrow key cell hopping)

## Known trade-offs (by design, revisit if they bite)
- Replacement matches on exact normalized *source text*, not per-page context — a string like "Name" translates
  the same everywhere it appears. This is what makes the flat spreadsheet grid possible; a context column can be
  added later (source_area) if two different meanings need different translations.
- Crawler only reaches URLs discoverable via admin_nav_items / customer_nav_items / public_routes filters. Since
  harvesting is now on-demand only (see v1.1.0 above), deep pages behind dynamic IDs (e.g. a single product page,
  a specific booking confirmation) will NOT get harvested until something explicitly visits them during a Scan —
  there's no passive fallback anymore. If you have content on URLs the nav filters don't expose, add a public
  route/link the crawler can discover, or extend MLT_Crawler::discoverUrls() with a manual URL list.
