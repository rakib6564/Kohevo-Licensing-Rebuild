<?php
/**
 * Slate — Template Library.
 *
 * Content -> Templates (/admin/templates.php)
 * Central management of page templates, section layout templates, and starter packs.
 *
 * "Use Template"/"Insert in Editor" open admin/editor.php?template=<id> (or
 * ?insert_section=<id>), which now actually seeds the new page's first draft
 * from real blocks (see admin/editor.php's slate_editor_starter_blocks()) —
 * these links used to go nowhere, since editor.php never read that param.
 *
 * User-saved templates (Save as Template, from the editor's Page tab) are
 * listed below the built-ins, backed by document_templates
 * (Slate\Services\Content\DocumentTemplateRepository) — with Export (download
 * the schema-1 document as JSON) and Import (upload that JSON back in) as the
 * counterpart round trip.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

use Slate\Presentation\DocumentValidator;
use Slate\Services\Content\ContentServiceFactory;

Auth::require();
Auth::requirePerm('content.view');

// Same Phase 2 kill switch as admin/editor.php — hiding the nav link alone
// leaves this page fully reachable by direct URL for any admin who knows it.
if (Database::setting('editor_phase2_enabled') === '0') {
    $pageTitle  = __('templates', 'Templates');
    $currentNav = 'content-templates';
    require __DIR__ . '/partials/header.php';
    echo '<div class="card"><div class="card-body"><p>'
        . e(__('editor_unavailable', 'The visual editor is temporarily unavailable.'))
        . '</p></div></div>';
    require __DIR__ . '/partials/footer.php';
    exit;
}

$pageTitle  = __('templates', 'Templates');
$currentNav = 'templates';

$canEdit = Auth::can('content.edit') || Auth::isSuperAdmin();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('no_permission', 'You do not have permission to manage templates.')];
    } else {
        $taction = $_POST['_action'] ?? '';

        if ($taction === 'delete') {
            ContentServiceFactory::templateRepository()->delete((int) ($_POST['id'] ?? 0));
            $flash = ['type' => 'success', 'msg' => __('template_deleted', 'Template deleted.')];
        } elseif ($taction === 'import') {
            $file = $_FILES['template_file'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $flash = ['type' => 'error', 'msg' => __('no_files', 'Please choose a .json file to import.')];
            } elseif (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
                $flash = ['type' => 'error', 'msg' => __('uploads_failed', 'The file could not be uploaded.')];
            } elseif (($file['size'] ?? 0) > 2 * 1024 * 1024) {
                $flash = ['type' => 'error', 'msg' => __('file_too_large', 'That file is too large (max 2MB).')];
            } else {
                $raw = file_get_contents($file['tmp_name']);
                $payload = json_decode((string) $raw, true);
                // Accept either our own export envelope ({name, document}) or a
                // bare schema-1 document — both round-trip through the same validator.
                $document = is_array($payload) && isset($payload['document']) && is_array($payload['document'])
                    ? $payload['document']
                    : (is_array($payload) ? $payload : null);
                $name = trim((string) ($payload['name'] ?? pathinfo((string) $file['name'], PATHINFO_FILENAME)));
                if ($document === null) {
                    $flash = ['type' => 'error', 'msg' => __('invalid_json', 'That file is not valid JSON.')];
                } else {
                    $validated = DocumentValidator::validate($document, ContentServiceFactory::validatorRegistry());
                    if (!$validated['valid']) {
                        $flash = ['type' => 'error', 'msg' => __('template_import_invalid', 'That document failed validation and was not imported.')];
                    } else {
                        ContentServiceFactory::templateRepository()->create(
                            $name !== '' ? $name : 'Imported template',
                            (string) ($validated['document']['type'] ?? 'page'),
                            $validated['document'],
                        );
                        $flash = ['type' => 'success', 'msg' => __('template_imported', 'Template imported.')];
                    }
                }
            }
        }
    }
}

$savedTemplates = ContentServiceFactory::templateRepository()->all();

// Load available page templates. 'bands' describes the real section
// composition each one seeds (see admin/editor.php's
// slate_editor_starter_blocks()) — used to draw a structural thumbnail rather
// than a generic placeholder, so a card looks like what "Use Template"
// actually produces.
$pageTemplates = [
    [
        'id'          => 'default',
        'name'        => __('template_default', 'Default Page'),
        'description' => __('template_default_desc', 'Heading, an intro paragraph, a two-column section, and a closing call to action.'),
        'type'        => 'page',
        'badge'       => 'Core',
        'bands'       => ['text', 'columns3', 'cta'],
    ],
    [
        'id'          => 'full-width',
        'name'        => __('template_full_width', 'Full Width Canvas'),
        'description' => __('template_full_width_desc', 'Edge-to-edge hero, a feature grid, a testimonial, and a closing CTA.'),
        'type'        => 'page',
        'badge'       => 'Core',
        'bands'       => ['hero-banner', 'grid3', 'cta'],
    ],
    [
        'id'          => 'landing',
        'name'        => __('template_landing', 'Landing Page (No Header/Footer)'),
        'description' => __('template_landing_desc', 'Split hero, features, social proof, a 3-tier pricing table, and a contact CTA.'),
        'type'        => 'page',
        'badge'       => 'Marketing',
        'bands'       => ['hero-split', 'grid3', 'quote', 'columns3'],
    ],
    [
        'id'          => 'blog-single',
        'name'        => __('template_blog_single', 'Editorial Post'),
        'description' => __('template_blog_single_desc', 'Article typography: title, intro, body copy, and a pull-quote.'),
        'type'        => 'post',
        'badge'       => 'Blog',
        'bands'       => ['text', 'text', 'quote'],
    ],
];

// Load available section layout presets
$sectionTemplates = [
    [
        'id'          => 'hero-split',
        'name'        => 'Split Hero Banner',
        'category'    => 'Hero',
        'description' => 'Two-column layout with high-impact headline, value bullets, primary CTA, and responsive media container.',
        'bands'       => ['hero-split'],
    ],
    [
        'id'          => 'features-grid-3',
        'name'        => 'Three-Column Features Grid',
        'category'    => 'Features',
        'description' => 'Modern bento-style cards with icons, titles, short descriptions, and link tags.',
        'bands'       => ['grid3'],
    ],
    [
        'id'          => 'social-proof',
        'name'        => 'Testimonial & Social Proof Wall',
        'category'    => 'Testimonials',
        'description' => 'Customer quotes, client logos, star ratings, and verified badge indicators.',
        'bands'       => ['quote'],
    ],
    [
        'id'          => 'pricing-table',
        'name'        => 'Three-Tier Pricing Table',
        'category'    => 'Commerce',
        'description' => 'Highlight featured plan, toggle billing cadences, and direct checkout buttons.',
        'bands'       => ['columns3'],
    ],
    [
        'id'          => 'contact-cta',
        'name'        => 'Conversion CTA with Contact',
        'category'    => 'Call to Action',
        'description' => 'Bold accent banner with newsletter signup or booking inquiry prompt.',
        'bands'       => ['cta'],
    ],
];

// Category/type -> accent color, purely cosmetic grouping for badges + thumbnails.
$slateTemplateColors = [
    'Core' => '#2563eb', 'Marketing' => '#d946ef', 'Blog' => '#f59e0b',
    'Hero' => '#2563eb', 'Features' => '#16a34a', 'Testimonials' => '#f59e0b',
    'Commerce' => '#d946ef', 'Call to Action' => '#ef4444', 'page' => '#2563eb', 'post' => '#f59e0b',
];

/**
 * A small structural "wireframe" thumbnail for a template card — draws the
 * real section shapes (hero/grid/columns/quote/text/cta) it seeds, rather
 * than a generic icon, so the card hints at the actual page layout.
 *
 * @param list<string> $bands
 */
