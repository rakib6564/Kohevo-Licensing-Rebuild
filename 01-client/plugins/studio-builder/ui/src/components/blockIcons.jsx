// Block icons and the drag type shared by the Add panel, the canvas and the Layers tree.

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
  IconStar,
  IconList,
  IconLink,
} from './Icons.jsx';

export const DRAG_TYPE_NEW = 'application/x-kohevo-studio-block-type';

/**
 * Add-panel icons by the manifest's `icon` name (the server owns title, description, category and
 * icon for every block, so a new or third-party block never falls back to a generic card).
 */
export const ICON_BY_NAME = {
  rocket: IconRocket,
  heading: IconHeading,
  pilcrow: IconPilcrow,
  image: IconImage,
  'arrow-up-right': IconArrowUpRight,
  'grid-panes': IconGridPanes,
  'grid-dots': IconGridDots,
  spacer: IconSpacer,
  divider: IconDivider,
  box: IconBox,
  'layout-section': IconLayoutSection,
  video: IconVideo,
  gallery: IconGallery,
  form: IconForm,
  search: IconSearch,
  'list-check': IconListCheck,
  quotes: IconQuotes,
  star: IconStar,
  list: IconList,
  link: IconLink,
};

export function renderBlockIcon(type, icon, label) {
  const Named = ICON_BY_NAME[String(icon || '')];
  if (Named) return <Named size={18} />;
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
