// InsertDialog — required setup before inserting a block.
//
// Some blocks cannot be inserted with defaults alone (core.image needs an
// image, core.button a link, a form card a form slug). The fields shown are
// exactly the block's required fields without a valid default, and the
// required parameters of its binding providers — all from the manifest.

import { useState } from 'react';
import { Dialog } from './Dialog.jsx';
import { FieldControl } from './fields/FieldControl.jsx';
import { useEditor } from './EditorContext.jsx';
import { hint } from '../core/fields.mjs';
import { asList } from '../core/doc.mjs';
import { t } from '../core/messages.mjs';

export function InsertDialog({ request, onCancel, onConfirm }) {
  const { manifest, boot } = useEditor();
  const [props, setProps] = useState({});
  const [params, setParams] = useState({});
  const complete = request.setup.every((f) => hint(f, props[f.key] ?? null) === null)
    && request.needsParams.every((slot) => asList(slot.params).every((f) => !f.required || hint(f, (params[slot.slot] || {})[f.key] ?? null) === null));

  const confirm = () => {
    const bindings = { ...request.bindings };
    request.needsParams.forEach((slot) => { bindings[slot.slot] = { provider: slot.provider, params: params[slot.slot] || {} }; });
    onConfirm(request.setup.length ? props : null, bindings);
  };

  return (
    <Dialog
      title={t('insert_dialog_title', { label: request.def.label })}
      onClose={onCancel}
      footer={(
        <>
          <button type="button" className="sbx-btn" onClick={onCancel}>{t('cancel')}</button>
          <button type="button" className="sbx-btn sbx-btn--primary" disabled={!complete} onClick={confirm}>{t('add')}</button>
        </>
      )}
    >
      <p className="sbx-hint">{t('insert_dialog_hint')}</p>
      {request.setup.map((f) => (
        <FieldControl key={f.key} field={f} value={props[f.key] ?? null} manifest={manifest} mediaPicker={boot.mediaPicker} onChange={(v) => setProps((p) => ({ ...p, [f.key]: v }))} />
      ))}
      {request.needsParams.map((slot) => asList(slot.params).map((f) => (
        <FieldControl
          key={`${slot.slot}.${f.key}`}
          field={f}
          value={(params[slot.slot] || {})[f.key] ?? null}
          manifest={manifest}
          onChange={(v) => setParams((p) => ({ ...p, [slot.slot]: { ...(p[slot.slot] || {}), [f.key]: v } }))}
        />
      )))}
    </Dialog>
  );
}
