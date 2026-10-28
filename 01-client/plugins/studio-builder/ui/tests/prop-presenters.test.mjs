import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { ICON_STYLE_PROPS, PROP_PRESENTERS, presentersFor } from '../src/core/propPresenters.mjs';
import { optionLabel } from '../src/core/optionLabels.mjs';

const manifest = JSON.parse(readFileSync(new URL('./fixtures/manifest.json', import.meta.url), 'utf8'));

function fieldSchemas() {
  const out = new Map();
  (function walk(o) {
    if (Array.isArray(o)) o.forEach(walk);
    else if (o && typeof o === 'object') {
      if (typeof o.type === 'string' && Array.isArray(o.field_schema)) out.set(o.type, o.field_schema);
      Object.values(o).forEach(walk);
    }
  })(manifest);
  return out;
}

test('every presenter names a real field of its block, with a matching kind', () => {
  const schemas = fieldSchemas();
  for (const [type, map] of Object.entries(PROP_PRESENTERS)) {
    const fields = schemas.get(type);
    assert.ok(fields, `${type} is in the manifest`);
    for (const [key, p] of Object.entries(map)) {
      const f = fields.find((x) => x.key === key);
      assert.ok(f, `${type}.${key} exists`);
      if (p.tiles) assert.equal(f.type, 'number');
      else if (p.icons || p.pills) assert.equal(f.type, 'enum');
      else assert.equal(typeof p.showIf, 'function', `${type}.${key} only decides when the field shows`);
    }
  }
});

test('an icon presenter has an icon for each allowed value', () => {
  const source = readFileSync(new URL('../src/components/inspectors/InspectorIcons.jsx', import.meta.url), 'utf8');
  const schemas = fieldSchemas();
  for (const [type, map] of Object.entries(PROP_PRESENTERS)) {
    for (const [key, p] of Object.entries(map)) {
      if (!p.icons) continue;
      const f = schemas.get(type).find((x) => x.key === key);
      for (const v of f.allowed_values) {
        const name = `${p.icons}_${String(p.map?.[v] ?? v).replace(/-/g, '_')}`;
        assert.ok(new RegExp(`\\b${name}:`).test(source), `${type}.${key}=${v} -> ${name}`);
      }
    }
  }
});

test('presentersFor returns null for blocks that keep plain selects', () => {
  assert.equal(presentersFor('core.quote'), null);
  assert.ok(presentersFor('layout.flex').direction);
});

test('a field that means nothing yet is hidden: the icon position and spacing, the new tab of a heading link', () => {
  const button = presentersFor('core.button');
  assert.equal(button.icon_position.showIf({ icon: 'none' }), false);
  assert.equal(button.icon_spacing.showIf({}), false);
  assert.equal(button.icon_position.showIf({ icon: 'star' }), true);
  const heading = presentersFor('core.heading');
  assert.equal(heading.new_tab.showIf({ url: '' }), false);
  assert.equal(heading.new_tab.showIf({ url: '/menu' }), true);
});

test('an image link, a text column gap and an icon frame show only when they mean something; the icon style props stay off Content', () => {
  const image = presentersFor('core.image');
  assert.equal(image.url.showIf({ link_to: 'media' }), false);
  assert.equal(image.url.showIf({ link_to: 'custom' }), true);
  assert.equal(image.new_tab.showIf({ link_to: 'none' }), false);
  assert.equal(image.new_tab.showIf({ link_to: 'media' }), true);
  for (const type of ['core.rich_text', 'core.text']) {
    assert.equal(presentersFor(type).columns_gap.showIf({ columns: 1 }), false);
    assert.equal(presentersFor(type).columns_gap.showIf({ columns: 3 }), true);
  }
  const icon = presentersFor('core.icon');
  assert.equal(icon.shape.showIf({ view: 'default' }), false);
  assert.equal(icon.shape.showIf({ view: 'framed' }), true);
  for (const key of ICON_STYLE_PROPS) assert.equal(icon[key].showIf({ view: 'stacked' }), false, `${key} is in the Style section`);
});

test('an icon has one size and one colour, in its Style section; the older preset and token show only while they hold a value it does not show', () => {
  const icon = presentersFor('core.icon');
  assert.equal(icon.size.showIf({ size: 'md' }), false, 'the default preset is the slider\'s starting point');
  assert.equal(icon.size.showIf({ size: 'lg' }), true, 'another preset stays visible');
  assert.equal(icon.size.showIf({ size: 'lg', size_px: 40 }), false, 'a pixel size replaces it');
  assert.equal(icon.color_token.showIf({ color_token: null }), false);
  assert.equal(icon.color_token.showIf({ color_token: 'color.accent' }), true);
});

test('Video: Source decides which address shows, and the poster, play icon and lightbox wait for Image Overlay', () => {
  const v = presentersFor('core.video');
  const shown = (props) => Object.keys(v).filter((k) => !v[k].showIf || v[k].showIf(props));
  // A block saved before Source existed has none: it is a YouTube or Vimeo link.
  assert.deepEqual(shown({}).sort(), ['privacy', 'source', 'url']);
  assert.deepEqual(shown({ source: 'youtube' }).sort(), ['privacy', 'source', 'url']);
  assert.deepEqual(shown({ source: 'hosted' }).sort(), ['hosted_url', 'source']);
  assert.deepEqual(shown({ source: 'vimeo', overlay: true }).sort(), ['lightbox', 'overlay_image', 'play_icon', 'privacy', 'source', 'url']);
  assert.equal(v.source.pills, true);
  assert.deepEqual(['youtube', 'vimeo', 'hosted'].map(optionLabel), ['YouTube', 'Vimeo', 'Self hosted']);

  const fields = fieldSchemas().get('core.video');
  const field = (k) => fields.find((f) => f.key === k);
  assert.deepEqual(field('source').allowed_values, ['youtube', 'vimeo', 'hosted']);
  assert.deepEqual(field('aspect_ratio').allowed_values, ['16:9', '4:3', '1:1', '9:16']);
  assert.equal(field('hosted_url').type, 'url', 'a URL field, so it offers the Media library');
  assert.equal(field('overlay_image').type, 'media_ref');
  for (const k of ['start', 'end']) assert.equal(field(k).type, 'number');
  for (const k of ['autoplay', 'muted', 'loop', 'controls', 'privacy', 'lazy_load', 'overlay', 'play_icon', 'lightbox']) assert.equal(field(k).type, 'boolean', k);
});
