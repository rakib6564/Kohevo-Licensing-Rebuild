// Block kind helpers shared by the Add panel and its pure logic (no JSX, so node:test can import it).

import { asList } from '../core/doc.mjs';

/** Blocks fed by a tenant-scoped data provider, or by the current post/archive context. */
export function isDynamicBlock(def) {
  return !!def && (asList(def.binding_slots).length > 0 || String(def.type).startsWith('theme.'));
}
