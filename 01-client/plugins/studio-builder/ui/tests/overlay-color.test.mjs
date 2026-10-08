import test from 'node:test';
import assert from 'node:assert/strict';
import { focalFromPoint, overlayWith, parseOverlay } from '../src/core/overlayColor.mjs';

test('an empty overlay is black at full opacity; a hex colour carries its alpha', () => {
  assert.deepEqual(parseOverlay(undefined), { editable: true, base: '#000000', percent: 100 });
  assert.deepEqual(parseOverlay('#112233'), { editable: true, base: '#112233', percent: 100 });
  assert.deepEqual(parseOverlay('#11223380'), { editable: true, base: '#112233', percent: 50 });
  assert.deepEqual(parseOverlay('#f008'), { editable: true, base: '#ff0000', percent: 53 });
});

test('a token, rgba() or keyword overlay is not editable by the slider', () => {
  for (const v of ['surface.dark', 'rgba(0,0,0,0.4)', 'black']) assert.equal(parseOverlay(v).editable, false, v);
});

test('opacity round-trips and stays a colour the server accepts', () => {
  assert.equal(overlayWith('#000000', 100), '#000000');
  assert.equal(overlayWith('#000000', 30), '#0000004d');
  assert.equal(overlayWith('#112233', 0), '#11223300');
  assert.equal(parseOverlay(overlayWith('#112233', 30)).percent, 30);
  assert.equal(overlayWith('#112233', 250), '#112233');
  assert.equal(overlayWith('#112233', -5), '#11223300');
});

test('a pointer position becomes a focal point in range', () => {
  assert.deepEqual(focalFromPoint(50, 25, 100, 50), [0.5, 0.5]);
  assert.deepEqual(focalFromPoint(-10, 999, 100, 50), [0, 1]);
  assert.deepEqual(focalFromPoint(33.333, 10, 100, 40), [0.33, 0.25]);
  assert.deepEqual(focalFromPoint(5, 5, 0, 0), [0.5, 0.5]);
});