function slate_template_thumb(array $bands, string $accent = '#2563eb'): string
{
    $w = 280; $h = 132;
    $n = max(1, count($bands));
    $bandH = $h / $n;
    $out = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="100%" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">';
    $out .= '<rect x="0" y="0" width="' . $w . '" height="' . $h . '" fill="var(--bg, #f8fafc)"/>';
    $y = 0;
    foreach ($bands as $band) {
        $out .= slate_template_thumb_band($band, $y, $w, $bandH, $accent);
        $y += $bandH;
    }
    $out .= '</svg>';
    return $out;
}

function slate_template_thumb_band(string $type, float $y, float $w, float $h, string $accent): string
{
    $line = static fn (float $x, float $ly, float $lw, float $lh = 6, string $fill = 'var(--border)') =>
        '<rect x="' . $x . '" y="' . $ly . '" width="' . $lw . '" height="' . $lh . '" rx="' . ($lh / 2) . '" fill="' . $fill . '"/>';
    $card = static fn (float $x, float $cy, float $cw, float $ch) =>
        '<rect x="' . $x . '" y="' . $cy . '" width="' . $cw . '" height="' . $ch . '" rx="4" fill="none" stroke="var(--border)" stroke-width="1.5"/>';
    $cy = $y + $h / 2;
    $pad = 14;

    return match ($type) {
        'hero-split' =>
            '<rect x="' . $pad . '" y="' . ($y + $pad * 0.6) . '" width="' . ($w * 0.4) . '" height="' . ($h - $pad * 1.2) . '" rx="4" fill="var(--border)" opacity=".5"/>'
            . $line($w * 0.48, $cy - 14, $w * 0.42, 10)
            . $line($w * 0.48, $cy + 4, $w * 0.36, 5)
            . '<rect x="' . ($w * 0.48) . '" y="' . ($cy + 18) . '" width="46" height="14" rx="7" fill="' . $accent . '"/>',
        'hero-banner' =>
            $line($w * 0.28, $cy - 18, $w * 0.44, 11)
            . $line($w * 0.34, $cy - 1, $w * 0.32, 5)
            . '<rect x="' . ($w / 2 - 26) . '" y="' . ($cy + 14) . '" width="52" height="14" rx="7" fill="' . $accent . '"/>',
        'grid3' => (function () use ($w, $y, $h, $pad, $card, $line) {
            $cw = ($w - $pad * 4) / 3; $cy2 = $y + $h * 0.18; $ch = $h * 0.64;
            $out = '';
            for ($i = 0; $i < 3; $i++) {
                $cx = $pad + $i * ($cw + $pad);
                $out .= $card($cx, $cy2, $cw, $ch);
                $out .= '<circle cx="' . ($cx + $cw / 2) . '" cy="' . ($cy2 + 14) . '" r="7" fill="var(--border)"/>';
                $out .= $line($cx + 8, $cy2 + $ch - 22, $cw - 16, 5);
                $out .= $line($cx + 8, $cy2 + $ch - 12, $cw - 24, 4);
            }
            return $out;
        })(),
        'columns3' => (function () use ($w, $y, $h, $pad, $card, $line, $accent) {
            $cw = ($w - $pad * 4) / 3; $cy2 = $y + $h * 0.14; $ch = $h * 0.72;
            $out = '';
            for ($i = 0; $i < 3; $i++) {
                $cx = $pad + $i * ($cw + $pad);
                $out .= $card($cx, $cy2, $cw, $ch);
                $out .= $line($cx + 8, $cy2 + 10, $cw * 0.5, 6);
                $out .= $line($cx + 8, $cy2 + 24, $cw - 16, 4);
                $out .= $line($cx + 8, $cy2 + 32, $cw - 24, 4);
                $out .= '<rect x="' . ($cx + 8) . '" y="' . ($cy2 + $ch - 20) . '" width="' . ($cw - 16) . '" height="12" rx="6" fill="' . ($i === 1 ? $accent : 'var(--border)') . '" opacity="' . ($i === 1 ? '1' : '.6') . '"/>';
            }
            return $out;
        })(),
        'quote' =>
            '<circle cx="' . ($w / 2) . '" cy="' . ($y + $h * 0.24) . '" r="9" fill="none" stroke="var(--border)" stroke-width="1.5"/>'
            . $line($w * 0.22, $cy, $w * 0.56, 6)
            . $line($w * 0.3, $cy + 12, $w * 0.4, 5)
            . '<circle cx="' . ($w / 2) . '" cy="' . ($y + $h - 18) . '" r="8" fill="var(--border)" opacity=".6"/>',
        'cta' =>
            '<rect x="' . ($pad * 1.5) . '" y="' . ($y + $pad * 0.5) . '" width="' . ($w - $pad * 3) . '" height="' . ($h - $pad) . '" rx="6" fill="' . $accent . '" opacity=".12"/>'
            . $line($w * 0.3, $cy - 14, $w * 0.4, 8, $accent)
            . $line($w * 0.34, $cy + 2, $w * 0.32, 5)
            . '<rect x="' . ($w / 2 - 28) . '" y="' . ($cy + 16) . '" width="56" height="14" rx="7" fill="' . $accent . '"/>',
        default => // 'text'
            $line($pad, $y + $h * 0.22, $w * 0.5, 7)
            . $line($pad, $y + $h * 0.48, $w - $pad * 2, 4)
            . $line($pad, $y + $h * 0.62, $w - $pad * 2, 4)
            . $line($pad, $y + $h * 0.76, $w * 0.7, 4),
    };
}

