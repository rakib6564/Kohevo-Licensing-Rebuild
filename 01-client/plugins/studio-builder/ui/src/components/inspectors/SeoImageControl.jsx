// SeoImageControl — the page's social share image (`seo.og_image_media_id`).
//
// The same picker as the block media field (the builder's own MediaDialog); a
// plain media-id input only when this user cannot pick images. Only the integer id is stored; the server re-checks that it belongs
// to the active tenant when the draft is saved, and resolves it again when the
// page is published. Nothing is uploaded, created or fetched from here.

import { useId, useState } from 'react';
import { t } from '../../core/messages.mjs';
import { mediaPickerAvailable, openMediaPicker } from '../../core/mediaPicker.mjs';
import { useMediaUrl } from '../fields/MediaControl.jsx';

export function SeoImageControl({ value, onChange, mediaPicker }) {
  const id = useId();
  const [preview, setPreview] = useState(null);
  const mediaId = Number.isInteger(value) && value > 0 ? value : null;
  const pickerAvailable = mediaPickerAvailable(mediaPicker);
  const shownUrl = useMediaUrl(mediaId);

  const pick = () => {
    openMediaPicker({
      types: 'image',
      selectedId: mediaId,
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
      {(preview || shownUrl) && mediaId ? <img className="sbx-media__preview" src={preview || shownUrl} alt="" /> : null}
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
