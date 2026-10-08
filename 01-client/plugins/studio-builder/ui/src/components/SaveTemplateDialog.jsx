// SaveTemplateDialog — save the page, the selected section or the selected
// block as a reusable template (studio-builder.admin). The server reads the
// content from the stored working revision; only metadata travels.

import { useMemo, useState } from 'react';
import { Dialog } from './Dialog.jsx';
import { MediaControl } from './fields/MediaControl.jsx';
import { useEditor, useEngineState, useSelection } from './EditorContext.jsx';
import { t, errorMessage } from '../core/messages.mjs';
import { asList } from '../core/doc.mjs';
import { saveScopesFor, slugify } from '../core/library.mjs';

const THUMB_FIELD = Object.freeze({ key: 'thumbnail', type: 'media_ref', label: 'Thumbnail', required: false });

export function SaveTemplateDialog({ onClose, onSaved }) {
  const { transport, boot, library = null } = useEditor();
  const { selection } = useSelection();
  const working = useEngineState((s) => s.working);
  const page = useEngineState((s) => s.page);
  const scopes = useMemo(() => saveScopesFor(working, page ? page.page_type : 'page', selection), [working, page, selection]);
  const [scope, setScope] = useState(scopes.length ? scopes[scopes.length - 1].type : '');
  const [name, setName] = useState('');
  const [key, setKey] = useState('');
  const [keyTouched, setKeyTouched] = useState(false);
  const [category, setCategory] = useState('general');
  const [description, setDescription] = useState('');
  const [thumb, setThumb] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const effectiveKey = keyTouched ? key : slugify(name);
  const chosen = scopes.find((s) => s.type === scope) || null;
  const exists = asList(library && library.templates).some((x) => x.template_key === effectiveKey);
  const valid = !!chosen && name.trim() !== '' && /^[a-z0-9][a-z0-9_-]{0,119}$/.test(effectiveKey);

  const submit = async () => {
    if (!valid || busy) return;
    setBusy(true);
    setError(null);
    const res = await transport.saveTemplate({
      page_id: boot.pageId,
      node_id: chosen.nodeId,
      template_key: effectiveKey,
      template_type: chosen.type,
      category: slugify(category) || 'general',
      name: name.trim(),
      description: description.trim() || null,
      thumbnail_media_id: thumb && Number.isInteger(thumb.media_id) ? thumb.media_id : null,
    });
    setBusy(false);
    if (!res.ok) {
      const detail = res.error && res.error.details && asList(res.error.details.errors)[0];
      setError(detail && detail.message ? detail.message : errorMessage(res.error));
      return;
    }
    onSaved(res.data.template);
  };

  return (
    <Dialog
      title={t('save_template_title')}
      onClose={onClose}
      footer={(
        <>
          <button type="button" className="sbx-btn" onClick={onClose}>{t('cancel')}</button>
          <button type="button" className="sbx-btn sbx-btn--primary" disabled={!valid || busy} onClick={submit}>{t('save')}</button>
        </>
      )}
    >
      <p className="sbx-hint">{t('save_template_hint')}</p>
      {scopes.length === 0 && <p role="alert">{t('template_needs_selection')}</p>}
      <div className="sbx-field">
        <label className="sbx-field__label" htmlFor="sbx-tpl-scope">{t('template_type')}</label>
        <select id="sbx-tpl-scope" value={scope} onChange={(e) => setScope(e.target.value)}>
          {scopes.map((s) => <option key={s.type} value={s.type}>{t(s.label)}</option>)}
        </select>
      </div>
      <div className="sbx-field">
        <label className="sbx-field__label" htmlFor="sbx-tpl-name">{t('template_name')}</label>
        <input id="sbx-tpl-name" type="text" maxLength={191} value={name} onChange={(e) => setName(e.target.value)} />
      </div>
      <div className="sbx-field">
        <label className="sbx-field__label" htmlFor="sbx-tpl-key">{t('template_key')}</label>
        <input id="sbx-tpl-key" type="text" maxLength={120} value={effectiveKey} onChange={(e) => { setKeyTouched(true); setKey(slugify(e.target.value, 120)); }} />
        {exists && <p className="sbx-hint" role="note">{t('template_replace_existing')}</p>}
      </div>
      <div className="sbx-field">
        <label className="sbx-field__label" htmlFor="sbx-tpl-cat">{t('template_category')}</label>
        <input id="sbx-tpl-cat" type="text" maxLength={64} value={category} onChange={(e) => setCategory(e.target.value)} />
      </div>
      <div className="sbx-field">
        <label className="sbx-field__label" htmlFor="sbx-tpl-desc">{t('template_description')}</label>
        <textarea id="sbx-tpl-desc" rows={3} maxLength={1000} value={description} onChange={(e) => setDescription(e.target.value)} />
      </div>
      <MediaControl field={{ ...THUMB_FIELD, label: t('template_thumbnail') }} value={thumb} onChange={setThumb} mediaPicker={boot.mediaPicker} problem={null} />
      {error && <p role="alert" className="sbx-field__problem">{error}</p>}
    </Dialog>
  );
}
