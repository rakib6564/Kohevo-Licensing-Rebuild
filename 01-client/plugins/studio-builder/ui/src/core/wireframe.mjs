// Wireframe layout for the Add panel's section thumbnails.
//
// Pure and deterministic: turns the server's structural outline of a preset (block types, item and
// column counts, alignment; never any copy) into a list of rectangles inside a fixed-width box. The
// component draws them as SVG, so a preset needs no image asset and nothing is fetched.

export const WF_WIDTH = 160;
const PAD = 8;
const GAP = 5;
const MAX_DEPTH = 6;

const HEADING_H = { h1: 9, h2: 8, h3: 6, h4: 5, h5: 5, h6: 4 };

/** @returns {{width:number,height:number,sections:{bg:string,y:number,h:number}[],shapes:{k:string,x:number,y:number,w:number,h:number}[]}} */
export function layoutOutline(outline, width = WF_WIDTH) {
  const shapes = [];
  const sections = [];
  let y = 0;
  for (const section of Array.isArray(outline) ? outline : []) {
    const top = y;
    const inner = stack(section.nodes, PAD, y + PAD, width - PAD * 2, shapes, 0);
    const h = inner + PAD * 2;
    sections.push({ bg: String(section.bg || ''), y: top, h });
    y = top + h;
  }
  return { width, height: Math.max(y, 24), sections, shapes };
}

function stack(nodes, x, y, w, shapes, depth) {
  const list = Array.isArray(nodes) ? nodes : [];
  let cursor = y;
  list.forEach((node, i) => {
    cursor += place(node, x, cursor, w, shapes, depth) + (i < list.length - 1 ? GAP : 0);
  });
  return cursor - y;
}

function alignedX(align, x, w, cw) {
  if (align === 'center') return x + (w - cw) / 2;
  if (align === 'right') return x + w - cw;
  return x;
}

function lines(count, x, y, w, align, shapes) {
  let cursor = y;
  for (let i = 0; i < count; i++) {
    const lw = i === count - 1 && count > 1 ? w * 0.6 : w;
    shapes.push({ k: 'line', x: alignedX(align, x, w, lw), y: cursor, w: lw, h: 3 });
    cursor += 3 + (i < count - 1 ? 3 : 0);
  }
  return cursor - y;
}

/** Equal-width cells, left to right, wrapping after `cols`; returns the total height. */
function cells(nodes, cols, x, y, w, shapes, depth, drawOne) {
  const count = Math.max(1, Math.min(6, cols));
  const cellW = (w - GAP * (count - 1)) / count;
  let cursor = y;
  for (let start = 0; start < nodes.length; start += count) {
    let rowH = 0;
    nodes.slice(start, start + count).forEach((node, i) => {
      rowH = Math.max(rowH, drawOne(node, x + i * (cellW + GAP), cursor, cellW, depth + 1));
    });
    cursor += rowH + GAP;
  }
  return Math.max(0, cursor - y - GAP);
}

