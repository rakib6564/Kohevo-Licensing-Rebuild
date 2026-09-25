<?php
/**
 * Slate — breadcrumbs.
 *
 * Small accessible breadcrumb component for admin pages.
 *
 *   slate_breadcrumbs([
 *     ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
 *     ['label' => 'Plugins',   'href' => SLATE_URL . '/admin/plugins.php'],
 *     ['label' => 'Install'],  // current page — no href
 *   ]);
 */

if (!function_exists('slate_breadcrumbs')) {
    function slate_breadcrumbs(array $items): void {
        if (!$items) return;
        $count = count($items);
        echo '<nav class="slate-bc" aria-label="Breadcrumb"><ol class="slate-bc-list">';
        foreach ($items as $i => $it) {
            $isLast = ($i === $count - 1);
            $label  = (string)($it['label'] ?? '');
            $href   = $it['href'] ?? null;
            echo '<li class="slate-bc-item">';
            if ($href && !$isLast) {
                echo '<a href="' . e($href) . '" class="slate-bc-link">' . e($label) . '</a>';
            } else {
                echo '<span class="slate-bc-current" aria-current="page">' . e($label) . '</span>';
            }
            echo '</li>';
        }
        echo '</ol></nav>';
    }
}

if (!defined('SLATE_BC_CSS_EMITTED')) {
    define('SLATE_BC_CSS_EMITTED', true);
    ?>
    <style>
    /* Compact, chip-free trail that sits just above the page title. */
    .slate-bc { margin: 0 0 6px; font-family: inherit; }
    .slate-bc-list {
        list-style: none; padding: 0; margin: 0;
        display: flex; flex-wrap: wrap; align-items: center; gap: 0;
    }
    .slate-bc-item {
        display: inline-flex; align-items: center;
        font-size: 11.5px; color: var(--subtle); line-height: 1.3;
    }
    .slate-bc-item + .slate-bc-item::before {
        content: "›";
        margin: 0 6px;
        color: var(--faint);
        font-weight: 500;
        flex-shrink: 0;
    }
    .slate-bc-link {
        color: var(--muted);
        text-decoration: none;
        font-weight: 500;
        border-radius: 4px;
        transition: color .12s ease;
    }
    .slate-bc-link:hover { color: var(--accent); text-decoration: none; }
    .slate-bc-current { color: var(--text-2); font-weight: 600; }
    </style>
    <?php
}
