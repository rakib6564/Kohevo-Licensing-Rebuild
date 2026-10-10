// The one way any field opens the image picker. The shell registers its dialog host here; fields call
// openMediaPicker({ types, onPick }) with the same contract the platform's media library used (onPick gets
// { id, url, alt_text, original_name }), so a field never needs to know which picker answers.

let host = null;

/** The shell mounts its dialog and registers the opener; returns the unregister function. */
export function registerMediaHost(open) {
  host = open;
  return () => { if (host === open) host = null; };
}

const legacy = () => typeof window !== 'undefined' && window.SlateMedia && typeof window.SlateMedia.open === 'function';

/** `flag` is boot.mediaPicker: the user may pick images at all. */
export const mediaPickerAvailable = (flag) => !!flag && (host !== null || legacy());

export function openMediaPicker(opts) {
  if (host) { host(opts); return true; }
  if (legacy()) { window.SlateMedia.open(opts); return true; }
  return false;
}
