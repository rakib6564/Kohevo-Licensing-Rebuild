// BlockPalette — insertable blocks from the server manifest rendered as
// premium dark cards with green-tinted stroke outline icons, clean microcopy,
// drag & drop support, and single-click insertion.

import { memo, useMemo, useState } from 'react';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { t } from '../core/messages.mjs';
import { asList } from '../core/doc.mjs';
import { isAiRevision } from '../core/review.mjs';
import { SectionPresetsPanel } from './SectionPresetsPanel.jsx';
import { isDynamicBlock } from './blockKinds.mjs';
import { isOffered } from '../core/addPanel.mjs';
import { ElementsPanel } from './ElementsPanel.jsx';
import { ComponentsPanel } from './ComponentsPanel.jsx';
import { AddSearchResults } from './AddSearchResults.jsx';
import { IconPlus, IconSearch } from './Icons.jsx';
import { Pills } from './ui/index.js';
import { DRAG_TYPE_NEW, renderBlockIcon } from './blockIcons.jsx';

export { DRAG_TYPE_NEW };
export { isDynamicBlock };

function providerNote(def) {
  const providers = asList(def.binding_slots).map((b) => b.provider);
  return providers.length ? `${t('dynamic_data_from')} ${providers.join(', ')}` : t('dynamic_page_context');
}

function PaletteCard({ def, query, onInsert }) {
  return (
    <button
      type="button"
      className="sbx-palette-card"
      draggable
      onDragStart={(e) => { e.dataTransfer.setData(DRAG_TYPE_NEW, def.type); e.dataTransfer.effectAllowed = 'copy'; }}
      onClick={() => onInsert(def.type)}
      aria-label={`${t('insert')} ${def.title || def.label}`}
      data-query={query}
    >
      <div className="sbx-palette-card__icon-badge" aria-hidden="true">{renderBlockIcon(def.type, def.icon, def.label)}</div>
      <div className="sbx-palette-card__content">
        <span className="sbx-palette-card__title">{def.title || def.label}</span>
        <span className="sbx-palette-card__desc">{isDynamicBlock(def) ? providerNote(def) : (def.description || '')}</span>
      </div>
      <span className="sbx-palette-card__plus-btn" aria-hidden="true"><IconPlus size={14} /></span>
    </button>
  );
}

/** Dynamic tab: only the data-bound and page-context blocks, with where their data comes from. */
function DynamicPanel({ manifest, query, insertBlock }) {
  const q = query.trim().toLowerCase();
  const items = asList(manifest.blocks).filter((b) => isOffered(b) && isDynamicBlock(b)
    && (!q || `${b.label} ${b.type} ${providerNote(b)}`.toLowerCase().includes(q)));
  return (
    <div className="sbx-palette__cards" data-testid="palette-dynamic">
      <p className="sbx-palette__note">{t('dynamic_note')}</p>
      {items.length === 0 && <p className="sbx-muted">{t('no_blocks_match')}</p>}
      {items.map((b) => <PaletteCard key={b.type} def={b} query={q} onInsert={insertBlock} />)}
    </div>
  );
}

/** Media tab: pick from the tenant media library straight into a new Image block, or insert a media block. */
function MediaPanel({ manifest, query, insertBlock, insertBlockWithProps, mediaPicker }) {
  const pickerAvailable = mediaPicker && typeof window !== 'undefined' && window.SlateMedia && typeof window.SlateMedia.open === 'function';
  const q = query.trim().toLowerCase();
  const items = asList(manifest.blocks).filter((b) => isOffered(b) && ['core.image', 'core.gallery', 'core.video'].includes(b.type)
    && (!q || `${b.label} ${b.type}`.toLowerCase().includes(q)));

  const addImage = () => {
    window.SlateMedia.open({
      types: 'image',
      onPick: (record) => {
        const mediaId = record && Number.parseInt(record.id, 10);
        if (!Number.isInteger(mediaId) || mediaId <= 0) return;
        const alt = record.alt_text || record.alt || String(record.original_name || '').replace(/\.[a-z0-9]+$/i, '').slice(0, 200);
        insertBlockWithProps('core.image', { media: { media_id: mediaId, alt, focal_point: [0.5, 0.5] } });
      },
    });
  };

  return (
    <div className="sbx-palette__cards" data-testid="palette-media">
      {pickerAvailable ? (
        <button type="button" className="sbx-btn sbx-btn--primary sbx-palette__cta" onClick={addImage}>
          <IconImage size={14} /> <span>{t('media_add_image')}</span>
        </button>
      ) : (
        <p className="sbx-palette__note">{t('media_unavailable')}</p>
      )}
      <p className="sbx-palette__note">{t('media_note')}</p>
      {items.map((b) => <PaletteCard key={b.type} def={b} query={q} onInsert={insertBlock} />)}
    </div>
  );
}

