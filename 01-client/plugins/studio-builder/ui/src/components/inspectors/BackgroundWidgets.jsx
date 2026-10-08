// Background image extras: the overlay (colour + opacity) and the focal-point pad.
// Neither changes what the document stores: the overlay stays one colour string (its opacity is the alpha pair of a
// `#rrggbbaa` value, see core/overlayColor.mjs) and the focal point is the media_ref's own `focal_point` pair.

import { useRef } from 'react';
import { t } from '../../core/messages.mjs';
import { focalFromPoint, overlayWith, parseOverlay } from '../../core/overlayColor.mjs';
import { ColorField } from './StyleControls.jsx';

export function OverlayField({ id, value, onChange }) {
  const parsed = parseOverlay(value);
  return (
    <>
      <ColorField
        id={`${id}-overlay`}
        label={t('bg_overlay')}
        value={value}
        onChange={(v) => {
          // Picking or typing a plain #rrggbb keeps the opacity already chosen; anything else is taken as written.
          if (typeof v === 'string' && /^#[0-9a-f]{6}$/i.test(v) && parsed.editable && parsed.percent < 100) onChange(overlayWith(v.toLowerCase(), parsed.percent));
          else onChange(v);
        }}
      />
      {parsed.editable && (
        <div className="sbx-field">
          <label className="sbx-field__label" htmlFor={`${id}-overlay-opacity`}>{t('bg_overlay_opacity')}</label>
          <div className="sbx-slider">
            <input
              id={`${id}-overlay-opacity`}
              type="range"
              min={0}
              max={100}
              step={1}
              value={parsed.percent}
              onChange={(e) => {
                const percent = Number(e.target.value);
                // A fully opaque overlay with no colour chosen yet means "no overlay".
                onChange(value === undefined && percent === 100 ? undefined : overlayWith(parsed.base, percent));
              }}
            />
            <output htmlFor={`${id}-overlay-opacity`}>{parsed.percent}%</output>
          </div>
        </div>
      )}
    </>
  );
}

const STEP = 0.05;

/** A pad that picks the point of the image that stays visible when it is cropped. Pointer, touch and arrow keys. */
export function FocalPad({ id, value, onChange }) {
  const padRef = useRef(null);
  const fp = Array.isArray(value) && value.length === 2 ? value : [0.5, 0.5];
  const set = (x, y) => onChange([Math.round(Math.max(0, Math.min(1, x)) * 100) / 100, Math.round(Math.max(0, Math.min(1, y)) * 100) / 100]);
  const fromEvent = (e) => {
    const r = padRef.current.getBoundingClientRect();
    onChange(focalFromPoint(e.clientX - r.left, e.clientY - r.top, r.width, r.height));
  };
  return (
    <div className="sbx-field sbx-field--stacked">
      <span className="sbx-field__label" id={`${id}-focal-label`}>{t('bg_focal')}</span>
      <div
        ref={padRef}
        id={`${id}-focal`}
        className="sbx-focal"
        role="slider"
        tabIndex={0}
        aria-labelledby={`${id}-focal-label`}
        aria-describedby={`${id}-focal-hint`}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={Math.round(fp[0] * 100)}
        aria-valuetext={`${Math.round(fp[0] * 100)}%, ${Math.round(fp[1] * 100)}%`}
        onPointerDown={(e) => { e.currentTarget.setPointerCapture(e.pointerId); fromEvent(e); }}
        onPointerMove={(e) => { if (e.currentTarget.hasPointerCapture(e.pointerId)) fromEvent(e); }}
        onKeyDown={(e) => {
          const move = { ArrowLeft: [-STEP, 0], ArrowRight: [STEP, 0], ArrowUp: [0, -STEP], ArrowDown: [0, STEP] }[e.key];
          if (!move) return;
          e.preventDefault();
          set(fp[0] + move[0], fp[1] + move[1]);
        }}
      >
        <span className="sbx-focal__dot" style={{ left: `${fp[0] * 100}%`, top: `${fp[1] * 100}%` }} />
      </div>
      <p className="sbx-hint" id={`${id}-focal-hint`}>{t('bg_focal_hint')}</p>
    </div>
  );
}
