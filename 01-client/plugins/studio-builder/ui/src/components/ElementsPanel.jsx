// ElementsPanel — plain building blocks grouped by category, with a category rail and "View all".

import { memo, useMemo, useState } from 'react';
import { useEditor } from './EditorContext.jsx';
import { BlockCard, CategoryRail, blockCategoryLabel } from './AddPanelParts.jsx';
import { t } from '../core/messages.mjs';
import { GROUP_PREVIEW, blocksWithVariants, groupBlocks, isElementBlock } from '../core/addPanel.mjs';

export const ElementsPanel = memo(function ElementsPanel() {
  const { manifest, insertBlock } = useEditor();
  const [cat, setCat] = useState('all');
  const groups = useMemo(() => groupBlocks(blocksWithVariants(manifest).filter(isElementBlock), '', blockCategoryLabel), [manifest.blocks, manifest.variants]);
  const active = groups.some((g) => g.category === cat) ? cat : 'all';
  const shown = active === 'all' ? groups : groups.filter((g) => g.category === active);

  return (
    <div className="sbx-elements" data-testid="palette-elements">
      <CategoryRail label={t('pal_category_rail')} groups={groups} value={active} onChange={setCat} labelOf={blockCategoryLabel} />
      {shown.map((g) => {
        const preview = active === 'all' && g.items.length > GROUP_PREVIEW;
        const items = preview ? g.items.slice(0, GROUP_PREVIEW) : g.items;
        return (
          <section key={g.category} className="sbx-elements__group" aria-labelledby={`sbx-el-cat-${g.category}`}>
            <div className="sbx-elements__head">
              <h3 id={`sbx-el-cat-${g.category}`} className="sbx-palette__category">{blockCategoryLabel(g.category)}</h3>
              {preview && (
                <button type="button" className="sbx-link-btn" data-view-all={g.category} onClick={() => setCat(g.category)}>
                  {t('pal_view_all', { count: g.items.length })}
                </button>
              )}
            </div>
            <div className="sbx-palette__cards">
              {items.map((b) => <BlockCard key={b.variantKey || b.type} def={b} onInsert={insertBlock} />)}
            </div>
          </section>
        );
      })}
    </div>
  );
});
