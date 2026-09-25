<?php
/**
 * Slate — admin shell footer.
 *
 * Closes the main element opened by header.php, then renders the
 * mobile bottom tab bar + "More" sheet. Both are no-ops on desktop
 * (CSS-hidden via @media min-width: 768px).
 *
 * Finally emits any scripts queued by active plugins via
 * $this->enqueueScript().
 *
 * Variables expected from header.php scope (PHP includes share scope
 * with their including file):
 *   $tabBarItems, $moreNavItems, $currentNav, $navItems
 */
if (!defined('SLATE_ROOT')) exit;
?>
        </main>
        </div><!-- /.app-panel -->

        <!-- ─── Bottom tab bar (mobile) — 4 tabs + center FAB ── -->
        <?php
            // Split the tabs around a prominent center action (the "More"
            // menu), app-style: two tabs · FAB · two tabs.
            $tabLeft  = array_slice($tabBarItems, 0, 2);
            $tabRight = array_slice($tabBarItems, 2, 2);
            $renderTab = static function (array $item) use ($currentNav) {
                $isActive = ($currentNav === ($item['slug'] ?? null));
                echo '<a href="' . e($item['href']) . '" class="tabbar-item' . ($isActive ? ' is-active' : '') . '"'
                   . ($isActive ? ' aria-current="page"' : '') . '>'
                   . slate_admin_nav_icon($item['icon'] ?? 'circle')
                   . '<span class="tabbar-item-label">' . e($item['label']) . '</span></a>';
            };
        ?>
        <nav class="tabbar" aria-label="<?= __('primary_navigation', 'Primary navigation') ?>">
            <?php foreach ($tabLeft as $item) $renderTab($item); ?>

            <?php if (!empty($moreNavItems)): ?>
                <button type="button" class="tabbar-fab" id="slate-more-trigger"
                        aria-expanded="false" aria-controls="slate-more-sheet"
                        aria-label="<?= __('more', 'More') ?>">
                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <span class="tabbar-item-label"><?= __('more', 'More') ?></span>
                </button>
            <?php endif; ?>

            <?php foreach ($tabRight as $item) $renderTab($item); ?>

            <?php if (empty($moreNavItems)): ?>
                <a href="<?= e(SLATE_URL) ?>/admin/logout.php?csrf=<?= e(csrf_token()) ?>" class="tabbar-item">
                    <?= slate_admin_nav_icon('logout') ?>
                    <span class="tabbar-item-label"><?= __('logout', 'Log out') ?></span>
                </a>
            <?php endif; ?>
        </nav>

        <?php if (!empty($navItems)):
            // The sheet is the full mobile menu — list EVERY destination
            // (grouped like the sidebar), not just the tab-bar overflow, so
            // Forms/Submissions/Contacts etc. are always reachable here too.
            $sheetGroups = [];
            foreach ($navItems as $item) {
                $gk = $item['group'] ?? '';
                if ($gk === '' || !is_string($gk)) $gk = 'plugins';
                $gk = strtolower(preg_replace('/[^a-z0-9_-]+/i', '-', $gk)) ?: 'plugins';
                $sheetGroups[$gk][] = $item;
            }
        ?>
        <!-- ─── More sheet (mobile overflow) ─────────────── -->
        <div class="sheet-overlay" id="slate-sheet-overlay" hidden></div>
        <div class="sheet" id="slate-more-sheet" role="dialog"
             aria-modal="true" aria-labelledby="slate-more-title" hidden>
            <div class="sheet-grab" aria-hidden="true"><span class="sheet-handle"></span></div>
            <div class="sheet-topbar">
                <div class="sheet-header" id="slate-more-title"><?= __('more', 'More') ?></div>
                <button type="button" class="sheet-close" data-sheet-close aria-label="<?= __('close', 'Close') ?>">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            <div class="sheet-scroll">
                <?php foreach ($sheetGroups as $gk => $gItems):
                    $gkAttr = (string)$gk;
                    $gHasActive = false;
                    foreach ($gItems as $gi) {
                        if (($gi['slug'] ?? null) === $currentNav) { $gHasActive = true; break; }
                    }
                ?>
                    <details class="nav-group" <?= $gHasActive ? 'open' : '' ?>>
                    <summary class="sheet-section-label">
                        <span class="nav-section-label-text"><?= e(slate_admin_group_label($gkAttr)) ?></span>
                        <svg class="nav-section-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg>
                    </summary>
                        <ul class="sheet-nav">
                            <?php foreach ($gItems as $item):
                                $isActive = ($currentNav === ($item['slug'] ?? null));
                            ?>
                                <li>
                                    <a href="<?= e($item['href']) ?>" class="<?= $isActive ? 'is-active' : '' ?>"
                                       <?= $isActive ? 'aria-current="page"' : '' ?>>
                                        <span class="sheet-nav-ico"><?= slate_admin_nav_icon($item['icon'] ?? 'circle') ?></span>
                                        <span class="sheet-nav-label"><?= e($item['label']) ?></span>
                                        <svg class="sheet-nav-arr" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endforeach; ?>
                <div class="sheet-section-label"><?= __('account', 'Account') ?></div>
                <ul class="sheet-nav">
                    <li>
                        <a href="<?= e(SLATE_URL) ?>/admin/logout.php?csrf=<?= e(csrf_token()) ?>" class="sheet-nav-danger">
                            <span class="sheet-nav-ico"><?= slate_admin_nav_icon('logout') ?></span>
                            <span class="sheet-nav-label"><?= __('logout', 'Log out') ?></span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
        <script>
        (function() {
            var trigger = document.getElementById('slate-more-trigger');
            var sheet   = document.getElementById('slate-more-sheet');
            var overlay = document.getElementById('slate-sheet-overlay');
            if (!trigger || !sheet || !overlay) return;

            function openSheet() {
                sheet.hidden = false;
                overlay.hidden = false;
                // Force reflow so the transition fires
                void sheet.offsetWidth;
                sheet.classList.add('is-open');
                overlay.classList.add('is-open');
                trigger.setAttribute('aria-expanded', 'true');
                document.body.style.overflow = 'hidden';
            }
            function closeSheet() {
                sheet.classList.remove('is-open');
                overlay.classList.remove('is-open');
                trigger.setAttribute('aria-expanded', 'false');
                document.body.style.overflow = '';
                setTimeout(function() {
                    sheet.hidden = true;
                    overlay.hidden = true;
                }, 250);
            }
            trigger.addEventListener('click', openSheet);
            overlay.addEventListener('click', closeSheet);
            var closeBtn = sheet.querySelector('[data-sheet-close]');
            if (closeBtn) closeBtn.addEventListener('click', closeSheet);
            // Close after tapping a destination so the sheet doesn't linger
            // over the new page during the navigation.
            sheet.querySelectorAll('.sheet-nav a').forEach(function (a) {
                a.addEventListener('click', closeSheet);
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && sheet.classList.contains('is-open')) closeSheet();
            });
        })();
        </script>
        <?php endif; ?>

        <!-- ─── Topbar menus (notifications + user) ───────── -->
        <script>
        (function () {
            // Relocate the page breadcrumb into the topbar on desktop (fills
            // the topbar, frees content space); keep it in the content on
            // mobile. Resize-aware so crossing the breakpoint never strands it.
            var bcSlot = document.getElementById('topbar-bc-slot');
            var bc = document.querySelector('main.content .slate-bc')
                  || (bcSlot && bcSlot.querySelector('.slate-bc'));
            var contentMain = document.querySelector('main.content');
            if (bcSlot && bc && contentMain) {
                var placeBc = function () {
                    if (window.matchMedia('(min-width: 768px)').matches) {
                        if (bc.parentNode !== bcSlot) { bcSlot.appendChild(bc); }
                        bcSlot.classList.add('is-filled');
                    } else {
                        if (bc.parentNode === bcSlot) { contentMain.insertBefore(bc, contentMain.firstChild); }
                        bcSlot.classList.remove('is-filled');
                    }
                };
                placeBc();
                window.addEventListener('resize', placeBc);
            }

            // Collapse filter bars behind a compact "Filters" toggle that
            // reveals the fields on click. Opens automatically when filters
            // are already active (so the user sees what's applied).
            document.querySelectorAll('main.content form.filter-row').forEach(function (form) {
                var card = form.closest('.card');
                if (!card || card.dataset.filterReady) return;
                card.dataset.filterReady = '1';
                card.classList.add('is-filter');

                var active = 0;
                form.querySelectorAll('input, select').forEach(function (el) {
                    if (el.type === 'hidden' || el.type === 'submit' || el.type === 'button') return;
                    if (el.tagName === 'SELECT') {
                        // The first <option> is the neutral "All/Any" default
                        // (its value may be '0', 'all', '', …). A select whose
                        // default isn't first can mark it with data-default.
                        // Only a non-default choice counts as an active filter.
                        var opt = el.options[el.selectedIndex];
                        var isDefault = el.selectedIndex === 0 || (opt && opt.hasAttribute('data-default'));
                        if (!isDefault) active++;
                    } else if ((el.value || '').trim() !== '') {
                        active++;
                    }
                });

                var tgl = document.createElement('button');
                tgl.type = 'button';
                tgl.className = 'filter-toggle';
                tgl.innerHTML =
                    '<svg class="filter-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>' +
                    '<span>Filters</span>' +
                    (active ? '<span class="filter-count">' + active + '</span>' : '') +
                    '<svg class="filter-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';

                card.insertBefore(tgl, form);
                var open = active > 0;
                form.hidden = !open;
                tgl.setAttribute('aria-expanded', open ? 'true' : 'false');
                tgl.addEventListener('click', function () {
                    open = !open;
                    form.hidden = !open;
                    tgl.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
            });

            var menus = Array.prototype.slice.call(document.querySelectorAll('[data-topbar-menu]'));
            if (!menus.length) return;

            function closeAll(except) {
                menus.forEach(function (m) {
                    if (m === except) return;
                    var pop = m.querySelector('[data-topbar-pop]');
                    var tog = m.querySelector('[data-topbar-toggle]');
                    if (pop) pop.hidden = true;
                    if (tog) tog.setAttribute('aria-expanded', 'false');
                });
            }

            menus.forEach(function (menu) {
                var toggle = menu.querySelector('[data-topbar-toggle]');
                var pop    = menu.querySelector('[data-topbar-pop]');
                if (!toggle || !pop) return;
                toggle.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var willOpen = pop.hidden;
                    closeAll(menu);
                    pop.hidden = !willOpen;
                    toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                });
                pop.addEventListener('click', function (e) { e.stopPropagation(); });
            });

            document.addEventListener('click', function () { closeAll(null); });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeAll(null);
            });

        })();
        </script>

        <!-- ─── Sidebar collapse toggle ───────────────────── -->
        <script>
        (function () {
            var btn = document.getElementById('slate-sidebar-toggle');
            if (!btn) return;
            var root = document.documentElement;
            btn.addEventListener('click', function () {
                var collapsed = root.classList.toggle('sidebar-collapsed');
                try { localStorage.setItem('slate_sidebar_collapsed', collapsed ? '1' : '0'); } catch (e) {}
                btn.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
            });
        })();
        </script>

        <!-- Nav group accordion (desktop sidebar + mobile More sheet): now
             plain native <details>/<summary> in the markup above — the
             browser owns open/close state entirely, no JS required. Three
             prior JS-based attempts at an animated version all hit the same
             class of bug (transitioning max-height to/from 'none' renders
             inconsistently across browsers, clipping the panel mid-open) —
             removed for good in favor of this, which cannot have that bug. -->

    </div><!-- /.app-layout -->

    <?= PluginLoader::renderQueuedScripts() ?>
</body>
</html>
