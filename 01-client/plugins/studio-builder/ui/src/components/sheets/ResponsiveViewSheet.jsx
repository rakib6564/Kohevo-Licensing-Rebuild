// ResponsiveViewSheet — device preset, zoom and canvas aids on a phone (M01).
//
// The device chosen here is the CANVAS device (which breakpoint the server-rendered
// page is laid out at); it is independent of the physical device you are holding.
// Dark-mode preview and spacing guides are listed but disabled until their
// supporting work exists (theme modes, measured spacing) — never faked.

import { memo } from 'react';
import { SheetPrimitive } from './SheetPrimitive.jsx';
import { useEditor } from '../EditorContext.jsx';
import { VIEWPORTS } from '../../core/viewport.mjs';
import { ZOOM_STEPS, canZoomIn, canZoomOut, stepZoom } from '../../core/zoom.mjs';
import { t } from '../../core/messages.mjs';

function Toggle({ id, label, checked, onChange, disabled = false, note = null }) {
  return (
    <label className={`sbx-aid${disabled ? ' is-disabled' : ''}`} htmlFor={id}>
      <span className="sbx-aid__text">
        <span className="sbx-aid__label">{label}</span>
        {note && <span className="sbx-aid__note">{note}</span>}
      </span>
      <input id={id} type="checkbox" role="switch" data-testid={id} checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
    </label>
  );
}

export const ResponsiveViewSheet = memo(function ResponsiveViewSheet({ viewportKey, onViewport, percent, onClose }) {
  const { canvasView, setCanvasView } = useEditor();
  return (
    <SheetPrimitive title={t('responsive_view')} subtitle={t('responsive_view_hint')} onClose={onClose} className="sbx-responsive-sheet" testId="sheet-responsive">
      <div className="sbx-bottom-sheet__body">
        <section aria-labelledby="sbx-rv-device">
          <h3 className="sbx-more-category__title" id="sbx-rv-device">{t('device_preset')}</h3>
          <div className="sbx-rv-devices" role="group" aria-labelledby="sbx-rv-device">
            {VIEWPORTS.map((v) => (
              <button
                key={v.key}
                type="button"
                className={`sbx-btn sbx-btn--seg${viewportKey === v.key ? ' is-active' : ''}`}
                aria-pressed={viewportKey === v.key}
                data-testid={`rv-device-${v.key}`}
                onClick={() => onViewport(v.key)}
              >
                {t(v.key)} <small>{v.width}px</small>
              </button>
            ))}
          </div>
        </section>

        <section aria-labelledby="sbx-rv-zoom">
          <h3 className="sbx-more-category__title" id="sbx-rv-zoom">{t('zoom_level')}</h3>
          <div className="sbx-rv-zoom" role="group" aria-labelledby="sbx-rv-zoom">
            <button type="button" className="sbx-btn sbx-btn--seg" data-testid="rv-zoom-out" aria-label={t('zoom_out')} disabled={!canZoomOut(percent)} onClick={() => setCanvasView({ zoom: stepZoom(percent, -1) })}>−</button>
            <output className="sbx-rv-zoom__value" data-testid="rv-zoom-percent">{percent}%</output>
            <button type="button" className="sbx-btn sbx-btn--seg" data-testid="rv-zoom-in" aria-label={t('zoom_in')} disabled={!canZoomIn(percent)} onClick={() => setCanvasView({ zoom: stepZoom(percent, 1) })}>+</button>
            <button type="button" className={`sbx-btn sbx-btn--seg${canvasView.zoom === 'fit' ? ' is-active' : ''}`} aria-pressed={canvasView.zoom === 'fit'} data-testid="rv-zoom-fit" onClick={() => setCanvasView({ zoom: 'fit' })}>{t('fit')}</button>
          </div>
          <p className="sbx-hint">{ZOOM_STEPS.join(' · ')}%</p>
        </section>

        <section aria-labelledby="sbx-rv-aids">
          <h3 className="sbx-more-category__title" id="sbx-rv-aids">{t('canvas_aids')}</h3>
          <Toggle id="rv-outlines" label={t('aid_outlines')} checked={canvasView.outlines} onChange={(v) => setCanvasView({ outlines: v })} />
          <Toggle id="rv-labels" label={t('aid_labels')} checked={canvasView.labels} onChange={(v) => setCanvasView({ labels: v })} />
          <Toggle id="rv-grid" label={t('aid_grid')} checked={canvasView.grid} onChange={(v) => setCanvasView({ grid: v })} />
          <Toggle id="rv-spacing" label={t('aid_spacing')} checked={false} disabled note={t('aid_unavailable')} onChange={() => {}} />
          <Toggle id="rv-dark" label={t('aid_dark')} checked={false} disabled note={t('aid_unavailable')} onChange={() => {}} />
        </section>
      </div>
    </SheetPrimitive>
  );
});
