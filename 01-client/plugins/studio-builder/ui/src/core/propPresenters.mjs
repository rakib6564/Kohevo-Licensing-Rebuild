// How a container block's own props are drawn in the Inspector. The server still owns the fields and their
// allowed values; this only picks a friendlier control for some of them: icon toggles (direction, wrap,
// justify, align), text pills (width modes) or tiles (grid columns). A field with no entry stays a plain select.

/** The Nav menu has a menu button. */
const navToggled = (p) => !!p.toggle_breakpoint && p.toggle_breakpoint !== 'none';
const navPresenter = {
  pointer_animation: { showIf: (p) => !!p.pointer && p.pointer !== 'none' && p.pointer !== 'text' },
  pointer_color: { showIf: (p) => !!p.pointer && p.pointer !== 'none' },
  toggle_mode: { pills: true, showIf: navToggled },
  toggle_full_width: { showIf: (p) => navToggled(p) && (!p.toggle_mode || p.toggle_mode === 'dropdown') },
  toggle_animation: { pills: true, showIf: navToggled },
  toggle_icon: { showIf: navToggled },
  toggle_label: { showIf: navToggled },
  toggle_align: { pills: true, showIf: navToggled },
  toggle_cta_label: { showIf: navToggled },
  toggle_cta_url: { showIf: (p) => navToggled(p) && !!p.toggle_cta_label },
};

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
  'core.container': {
    direction: { icons: 'dir', map: { vertical: 'column', horizontal: 'row' } },
  },
  'core.card': {
    variant: { pills: true },
    surface_token: { showIf: (p) => !!p.surface_token },
  },
  // The menu button's options mean nothing without a button, and the dropdown's width only applies to a dropdown.
  'core.nav_menu': navPresenter,
  'layout.mega_menu': { ...navPresenter, panel_width: { pills: true } },
  'core.button': {
    icon_position: { pills: true, showIf: (p) => !!p.icon && p.icon !== 'none' },
    icon_spacing: { showIf: (p) => !!p.icon && p.icon !== 'none' },
  },
  'core.heading': {
    new_tab: { showIf: (p) => typeof p.url === 'string' && p.url.trim() !== '' },
  },
  // The Text Editor's columns gap matters only with more than one column.
  'core.rich_text': {
    columns_gap: { showIf: (p) => typeof p.columns === 'number' && p.columns > 1 },
  },
  // Size and alignment live in the Style section. The older colour token stays visible only while it holds a value.
  'core.text': {
    size: { showIf: () => false },
    align: { showIf: () => false },
    color_token: { showIf: (p) => !!p.color_token },
    columns_gap: { showIf: (p) => typeof p.columns === 'number' && p.columns > 1 },
  },
  'core.quote': { align: { showIf: () => false } },
  'core.link': { align: { showIf: () => false } },
  'theme.post_title': { align: { showIf: () => false } },
  'core.image': {
    url: { showIf: (p) => p.link_to === 'custom' },
    new_tab: { showIf: (p) => p.link_to === 'media' || p.link_to === 'custom' },
  },
  // The Video, like the reference editor's: Source decides which address field shows, and the poster's own settings
  // show once Image Overlay is on (a lightbox opens from the poster, so it needs one too).
  'core.video': {
    source: { pills: true },
    url: { showIf: (p) => p.source !== 'hosted' },
    hosted_url: { showIf: (p) => p.source === 'hosted' },
    privacy: { showIf: (p) => p.source !== 'hosted' },
    overlay_image: { showIf: (p) => p.overlay === true },
    play_icon: { showIf: (p) => p.overlay === true },
    lightbox: { showIf: (p) => p.overlay === true },
  },
  // The Countdown: Type decides between a date and a length, and each After Expire action has its own settings.
  'core.countdown': {
    countdown_type: { pills: true },
    target: { showIf: (p) => p.countdown_type !== 'evergreen' },
    evergreen_hours: { showIf: (p) => p.countdown_type === 'evergreen' },
    evergreen_minutes: { showIf: (p) => p.countdown_type === 'evergreen' },
    view: { pills: true },
    custom_labels: { showIf: (p) => p.show_labels !== false },
    label_days: { showIf: (p) => p.show_labels !== false && p.custom_labels === true && p.show_days !== false },
    label_hours: { showIf: (p) => p.show_labels !== false && p.custom_labels === true && p.show_hours !== false },
    label_minutes: { showIf: (p) => p.show_labels !== false && p.custom_labels === true && p.show_minutes !== false },
    label_seconds: { showIf: (p) => p.show_labels !== false && p.custom_labels === true && p.show_seconds !== false },
    done_text: { showIf: (p) => (p.expire_action || 'message') === 'message' },
    message_align: { pills: true, showIf: (p) => (p.expire_action || 'message') === 'message' },
    redirect_url: { showIf: (p) => p.expire_action === 'redirect' },
  },
  'core.marquee': {
    direction: { pills: true },
  },
  // The Audio: Source decides which address field shows.
  'core.audio': {
    source: { pills: true },
    url: { showIf: (p) => p.source !== 'hosted' },
    hosted_url: { showIf: (p) => p.source === 'hosted' },
  },
  // The Icon's colours, size, frame and rotation are set from its Style section, not here. The older preset size and
  // colour token stay visible only while they hold a value the Style section does not show.
  'core.icon': {
    size: { showIf: (p) => !(p.size_px > 0) && typeof p.size === 'string' && p.size !== 'md' },
    color_token: { showIf: (p) => !!p.color_token },
    view: { pills: true },
    shape: { pills: true, showIf: (p) => p.view === 'stacked' || p.view === 'framed' },
    new_tab: { showIf: (p) => typeof p.url === 'string' && p.url.trim() !== '' },
    secondary_token: { showIf: () => false },
    size_px: { showIf: () => false },
    frame_padding: { showIf: () => false },
    frame_border: { showIf: () => false },
    rotate: { showIf: () => false },
  },
});

/** The Icon props its Style section edits (hidden from Content). */
export const ICON_STYLE_PROPS = Object.freeze(['secondary_token', 'size_px', 'frame_padding', 'frame_border', 'rotate']);

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
