// PagesPanel — the tenant's pages: status chips, the page being edited, and create, rename,
// duplicate and archive. Every command is the builder API's own (permissions, tenant scope,
// slug rules and audit are enforced there); this panel only asks and shows the answer.

import { memo, useCallback, useEffect, useState } from 'react';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { Dialog } from './Dialog.jsx';
import { t, errorMessage } from '../core/messages.mjs';
import { slugify } from '../core/library.mjs';
import { copyNames, failureDetail, isValidSlug, listablePages, pageChips, renameChanges } from '../core/pages.mjs';

/** Title and address form shared by "new", "rename" and "duplicate". */
function PageForm({ heading, hint, warn, initial, submitLabel, onSubmit, onClose, slugFromTitle }) {
  const [title, setTitle] = useState(initial.title);
  const [slug, setSlug] = useState(initial.slug);
  const [slugTouched, setSlugTouched] = useState(!slugFromTitle);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const effectiveSlug = slugTouched ? slug : slugify(title, 191);
  const valid = title.trim() !== '' && isValidSlug(effectiveSlug);

  const submit = async () => {
    if (!valid || busy) return;
    setBusy(true);
    setError(null);
    const message = await onSubmit({ title: title.trim(), slug: effectiveSlug });
    setBusy(false);
    if (message) setError(message);
  };

  return (
    <Dialog
      title={heading}
      onClose={onClose}
      footer={(
        <>
          <button type="button" className="sbx-btn" onClick={onClose}>{t('cancel')}</button>
          <button type="button" className="sbx-btn sbx-btn--primary" disabled={!valid || busy} onClick={submit} data-testid="page-form-submit">{submitLabel}</button>
        </>
      )}
    >
      {hint && <p className="sbx-hint">{hint}</p>}
      <div className="sbx-field">
        <label className="sbx-field__label" htmlFor="sbx-page-title">{t('nav_page_name')}</label>
        <input id="sbx-page-title" type="text" maxLength={255} value={title} onChange={(e) => setTitle(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') submit(); }} />
      </div>
      <div className="sbx-field">
        <label className="sbx-field__label" htmlFor="sbx-page-slug">{t('nav_page_address')}</label>
        <input id="sbx-page-slug" type="text" maxLength={191} value={effectiveSlug} onChange={(e) => { setSlugTouched(true); setSlug(slugify(e.target.value, 191)); }} onKeyDown={(e) => { if (e.key === 'Enter') submit(); }} />
      </div>
      {warn && <p className="sbx-hint" role="note">{warn}</p>}
      {error && <p className="sbx-error" role="alert" data-testid="page-form-error">{error}</p>}
    </Dialog>
  );
}

export const PagesPanel = memo(function PagesPanel() {
  const { boot, transport, announce } = useEditor();
  const pendingCount = useEngineState((s) => s.pending.length);
  const [pages, setPages] = useState(null);
  const [failed, setFailed] = useState(false);
  const [form, setForm] = useState(null);
  const current = Number(boot.pageId);

  const load = useCallback(async () => {
    setFailed(false);
    const res = await transport.pages();
    if (res.ok) setPages(listablePages(res.data.pages));
    else setFailed(true);
  }, [transport]);
  useEffect(() => { load(); }, [load]);

  const say = (key, title) => { if (announce) announce(t(key, { title })); };
  const fail = (res) => failureDetail(res.error) || errorMessage(res.error);

  const goTo = (id) => {
    if (Number(id) === current) return;
    if (pendingCount > 0 && !window.confirm(t('nav_page_unsaved'))) return;
    window.location.href = `${boot.builderUrl}?page=${id}`;
  };

  const create = async ({ title, slug }) => {
    const res = await transport.createPage({ title, slug, page_type: 'page', route_mode: 'standalone' });
    if (!res.ok) return fail(res);
    say('nav_announce_created', title);
    setForm(null);
    goTo(res.data.page.id);
    return null;
  };

  const rename = (page) => async ({ title, slug }) => {
    const changes = renameChanges(page, title, slug);
    if (!Object.keys(changes).length) { setForm(null); return null; }
    if (Number(page.id) === current && pendingCount > 0) return t('nav_page_save_first');
    const res = await transport.updatePage({ page_id: page.id, ...changes });
    if (!res.ok) return fail(res);
    say('nav_announce_renamed', title);
    setForm(null);
    if (Number(page.id) === current) { window.location.href = `${boot.builderUrl}?page=${page.id}`; return null; } // the top bar reads the title once, at load
    await load();
    return null;
  };

  const duplicate = (page) => async ({ title, slug }) => {
    const res = await transport.duplicatePage({ page_id: page.id, title, slug });
    if (!res.ok) return fail(res);
    say('nav_announce_duplicated', title);
    setForm(null);
    await load();
    return null;
  };

  const archive = async (page) => {
    if (!window.confirm(t(page.is_published ? 'nav_page_archive_confirm_published' : 'nav_page_archive_confirm', { title: page.title }))) return;
    const res = await transport.archivePage({ page_id: page.id });
    if (!res.ok) { announce && announce(fail(res)); return; }
    say('nav_announce_archived', page.title);
    await load();
  };

  return (
    <div className="sbx-pages" data-testid="pages-panel">
      <div className="sbx-pages__head">
        <button type="button" className="sbx-btn sbx-btn--primary sbx-btn--block" data-testid="page-new" onClick={() => setForm({ kind: 'new' })}>+ {t('nav_page_new')}</button>
      </div>

      {pages === null && !failed && <p className="sbx-muted" role="status">{t('nav_pages_loading')}</p>}
      {failed && (
        <p className="sbx-error" role="alert">
          {t('nav_pages_failed')} <button type="button" className="sbx-link-btn" onClick={load}>{t('retry')}</button>
        </p>
      )}
      {pages && pages.length === 0 && <p className="sbx-muted" data-testid="pages-empty">{t('nav_pages_empty')}</p>}

      {pages && pages.length > 0 && (
        <ul className="sbx-pages__list" aria-label={t('nav_pages_list')}>
          {pages.map((p) => {
            const isCurrent = Number(p.id) === current;
            return (
              <li key={p.id} className={`sbx-page-row${isCurrent ? ' is-current' : ''}`} data-page-id={p.id} aria-current={isCurrent ? 'page' : undefined}>
                <button type="button" className="sbx-page-row__main" onClick={() => goTo(p.id)} disabled={isCurrent} aria-label={isCurrent ? `${p.title}, ${t('nav_page_current')}` : t('nav_page_open', { title: p.title })}>
                  <span className="sbx-page-row__title">{p.title}</span>
                  <span className="sbx-page-row__path">{p.public_path}</span>
                  <span className="sbx-page-row__chips">
                    {isCurrent && <span className="sbx-pill sbx-pill--current">{t('nav_page_current')}</span>}
                    {pageChips(p).map((c) => <span key={c} className={`sbx-pill sbx-pill--${c}`} data-chip-kind={c}>{t(c === 'homepage' ? 'nav_page_homepage' : `nav_status_${c}`)}</span>)}
                  </span>
                </button>
                <div className="sbx-page-row__actions" role="group" aria-label={t('nav_page_actions', { title: p.title })}>
                  <button type="button" className="sbx-btn sbx-btn--seg" data-action="rename" onClick={() => setForm({ kind: 'rename', page: p })}>{t('nav_page_rename')}</button>
                  <button type="button" className="sbx-btn sbx-btn--seg" data-action="duplicate" onClick={() => setForm({ kind: 'duplicate', page: p })}>{t('nav_page_duplicate')}</button>
                  <button type="button" className="sbx-btn sbx-btn--seg sbx-btn--danger" data-action="archive" disabled={isCurrent} title={isCurrent ? t('nav_page_archive_current') : undefined} onClick={() => archive(p)}>{t('nav_page_archive')}</button>
                </div>
              </li>
            );
          })}
        </ul>
      )}

      {form && form.kind === 'new' && (
        <PageForm heading={t('nav_page_new_title')} initial={{ title: '', slug: '' }} slugFromTitle submitLabel={t('nav_page_create')} onSubmit={create} onClose={() => setForm(null)} />
      )}
      {form && form.kind === 'rename' && (
        <PageForm heading={t('nav_page_rename_title')} initial={{ title: form.page.title, slug: form.page.slug }} warn={form.page.is_published ? t('nav_page_address_warn') : null} submitLabel={t('nav_page_save')} onSubmit={rename(form.page)} onClose={() => setForm(null)} />
      )}
      {form && form.kind === 'duplicate' && (
        <PageForm heading={t('nav_page_dup_title')} hint={t('nav_page_dup_hint')} initial={copyNames(form.page, t('nav_page_copy_suffix'))} submitLabel={t('nav_page_duplicate')} onSubmit={duplicate(form.page)} onClose={() => setForm(null)} />
      )}
    </div>
  );
});
