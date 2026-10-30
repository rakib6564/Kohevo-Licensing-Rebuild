// Kohevo Studio builder — the motion vocabulary.
//
// Mirrors the server contract in `DocumentValidator::validateBlock()` exactly:
// the `animation.type` and `interactions.trigger` enums below MUST stay in step
// with the PHP lists, because the server is the only authority and will reject
// anything it does not recognise. Keeping them here (rather than inventing a UI
// -only list) is what makes the inspector produce valid operations.
//
// The renderer (`DocumentRenderer`) turns these into `.sb-animate-*` and
// `.sb-interaction-*` classes plus `data-sb-interaction-trigger`, and
// `StudioStylesheet` owns the actual keyframes — so the inspector never emits
// CSS or an animation name the renderer does not already understand.
// Every trigger offered here does something: click replays the chosen
// animation (studio-runtime.js), load plays it as the page loads, and scroll
// ties it (or a gentle drift) to the scroll position.

import { t } from './messages.mjs';

/** `animation.type` — must match DocumentValidator's list, in order. */
export const ANIMATION_TYPES = Object.freeze([
  { value: 'none', label: 'None' },
  { value: 'fade_in', label: 'Fade in' },
  { value: 'fade_up', label: 'Fade up' },
  { value: 'fade_down', label: 'Fade down' },
  { value: 'scale_up', label: 'Scale up' },
  { value: 'slide_in', label: 'Slide in' },
  { value: 'move_left', label: 'Move left' },
  { value: 'move_right', label: 'Move right' },
  { value: 'reveal_left', label: 'Reveal from left' },
  { value: 'reveal_up', label: 'Reveal upward' },
]);

/** `interactions.trigger` — must match DocumentValidator's list, in order. */
export const INTERACTION_TRIGGERS = Object.freeze([
  { value: '', label: 'None' },
  { value: 'hover', label: 'Hover' },
  { value: 'focus', label: 'Focus' },
  { value: 'click', label: 'Click' },
  { value: 'viewport-enter', label: 'Scroll into view' },
  { value: 'scroll', label: 'Scroll' },
  { value: 'load', label: 'On load' },
]);

/** Easing curves the stylesheet ships. Free-text is NOT accepted. */
export const EASINGS = Object.freeze([
  { value: 'ease', label: 'Ease' },
  { value: 'ease-out', label: 'Ease out' },
  { value: 'ease-in', label: 'Ease in' },
  { value: 'linear', label: 'Linear' },
  { value: 'cubic-bezier(.22,1,.36,1)', label: 'Smooth' },
]);

/** Duration / delay bounds, in ms. Mirrors the server-side clamp. */
export const DURATION_RANGE = Object.freeze({ min: 0, max: 4000, step: 50, default: 500 });
export const DELAY_RANGE = Object.freeze({ min: 0, max: 4000, step: 50, default: 0 });

/** The five presets offered as one-click buttons, in showcase order. */
export const MOTION_PRESETS = Object.freeze([
  { value: 'none', label: 'None', hint: 'No entrance motion' },
  { value: 'fade_up', label: 'Fade up', hint: 'Rises into place' },
  { value: 'fade_in', label: 'Fade in', hint: 'Opacity only' },
  { value: 'scale_up', label: 'Scale up', hint: 'Grows into place' },
  { value: 'slide_in', label: 'Slide', hint: 'Sweeps in from the left' },
  { value: 'move_left', label: 'Move left', hint: 'Travels in from the right' },
  { value: 'move_right', label: 'Move right', hint: 'Travels in from the left' },
  { value: 'reveal_left', label: 'Reveal', hint: 'Wipes into view from the left' },
  { value: 'reveal_up', label: 'Reveal up', hint: 'Wipes into view from the bottom' },
]);

/**
 * An option's label in the editor's language. Each option has a message key (`motion_anim_*`,
 * `motion_trigger_*`, `motion_ease_*`, `motion_preset_*`); the English `label` / `hint` above are the defaults.
 */
const slug = (s) => String(s).toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
export function motionLabel(kind, option, part = 'label') {
  const id = kind === 'ease' ? slug(option.label) : (slug(option.value) || 'none');
  const key = `motion_${kind}_${id}${part === 'hint' ? '_hint' : ''}`;
  const text = t(key);
  return text === key ? (part === 'hint' ? option.hint : option.label) : text;
}

const ANIMATION_VALUES = new Set(ANIMATION_TYPES.map((o) => o.value));
const TRIGGER_VALUES = new Set(INTERACTION_TRIGGERS.map((o) => o.value).filter(Boolean));
const EASING_VALUES = new Set(EASINGS.map((o) => o.value));

/** Clamp to the server's accepted range; non-numbers fall back to the default. */
export function clampMs(raw, range) {
  const n = Number(raw);
  if (!Number.isFinite(n)) return range.default;
  return Math.max(range.min, Math.min(range.max, Math.round(n)));
}