function place(node, x, y, w, shapes, depth) {
  if (!node || depth > MAX_DEPTH) return 0;
  const kids = Array.isArray(node.k) ? node.k : [];
  const align = node.a || 'left';
  const n = Number.isFinite(node.n) ? node.n : 0;
  const cols = Number.isFinite(node.c) ? node.c : 0;

  switch (node.t) {
    case 'core.heading': {
      const h = HEADING_H[node.l] || 6;
      const bw = w * (node.l === 'h1' ? 0.8 : node.l === 'h2' ? 0.6 : 0.45);
      shapes.push({ k: 'bar', x: alignedX(align, x, w, bw), y, w: bw, h });
      return h;
    }
    case 'core.text':
      return lines(2, x, y, w, align, shapes);
    case 'core.rich_text':
      return lines(3, x, y, w, align, shapes);
    case 'core.button': {
      shapes.push({ k: 'pill', x: alignedX(align, x, w, 34), y, w: 34, h: 9 });
      return 9;
    }
    case 'core.hero': {
      shapes.push({ k: 'line', x, y, w: w * 0.2, h: 3 });
      shapes.push({ k: 'bar', x, y: y + 7, w: w * 0.75, h: 8 });
      shapes.push({ k: 'bar', x, y: y + 18, w: w * 0.5, h: 8 });
      const t = lines(2, x, y + 31, w * 0.8, 'left', shapes);
      shapes.push({ k: 'pill', x, y: y + 31 + t + 6, w: 34, h: 9 });
      return 31 + t + 6 + 9;
    }
    case 'layout.container':
    case 'core.container': {
      if (node.s) {
        const idx = shapes.length;
        shapes.push({ k: 'card', x, y, w, h: 0 });
        const inner = stack(kids, x + 6, y + 6, w - 12, shapes, depth + 1);
        shapes[idx].h = inner + 12;
        return inner + 12;
      }
      return stack(kids, x, y, w, shapes, depth + 1);
    }
    case 'layout.grid':
      return cells(kids, cols || 2, x, y, w, shapes, depth, (c, cx, cy, cw, d) => place(c, cx, cy, cw, shapes, d));
    case 'layout.flex': {
      const centered = kids.map((c) => ({ ...c, a: c.a || 'center' }));
      return cells(centered, centered.length || 1, x, y, w, shapes, depth, (c, cx, cy, cw, d) => place(c, cx, cy, cw, shapes, d));
    }
    case 'core.feature_list': {
      const items = Array.from({ length: n || cols || 3 }, () => ({ t: '_feature' }));
      return cells(items, cols || 3, x, y, w, shapes, depth, (c, cx, cy, cw) => {
        shapes.push({ k: 'card', x: cx, y: cy, w: cw, h: node.u ? 30 : 24 });
        shapes.push({ k: 'bar', x: cx + 4, y: cy + 4, w: cw * 0.5, h: 5 });
        lines(2, cx + 4, cy + 12, cw - 8, 'left', shapes);
        if (node.u) shapes.push({ k: 'pill', x: cx + 4, y: cy + 23, w: 12, h: 3 });
        return node.u ? 30 : 24;
      });
    }
    case 'core.stats': {
      const items = Array.from({ length: Math.min(n || 4, 6) }, () => ({ t: '_stat' }));
      return cells(items, items.length, x, y, w, shapes, depth, (c, cx, cy, cw) => {
        shapes.push({ k: 'bar', x: cx + cw * 0.15, y: cy, w: cw * 0.7, h: 8 });
        shapes.push({ k: 'line', x: cx + cw * 0.1, y: cy + 12, w: cw * 0.8, h: 3 });
        return 15;
      });
    }
    case 'core.accordion': {
      const rows = Math.min(n || 4, 6);
      for (let i = 0; i < rows; i++) shapes.push({ k: 'card', x, y: y + i * 11, w, h: 8 });
      return rows * 11 - 3;
    }
    case 'core.tabs': {
      const tabs = Math.min(n || 3, 5);
      const tw = (w - GAP * (tabs - 1)) / tabs;
      for (let i = 0; i < tabs; i++) shapes.push({ k: i === 0 ? 'pill' : 'card', x: x + i * (tw + GAP), y, w: tw, h: 7 });
      shapes.push({ k: 'card', x, y: y + 11, w, h: 22 });
      return 33;
    }
    case 'core.carousel': {
      shapes.push({ k: 'card', x, y, w, h: 30 });
      lines(3, x + w * 0.15, y + 7, w * 0.7, 'center', shapes);
      for (let i = 0; i < 3; i++) shapes.push({ k: 'dot', x: x + w / 2 - 9 + i * 9, y: y + 33, w: 4, h: 4 });
      return 38;
    }
    case 'core.gallery': {
      const c = cols || 3;
      const items = Array.from({ length: c * 2 }, () => ({ t: '_photo' }));
      return cells(items, c, x, y, w, shapes, depth, (_c, cx, cy, cw) => {
        shapes.push({ k: 'box', x: cx, y: cy, w: cw, h: cw * 0.75 });
        return cw * 0.75;
      });
    }
    case 'core.form': {
      const used = stack(kids, x, y, w, shapes, depth + 1);
      const by = y + used + (kids.length ? GAP : 0);
      shapes.push({ k: 'pill', x, y: by, w: 34, h: 9 });
      return by - y + 9;
    }
    case 'core.form_field': {
      const h = node.f === 'textarea' ? 16 : 9;
      shapes.push({ k: 'box', x, y, w, h });
      return h;
    }
    case 'core.image':
    case 'core.video': {
      shapes.push({ k: 'box', x, y, w, h: 26 });
      return 26;
    }
    default: {
      if (kids.length) return stack(kids, x, y, w, shapes, depth + 1);
      shapes.push({ k: 'card', x, y, w, h: 14 });
      return 14;
    }
  }
}
