// BlockPalette — insertable blocks from the server manifest (already filtered
// by this tenant's entitlements and this user's permissions). Labels, icons
// and categories are data from the server, rendered as text.

import { memo, useMemo, useState } from 'react';
import { useEditor } from './EditorContext.jsx';
import { t } from '../core/messages.mjs';
import { asList } from '../core/doc.mjs';

export const DRAG_TYPE_NEW = 'application/x-kohevo-studio-block-type';

export const BlockPalette = memo(function BlockPalette({ compact = false }) {
  const { manifest, insertBlock } = useEditor();
  const [query, setQuery] = useState('');
  const groups = useMemo(() => {
    const q = query.trim().toLowerCase();
    const out = new Map();
    for (const b of asList(manifest.blocks)) {
      if (q && !`${b.label} ${b.category} ${b.type}`.toLowerCase().includes(q)) continue;
      if (!out.has(b.category)) out.set(b.category, []);
      out.get(b.category).push(b);
    }
    return [...out.entries()];
  }, [manifest.blocks, query]);

  if (compact) {
    return (
      <details className="sbx-palette sbx-palette--compact">
        <summary>{t('panel_blocks')}</summary>
        <PaletteList groups={groups} insertBlock={insertBlock} />
      </details>
    );
  }
  return (
    <div className="sbx-palette">
      <label className="sbx-field">
        <span className="sbx-field__label">{t('search_blocks')}</span>
        <input type="search" value={query} onChange={(e) => setQuery(e.target.value)} />
      </label>
      <p className="sbx-hint">{t('palette_hint')}</p>
      {groups.length === 0 ? <p className="sbx-muted">{t('no_blocks_match')}</p> : <PaletteList groups={groups} insertBlock={insertBlock} />}
    </div>
  );
});

function PaletteList({ groups, insertBlock }) {
  return groups.map(([category, blocks]) => (
    <section key={category} className="sbx-palette__group" aria-label={category}>
      <h3 className="sbx-palette__category">{category}</h3>
      <ul className="sbx-palette__list">
        {blocks.map((b) => (
          <li key={b.type}>
            <button
              type="button"
              className="sbx-palette__item"
              draggable
              onDragStart={(e) => {
                e.dataTransfer.setData(DRAG_TYPE_NEW, b.type);
                e.dataTransfer.effectAllowed = 'copy';
              }}
              onClick={() => insertBlock(b.type)}
              aria-label={`${t('insert')} ${b.label}`}
            >
              <span className="sbx-palette__icon" aria-hidden="true">{(b.icon || b.label || '?').slice(0, 1).toUpperCase()}</span>
              <span>{b.label}</span>
            </button>
          </li>
        ))}
      </ul>
    </section>
  ));
}
