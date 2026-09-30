// TopBar — page identity, save state, history, viewport, preview and publish.

import { memo } from 'react';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { STATUS } from '../core/sync.mjs';
import { VIEWPORTS } from '../core/viewport.mjs';
import { t, errorMessage } from '../core/messages.mjs';
import { isAiRevision } from '../core/review.mjs';

const STATUS_KEY = {
  [STATUS.LOADING]: 'status_loading', [STATUS.IDLE]: 'status_idle', [STATUS.SAVED]: 'status_saved',
  [STATUS.DIRTY]: 'status_dirty', [STATUS.SAVING]: 'status_saving', [STATUS.ERROR]: 'status_error',
  [STATUS.CONFLICT]: 'status_conflict',
};

export const SaveStatus = memo(function SaveStatus() {
  const status = useEngineState((s) => s.status);
  const error = useEngineState((s) => s.error);
  return (
    <span className={`sbx-status sbx-status--${status}`} data-status={status} title={status === STATUS.ERROR && error ? errorMessage(error) : undefined}>
      <span className="sbx-status__dot" aria-hidden="true" />
      {t(STATUS_KEY[status] || 'status_idle')}
    </span>
  );
});

export const TopBar = memo(function TopBar({ viewportKey, onViewport, onSave, onUndo, onRedo, onPublish, onHistory, onTheme = null, onAiReview = null, onPackages = null }) {
  const { boot, engine, manifest } = useEditor();
  const page = useEngineState((s) => s.page);
  const revision = useEngineState((s) => s.revision);
  const aiDraft = isAiRevision(revision);
  const status = useEngineState((s) => s.status);
  const undoCount = useEngineState((s) => s.undo.length);
  const redoCount = useEngineState((s) => s.redo.length);
  const pendingCount = useEngineState((s) => s.pending.length);
  const conflict = status === STATUS.CONFLICT;
  const busy = status === STATUS.SAVING;
  const canPublish = !!(manifest.permissions && manifest.permissions.publish);

  return (
    <header className="sbx-topbar" role="banner">
      <div className="sbx-topbar__left">
        <a className="sbx-btn sbx-btn--ghost" href={boot.pagesUrl}>← {t('back_to_pages')}</a>
        <div className="sbx-topbar__title">
          <strong>{page ? page.title : ''}</strong>
          {page && <span className="sbx-muted"> {page.public_path}{page.is_published ? (page.has_unpublished_changes ? ' · draft changes' : ' · published') : ' · not published'}</span>}
          {aiDraft && <span className="sbx-badge sbx-badge--ai" data-testid="ai-badge" title={t('ai_review_hint')}>{t('ai_badge')}</span>}
        </div>
      </div>

      <div className="sbx-topbar__center" role="toolbar" aria-label={t('viewport')}>
        {VIEWPORTS.map((v) => (
          <button
            key={v.key}
            type="button"
            className={`sbx-btn sbx-btn--seg${viewportKey === v.key ? ' is-active' : ''}`}
            aria-pressed={viewportKey === v.key}
            onClick={() => onViewport(v.key)}
            title={`${t(v.key)} (${v.breakpoint}, ${v.width}px)`}
          >
            {t(v.key)}
          </button>
        ))}
      </div>

      <div className="sbx-topbar__right">
        <SaveStatus />
        <button type="button" className="sbx-btn" onClick={onUndo} disabled={conflict || busy || undoCount === 0} aria-keyshortcuts="Control+Z Meta+Z">{t('undo')}</button>
        <button type="button" className="sbx-btn" onClick={onRedo} disabled={conflict || busy || redoCount === 0} aria-keyshortcuts="Control+Shift+Z Meta+Shift+Z">{t('redo')}</button>
        <button type="button" className="sbx-btn" onClick={onSave} disabled={conflict || pendingCount === 0} aria-keyshortcuts="Control+S Meta+S">{t('save')}</button>
        <button type="button" className="sbx-btn" onClick={onHistory} disabled={conflict}>{t('history')}</button>
        {onTheme && <button type="button" className="sbx-btn" onClick={onTheme}>{t('theme')}</button>}
        {onPackages && <button type="button" className="sbx-btn" data-testid="open-packages" onClick={onPackages} disabled={conflict}>{t('packages')}</button>}
        {aiDraft && onAiReview && <button type="button" className="sbx-btn sbx-btn--seg" data-testid="ai-review" onClick={onAiReview} disabled={conflict}>{t('ai_review')}</button>}
        {!aiDraft && boot.assistantUrl && <a className="sbx-btn" href={boot.assistantUrl} target="_blank" rel="noopener">{t('ai_assistant')}</a>}
        <a className="sbx-btn" href={`${boot.previewUrl}?page=${boot.pageId}`} target="_blank" rel="noopener">{t('preview')}</a>
        {canPublish && (
          <button type="button" className="sbx-btn sbx-btn--primary" onClick={onPublish} disabled={conflict || busy || !engine}>{t('publish')}</button>
        )}
      </div>
    </header>
  );
});
