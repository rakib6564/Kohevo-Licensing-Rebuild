// ComponentsPanel — Kohevo components (module-backed blocks, shown only when licensed) and the
// site's global components (live references). Nothing here is a placeholder: every card inserts
// the real thing, or is absent.

import { memo } from 'react';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { BlockCard, usePresetInsertState } from './AddPanelParts.jsx';
import { t } from '../core/messages.mjs';
import { asList } from '../core/doc.mjs';
import { isComponentBlock } from '../core/addPanel.mjs';
import { STATUS } from '../core/sync.mjs';

export const ComponentsPanel = memo(function ComponentsPanel() {
  const { manifest, library = null, insertBlock, insertComponentRef } = useEditor();
  const page = useEngineState((s) => s.page);
  const status = useEngineState((s) => s.status);
  const presetState = usePresetInsertState();
  const pageType = page ? page.page_type : 'page';
  const canReference = asList(manifest && manifest.components && manifest.components.referencing_types).includes(pageType);
  const blocked = status === STATUS.CONFLICT || status === STATUS.LOADING;
  const kohevo = asList(manifest.blocks).filter(isComponentBlock);
  const components = asList(library && library.components);

  return (
    <div className="sbx-components" data-testid="palette-components">
      <section aria-labelledby="sbx-comp-kohevo">
        <h3 id="sbx-comp-kohevo" className="sbx-palette__category">{t('pal_components_kohevo')}</h3>
        {kohevo.length === 0 && <p className="sbx-muted">{t('pal_components_none_licensed')}</p>}
        <div className="sbx-palette__cards">
          {kohevo.map((b) => <BlockCard key={b.type} def={b} onInsert={insertBlock} />)}
        </div>
      </section>

      <section aria-labelledby="sbx-comp-global">
        <h3 id="sbx-comp-global" className="sbx-palette__category">{t('library_components')}</h3>
        {components.length === 0 && <p className="sbx-muted">{t('pal_components_none_global')}</p>}
        <ul className="sbx-library__list">
          {components.map((c) => (
            <li key={c.ref} className="sbx-library__item" data-component-ref={c.ref}>
              <div className="sbx-library__body">
                <div className="sbx-library__title"><strong>{c.title}</strong></div>
                <p className="sbx-muted sbx-library__meta">{c.is_published ? t('library_component_published') : t('library_component_unpublished')}</p>
                {canReference && (
                  <button type="button" className="sbx-btn sbx-btn--xs" disabled={blocked || !presetState.ok} onClick={() => insertComponentRef(c)}>
                    {t('library_insert_ref')}
                  </button>
                )}
              </div>
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
});
