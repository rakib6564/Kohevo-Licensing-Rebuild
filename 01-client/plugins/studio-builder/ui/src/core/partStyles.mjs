// Part styles: the editor half of `StyleSurface::partIssues()` (BlockParts.php). `block.style_parts` maps a part (or
// `<part>:hover`) to a partial style; a cleared field is removed, and a part left with nothing is removed with it.

import { asObject } from './doc.mjs';

/** A partial style with empty groups (and the part, when nothing is left) removed. */
function prune(partial) {
  const out = {};
  for (const [k, v] of Object.entries(asObject(partial))) {
    if (v === undefined || v === null || v === '') continue;
    if (typeof v === 'object' && !Array.isArray(v)) {
      const inner = prune(v);
      if (Object.keys(inner).length) out[k] = inner;
    } else out[k] = v;
  }
  return out;
}

/** `style_parts` with `key` set to `partial` (or removed when it is empty). Never mutates. */
export function writePart(parts, key, partial) {
  const next = { ...asObject(parts) };
  const clean = prune(partial);
  if (Object.keys(clean).length) next[key] = clean;
  else delete next[key];
  return next;
}
