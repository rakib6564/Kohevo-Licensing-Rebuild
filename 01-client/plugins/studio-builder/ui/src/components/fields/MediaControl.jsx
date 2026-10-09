// MediaControl — a `media_ref` value {media_id, alt, focal_point}.
//
// Adapter over the EXISTING core media library picker (media-library plugin's
// `window.SlateMedia.open`) when it is available to this user; otherwise a
// plain media-id input. Only the integer media id is stored — never a URL or
// path — and the server re-checks that the id belongs to the active tenant
// (DocumentValidator `media_exists` → Media::get()). No new media storage.

import { useId, useState } from 'react';
import { asObject } from '../../core/doc.mjs';
import { t } from '../../core/messages.mjs';

/** `hideFocal`: the caller draws its own focal-point control (the Background pad), so the two sliders are left out. */
export function MediaControl({ field, value, onChange, problem, mediaPicker, hideFocal = false }) {
  const id = useId();
  const ref = asObject(value);
  const [preview, setPreview] = useState(null);
  const pickerAvailable = mediaPicker && typeof window !== 'undefined' && window.SlateMedia && typeof window.SlateMedia.open === 'function';

  const set = (patch) => {
    const next = { alt: ref.alt ?? '', focal_point: ref.focal_point ?? [0.5, 0.5], media_id: ref.media_id ?? null, ...patch };
    onChange(next.media_id ? next : (field.required ? next : null));
  };

  const pick = () => {
    window.SlateMedia.open({
      types: 'image',
      onPick: (record) => {
        const mediaId = record && Number.parseInt(record.id, 10);
        if (!Number.isInteger(mediaId) || mediaId <= 0) return;
        if (record.url && /^(https?:\/\/|\/)/.test(record.url)) setPreview(record.url);
        const altVal = record.alt_text || record.alt || ref.alt || String(record.original_name || '').replace(/\.[a-z0-9]+$/i, '').slice(0, 200);
        set({ media_id: mediaId, alt: altVal });
      },
    });
  };

  const fp = Array.isArray(ref.focal_point) ? ref.focal_point : [0.5, 0.5];

  return (
    <fieldset className="sbx-fieldset sbx-media" aria-describedby={problem ? `${id}-problem` : undefined}>
      <legend>{field.label}{field.required ? ' *' : ''}</legend>
      {preview ? <img className="sbx-media__preview" src={preview} alt="" /> : null}
      <div className="sbx-media__row">
        {pickerAvailable ? (
          <button type="button" className="sbx-btn" onClick={pick} style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
              <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
              <circle cx="8.5" cy="8.5" r="1.5"></circle>
              <polyline points="21 15 16 10 5 21"></polyline>
            </svg>
            <span>{t('choose_image')}</span>
          </button>
        ) : null}
        <label className="sbx-field sbx-field--inline">
          <span className="sbx-field__label">{t('media_id')}</span>
          <input
            type="number" min={1} step={1} inputMode="numeric"
            value={ref.media_id ?? ''}
            onChange={(e) => {
              const n = Number.parseInt(e.target.value, 10);
              set({ media_id: Number.isInteger(n) && n > 0 ? n : null });
            }}
          />
        </label>
        {ref.media_id && !field.required ? (
          <button type="button" className="sbx-btn sbx-btn--ghost" onClick={() => { setPreview(null); onChange(null); }}>{t('remove')}</button>
        ) : null}
      </div>
      <label className="sbx-field">
        <span className="sbx-field__label">{t('alt_text')}</span>
        <input type="text" maxLength={500} value={ref.alt ?? ''} onChange={(e) => set({ alt: e.target.value })} />
      </label>
      {!hideFocal && (
        <div className="sbx-media__focal" role="group" aria-label={t('focal_point')}>
          <span className="sbx-field__label">{t('focal_point')}</span>
          <input type="range" min={0} max={100} value={Math.round(fp[0] * 100)} aria-label="X" onChange={(e) => set({ focal_point: [Number(e.target.value) / 100, fp[1]] })} />
          <input type="range" min={0} max={100} value={Math.round(fp[1] * 100)} aria-label="Y" onChange={(e) => set({ focal_point: [fp[0], Number(e.target.value) / 100] })} />
        </div>
      )}
      {problem ? <p className="sbx-field__error" id={`${id}-problem`} role="alert">{t(problem)}</p> : null}
    </fieldset>
  );
}
