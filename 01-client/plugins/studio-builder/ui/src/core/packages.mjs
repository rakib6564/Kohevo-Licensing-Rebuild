// Kohevo Studio builder — Phase 8A JSON package helpers (framework-free).
//
// The browser never interprets a package: it only reads the file the user
// picked, checks that it LOOKS like a Kohevo Studio package, and sends it to
// the server's `import_package` command — first as a dry run (analysis),
// then, only when that analysis says `can_commit === true` for exactly the
// same inputs, as the import into drafts. All validation, tenant scoping,
// reference remapping and persistence happen on the server.

export const PACKAGE_FORMAT = 'kohevo-studio-package';
/** The server refuses bodies above ~1.5 MB; refuse obviously larger files before uploading. */
export const MAX_PACKAGE_BYTES = 1_500_000;
export const MODES = Object.freeze({ CREATE: 'create', REPLACE: 'replace_draft' });
/** Phase 8B: where an import comes from. */
export const SOURCES = Object.freeze({ PACKAGE: 'package', HTML: 'html' });
/** The server's HTML/CSS source budgets (bytes); larger files are refused before uploading. */
export const MAX_HTML_BYTES = 524288;
export const MAX_CSS_BYTES = 131072;

/**
 * Parse the text of a chosen file. Never throws.
 * @returns {{ ok: true, package: object, items: number } | { ok: false, error: string }}
 */
export function parsePackageText(text) {
  if (typeof text !== 'string' || text.trim() === '') return { ok: false, error: 'package_empty' };
  if (text.length > MAX_PACKAGE_BYTES) return { ok: false, error: 'package_too_large' };
  let data;
  try {
    data = JSON.parse(text);
  } catch {
    return { ok: false, error: 'package_not_json' };
  }
  if (!data || typeof data !== 'object' || Array.isArray(data) || data.package_format !== PACKAGE_FORMAT || !Array.isArray(data.items)) {
    return { ok: false, error: 'package_not_studio' };
  }
  return { ok: true, package: data, items: data.items.length };
}

/**
 * The `import_package` request body. Replace mode targets the page being
 * edited (its uuid) at the revision the user is looking at.
 */
export function importRequest({ pkg, mode = MODES.CREATE, includeTokens = false, page = null, revisionId = null, dryRun }) {
  const body = { package: pkg, dry_run: dryRun === true, mode, include_tokens: includeTokens === true };
  if (mode === MODES.REPLACE) {
    body.target_page_id = page && Number.isInteger(page.id) ? page.id : null;
    body.expected_revision_id = revisionId;
  }
  return body;
}

/** UTF-8 byte length (the server's budgets are in bytes). */
export function byteLength(text) {
  return new TextEncoder().encode(String(text)).length;
}

/**
 * Check the text of a chosen HTML or CSS file. Never throws. The browser
 * never interprets the markup: it is sent as text and converted, filtered
 * and validated on the server.
 * @returns {{ ok: true, text: string } | { ok: false, error: string }}
 */
export function checkSourceText(text, maxBytes, { allowEmpty = false } = {}) {
  if (typeof text !== 'string') return { ok: false, error: 'source_empty' };
  if (!allowEmpty && text.trim() === '') return { ok: false, error: 'source_empty' };
  if (byteLength(text) > maxBytes) return { ok: false, error: 'source_too_large' };
  return { ok: true, text };
}

/** A page address suggestion from a title (the server validates the slug). */
export function slugify(text) {
  return String(text || '')
    .normalize('NFKD').replace(/[\u0300-\u036f]/g, '')
    .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 120);
}

/**
 * The `import_html` request body (Phase 8B). Create mode names the new
 * page's title and slug; replace mode targets the page being edited at the
 * revision the user is looking at (it keeps that page's address).
 */
export function htmlImportRequest({ html, css = '', title = '', slug = '', mode = MODES.CREATE, page = null, revisionId = null, dryRun }) {
  const body = { html: String(html || ''), css: String(css || ''), dry_run: dryRun === true, mode };
  const cleanTitle = String(title || '').trim();
  if (cleanTitle !== '') body.title = cleanTitle;
  if (mode === MODES.REPLACE) {
    body.target_page_id = page && Number.isInteger(page.id) ? page.id : null;
    body.expected_revision_id = revisionId;
  } else {
    body.slug = String(slug || '').trim();
  }
  return body;
}

/** A key identifying the inputs an analysis was made for (everything except dry_run). */
export function analysisKey(body) {
  const { dry_run: _ignored, ...rest } = body;
  return JSON.stringify(rest);
}

/**
 * Commit is allowed only for a dry-run report that said so, for exactly the
 * inputs being committed.
 */
export function canImport(report, analyzedKey, currentKey) {
  return !!report && report.dry_run === true && report.can_commit === true && typeof analyzedKey === 'string' && analyzedKey === currentKey;
}

/** Errors and warnings of a report, in the server's (deterministic) order. */
export function splitIssues(report) {
  const issues = report && Array.isArray(report.issues) ? report.issues : [];
  return {
    errors: issues.filter((i) => i && i.severity === 'error'),
    warnings: issues.filter((i) => i && i.severity !== 'error'),
  };
}

/** The report inside an import response, whether it succeeded (200/201) or was refused (422). */
export function reportOf(res) {
  if (!res) return null;
  if (res.ok) return res.data && res.data.report ? res.data.report : null;
  return res.error && res.error.details && res.error.details.report ? res.error.details.report : null;
}

/** Query of `export_package`. */
export function exportQuery(pageId, { components = true, template = true, tokens = false } = {}) {
  return { page: pageId, include_components: components ? 1 : 0, include_template: template ? 1 : 0, include_tokens: tokens ? 1 : 0 };
}

/** A safe download file name. */
export function packageFileName(name) {
  const base = String(name || 'kohevo-studio-package.json').replace(/[^a-z0-9._-]+/gi, '-').replace(/^-+|-+$/g, '').slice(0, 120);
  return base.endsWith('.json') ? base : `${base || 'kohevo-studio-package'}.json`;
}

/** Offer a package as a file download (browser only). */
export function downloadPackage(pkg, name, doc = globalThis.document) {
  if (!doc || typeof Blob === 'undefined' || typeof URL === 'undefined' || !URL.createObjectURL) return false;
  const blob = new Blob([JSON.stringify(pkg, null, 2)], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const a = doc.createElement('a');
  a.href = url;
  a.download = packageFileName(name);
  a.rel = 'noopener';
  doc.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 0);
  return true;
}
