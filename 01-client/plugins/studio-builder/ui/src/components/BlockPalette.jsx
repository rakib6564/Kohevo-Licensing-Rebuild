// BlockPalette — insertable blocks from the server manifest rendered as
// premium dark cards with green-tinted stroke outline icons, clean microcopy,
// drag & drop support, and single-click insertion.

import { memo, useMemo, useState } from 'react';
import { useEditor } from './EditorContext.jsx';
import { t } from '../core/messages.mjs';
import { asList } from '../core/doc.mjs';
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

export const BlockPalette = memo(function BlockPalette({ compact = false }) {
  const { manifest, insertBlock } = useEditor();
  const [query, setQuery] = useState('');

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

  return (
    <div className={`sbx-palette${compact ? ' sbx-palette--compact' : ''}`}>
      <div className="sbx-palette__breadcrumb">INSERT / BLOCK</div>

      <div className="sbx-palette__search">
        <IconSearch size={13} className="sbx-palette__search-icon" />
        <input
          type="search"
          className="sbx-palette__search-input"
          placeholder={t('search_blocks')}
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          aria-label={t('search_blocks')}
        />
      </div>

      {blockItems.length === 0 ? (
        <p className="sbx-muted sbx-palette__empty">{t('no_blocks_match')}</p>
      ) : (
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
    </div>
  );
});
