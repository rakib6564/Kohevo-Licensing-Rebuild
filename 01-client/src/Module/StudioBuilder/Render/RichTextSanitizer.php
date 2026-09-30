<?php
/**
 * Kohevo Studio (studio-builder) — Output-side Rich Text Sanitizer.
 *
 * `FieldSchema::validateRichText()` fails closed on WRITE. The renderer does
 * not rely on that alone (defense in depth, and old trusted data): every
 * `rich_text` value is re-emitted through this allowlist on OUTPUT.
 *
 * - Only the same allowlisted tags as the write validator are emitted, and
 *   only `<a>` keeps attributes (`href` re-checked by `FieldSchema::isSafeUrl`,
 *   `target` in {_self,_blank}, `rel` charset-limited; `_blank` forces
 *   `noopener noreferrer`).
 * - Comments, doctype, processing instructions and every non-allowlisted tag
 *   are dropped; anything tag-shaped that does not parse is escaped as text.
 * - Text is entity-decoded then re-escaped, so no raw markup survives.
 * - Output is always well-formed: stray closers are dropped and unclosed
 *   elements are closed, so authored content can never bleed into the page
 *   chrome around it.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render;

use Slate\Module\StudioBuilder\Schema\FieldSchema;

final class RichTextSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'em', 'b', 'i', 'u', 's',
        'ul', 'ol', 'li', 'blockquote', 'code', 'pre',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'span',
    ];

    private const VOID_TAGS = ['br'];

    public static function sanitize(string $html): string
    {
        $parts = preg_split('/(<[^<>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return Html::e($html);
        }

        $out   = '';
        $stack = [];

        foreach ($parts as $part) {
            if ($part === '' ) {
                continue;
            }
            if ($part[0] !== '<') {
                $out .= Html::e(html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                continue;
            }

            // Comments, doctype, CDATA, processing instructions: dropped entirely.
            if (preg_match('/^<\s*[!?]/', $part) === 1) {
                continue;
            }

            if (preg_match('/^<\s*(\/?)\s*([a-zA-Z0-9]+)([^>]*)>$/', $part, $m) !== 1) {
                $out .= Html::e($part);
                continue;
            }

            $closing = $m[1] === '/';
            $tag     = strtolower($m[2]);
            $attrs   = trim(rtrim(trim($m[3]), '/'));

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                continue;
            }

            if ($closing) {
                $pos = array_search($tag, $stack, true);
                if ($pos === false) {
                    continue; // stray closer
                }
                while (count($stack) > $pos) {
                    $out .= '</' . array_pop($stack) . '>';
                }
                continue;
            }

            if (in_array($tag, self::VOID_TAGS, true)) {
                $out .= '<' . $tag . '>';
                continue;
            }

            $out .= $tag === 'a' ? self::anchorOpenTag($attrs) : '<' . $tag . '>';
            $stack[] = $tag;
        }

        while ($stack !== []) {
            $out .= '</' . array_pop($stack) . '>';
        }

        return $out;
    }

    private static function anchorOpenTag(string $attrString): string
    {
        $href = null;
        $target = '_self';
        $rel = [];

        if ($attrString !== '' && preg_match_all('/([a-zA-Z0-9_-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $attrString, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $name  = strtolower($m[1]);
                $value = html_entity_decode($m[2] !== '' ? $m[2] : ($m[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($name === 'href' && FieldSchema::isSafeUrl($value)) {
                    $href = trim($value);
                } elseif ($name === 'target' && in_array($value, FieldSchema::ALLOWED_LINK_TARGETS, true)) {
                    $target = $value;
                } elseif ($name === 'rel' && preg_match('/^[a-zA-Z0-9 _-]{1,64}$/', $value) === 1) {
                    $rel = array_filter(explode(' ', strtolower($value)));
                }
            }
        }

        if ($href === null) {
            return '<a>';
        }
        if ($target === '_blank') {
            $rel[] = 'noopener';
            $rel[] = 'noreferrer';
        }
        $rel = array_values(array_unique($rel));
        sort($rel);

        return '<a href="' . Html::e($href) . '"'
            . ($target === '_blank' ? ' target="_blank"' : '')
            . ($rel !== [] ? ' rel="' . Html::e(implode(' ', $rel)) . '"' : '')
            . '>';
    }
}
