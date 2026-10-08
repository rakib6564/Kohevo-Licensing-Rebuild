// BlockPalette — insertable blocks from the server manifest rendered as
// premium dark cards with green-tinted stroke outline icons, clean microcopy,
// drag & drop support, and single-click insertion.

import { memo, useMemo, useState } from 'react';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { t } from '../core/messages.mjs';
import { asList } from '../core/doc.mjs';
import { isAiRevision } from '../core/review.mjs';
import {
  IconPlus,
  IconRocket,
  IconHeading,
  IconPilcrow,
  IconImage,
  IconArrowUpRight,
  IconGridPanes,
  IconGridDots,
  IconSpacer,
  IconDivider,
  IconBox,
  IconLayoutSection,
  IconVideo,
  IconGallery,
  IconForm,
  IconSearch,
  IconListCheck,
  IconQuotes,
} from './Icons.jsx';

export const DRAG_TYPE_NEW = 'application/x-kohevo-studio-block-type';

const BLOCK_META = {
  'core.hero': {
    title: 'Hero',
    desc: 'Lead with a clear proposition',
    icon: (s) => <IconRocket size={s} />,
  },
  'core.heading': {
    title: 'Heading',
    desc: 'Create hierarchy',
    icon: (s) => <IconHeading size={s} />,
  },
  'core.text': {
    title: 'Text',
    desc: 'Plain-text body copy',
    icon: (s) => <IconPilcrow size={s} />,
  },
  'core.rich_text': {
    title: 'Rich text',
    desc: 'Formatted copy & body content',
    icon: (s) => <IconPilcrow size={s} />,
  },
  'core.image': {
    title: 'Image',
    desc: 'Media library asset',
    icon: (s) => <IconImage size={s} />,
  },
  'core.button': {
    title: 'CTA banner',
    desc: 'Close with an action',
    icon: (s) => <IconArrowUpRight size={s} />,
  },
  'core.query_loop': {
    title: 'Services grid',
    desc: 'Live Service & dynamic posts',
    icon: (s) => <IconGridPanes size={s} />,
  },
  'core.feature_list': {
    title: 'Feature list',
    desc: 'Highlights & feature cards',
    icon: (s) => <IconListCheck size={s} />,
  },
  'core.container': {
    title: 'Container',
    desc: 'Inner constraint container',
    icon: (s) => <IconBox size={s} />,
  },
  'layout.section': {
    title: 'Section',
    desc: 'Full-width layout section',
    icon: (s) => <IconLayoutSection size={s} />,
  },
  'layout.container': {
    title: 'Container',
    desc: 'Constrained width container',
    icon: (s) => <IconBox size={s} />,
  },
  'layout.flex': {
    title: 'Flex',
    desc: 'Flexible row or column layout',
    icon: (s) => <IconBox size={s} />,
  },
  'layout.grid': {
    title: 'Grid',
    desc: 'Multi-column responsive grid',
    icon: (s) => <IconGridPanes size={s} />,
  },
  'core.gallery': {
    title: 'Gallery',
    desc: 'Media image gallery',
    icon: (s) => <IconGallery size={s} />,
  },
  'core.video': {
    title: 'Video',
    desc: 'Responsive video player',
    icon: (s) => <IconVideo size={s} />,
  },
  'core.form': {
    title: 'Form',
    desc: 'Interactive form builder',
    icon: (s) => <IconForm size={s} />,
  },
  'core.modal': {
    title: 'Modal',
    desc: 'Pop-up modal dialog',
    icon: (s) => <IconBox size={s} />,
  },
  'layout.offcanvas': {
    title: 'Offcanvas',
    desc: 'Slide-out navigation drawer',
    icon: (s) => <IconLayoutSection size={s} />,
  },
  'theme.post_title': {
    title: 'Post title',
    desc: 'Dynamic article heading',
    icon: (s) => <IconHeading size={s} />,
  },
  'theme.post_content': {
    title: 'Post content',
    desc: 'Dynamic post body copy',
    icon: (s) => <IconPilcrow size={s} />,
  },
  'theme.post_meta': {
    title: 'Post meta',
    desc: 'Author, date & category info',
    icon: (s) => <IconListCheck size={s} />,
  },
  'theme.archive_title': {
    title: 'Archive title',
    desc: 'Taxonomy & archive heading',
    icon: (s) => <IconHeading size={s} />,
  },
  'theme.search_box': {
    title: 'Search box',
    desc: 'Site search input box',
    icon: (s) => <IconSearch size={s} />,
  },
  'core.spacer': {
    title: 'Spacer',
    desc: 'Tune vertical rhythm',
    icon: (s) => <IconSpacer size={s} />,
  },
  'core.divider': {
    title: 'Divider',
    desc: 'A horizontal rule',
    icon: (s) => <IconDivider size={s} />,
  },
  'core.tabs': {
    title: 'Tabs',
    desc: 'Switch between panels in place',
    icon: (s) => <IconGridPanes size={s} />,
  },
  'core.accordion': {
    title: 'Accordion',
    desc: 'Stacked rows that expand, ideal for FAQs',
    icon: (s) => <IconListCheck size={s} />,
  },
  'core.carousel': {
    title: 'Carousel',
    desc: 'Image and testimonial slides with dots',
    icon: (s) => <IconGallery size={s} />,
  },
  'core.stats': {
    title: 'Stats',
    desc: 'Animated figures that count up',
    icon: (s) => <IconGridDots size={s} />,
  },
};

