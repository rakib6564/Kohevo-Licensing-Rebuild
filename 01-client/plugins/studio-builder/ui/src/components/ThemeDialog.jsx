// ThemeDialog — controlled editing of the supported design tokens
// (studio-builder.tokens). Each token shows its default, the value inherited
// from site branding (Settings › Branding) and the Studio override; only the
// override is editable, and only values the server's sanitizer accepts are
// stored (colours, lengths, shadows, font families — never raw CSS).

import { useEffect, useMemo, useState } from 'react';
import { Dialog } from './Dialog.jsx';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { t, errorMessage } from '../core/messages.mjs';
import { asList, asObject } from '../core/doc.mjs';
import { COLOR_CATEGORIES, isHexColor } from '../core/library.mjs';

export function ThemeDialog({ onClose, onSaved }) {
  const { transport, boot, manifest } = useEditor();
  const working = useEngineState((s) => s.working);
  const group = (asObject(working && working.settings).token_group) || 'default';
  const [data, setData] = useState(null);
  const [edits, setEdits] = useState({});
  const [problems, setProblems] = useState({});
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const canSave = !!(manifest && manifest.permissions && manifest.permissions.tokens);

  useEffect(() => {
    let alive = true;
    transport.tokens(group).then((res) => {
      if (!alive) return;
      if (res.ok) setData(res.data.tokens);
      else setError(errorMessage(res.error));
    });
    return () => { alive = false; };
  }, [transport, group]);

  const byCategory = useMemo(() => {
    const map = new Map();
    for (const tk of asList(data && data.tokens)) {
      if (!map.has(tk.category)) map.set(tk.category, []);
      map.get(tk.category).push(tk);
    }
    return [...map.entries()];
  }, [data]);

  const valueOf = (tk) => (Object.prototype.hasOwnProperty.call(edits, tk.ref) ? edits[tk.ref] : (tk.stored ?? ''));
  const set = (ref, value) => { setEdits((e) => ({ ...e, [ref]: value })); setProblems((p) => { const n = { ...p }; delete n[ref]; return n; }); };

  const save = async () => {
    if (!canSave || busy || !data) return;
    setBusy(true);
    setError(null);
    const tokens = {};
    for (const tk of asList(data.tokens)) {
      const v = valueOf(tk);
      tokens[tk.ref] = typeof v === 'string' && v.trim() !== '' ? v.trim() : null;
    }
    const res = await transport.saveTokens({ group, tokens });
    setBusy(false);
    if (!res.ok) {
      const list = asList(res.error && res.error.details && res.error.details.errors);
      const next = {};
      for (const issue of list) {
        const m = /^\$\.tokens\.(.+)$/.exec(issue.path || '');
        if (m) next[m[1]] = issue.message;
      }
      setProblems(next);
      setError(Object.keys(next).length ? t('theme_invalid') : errorMessage(res.error));
      return;
    }
    setData(res.data.tokens);
    setEdits({});
    onSaved(res.data.tokens);
  };

  return (
    <Dialog
      title={t('theme_title')}
      onClose={onClose}
      footer={(
        <>
          <button type="button" className="sbx-btn" onClick={onClose}>{t('close')}</button>
          {canSave && <button type="button" className="sbx-btn sbx-btn--primary" disabled={busy || !data} onClick={save}>{t('theme_save')}</button>}
        </>
      )}
    >
      <p className="sbx-hint">{t('theme_hint')}</p>
      <p className="sbx-hint">
        {t('theme_branding_note')}
        {boot.brandingUrl ? <> <a href={boot.brandingUrl} target="_blank" rel="noopener">{t('theme_branding_link')}</a></> : null}
      </p>
      {group !== 'default' && <p className="sbx-muted">{t('theme_group')}: <code>{group}</code></p>}
      {error && <p role="alert" className="sbx-field__problem">{error}</p>}
      {!data && !error && <p className="sbx-muted">{t('loading')}</p>}
      {byCategory.map(([category, list]) => (
        <fieldset key={category} className="sbx-fieldset sbx-theme__group">
          <legend>{category}</legend>
          {list.map((tk) => {
            const value = valueOf(tk);
            const isColor = COLOR_CATEGORIES.includes(tk.category);
            const shown = value !== '' ? value : tk.effective;
            const id = `sbx-tk-${tk.ref.replace(/\./g, '-')}`;
            const source = value !== '' ? 'theme_source_studio' : (tk.branding !== null ? 'theme_source_branding' : 'theme_source_default');
            return (
              <div key={tk.ref} className="sbx-theme__row">
                <label className="sbx-field__label" htmlFor={id}><code>{tk.ref}</code> <span className="sbx-muted">· {t(source)}</span></label>
                <div className="sbx-theme__controls">
                  {isColor && <span className="sbx-theme__swatch" aria-hidden="true" style={{ background: isHexColor(shown) ? shown : 'transparent' }} />}
                  {isColor && isHexColor(shown) && (
                    <input type="color" aria-label={`${tk.ref} colour`} value={shown} disabled={!canSave} onChange={(e) => set(tk.ref, e.target.value)} />
                  )}
                  <input
                    id={id} type="text" maxLength={200} value={value} placeholder={tk.effective} disabled={!canSave}
                    aria-invalid={problems[tk.ref] ? true : undefined}
                    onChange={(e) => set(tk.ref, e.target.value)}
                  />
                  {value !== '' && canSave && <button type="button" className="sbx-btn sbx-btn--xs" onClick={() => set(tk.ref, '')}>{t('theme_reset')}</button>}
                </div>
                {problems[tk.ref] && <p className="sbx-field__problem" role="alert">{problems[tk.ref]}</p>}
              </div>
            );
          })}
        </fieldset>
      ))}
    </Dialog>
  );
}
