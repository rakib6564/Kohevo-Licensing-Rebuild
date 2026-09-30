// Kohevo Studio builder — UI strings.
//
// Human-readable text for the builder chrome, including the message shown
// for each SAFE server error code. Server-provided labels (block names, field
// labels, token names) are data and are rendered as text only.

const EN = {
  app_name: 'Kohevo Studio',
  back_to_pages: 'Studio pages',
  loading: 'Loading the builder…',
  load_failed: 'The builder could not load this page.',
  retry: 'Try again',

  status_loading: 'Loading…',
  status_idle: 'All changes saved',
  status_saved: 'All changes saved',
  status_dirty: 'Unsaved changes',
  status_saving: 'Saving…',
  status_error: 'Not saved',
  status_conflict: 'Conflict — not saved',

  save: 'Save',
  undo: 'Undo',
  redo: 'Redo',
  preview: 'Preview',
  publish: 'Publish',
  publishing_done: 'Published.',
  history: 'History',
  viewport: 'Viewport',
  desktop: 'Desktop',
  tablet: 'Tablet',
  mobile: 'Mobile',

  panel_blocks: 'Blocks',
  panel_structure: 'Structure',
  search_blocks: 'Search blocks',
  insert: 'Insert',
  add_section: 'Add section',
  no_blocks_match: 'No blocks match.',
  empty_page: 'This page is empty. Add a section to start.',
  outline_label: 'Page structure',
  palette_hint: 'Press Insert to add after the selection, or drag onto the structure.',

  inspector: 'Properties',
  tab_content: 'Content',
  tab_style: 'Style',
  tab_visibility: 'Visibility',
  tab_data: 'Data',
  tab_layout: 'Layout',
  page_settings: 'Page settings',
  seo: 'Search appearance',
  nothing_selected: 'Select a block or section on the canvas or in the structure to edit it.',
  section: 'Section',
  move_up: 'Move up',
  move_down: 'Move down',
  indent: 'Move into container above',
  outdent: 'Move out of container',
  remove: 'Delete',
  confirm_remove_section: 'Delete this section and all of its blocks?',
  none: 'None',
  inherit: 'Inherit',
  choose_image: 'Choose image',
  media_id: 'Media ID',
  alt_text: 'Alternative text',
  focal_point: 'Focal point',
  link_label: 'Label',
  link_href: 'Address',
  link_new_tab: 'Open in a new tab',
  add_item: 'Add item',
  remove_item: 'Remove item',
  item: 'Item',
  devices: 'Show on',
  audience: 'Audience',
  align: 'Alignment',
  columns: 'Columns',
  padding_y: 'Vertical padding',
  width: 'Width',
  gap: 'Gap',
  background: 'Background',
  provider: 'Data source',
  bindings_hint: 'This block shows live data from an installed module.',
  editing_at: 'Editing at',
  unavailable_block: 'Unknown block',

  insert_dialog_title: 'Set up {label}',
  insert_dialog_hint: 'Fill in the required fields to add this block.',
  cancel: 'Cancel',
  add: 'Add',

  conflict_title: 'This page was changed somewhere else',
  conflict_body: 'Your {count} most recent change(s) were not saved, because the page now has a newer revision on the server. Nothing was overwritten. Reload to continue from the newest version (your unsaved changes will be discarded).',
  conflict_reload: 'Reload latest version',
  lock_other: 'Someone else has this page open in the builder. Changes are protected by revision checks, but you may run into conflicts.',
  unsaved_leave: 'You have unsaved changes.',

  history_title: 'Revision history',
  history_restore: 'Restore',
  history_current: 'Current',
  history_confirm: 'Restore revision #{number}? This creates a new draft revision; published output does not change until you publish.',
  close: 'Close',

  announce_selected: '{label} selected',
  announce_saved: 'All changes saved',
  announce_inserted: '{label} added',
  announce_removed: '{label} deleted',
  announce_moved: '{label} moved',
  announce_conflict: 'Save rejected: the page changed on the server. Reload required.',
  announce_rejected: 'That change was rejected and has been undone.',
  announce_failed: 'Saving failed. Your changes are kept and will be retried.',
  announce_undo: 'Undone',
  announce_redo: 'Redone',
  announce_published: 'Page published',
  announce_viewport: 'Viewport: {label}',

  field_required: 'This field is required.',
  field_too_long: 'This is too long.',
  field_too_short: 'This is too short.',
  field_number: 'Enter a number.',
  field_integer: 'Enter a whole number.',
  field_too_small: 'This number is too small.',
  field_too_large: 'This number is too large.',
  field_url: 'Enter a safe address (https://…, /path, #anchor, mailto: or tel:).',
  field_link_label: 'A link needs a label.',
  field_media: 'Choose an image.',
  field_too_many: 'Too many items.',

  error_validation_error: 'That change was rejected because it is not valid.',
  error_authentication_error: 'Your session has ended. Sign in again to keep editing.',
  error_authorization_error: 'You do not have permission to do this.',
  error_entitlement_error: 'Kohevo Studio is not available on this site.',
  error_csrf_error: 'The security check failed. Reload the builder.',
  error_not_found: 'This page or revision no longer exists.',
  error_concurrency_conflict: 'This page was changed elsewhere.',
  error_rate_limited: 'Too many requests. Retrying shortly.',
  error_payload_too_large: 'This change is too large to save.',
  error_network_error: 'Could not reach the server. Retrying shortly.',
  error_timeout: 'The server took too long to answer. Retrying shortly.',
  error_server_error: 'The server could not save this change. Retrying shortly.',
};

let overrides = {};

/** Optional translated strings supplied by the host page. */
export function setMessages(map) {
  overrides = map && typeof map === 'object' ? map : {};
}

export function t(key, vars = null) {
  let s = Object.prototype.hasOwnProperty.call(overrides, key) ? String(overrides[key])
    : Object.prototype.hasOwnProperty.call(EN, key) ? EN[key] : key;
  if (vars) {
    s = s.replace(/\{(\w+)\}/g, (m, name) => (Object.prototype.hasOwnProperty.call(vars, name) ? String(vars[name]) : m));
  }
  return s;
}

/** A readable message for a safe server error, never the raw server text. */
export function errorMessage(error) {
  const code = error && typeof error.code === 'string' ? error.code : 'server_error';
  const key = `error_${code}`;
  return Object.prototype.hasOwnProperty.call(EN, key) ? t(key) : t('error_server_error');
}