function renderBlockIcon(type, icon, label) {
  if (BLOCK_META[type] && BLOCK_META[type].icon) {
    return BLOCK_META[type].icon(18);
  }
  const i = (icon || '').toLowerCase();
  if (i.includes('hero') || i.includes('rocket')) return <IconRocket size={18} />;
  if (i.includes('heading') || i.includes('title')) return <IconHeading size={18} />;
  if (i.includes('text') || i.includes('type')) return <IconPilcrow size={18} />;
  if (i.includes('image') || i.includes('media')) return <IconImage size={18} />;
  if (i.includes('button') || i.includes('cta') || i.includes('click')) return <IconArrowUpRight size={18} />;
  if (i.includes('grid')) return <IconGridPanes size={18} />;
  if (i.includes('dot')) return <IconGridDots size={18} />;
  if (i.includes('spacer') || i.includes('arrow')) return <IconSpacer size={18} />;
  if (i.includes('divider') || i.includes('line')) return <IconDivider size={18} />;
  if (i.includes('video')) return <IconVideo size={18} />;
  if (i.includes('gallery')) return <IconGallery size={18} />;
  if (i.includes('form')) return <IconForm size={18} />;
  if (i.includes('quote') || i.includes('testimonial')) return <IconQuotes size={18} />;
  if (i.includes('search')) return <IconSearch size={18} />;
  if (i.includes('check') || i.includes('list')) return <IconListCheck size={18} />;
  if (i.includes('section')) return <IconLayoutSection size={18} />;
  return <IconBox size={18} />;
}

const PRESET_SECTIONS = [
  {
    key: 'hero',
    type: 'core.hero',
    title: 'Hero Section',
    desc: 'High-impact intro with image, copy and CTA',
  },
  {
    key: 'features',
    type: 'core.feature_list',
    title: 'Feature Grid',
    desc: 'Showcase key benefits with clear layout',
  },
  {
    key: 'services',
    type: 'core.query_loop',
    title: 'Services Overview',
    desc: 'Display services or features with icons',
  },
  {
    key: 'image_text',
    type: 'layout.container',
    title: 'Image + Text',
    desc: 'Side-by-side image and content',
  },
  {
    key: 'testimonials',
    type: 'core.text',
    title: 'Testimonials',
    desc: 'Build trust with customer reviews',
  },
  {
    key: 'pricing',
    type: 'core.feature_list',
    title: 'Pricing Plans',
    desc: 'Compare plans with features and CTA',
  },
  {
    key: 'faq',
    type: 'core.rich_text',
    title: 'FAQ Section',
    desc: 'Expandable questions and answers',
  },
  {
    key: 'gallery',
    type: 'core.gallery',
    title: 'Gallery',
    desc: 'Image grid for visual media',
  },
];

