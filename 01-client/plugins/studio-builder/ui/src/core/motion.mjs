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

/** `animation.type` — must match DocumentValidator's list, in order. */
export const ANIMATION_TYPES = Object.freeze([
  { value: 'none', label: 'None' },
  { value: 'fade_in', label: 'Fade in' },
  { value: 'fade_up', label: 'Fade up' },
  { value: 'fade_down', label: 'Fade down' },
  { value: 'scale_up', label: 'Scale up' },
  { value: 'slide_in', label: 'Slide in' },
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
]);

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

/** Coerce an arbitrary object into a document-valid `interactions` value. */
export function normalizeInteractions(raw) {
  const i = raw && typeof raw === 'object' && !Array.isArray(raw) ? raw : {};
  const trigger = TRIGGER_VALUES.has(i.trigger) ? i.trigger : '';
  if (!trigger) return {};
  const out = { trigger };
  if (i.animation && typeof i.animation === 'object' && !Array.isArray(i.animation)) {
    const anim = normalizeAnimation(i.animation);
    // A nested animation with no real type would be noise in the document.
    if (anim.type !== 'none') out.animation = anim;
  }
  return out;
}

/** True when the block carries any motion at all (drives the panel's summary). */
export function hasMotion(block) {
  const anim = block && block.animation;
  const inter = block && block.interactions;
  return Boolean(
    (anim && anim.type && anim.type !== 'none')
    || (inter && inter.trigger)
  );
}