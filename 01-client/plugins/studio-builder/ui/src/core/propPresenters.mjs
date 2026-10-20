// How a container block's own props are drawn in the Inspector. The server still owns the fields and their
// allowed values; this only picks a friendlier control for some of them: icon toggles (direction, wrap,
// justify, align), text pills (width modes) or tiles (grid columns). A field with no entry stays a plain select.

export const PROP_PRESENTERS = Object.freeze({
  'layout.flex': {
    direction: { icons: 'dir' },
    wrap: { icons: 'wrap' },
    justify: { icons: 'justify' },
    align: { icons: 'items' },
  },
  'layout.grid': {
    columns: { tiles: [1, 2, 3, 4, 5, 6] },
    align: { icons: 'items' },
  },
  'layout.container': {
    width: { pills: true },
    alignment: { icons: 'text' },
  },
  'layout.section': {
    content_width: { pills: true },
  },
  'core.container': {
    direction: { icons: 'dir', map: { vertical: 'column', horizontal: 'row' } },
  },
  'core.card': {
    variant: { pills: true },
  },
  'core.button': {
    icon_position: { pills: true, showIf: (p) => !!p.icon && p.icon !== 'none' },
    icon_spacing: { showIf: (p) => !!p.icon && p.icon !== 'none' },
  },
  'core.heading': {
    new_tab: { showIf: (p) => typeof p.url === 'string' && p.url.trim() !== '' },
  },
});

export const presentersFor = (type) => PROP_PRESENTERS[type] || null;

// Container props that "Flex & grid" (style.layout) can also set. The style is a scoped rule that comes after the
// prop's class, so when both are set the style wins; the Inspector then shows the prop read-only (CL-02).
export const LAYOUT_OWNED_PROPS = Object.freeze({
  'layout.flex': { direction: 'direction', wrap: 'wrap', justify: 'justify', align: 'align', gap: 'gap' },
  'layout.grid': { columns: 'columns', align: 'align', gap: 'gap' },
  'core.container': { direction: 'direction', wrap: 'wrap', gap: 'gap' },
});

/** The prop keys of `type` whose value `style.layout` currently overrides. */
export function layoutOwnedProps(type, style) {
  const map = LAYOUT_OWNED_PROPS[type];
  const layout = style && typeof style === 'object' && style.layout && typeof style.layout === 'object' ? style.layout : null;
  if (!map || !layout) return [];
  return Object.keys(map).filter((prop) => layout[map[prop]] !== undefined && layout[map[prop]] !== null && layout[map[prop]] !== '');
}
