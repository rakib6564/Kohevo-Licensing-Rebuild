// Per-device style overrides: the editor half of `StyleSurface::responsiveRules()`.
//
// Desktop is the block's own `style`. Tablet and mobile keep a partial style under
// `block.responsive.<device>.style`, limited to the paths the server accepts there (font size, padding, margin and
// gaps). The editor only edits these document values; the server turns them into @media rules and is the only
// place that writes CSS. A cleared field is removed, and an override left empty is removed with it.

import { asObject } from './doc.mjs';
import { getPath, setPath } from './styleSurface.mjs';

export const DEVICE_KEYS = Object.freeze(['desktop', 'tablet', 'mobile']);
export const OVERRIDE_DEVICES = Object.freeze(['tablet', 'mobile']);

/** The control groups that can differ per device (each is a list of style paths). */
export const RESPONSIVE_SCOPES = Object.freeze({
  size: ['typography.size'],
  spacing: ['margin.top', 'margin.right', 'margin.bottom', 'margin.left', 'padding.top', 'padding.right', 'padding.bottom', 'padding.left'],
  gap: ['layout.gap', 'layout.row_gap', 'layout.column_gap'],
});


/** The partial style a device overrides (empty for desktop, which is the block style itself). */
export function deviceOverride(responsive, device) {
  return OVERRIDE_DEVICES.includes(device) ? asObject(asObject(asObject(responsive)[device]).style) : {};
}

/** The style a control group shows for a device: the block style on desktop, otherwise only that device's own values. */
export function deviceView(style, responsive, device, paths) {
  const source = OVERRIDE_DEVICES.includes(device) ? deviceOverride(responsive, device) : asObject(style);
  return paths.reduce((acc, path) => {
    const v = getPath(source, path);
    return v === undefined ? acc : setPath(acc, path, v);
  }, {});
}

/** True when a device holds a value for any of the paths. */
export function hasOverride(responsive, device, paths) {
  const own = deviceOverride(responsive, device);
  return paths.some((path) => getPath(own, path) !== undefined);
}

/** The `responsive` object after writing `nextView` (a control group's view) for a tablet or mobile override. */
export function writeOverride(responsive, device, paths, nextView) {
  const all = { ...asObject(responsive) };
  let style = deviceOverride(all, device);
  for (const path of paths) style = setPath(style, path, getPath(nextView, path));
  const conf = { ...asObject(all[device]) };
  if (Object.keys(style).length) conf.style = style;
  else delete conf.style;
  if (Object.keys(conf).length) all[device] = conf;
  else delete all[device];
  return all;
}

/** Back to inheriting: the same as writing an empty view. */
export const clearOverride = (responsive, device, paths) => writeOverride(responsive, device, paths, {});

