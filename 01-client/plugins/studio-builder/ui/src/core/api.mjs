// Kohevo Studio builder — command/query API client.
//
// A thin wrapper over the builder endpoint (admin/api.php). It never throws:
// every call resolves to { ok: true, status, data } or
// { ok: false, status, error: { code, message, details? } } with a SAFE code
// from the server's fixed vocabulary (or `network_error` / `timeout` /
// `server_error` when the server could not be reached or answered badly).
//
// Commands carry the session CSRF token in the X-CSRF-Token header (the
// platform's own csrf_verify() convention) and are same-origin only.

const TIMEOUT_MS = 20000;

export function createTransport({ apiUrl, csrfToken, fetchImpl, timeoutMs = TIMEOUT_MS }) {
  const doFetch = fetchImpl || ((...a) => globalThis.fetch(...a));

  async function call(method, action, { query = {}, body = null, keepalive = false } = {}) {
    const url = new URL(apiUrl, globalThis.location ? globalThis.location.href : 'http://localhost/');
    url.searchParams.set('action', action);
    for (const [k, v] of Object.entries(query)) {
      if (v !== undefined && v !== null) url.searchParams.set(k, String(v));
    }
    const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    const timer = controller ? setTimeout(() => controller.abort(), timeoutMs) : null;
    const init = {
      method,
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
      signal: controller ? controller.signal : undefined,
      keepalive,
    };
    if (method === 'POST') {
      init.headers['Content-Type'] = 'application/json';
      init.headers['X-CSRF-Token'] = csrfToken;
      init.body = JSON.stringify(body || {});
    }
    let res;
    try {
      res = await doFetch(url.toString(), init);
    } catch (e) {
      const aborted = e && e.name === 'AbortError';
      return { ok: false, status: 0, error: { code: aborted ? 'timeout' : 'network_error', message: '' } };
    } finally {
      if (timer) clearTimeout(timer);
    }
    let json = null;
    try {
      json = await res.json();
    } catch {
      json = null;
    }
    if (json && json.ok === true && res.status >= 200 && res.status < 300) {
      return { ok: true, status: res.status, data: json.data || {} };
    }
    const error = json && json.ok === false && json.error && typeof json.error.code === 'string'
      ? json.error
      : { code: 'server_error', message: '' };
    return { ok: false, status: res.status, error };
  }

  return {
    bootstrap: (pageId) => call('GET', 'bootstrap', { query: { page: pageId } }),
    document: (pageId) => call('GET', 'document', { query: { page: pageId } }),
    status: (pageId) => call('GET', 'status', { query: { page: pageId } }),
    revisions: (pageId, limit = 30) => call('GET', 'revisions', { query: { page: pageId, limit } }),
    operations: (body) => call('POST', 'operations', { body }),
    // One unsaved block rendered as the canvas shows it (nothing is stored): for a block the author has just inserted.
    renderBlock: (pageId, block) => call('POST', 'render_block', { body: { page_id: pageId, block } }),
    renderSection: (pageId, section) => call('POST', 'render_section', { body: { page_id: pageId, section } }),
    saveDraft: (body) => call('POST', 'save_draft', { body }),
    publish: (body) => call('POST', 'publish', { body }),
    rollback: (body) => call('POST', 'rollback', { body }),
    lockAcquire: (pageId) => call('POST', 'lock_acquire', { body: { page_id: pageId } }),
    lockRefresh: (pageId, token) => call('POST', 'lock_refresh', { body: { page_id: pageId, lock_token: token } }),
    lockRelease: (pageId, token) => call('POST', 'lock_release', { body: { page_id: pageId, lock_token: token }, keepalive: true }),
    // Phase 6 — template library, global components, chrome bindings, design tokens.
    manifest: () => call('GET', 'manifest'),
    templates: (type = null) => call('GET', 'templates', { query: type ? { type } : {} }),
    sectionPresets: () => call('GET', 'section_presets'),
    components: () => call('GET', 'components'),
    // Pages: the tenant's list and the page commands (rename = title and/or slug, duplicate, archive).
    pages: () => call('GET', 'pages'),
    createPage: (body) => call('POST', 'create_page', { body }),
    updatePage: (body) => call('POST', 'update_page', { body }),
    duplicatePage: (body) => call('POST', 'duplicate_page', { body }),
    archivePage: (body) => call('POST', 'archive_page', { body }),
    chrome: (pageId) => call('GET', 'chrome', { query: { page: pageId } }),
    tokens: (group = 'default') => call('GET', 'tokens', { query: { group } }),
    applyTemplate: (body) => call('POST', 'apply_template', { body }),
    insertTemplate: (body) => call('POST', 'insert_template', { body }),
    saveTemplate: (body) => call('POST', 'save_template', { body }),
    deleteTemplate: (body) => call('POST', 'delete_template', { body }),
    createComponent: (body) => call('POST', 'create_component', { body }),
    detachComponent: (body) => call('POST', 'detach_component', { body }),
    saveTokens: (body) => call('POST', 'save_tokens', { body }),
    // Element Manager (administrators): the blocks a site offers and where each is used; switch types off or on.
    elements: () => call('GET', 'elements'),
    saveElements: (body) => call('POST', 'save_elements', { body }),
    // Site-wide custom CSS (administrators).
    customCss: () => call('GET', 'custom_css'),
    saveCustomCss: (body) => call('POST', 'save_custom_css', { body }),
    // Phase 7 — structured diff for reviewing an AI-proposed draft.
    diff: (pageId, base = null, proposed = null) => call('GET', 'diff', { query: { page: pageId, base, proposed } }),
    // Phase 8A — JSON package export (query) and import (one command; `dry_run` = analysis only).
    exportPackage: (query) => call('GET', 'export_package', { query }),
    importPackage: (body) => call('POST', 'import_package', { body }),
    // Phase 8B — constrained HTML/CSS import (same dry-run / import-into-draft contract).
    importHtml: (body) => call('POST', 'import_html', { body }),
  };
}
