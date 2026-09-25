<?php
/**
 * Slate — accessibility primitives.
 *
 * Tiny stylesheet for: screen-reader-only text, skip link,
 * focus-visible rings, reduced-motion respect.
 *
 * Variables are read from ui_components.php so they stay in sync
 * with the active theme.
 */
if (defined('SLATE_A11Y_HEAD_EMITTED')) return;
define('SLATE_A11Y_HEAD_EMITTED', true);
?>
<style>
/* Screen-reader-only text */
.sr-only,
.visually-hidden {
    position: absolute !important;
    width: 1px; height: 1px; padding: 0; margin: -1px;
    overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0;
}

/* Skip link — hidden until focused, then prominent */
.skip-link {
    position: absolute;
    left: -9999px;
    top: auto;
    width: 1px; height: 1px;
    overflow: hidden;
}
.skip-link:focus {
    position: fixed;
    left: 1rem;
    top: 1rem;
    width: auto; height: auto;
    padding: 12px 18px;
    z-index: 9999;
    background: var(--accent, #2563EB);
    color: #fff;
    border-radius: 10px;
    font-weight: 600;
    text-decoration: none;
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
}

/* Visible focus ring on keyboard navigation only */
:focus { outline: none; }
:focus-visible {
    outline: 2px solid var(--accent, #2563EB);
    outline-offset: 2px;
    border-radius: 4px;
}
/* Inputs/buttons handle their own focus via box-shadow ring */
.btn:focus-visible,
.input:focus-visible,
.field input:focus-visible,
.field select:focus-visible,
.field textarea:focus-visible {
    outline: none;
    box-shadow: 0 0 0 3px var(--ring, rgba(37,99,235,0.13));
}

/* Reduced motion respect */
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
        scroll-behavior: auto !important;
    }
}
</style>
