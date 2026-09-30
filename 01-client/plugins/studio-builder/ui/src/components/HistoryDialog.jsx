// HistoryDialog — recent revisions of this page; "Restore" is the server's
// rollback command (a NEW draft revision; published output is unchanged
// until an explicit publish).

import { useEffect, useState } from 'react';
import { Dialog } from './Dialog.jsx';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { t, errorMessage } from '../core/messages.mjs';
import { isAiRevision, revisionKindLabel } from '../core/review.mjs';

export function HistoryDialog({ onClose }) {
  const { transport, boot, engine } = useEditor();
  const currentId = useEngineState((s) => (s.revision ? s.revision.id : null));
  const [revisions, setRevisions] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    let alive = true;
    transport.revisions(boot.pageId, 30).then((res) => {
      if (!alive) return;
      if (res.ok) setRevisions(res.data.revisions || []);
      else setError(res.error);
    });
    return () => { alive = false; };
  }, [transport, boot.pageId, currentId]);

  const restore = async (rev) => {
    if (!window.confirm(t('history_confirm', { number: rev.revision_number }))) return;
    if (!(await engine.drain())) return;
    const ok = await engine.rollbackTo(rev.id, 'restore');
    if (ok) onClose();
  };

  return (
    <Dialog title={t('history_title')} onClose={onClose} footer={<button type="button" className="sbx-btn" onClick={onClose}>{t('close')}</button>}>
      {error && <p role="alert">{errorMessage(error)}</p>}
      {!revisions && !error && <p className="sbx-muted">{t('loading')}</p>}
      {revisions && (
        <ol className="sbx-history">
          {revisions.map((r) => (
            <li key={r.id} className="sbx-history__item">
              <span>
                <strong>#{r.revision_number}</strong> · {revisionKindLabel(r.revision_kind)}
                {isAiRevision(r) && <> <span className="sbx-badge sbx-badge--ai">{t('ai_badge')}</span></>}
                {r.summary ? ` · ${r.summary}` : ''}
                <br />
                <span className="sbx-muted">{r.created_at}</span>
              </span>
              {r.id === currentId
                ? <span className="sbx-badge">{t('history_current')}</span>
                : <button type="button" className="sbx-btn sbx-btn--xs" onClick={() => restore(r)}>{t('history_restore')}</button>}
            </li>
          ))}
        </ol>
      )}
    </Dialog>
  );
}