require __DIR__ . '/partials/header.php';
?>
<style>
    .tpl-card { margin:0; border:1px solid var(--border); border-radius:12px; overflow:hidden;
                display:flex; flex-direction:column; background:var(--surface, var(--bg));
                transition: transform .15s ease, box-shadow .15s ease; }
    .tpl-card:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,0,0,.07); }
    .tpl-thumb { aspect-ratio: 280/132; width:100%; background:var(--bg); border-bottom:1px solid var(--border); display:block; }
    .tpl-body { padding:16px 18px; display:flex; flex-direction:column; flex:1; }
    .tpl-head { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; margin-bottom:6px; }
    .tpl-name { font-size:15px; font-weight:700; color:var(--text); line-height:1.3; }
    .tpl-desc { font-size:12.5px; color:var(--muted); margin:0 0 14px; line-height:1.5; flex:1; }
    .tpl-foot { display:flex; justify-content:space-between; align-items:center; gap:8px; border-top:1px solid var(--border); padding-top:12px; }
    .tpl-pill { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; padding:3px 9px; border-radius:20px; white-space:nowrap; }
    .tpl-icon-btn { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:7px;
                    border:1px solid var(--border); background:transparent; color:var(--muted); cursor:pointer; text-decoration:none; }
    .tpl-icon-btn:hover { color:var(--text); border-color:var(--muted); }
    .tpl-icon-btn.danger:hover { color:#ef4444; border-color:#ef4444; }
    .tpl-icon-btn svg { width:15px; height:15px; }
    .tpl-dropzone { border:2px dashed var(--border); border-radius:12px; padding:22px; text-align:center;
                    display:flex; flex-direction:column; align-items:center; gap:10px; }
    .tpl-dropzone svg { width:28px; height:28px; color:var(--muted); }
    .tpl-empty { display:flex; flex-direction:column; align-items:center; gap:10px; padding:30px 10px; color:var(--muted); text-align:center; }
    .tpl-empty svg { width:34px; height:34px; opacity:.5; }
</style>
<?php
function slate_tpl_pill(string $label, string $color): string
{
    return '<span class="tpl-pill" style="background:' . e($color) . '22;color:' . e($color) . '">' . e($label) . '</span>';
}
?>

<div class="page-header" style="margin-bottom:24px;">
    <div>
        <h1 style="margin:0 0 6px;font-size:24px;font-weight:700;color:var(--text);"><?= __('templates_library', 'Template Library') ?></h1>
        <p style="margin:0;color:var(--muted);font-size:14px;"><?= __('templates_library_sub', 'Standard page structures, section presets, and reusable design layouts.') ?></p>
    </div>
    <div class="toolbar">
        <a href="<?= e(SLATE_URL) ?>/admin/editor.php" class="btn btn-primary">
            <?= slate_admin_nav_icon('layout') ?>
            <span><?= __('open_editor', 'New Page in Editor') ?></span>
        </a>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status" style="margin-bottom:20px;"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:32px;">
    <div class="card-header">
        <h2><?= __('your_templates', 'Your Templates') ?></h2>
        <span class="badge badge-info"><?= count($savedTemplates) ?> <?= __('templates', 'Templates') ?></span>
    </div>
    <div class="card-body" style="padding:20px;">
        <?php if (!$savedTemplates): ?>
            <div class="tpl-empty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="14" rx="2"/><path d="M3 9h18"/><path d="M8 4v5"/></svg>
                <p style="margin:0;font-size:13px;max-width:40ch;"><?= __('no_saved_templates', 'No saved templates yet. Open a page in the editor and use "Save as Template" (Page tab), or import one below.') ?></p>
            </div>
        <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(260px, 1fr));gap:18px;margin-bottom:22px;">
                <?php foreach ($savedTemplates as $tmpl): ?>
                    <div class="tpl-card">
                        <div class="tpl-thumb"><?= slate_template_thumb(['text', 'grid3'], $slateTemplateColors[$tmpl['type']] ?? '#2563eb') ?></div>
                        <div class="tpl-body">
                            <div class="tpl-head">
                                <span class="tpl-name"><?= e($tmpl['name']) ?></span>
                                <?= slate_tpl_pill($tmpl['type'], $slateTemplateColors[$tmpl['type']] ?? '#64748b') ?>
                            </div>
                            <p class="tpl-desc"><?= __('updated', 'Updated') ?> <?= e($tmpl['updated_at']) ?></p>
                            <div class="tpl-foot">
                                <a href="<?= e(SLATE_URL) ?>/admin/editor.php?template=<?= urlencode('custom:' . $tmpl['id']) ?>" class="btn btn-sm btn-ghost">
                                    <?= __('use_in_editor', 'Use Template') ?> →
                                </a>
                                <div style="display:flex;gap:6px;">
                                    <a href="<?= e(SLATE_URL) ?>/admin/template-export.php?id=<?= (int) $tmpl['id'] ?>" class="tpl-icon-btn" title="<?= __('export', 'Export') ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    </a>
                                    <?php if ($canEdit): ?>
                                    <form method="post" onsubmit="return confirm('Delete this template? This cannot be undone.');" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $tmpl['id'] ?>">
                                        <button type="submit" class="tpl-icon-btn danger" title="<?= __('delete', 'Delete') ?>">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($canEdit): ?>
        <form method="post" enctype="multipart/form-data" class="tpl-dropzone">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="import">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <div style="font-size:13px;color:var(--text);font-weight:600;"><?= __('import_template', 'Import Template') ?></div>
            <div style="font-size:12px;color:var(--subtle);"><?= __('import_template_hint', 'Upload a .json file exported from this Template Library.') ?></div>
            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:center;margin-top:4px;max-width:100%;">
                <input type="file" name="template_file" accept="application/json,.json" required style="max-width:100%;">
                <button type="submit" class="btn btn-sm btn-primary"><?= __('import', 'Import') ?></button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-bottom:32px;">
    <div class="card-header">
        <h2><?= __('page_templates', 'Page Document Templates') ?></h2>
        <span class="badge badge-info"><?= count($pageTemplates) ?> <?= __('templates', 'Templates') ?></span>
    </div>
    <div class="card-body" style="padding:20px;">
        <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(260px, 1fr));gap:18px;">
            <?php foreach ($pageTemplates as $tmpl): ?>
                <?php $color = $slateTemplateColors[$tmpl['badge']] ?? '#2563eb'; ?>
                <div class="tpl-card">
                    <div class="tpl-thumb"><?= slate_template_thumb($tmpl['bands'], $color) ?></div>
                    <div class="tpl-body">
                        <div class="tpl-head">
                            <span class="tpl-name"><?= e($tmpl['name']) ?></span>
                            <?= slate_tpl_pill($tmpl['badge'], $color) ?>
                        </div>
                        <p class="tpl-desc"><?= e($tmpl['description']) ?></p>
                        <div class="tpl-foot">
                            <code style="font-size:11px;color:var(--subtle);"><?= e($tmpl['id']) ?></code>
                            <a href="<?= e(SLATE_URL) ?>/admin/editor.php?template=<?= urlencode($tmpl['id']) ?>" class="btn btn-sm btn-ghost">
                                <?= __('use_in_editor', 'Use Template') ?> →
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2><?= __('section_templates', 'Section Layout Library') ?></h2>
        <span class="badge badge-info"><?= count($sectionTemplates) ?> <?= __('presets', 'Presets') ?></span>
    </div>
    <div class="card-body" style="padding:20px;">
        <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(260px, 1fr));gap:18px;">
            <?php foreach ($sectionTemplates as $sec): ?>
                <?php $color = $slateTemplateColors[$sec['category']] ?? '#2563eb'; ?>
                <div class="tpl-card">
                    <div class="tpl-thumb"><?= slate_template_thumb($sec['bands'], $color) ?></div>
                    <div class="tpl-body">
                        <div class="tpl-head">
                            <span class="tpl-name" style="font-size:14px;"><?= e($sec['name']) ?></span>
                            <?= slate_tpl_pill($sec['category'], $color) ?>
                        </div>
                        <p class="tpl-desc"><?= e($sec['description']) ?></p>
                        <div class="tpl-foot" style="justify-content:flex-end;">
                            <a href="<?= e(SLATE_URL) ?>/admin/editor.php?insert_section=<?= urlencode($sec['id']) ?>" class="btn btn-sm btn-secondary">
                                <?= __('insert_in_page', 'Insert in Editor') ?>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php
require __DIR__ . '/partials/footer.php';
