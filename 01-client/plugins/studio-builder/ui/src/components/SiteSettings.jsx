// SiteSettings — the site's design tokens as a compact, grouped panel (global colours, fonts, corners,
// shadows, spacing). One form serves the docked Settings › Site view and the Theme dialog, and it saves
// through the same token endpoint, so only values the server's sanitizer accepts are stored.

import { useEffect, useId, useMemo, useState } from 'react';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { t, errorMessage } from '../core/messages.mjs';
import { asList, asObject } from '../core/doc.mjs';
import { isHexColor } from '../core/library.mjs';
import { CustomCss } from './CustomCss.jsx';
import { FONT_PRESETS, groupTokens, tokenNameKey } from '../core/siteSettings.mjs';

const friendly = (tk) => {
  const key = tokenNameKey(tk.ref);
  const text = t(key);
  return text && text !== key ? text : tk.ref;
};

function TokenRow({ tk, value, canEdit, problem, onChange, swatch, listId }) {
  const id = useId();
  const shown = value !== '' ? value : tk.effective;
  return (
    <div className="sbx-ss__row">
      <label className="sbx-ss__name" htmlFor={id} title={tk.ref}>{friendly(tk)}</label>
      <div className="sbx-ss__ctl">
        {swatch && (
          isHexColor(shown)
            ? <input type="color" className="sbx-ss__swatch" aria-label={`${friendly(tk)} (${tk.ref})`} value={shown} disabled={!canEdit} onChange={(e) => onChange(e.target.value)} />
            : <span className="sbx-ss__swatch sbx-ss__swatch--static" aria-hidden="true" style={{ background: shown || 'transparent' }} />
        )}
        <input
          id={id}
          type="text"
          className="sbx-ss__text"
          maxLength={200}
          value={value}
          placeholder={tk.effective}
          disabled={!canEdit}
          list={listId}
          aria-invalid={problem ? true : undefined}
          onChange={(e) => onChange(e.target.value)}
        />
        {value !== '' && canEdit && (
          <button type="button" className="sbx-ss__reset" aria-label={`${t('theme_reset')}: ${friendly(tk)}`} title={t('theme_reset')} onClick={() => onChange('')}>×</button>
        )}
      </div>
      {problem && <p className="sbx-field__error" role="alert">{problem}</p>}
    </div>
  );
}

export function SiteSettingsForm({ onSaved, footer = null }) {
  const { transport, boot, manifest } = useEditor();
  const working = useEngineState((s) => s.working);
  const group = (asObject(working && working.settings).token_group) || 'default';
  const [data, setData] = useState(null);
  const [edits, setEdits] = useState({});
  const [problems, setProblems] = useState({});
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState(false);
  const listId = useId();
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

  const groups = useMemo(() => groupTokens(data && data.tokens), [data]);
  const valueOf = (tk) => (Object.prototype.hasOwnProperty.call(edits, tk.ref) ? edits[tk.ref] : (tk.stored ?? ''));
  const dirty = Object.keys(edits).some((ref) => {
    const tk = asList(data && data.tokens).find((x) => x.ref === ref);
    return tk && edits[ref] !== (tk.stored ?? '');
  });
  const set = (ref, value) => {
    setSaved(false);
    setEdits((e) => ({ ...e, [ref]: value }));
    setProblems((p) => { const n = { ...p }; delete n[ref]; return n; });
  };

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
    setSaved(true);
    if (onSaved) onSaved(res.data.tokens);
  };

  const actions = (
    <div className="sbx-ss__actions">
      {saved && !dirty && <span className="sbx-muted" role="status">{t('ss_saved')}</span>}
      {canSave && <button type="button" className="sbx-btn sbx-btn--primary" data-testid="site-settings-save" disabled={busy || !data || !dirty} onClick={save}>{t('ss_save')}</button>}
    </div>
  );

  return (
    <div className="sbx-ss" data-testid="site-settings">
      <p className="sbx-hint">{t('ss_intro')}</p>
      {!canSave && <p className="sbx-hint">{t('ss_readonly')}</p>}
      <p className="sbx-hint">
        {t('theme_branding_note')}
        {boot.brandingUrl ? <> <a href={boot.brandingUrl} target="_blank" rel="noopener">{t('theme_branding_link')}</a></> : null}
      </p>
      {group !== 'default' && <p className="sbx-muted">{t('theme_group')}: <code>{group}</code></p>}
      {error && <p role="alert" className="sbx-field__error">{error}</p>}
      {!data && !error && <p className="sbx-muted">{t('loading')}</p>}
      <datalist id={listId} aria-label={t('ss_font_presets')}>
        {FONT_PRESETS.map((f) => <option key={f} value={f} />)}
      </datalist>
      {groups.map((g) => {
        const cats = g.swatch ? g.categories.filter((c) => g.tokens.some((tk) => tk.category === c)) : [null];
        return (
          <details key={g.id} className="sbx-ss__group" data-ss-group={g.id} open={g.id === 'colors' || g.id === 'fonts'}>
            <summary><span>{t(g.titleKey)}</span><span className="sbx-ss__count">{g.tokens.length}</span></summary>
            {cats.map((c) => (
              <div key={c || g.id} className="sbx-ss__block">
                {c && <h4 className="sbx-ss__cat">{t(`ss_cat_${c}`)}</h4>}
                {g.tokens.filter((tk) => c === null || tk.category === c).map((tk) => (
                  <TokenRow
                    key={tk.ref}
                    tk={tk}
                    value={valueOf(tk)}
                    canEdit={canSave}
                    problem={problems[tk.ref]}
                    swatch={!!g.swatch}
                    listId={g.fonts ? listId : undefined}
                    onChange={(v) => set(tk.ref, v)}
                  />
                ))}
              </div>
            ))}
          </details>
        );
      })}
      {manifest && manifest.permissions && manifest.permissions.admin && <CustomCss />}
      {footer ? footer(actions) : <div className="sbx-ss__bar">{actions}</div>}
    </div>
  );
}
