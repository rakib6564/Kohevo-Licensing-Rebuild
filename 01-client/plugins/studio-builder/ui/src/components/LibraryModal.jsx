// LibraryModal — the template library as a large window: a tab per kind of template (and one for global
// components), a search box and a grid of cards with a thumbnail and one action. Same data and the same commands
// as the Library tab in the side panel; this is the roomy way to browse them.

import { memo, useMemo, useState } from 'react';
import { Dialog } from './Dialog.jsx';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { t } from '../core/messages.mjs';
import { asList } from '../core/doc.mjs';
import { filterTemplates, groupTemplates, templateAction } from '../core/library.mjs';
import { STATUS } from '../core/sync.mjs';

export const LibraryModal = memo(function LibraryModal({ onClose }) {
  const { manifest, library = null, applyTemplate, insertTemplate, insertComponentRef } = useEditor();
  const page = useEngineState((s) => s.page);
  const status = useEngineState((s) => s.status);
  const blocked = status === STATUS.CONFLICT || status === STATUS.LOADING;
  const pageType = page ? page.page_type : 'page';
  const canReference = asList(manifest && manifest.components && manifest.components.referencing_types).includes(pageType);
  const groups = useMemo(() => groupTemplates(library && library.templates, pageType), [library, pageType]);
  const components = asList(library && library.components);
  const tabs = [...groups.map((g) => ({ key: g.type, label: t(g.label), count: g.items.length })), { key: 'components', label: t('library_components'), count: components.length }];
  const firstFilled = tabs.find((x) => x.count > 0);
  // Until the author picks a tab, show the first one that has something (the library may load after the window opens).
  const [picked, setPicked] = useState(null);
  const tab = picked || (firstFilled ? firstFilled.key : tabs[0].key);
  const setTab = setPicked;
  const [query, setQuery] = useState('');

  const run = async (action) => { await action(); onClose(); };
  const group = groups.find((g) => g.type === tab);
  const shown = group ? filterTemplates(group.items, query) : components.filter((c) => !query.trim() || String(c.title).toLowerCase().includes(query.trim().toLowerCase()));

  return (
    <Dialog title={t('library_modal_title')} onClose={onClose} className="sbx-dialog--library">
      {!library ? <p className="sbx-muted">{t('loading')}</p> : (
        <div className="sbx-libmodal" data-testid="library-modal">
          <div className="sbx-libmodal__bar">
            <div className="sbx-libmodal__tabs" role="tablist" aria-label={t('library_modal_title')}>
              {tabs.map((x) => (
                <button key={x.key} type="button" role="tab" aria-selected={tab === x.key} data-lib-tab={x.key} className="sbx-libmodal__tab" onClick={() => setTab(x.key)}>
                  {x.label}<span className="sbx-libmodal__count">{x.count}</span>
                </button>
              ))}
            </div>
            <input type="search" className="sbx-libmodal__search" data-testid="library-search" placeholder={t('library_search')} aria-label={t('library_search')} value={query} onChange={(e) => setQuery(e.target.value)} />
          </div>
          {shown.length === 0 ? <p className="sbx-hint">{query.trim() ? t('library_no_match') : t(group ? 'library_empty_templates' : 'library_empty_components')}</p> : (
            <ul className="sbx-libmodal__grid">
              {group ? shown.map((tpl) => (
                <li key={tpl.template_key} className="sbx-libmodal__card" data-template={tpl.template_key}>
                  {tpl.thumbnail_url ? <img className="sbx-libmodal__thumb" src={tpl.thumbnail_url} alt="" /> : <span className="sbx-libmodal__thumb sbx-libmodal__thumb--empty" aria-hidden="true" />}
                  <div className="sbx-libmodal__meta">
                    <strong>{tpl.name}</strong>
                    {tpl.summary && <span className="sbx-muted">{t('library_summary', { sections: tpl.summary.sections, blocks: tpl.summary.blocks })}</span>}
                  </div>
                  <button type="button" className="sbx-btn sbx-btn--primary sbx-btn--xs sbx-libmodal__action" data-testid="library-insert" disabled={blocked}
                    onClick={() => run(() => (templateAction(tpl) === 'apply' ? applyTemplate(tpl) : insertTemplate(tpl)))}>
                    {templateAction(tpl) === 'apply' ? t('library_apply') : t('library_insert')}
                  </button>
                </li>
              )) : shown.map((c) => (
                <li key={c.ref} className="sbx-libmodal__card" data-component-ref={c.ref}>
                  <span className="sbx-libmodal__thumb sbx-libmodal__thumb--empty" aria-hidden="true" />
                  <div className="sbx-libmodal__meta">
                    <strong>{c.title}</strong>
                    <span className="sbx-muted">{t('library_usage', { count: c.usage_count })}</span>
                  </div>
                  {canReference && <button type="button" className="sbx-btn sbx-btn--primary sbx-btn--xs sbx-libmodal__action" disabled={blocked} onClick={() => run(() => insertComponentRef(c))}>{t('library_insert_ref')}</button>}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </Dialog>
  );
});
