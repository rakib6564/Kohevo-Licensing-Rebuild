// CustomCss — the site-wide stylesheet (administrators). It saves through its own endpoint, which reduces the
// CSS exactly as the public renderer does and returns what was really stored, so the author never wonders
// whether a rule was dropped.

import { useEffect, useId, useState } from 'react';
import { useEditor } from './EditorContext.jsx';
import { t, errorMessage } from '../core/messages.mjs';
import { cssState } from '../core/customCss.mjs';

export function CustomCss() {
  const { transport } = useEditor();
  const [stored, setStored] = useState(null); // { css, max_bytes }
  const [draft, setDraft] = useState('');
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState(null); // 'saved' | 'sanitized'
  const id = useId();

  useEffect(() => {
    let alive = true;
    transport.customCss().then((res) => {
      if (!alive) return;
      if (res.ok) { setStored(res.data); setDraft(res.data.css); } else setError(errorMessage(res.error));
    });
    return () => { alive = false; };
  }, [transport]);

  const max = stored ? stored.max_bytes : 65536;
  const state = cssState(draft, stored ? stored.css : '', max);

  const save = async () => {
    if (busy || !stored || !state.dirty || state.tooLarge) return;
    setBusy(true);
    setError(null);
    const res = await transport.saveCustomCss({ css: draft });
    setBusy(false);
    if (!res.ok) { setError(errorMessage(res.error)); return; }
    setStored(res.data);
    setDraft(res.data.css);
    setNote(res.data.changed ? 'sanitized' : 'saved');
  };

  return (
    <details className="sbx-ss__group" data-ss-group="css">
      <summary><span>{t('cc_title')}</span><span className="sbx-ss__count">{stored ? `${stored.bytes} B` : ''}</span></summary>
      <div className="sbx-cc" data-testid="custom-css">
        <p className="sbx-hint">{t('cc_hint')}</p>
        {error && <p role="alert" className="sbx-field__problem">{error}</p>}
        <label className="sbx-sr-only" htmlFor={id}>{t('cc_label')}</label>
        <textarea
          id={id}
          className="sbx-cc__editor"
          spellCheck={false}
          autoCapitalize="off"
          autoCorrect="off"
          rows={10}
          placeholder={t('cc_placeholder')}
          value={draft}
          disabled={!stored}
          aria-invalid={state.tooLarge ? true : undefined}
          onChange={(e) => { setDraft(e.target.value); setNote(null); }}
        />
        <div className="sbx-cc__foot">
          <span className={`sbx-muted${state.tooLarge ? ' sbx-field__problem' : ''}`}>{state.tooLarge ? t('cc_too_large') : t('cc_bytes', { n: state.bytes, max })}</span>
          <button type="button" className="sbx-btn sbx-btn--primary" data-testid="custom-css-save" disabled={busy || !stored || !state.dirty || state.tooLarge} onClick={save}>{t('cc_save')}</button>
        </div>
        {note && !state.dirty && <p className="sbx-hint" role="status">{note === 'sanitized' ? t('cc_sanitized') : t('cc_saved')}</p>}
      </div>
    </details>
  );
}