/**
 * Coerce an arbitrary object into a document-valid `animation` value.
 * Unknown types collapse to 'none' rather than being passed through — a stale
 * or hand-edited document must never be able to send the server an enum it
 * will reject.
 */
export function normalizeAnimation(raw) {
  const a = raw && typeof raw === 'object' && !Array.isArray(raw) ? raw : {};
  const type = ANIMATION_VALUES.has(a.type) ? a.type : 'none';
  if (type === 'none') return { type: 'none' };

  const out = { type };
  if (a.duration_ms !== undefined && a.duration_ms !== null && a.duration_ms !== '') {
    out.duration_ms = clampMs(a.duration_ms, DURATION_RANGE);
  }
  if (a.delay_ms !== undefined && a.delay_ms !== null && a.delay_ms !== '') {
    out.delay_ms = clampMs(a.delay_ms, DELAY_RANGE);
  }
  if (typeof a.easing === 'string' && EASING_VALUES.has(a.easing)) {
    out.easing = a.easing;
  }
  return out;
}

/** Coerce an arbitrary object into a document-valid `interactions` value, keeping its sticky and parallax settings. */
export function normalizeInteractions(raw) {
  const i = raw && typeof raw === 'object' && !Array.isArray(raw) ? raw : {};
  const trigger = TRIGGER_VALUES.has(i.trigger) ? i.trigger : '';
  const out = {};
  if (trigger) {
    out.trigger = trigger;
    if (i.animation && typeof i.animation === 'object' && !Array.isArray(i.animation)) {
      const anim = normalizeAnimation(i.animation);
      // A nested animation with no real type would be noise in the document.
      if (anim.type !== 'none') out.animation = anim;
    }
  }
  const sticky = normalizeSticky(i.sticky);
  if (sticky) out.sticky = sticky;
  const parallax = normalizeParallax(i.parallax);
  if (parallax) out.parallax = parallax;
  return out;
}

// ── Scroll effects (ScrollEffects.php is the server's copy of these rules) ──

export const SCROLL_DEVICES = Object.freeze(['desktop', 'tablet', 'mobile']);
export const STICKY_EDGES = Object.freeze(['top', 'bottom']);
export const STICKY_OFFSET = Object.freeze({ min: 0, max: 400 });
export const PARALLAX_LAYERS = Object.freeze(['background', 'element']);
export const PARALLAX_SPEED = Object.freeze({ min: 5, max: 80, default: 30 });

const plainObject = (v) => v && typeof v === 'object' && !Array.isArray(v);

/** A device list with only known devices, once each, in a fixed order; all three (or none) means "every device", so it is dropped. */
function normalizeDevices(raw) {
  if (!Array.isArray(raw)) return undefined;
  const list = SCROLL_DEVICES.filter((d) => raw.includes(d));
  return list.length > 0 && list.length < SCROLL_DEVICES.length ? list : undefined;
}

/** `interactions.sticky`, or null when it is off. */
export function normalizeSticky(raw) {
  if (!plainObject(raw) || !STICKY_EDGES.includes(raw.edge)) return null;
  const out = { edge: raw.edge };
  const offset = Number(raw.offset);
  if (raw.offset !== undefined && raw.offset !== '' && Number.isFinite(offset)) {
    const px = Math.max(STICKY_OFFSET.min, Math.min(STICKY_OFFSET.max, Math.round(offset)));
    if (px > 0) out.offset = px;
  }
  const devices = normalizeDevices(raw.devices);
  if (devices) out.devices = devices;
  if (raw.shadow === true) out.shadow = true;
  if (typeof raw.background === 'string' && raw.background.trim() !== '') out.background = raw.background.trim();
  if (raw.hide_on_scroll === true && raw.edge === 'top') out.hide_on_scroll = true;
  if (raw.shrink === true && raw.edge === 'top') out.shrink = true;
  return out;
}

/** `interactions.parallax`, or null when it is off. */
export function normalizeParallax(raw) {
  if (!plainObject(raw)) return null;
  const speed = Number(raw.speed);
  if (raw.speed === undefined || raw.speed === '' || !Number.isFinite(speed)) return null;
  const out = {
    layer: PARALLAX_LAYERS.includes(raw.layer) ? raw.layer : 'background',
    speed: Math.max(PARALLAX_SPEED.min, Math.min(PARALLAX_SPEED.max, Math.round(speed))),
  };
  if (raw.direction === 'down') out.direction = 'down';
  const devices = normalizeDevices(raw.devices);
  if (devices) out.devices = devices;
  return out;
}

/** True when the block carries any motion at all (drives the panel's summary). */
export function hasMotion(block) {
  const anim = block && block.animation;
  const inter = block && block.interactions;
  return Boolean(
    (anim && anim.type && anim.type !== 'none')
    || (inter && (inter.trigger || inter.sticky || inter.parallax))
  );
}