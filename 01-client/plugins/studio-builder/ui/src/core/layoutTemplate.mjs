// The Single template that lays the open page out on the live site (`layout_template` query). The canvas shows the
// page inside it, so a Container set wider than the template's content area is kept to the template's width there
// as on the live page; the Container Inspector says why, naming the template.

const WIDTHS = ['narrow', 'normal', 'wide', 'full'];

/** Whether `template` (the query's result) keeps a Container of this width narrower on the live page. */
export function templateCapsWidth(template, width) {
  if (!template || typeof template !== 'object' || !template.content_width) return false;
  const own = WIDTHS.indexOf(width || 'wide');
  const cap = WIDTHS.indexOf(template.content_width);
  return own >= 0 && cap >= 0 && own > cap;
}