/** AI tab: AI works through the assistant and always lands as a draft you review; no in-builder modes yet. */
function AiPanel({ boot, onReview }) {
  const revision = useEngineState((s) => s.revision);
  const aiDraft = isAiRevision(revision);
  return (
    <div className="sbx-palette__cards" data-testid="palette-ai">
      <p className="sbx-palette__note">{t('ai_panel_note')}</p>
      {aiDraft && onReview && (
        <button type="button" className="sbx-btn sbx-btn--primary sbx-palette__cta" onClick={onReview}>{t('ai_review')}</button>
      )}
      {boot.assistantUrl ? (
        <a className="sbx-btn sbx-palette__cta" href={boot.assistantUrl} target="_blank" rel="noopener">{t('ai_assistant')}</a>
      ) : (
        <p className="sbx-muted">{t('ai_unavailable')}</p>
      )}
    </div>
  );
}

export const BlockPalette = memo(function BlockPalette({ compact = false }) {
  const { boot, manifest, insertBlock, insertSection, insertBlockWithProps, openAiReview } = useEditor();
  const [query, setQuery] = useState('');
  const [categoryTab, setCategoryTab] = useState('sections');
  const [favorites, setFavorites] = useState(new Set());

  const toggleFavorite = (e, key) => {
    e.stopPropagation();
    setFavorites((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  };

  const searching = query.trim() !== '';

  return (
    <div className={`sbx-palette${compact ? ' sbx-palette--compact' : ''}`}>
      {/* Search Header */}
      <div className="sbx-palette__search">
        <IconSearch size={14} className="sbx-palette__search-icon" />
        <input
          type="search"
          className="sbx-palette__search-input"
          placeholder={t('pal_search_placeholder')}
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          aria-label={t('pal_search_label')}
        />
        <span className="sbx-search-badge" aria-hidden="true">⌘ K</span>
      </div>

      <Pills
        role="tablist"
        size="sm"
        className="sbx-palette__tabs"
        label={t('pal_tab_label')}
        value={categoryTab}
        onChange={setCategoryTab}
        options={[
          { value: 'sections', label: t('pal_tab_sections') },
          { value: 'elements', label: t('pal_tab_elements') },
          { value: 'components', label: t('pal_tab_components') },
          { value: 'dynamic', label: t('palette_dynamic') },
          { value: 'media', label: t('palette_media') },
          { value: 'ai', label: t('palette_ai') },
        ].map((o) => ({ ...o, 'data-tab': o.value }))}
      />

      {searching ? (
        <AddSearchResults query={query} favorites={favorites} onToggleFavorite={toggleFavorite} />
      ) : (
        <>
          {categoryTab === 'sections' && <SectionPresetsPanel query="" favorites={favorites} onToggleFavorite={toggleFavorite} />}
          {categoryTab === 'elements' && <ElementsPanel />}
          {categoryTab === 'components' && <ComponentsPanel />}

          {categoryTab === 'dynamic' && <DynamicPanel manifest={manifest} query="" insertBlock={insertBlock} />}
          {categoryTab === 'media' && (
            <MediaPanel manifest={manifest} query="" insertBlock={insertBlock} insertBlockWithProps={insertBlockWithProps} mediaPicker={boot.mediaPicker} />
          )}
          {categoryTab === 'ai' && <AiPanel boot={boot} onReview={openAiReview} />}
        </>
      )}

    </div>
  );
});
