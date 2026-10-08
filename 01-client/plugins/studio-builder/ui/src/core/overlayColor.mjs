// The background overlay is one colour string (the server accepts `{color}` only). Its opacity is carried in that
// colour as the alpha pair of `#rrggbbaa`, which the server's colour check already accepts and the swatch can show.
// A colour the slider cannot edit (a token, `rgba()`, a keyword) is left exactly as the author wrote it.

const HEX = /^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i;

function expand(hex) {
  const h = hex.slice(1);
  if (h.length === 3 || h.length === 4) return `#${[...h].map((c) => c + c).join('')}`;
  return `#${h}`;
}

/** @returns {{editable: boolean, base: string, percent: number}} percent is opacity 0–100; an empty overlay is black at 100. */
export function parseOverlay(value) {
  if (value === undefined || value === null || value === '') return { editable: true, base: '#000000', percent: 100 };
  if (typeof value !== 'string' || !HEX.test(value.trim())) return { editable: false, base: '#000000', percent: 100 };
  const full = expand(value.trim().toLowerCase());
  const base = full.slice(0, 7);
  const percent = full.length === 9 ? Math.round((parseInt(full.slice(7, 9), 16) / 255) * 100) : 100;
  return { editable: true, base, percent };
}

/** The colour string for a base colour at an opacity: plain `#rrggbb` when fully opaque. */
export function overlayWith(base, percent) {
  const p = Math.max(0, Math.min(100, Math.round(Number(percent))));
  if (!Number.isFinite(p) || p >= 100) return base;
  return base + Math.round((p / 100) * 255).toString(16).padStart(2, '0');
}

/** A pointer position inside a pad of `width` × `height` as a focal point in [0, 1], two decimals. */
export function focalFromPoint(x, y, width, height) {
  const clamp = (v) => Math.max(0, Math.min(1, v));
  const round = (v) => Math.round(clamp(v) * 100) / 100;
  return [round(width > 0 ? x / width : 0.5), round(height > 0 ? y / height : 0.5)];
}
