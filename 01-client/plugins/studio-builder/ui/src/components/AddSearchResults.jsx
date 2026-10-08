// AddSearchResults — one search over every Add-panel tab: sections, elements, dynamic blocks and
// components, each under its own heading with a count. Typing in the search box shows this;
// clearing it returns to the tabs.

import { memo, useMemo } from 'react';
import { useEditor } from './EditorContext.jsx';
import { BlockCard, blockCategoryLabel, usePresetInsertState } from './AddPanelParts.jsx';
import { PresetCard, categoryLabel } from './SectionPresetsPanel.jsx';
import { t } from '../core/messages.mjs';
import { searchAll } from '../core/addPanel.mjs';

export const AddSearchResults = memo(function AddSearchResults({ query, favorites, onToggleFavorite }) {
  const { manifest, library = null, insertBlock, insertComponentRef } = useEditor();
  const presetState = usePresetInsertState();
  const found = useMemo(
    () => searchAll(
      { blocks: manifest.blocks, presets: library && library.presets, components: library && library.components },
      query,
      { blockCategory: blockCategoryLabel, presetCategory: categoryLabel },
    ),
    [manifest.blocks, library, query],
  );

  if (found.total === 0) return <p className="sbx-palette__empty sbx-muted" role="status" data-testid="search-empty">{t('pal_no_results')}</p>;

  const heading = (key, count) => <h3 className="sbx-palette__category">{t(key)} <span className="sbx-chip__count" aria-hidden="true">{count}</span></h3>;
  return (
    <div className="sbx-search-results" data-testid="add-search-results" role="region" aria-label={t('pal_search_results')}>
      {found.presets.length > 0 && (
        <section>
          {heading('pal_tab_sections', found.presets.length)}
          <ul className="sbx-presets__list">
            {found.presets.map((p) => <PresetCard key={p.template_key} preset={p} fav={favorites.has(p.template_key)} onToggleFavorite={onToggleFavorite} />)}
          </ul>
        </section>
      )}
      {found.elements.length > 0 && (
        <section>
          {heading('pal_tab_elements', found.elements.length)}
          <div className="sbx-palette__cards">{found.elements.map((b) => <BlockCard key={b.type} def={b} onInsert={insertBlock} />)}</div>
        </section>
      )}
      {found.dynamic.length > 0 && (
        <section>
          {heading('palette_dynamic', found.dynamic.length)}
          <div className="sbx-palette__cards">{found.dynamic.map((b) => <BlockCard key={b.type} def={b} onInsert={insertBlock} />)}</div>
        </section>
      )}
      {found.components.length > 0 && (
        <section>
          {heading('pal_tab_components', found.components.length)}
          <div className="sbx-palette__cards">
            {found.components.map((c) => (c.kind === 'block'
              ? <BlockCard key={c.block.type} def={c.block} onInsert={insertBlock} />
              : (
                <button key={c.component.ref} type="button" className="sbx-palette-card" disabled={!presetState.ok} onClick={() => insertComponentRef(c.component)}>
                  <div className="sbx-palette-card__content">
                    <span className="sbx-palette-card__title">{c.component.title}</span>
                    <span className="sbx-palette-card__desc">{t('library_insert_ref')}</span>
                  </div>
                </button>
              )))}
          </div>
        </section>
      )}
    </div>
  );
});
