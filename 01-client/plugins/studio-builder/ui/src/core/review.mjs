// Kohevo Studio builder — AI draft review helpers (Phase 7).
//
// Framework-free rules for the human review of an AI-proposed draft: how an
// AI revision is recognised (its recorded kind — the server decides it from
// the actor's origin, the client never sends it), how the structured diff
// the server returns is summarised for people, and how the publish action
// is bound to the exact reviewed revision. Every string coming from the
// review payload (labels, values, lines) is site content and is rendered as
// text only.

export const AI_REVISION_KIND = 'ai_operation';

/** True when a revision summary is an AI-produced draft revision. */
export function isAiRevision(revision) {
  return !!(revision && revision.revision_kind === AI_REVISION_KIND);
}

/** Human labels for revision kinds (history list, badges). */
export function revisionKindLabel(kind) {
  switch (kind) {
    case AI_REVISION_KIND: return 'AI draft';
    case 'autosave': return 'autosave';
    case 'manual': return 'saved';
    case 'publish': return 'published';
    case 'rollback': return 'restored';
    case 'import': return 'imported';
    default: return String(kind || '');
  }
}

/**
 * Compact counters for the review header, from the server's `diff.summary`.
 * @returns {Array<{key: string, count: number}>} non-zero counters only
 */
export function summaryCounters(review) {
  const s = review && review.diff && review.diff.summary ? review.diff.summary : {};
  const keys = [
    'sections_added', 'sections_removed', 'sections_moved', 'sections_updated',
    'blocks_added', 'blocks_removed', 'blocks_moved', 'blocks_updated',
  ];
  const out = keys.filter((k) => Number.isInteger(s[k]) && s[k] > 0).map((k) => ({ key: k, count: s[k] }));
  if (s.settings_changed) out.push({ key: 'settings_changed', count: 1 });
  if (s.seo_changed) out.push({ key: 'seo_changed', count: 1 });
  if (s.template_changed) out.push({ key: 'template_changed', count: 1 });
  return out;
}

/** The server's human-readable lines, bounded and always an array of strings. */
export function reviewLines(review, max = 200) {
  const lines = review && review.diff && Array.isArray(review.diff.lines) ? review.diff.lines : [];
  return lines.filter((l) => typeof l === 'string').slice(0, max);
}

/**
 * The publish binding: publishing an AI draft must target the EXACT reviewed
 * revision. Returns null when the reviewed revision is not the page's
 * current draft any more (the draft moved on — the reviewer must reload).
 */
export function publishBinding(review, currentRevisionId) {
  const proposed = review && review.proposed_revision ? review.proposed_revision.id : null;
  const impact = review && review.publish_impact ? review.publish_impact : {};
  if (!proposed || !impact.proposed_is_current_draft) return null;
  if (currentRevisionId && currentRevisionId !== proposed) return null;
  return { page_id: review.page.id, expected_revision_id: proposed };
}

/** Whether there is anything to publish: the proposed revision differs from what is live. */
export function needsPublish(review) {
  const impact = review && review.publish_impact ? review.publish_impact : null;
  return !!(impact && impact.requires_publish);
}
