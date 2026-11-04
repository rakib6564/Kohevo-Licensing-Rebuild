<?php
/**
 * Studio public — shared chrome for the /studio views.
 *
 * The four public views (catalog / class / portal / register) each ship their
 * own scoped layout CSS, but three things must look and behave identically
 * across all of them, so they live here instead of being copy-pasted:
 *
 *   1. Palette bridge — the views were written against hard-coded hexes
 *      (#0f172a ink, #dcfce7 availability green). Those are re-expressed as
 *      `--st-*` custom properties sourced from the core token set, so a tenant
 *      that re-brands the accent gets a consistent studio too.
 *   2. Focus visibility — portal.css ships no `:focus-visible` rule, so a
 *      keyboard user tabbing the day strip or a Sign Up button saw nothing.
 *   3. Submit safety — "Pay now" opens a Stripe checkout and "Confirm
 *      registration" writes an enrollment; both were double-submittable.
 *
 * Loaded once from public/router.php, before any view is required.
 */

if (!defined('SLATE_ROOT')) { exit; }

if (!function_exists('studio_pub_css')) {
    /** Shared tokens + focus ring + availability pills for every /studio view. */
    function studio_pub_css(): void
    {
        if (defined('STUDIO_PUB_CSS')) { return; }
        define('STUDIO_PUB_CSS', true);
        ?>
        <style>
        body.studio-public {
            /* These views were written against the core token names, but they now
               render in the portal shell, whose kit is built on --m-*. Alias the
               core names to the kit's so the catalog and class pages use exactly
               the same greys as every other portal page instead of a palette a
               half-step off it. One block here beats editing every declaration. */
            --surface:   var(--m-surface, #FFFFFF);
            --surface-2: var(--m-bg,      #F4F5F7);
            --border:    var(--m-line,    #ECEEF1);
            --text:      var(--m-ink,     #15181E);
            --text-2:    var(--m-ink-2,   #3C4250);
            --muted:     var(--m-muted,   #737886);

            /* High-emphasis surfaces (active day pill, Sign Up). These used the
               near-black ink, which was readable but read as another product
               next to a pink brand. They now take the accent, with --on-accent
               for the label: the shell computes that per tenant, so a light
               brand gets dark text (8.96:1 here) instead of the white that a
               naive "make it branded" swap would have given it (1.98:1). */
            --st-ink: var(--accent);
            --st-ink-on: var(--on-accent, #fff);
            --st-accent: var(--accent);
            /* Availability pills. The soft tints are the core semantic tokens; the
               inks are deliberately a step darker than --success / --warning, which
               only reach ~3:1 on their own tint and would fail AA at 11px. */
            --st-open-bg: var(--success-soft);
            --st-open-ink: #15803D;
            --st-full-bg: var(--warning-soft);
            --st-full-ink: #B45309;
        }

        /* ── Focus visibility ─────────────────────────────────────────────
           portal.css has no focus-visible rule, so every custom control on
           these pages (day strip, week arrows, filter selects, CTAs) was
           invisible to keyboard users. One ring, applied everywhere. */
        body.studio-public :is(a, button, select, input, textarea, [tabindex]):focus-visible {
            outline: 2px solid var(--st-accent);
            outline-offset: 2px;
            border-radius: 8px;
        }

        /* ── Availability pill ────────────────────────────────────────── */
        body.studio-public .st-spots,
        body.studio-public .scd-spots {
            background: var(--st-open-bg);
            color: var(--st-open-ink);
        }
        body.studio-public .st-spots.is-full,
        body.studio-public .scd-spots.is-full {
            background: var(--st-full-bg);
            color: var(--st-full-ink);
        }

        /* ── Busy state ───────────────────────────────────────────────────
           Set by studio_pub_busy_js() on submit. Keeps the button's own
           colours (so a primary CTA still reads as primary) and swaps the
           label for a spinner so the press is visibly acknowledged. */
        body.studio-public .is-busy {
            position: relative;
            pointer-events: none;
            color: transparent !important;
        }
        body.studio-public .is-busy::after {
            content: "";
            position: absolute;
            inset: 0;
            margin: auto;
            width: 15px;
            height: 15px;
            border-radius: 50%;
            border: 2px solid currentColor;
            border-top-color: transparent;
            color: var(--st-ink-on);
            animation: st-spin 0.6s linear infinite;
        }
        @keyframes st-spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) {
            body.studio-public .is-busy::after { animation-duration: 2s; }
        }
        </style>
        <?php
    }
}

if (!function_exists('studio_pub_subnav')) {
    /**
     * The three public studio pages, as a nav.
     *
     * They existed before this did, and one of them could not be reached: the
     * policies page had a route, a view and no link to it anywhere in the
     * application. A page nobody can navigate to is a page nobody reads, and
     * the prices are on it.
     *
     * Public on purpose — no login gate. A parent deciding whether to enrol
     * is exactly who needs the price list, and they do not have an account
     * yet. The signed-in nav ("My studio") is separate and comes from
     * Studio::addCustomerNav().
     *
     * @param string $current Slug of the page being rendered, for aria-current.
     */
    function studio_pub_subnav(string $current = ''): void
    {
        $base  = SLATE_URL . '/studio';
        $items = [
            ['classes',  __('studio_nav_classes', 'Classes'),    $base],
            ['prices',   __('studio_nav_prices', 'Price list'),  $base . '?view=prices'],
            ['policies', __('studio_nav_policies', 'Policies'),  $base . '?view=policies'],
        ];
        ?>
        <style>
        .spn { display:flex; gap:8px; flex-wrap:wrap; margin:0 0 18px; }
        .spn a {
            display:inline-block; padding:9px 18px; border-radius:999px; text-decoration:none;
            font-size:14px; font-weight:600; color:var(--text-2,#3F4450);
            background:var(--surface,#fff); border:1px solid var(--border,#ECEDEF); transition:.15s;
        }
        .spn a:hover { border-color:var(--accent-ink, var(--text-2,#3F4450)); }
        .spn a[aria-current="page"] {
            background:var(--accent-soft,#F5F6F8); color:var(--text,#16181D);
            border-color:transparent; font-weight:700;
        }
        .spn a:focus-visible { outline:2px solid var(--accent-ink, var(--text-2,#3F4450)); outline-offset:2px; }
        </style>
        <nav class="spn" aria-label="<?= e(__('studio_nav_label', 'Studio pages')) ?>">
            <?php foreach ($items as [$slug, $label, $href]): ?>
                <a href="<?= e($href) ?>"<?= $slug === $current ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php
    }
}

if (!function_exists('studio_pub_busy_js')) {
    /**
     * Guards every POST form on the studio public pages against a double
     * submit. "Pay now" creates a Stripe Checkout session and "Confirm
     * registration" writes an enrollment row — a double-click on either was
     * previously two round trips, and on a slow connection the second one
     * lands before the redirect.
     */
    function studio_pub_busy_js(): void
    {
        if (defined('STUDIO_PUB_BUSY_JS')) { return; }
        define('STUDIO_PUB_BUSY_JS', true);
        ?>
        <script>
        (function () {
            document.addEventListener('submit', function (e) {
                var form = e.target;
                if (!form || form.method.toLowerCase() !== 'post') return;
                // A view may cancel its own submit for client-side validation;
                // latching before that check would wedge the form for good.
                if (e.defaultPrevented) return;
                if (form.dataset.stSubmitted) { e.preventDefault(); return; }
                form.dataset.stSubmitted = '1';
                var btn = form.querySelector('button[type=submit], button:not([type])');
                if (btn) {
                    btn.classList.add('is-busy');
                    btn.setAttribute('aria-busy', 'true');
                }
            });
        })();
        </script>
        <?php
    }
}