/** Blocks fed by a tenant-scoped data provider, or by the current post/archive context. */
export function isDynamicBlock(def) {
  return !!def && (asList(def.binding_slots).length > 0 || String(def.type).startsWith('theme.'));
}

function providerNote(def) {
  const providers = asList(def.binding_slots).map((b) => b.provider);
  return providers.length ? `${t('dynamic_data_from')} ${providers.join(', ')}` : t('dynamic_page_context');
}

function PaletteCard({ def, query, onInsert }) {
  const meta = BLOCK_META[def.type] || {};
  return (
    <button
      type="button"
      className="sbx-palette-card"
      draggable
      onDragStart={(e) => { e.dataTransfer.setData(DRAG_TYPE_NEW, def.type); e.dataTransfer.effectAllowed = 'copy'; }}
      onClick={() => onInsert(def.type)}
      aria-label={`${t('insert')} ${meta.title || def.label}`}
      data-query={query}
    >
      <div className="sbx-palette-card__icon-badge" aria-hidden="true">{renderBlockIcon(def.type, def.icon, def.label)}</div>
      <div className="sbx-palette-card__content">
        <span className="sbx-palette-card__title">{meta.title || def.label}</span>
        <span className="sbx-palette-card__desc">{isDynamicBlock(def) ? providerNote(def) : (meta.desc || def.description || '')}</span>
      </div>
      <span className="sbx-palette-card__plus-btn" aria-hidden="true"><IconPlus size={14} /></span>
    </button>
  );
}

