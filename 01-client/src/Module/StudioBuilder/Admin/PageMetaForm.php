<?php
/**
 * Kohevo Studio — the "Content type & custom fields" card of the page settings screen.
 *
 * `card()` returns the whole form card of a plain page: a content type select and, for each type, the inputs of that
 * type's meta fields (one input kind per `MetaFieldTypes` key). Only the chosen type's group is shown and posted; the
 * other groups are disabled by the small script at the end, so their inputs never reach the server. An item of a type
 * uses `typeSelect()` and `groups()` inside its own screen.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Admin;

use Slate\Module\StudioBuilder\Meta\MetaFieldTypes;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\RepeaterRenderer;

final class PageMetaForm
{
    /**
     * @param array<string, array<string, mixed>> $types       key => post type
     * @param array<string, list<array<string, mixed>>> $fields post type key => its fields
     * @param array<string, mixed> $values                      the page's saved values (for its own type)
     * @param array<string, string> $errors                     field key => message
     * @param array<string, array<int, string>> $relationPages  post type key => [page id => title]
     */
    public static function card(string $self, int $pageId, string $current, array $types, array $fields, array $values, array $errors, array $relationPages, bool $canEdit, string $typesUrl): string
    {
        $h = static fn(string $s): string => \e($s);
        $dis = $canEdit ? '' : ' disabled';
        $out = '<form method="post" class="card sb-form-card" id="sb-meta-form" data-testid="page-meta-form">' . \csrf_field()
            . '<input type="hidden" name="id" value="' . $pageId . '"><input type="hidden" name="action" value="save_meta">'
            . '<div class="card-header"><div><h2>' . $h(\__('studio_page_content_type', 'Content type & custom fields')) . '</h2>'
            . '<p class="sb-field__hint">' . $h(\__('studio_page_content_type_sub', 'Pick what this page is, then fill in that type\'s custom fields. Define fields under Types & fields.')) . '</p></div></div>';

        if (isset($errors['_type'])) {
            $out .= '<div class="sb-alert sb-alert--error">' . $h($errors['_type']) . '</div>';
        }
        $out .= '<div class="sb-form-grid">' . self::typeSelect($current, $types, $canEdit) . '</div>';
        $out .= self::groups($current, $types, $fields, $values, $errors, $relationPages, $canEdit, $typesUrl);
        if ($canEdit) {
            $out .= '<div class="sb-form-actions"><button type="submit" class="btn btn-primary"><span>' . $h(\__('studio_page_save_meta', 'Save custom fields')) . '</span></button></div>';
        }
        return $out . '</form>' . self::script('sb-meta-form', $canEdit);
    }

    /**
     * The content type select.
     *
     * @param array<string, array<string, mixed>> $types key => post type
     */
    public static function typeSelect(string $current, array $types, bool $canEdit): string
    {
        $h = static fn(string $s): string => \e($s);
        $out = '<div class="sb-field"><label class="sb-field__label" for="sb-ctype">' . $h(\__('studio_page_content_type_label', 'Content type')) . '</label>'
            . '<select id="sb-ctype" name="post_type"' . ($canEdit ? '' : ' disabled') . '><option value="">' . $h(\__('studio_page_no_type', 'None (a plain page)')) . '</option>';
        foreach ($types as $key => $t) {
            $out .= '<option value="' . $h((string) $key) . '"' . ($key === $current ? ' selected' : '') . '>' . $h((string) ($t['singular'] ?? $key)) . '</option>';
        }
        return $out . '</select></div>';
    }

    /**
     * Each type's fields as a group of inputs; only the chosen type's group is shown and posted (see `script()`). A type
     * with Hide field names off shows each field's key under its label.
     *
     * @param array<string, array<string, mixed>> $types
     * @param array<string, list<array<string, mixed>>> $fields
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     * @param array<string, array<int, string>> $relationPages
     */
    public static function groups(string $current, array $types, array $fields, array $values, array $errors, array $relationPages, bool $canEdit, string $typesUrl): string
    {
        $h = static fn(string $s): string => \e($s);
        $out = '';
        foreach ($types as $key => $t) {
            $list = $fields[$key] ?? [];
            $showKeys = ($t['hide_field_names'] ?? true) === false;
            $out .= '<div class="sb-meta-group" data-type="' . $h((string) $key) . '"' . ($key === $current ? '' : ' hidden') . '>';
            if ($list === []) {
                $out .= '<p class="sb-field__hint">' . $h(\__('studio_page_no_fields', 'This type has no custom fields yet.')) . ' <a href="' . $h($typesUrl) . '">' . $h(\__('studio_types_fields', 'Types & fields')) . '</a></p>';
            } else {
                $out .= '<div class="sb-form-grid">';
                foreach ($list as $f) {
                    $fk = (string) ($f['key'] ?? '');
                    $id = 'sb-m-' . $key . '-' . $fk;
                    $wide = in_array(MetaFieldTypes::normalize((string) ($f['type'] ?? 'text')), ['textarea', 'richtext', 'repeater', 'gallery', 'list', 'keyvalue', 'checkboxes', 'multiselect', 'relations'], true);
                    $out .= '<div class="sb-field' . ($wide ? ' sb-field--wide' : '') . '"><label class="sb-field__label" id="' . $h($id) . '-l" for="' . $h($id) . '">' . $h((string) ($f['label'] ?? $fk))
                        . (!empty($f['required']) ? ' <span style="color:#ef4444;">*</span>' : '') . '</label>'
                        . self::input($f, $key === $current ? ($values[$fk] ?? null) : null, $id, $relationPages[(string) ($f['options']['target'] ?? '')] ?? [], $canEdit);
                    if ($showKeys) {
                        $out .= '<p class="sb-field__hint"><code>' . $h($fk) . '</code></p>';
                    }
                    if (!empty($f['help'])) {
                        $out .= '<p class="sb-field__hint">' . $h((string) $f['help']) . '</p>';
                    }
                    if (isset($errors[$fk]) && $key === $current) {
                        $out .= '<p class="sb-field__hint" style="color:#b91c1c;">' . $h($errors[$fk]) . '</p>';
                    }
                    $out .= '</div>';
                }
                $out .= '</div>';
            }
            $out .= '</div>';
        }
        return $out;
    }

    /** Shows the chosen type's group and turns the others off, so their inputs never reach the server. */
    public static function script(string $formId, bool $canEdit): string
    {
        return self::fieldAssets() . '<script>(function(){var f=document.getElementById(' . json_encode($formId) . ');if(!f)return;var s=f.querySelector("#sb-ctype");'
            . 'function sync(){f.querySelectorAll(".sb-meta-group").forEach(function(g){var on=g.getAttribute("data-type")===s.value;g.hidden=!on;'
            . 'g.querySelectorAll("input,select,textarea").forEach(function(i){i.disabled=!on||' . ($canEdit ? 'false' : 'true') . ';});});}'
            . 's.addEventListener("change",sync);sync();})();</script>';
    }

    /**
     * The rows of a repeater field, with a template the script copies for a new row.
     *
     * @param array<string, mixed> $f
     * @param array<int, mixed> $rows
     */
    private static function repeater(array $f, array $rows, string $id, string $name, bool $canEdit): string
    {
        $row = static function (int|string $i, array $values) use ($f, $id, $name, $canEdit): string {
            $inputs = '';
            foreach (MetaFieldTypes::subfields($f) as $sub) {
                $k = (string) $sub['key'];
                $subId = $id . '-' . $i . '-' . $k;
                $inputs .= '<div class="sb-field"><label class="sb-field__label" id="' . \e($subId) . '-l" for="' . \e($subId) . '">' . \e((string) ($sub['label'] ?? $k)) . '</label>'
                    . self::input($sub, $values[$k] ?? null, $subId, [], $canEdit, $name . '[' . $i . '][' . $k . ']') . '</div>';
            }
            $tool = static fn(string $act, string $label): string => '<button type="button" data-rep-act="' . $act . '" title="' . \e($label) . '" aria-label="' . \e($label) . '">' . ['up' => '↑', 'down' => '↓', 'copy' => '⧉', 'del' => '×'][$act] . '</button>';
            return '<div class="sb-rep__row" data-rep-row><div class="sb-rep__tools">' . ($canEdit ? $tool('up', \__('studio_move_up', 'Move up')) . $tool('down', \__('studio_move_down', 'Move down')) . $tool('copy', \__('studio_duplicate', 'Duplicate')) . $tool('del', \__('studio_delete', 'Delete')) : '') . '</div>' . $inputs . '</div>';
        };
        $out = '';
        foreach (array_values(array_filter($rows, 'is_array')) as $i => $values) {
            $out .= $row($i, $values);
        }
        return '<div class="sb-rep" data-rep data-next="' . count($rows) . '"><div data-rep-rows>' . $out . '</div>'
            . ($canEdit ? '<template data-rep-tpl>' . $row('__i__', []) . '</template><button type="button" class="btn btn-outline" data-rep-add>+ ' . \e(\__('studio_add_item', 'Add item')) . '</button>' : '') . '</div>';
    }

    /** The rich text editor, the repeater rows and the image button, for every field on the form. */
    private static function fieldAssets(): string
    {
        return <<<'HTML'
<style>
.sb-rte{border:1px solid #d1d5db;border-radius:8px;overflow:hidden;background:#fff}
.sb-rte__bar{display:flex;flex-wrap:wrap;gap:4px;padding:6px;border-bottom:1px solid #e5e7eb;background:#f9fafb}
.sb-rte__bar button{min-width:32px;height:30px;padding:0 8px;border:1px solid #d1d5db;border-radius:5px;background:#fff;color:#111827;font-weight:600;cursor:pointer}
.sb-rte__bar button[aria-pressed=true]{background:#111827;color:#fff}
.sb-rte__edit{min-height:180px;max-height:480px;overflow:auto;padding:12px 14px;line-height:1.6;outline:0}
.sb-rte [hidden]{display:none!important}
.sb-rte textarea{width:100%;border:0;padding:12px 14px;font-family:ui-monospace,monospace;font-size:13px}
.sb-rep{display:flex;flex-direction:column;gap:12px;align-items:flex-start;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb}
.sb-rep [data-rep-rows]{display:flex;flex-direction:column;gap:12px;width:100%}
.sb-rep__row{position:relative;display:flex;flex-direction:column;gap:10px;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff}
.sb-rep__tools{display:flex;justify-content:flex-end;gap:4px}
.sb-rep__tools button{width:30px;height:28px;border:1px solid #d1d5db;border-radius:5px;background:#fff;color:#374151;cursor:pointer}
.sb-img-field{display:flex;gap:10px;align-items:center}
.sb-img-field__thumb{width:52px;height:52px;border-radius:6px;background:#f3f4f6;overflow:hidden;flex:none}
.sb-img-field__thumb img{width:100%;height:100%;object-fit:cover}
.sb-img-field input{flex:1;min-width:0}
</style>
<script>
(function(){
function sync(r){var e=r.querySelector('[data-rte-edit]'),s=r.querySelector('[data-rte-src]');if(!s.hidden){e.innerHTML=s.value;}else{s.value=e.innerHTML;}}
document.addEventListener('input',function(ev){var e=ev.target.closest&&ev.target.closest('[data-rte-edit]');if(e){e.parentNode.querySelector('[data-rte-src]').value=e.innerHTML;}});
document.addEventListener('submit',function(ev){ev.target.querySelectorAll('[data-rte]').forEach(sync);},true);
document.addEventListener('mousedown',function(ev){var b=ev.target.closest&&ev.target.closest('.sb-rte__bar button');if(b){ev.preventDefault();}});
document.addEventListener('click',function(ev){
  var t=ev.target,b;
  if(!t.closest){return;}
  if((b=t.closest('.sb-rte__bar button'))){
    var r=b.closest('[data-rte]'),e=r.querySelector('[data-rte-edit]'),s=r.querySelector('[data-rte-src]'),c=b.getAttribute('data-cmd');
    if(c==='code'){sync(r);s.hidden=!s.hidden;e.hidden=!s.hidden;b.setAttribute('aria-pressed',String(!s.hidden));return;}
    if(!s.hidden){return;}
    e.focus();
    if(c==='createLink'||c==='insertImage'){var u=window.prompt('https://');if(u){document.execCommand(c,false,u);}}
    else{document.execCommand(c,false,b.getAttribute('data-arg')||null);}
    s.value=e.innerHTML;return;
  }
  if((b=t.closest('[data-img-pick]'))){
    var box=b.closest('[data-img-field]'),i=box.querySelector('input');
    if(window.SbMediaPicker){window.SbMediaPicker.open(i.id);}return;
  }
  if((b=t.closest('[data-rep-add]'))){
    var rep=b.closest('[data-rep]'),n=rep.getAttribute('data-next');rep.setAttribute('data-next',+n+1);
    var w=document.createElement('div');w.innerHTML=rep.querySelector('[data-rep-tpl]').innerHTML.replace(/__i__/g,n);
    rep.querySelector('[data-rep-rows]').appendChild(w.firstElementChild);return;
  }
  if((b=t.closest('[data-rep-act]'))){
    var row=b.closest('[data-rep-row]'),a=b.getAttribute('data-rep-act'),box2=row.parentNode;
    if(a==='del'){row.remove();}
    if(a==='up'&&row.previousElementSibling){box2.insertBefore(row,row.previousElementSibling);}
    if(a==='down'&&row.nextElementSibling){box2.insertBefore(row.nextElementSibling,row);}
    if(a==='copy'){
      row.querySelectorAll('[data-rte]').forEach(sync);
      var rep2=row.closest('[data-rep]'),m=rep2.getAttribute('data-next');rep2.setAttribute('data-next',+m+1);
      var from=row.querySelectorAll('input,textarea,select'),c2=row.cloneNode(true),to=c2.querySelectorAll('input,textarea,select');
      to.forEach(function(el,k){el.value=from[k].value;});
      c2.querySelectorAll('[name],[id],[for],[aria-labelledby]').forEach(function(el){['name','id','for','aria-labelledby'].forEach(function(at){var v=el.getAttribute(at);if(v){el.setAttribute(at,v.replace(/\[\d+\]\[/,'['+m+'][').replace(/-\d+-(?=[a-z0-9_]+(-l)?$)/,'-'+m+'-'));}});});
      row.after(c2);
    }
  }
});
})();
</script>
HTML;
    }

    /**
     * One input for one field.
     *
     * @param array<string, mixed> $f
     * @param array<int, string> $pages page id => title, for a relation
     */
    public static function input(array $f, mixed $value, string $id, array $pages, bool $canEdit, ?string $inputName = null): string
    {
        $h = static fn(mixed $s): string => \e(is_scalar($s) ? (string) $s : '');
        $type = MetaFieldTypes::normalize((string) ($f['type'] ?? 'text'));
        $name = $inputName ?? 'meta[' . (string) ($f['key'] ?? '') . ']';
        $opt = is_array($f['options'] ?? null) ? $f['options'] : [];
        $ph = ' placeholder="' . $h($f['placeholder'] ?? '') . '"';
        $req = !empty($f['required']) && !in_array($type, ['boolean', 'multiselect', 'checkboxes', 'gallery', 'list', 'relations', 'keyvalue', 'daterange', 'richtext', 'repeater'], true) ? ' required' : '';
        $dis = $canEdit ? '' : ' disabled';
        $base = ' id="' . $h($id) . '" name="' . $h($name) . '"' . $req . $dis;
        $attr = '';
        foreach (['min', 'max', 'step'] as $k) {
            if (isset($opt[$k])) {
                $attr .= ' ' . $k . '="' . $h($opt[$k]) . '"';
            }
        }
        $arr = is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]);
        $lines = static fn(array $a): string => implode("\n", array_map(static fn($x): string => is_scalar($x) ? (string) $x : '', $a));
        $choices = is_array($opt['choices'] ?? null) ? $opt['choices'] : [];

        switch ($type) {
            case 'richtext':
                $html = is_scalar($value) ? RepeaterRenderer::rich($value) : '';
                $tool = static fn(string $cmd, string $label, string $arg = ''): string => '<button type="button" data-cmd="' . $cmd . '" data-arg="' . $arg . '" title="' . $h($label) . '">' . $h($label) . '</button>';
                return '<div class="sb-rte" data-rte><div class="sb-rte__bar">' . $tool('bold', 'B') . $tool('italic', 'I') . $tool('formatBlock', 'H2', 'h2') . $tool('formatBlock', 'H3', 'h3') . $tool('formatBlock', '¶', 'p')
                    . $tool('insertUnorderedList', '•') . $tool('insertOrderedList', '1.') . $tool('createLink', \__('studio_rte_link', 'Link')) . $tool('insertImage', \__('studio_rte_image', 'Image')) . $tool('code', \__('studio_rte_code', 'Code')) . '</div>'
                    . '<div class="sb-rte__edit" data-rte-edit' . ($canEdit ? ' contenteditable="true"' : '') . ' role="textbox" aria-multiline="true" aria-labelledby="' . $h($id) . '-l">' . $html . '</div>'
                    . '<textarea' . $base . ' rows="10" hidden data-rte-src>' . $h($html) . '</textarea></div>';
            case 'repeater':
                return self::repeater($f, is_array($value) ? $value : [], $id, $name, $canEdit);
            case 'image':
                return '<div class="sb-img-field" data-img-field><span class="sb-img-field__thumb" id="preview_' . $h($id) . '">' . (is_string($value) && $value !== '' ? '<img src="' . $h($value) . '" alt="">' : '') . '</span><input type="text"' . $base . $ph . ' value="' . $h($value) . '">'
                    . ($canEdit ? '<button type="button" class="btn btn-outline" data-img-pick>' . $h(\__('studio_choose_image', 'Choose image')) . '</button>' : '') . '</div>';
            case 'textarea':
                return '<textarea' . $base . ' rows="4"' . $ph . (isset($opt['max_length']) ? ' maxlength="' . (int) $opt['max_length'] . '"' : '') . '>' . $h($value) . '</textarea>';
            case 'number':
            case 'price':
            case 'rating':
                return '<input type="number" step="' . $h($opt['step'] ?? ($type === 'price' ? '0.01' : 'any')) . '"' . $base . $attr . $ph . ' value="' . $h($value) . '">';
            case 'range':
                return '<input type="range"' . $base . $attr . ' value="' . $h($value ?? ($opt['min'] ?? 0)) . '">';
            case 'select':
                $o = '<select' . $base . '><option value="">' . $h(\__('studio_choose', 'Choose…')) . '</option>';
                foreach ($choices as $c) {
                    $o .= '<option value="' . $h($c['value'] ?? '') . '"' . ((string) ($c['value'] ?? '') === (string) $value ? ' selected' : '') . '>' . $h($c['label'] ?? $c['value'] ?? '') . '</option>';
                }
                return $o . '</select>';
            case 'radio':
                $o = '';
                foreach ($choices as $i => $c) {
                    $o .= '<label class="sb-check"><input type="radio" name="' . $h($name) . '" value="' . $h($c['value'] ?? '') . '"' . ((string) ($c['value'] ?? '') === (string) $value ? ' checked' : '') . $dis . '> ' . $h($c['label'] ?? $c['value'] ?? '') . '</label> ';
                }
                return '<div id="' . $h($id) . '">' . $o . '</div>';
            case 'multiselect':
                $o = '<select multiple size="' . min(8, max(3, count($choices))) . '" id="' . $h($id) . '" name="' . $h($name) . '[]"' . $dis . '>';
                foreach ($choices as $c) {
                    $o .= '<option value="' . $h($c['value'] ?? '') . '"' . (in_array((string) ($c['value'] ?? ''), array_map('strval', $arr), true) ? ' selected' : '') . '>' . $h($c['label'] ?? $c['value'] ?? '') . '</option>';
                }
                return $o . '</select>';
            case 'checkboxes':
                $o = '';
                foreach ($choices as $c) {
                    $o .= '<label class="sb-check"><input type="checkbox" name="' . $h($name) . '[]" value="' . $h($c['value'] ?? '') . '"' . (in_array((string) ($c['value'] ?? ''), array_map('strval', $arr), true) ? ' checked' : '') . $dis . '> ' . $h($c['label'] ?? $c['value'] ?? '') . '</label> ';
                }
                return '<div id="' . $h($id) . '">' . $o . '</div>';
            case 'boolean':
                return '<label class="sb-check"><input type="checkbox" id="' . $h($id) . '" name="' . $h($name) . '" value="1"' . ($value ? ' checked' : '') . $dis . '> ' . $h(\__('studio_yes', 'Yes')) . '</label>';
            case 'date':
            case 'time':
                return '<input type="' . $type . '"' . $base . ' value="' . $h($value) . '">';
            case 'datetime':
                return '<input type="datetime-local"' . $base . ' value="' . $h($value) . '">';
            case 'daterange':
                $a = is_array($value) ? ($value['start'] ?? '') : '';
                $b = is_array($value) ? ($value['end'] ?? '') : '';
                return '<div class="sb-two-up"><input type="date" id="' . $h($id) . '" name="' . $h($name) . '[start]" value="' . $h($a) . '"' . $dis . '><input type="date" name="' . $h($name) . '[end]" value="' . $h($b) . '"' . $dis . '></div>';
            case 'color':
                return '<input type="color"' . $base . ' value="' . $h(is_string($value) && $value !== '' ? $value : '#000000') . '">';
            case 'email':
            case 'url':
                return '<input type="' . $type . '"' . $base . $ph . ' value="' . $h($value) . '">';
            case 'phone':
                return '<input type="tel"' . $base . $ph . ' value="' . $h($value) . '">';
            case 'relation':
                $o = '<select' . $base . '><option value="">' . $h(\__('studio_choose', 'Choose…')) . '</option>';
                foreach ($pages as $pid => $title) {
                    $o .= '<option value="' . (int) $pid . '"' . ((string) $pid === (string) $value ? ' selected' : '') . '>' . $h($title) . '</option>';
                }
                return $o . '</select>';
            case 'relations':
                $o = '<select multiple size="' . min(8, max(3, count($pages))) . '" id="' . $h($id) . '" name="' . $h($name) . '[]"' . $dis . '>';
                foreach ($pages as $pid => $title) {
                    $o .= '<option value="' . (int) $pid . '"' . (in_array((string) $pid, array_map('strval', $arr), true) ? ' selected' : '') . '>' . $h($title) . '</option>';
                }
                return $o . '</select>';
            case 'gallery':
            case 'list':
                return '<textarea' . $base . ' rows="4"' . ($type === 'gallery' ? ' placeholder="https://…"' : $ph) . '>' . $h($lines($arr)) . '</textarea>';
            case 'keyvalue':
                $pairs = [];
                foreach (is_array($value) ? $value : [] as $k => $v) {
                    $pairs[] = $k . ' : ' . (is_scalar($v) ? $v : '');
                }
                return '<textarea' . $base . ' rows="4" placeholder="key : value">' . $h(implode("\n", $pairs)) . '</textarea>';
            default: // text, url-like fallbacks, file, video, location
                return '<input type="text"' . $base . $ph . (isset($opt['max_length']) ? ' maxlength="' . (int) $opt['max_length'] . '"' : '') . ' value="' . $h($value) . '">';
        }
    }
}
