// InspectorBar — Reset · Discard · Apply, pinned under the Inspector's controls.
//
//   Reset    clears this block's look (style, device overrides, states) back to inherited; its content stays.
//   Discard  puts the block back to how it was when it was selected.
//   Apply    saves now, instead of waiting for the autosave.
//
// Edits already show on the canvas as they are made and save by themselves, so none of this is needed to keep work;
// the bar is for taking it back (both are one undo step) and for saving on the spot.

import { useRef } from 'react';
import { useEditor, useEngineState } from '../EditorContext.jsx';
import { STATUS } from '../../core/sync.mjs';
import { discardOps, editableState, hasChanged, hasStyle, resetOps } from '../../core/inspectorBar.mjs';
import { t } from '../../core/messages.mjs';

export function InspectorBar({ block, label }) {
  const { engine, applyOp, announce } = useEditor();
  const status = useEngineState((s) => s.status);
  // The block as it was when it was selected: captured once per selection, never moved by later edits.
  const baseline = useRef({ id: null, state: null });
  if (baseline.current.id !== block.id) baseline.current = { id: block.id, state: editableState(block) };

  const canReset = hasStyle(block);
  const canDiscard = hasChanged(block, baseline.current.state);
  const canApply = status === STATUS.DIRTY || status === STATUS.ERROR;

  const run = (operations, message) => {
    // Queued in one go, so they leave in one batch: one undo step.
    for (const operation of operations) applyOp(operation, { label });
    announce(message);
  };

  return (
    <div className="sbx-inspector__bar" role="group" aria-label={t('inspector_bar')} data-testid="inspector-bar">
      <button type="button" className="sbx-btn" disabled={!canReset} title={t('inspector_reset_hint')} onClick={() => run(resetOps(block), t('announce_reset'))}>{t('inspector_reset')}</button>
      <button type="button" className="sbx-btn" disabled={!canDiscard} title={t('inspector_discard_hint')} onClick={() => run(discardOps(block, baseline.current.state), t('announce_discarded'))}>{t('inspector_discard')}</button>
      <button type="button" className="sbx-btn sbx-btn--primary" disabled={!canApply} title={t('inspector_apply_hint')} onClick={() => engine.save()}>{t('inspector_apply')}</button>
    </div>
  );
}
