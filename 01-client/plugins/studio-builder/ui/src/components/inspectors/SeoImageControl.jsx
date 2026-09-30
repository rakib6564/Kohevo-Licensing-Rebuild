// SeoImageControl — the page's social share image (`seo.og_image_media_id`).
//
// The same adapter as the block media field: the core media library picker
// (`window.SlateMedia.open`) when this user has it, otherwise a plain media-id
// input. Only the integer id is stored; the server re-checks that it belongs
// to the active tenant when the draft is saved, and resolves it again when the
// page is published. Nothing is uploaded, created or fetched from here.

import { useId, useState } from 'react';
import { t } from '../../core/messages.mjs';

export function SeoImageControl({ value, onChange, mediaPicker }) {
  const id = useId();
  const [preview, setPreview] = useState(null);
  const mediaId = Number.isInteger(value) && value > 0 ? value : null;
  const pickerAvailable = mediaPicker && typeof window !== 'undefined' && window.SlateMedia && typeof window.SlateMedia.open === 'function';

  const pick = () => {
    window.SlateMedia.open({
      types: 'image',
      onPick: (record) => {
        const picked = record && Number.parseInt(record.id, 10);
        if (!Number.isInteger(picked) || picked <= 0) return;
        setPreview(record.url && /^(https?:\/\/|\/)/.test(record.url) ? record.url : null);
        onChange(picked);
      },
    });
  };

  return (
    <fieldset className="sbx-fieldset sbx-media" data-testid="seo-og-image" aria-describedby={`${id}-hint`}>
      <legend>{t('seo_og_image_label')}</legend>
      {preview && mediaId ? <img className="sbx-media__preview" src={preview} alt="" /> : null}
      <div className="sbx-media__row">
        {pickerAvailable ? <button type="button" className="sbx-btn" onClick={pick}>{t('choose_image')}</button> : null}
        <label className="sbx-field sbx-field--inline">
          <span className="sbx-field__label">{t('media_id')}</span>
          <input
            type="number" min={1} step={1} inputMode="numeric"
            value={mediaId ?? ''}
            onChange={(e) => {
              const n = Number.parseInt(e.target.value, 10);
              setPreview(null);
              onChange(Number.isInteger(n) && n > 0 ? n : null);
            }}
          />
        </label>
        {mediaId ? <button type="button" className="sbx-btn sbx-btn--ghost" onClick={() => { setPreview(null); onChange(null); }}>{t('seo_remove_image')}</button> : null}
      </div>
      <p className="sbx-hint" id={`${id}-hint`}>{t('seo_og_image_hint')}</p>
    </fieldset>
  );
}
