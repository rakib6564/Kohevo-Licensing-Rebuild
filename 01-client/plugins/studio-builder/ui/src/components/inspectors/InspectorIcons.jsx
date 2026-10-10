// Small stroke icons for the Inspector: one per section row, plus the arrows and actions of the compact toolbar.
// Paths are drawn on a 24px grid; every icon is decorative (the control around it carries the accessible name).

const PATHS = {
  chevron: 'M9 6l6 6-6 6',
  up: 'M12 19V5M6 11l6-6 6 6',
  down: 'M12 5v14M6 13l6 6 6-6',
  indent: 'M4 6h16M12 12h8M4 18h16M4 9l3 3-3 3',
  outdent: 'M4 6h16M12 12h8M4 18h16M8 9l-3 3 3 3',
  plus: 'M12 5v14M5 12h14',
  trash: 'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3',
  save: 'M5 4h11l3 3v13H5zM8 4v5h7V4M8 20v-6h8v6',
  component: 'M12 3l4 4-4 4-4-4zM12 13l4 4-4 4-4-4zM3 12l4-4M21 12l-4-4',
  content: 'M5 6h14M5 11h14M5 16h9',
  data: 'M5 7c0-1.7 3.1-3 7-3s7 1.3 7 3-3.1 3-7 3-7-1.3-7-3zM5 7v10c0 1.7 3.1 3 7 3s7-1.3 7-3V7M5 12c0 1.7 3.1 3 7 3s7-1.3 7-3',
  align: 'M4 6h16M4 11h10M4 16h16M4 21h10',
  layout: 'M4 5h16v14H4zM4 11h16M10 11v8',
  spacing: 'M8 4v16M16 4v16M4 8h16M4 16h16',
  typography: 'M5 19L12 5l7 14M8 14h8',
  background: 'M4 5h16v14H4zM4 15l5-5 4 4 3-3 4 4',
  border: 'M5 5h14v14H5z',
  shadow: 'M6 4h12v12H6zM9 20h12V8',
  dimensions: 'M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5',
  position: 'M12 3v18M3 12h18M12 3l-3 3M12 3l3 3M12 21l-3-3M12 21l3-3M3 12l3-3M3 12l3 3M21 12l-3-3M21 12l-3 3',
  effects: 'M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8zM19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z',
  opacity: 'M12 3c4 5 6 8 6 11a6 6 0 0 1-12 0c0-3 2-6 6-11z',
  states: 'M12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16zM12 8v4l3 2',
  tokens: 'M12 3a9 9 0 1 0 0 18c1.5 0 2-1 1.5-2s0-2 1.5-2h2a3 3 0 0 0 3-3 9 9 0 0 0-8-11zM7.5 11h.01M10 7.5h.01M14.5 7.5h.01',
  motion: 'M4 12h6M7 8l-3 4 3 4M14 6l6 6-6 6',
  visibility: 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z',
  responsive: 'M3 6h13v9H3zM1 19h17M19 9h3v10h-3z',
  classes: 'M8 7l-5 5 5 5M16 7l5 5-5 5',
  identity: 'M10 4L8 20M16 4l-2 16M5 9h15M4 15h15',
  stacking: 'M12 3l9 5-9 5-9-5zM3 13l9 5 9-5M3 17l9 5 9-5',
  tag: 'M9 6L3 12l6 6M15 6l6 6-6 6',
  attributes: 'M4 6h16M4 12h10M4 18h16M18 10l3 2-3 2',
  reset: 'M4 12a8 8 0 1 0 3-6.2M4 4v5h5',
  link: 'M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1',
  tab_content: 'M4 20h4L19 9l-4-4L4 16zM13.5 6.5l4 4',
  tab_style: 'M12 3a9 9 0 1 0 0 18zM12 3v18',
  tab_advanced: 'M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
  dir_row: 'M5 12h14M13 6l6 6-6 6',
  dir_row_reverse: 'M19 12H5M11 6l-6 6 6 6',
  dir_column: 'M12 5v14M6 13l6 6 6-6',
  dir_column_reverse: 'M12 19V5M6 11l6-6 6 6',
  wrap_nowrap: 'M4 12h14M14 8l4 4-4 4',
  wrap_wrap: 'M4 6h16M4 12h16M4 18h9',
  wrap_wrap_reverse: 'M4 18h16M4 12h16M4 6h9',
  justify_start: 'M3 4v16M7 8h4v8H7zM13 8h4v8h-4z',
  justify_center: 'M12 4v16M5 8h4v8H5zM15 8h4v8h-4z',
  justify_end: 'M21 4v16M7 8h4v8H7zM13 8h4v8h-4z',
  justify_between: 'M3 4v16M21 4v16M6 8h4v8H6zM14 8h4v8h-4z',
  justify_around: 'M5 4v16M19 4v16M9 9h2.5v6H9zM12.5 9H15v6h-2.5z',
  justify_evenly: 'M3 4v16M21 4v16M7.5 9H10v6H7.5zM14 9h2.5v6H14z',
  items_start: 'M4 3h16M8 6v8M15 6v11',
  items_center: 'M4 12h16M8 7v10M15 5v14',
  items_end: 'M4 21h16M8 10v8M15 7v11',
  items_stretch: 'M4 3h16M4 21h16M9 6v12M15 6v12',
  items_baseline: 'M4 13h16M8 7v10M15 9v8',
  text_left: 'M4 6h16M4 10h10M4 14h16M4 18h10',
  text_center: 'M4 6h16M7 10h10M4 14h16M7 18h10',
  text_right: 'M4 6h16M10 10h10M4 14h16M10 18h10',
  text_justify: 'M4 6h16M4 10h16M4 14h16M4 18h16',
  fallback: 'M5 6h14M5 12h14M5 18h14',
};

export function SectionIcon({ name, size = 16 }) {
  return <Icon name={PATHS[name] ? name : 'fallback'} size={size} className="sbx-isec__icon" />;
}

export function Icon({ name, size = 14, className = '' }) {
  return (
    <svg className={className} width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
      <path d={PATHS[name] || PATHS.fallback} />
    </svg>
  );
}

/** A square icon-only button: the name goes in `label` (aria-label and tooltip), so nothing is lost for a screen reader. */
export function IconButton({ icon, label, danger = false, ...rest }) {
  return (
    <button type="button" className={`sbx-iconbtn${danger ? ' is-danger' : ''}`} aria-label={label} title={label} {...rest}>
      <Icon name={icon} size={15} />
    </button>
  );
}

/** The icon name for one option of a choice group (`kind` is direction, wrap, justify, items or text). */
export function choiceIcon(kind, value) {
  return `${kind}_${String(value).replace(/-/g, '_')}`;
}
