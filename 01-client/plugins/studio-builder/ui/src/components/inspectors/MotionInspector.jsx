// MotionInspector — the Motion tab of the block inspector.
//
// Exposes the two canonical motion operations that have been server-side since
// Sprint 7 but had no UI until Phase 4:
//
//   update_block_animation     → the entrance preset + per-block timing
//   update_block_interactions  → the trigger (+ an optional hover animation)
//
// Every value goes through `motion.mjs`'s normalizers before it leaves this
// component, so the builder can only ever produce a document the server
// validator already accepts. The alternative — trusting the <select> — is how
// enums drift out of step with DocumentValidator and start failing saves.

import { asObject } from '../../core/doc.mjs';
import {
  ANIMATION_TYPES, DELAY_RANGE, DURATION_RANGE, EASINGS, INTERACTION_TRIGGERS,
  MOTION_PRESETS, clampMs, normalizeAnimation, normalizeInteractions,
} from '../../core/motion.mjs';
import * as ops from '../../core/operations.mjs';
import { t } from '../../core/messages.mjs';
import { Field } from '../ui/index.js';

/** `block` is the block (or, with `section`, the section) whose motion is edited. */
export function MotionInspector({ block, applyOp, label, section = false }) {
  const idPrefix = `sbx-motion-${block.id}`;
  const animation = normalizeAnimation(block.animation);
  const interactions = normalizeInteractions(block.interactions);
  const entranceOn = animation.type !== 'none';

  const commitAnimation = (next) => applyOp((section ? ops.updateSectionAnimation : ops.updateBlockAnimation)(block.id, next), { label });
  const commitInteractions = (next) => applyOp((section ? ops.updateSectionInteractions : ops.updateBlockInteractions)(block.id, next), { label });

  const choosePreset = (type) => {
    if (type === 'none') {
      commitAnimation({ type: 'none' });
      return;
    }
    // Switching preset keeps the user's timing — only the type changes, so
    // re-picking "fade in" after "fade up" does not silently reset a
    // hand-tuned duration.
    const next = { type };
    if (animation.duration_ms !== undefined) next.duration_ms = animation.duration_ms;
    if (animation.delay_ms !== undefined) next.delay_ms = animation.delay_ms;
    if (animation.easing) next.easing = animation.easing;
    commitAnimation(next);
  };

  return (
    <>
      <section className="sbx-motion-section">
        <h3 className="sbx-fieldset-legend">{t('motion_entrance')}</h3>
        <p className="sbx-hint">{t('motion_entrance_hint')}</p>

        {/* Presets as real radio buttons: one arrow-key tab stop, and the
            current choice is announced, which a row of buttons would not do. */}
        <fieldset className="sbx-fieldset">
          <legend className="sbx-sr-only">{t('motion_entrance')}</legend>
          <div className="sbx-motion-presets" role="radiogroup" aria-label={t('motion_entrance')}>
            {MOTION_PRESETS.map((preset) => {
              const active = animation.type === preset.value;
              return (
                <button
                  key={preset.value}
                  type="button"
                  role="radio"
                  aria-checked={active}
                  title={preset.hint}
                  className={`sbx-motion-preset${active ? ' is-active' : ''}`}
                  onClick={() => choosePreset(preset.value)}
                >
                  <span className="sbx-motion-preset__label">{preset.label}</span>
                  <span className="sbx-motion-preset__hint">{preset.hint}</span>
                </button>
              );
            })}
          </div>
        </fieldset>

        {/* The presets are a shortcut; this select is the authoritative
            control (and the only place 'none' is spelled out). */}
        <Field label={t('motion_entrance')} htmlFor={`${idPrefix}-type`}>
          <select
            id={`${idPrefix}-type`}
            value={animation.type}
            onChange={(e) => choosePreset(e.target.value)}
          >
            {ANIMATION_TYPES.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
        </Field>

        {entranceOn && (
          <fieldset className="sbx-fieldset">
            <legend>{t('motion_timing')}</legend>
            <RangeField
              id={`${idPrefix}-duration`}
              label={t('motion_duration')}
              range={DURATION_RANGE}
              value={animation.duration_ms}
              onChange={(ms) => commitAnimation({ ...animation, duration_ms: ms })}
            />
            <RangeField
              id={`${idPrefix}-delay`}
              label={t('motion_delay')}
              range={DELAY_RANGE}
              value={animation.delay_ms}
              hint="Stagger a group by giving each block a different delay."
              onChange={(ms) => commitAnimation({ ...animation, delay_ms: ms })}
            />

            <Field label={t('motion_easing')} htmlFor={`${idPrefix}-easing`}>
              <select
                id={`${idPrefix}-easing`}
                value={animation.easing || EASINGS[0].value}
                onChange={(e) => commitAnimation({ ...animation, easing: e.target.value })}
              >
                {EASINGS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
              </select>
            </Field>
          </fieldset>
        )}
      </section>

      <InteractionSection
        idPrefix={idPrefix}
        interactions={interactions}
        onChange={commitInteractions}
      />
    </>
  );
}
/** A labelled slider with a live value readout, for the timing controls. */
function RangeField({ id, label, range, value, hint, onChange }) {
  const shown = value === undefined ? range.default : value;
  return (
    <div className="sbx-field">
      <label className="sbx-field__label" htmlFor={id}>
        {label} — <output htmlFor={id}>{shown}ms</output>
      </label>
      <input
        id={id}
        type="range"
        min={range.min}
        max={range.max}
        step={range.step}
        value={shown}
        onChange={(e) => onChange(clampMs(e.target.value, range))}
      />
      {hint && <p className="sbx-hint">{hint}</p>}
    </div>
  );
}

/**
 * The trigger half of the panel.
 *
 * The optional hover/focus animation is offered only for the two triggers CSS
 * can genuinely deliver on its own — it chooses WHAT MOVES, while the trigger
 * above chooses WHEN.
 */
function InteractionSection({ idPrefix, interactions, onChange }) {
  const trigger = interactions.trigger || '';
  const hoverAnim = asObject(interactions.animation).type || 'none';

  return (
    <section className="sbx-motion-section">
      <h3 className="sbx-fieldset-legend">{t('motion_interaction')}</h3>
      <p className="sbx-hint">{t('motion_interaction_hint')}</p>

      <Field label={t('motion_interaction')} htmlFor={`${idPrefix}-trigger`}>
        <select
          id={`${idPrefix}-trigger`}
          value={trigger}
          onChange={(e) => onChange(normalizeInteractions({ trigger: e.target.value }))}
        >
          {INTERACTION_TRIGGERS.map((o) => (
            <option key={o.value || 'none'} value={o.value}>{o.label}</option>
          ))}
        </select>
        {trigger === 'scroll' && <p className="sbx-hint">{t('motion_scroll_hint')}</p>}
      </Field>

      {(trigger === 'hover' || trigger === 'focus') && (
        <Field label={t('motion_entrance')} htmlFor={`${idPrefix}-trigger-anim`}>
          <select
            id={`${idPrefix}-trigger-anim`}
            value={hoverAnim}
            onChange={(e) => onChange(normalizeInteractions({
              trigger,
              animation: { type: e.target.value },
            }))}
          >
            {ANIMATION_TYPES.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
        </Field>
      )}

      <p className="sbx-hint">{t('motion_reduced_note')}</p>
    </section>
  );
}
/** The entrance preset as one select (the reference's "Entrance Animation"); the Motion group keeps timing and triggers. */
export function EntranceSelect({ block, applyOp, label, section = false }) {
  const id = `sbx-entrance-${block.id}`;
  const animation = normalizeAnimation(block.animation);
  const commit = (next) => applyOp((section ? ops.updateSectionAnimation : ops.updateBlockAnimation)(block.id, next), { label });
  return (
    <Field label={t('entrance_animation')} htmlFor={id}>
      <select
        id={id}
        value={animation.type}
        onChange={(e) => {
          const type = e.target.value;
          if (type === 'none') { commit({ type: 'none' }); return; }
          const next = { type };
          if (animation.duration_ms !== undefined) next.duration_ms = animation.duration_ms;
          if (animation.delay_ms !== undefined) next.delay_ms = animation.delay_ms;
          if (animation.easing) next.easing = animation.easing;
          commit(next);
        }}
      >
        {MOTION_PRESETS.map((preset) => <option key={preset.value} value={preset.value}>{preset.label}</option>)}
      </select>
    </Field>
  );
}
