// The Single template that lays the open page out on the live site (`layout_template` query). The canvas shows the
// page inside it, so a section set wider than the template's content section is kept to the template's width there
// as on the live page; the section Inspector says why, naming the template.

const WIDTHS = ['narrow', 'normal', 'wide', 'full'];

/** Whether `template` (the query's result) keeps a section of this width narrower on the live page. */
export function templateCapsWidth(template, width) {
  if (!template || typeof template !== 'object' || !template.content_width) return false;
  const own = WIDTHS.indexOf(width || 'wide');
  const cap = WIDTHS.indexOf(template.content_width);
  return own >= 0 && cap >= 0 && own > cap;
}
