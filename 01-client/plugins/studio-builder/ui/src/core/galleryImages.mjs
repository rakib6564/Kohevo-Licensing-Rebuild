// The Gallery widget's image list, as the Inspector's thumbnail grid edits it. Each entry is the block's own repeater
// item ({url, alt, caption, category, width, height}), so the server validates exactly what the plain repeater wrote.

/** The Gallery's `images` field: a repeater of image URLs. Other repeaters keep the plain control. */
export const isGalleryImages = (field) => !!field && field.type === 'repeater' && field.key === 'images'
  && !!fieldOf(field.item_schema, 'category');

function fieldOf(schema, key) {
  if (Array.isArray(schema)) return schema.find((f) => f && f.key === key) || null;
  return schema && typeof schema === 'object' ? schema[key] || null : null;
}

/** The fields an author edits per image: the size comes from the library. */
export function editableSchema(schema) {
  const keep = ['alt', 'caption', 'category'];
  if (Array.isArray(schema)) return schema.filter((f) => f && keep.includes(f.key));
  return Object.fromEntries(Object.entries(schema || {}).filter(([k]) => keep.includes(k)));
}

/** A picked library record as a gallery item. */
export function toGalleryItem(rec) {
  const item = { url: rec.url, alt: rec.alt_text || '', caption: '', category: '' };
  if (Number.isInteger(rec.width) && rec.width > 0 && Number.isInteger(rec.height) && rec.height > 0) {
    item.width = rec.width;
    item.height = rec.height;
  }
  return item;
}

/** Adds picked records to the end, skipping images already in the gallery, up to `max`. */
export function addImages(list, records, max = 300) {
  const next = [...list];
  const seen = new Set(list.map((i) => i && i.url));
  for (const rec of records) {
    if (!rec || !rec.url || seen.has(rec.url) || next.length >= max) continue;
    seen.add(rec.url);
    next.push(toGalleryItem(rec));
  }
  return next;
}

/** The list with the image at `from` moved to `to`; out-of-range moves change nothing. */
export function moveImage(list, from, to) {
  if (from === to || from < 0 || to < 0 || from >= list.length || to >= list.length) return list;
  const next = [...list];
  const [item] = next.splice(from, 1);
  next.splice(to, 0, item);
  return next;
}
