// UrlControl — a URL property control with built-in Media Library integration.
//
// Allows typing/pasting an external or relative URL, or clicking "Media Library"
// to pick/upload an asset via SlateMedia.open(). If the URL points to an image
// (or the field represents an image/cover/avatar/mockup/photo), an image preview
// is displayed.

import { coerce } from '../../core/fields.mjs';
import { t } from '../../core/messages.mjs';

function isImageTarget(field, val) {
  const key = String((field && field.key) || '').toLowerCase();
  const label = String((field && field.label) || '').toLowerCase();
  if (/(image|img|cover|mockup|thumb|avatar|photo|picture|banner|logo|icon|media)/i.test(key) ||
      /(image|img|cover|mockup|thumb|avatar|photo|picture|banner|logo|icon|media)/i.test(label)) {
    return true;
  }
  if (typeof val === 'string' && (/\.(png|jpe?g|webp|gif|svg|avif)(\?.*)?$/i.test(val) || val.includes('/uploads/media/') || val.includes('images.unsplash.com'))) {
    return true;
  }
  return false;
}

export function UrlControl({ field, draft, update, problem, common, id, mediaPicker }) {
  const isImage = isImageTarget(field, draft);
  const pickerAvailable = mediaPicker && typeof window !== 'undefined' && window.SlateMedia && typeof window.SlateMedia.open === 'function';

  const pickMedia = () => {
    window.SlateMedia.open({
      types: isImage ? 'image' : 'all',
      onPick: (record) => {
        const pickedUrl = (record && (record.url || record.path)) || '';
        if (pickedUrl) {
          update(pickedUrl);
        }
      },
    });
  };

  return (
    <div className="sbx-field sbx-url-control" aria-describedby={problem ? `${id}-problem` : undefined}>
      <label className="sbx-field__label" htmlFor={id}>
        {field.label}{field.required ? <span aria-hidden="true"> *</span> : null}
      </label>

      {isImage && draft && typeof draft === 'string' && draft.trim() ? (
        <div style={{ position: 'relative', marginBottom: '8px' }}>
          <img
            className="sbx-media__preview"
            src={draft}
            alt=""
            style={{ width: '100%', maxHeight: '120px', objectFit: 'cover', borderRadius: '6px', display: 'block', background: '#0f172a' }}
            onError={(e) => { e.currentTarget.style.display = 'none'; }}
          />
        </div>
      ) : null}

      <div className="sbx-media__row" style={{ display: 'flex', gap: '6px', alignItems: 'center', flexWrap: 'wrap' }}>
        <input
          type="text"
          inputMode="url"
          {...common}
          value={draft ?? ''}
          placeholder={t('url_placeholder_path')}
          onChange={(e) => update(coerce(field, e.target.value.trim()))}
          style={{ flex: '1 1 140px', minWidth: 0 }}
        />
        {pickerAvailable ? (
          <button
            type="button"
            className="sbx-btn"
            onClick={pickMedia}
            title={draft ? t('change') : t('choose_image')}
            style={{ display: 'inline-flex', alignItems: 'center', gap: '6px', whiteSpace: 'nowrap', padding: '0.4rem 0.65rem' }}
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
              <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
              <circle cx="8.5" cy="8.5" r="1.5"></circle>
              <polyline points="21 15 16 10 5 21"></polyline>
            </svg>
            <span>{draft ? t('change') : t('choose_image')}</span>
          </button>
        ) : null}
        {draft ? (
          <button
            type="button"
            className="sbx-btn sbx-btn--ghost"
            onClick={() => update(null)}
            title={t('remove') || 'Clear'}
            style={{ padding: '0.4rem 0.5rem' }}
          >
            {t('remove') || 'Clear'}
          </button>
        ) : null}
      </div>
      {problem ? <p className="sbx-field__error" id={`${id}-problem`} role="alert">{t(problem)}</p> : null}
    </div>
  );
}
