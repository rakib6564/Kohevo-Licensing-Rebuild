import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createMediaApi, listQuery, uploadProblem, toPickRecord, isVideoTarget, MAX_UPLOAD_BYTES, MAX_VIDEO_UPLOAD_BYTES } from '../src/core/mediaApi.mjs';
import { registerMediaHost, mediaPickerAvailable, openMediaPicker } from '../src/core/mediaPicker.mjs';

const json = (status, body) => ({ ok: status < 300, status, json: async () => body });

test('listQuery: ids win over search and page; blanks and bad values are dropped', () => {
  assert.equal(listQuery(), '');
  assert.equal(listQuery({ q: '  hero ', page: 3 }), '?q=hero&page=3');
  assert.equal(listQuery({ q: 'x', page: 1 }), '?q=x');
  assert.equal(listQuery({ ids: [4, 'a', -1, 9], q: 'ignored', page: 2 }), '?ids=4%2C9');
});

test('uploadProblem: tells what the server would refuse, before sending', () => {
  assert.equal(uploadProblem(null), 'media_err_none');
  assert.equal(uploadProblem({ size: MAX_UPLOAD_BYTES + 1, type: 'image/png' }), 'media_err_too_big');
  assert.equal(uploadProblem({ size: 10, type: 'application/pdf' }), 'media_err_type');
  assert.equal(uploadProblem({ size: 10, type: 'image/webp' }), null);
  assert.equal(uploadProblem({ size: 10, type: '' }), null, 'an unknown type is left to the server');
});

test('the pick record keeps the contract fields fields rely on', () => {
  assert.deepEqual(toPickRecord({ id: 3, url: '/a.png', alt_text: 'A', original_name: 'a.png', width: 10, height: null, extra: 1 }),
    { id: 3, url: '/a.png', alt_text: 'A', original_name: 'a.png', width: 10, height: null });
});

test('media client: list remembers items, get uses memory, upload sends the CSRF header and one file', async () => {
  const calls = [];
  const api = createMediaApi({
    url: '/m.php', csrfToken: 'tok',
    fetchImpl: async (u, o = {}) => {
      calls.push({ u, o });
      if (o.method === 'POST') return json(201, { ok: true, data: { item: { id: 9, url: '/n.png' } } });
      return json(200, { ok: true, data: { items: [{ id: 1, url: '/a.png' }], total: 1, page: 1, pages: 1 } });
    },
  });
  const r = await api.list({ q: 'a' });
  assert.deepEqual([r.ok, r.items.length, r.pages], [true, 1, 1]);
  assert.equal(calls[0].u, '/m.php?q=a');
  assert.equal((await api.get(1)).url, '/a.png');
  assert.equal(calls.length, 1, 'get(1) came from memory');

  const file = new File([new Uint8Array(4)], 'n.png', { type: 'image/png' });
  const up = await api.upload(file);
  assert.equal(up.item.id, 9);
  assert.equal(calls[1].o.headers['X-CSRF-Token'], 'tok');
  assert.ok(calls[1].o.body instanceof FormData && calls[1].o.body.get('file') instanceof File);
  assert.equal(api.cached(9).url, '/n.png');
});

test('media client: failures carry the server message; a refused file never reaches the network', async () => {
  let hit = 0;
  const api = createMediaApi({ url: '/m.php', csrfToken: 't', fetchImpl: async () => { hit++; return json(422, { ok: false, error: { message: 'File type not allowed.' } }); } });
  const bad = await api.upload(new File(['x'], 'x.png', { type: 'image/png' }));
  assert.deepEqual([bad.ok, bad.status, bad.message], [false, 422, 'File type not allowed.']);
  const tooBig = await api.upload({ size: MAX_UPLOAD_BYTES + 1, type: 'image/png' });
  assert.equal(tooBig.problem, 'media_err_too_big');
  assert.equal(hit, 1);
  const down = createMediaApi({ url: '/m.php', csrfToken: 't', fetchImpl: async () => { throw new Error('offline'); } });
  assert.equal((await down.list()).ok, false);
});

test('the picker registry: available only with the flag and a host; the host receives the options', () => {
  assert.equal(mediaPickerAvailable(true), false, 'no host yet');
  let got = null;
  const off = registerMediaHost((o) => { got = o; });
  assert.equal(mediaPickerAvailable(false), false, 'no permission');
  assert.equal(mediaPickerAvailable(true), true);
  assert.equal(openMediaPicker({ types: 'image', selectedId: 4 }), true);
  assert.deepEqual(got, { types: 'image', selectedId: 4 });
  off();
  assert.equal(mediaPickerAvailable(true), false);
  assert.equal(openMediaPicker({}), false);
});

test('video files: the Video widget\'s picker lists, checks and uploads video files with their own size and types', async () => {
  assert.equal(listQuery({ type: 'video' }), '?type=video');
  assert.equal(listQuery({ q: 'clip', page: 2, type: 'video' }), '?type=video&q=clip&page=2');
  assert.equal(listQuery({ ids: [5], type: 'video' }), '?type=video&ids=5');
  assert.equal(listQuery({ q: 'x', type: 'nonsense' }), '?q=x', 'anything else is an image list');

  assert.equal(uploadProblem(null, 'video'), 'media_err_none_video');
  assert.equal(uploadProblem({ size: 20 * 1024 * 1024, type: 'video/mp4' }, 'video'), null, 'a 20 MB video is fine');
  assert.equal(uploadProblem({ size: MAX_VIDEO_UPLOAD_BYTES + 1, type: 'video/mp4' }, 'video'), 'media_err_too_big_video');
  assert.equal(uploadProblem({ size: 10, type: 'image/png' }, 'video'), 'media_err_type_video');
  assert.equal(uploadProblem({ size: 10, type: 'video/mp4' }), 'media_err_type', 'an image field still refuses a video');
  for (const type of ['video/webm', 'video/ogg', 'video/quicktime']) assert.equal(uploadProblem({ size: 10, type }, 'video'), null, type);

  const calls = [];
  const api = createMediaApi({ url: '/m.php', csrfToken: 't', fetchImpl: async (u, o = {}) => {
    calls.push({ u, o });
    return o.method === 'POST' ? json(201, { ok: true, data: { item: { id: 4, url: '/c.mp4' } } }) : json(200, { ok: true, data: { items: [], total: 0, page: 1, pages: 1 } });
  } });
  await api.list({ type: 'video' });
  assert.equal(calls[0].u, '/m.php?type=video');
  const up = await api.upload(new File([new Uint8Array(4)], 'c.mp4', { type: 'video/mp4' }), 'video');
  assert.equal(up.item.url, '/c.mp4');
  assert.equal(calls[1].o.body.get('type'), 'video');
});

test('a URL field holding a video opens the video picker', () => {
  assert.equal(isVideoTarget({ key: 'hosted_url', label: 'Video File' }, ''), true, 'the Video widget\'s file');
  assert.equal(isVideoTarget({ key: 'bg_video', label: 'Background Video' }, null), true, 'a Container\'s background video');
  assert.equal(isVideoTarget({ key: 'href', label: 'Link' }, '/uploads/media/2026/10/a.webm'), true, 'a value that is a video file');
  assert.equal(isVideoTarget({ key: 'url', label: 'Link URL' }, '/about'), false);
  assert.equal(isVideoTarget({ key: 'image', label: 'Image' }, '/a.png'), false);
});
