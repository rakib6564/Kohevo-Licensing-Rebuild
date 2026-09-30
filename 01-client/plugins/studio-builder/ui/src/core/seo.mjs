// Search-appearance helpers shared by the page inspector and its tests.
//
// The SERVER is the authority (DocumentValidator / FieldSchema::isSafeUrl and
// the public SeoHead canonical policy); these checks only keep obviously
// invalid input from being sent, and never accept anything the server would
// refuse for being an unsafe scheme.

export const DEFAULT_SEO_TITLE_MAX = 255;
export const DEFAULT_SEO_DESCRIPTION_MAX = 1000;

/** The limits the server's validator enforces (manifest.limits), with the schema defaults as fallback. */
export function seoLimits(manifest) {
  const limits = (manifest && manifest.limits) || {};
  const pick = (v, d) => (Number.isInteger(v) && v > 0 ? v : d);
  return { title: pick(limits.seo_title_max, DEFAULT_SEO_TITLE_MAX), description: pick(limits.seo_description_max, DEFAULT_SEO_DESCRIPTION_MAX) };
}

/**
 * The message key for a problem with an authored canonical URL, or null when
 * it is acceptable: empty (uses the page's own address), a site-relative path
 * (`/about`), or an absolute http(s) URL. Protocol-relative (`//host`), other
 * schemes (javascript:, data:, mailto:, …), whitespace and control characters
 * are refused. Whether an absolute URL is on THIS site is decided by the
 * server when the page is published: a foreign host is ignored there.
 */
export function canonicalProblem(value) {
  const v = typeof value === 'string' ? value.trim() : '';
  if (v === '') return null;
  if (v.length > 2048 || /[\u0000-\u001f\u007f\s]/.test(v)) return 'seo_canonical_invalid';
  if (v.startsWith('/')) return v.startsWith('//') ? 'seo_canonical_invalid' : null;
  return /^https?:\/\/[^\s/$.?#].[^\s]*$/i.test(v) ? null : 'seo_canonical_invalid';
}

/** The value to store for the canonical input: trimmed text, or null to clear it. */
export function canonicalValue(value) {
  const v = typeof value === 'string' ? value.trim() : '';
  return v === '' ? null : v;
}
