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
    get title() { return t('pal_hero'); },
    get desc() { return t('pal_lead_with_a_clear_proposition'); },
    icon: (s) => <IconRocket size={s} />,
  },
  'core.heading': {
    get title() { return t('pal_heading'); },
    get desc() { return t('pal_create_hierarchy'); },
    icon: (s) => <IconHeading size={s} />,
  },
  'core.text': {
    get title() { return t('pal_text'); },
    get desc() { return t('pal_plain_text_body_copy'); },
    icon: (s) => <IconPilcrow size={s} />,
  },
  'core.rich_text': {
    get title() { return t('pal_rich_text'); },
    get desc() { return t('pal_formatted_copy_body_content'); },
    icon: (s) => <IconPilcrow size={s} />,
  },
  'core.image': {
    get title() { return t('pal_image'); },
    get desc() { return t('pal_media_library_asset'); },
    icon: (s) => <IconImage size={s} />,
  },
  'core.button': {
    get title() { return t('pal_cta_banner'); },
    get desc() { return t('pal_close_with_an_action'); },
    icon: (s) => <IconArrowUpRight size={s} />,
  },
  'core.query_loop': {
    get title() { return t('pal_services_grid'); },
    get desc() { return t('pal_live_service_dynamic_posts'); },
    icon: (s) => <IconGridPanes size={s} />,
  },
  'core.feature_list': {
    get title() { return t('pal_feature_list'); },
    get desc() { return t('pal_highlights_feature_cards'); },
    icon: (s) => <IconListCheck size={s} />,
  },
  'core.container': {
    get title() { return t('pal_container'); },
    get desc() { return t('pal_inner_constraint_container'); },
    icon: (s) => <IconBox size={s} />,
  },
  'layout.section': {
    get title() { return t('pal_section'); },
    get desc() { return t('pal_full_width_layout_section'); },
    icon: (s) => <IconLayoutSection size={s} />,
  },
  'layout.container': {
    get title() { return t('pal_container'); },
    get desc() { return t('pal_constrained_width_container'); },
    icon: (s) => <IconBox size={s} />,
  },
  'layout.flex': {
    get title() { return t('pal_flex'); },
    get desc() { return t('pal_flexible_row_or_column_layout'); },
    icon: (s) => <IconBox size={s} />,
  },
  'layout.grid': {
    get title() { return t('pal_grid'); },
    get desc() { return t('pal_multi_column_responsive_grid'); },
    icon: (s) => <IconGridPanes size={s} />,
  },
  'core.gallery': {
    get title() { return t('pal_gallery'); },
    get desc() { return t('pal_media_image_gallery'); },
    icon: (s) => <IconGallery size={s} />,
  },
  'core.video': {
    get title() { return t('pal_video'); },
    get desc() { return t('pal_responsive_video_player'); },
    icon: (s) => <IconVideo size={s} />,
  },
  'core.form': {
    get title() { return t('pal_form'); },
    get desc() { return t('pal_interactive_form_builder'); },
    icon: (s) => <IconForm size={s} />,
  },
  'core.modal': {
    get title() { return t('pal_modal'); },
    get desc() { return t('pal_pop_up_modal_dialog'); },
    icon: (s) => <IconBox size={s} />,
  },
  'layout.offcanvas': {
    get title() { return t('pal_offcanvas'); },
    get desc() { return t('pal_slide_out_navigation_drawer'); },
    icon: (s) => <IconLayoutSection size={s} />,
  },
  'theme.post_title': {
    get title() { return t('pal_post_title'); },
    get desc() { return t('pal_dynamic_article_heading'); },
    icon: (s) => <IconHeading size={s} />,
  },
  'theme.post_content': {
    get title() { return t('pal_post_content'); },
    get desc() { return t('pal_dynamic_post_body_copy'); },
    icon: (s) => <IconPilcrow size={s} />,
  },
  'theme.post_meta': {
    get title() { return t('pal_post_meta'); },
    get desc() { return t('pal_author_date_category_info'); },
    icon: (s) => <IconListCheck size={s} />,
  },
  'theme.archive_title': {
    get title() { return t('pal_archive_title'); },
    get desc() { return t('pal_taxonomy_archive_heading'); },
    icon: (s) => <IconHeading size={s} />,
  },
  'theme.search_box': {
    get title() { return t('pal_search_box'); },
    get desc() { return t('pal_site_search_input_box'); },
    icon: (s) => <IconSearch size={s} />,
  },
  'core.spacer': {
    get title() { return t('pal_spacer'); },
    get desc() { return t('pal_tune_vertical_rhythm'); },
    icon: (s) => <IconSpacer size={s} />,
  },
  'core.divider': {
    get title() { return t('pal_divider'); },
    get desc() { return t('pal_a_horizontal_rule'); },
    icon: (s) => <IconDivider size={s} />,
  },
  'core.tabs': {
    get title() { return t('pal_tabs'); },
    get desc() { return t('pal_switch_between_panels_in_place'); },
    icon: (s) => <IconGridPanes size={s} />,
  },
  'core.accordion': {
    get title() { return t('pal_accordion'); },
    get desc() { return t('pal_stacked_rows_that_expand_ideal_for_faqs'); },
    icon: (s) => <IconListCheck size={s} />,
  },
  'core.carousel': {
    get title() { return t('pal_carousel'); },
    get desc() { return t('pal_image_and_testimonial_slides_with_dots'); },
    icon: (s) => <IconGallery size={s} />,
  },
  'core.stats': {
    get title() { return t('pal_stats'); },
    get desc() { return t('pal_animated_figures_that_count_up'); },
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
    get title() { return t('pal_hero_section'); },
    get desc() { return t('pal_high_impact_intro_with_image_copy_and_cta'); },
  },
  {
    key: 'features',
    type: 'core.feature_list',
    get title() { return t('pal_feature_grid'); },
    get desc() { return t('pal_showcase_key_benefits_with_clear_layout'); },
  },
  {
    key: 'services',
    type: 'core.query_loop',
    get title() { return t('pal_services_overview'); },
    get desc() { return t('pal_display_services_or_features_with_icons'); },
  },
  {
    key: 'image_text',
    type: 'layout.container',
    get title() { return t('pal_image_text'); },
    get desc() { return t('pal_side_by_side_image_and_content'); },
  },
  {
    key: 'testimonials',
    type: 'core.text',
    get title() { return t('pal_testimonials'); },
    get desc() { return t('pal_build_trust_with_customer_reviews'); },
  },
  {
    key: 'pricing',
    type: 'core.feature_list',
    get title() { return t('pal_pricing_plans'); },
    get desc() { return t('pal_compare_plans_with_features_and_cta'); },
  },
  {
    key: 'faq',
    type: 'core.rich_text',
    get title() { return t('pal_faq_section'); },
    get desc() { return t('pal_expandable_questions_and_answers'); },
  },
  {
    key: 'gallery',
    type: 'core.gallery',
    get title() { return t('pal_gallery'); },
    get desc() { return t('pal_image_grid_for_visual_media'); },
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
      const desc = meta.desc || b.description || t('pal_block_component_fallback');
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
          placeholder={t('pal_search_placeholder')}
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          aria-label={t('pal_search_label')}
        />
        <span className="sbx-search-badge" aria-hidden="true">⌘ K</span>
      </div>

      {/* Category Tabs: Sections, Elements, Components */}
      <div className="sbx-palette__category-tabs" role="tablist">
        {[
          { key: 'sections', label: t('pal_tab_sections') },
          { key: 'elements', label: t('pal_tab_elements') },
          { key: 'components', label: t('pal_tab_components') },
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
                  title={isFav ? t('pal_unfavorite') : t('pal_favorite_add')}
                  aria-label={t('pal_favorite')}
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
              <span className="sbx-palette-card__plus-btn" aria-hidden="true" title={t('pal_insert')}>
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
              <span className="sbx-palette-card__title">{t('pal_global_header')}</span>
              <span className="sbx-palette-card__desc">{t('pal_site_wide_synchronized_header')}</span>
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
              <span className="sbx-palette-card__title">{t('pal_global_footer')}</span>
              <span className="sbx-palette-card__desc">{t('pal_site_wide_synchronized_footer')}</span>
            </div>
          </button>
        </div>
      )}
    </div>
  );
});
