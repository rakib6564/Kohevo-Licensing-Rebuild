// MediaDialog — the builder's own image picker: this site's images in a grid, search, upload by choosing or
// dropping a file, then "Use this image". It talks to admin/media-api.php through the media client, so it needs no
// other plugin. Opened through core/mediaPicker.mjs (registerMediaHost), so every field uses the same dialog.
// `type="video"` makes it the Video widget's picker: video files, their own wording, size and types.

import { useCallback, useEffect, useRef, useState } from 'react';
import { Dialog } from './Dialog.jsx';
import { t } from '../core/messages.mjs';
import { mediaType, toPickRecord, uploadProblem } from '../core/mediaApi.mjs';
import { registerMediaHost } from '../core/mediaPicker.mjs';

/** The message for `key` in this picker's wording (`media_use` → `media_use_video` for videos). */
const wording = (type) => (key) => (type === 'video' ? `${key}_video` : key);

export function MediaDialog({ api, type: rawType = 'image', selectedId = null, onPick, onClose }) {
  const type = mediaType(rawType);
  const k = wording(type);
  const [query, setQuery] = useState('');
  const [items, setItems] = useState([]);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [uploadError, setUploadError] = useState('');
  const [uploading, setUploading] = useState(false);
  const [over, setOver] = useState(false);
  const [chosen, setChosen] = useState(selectedId);
  const fileRef = useRef(null);
  const seq = useRef(0);

  const load = useCallback(async (q, p, append) => {
    const mine = ++seq.current;
    setLoading(true);
    setLoadError('');
    const r = await api.list({ q, page: p, type });
    if (mine !== seq.current) return;
    setLoading(false);
    if (!r.ok) { setLoadError(r.status === 403 || r.status === 401 ? 'media_err_denied' : k('media_err_load')); return; }
    setItems((cur) => (append ? [...cur, ...r.items.filter((i) => !cur.some((c) => c.id === i.id))] : r.items));
    setPage(r.page);
    setPages(r.pages);
  }, [api, type]);

  useEffect(() => {
    const h = setTimeout(() => load(query, 1, false), query ? 250 : 0);
    return () => clearTimeout(h);
  }, [query, load]);

  const upload = async (file) => {
    const problem = uploadProblem(file, type);
    if (problem) { setUploadError(problem); return; }
    setUploading(true);
    setUploadError('');
    const r = await api.upload(file, type);
    setUploading(false);
    if (!r.ok) { setUploadError(r.message ? `!${r.message}` : k('media_err_upload')); return; }
    setItems((cur) => [r.item, ...cur.filter((c) => c.id !== r.item.id)]);
    setChosen(r.item.id);
  };

  const error = uploadError || loadError;
  const picked = items.find((i) => i.id === chosen) || null;
  const use = (item) => { if (item) onPick(toPickRecord(item)); };

  return (
    <Dialog
      title={t(k('media_dialog_title'))}
      onClose={onClose}
      footer={(
        <>
          <button type="button" className="sbx-btn" onClick={onClose}>{t('cancel')}</button>
          <button type="button" className="sbx-btn sbx-btn--primary" disabled={!picked} onClick={() => use(picked)} data-testid="media-use">{t(k('media_use'))}</button>
        </>
      )}
    >
      <div
        className={`sbx-media-dialog${over ? ' is-over' : ''}`}
        data-testid="media-dialog"
        onDragOver={(e) => { if (e.dataTransfer && [...e.dataTransfer.types].includes('Files')) { e.preventDefault(); setOver(true); } }}
        onDragLeave={() => setOver(false)}
        onDrop={(e) => { e.preventDefault(); setOver(false); const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]; if (f) upload(f); }}
      >
        <div className="sbx-media-dialog__bar">
          <input type="search" className="sbx-media-dialog__search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder={t(k('media_search'))} aria-label={t(k('media_search'))} />
          <button type="button" className="sbx-btn" onClick={() => fileRef.current && fileRef.current.click()} disabled={uploading} data-testid="media-upload-btn">
            {uploading ? t('media_uploading') : t(k('media_upload'))}
          </button>
          <input ref={fileRef} type="file" accept={type === 'video' ? 'video/mp4,video/webm,video/ogg,video/quicktime,.mp4,.m4v,.webm,.ogv,.mov' : 'image/*'} hidden data-testid="media-file" onChange={(e) => { const f = e.target.files && e.target.files[0]; e.target.value = ''; if (f) upload(f); }} />
        </div>

        {error && <p className="sbx-field__error" role="alert" data-testid="media-error">{error.startsWith('!') ? error.slice(1) : t(error)}</p>}

        {!loading && items.length === 0 && !error ? (
          <p className="sbx-muted sbx-media-dialog__empty" data-testid="media-empty">{query ? t(k('media_none_found')) : t(k('media_empty'))}</p>
        ) : (
          <ul className="sbx-media-dialog__grid" aria-label={t(k('media_dialog_title'))}>
            {items.map((i) => (
              <li key={i.id}>
                <button
                  type="button"
                  className={`sbx-media-dialog__item${chosen === i.id ? ' is-chosen' : ''}`}
                  aria-pressed={chosen === i.id}
                  data-media-id={i.id}
                  title={i.original_name}
                  onClick={() => setChosen(i.id)}
                  onDoubleClick={() => use(i)}
                >
                  {type === 'video'
                    ? <video src={i.url} preload="metadata" muted playsInline aria-hidden="true" />
                    : <img src={i.url} alt="" loading="lazy" />}
                  <span className="sbx-media-dialog__name">{i.original_name}</span>
                </button>
              </li>
            ))}
          </ul>
        )}

        {loading && <p className="sbx-muted" role="status">{t('loading')}</p>}
        {!loading && page < pages && (
          <button type="button" className="sbx-btn sbx-btn--ghost sbx-media-dialog__more" onClick={() => load(query, page + 1, true)}>{t('media_load_more')}</button>
        )}
        <p className="sbx-hint">{t(k('media_drop_hint'))}</p>
      </div>
    </Dialog>
  );
}

/** Mounted once by the shell: registers itself as the picker every field opens. */
export function MediaHost({ api }) {
  const [req, setReq] = useState(null);
  useEffect(() => registerMediaHost((opts) => setReq(opts || {})), []);
  if (!req) return null;
  return (
    <MediaDialog
      api={api}
      type={req.types === 'video' ? 'video' : 'image'}
      selectedId={req.selectedId || null}
      onClose={() => setReq(null)}
      onPick={(rec) => { const cb = req.onPick; setReq(null); if (cb) cb(rec); }}
    />
  );
}
