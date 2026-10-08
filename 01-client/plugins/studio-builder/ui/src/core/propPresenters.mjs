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
});

export const presentersFor = (type) => PROP_PRESENTERS[type] || null;
