// Widget design-control audit — step 3: insert every widget in the real builder and record what its Edit panel offers
// (Content, Style, Advanced): control labels and kinds, raw-looking labels, disabled or unlabelled controls, console errors.
// Writes <AUDIT_OUT>/inventory.json and a screenshot of each widget's Content tab.
import { test } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { openBuilder, settled, layerCount, restoreLayers } from '../e2e/helpers.mjs';

const OUT = process.env.AUDIT_OUT || '/tmp/fh-audit';
const manifest = JSON.parse(fs.readFileSync(new URL('../tests/fixtures/manifest.json', import.meta.url), 'utf8'));
const only = (process.env.AUDIT_ONLY || '').split(',').filter(Boolean);
fs.mkdirSync(path.join(OUT, 'shots'), { recursive: true });

/** Runs in the page: what the Edit panel currently shows. */
function scanPanel() {
  const root = document.querySelector('#sbx-leftpanel-inspector');
  if (!root) return { missing: true };
  const fields = [];
  const seen = new Set();
  root.querySelectorAll('input, select, textarea, button[role="switch"], [role="slider"], [contenteditable="true"]').forEach((el) => {
    if (el.closest('.sbx-inspector__bar, [data-testid="inspector-bar"]')) return;
    const r = el.getBoundingClientRect();
    const hidden = r.width === 0 && r.height === 0;
    const id = el.id;
    let label = '';
    if (id) { const l = root.querySelector(`label[for="${CSS.escape(id)}"]`); if (l) label = l.textContent.trim(); }
    if (!label) label = (el.getAttribute('aria-label') || '').trim();
    if (!label) { const wrap = el.closest('label'); if (wrap) label = wrap.textContent.trim(); }
    if (!label) { const fs = el.closest('fieldset'); const lg = fs && fs.querySelector('legend'); if (lg) label = lg.textContent.trim(); }
    const kind = el.tagName.toLowerCase() + (el.type && el.tagName === 'INPUT' ? `:${el.type}` : '');
    const key = `${kind}|${label}|${el.name || id}`;
    if (seen.has(key) && !label) return;
    seen.add(key);
    fields.push({ label, kind, hidden, disabled: el.disabled || el.getAttribute('aria-disabled') === 'true', invalid: el.getAttribute('aria-invalid') === 'true', value: el.type === 'checkbox' ? el.checked : (el.value || '').slice(0, 40) });
  });
  const headings = Array.from(root.querySelectorAll('h2, h3, h4, summary, legend, .sbx-isec__title, [data-section]')).map((h) => (h.getAttribute('data-section') ? `§${h.getAttribute('data-section')}` : h.textContent.trim().slice(0, 40))).filter(Boolean);
  return { fields, headings, text: (root.innerText || '').slice(0, 200) };
}

fs.mkdirSync(path.join(OUT, 'inventory'), { recursive: true });
const types = manifest.blocks.map((b) => b.type).filter((t) => only.length === 0 || only.includes(t));

for (const type of types) {
  test(`inventory: ${type}`, async ({ page }) => {
    test.setTimeout(75_000);
    const def = manifest.blocks.find((b) => b.type === type);
    const rec = { type, title: def.title, category: def.category, fields: def.field_schema.length };
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e).slice(0, 160)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 160)); });
    const save = () => fs.writeFileSync(path.join(OUT, 'inventory', `${type}.json`), JSON.stringify(rec, null, 1));
    try {
      await openBuilder(page);
      await settled(page);
      await page.getByRole('tab', { name: /^Add$/ }).click();
      const blocks = page.locator('#sbx-leftpanel-blocks');
      await blocks.getByRole('tab', { name: 'Elements' }).click();
      await blocks.getByPlaceholder(/Search/).fill(def.title);
      await page.waitForTimeout(300);
      const card = blocks.locator(`[data-block-type="${type}"]`).first();
      if (await card.count() === 0) { rec.insert = 'no card in the Add panel'; return; }
      if (await card.isDisabled().catch(() => false)) { rec.insert = 'card disabled'; return; }
      await card.click();
      await page.waitForTimeout(800);
      if (await page.locator('[role="dialog"]').count()) { rec.insert = 'asks for setup first'; await page.keyboard.press('Escape'); return; }
      rec.insert = 'ok';
      await page.getByRole('tab', { name: /^Edit$/ }).click().catch(() => {});
      for (const tab of ['Content', 'Style', 'Advanced']) {
        const t = page.locator('#sbx-leftpanel-inspector').getByRole('tab', { name: tab });
        if (await t.count() === 0) { rec[tab] = { missing: true }; continue; }
        await t.click();
        await page.waitForTimeout(250);
        for (let i = 0; i < 14; i++) {
          const closed = page.locator('#sbx-leftpanel-inspector .sbx-isec__toggle[aria-expanded="false"]').first();
          if (await closed.count() === 0) break;
          await closed.click({ timeout: 2000 }).catch(() => {});
        }
        rec[tab] = await page.evaluate(scanPanel);
        if (tab === 'Content') await page.screenshot({ path: path.join(OUT, 'shots', `${type}.png`) });
      }
      rec.errors = [...errors];
    } catch (e) {
      rec.failure = String(e).slice(0, 200);
    } finally {
      save();
    }
  });
}
