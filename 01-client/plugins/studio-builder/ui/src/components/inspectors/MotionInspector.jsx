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
  ANIMATION_TYPES, DELAY_RANGE, DURATION_RANGE, EASINGS, GLOW_SIZE, HOVER_IMAGE_SIZE, INTERACTION_TRIGGERS, ITEM_WIDTH, MARQUEE_SPEED, PANEL_WIDTH,
  MOTION_PRESETS, MOUSE_LEVELS, PARALLAX_SPEED, PROGRESS_HEIGHT, SCROLL_LEVELS, SCROLL_DEVICES, STAGGER_STEP, STICKY_OFFSET,
  TEXT_DIM, TEXT_EFFECT_TYPES, TEXT_STEP, WAVE_RADIUS, WAVE_WEIGHT, clampMs, motionLabel, normalizeAnimation, normalizeInteractions,
} from '../../core/motion.mjs';
import * as ops from '../../core/operations.mjs';
import { t } from '../../core/messages.mjs';
import { useState } from 'react';
import { Check, Field, Pills } from '../ui/index.js';
import { IntField, SliderField, ThemeColor } from './SurfaceControls.jsx';

/** `block` is the block whose motion is edited; `tokens` are the theme colours offered. */
export function MotionInspector({ block, applyOp, label, tokens }) {
  const idPrefix = `sbx-motion-${block.id}`;
  const animation = normalizeAnimation(block.animation);
  const interactions = normalizeInteractions(block.interactions);
  const entranceOn = animation.type !== 'none';

  const commitAnimation = (next) => applyOp(ops.updateBlockAnimation(block.id, next), { label });
  const commitInteractions = (next) => applyOp(ops.updateBlockInteractions(block.id, next), { label });

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
      <ScrollEffectsGroup
        idPrefix={idPrefix}
        interactions={interactions}
        hasBackground={block.type === 'core.container'}
        tokens={tokens}
        onChange={commitInteractions}
      />
      <PageEffectsGroup
        idPrefix={idPrefix}
        type={block.type}
        interactions={interactions}
        tokens={tokens}
        onChange={commitInteractions}
      />
      <section className="sbx-motion-section">
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

      <details className="sbx-more" data-testid="motion-more">
        <summary>{t('motion_more')}</summary>
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

      <InteractionGroup
        idPrefix={idPrefix}
        interactions={interactions}
        onChange={commitInteractions}
      />

      </details>
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
function InteractionGroup({ idPrefix, interactions, onChange }) {
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
function ScrollEffectsGroup({ idPrefix, interactions, hasBackground, tokens, onChange }) {
  const sticky = interactions.sticky || null;
  const parallax = interactions.parallax || null;
  const setSticky = (next) => onChange(normalizeInteractions({ ...interactions, sticky: next }));
  const setParallax = (next) => onChange(normalizeInteractions({ ...interactions, parallax: next }));
  const layer = parallax ? parallax.layer : (hasBackground ? 'background' : 'element');

  return (
    <section className="sbx-motion-section" data-scroll-effects>
      
      <LevelEffects
        idPrefix={`${idPrefix}-scroll`}
        title={t('scrolling_effects')}
        fields={SCROLL_LEVELS}
        value={interactions.scroll}
        onChange={(scroll) => onChange(normalizeInteractions({ ...interactions, scroll }))}
      >
        {interactions.scroll && <DeviceChecks idPrefix={`${idPrefix}-scroll`} legend={t('scroll_on')} devices={interactions.scroll.devices} onChange={(devices) => onChange(normalizeInteractions({ ...interactions, scroll: { ...interactions.scroll, devices } }))} />}
      </LevelEffects>
      <LevelEffects
        idPrefix={`${idPrefix}-mouse`}
        title={t('mouse_effects')}
        fields={MOUSE_LEVELS}
        value={interactions.mouse}
        onChange={(mouse) => onChange(normalizeInteractions({ ...interactions, mouse }))}
      />

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

    </section>
  );
}

/** Blocks that hold others: they can colour the page, carry a progress bar, take the colours beneath, and stagger. */
const BOX_TYPES = ['core.container', 'layout.container', 'layout.flex', 'layout.grid', 'core.card'];
/** Blocks made of links: a chapter nav can be one of these, or a box holding links. */
const LINK_LIST_TYPES = ['core.nav_menu', 'core.icon_list', 'core.list'];

/**
 * The page-level effects (interactions.page_colors, adapt, progress, text, stagger, glow). Each writes the whole
 * `interactions` back through the normaliser, so the settings above are kept; an effect that is switched on but has
 * nothing chosen yet is not stored.
 */
function PageEffectsGroup({ idPrefix, type, interactions, tokens, onChange }) {
  const set = (key, value) => onChange(normalizeInteractions({ ...interactions, [key]: value }));
  const isBox = BOX_TYPES.includes(type);
  const holdsLinks = isBox || LINK_LIST_TYPES.includes(type);
  const text = interactions.text || null;
  const pageColors = interactions.page_colors || null;
  const progress = interactions.progress || null;
  const stagger = interactions.stagger || null;
  const glow = interactions.glow || null;
  const marquee = interactions.marquee || null;
  const horizontal = interactions.horizontal || null;
  const hoverImage = interactions.hover_image || null;

  return (
    <section className="sbx-motion-section" data-page-effects>
      {TEXT_EFFECT_TYPES.includes(type) && (
        <>
          <Field label={t('text_effect')}>
            <Pills
              label={t('text_effect')}
              value={text ? text.effect : ''}
              options={[
                { value: '', label: t('sticky_off') },
                { value: 'light_words', label: t('text_effect_light_words') },
                { value: 'lines', label: t('text_effect_lines') },
                { value: 'wave', label: t('text_effect_wave') },
              ]}
              onChange={(v) => set('text', v ? { effect: v } : null)}
            />
          </Field>
          {text && text.effect === 'light_words' && (
            <SliderField id={`${idPrefix}-text-dim`} label={t('text_dim')} value={text.dim} min={TEXT_DIM.min} max={TEXT_DIM.max} unit="%" neutral={TEXT_DIM.default} onChange={(v) => set('text', { ...text, dim: v })} />
          )}
          {text && text.effect === 'lines' && (
            <SliderField id={`${idPrefix}-text-step`} label={t('text_step')} value={text.step_ms} min={TEXT_STEP.min} max={TEXT_STEP.max} step={10} unit="ms" neutral={TEXT_STEP.default} onChange={(v) => set('text', { ...text, step_ms: v })} />
          )}
          {text && text.effect === 'wave' && (
            <>
              <SliderField id={`${idPrefix}-wave-low`} label={t('wave_low')} value={text.low} min={WAVE_WEIGHT.min} max={WAVE_WEIGHT.max} step={50} neutral={WAVE_WEIGHT.low} onChange={(v) => set('text', { ...text, low: v })} />
              <SliderField id={`${idPrefix}-wave-high`} label={t('wave_high')} value={text.high} min={WAVE_WEIGHT.min} max={WAVE_WEIGHT.max} step={50} neutral={WAVE_WEIGHT.high} onChange={(v) => set('text', { ...text, high: v })} />
              <SliderField id={`${idPrefix}-wave-radius`} label={t('wave_radius')} value={text.radius} min={WAVE_RADIUS.min} max={WAVE_RADIUS.max} step={10} unit="px" neutral={WAVE_RADIUS.default} onChange={(v) => set('text', { ...text, radius: v })} />
            </>
          )}
          {text && <p className="sbx-hint">{t(text.effect === 'lines' ? 'text_effect_lines_hint' : text.effect === 'wave' ? 'text_effect_wave_hint' : 'text_effect_light_words_hint')}</p>}
        </>
      )}

      {isBox && (
        <>
          <SwitchSection id={`${idPrefix}-page-colors`} title={t('page_colors')} value={pageColors} onOff={() => set('page_colors', null)} testId="page-colors">
            <ThemeColor id={`${idPrefix}-page-bg`} label={t('page_colors_background')} value={pageColors && pageColors.background} tokens={tokens} onChange={(v) => set('page_colors', { ...(pageColors || {}), background: v })} />
            <ThemeColor id={`${idPrefix}-page-fg`} label={t('page_colors_text')} value={pageColors && pageColors.text} tokens={tokens} onChange={(v) => set('page_colors', { ...(pageColors || {}), text: v })} />
            <ThemeColor id={`${idPrefix}-page-muted`} label={t('page_colors_muted')} value={pageColors && pageColors.muted} tokens={tokens} onChange={(v) => set('page_colors', { ...(pageColors || {}), muted: v })} />
            <ThemeColor id={`${idPrefix}-page-line`} label={t('page_colors_line')} value={pageColors && pageColors.line} tokens={tokens} onChange={(v) => set('page_colors', { ...(pageColors || {}), line: v })} />
            <ThemeColor id={`${idPrefix}-page-accent`} label={t('page_colors_accent')} value={pageColors && pageColors.accent} tokens={tokens} onChange={(v) => set('page_colors', { ...(pageColors || {}), accent: v })} />
            <p className="sbx-hint">{t('page_colors_hint')}</p>
          </SwitchSection>
          <Check label={t('adapt_colors')} checked={interactions.adapt === true} onChange={(e) => set('adapt', e.target.checked ? true : undefined)} />
          <p className="sbx-hint">{t('adapt_colors_hint')}</p>

          <Field label={t('progress_bar')}>
            <Pills
              label={t('progress_bar')}
              value={progress ? progress.edge : ''}
              options={[
                { value: '', label: t('sticky_off') },
                { value: 'top', label: t('sticky_top') },
                { value: 'bottom', label: t('sticky_bottom') },
              ]}
              onChange={(edge) => set('progress', edge ? { ...(progress || {}), edge } : null)}
            />
          </Field>
          {progress && (
            <>
              <ThemeColor id={`${idPrefix}-progress-color`} label={t('progress_color')} value={progress.color} tokens={tokens} onChange={(v) => set('progress', { ...progress, color: v })} />
              <SliderField id={`${idPrefix}-progress-height`} label={t('progress_height')} value={progress.height} min={PROGRESS_HEIGHT.min} max={PROGRESS_HEIGHT.max} unit="px" neutral={PROGRESS_HEIGHT.default} onChange={(v) => set('progress', { ...progress, height: v })} />
            </>
          )}

          <SwitchSection id={`${idPrefix}-stagger`} title={t('stagger')} value={stagger} onOn={() => set('stagger', { step_ms: STAGGER_STEP.default })} onOff={() => set('stagger', null)} testId="stagger">
            {stagger && <SliderField id={`${idPrefix}-stagger-step`} label={t('stagger_step')} value={stagger.step_ms} min={STAGGER_STEP.min} max={STAGGER_STEP.max} step={10} unit="ms" neutral={STAGGER_STEP.default} onChange={(v) => set('stagger', { step_ms: v ?? STAGGER_STEP.default })} />}
            <p className="sbx-hint">{t('stagger_hint')}</p>
          </SwitchSection>

          {/* Marquee and sideways scroll both rearrange the children: switching one on switches the other off. */}
          <SwitchSection id={`${idPrefix}-marquee`} title={t('block_marquee')} value={marquee} onOn={() => onChange(normalizeInteractions({ ...interactions, horizontal: null, marquee: { speed: MARQUEE_SPEED.default } }))} onOff={() => set('marquee', null)} testId="block-marquee">
            {marquee && (
              <>
                <SliderField id={`${idPrefix}-marquee-speed`} label={t('block_marquee_speed')} value={marquee.speed} min={MARQUEE_SPEED.min} max={MARQUEE_SPEED.max} neutral={MARQUEE_SPEED.default} onChange={(v) => set('marquee', { ...marquee, speed: v ?? MARQUEE_SPEED.default })} />
                <Field label={t('parallax_direction')}>
                  <Pills
                    label={t('parallax_direction')}
                    value={marquee.direction === 'right' ? 'right' : 'left'}
                    options={[{ value: 'left', label: t('block_marquee_left') }, { value: 'right', label: t('block_marquee_right') }]}
                    onChange={(v) => set('marquee', { ...marquee, direction: v })}
                  />
                </Field>
                <Check label={t('block_marquee_scroll')} checked={marquee.scroll === true} onChange={(e) => set('marquee', { ...marquee, scroll: e.target.checked })} />
                <Check label={t('block_marquee_pause')} checked={marquee.pause === true} onChange={(e) => set('marquee', { ...marquee, pause: e.target.checked })} />
                <IntField id={`${idPrefix}-marquee-width`} label={t('item_width')} value={marquee.width} min={ITEM_WIDTH.min} max={ITEM_WIDTH.max} onChange={(v) => set('marquee', { ...marquee, width: v })} />
              </>
            )}
            <p className="sbx-hint">{t('block_marquee_hint')}</p>
          </SwitchSection>

          <SwitchSection id={`${idPrefix}-horizontal`} title={t('horizontal_scroll')} value={horizontal} onOn={() => onChange(normalizeInteractions({ ...interactions, marquee: null, horizontal: { offset: 0 } }))} onOff={() => set('horizontal', null)} testId="horizontal-scroll">
            {horizontal && (
              <>
                <SliderField id={`${idPrefix}-horizontal-offset`} label={t('sticky_offset')} value={horizontal.offset} min={STICKY_OFFSET.min} max={STICKY_OFFSET.max} unit="px" neutral={0} onChange={(v) => set('horizontal', { ...horizontal, offset: v ?? 0 })} />
                <IntField id={`${idPrefix}-horizontal-width`} label={t('item_width')} value={horizontal.width} min={PANEL_WIDTH.min} max={PANEL_WIDTH.max} onChange={(v) => set('horizontal', { ...horizontal, width: v })} />
              </>
            )}
            <p className="sbx-hint">{t('horizontal_scroll_hint')}</p>
          </SwitchSection>

          <SwitchSection id={`${idPrefix}-hover-image`} title={t('hover_image')} value={hoverImage} onOn={() => set('hover_image', { size: HOVER_IMAGE_SIZE.default })} onOff={() => set('hover_image', null)} testId="hover-image">
            {hoverImage && <SliderField id={`${idPrefix}-hover-image-size`} label={t('hover_image_size')} value={hoverImage.size} min={HOVER_IMAGE_SIZE.min} max={HOVER_IMAGE_SIZE.max} step={10} unit="px" neutral={HOVER_IMAGE_SIZE.default} onChange={(v) => set('hover_image', { size: v ?? HOVER_IMAGE_SIZE.default })} />}
            <p className="sbx-hint">{t('hover_image_hint')}</p>
          </SwitchSection>
        </>
      )}

      {holdsLinks && (
        <>
          <Check label={t('spy_links')} checked={interactions.spy === true} onChange={(e) => set('spy', e.target.checked ? true : undefined)} />
          <p className="sbx-hint">{t('spy_links_hint')}</p>
        </>
      )}

      <SwitchSection id={`${idPrefix}-glow`} title={t('glow')} value={glow} onOff={() => set('glow', null)} testId="glow">
        <ThemeColor id={`${idPrefix}-glow-color`} label={t('glow_color')} value={glow && glow.color} tokens={tokens} onChange={(v) => set('glow', { ...(glow || {}), color: v })} />
        {glow && <SliderField id={`${idPrefix}-glow-size`} label={t('glow_size')} value={glow.size} min={GLOW_SIZE.min} max={GLOW_SIZE.max} step={10} unit="px" neutral={GLOW_SIZE.default} onChange={(v) => set('glow', { ...glow, size: v })} />}
        <p className="sbx-hint">{t('glow_hint')}</p>
      </SwitchSection>
    </section>
  );
}

/**
 * A switch that opens its settings. On is "has a value" or "switched on here and still being filled in"; `onOn` stores
 * a default straight away (for effects that need nothing chosen), `onOff` removes the effect.
 */
function SwitchSection({ id, title, value, onOn, onOff, testId, children }) {
  const [open, setOpen] = useState(!!value);
  const on = open || !!value;
  return (
    <div className="sbx-leveleffects" data-testid={testId}>
      <div className="sbx-switchrow">
        <span id={`${id}-switch`}>{title}</span>
        <button
          type="button"
          role="switch"
          className="sbx-switch"
          aria-checked={on}
          aria-labelledby={`${id}-switch`}
          onClick={() => { setOpen(!on); if (on) onOff(); else if (onOn) onOn(); }}
        >
          <span className="sbx-switch__knob" />
        </button>
      </div>
      {on && children}
    </div>
  );
}

/** An effect with a switch, as in the reference: on, each of its levels is a slider. A level of 0 is off. */
function LevelEffects({ idPrefix, title, fields, value, onChange, children }) {
  const [open, setOpen] = useState(!!value);
  const on = open || !!value;
  return (
    <div className="sbx-leveleffects" data-testid={idPrefix.endsWith('mouse') ? 'mouse-effects' : 'scrolling-effects'}>
      <div className="sbx-switchrow">
        <span id={`${idPrefix}-switch`}>{title}</span>
        <button type="button" role="switch" className="sbx-switch" aria-checked={on} aria-labelledby={`${idPrefix}-switch`} onClick={() => { setOpen(!on); if (on) onChange(undefined); }}>
          <span className="sbx-switch__knob" />
        </button>
      </div>
      {on && Object.entries(fields).map(([key, min]) => (
        <SliderField key={key} id={`${idPrefix}-${key}`} label={t(`level_${key}`)} value={asObject(value)[key]} min={min} max={10} neutral={0} onChange={(v) => onChange({ ...asObject(value), [key]: v ?? 0 })} />
      ))}
      {children}
    </div>
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
export function EntranceSelect({ block, applyOp, label }) {
  const id = `sbx-entrance-${block.id}`;
  const animation = normalizeAnimation(block.animation);
  const commit = (next) => applyOp(ops.updateBlockAnimation(block.id, next), { label });
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
