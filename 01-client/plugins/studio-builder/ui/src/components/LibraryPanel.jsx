// LibraryPanel — the template library and the global components of this site.
//
//   Templates          reusable COPIES (server: studiobuilder_templates).
//                      "Apply to page" replaces the page document (apply_template,
//                      with expected_revision_id); "Insert copy" adds an owned copy
//                      through the operation pipeline (insert_template).
//   Global components  LIVE references (server: section_preset pages). "Insert
//                      reference" adds a section with global_ref = component ref;
//                      the page owns no copy and renders the component's published
//                      version. Editing happens in the component itself.
//
// Everything shown here is transport-safe data from the server (names,
// summaries, thumbnail URLs resolved by the tenant-scoped media resolver);
// no document body ever reaches this panel.

import { memo } from 'react';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { t } from '../core/messages.mjs';
import { asList } from '../core/doc.mjs';
import { groupTemplates } from '../core/library.mjs';
import { STATUS } from '../core/sync.mjs';

export const LibraryPanel = memo(function LibraryPanel() {
  const { manifest, library = null, refreshLibrary, applyTemplate, insertTemplate, deleteTemplate, insertComponentRef, publishComponent, openSaveTemplate, openComponentDialog, boot } = useEditor();
  const page = useEngineState((s) => s.page);
  const status = useEngineState((s) => s.status);
  const working = useEngineState((s) => s.working);
  const blocked = status === STATUS.CONFLICT || status === STATUS.LOADING;
  const perms = (manifest && manifest.permissions) || {};
  const pageType = page ? page.page_type : 'page';
  const canReference = asList(manifest && manifest.components && manifest.components.referencing_types).includes(pageType);

  if (!library) {
    return <p className="sbx-muted">{t('loading')}</p>;
  }

  const groups = groupTemplates(library.templates, pageType);
  const components = asList(library.components);

  return (
    <div className="sbx-library">
      {library.error && <p role="alert" className="sbx-field__error">{library.error}</p>}

      <section className="sbx-library__section" aria-labelledby="sbx-lib-templates">
        <div className="sbx-library__head">
          <h3 id="sbx-lib-templates" className="sbx-palette__category">{t('library_templates')}</h3>
          {perms.admin && <button type="button" className="sbx-btn sbx-btn--xs" onClick={openSaveTemplate} disabled={blocked}>{t('library_save_template')}</button>}
        </div>
        {groups.every((g) => g.items.length === 0) && <p className="sbx-hint">{t('library_empty_templates')}</p>}
        {groups.filter((g) => g.items.length).map((g) => (
          <div key={g.type} className="sbx-library__group">
            <h4 className="sbx-library__subhead">{t(g.label)}</h4>
            <ul className="sbx-library__list">
              {g.items.map((tpl) => (
                <li key={tpl.template_key} className="sbx-library__item">
                  {tpl.thumbnail_url ? <img className="sbx-library__thumb" src={tpl.thumbnail_url} alt="" /> : <span className="sbx-library__thumb sbx-library__thumb--empty" aria-hidden="true" />}
                  <div className="sbx-library__body">
                    <div className="sbx-library__title">
                      <strong>{tpl.name}</strong>
                      {tpl.is_system && <span className="sbx-badge" title={t('library_system')}>{t('library_system')}</span>}
                    </div>
                    {tpl.description && <p className="sbx-library__desc">{tpl.description}</p>}
                    {tpl.summary && (
                      <p className="sbx-muted sbx-library__meta">
                        {t('library_summary', { sections: tpl.summary.sections, blocks: tpl.summary.blocks })}
                        {asList(tpl.summary.block_labels).length ? ` · ${tpl.summary.block_labels.join(', ')}` : ''}
                      </p>
                    )}
                    <div className="sbx-library__actions">
                      {tpl.template_type === 'page_template'
                        ? <button type="button" className="sbx-btn sbx-btn--xs" disabled={blocked} onClick={() => applyTemplate(tpl)}>{t('library_apply')}</button>
                        : <button type="button" className="sbx-btn sbx-btn--xs" disabled={blocked} onClick={() => insertTemplate(tpl)}>{t('library_insert')}</button>}
                      {perms.admin && !tpl.is_system && (
                        <button type="button" className="sbx-btn sbx-btn--xs sbx-btn--danger" disabled={blocked} onClick={() => deleteTemplate(tpl)}>{t('library_delete')}</button>
                      )}
                    </div>
                  </div>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </section>

      <section className="sbx-library__section" aria-labelledby="sbx-lib-components">
        <div className="sbx-library__head">
          <h3 id="sbx-lib-components" className="sbx-palette__category">{t('library_components')}</h3>
          {perms.edit && canReference && <button type="button" className="sbx-btn sbx-btn--xs" onClick={openComponentDialog} disabled={blocked || !working}>{t('library_new_component')}</button>}
        </div>
        {components.length === 0 && <p className="sbx-hint">{t('library_empty_components')}</p>}
        {components.length > 0 && (
          <ul className="sbx-library__list">
            {components.map((c) => (
              <li key={c.ref} className="sbx-library__item" data-component-ref={c.ref}>
                <div className="sbx-library__body">
                  <div className="sbx-library__title"><strong>{c.title}</strong></div>
                  <p className="sbx-muted sbx-library__meta">
                    {c.is_published ? (c.has_unpublished_changes ? t('library_component_changes') : t('library_component_published')) : t('library_component_unpublished')}
                    {' · '}{t('library_usage', { count: c.usage_count })}
                  </p>
                  <div className="sbx-library__actions">
                    {canReference && <button type="button" className="sbx-btn sbx-btn--xs" disabled={blocked} onClick={() => insertComponentRef(c)}>{t('library_insert_ref')}</button>}
                    <a className="sbx-btn sbx-btn--xs" href={`${boot.builderUrl || ''}?page=${c.id}`} target="_blank" rel="noopener">{t('library_edit_component')}</a>
                    {perms.publish && (!c.is_published || c.has_unpublished_changes) && (
                      <button type="button" className="sbx-btn sbx-btn--xs sbx-btn--primary" disabled={blocked} onClick={() => publishComponent(c)}>{t('library_publish_component')}</button>
                    )}
                  </div>
                </div>
              </li>
            ))}
          </ul>
        )}
        <button type="button" className="sbx-btn sbx-btn--xs sbx-btn--ghost" onClick={refreshLibrary}>↻</button>
      </section>
    </div>
  );
});
