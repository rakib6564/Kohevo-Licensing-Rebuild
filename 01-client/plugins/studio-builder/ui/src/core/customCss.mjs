// Site-wide custom CSS: the byte size the server measures (UTF-8) and whether a draft may be sent.

export const byteLength = (text) => (typeof TextEncoder !== 'undefined' ? new TextEncoder().encode(String(text)).length : unescape(encodeURIComponent(String(text))).length);

/** { bytes, tooLarge, dirty } for a draft against the stored stylesheet and the server ceiling. */
export function cssState(draft, stored, max) {
  const bytes = byteLength(draft);
  return { bytes, tooLarge: bytes > max, dirty: String(draft).trim() !== String(stored).trim() };
}
