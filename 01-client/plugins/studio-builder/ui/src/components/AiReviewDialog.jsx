// AiReviewDialog — the human review step for an AI-proposed draft (Phase 7).
//
//   AI draft revision (ai_operation) → preview of that exact revision →
//   structured diff (server-computed) → the human clicks the existing
//   Publish action, bound to the exact reviewed revision.
//
// Everything shown comes from the server's `diff` query (labels, values and
// lines are site content rendered as text). Publishing goes through the same
// SyncEngine.publish() the top bar uses: the request carries
// expected_revision_id = the reviewed revision, so a draft that changed after
// review is refused (409 → conflict state) and nothing goes live.

import { useEffect, useState } from 'react';
import { Dialog } from './Dialog.jsx';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { t, errorMessage } from '../core/messages.mjs';
import { STATUS } from '../core/sync.mjs';
import { isAiRevision, needsPublish, publishBinding, reviewLines, revisionKindLabel, summaryCounters } from '../core/review.mjs';

export function AiReviewDialog({ onClose, onPublish, review: injectedReview = null }) {
  const { transport, boot, manifest } = useEditor();
  const revision = useEngineState((s) => s.revision);
  const status = useEngineState((s) => s.status);
  const [review, setReview] = useState(injectedReview);
  const [error, setError] = useState(null);
  const canPublish = !!(manifest && manifest.permissions && manifest.permissions.publish);
  const currentId = revision ? revision.id : null;

  useEffect(() => {
    if (injectedReview || !currentId || typeof transport.diff !== 'function') return undefined;
    let alive = true;
    transport.diff(boot.pageId, null, currentId).then((res) => {
      if (!alive) return;
      if (res.ok) setReview(res.data.review);
      else setError(res.error);
    });
    return () => { alive = false; };
  }, [transport, boot.pageId, currentId, injectedReview]);

  const binding = review ? publishBinding(review, currentId) : null;
  const proposed = review ? review.proposed_revision : null;
  const base = review ? review.base_revision : null;
  const impact = review && review.publish_impact ? review.publish_impact : {};
  const busy = status === STATUS.SAVING || status === STATUS.CONFLICT;

  return (
    <Dialog
      title={t('ai_review_title')}
      onClose={onClose}
      footer={(
        <>
          <button type="button" className="sbx-btn" onClick={onClose}>{t('close')}</button>
          {review && proposed && (
            <a className="sbx-btn" href={`${boot.previewUrl}?page=${boot.pageId}&revision=${proposed.id}`} target="_blank" rel="noopener">{t('ai_review_preview')}</a>
          )}
          {review && canPublish && binding && needsPublish(review) && (
            <button type="button" className="sbx-btn sbx-btn--primary" data-testid="ai-publish" disabled={busy} onClick={() => onPublish(binding)}>{t('ai_review_publish')}</button>
          )}
        </>
      )}
    >
      <p className="sbx-muted">{t('ai_review_hint')}</p>
      {error && <p role="alert">{errorMessage(error)}</p>}
      {!review && !error && <p className="sbx-muted">{t('loading')}</p>}
      {review && (
        <div className="sbx-review" data-testid="ai-review">
          {!isAiRevision(proposed) && <p className="sbx-review__meta">{t('ai_review_no_ai')}</p>}
          <p className="sbx-review__meta">
            {proposed ? t('ai_review_proposed', { number: proposed.revision_number }) : ''}
            {' · '}
            {base ? t('ai_review_base', { number: base.revision_number, kind: revisionKindLabel(base.revision_kind) }) : t('ai_review_base_empty')}
          </p>
          <div className="sbx-review__summary">
            {summaryCounters(review).map((c) => <span key={c.key} className="sbx-badge">{t(`count_${c.key}`, { count: c.count })}</span>)}
          </div>
          <h3>{t('ai_review_changes')}</h3>
          {reviewLines(review).length === 0
            ? <p className="sbx-muted">{t('ai_review_no_changes')}</p>
            : <ol className="sbx-review__lines">{reviewLines(review).map((line, i) => <li key={i}>{line}</li>)}</ol>}
          {impact.shared_content && Number.isInteger(impact.dependent_pages) && (
            <p className="sbx-review__meta">{t('ai_review_dependency', { count: impact.dependent_pages })}</p>
          )}
          {impact.proposed_is_published && <p className="sbx-review__meta">{t('ai_review_published')}</p>}
          {!binding && proposed && !impact.proposed_is_published && <p className="sbx-banner sbx-banner--conflict" role="alert">{t('ai_review_stale')}</p>}
          {binding && canPublish && needsPublish(review) && <p className="sbx-review__meta">{t('ai_review_publish_hint', { number: proposed.revision_number })}</p>}
          {binding && !canPublish && needsPublish(review) && <p className="sbx-review__meta">{t('ai_review_no_permission')}</p>}
        </div>
      )}
    </Dialog>
  );
}
