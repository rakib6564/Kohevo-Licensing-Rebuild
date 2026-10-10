// MediaControl — a `media_ref` value {media_id, alt, focal_point}.
//
// The builder's own image picker (MediaDialog): a preview of the chosen image, Choose / Replace / Remove, alternative
// text, and a focal point set by clicking the image. Only the integer media id is stored — never a URL or path — and
// the server re-checks that the id belongs to the active tenant (DocumentValidator `media_exists`). Without a picker
// (no permission) it falls back to a plain media-id input.

import { useEffect, useId, useState } from 'react';
import { asObject } from '../../core/doc.mjs';
import { t } from '../../core/messages.mjs';
import { mediaPickerAvailable, openMediaPicker } from '../../core/mediaPicker.mjs';
import { useEditor } from '../EditorContext.jsx';
import { FocalPointControl } from './FocalPointControl.jsx';

/** The chosen image's URL, loaded once per id (and remembered). */
export function useMediaUrl(mediaId) {
  const { mediaApi } = useEditor();
  const [url, setUrl] = useState(() => (mediaApi && mediaId ? (mediaApi.cached(mediaId) || {}).url || null : null));
  useEffect(() => {
    let live = true;
    if (!mediaApi || !mediaId) { setUrl(null); return undefined; }
    const known = mediaApi.cached(mediaId);
    if (known) setUrl(known.url);
    else mediaApi.get(mediaId).then((item) => { if (live) setUrl(item ? item.url : null); });
    return () => { live = false; };
  }, [mediaApi, mediaId]);
  return url;
}

/** `hideFocal`: the caller draws its own focal-point control (the Background pad), so the reticle is left out. */
export function MediaControl({ field, value, onChange, problem, mediaPicker, hideFocal = false }) {
  const id = useId();
  const ref = asObject(value);
  const url = useMediaUrl(ref.media_id || null);
  const available = mediaPickerAvailable(mediaPicker);

  const set = (patch) => {
    const next = { alt: ref.alt ?? '', focal_point: ref.focal_point ?? [0.5, 0.5], media_id: ref.media_id ?? null, ...patch };
    onChange(next.media_id ? next : (field.required ? next : null));
  };

  const pick = () => {
    openMediaPicker({
      types: 'image',
      selectedId: ref.media_id || null,
      onPick: (record) => {
        const mediaId = record && Number.parseInt(record.id, 10);
        if (!Number.isInteger(mediaId) || mediaId <= 0) return;
        const altVal = record.alt_text || record.alt || ref.alt || String(record.original_name || '').replace(/\.[a-z0-9]+$/i, '').slice(0, 200);
        set({ media_id: mediaId, alt: altVal });
      },
    });
  };

  const fp = Array.isArray(ref.focal_point) ? ref.focal_point : [0.5, 0.5];
  const has = !!ref.media_id;

  return (
    <fieldset className="sbx-fieldset sbx-media" aria-describedby={problem ? `${id}-problem` : undefined}>
      <legend>{field.label}{field.required ? ' *' : ''}</legend>

      {has && url && (hideFocal
        ? <img className="sbx-media__preview" src={url} alt="" />
        : (
          <FocalPointControl
            imageUrl={url}
            value={{ x: Math.round(fp[0] * 100), y: Math.round(fp[1] * 100) }}
            onChange={({ x, y }) => set({ focal_point: [x / 100, y / 100] })}
          />
        ))}

      <div className="sbx-media__row">
        {available ? (
          <button type="button" className="sbx-btn" onClick={pick} data-testid="media-choose">
            {has ? t('media_replace') : t('choose_image')}
          </button>
        ) : (
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
        )}
        {has && !field.required ? (
          <button type="button" className="sbx-btn sbx-btn--ghost" onClick={() => onChange(null)}>{t('remove')}</button>
        ) : null}
      </div>

      {has && (
        <label className="sbx-field">
          <span className="sbx-field__label">{t('alt_text')}</span>
          <input type="text" maxLength={500} value={ref.alt ?? ''} onChange={(e) => set({ alt: e.target.value })} />
        </label>
      )}
      {problem ? <p className="sbx-field__error" id={`${id}-problem`} role="alert">{t(problem)}</p> : null}
    </fieldset>
  );
}
