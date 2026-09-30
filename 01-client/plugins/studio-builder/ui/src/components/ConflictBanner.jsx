// ConflictBanner — the explicit, blocking conflict state (and advisory notices).
//
// On a 409 the builder has NOT overwritten the newer server revision and will
// not send anything else. The user sees that local changes exist, that the
// server revision changed, and that reload is required. No auto-merge.

import { memo } from 'react';
import { useEngineState } from './EditorContext.jsx';
import { STATUS } from '../core/sync.mjs';
import { t, errorMessage } from '../core/messages.mjs';

export const ConflictBanner = memo(function ConflictBanner({ onReload, lockState }) {
  const status = useEngineState((s) => s.status);
  const conflict = useEngineState((s) => s.conflict);
  const error = useEngineState((s) => s.error);

  if (status === STATUS.CONFLICT && conflict) {
    return (
      <div className="sbx-banner sbx-banner--conflict" role="alert" data-testid="conflict-banner">
        <strong>{t('conflict_title')}</strong>
        <p>
          {t('conflict_body', { count: conflict.localChangeCount })}
          {conflict.currentRevisionId ? ` (server revision ${conflict.currentRevisionId}, yours ${conflict.expectedRevisionId ?? '—'})` : ''}
        </p>
        <button type="button" className="sbx-btn sbx-btn--primary" onClick={onReload}>{t('conflict_reload')}</button>
      </div>
    );
  }
  if (status === STATUS.ERROR && error) {
    return <div className="sbx-banner sbx-banner--error" role="alert">{errorMessage(error)}</div>;
  }
  if (lockState && lockState.otherEditor) {
    return <div className="sbx-banner sbx-banner--info" role="note">{t('lock_other')}</div>;
  }
  return null;
});
