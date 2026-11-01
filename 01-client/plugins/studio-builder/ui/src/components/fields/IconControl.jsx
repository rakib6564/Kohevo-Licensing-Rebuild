// IconControl — an icon from the built-in library: its picture, Icon Library to change it, and a button to remove it.
// The picker searches every icon by name and narrows them by category.

import { useEffect, useMemo, useState } from 'react';
import { Dialog } from '../Dialog.jsx';
import { useEditor } from '../EditorContext.jsx';
import { loadIcons, searchIcons } from '../../core/iconLibrary.mjs';
import { t } from '../../core/messages.mjs';

/** Only ever markup from the library file, keyed by name; a stored name is never written into the page. */
const Glyph = ({ markup, size = 24 }) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" dangerouslySetInnerHTML={{ __html: markup || '' }} />
);

const PAGE = 240;

function IconDialog({ library, selected, onPick, onClose }) {
  const [query, setQuery] = useState('');
  const [category, setCategory] = useState('');
  const [shown, setShown] = useState(PAGE);
  const names = useMemo(() => searchIcons(library, query, category), [library, query, category]);
  useEffect(() => setShown(PAGE), [query, category]);
  return (
    <Dialog title={t('icon_library')} onClose={onClose} className="sbx-icon-dialog">
      <input type="search" className="sbx-icon-dialog__search" value={query} placeholder={t('icon_search')} aria-label={t('icon_search')} autoFocus onChange={(e) => setQuery(e.target.value)} />
      <div className="sbx-icon-dialog__cats">
        <button type="button" aria-pressed={category === ''} onClick={() => setCategory('')}>{t('icon_all')}</button>
        {library.categories.map((c) => <button type="button" key={c.key} aria-pressed={category === c.key} onClick={() => setCategory(c.key)}>{t(`icon_cat_${c.key}`)}</button>)}
      </div>
      <div className="sbx-icon-dialog__grid" data-testid="icon-grid">
        {names.slice(0, shown).map((name) => (
          <button type="button" key={name} title={name} aria-label={name} aria-pressed={name === selected} onClick={() => onPick(name)}>
            <Glyph markup={library.icons[name]} />
          </button>
        ))}
      </div>
      {names.length === 0 && <p className="sbx-hint">{t('icon_none_found')}</p>}
      {names.length > shown && <button type="button" className="sbx-btn" onClick={() => setShown(shown + PAGE)}>{t('icon_show_more')}</button>}
    </Dialog>
  );
}

export function IconControl({ value, empty, onChange }) {
  const { boot } = useEditor();
  const [library, setLibrary] = useState(null);
  const [open, setOpen] = useState(false);
  const has = !!value && value !== 'none';
  useEffect(() => {
    let alive = true;
    if (has && boot.iconsUrl) loadIcons(boot.iconsUrl).then((l) => { if (alive) setLibrary(l); });
    return () => { alive = false; };
  }, [has, boot.iconsUrl]);
  const choose = () => loadIcons(boot.iconsUrl).then((l) => { setLibrary(l); setOpen(true); });
  return (
    <div className="sbx-icon-control" data-testid="icon-control">
      <div className="sbx-icon-control__preview">{has ? <Glyph size={36} markup={library && library.icons[value]} /> : <span className="sbx-hint">{t('icon_none')}</span>}</div>
      <div className="sbx-icon-control__actions">
        <button type="button" className="sbx-btn sbx-btn--xs" onClick={choose}>{t('icon_library')}</button>
        {has && <button type="button" className="sbx-btn sbx-btn--xs" onClick={() => onChange(empty)}>{t('icon_remove')}</button>}
      </div>
      {open && library && <IconDialog library={library} selected={value} onClose={() => setOpen(false)} onPick={(name) => { onChange(name); setOpen(false); }} />}
    </div>
  );
}
