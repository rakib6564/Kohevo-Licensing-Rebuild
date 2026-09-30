// Kohevo Studio builder — rich text output normalizer.
//
// The rich-text control (Lexical) exports HTML with editor attributes
// (`class`, `dir`, `style="white-space: pre-wrap"`, `value` on <li>, …) that
// the canonical `rich_text` field does not allow. This converts that output to
// the SAME allowlist the server enforces (FieldSchema::validateRichText):
//
//   p br strong em b i u s ul ol li blockquote code pre h1-h6 a span
//   — no attributes, except href / target / rel on <a> (safe URLs only)
//
// Anything else is dropped (its text kept). The server still validates and
// the renderer still re-sanitizes; this only keeps the editor from producing
// values that would be rejected.

import { isLikelySafeUrl } from './fields.mjs';

const ALLOWED = new Set(['p', 'br', 'strong', 'em', 'b', 'i', 'u', 's', 'ul', 'ol', 'li', 'blockquote', 'code', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'span']);
const DROP_WITH_CONTENT = new Set(['script', 'style', 'iframe', 'object', 'embed', 'template', 'svg', 'math', 'noscript', 'textarea', 'select']);
const TAG = /<(\/?)([a-zA-Z][a-zA-Z0-9-]*)([^>]*)>|<!--[\s\S]*?-->/g;

function attr(attrs, name) {
  const m = new RegExp(`(?:^|\\s)${name}\\s*=\\s*(?:"([^"]*)"|'([^']*)'|([^\\s"'>]+))`, 'i').exec(attrs);
  if (!m) return null;
  return decodeEntities(m[1] ?? m[2] ?? m[3] ?? '');
}

function decodeEntities(s) {
  return s.replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
}

function escAttr(s) {
  return s.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

export function toAllowedHtml(html) {
  const input = String(html ?? '');
  let out = '';
  let last = 0;
  let skipDepth = 0;
  let skipTag = null;
  const open = [];
  let m;
  TAG.lastIndex = 0;
  while ((m = TAG.exec(input)) !== null) {
    const text = input.slice(last, m.index);
    last = TAG.lastIndex;
    if (!skipDepth) out += text;
    if (!m[2]) continue; // comment
    const closing = m[1] === '/';
    const tag = m[2].toLowerCase();

    if (skipDepth) {
      if (tag === skipTag) skipDepth += closing ? -1 : 1;
      continue;
    }
    if (DROP_WITH_CONTENT.has(tag)) {
      if (!closing) { skipDepth = 1; skipTag = tag; }
      continue;
    }
    if (!ALLOWED.has(tag)) continue;

    if (closing) {
      const idx = open.lastIndexOf(tag);
      if (idx < 0) continue;
      while (open.length > idx) out += `</${open.pop()}>`;
      continue;
    }
    if (tag === 'br') { out += '<br>'; continue; }
    if (tag === 'a') {
      const href = attr(m[3], 'href');
      const target = attr(m[3], 'target');
      let a = '<a';
      if (href && isLikelySafeUrl(href)) a += ` href="${escAttr(href.trim())}"`;
      if (target === '_blank') a += ' target="_blank" rel="noopener noreferrer"';
      out += a + '>';
    } else {
      out += `<${tag}>`;
    }
    open.push(tag);
  }
  if (!skipDepth) out += input.slice(last);
  while (open.length) out += `</${open.pop()}>`;

  // Attribute-less spans are pure editor noise.
  let prev;
  do {
    prev = out;
    out = out.replace(/<span>([^<]*)<\/span>/g, '$1');
  } while (out !== prev);
  // Lexical wraps bold as <b><strong>…</strong></b>; keep one.
  out = out.replace(/<b><strong>([\s\S]*?)<\/strong><\/b>/g, '<strong>$1</strong>')
    .replace(/<i><em>([\s\S]*?)<\/em><\/i>/g, '<em>$1</em>');
  return out.trim() === '' ? '<p></p>' : out;
}
