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
  MOTION_PRESETS, PARALLAX_SPEED, SCROLL_DEVICES, STICKY_OFFSET, clampMs, motionLabel, normalizeAnimation, normalizeInteractions,
} from '../../core/motion.mjs';
import * as ops from '../../core/operations.mjs';
import { t } from '../../core/messages.mjs';
import { Check, Field, Pills } from '../ui/index.js';
import { SliderField, ThemeColor } from './SurfaceControls.jsx';

/** `block` is the block (or, with `section`, the section) whose motion is edited; `tokens` are the theme colours offered. */
export function MotionInspector({ block, applyOp, label, section = false, tokens }) {
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
                  title={motionLabel('preset', preset, 'hint')}
                  className={`sbx-motion-preset${active ? ' is-active' : ''}`}
                  onClick={() => choosePreset(preset.value)}
                >
                  <span className="sbx-motion-preset__label">{motionLabel('preset', preset)}</span>
                  <span className="sbx-motion-preset__hint">{motionLabel('preset', preset, 'hint')}</span>
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
            {ANIMATION_TYPES.map((o) => <option key={o.value} value={o.value}>{motionLabel('anim', o)}</option>)}
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
                {EASINGS.map((o) => <option key={o.value} value={o.value}>{motionLabel('ease', o)}</option>)}
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

      <ScrollEffectsSection
        idPrefix={idPrefix}
        interactions={interactions}
        hasBackground={section || block.type === 'core.container'}
        tokens={tokens}
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
          onChange={(e) => onChange(normalizeInteractions({ ...interactions, trigger: e.target.value, animation: undefined }))}
        >
          {INTERACTION_TRIGGERS.map((o) => (
            <option key={o.value || 'none'} value={o.value}>{motionLabel('trigger', o)}</option>
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
              ...interactions,
              trigger,
              animation: { type: e.target.value },
            }))}
          >
            {ANIMATION_TYPES.map((o) => <option key={o.value} value={o.value}>{motionLabel('anim', o)}</option>)}
          </select>
        </Field>
      )}

      <p className="sbx-hint">{t('motion_reduced_note')}</p>
    </section>
  );
}
/**
 * Sticky and parallax (interactions.sticky / interactions.parallax). Off is the absence of the object; each control
 * writes the whole `interactions` back through the normaliser, so the trigger above is kept.
 */
function ScrollEffectsSection({ idPrefix, interactions, hasBackground, tokens, onChange }) {
  const sticky = interactions.sticky || null;
  const parallax = interactions.parallax || null;
  const setSticky = (next) => onChange(normalizeInteractions({ ...interactions, sticky: next }));
  const setParallax = (next) => onChange(normalizeInteractions({ ...interactions, parallax: next }));
  const layer = parallax ? parallax.layer : (hasBackground ? 'background' : 'element');

  return (
    <section className="sbx-motion-section" data-scroll-effects>
      <h3 className="sbx-fieldset-legend">{t('scroll_effects')}</h3>

      <Field label={t('sticky')}>
        <Pills
          label={t('sticky')}
          value={sticky ? sticky.edge : ''}
          options={[
            { value: '', label: t('sticky_off') },
            { value: 'top', label: t('sticky_top') },
            { value: 'bottom', label: t('sticky_bottom') },
          ]}
          onChange={(edge) => setSticky(edge ? { ...(sticky || {}), edge } : null)}
        />
      </Field>
      {sticky && (
        <>
          <SliderField
            id={`${idPrefix}-sticky-offset`}
            label={t('sticky_offset')}
            value={sticky.offset}
            min={STICKY_OFFSET.min}
            max={STICKY_OFFSET.max}
            unit="px"
            neutral={0}
            onChange={(v) => setSticky({ ...sticky, offset: v })}
          />
          <DeviceChecks idPrefix={`${idPrefix}-sticky`} legend={t('sticky_on')} devices={sticky.devices} onChange={(devices) => setSticky({ ...sticky, devices })} />
          <p className="sbx-hint">{t('sticky_effects_hint')}</p>
          <Check label={t('sticky_shadow')} checked={sticky.shadow === true} onChange={(e) => setSticky({ ...sticky, shadow: e.target.checked })} />
          {sticky.edge === 'top' && (
            <>
            <Check label={t('sticky_hide_on_scroll')} checked={sticky.hide_on_scroll === true} onChange={(e) => setSticky({ ...sticky, hide_on_scroll: e.target.checked })} />
            <Check label={t('sticky_shrink')} checked={sticky.shrink === true} onChange={(e) => setSticky({ ...sticky, shrink: e.target.checked })} />
            </>
          )}
          <ThemeColor id={`${idPrefix}-sticky-bg`} label={t('sticky_background')} value={sticky.background} tokens={tokens} onChange={(v) => setSticky({ ...sticky, background: v })} />
        </>
      )}

      <Field label={t('parallax')}>
        <Pills
          label={t('parallax')}
          value={parallax ? parallax.layer : ''}
          options={[
            { value: '', label: t('sticky_off') },
            ...(hasBackground ? [{ value: 'background', label: t('parallax_background') }] : []),
            { value: 'element', label: t('parallax_element') },
          ]}
          onChange={(v) => setParallax(v ? { speed: PARALLAX_SPEED.default, ...(parallax || {}), layer: v } : null)}
        />
      </Field>
      {parallax && (
        <>
          {layer === 'background' && <p className="sbx-hint">{t('parallax_background_hint')}</p>}
          <SliderField
            id={`${idPrefix}-parallax-speed`}
            label={t('parallax_speed')}
            value={parallax.speed}
            min={PARALLAX_SPEED.min}
            max={PARALLAX_SPEED.max}
            unit="%"
            neutral={PARALLAX_SPEED.default}
            onChange={(v) => setParallax({ ...parallax, speed: v ?? PARALLAX_SPEED.default })}
          />
          <Field label={t('parallax_direction')}>
            <Pills
              label={t('parallax_direction')}
              value={parallax.direction === 'down' ? 'down' : 'up'}
              options={[{ value: 'up', label: t('parallax_up') }, { value: 'down', label: t('parallax_down') }]}
              onChange={(v) => setParallax({ ...parallax, direction: v })}
            />
          </Field>
          <DeviceChecks idPrefix={`${idPrefix}-parallax`} legend={t('parallax_on')} devices={parallax.devices} onChange={(devices) => setParallax({ ...parallax, devices })} />
        </>
      )}
      <p className="sbx-hint">{t('scroll_effects_hint')}</p>
    </section>
  );
}

/** Which devices an effect runs on: every device when none is unticked. Unticking the last one is refused. */
export function DeviceChecks({ idPrefix, legend, devices, onChange }) {
  const on = Array.isArray(devices) && devices.length ? devices : SCROLL_DEVICES;
  return (
    <fieldset className="sbx-fieldset" id={idPrefix + '-devices'}>
      <legend>{legend}</legend>
      <div className="sbx-check-row">
        {SCROLL_DEVICES.map((d) => (
          <Check
            key={d}
            label={t(`device_${d}`)}
            checked={on.includes(d)}
            disabled={on.length === 1 && on.includes(d)}
            onChange={(e) => onChange(e.target.checked ? [...on, d] : on.filter((x) => x !== d))}
          />
        ))}
      </div>
    </fieldset>
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
        {MOTION_PRESETS.map((preset) => <option key={preset.value} value={preset.value}>{motionLabel('preset', preset)}</option>)}
      </select>
    </Field>
  );
}
