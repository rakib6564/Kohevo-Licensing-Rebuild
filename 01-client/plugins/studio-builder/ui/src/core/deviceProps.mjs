// Per-device prop values: the editor half of `DeviceProps.php`.
//
// A field the manifest marks `per_device` (grid columns, slides per view…) may hold its own value on tablet and mobile,
// under `block.responsive.<device>.props`, keyed by the prop's path (`columns`, `options.per_view`). Desktop is the
// block's own prop. A device without a value is "Auto": the renderer's default for that device (fewer columns on a
// phone). A cleared value is removed, and a device left with nothing is removed with it.

import { asList, asObject } from './doc.mjs';

export const PROP_DEVICES = Object.freeze(['tablet', 'mobile']);

/** The value a device sets for `path`, or undefined (Auto). */
export function readDeviceProp(responsive, device, path) {
  if (!PROP_DEVICES.includes(device)) return undefined;
  const v = asObject(asObject(asObject(responsive)[device]).props)[path];
  return v === null ? undefined : v;
}

/** `responsive` with `path` set (or, with `undefined`, cleared) on `device`. Never mutates. */
export function writeDeviceProp(responsive, device, path, value) {
  const all = { ...asObject(responsive) };
  const conf = { ...asObject(all[device]) };
  const props = { ...asObject(conf.props) };
  if (value === undefined || value === null || value === '') delete props[path];
  else props[path] = value;
  if (Object.keys(props).length) conf.props = props;
  else delete conf.props;
  if (Object.keys(conf).length) all[device] = conf;
  else delete all[device];
  return all;
}

/** True when any device sets `path`. */
export function hasDeviceProp(responsive, path) {
  return PROP_DEVICES.some((d) => readDeviceProp(responsive, d, path) !== undefined);
}

/** The choices a per-device field offers: its allowed values (an enum), or every whole number from min to max. */
export function deviceChoices(field) {
  const f = asObject(field);
  if (f.type === 'enum') return asList(f.allowed_values);
  const min = Number.isInteger(f.min) ? f.min : 1;
  const max = Number.isInteger(f.max) ? f.max : min;
  const out = [];
  for (let n = min; n <= max && out.length < 12; n++) out.push(n);
  return out;
}
