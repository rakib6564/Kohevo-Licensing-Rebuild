// Typed CSS value checks — the client half of `StyleValueGuard.php`.
//
// Every free-form style control validates with these before it commits, so an
// author sees an inline error instead of a rejected save. The server remains
// the authority (it checks on save AND on render); this file must accept and
// refuse exactly what the PHP guard does, which `tests/style-values.test.mjs`
// and `tests/unit/StudioBuilderStyleGuardTest.php` both pin against the shared
// `tests/fixtures/style-values.json`.

const UNITS = 'px|rem|em|%|vw|vh|vmin|vmax|ch|ex|pt|cm|mm|in';
const LENGTH_KEYWORDS = ['auto', 'none', 'fit-content', 'min-content', 'max-content', 'inherit', 'initial', 'unset'];
const COLOR_FUNCTIONS = ['rgb', 'rgba', 'hsl', 'hsla'];
const GRADIENT_FUNCTIONS = ['linear-gradient', 'radial-gradient', 'conic-gradient', 'rgb', 'rgba', 'hsl', 'hsla'];
const MATH_FUNCTIONS = ['calc', 'clamp', 'min', 'max'];
const MAX_LENGTH = 300;
const FORBIDDEN_FRAGMENTS = ['url(', 'image(', 'image-set(', 'src(', 'var(', 'env(', 'attr(', 'expression(', 'javascript:', 'behavior:', '-moz-binding', 'data:', '!important'];

const TOKEN = /^(surface|text|space|radius|shadow|font|color|border)\.[a-z0-9_]+(\.[a-z0-9_]+)?$/;
// eslint-disable-next-line no-control-regex
const FORBIDDEN_CHARS = /[<>;{}\\@\u0000-\u001f]/;

const isString = (v) => typeof v === 'string';

function plain(value) {
  if (value === '' || value.length > MAX_LENGTH) return false;
  if (FORBIDDEN_CHARS.test(value)) return false;
  if (value.includes('/*') || value.includes('*/')) return false;
  const lower = value.toLowerCase();
  return !FORBIDDEN_FRAGMENTS.some((f) => lower.includes(f));
}

function onlyFunctions(value, allowed) {
  for (const m of value.matchAll(/([a-z-]*)\(/gi)) {
    if (m[1] !== '' && !allowed.includes(m[1].toLowerCase())) return false;
  }
  return true;
}

function balanced(value) {
  let depth = 0;
  for (const ch of value) {
    if (ch === '(') depth += 1;
    else if (ch === ')') {
      depth -= 1;
      if (depth < 0) return false;
    }
  }
  return depth === 0;
}

export function isToken(value) {
  return isString(value) && TOKEN.test(value);
}

function isMath(value) {
  if (!/^(calc|clamp|min|max)\(/i.test(value) || !value.endsWith(')')) return false;
  return onlyFunctions(value, MATH_FUNCTIONS) && /^[a-z0-9.,%()+\-*/ ]+$/i.test(value) && balanced(value);
}

export function isLength(value) {
  if (typeof value === 'number') return Number.isFinite(value) && Math.abs(value) <= 100000;
  if (!isString(value) || !plain(value)) return false;
  const v = value.trim();
  if (LENGTH_KEYWORDS.includes(v.toLowerCase())) return true;
  if (new RegExp(`^-?(\\d+\\.?\\d*|\\.\\d+)(${UNITS})?$`, 'i').test(v)) return true;
  return isMath(v);
}

export function isLengthList(value, max = 4) {
  if (!isString(value)) return isLength(value);
  if (!plain(value) || value.includes('(')) return isLength(value);
  const parts = value.trim().split(/\s+/);
  if (parts.length === 0 || parts.length > max) return false;
  return parts.every(isLength);
}

export function isLengthOrToken(value) {
  if (isString(value) && isToken(value)) return true;
  return isLengthList(value);
}

export function isLineHeight(value) {
  if (isString(value) && value.trim().toLowerCase() === 'normal') return true;
  return isLength(value);
}

export function isLetterSpacing(value) {
  if (isString(value) && value.trim().toLowerCase() === 'normal') return true;
  return isLength(value);
}

export function isBorderWidth(value) {
  if (isString(value) && ['thin', 'medium', 'thick'].includes(value.trim().toLowerCase())) return true;
  return isLengthList(value);
}

function functionalColor(value) {
  return /^(rgb|rgba|hsl|hsla)\(\s*[0-9.]+(deg|%)?\s*[, ]\s*[0-9.]+%?\s*[, ]\s*[0-9.]+%?(\s*[,/]\s*[0-9.]+%?)?\s*\)$/i.test(value);
}

export function isColor(value) {
  if (!isString(value) || !plain(value)) return false;
  const v = value.trim();
  if (isToken(v)) return true;
  if (/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test(v)) return true;
  if (/^[a-z]{3,24}$/i.test(v)) return true;
  return functionalColor(v);
}

export function isGradient(value) {
  if (!isString(value) || !plain(value)) return false;
  const v = value.trim();
  if (!/^(linear|radial|conic)-gradient\(/i.test(v) || !v.endsWith(')')) return false;
  return onlyFunctions(v, GRADIENT_FUNCTIONS) && /^[a-z0-9#.,%() +-]+$/i.test(v) && balanced(v);
}

export function isShadow(value) {
  if (!isString(value) || !plain(value)) return false;
  const v = value.trim();
  if (v === '') return false;
  if (!onlyFunctions(v, COLOR_FUNCTIONS) || !/^[a-z0-9#.,%() +-]+$/i.test(v) || !balanced(v)) return false;
  const layers = [];
  let depth = 0;
  let buf = '';
  for (const ch of v) {
    if (ch === '(') depth += 1;
    else if (ch === ')') depth -= 1;
    if (ch === ',' && depth === 0) {
      layers.push(buf);
      buf = '';
    } else {
      buf += ch;
    }
  }
  layers.push(buf);
  return layers.length <= 4 && !layers.some((l) => l.trim() === '');
}

const FAMILY = '(?:[A-Za-z0-9 _-]+|\'[A-Za-z0-9 _-]+\'|"[A-Za-z0-9 _-]+")';
const FONT_STACK = new RegExp(`^${FAMILY}(?:\\s*,\\s*${FAMILY})*$`);

export function isFontFamily(value) {
  if (!isString(value) || value.length > 200) return false;
  const v = value.trim();
  if (v === '') return false;
  if (isToken(v)) return true;
  return FONT_STACK.test(v);
}

/** Field kind → checker, for controls that name what they edit. */
export const CHECKS = {
  length: isLength,
  lengthList: isLengthList,
  lengthOrToken: isLengthOrToken,
  lineHeight: isLineHeight,
  letterSpacing: isLetterSpacing,
  borderWidth: isBorderWidth,
  color: isColor,
  gradient: isGradient,
  shadow: isShadow,
  fontFamily: isFontFamily,
};

/** True when `value` is empty (meaning "inherit") or passes the named check. */
export function acceptsDraft(kind, value) {
  if (value === undefined || value === null || value === '') return true;
  const check = CHECKS[kind];
  return check ? check(value) : false;
}
