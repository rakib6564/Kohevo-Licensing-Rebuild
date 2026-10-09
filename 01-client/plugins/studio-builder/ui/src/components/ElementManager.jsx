// ElementManager — administrators switch block types off or on for this site and see where each is used.
// A switched-off element disappears from the Add panel and can no longer be inserted; blocks already on pages
// keep rendering and stay editable. Saving replaces the site's whole list (the server validates every type).

import { useEffect, useId, useMemo, useState } from 'react';
import { useEditor } from './EditorContext.jsx';
import { renderBlockIcon } from './blockIcons.jsx';
import { t, errorMessage } from '../core/messages.mjs';
import { blockCategoryLabel } from './AddPanelParts.jsx';
import { disabledInUse, disabledSet, groupElements, isChanged, unusedTypes, usageOf } from '../core/elementManager.mjs';

function UsageNote({ element }) {
  const u = usageOf(element);
  if (u.blocks === 0) return <span className="sbx-em__usage sbx-em__usage--none">{t('em_usage_none')}</span>;
  return <span className="sbx-em__usage" title={u.sample.join(' · ')}>{t('em_usage', { blocks: u.blocks, pages: u.pages })}</span>;
}

export function ElementManager() {
  const { transport, refreshManifest } = useEditor();
  const [data, setData] = useState(null);
  const [selection, setSelection] = useState(() => new Set());
  const [query, setQuery] = useState('');
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState(false);
  const searchId = useId();

  const load = (alive = () => true) => transport.elements().then((res) => {
    if (!alive()) return;
    if (res.ok) {
      setData(res.data);
      setSelection(disabledSet(res.data.elements));
    } else setError(errorMessage(res.error));
  });

  useEffect(() => {
    let alive = true;
    load(() => alive);
    return () => { alive = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [transport]);

  const elements = data ? data.elements : [];
  const groups = useMemo(() => groupElements(elements, query, blockCategoryLabel), [elements, query]);
  const changed = data ? isChanged(elements, selection) : false;
  const stillUsed = disabledInUse(elements, selection);

  const toggle = (type) => {
    setSaved(false);
    setSelection((prev) => {
      const next = new Set(prev);
      if (next.has(type)) next.delete(type); else next.add(type);
      return next;
    });
  };

  const save = async () => {
    if (busy || !changed) return;
    setBusy(true);
    setError(null);
    const res = await transport.saveElements({ disabled: [...selection].sort() });
    setBusy(false);
    if (!res.ok) { setError(errorMessage(res.error)); return; }
    setSaved(true);
    await load();
    if (refreshManifest) await refreshManifest();
  };

  return (
    <div className="sbx-em" data-testid="element-manager">
      <p className="sbx-hint">{t('em_intro')}</p>
      {error && <p role="alert" className="sbx-field__error">{error}</p>}
      {!data && !error && <p className="sbx-muted">{t('loading')}</p>}
      {data && (
        <>
          <label className="sbx-sr-only" htmlFor={searchId}>{t('em_search')}</label>
          <input id={searchId} type="search" className="sbx-input" placeholder={t('em_search')} value={query} onChange={(e) => setQuery(e.target.value)} />
          <div className="sbx-em__bulk">
            <button type="button" className="sbx-btn sbx-btn--xs" data-em-action="enable-all" onClick={() => { setSaved(false); setSelection(new Set()); }}>{t('em_enable_all')}</button>
            <button type="button" className="sbx-btn sbx-btn--xs" data-em-action="disable-unused" onClick={() => { setSaved(false); setSelection(new Set(unusedTypes(elements))); }}>{t('em_disable_unused')}</button>
          </div>
          {groups.length === 0 && <p className="sbx-muted">{t('em_empty')}</p>}
          {groups.map((g) => (
            <section key={g.category} className="sbx-em__group" aria-label={blockCategoryLabel(g.category)}>
              <h4 className="sbx-ss__cat">{blockCategoryLabel(g.category)}</h4>
              {g.items.map((e) => {
                const on = !selection.has(e.type);
                return (
                  <div key={e.type} className={`sbx-em__row${on ? '' : ' is-off'}`} data-element={e.type}>
                    <span className="sbx-em__icon" aria-hidden="true">{renderBlockIcon(e.type, e.icon, e.title)}</span>
                    <span className="sbx-em__text">
                      <span className="sbx-em__title">{e.title}</span>
                      <UsageNote element={e} />
                    </span>
                    <button
                      type="button"
                      role="switch"
                      aria-checked={on}
                      aria-label={t('em_toggle', { name: e.title })}
                      className="sbx-switch"
                      onClick={() => toggle(e.type)}
                    ><span className="sbx-switch__knob" /></button>
                  </div>
                );
              })}
            </section>
          ))}
          <p className="sbx-muted sbx-em__scan">{t('em_scanned', { n: data.scanned_pages })}{data.truncated ? ` ${t('em_truncated')}` : ''}</p>
          {stillUsed.length > 0 && <p className="sbx-hint" role="status">{t('em_in_use', { n: stillUsed.length })}</p>}
          <div className="sbx-ss__bar">
            <div className="sbx-ss__actions">
              {saved && !changed && <span className="sbx-muted" role="status">{t('em_saved')}</span>}
              <button type="button" className="sbx-btn sbx-btn--primary" data-testid="element-manager-save" disabled={busy || !changed} onClick={save}>{t('em_save')}</button>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
