// ComponentDialog — create a global component (a `section_preset` page),
// optionally by moving the selected section into it and leaving a live
// reference in its place. Creation, the copy and the replacement happen in
// ONE server transaction guarded by expected_revision_id.

import { useMemo, useState } from 'react';
import { Dialog } from './Dialog.jsx';
import { useEngineState, useSelection } from './EditorContext.jsx';
import { t } from '../core/messages.mjs';
import { findNode, isGlobalSection } from '../core/doc.mjs';
import { slugify } from '../core/library.mjs';
import { Check } from './ui/index.js';

export function ComponentDialog({ onClose, onCreate }) {
  const { selection } = useSelection();
  const working = useEngineState((s) => s.working);
  const selectedSection = useMemo(() => {
    const info = selection ? findNode(working, selection) : null;
    return info && info.kind === 'section' && !isGlobalSection(info.node) ? info.node : null;
  }, [working, selection]);
  const [title, setTitle] = useState(selectedSection ? (selectedSection.label || '') : '');
  const [slug, setSlug] = useState('');
  const [slugTouched, setSlugTouched] = useState(false);
  const [fromSection, setFromSection] = useState(!!selectedSection);
  const [openAfter, setOpenAfter] = useState(!selectedSection);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const effectiveSlug = slugTouched ? slug : slugify(title);
  const valid = title.trim() !== '' && /^[a-z0-9]([a-z0-9-]{0,189}[a-z0-9])?$/.test(effectiveSlug);

  const submit = async () => {
    if (!valid || busy) return;
    setBusy(true);
    setError(null);
    const result = await onCreate({ title: title.trim(), slug: effectiveSlug, sectionId: fromSection && selectedSection ? selectedSection.id : null, openAfter });
    setBusy(false);
    if (result && result.error) setError(result.error);
  };

  return (
    <Dialog
      title={t('component_title')}
      onClose={onClose}
      footer={(
        <>
          <button type="button" className="sbx-btn" onClick={onClose}>{t('cancel')}</button>
          <button type="button" className="sbx-btn sbx-btn--primary" disabled={!valid || busy} onClick={submit}>{t('component_create')}</button>
        </>
      )}
    >
      <p className="sbx-hint">{t('component_hint')}</p>
      <div className="sbx-field">
        <label className="sbx-field__label" htmlFor="sbx-cmp-title">{t('component_name')}</label>
        <input id="sbx-cmp-title" type="text" maxLength={255} value={title} onChange={(e) => setTitle(e.target.value)} />
      </div>
      <div className="sbx-field">
        <label className="sbx-field__label" htmlFor="sbx-cmp-slug">{t('component_slug')}</label>
        <input id="sbx-cmp-slug" type="text" maxLength={191} value={effectiveSlug} onChange={(e) => { setSlugTouched(true); setSlug(slugify(e.target.value, 191)); }} />
      </div>
      {selectedSection && (
        <Check label={t('component_from_section')} checked={fromSection} onChange={(e) => setFromSection(e.target.checked)} />
      )}
      <Check label={t('component_open_after')} checked={openAfter} onChange={(e) => setOpenAfter(e.target.checked)} />
      {error && <p role="alert" className="sbx-field__error">{error}</p>}
    </Dialog>
  );
}
