// The computed look of a block on the canvas: what the stylesheet gives it where the document holds no value. Read in its
// resting state: a block under the pointer shows its hover colours, so the read waits until the pointer leaves.

import { useEffect, useState } from 'react';
import { mainElement } from '../core/effectiveColour.mjs';

const REPAINT_MS = 500;

export function useCanvasComputed(nodeId, revision) {
  const [computed, setComputed] = useState(null);
  useEffect(() => {
    let timer = null;
    let waiting = null;
    const read = () => {
      let doc = null;
      try { doc = document.querySelector('.sbx-canvas__frame')?.contentDocument || null; } catch { doc = null; }
      const node = doc && doc.querySelector(`[data-sb-node="${String(nodeId).replace(/[^\w-]/g, '')}"]`);
      if (!node) return;
      const el = mainElement(node);
      if (el.matches(':hover')) {
        waiting = el;
        el.addEventListener('mouseleave', later, { once: true });
        return;
      }
      const cs = doc.defaultView.getComputedStyle(el);
      setComputed({
        color: cs.color, backgroundColor: cs.backgroundColor, borderColor: cs.borderTopColor, borderStyle: cs.borderTopStyle,
        borderWidth: cs.borderTopWidth, borderRadius: cs.borderTopLeftRadius, fontSize: cs.fontSize, fontWeight: cs.fontWeight,
      });
    };
    function later() { clearTimeout(timer); timer = setTimeout(read, REPAINT_MS); }
    read();
    // A style edit reaches the canvas after a server repaint.
    later();
    return () => { clearTimeout(timer); if (waiting) waiting.removeEventListener('mouseleave', later); };
  }, [nodeId, revision]);
  return computed;
}
