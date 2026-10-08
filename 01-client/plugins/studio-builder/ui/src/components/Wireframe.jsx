// Wireframe — a small SVG drawing of a preset's structure (see core/wireframe.mjs).
// Decorative: the card's text carries the meaning, so the drawing is hidden from assistive tech.

import { memo, useMemo } from 'react';
import { layoutOutline } from '../core/wireframe.mjs';

export const Wireframe = memo(function Wireframe({ outline }) {
  const wf = useMemo(() => layoutOutline(outline), [outline]);
  return (
    <svg className="sbx-wf" viewBox={`0 0 ${wf.width} ${wf.height}`} preserveAspectRatio="xMidYMin meet" aria-hidden="true" focusable="false" data-testid="wireframe">
      {wf.sections.map((s, i) => (
        <rect key={`s${i}`} className={`sbx-wf__section${/inverse|accent/.test(s.bg) ? ' is-dark' : /secondary|muted/.test(s.bg) ? ' is-tint' : ''}`} x="0" y={s.y} width={wf.width} height={s.h} />
      ))}
      {wf.shapes.map((s, i) => (s.k === 'dot'
        ? <circle key={i} className="sbx-wf__dot" cx={s.x + s.w / 2} cy={s.y + s.h / 2} r={s.w / 2} />
        : <rect key={i} className={`sbx-wf__${s.k}`} x={s.x} y={s.y} width={s.w} height={s.h} rx={s.k === 'pill' ? s.h / 2 : s.k === 'bar' || s.k === 'line' ? 1 : 2.5} />))}
    </svg>
  );
});