/** Dynamic tab: only the data-bound and page-context blocks, with where their data comes from. */
function DynamicPanel({ manifest, query, insertBlock }) {
  const q = query.trim().toLowerCase();
  const items = asList(manifest.blocks).filter((b) => isDynamicBlock(b)
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
  const items = asList(manifest.blocks).filter((b) => ['core.image', 'core.gallery', 'core.video'].includes(b.type)
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

  const blockItems = useMemo(() => {
    const q = query.trim().toLowerCase();
    const list = asList(manifest.blocks);
    const result = [];
    for (const b of list) {
      const meta = BLOCK_META[b.type] || {};
      const title = meta.title || b.label;
      const desc = meta.desc || b.description || 'Block component';
      if (q && !`${title} ${desc} ${b.category} ${b.type}`.toLowerCase().includes(q)) {
        continue;
      }
      result.push({
        ...b,
        displayTitle: title,
        displayDesc: desc,
      });
    }
    return result;
  }, [manifest.blocks, query]);

  const sectionItems = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return PRESET_SECTIONS;
    return PRESET_SECTIONS.filter((s) => `${s.title} ${s.desc}`.toLowerCase().includes(q));
  }, [query]);

  return (
    <div className={`sbx-palette${compact ? ' sbx-palette--compact' : ''}`}>
      {/* Search Header */}
      <div className="sbx-palette__search">
        <IconSearch size={14} className="sbx-palette__search-icon" />
        <input
          type="search"
          className="sbx-palette__search-input"
          placeholder="Search sections, elements..."
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          aria-label="Search sections and elements"
        />
        <span className="sbx-search-badge" aria-hidden="true">⌘ K</span>
      </div>

      {/* Category Tabs: Sections, Elements, Components */}
      <div className="sbx-palette__category-tabs" role="tablist">
        {[
          { key: 'sections', label: 'Sections' },
          { key: 'elements', label: 'Elements' },
          { key: 'components', label: 'Components' },
          { key: 'dynamic', label: t('palette_dynamic') },
          { key: 'media', label: t('palette_media') },
          { key: 'ai', label: t('palette_ai') },
        ].map((tab) => (
          <button
            key={tab.key}
            type="button"
            role="tab"
            aria-selected={categoryTab === tab.key}
            data-tab={tab.key}
            className={`sbx-palette__category-tab${categoryTab === tab.key ? ' is-active' : ''}`}
            onClick={() => setCategoryTab(tab.key)}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* Sections Tab Content */}
      {categoryTab === 'sections' && (
        <div className="sbx-palette__cards">
          {sectionItems.map((sec) => {
            const isFav = favorites.has(sec.key);
            return (
              <button
                key={sec.key}
                type="button"
                className="sbx-palette-card sbx-section-card"
                onClick={() => insertBlock(sec.type)}
                aria-label={`Insert ${sec.title}`}
              >
                <div className="sbx-palette-card__icon-badge" aria-hidden="true">
                  {renderBlockIcon(sec.type, sec.key, sec.title)}
                </div>
                <div className="sbx-palette-card__content">
                  <span className="sbx-palette-card__title">{sec.title}</span>
                  <span className="sbx-palette-card__desc">{sec.desc}</span>
                </div>
                <button
                  type="button"
                  className={`sbx-star-btn${isFav ? ' is-favorited' : ''}`}
                  onClick={(e) => toggleFavorite(e, sec.key)}
                  title={isFav ? 'Remove favorite' : 'Add to favorites'}
                  aria-label="Favorite"
                >
                  {isFav ? '★' : '☆'}
                </button>
              </button>
            );
          })}
        </div>
      )}

      {/* Elements Tab Content */}
      {categoryTab === 'elements' && (
        <div className="sbx-palette__cards">
          {blockItems.map((b) => (
            <button
              key={b.type}
              type="button"
              className="sbx-palette-card"
              draggable
              onDragStart={(e) => {
                e.dataTransfer.setData(DRAG_TYPE_NEW, b.type);
                e.dataTransfer.effectAllowed = 'copy';
              }}
              onClick={() => insertBlock(b.type)}
              aria-label={`${t('insert')} ${b.displayTitle}`}
            >
              <div className="sbx-palette-card__icon-badge" aria-hidden="true">
                {renderBlockIcon(b.type, b.icon, b.label)}
              </div>
              <div className="sbx-palette-card__content">
                <span className="sbx-palette-card__title">{b.displayTitle}</span>
                <span className="sbx-palette-card__desc">{b.displayDesc}</span>
              </div>
              <span className="sbx-palette-card__plus-btn" aria-hidden="true" title="Insert">
                <IconPlus size={14} />
              </span>
            </button>
          ))}
        </div>
      )}

      {categoryTab === 'dynamic' && <DynamicPanel manifest={manifest} query={query} insertBlock={insertBlock} />}
      {categoryTab === 'media' && (
        <MediaPanel manifest={manifest} query={query} insertBlock={insertBlock} insertBlockWithProps={insertBlockWithProps} mediaPicker={boot.mediaPicker} />
      )}
      {categoryTab === 'ai' && <AiPanel boot={boot} onReview={openAiReview} />}

      {/* Components Tab Content */}
      {categoryTab === 'components' && (
        <div className="sbx-palette__cards">
          <button
            type="button"
            className="sbx-palette-card"
            onClick={() => insertBlock('core.container')}
          >
            <div className="sbx-palette-card__icon-badge">
              <IconBox size={18} />
            </div>
            <div className="sbx-palette-card__content">
              <span className="sbx-palette-card__title">Global Header</span>
              <span className="sbx-palette-card__desc">Site-wide synchronized header</span>
            </div>
          </button>
          <button
            type="button"
            className="sbx-palette-card"
            onClick={() => insertBlock('core.container')}
          >
            <div className="sbx-palette-card__icon-badge">
              <IconBox size={18} />
            </div>
            <div className="sbx-palette-card__content">
              <span className="sbx-palette-card__title">Global Footer</span>
              <span className="sbx-palette-card__desc">Site-wide synchronized footer</span>
            </div>
          </button>
        </div>
      )}
    </div>
  );
});
