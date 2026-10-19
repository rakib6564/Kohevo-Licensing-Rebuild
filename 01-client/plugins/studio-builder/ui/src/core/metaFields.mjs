// The site's custom meta fields (Types & fields), for the Field value block's dropdown.
//
// The server puts them in the boot payload once; like the translated messages they are plain data the UI only reads.
// A field key that exists on several content types is listed once per type, grouped under the type's name.

let fields = [];

/** `list` is boot.metaFields: [{key, label, type, postType, postTypeLabel}]. Anything malformed is dropped. */
export function setMetaFields(list) {
  fields = (Array.isArray(list) ? list : [])
    .filter((f) => f && typeof f.key === 'string' && /^[a-z0-9_]{1,64}$/.test(f.key))
    .map((f) => ({
      key: f.key,
      label: typeof f.label === 'string' && f.label !== '' ? f.label : f.key,
      type: typeof f.type === 'string' ? f.type : 'text',
      postType: typeof f.postType === 'string' ? f.postType : '',
      postTypeLabel: typeof f.postTypeLabel === 'string' && f.postTypeLabel !== '' ? f.postTypeLabel : (typeof f.postType === 'string' ? f.postType : ''),
    }));
}

export const getMetaFields = () => fields;

/** The options grouped by content type, in the order the types first appear: [{label, fields: [...]}]. */
export function metaFieldGroups(list = fields) {
  const groups = new Map();
  for (const f of list) {
    if (!groups.has(f.postType)) groups.set(f.postType, { label: f.postTypeLabel, fields: [] });
    groups.get(f.postType).fields.push(f);
  }
  return [...groups.values()];
}

/** True when `key` is one of the defined fields (any content type). */
export const isKnownMetaField = (key, list = fields) => list.some((f) => f.key === key);
