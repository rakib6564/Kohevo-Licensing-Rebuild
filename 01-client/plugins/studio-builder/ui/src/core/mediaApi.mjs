// Client for the builder's own image picker endpoint (admin/media-api.php): list this site's images, show given
// ones, upload one. With `type: 'video'` the same for video files (the Video widget). The server owns every rule
// (tenant, type, size, SVG clean-up); this only moves JSON and one file.

export const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;
export const MAX_VIDEO_UPLOAD_BYTES = 256 * 1024 * 1024;
const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
const VIDEO_TYPES = ['video/mp4', 'video/x-m4v', 'video/webm', 'video/ogg', 'video/quicktime'];

/** `video`, or `image` for anything else. */
export const mediaType = (type) => (type === 'video' ? 'video' : 'image');

/** A URL field that holds a video file (the Video widget's file, a section's background video): its key or label says
 * so, or its value is one. Its Media Library button opens the picker on video files. */
export function isVideoTarget(field, val) {
  const key = String((field && field.key) || '');
  const label = String((field && field.label) || '');
  if (/video/i.test(key) || /video/i.test(label)) return true;
  return typeof val === 'string' && /\.(mp4|m4v|webm|ogv|mov)(\?.*)?$/i.test(val);
}

/** The query string for a list or preview request. */
export function listQuery({ q = '', page = 1, ids = [], type = 'image' } = {}) {
  const p = new URLSearchParams();
  if (mediaType(type) === 'video') p.set('type', 'video');
  const wanted = (Array.isArray(ids) ? ids : []).filter((n) => Number.isInteger(n) && n > 0);
  if (wanted.length) {
    p.set('ids', wanted.join(','));
  } else {
    if (String(q).trim()) p.set('q', String(q).trim());
    if (Number.isInteger(page) && page > 1) p.set('page', String(page));
  }
  const s = p.toString();
  return s ? `?${s}` : '';
}

/** A message key for a file the browser can already tell the server will refuse, or null. */
export function uploadProblem(file, type = 'image') {
  const video = mediaType(type) === 'video';
  const suffix = video ? '_video' : '';
  if (!file) return `media_err_none${suffix}`;
  if (file.size > (video ? MAX_VIDEO_UPLOAD_BYTES : MAX_UPLOAD_BYTES)) return `media_err_too_big${suffix}`;
  if (file.type && !(video ? VIDEO_TYPES : IMAGE_TYPES).includes(file.type)) return `media_err_type${suffix}`;
  return null;
}

/** The record the existing picker contract hands to `onPick` (what core `SlateMedia.open` used to give). */
export const toPickRecord = (item) => ({
  id: item.id, url: item.url, alt_text: item.alt_text || '', original_name: item.original_name || '', width: item.width ?? null, height: item.height ?? null,
});

export function createMediaApi({ url, csrfToken, fetchImpl = (...a) => fetch(...a) }) {
  const cache = new Map();
  const remember = (items) => items.forEach((i) => cache.set(i.id, i));
  const failure = async (res) => {
    let message = '';
    try { const j = await res.json(); message = (j && j.error && j.error.message) || ''; } catch { /* not JSON */ }
    return { ok: false, status: res.status, message };
  };

  return {
    /** @returns {Promise<{ok:true,items:object[],total:number,page:number,pages:number}|{ok:false,status:number,message:string}>} */
    async list(params = {}) {
      let res;
      try { res = await fetchImpl(url + listQuery(params), { credentials: 'same-origin', headers: { Accept: 'application/json' } }); } catch { return { ok: false, status: 0, message: '' }; }
      if (!res.ok) return failure(res);
      const body = await res.json();
      const d = body && body.data ? body.data : {};
      const items = Array.isArray(d.items) ? d.items : [];
      remember(items);
      return { ok: true, items, total: d.total || 0, page: d.page || 1, pages: d.pages || 1 };
    },
    /** One image by id, from memory when already seen. */
    async get(id, type = 'image') {
      if (cache.has(id)) return cache.get(id);
      const r = await this.list({ ids: [id], type });
      return r.ok ? (r.items.find((i) => i.id === id) || null) : null;
    },
    cached: (id) => cache.get(id) || null,
    async upload(file, type = 'image') {
      const problem = uploadProblem(file, type);
      if (problem) return { ok: false, status: 0, problem, message: '' };
      const body = new FormData();
      body.append('file', file);
      if (mediaType(type) === 'video') body.append('type', 'video');
      let res;
      try { res = await fetchImpl(url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken }, body }); } catch { return { ok: false, status: 0, message: '' }; }
      if (!res.ok) return failure(res);
      const j = await res.json();
      const item = j && j.data && j.data.item;
      if (!item) return { ok: false, status: res.status, message: '' };
      remember([item]);
      return { ok: true, item };
    },
  };
}
