// The Shop plugin's widgets as the builder receives them (plugins/shop/studio-widgets.php, through the manifest): they are
// Components, gated by the `shop` entitlement, carry their style parts, and their option values read as words.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { isComponentBlock, isElementBlock } from '../src/core/addPanel.mjs';
import { optionLabel } from '../src/core/optionLabels.mjs';

const manifest = JSON.parse(readFileSync(new URL('./fixtures/manifest.json', import.meta.url), 'utf8'));
const shop = manifest.blocks.filter((b) => b.type.startsWith('shop.'));
const byType = Object.fromEntries(shop.map((b) => [b.type, b]));

test('every Shop widget is a Component that needs the shop entitlement', () => {
  assert.equal(shop.length, 12);
  for (const b of shop) {
    assert.ok(isComponentBlock(b) && !isElementBlock(b), `${b.type} is in Add › Components`);
    assert.equal(b.required_entitlement, 'shop', b.type);
    assert.equal(b.category, 'business', b.type);
  }
});

test('the Product grid binds to the shop\'s products, whose category is picked from a list', () => {
  const grid = byType['shop.products'];
  assert.deepEqual(grid.binding_slots, [{ provider: 'shop.products', slot: 'items' }]);
  const provider = manifest.providers.find((p) => p.key === 'shop.products');
  assert.deepEqual(provider.params.map((p) => p.key), ['category']);
  assert.ok(Array.isArray(provider.params[0].choices), 'a pick-list, filled from the shop\'s categories');
  assert.deepEqual(grid.field_schema.map((f) => f.key), ['heading', 'columns', 'order', 'per_page', 'show_filter', 'show_pager', 'show_price', 'show_add_to_cart', 'button_label', 'link_label', 'options_label', 'show_icon', 'icon', 'empty_message']);
});

test('the widgets an author styles piece by piece list their parts', () => {
  const parts = (type) => byType[type].style_parts.map((p) => p.key);
  assert.deepEqual(parts('shop.products'), ['card', 'image', 'body', 'icon', 'link', 'title', 'price', 'button', 'badge', 'filter', 'pager', 'grid']);
  assert.deepEqual(parts('shop.product_add_to_cart'), ['button', 'options', 'option_labels', 'variant_price', 'quantity', 'message']);
  assert.deepEqual(parts('shop.cart'), ['table', 'title', 'options', 'totals', 'button']);
  assert.deepEqual(parts('shop.checkout'), ['fields', 'labels', 'summary', 'button']);
  assert.deepEqual(byType['shop.product_title'].style_parts, [], 'a one-element widget is styled as a whole');
});

test('the Shop option values read as words', () => {
  for (const [value, label] of [['price_asc', 'Price, low to high'], ['price_desc', 'Price, high to low'], ['menu', 'Shop order'], ['long', 'Full description'], ['portrait', 'Portrait']]) {
    assert.equal(optionLabel(value), label);
  }
});
