// A carousel on a public page, against the real public runtime: a translated page stays translated once the
// runtime runs (it reuses the server's dots and labels), and autoplay can be paused, on touch screens too.
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const here = path.dirname(fileURLToPath(import.meta.url));
const runtimePath = path.join(here, '..', '..', 'assets', 'public', 'studio-runtime.js');

const slide = (n) => `<figure class="sb-carousel__slide" data-sb-slide><blockquote class="sb-carousel__quote"><p>Avis ${n}</p></blockquote></figure>`;
const dot = (i) => `<button type="button" class="sb-carousel__dot" data-sb-car-dot="${i}" aria-label="Aller à la diapositive ${i + 1}" aria-current="${i === 0}"></button>`;
const carousel = (autoplay) => `
  <div class="sb-carousel" data-sb-carousel data-sb-per-view="1" data-sb-loop ${autoplay ? 'data-sb-autoplay="300"' : ''}
       aria-roledescription="carrousel" aria-label="Diaporama"
       data-sb-label-slide="diapositive" data-sb-label-slide-of="%1$s sur %2$s" data-sb-label-go="Aller à la diapositive">
    <div class="sb-carousel__track" data-sb-carousel-track>${slide(1)}${slide(2)}${slide(3)}</div>
    <div class="sb-carousel__nav">
      <button type="button" class="sb-carousel__btn" data-sb-car-prev aria-label="Diapositive précédente">‹</button>
      <div class="sb-carousel__dots" data-sb-car-dots role="group" aria-label="Choisir une diapositive">${dot(0)}${dot(1)}${dot(2)}</div>
      <button type="button" class="sb-carousel__btn" data-sb-car-next aria-label="Diapositive suivante">›</button>
      ${autoplay ? '<button type="button" class="sb-carousel__btn sb-carousel__pause" data-sb-car-pause aria-pressed="false" data-sb-label-pause="Mettre en pause" data-sb-label-play="Lire" aria-label="Mettre en pause">II</button>' : ''}
    </div>
  </div>`;

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'no layout involved; run once');
});

async function load(page, markup) {
  await page.setContent(`<!doctype html><html lang="fr"><head><style>.sb-carousel__track{display:flex}.sb-carousel__slide{flex:0 0 100%}</style></head><body>${markup}</body></html>`);
  await page.addScriptTag({ path: runtimePath });
}

test('the runtime keeps the server\'s translated labels and valid roles', async ({ page }) => {
  await load(page, carousel(false));
  const group = page.locator('[data-sb-carousel]');
  await expect(group).toHaveAttribute('aria-roledescription', 'carrousel');
  await expect(page.locator('[data-sb-slide]').first()).toHaveAttribute('aria-label', '1 sur 3');
  await expect(page.locator('[data-sb-slide]').first()).toHaveAttribute('aria-roledescription', 'diapositive');
  const dots = page.locator('[data-sb-car-dot]');
  await expect(dots).toHaveCount(3);
  await expect(dots.nth(1)).toHaveAttribute('aria-label', 'Aller à la diapositive 2');
  expect(await dots.nth(1).getAttribute('role')).toBeNull();
  await dots.nth(1).click();
  await expect(dots.nth(1)).toHaveAttribute('aria-current', 'true');
  await expect(dots.nth(0)).toHaveAttribute('aria-current', 'false');
});

test('the pause button stops autoplay and starts it again', async ({ page }) => {
  await load(page, carousel(true));
  const pause = page.locator('[data-sb-car-pause]');
  const current = () => page.locator('[data-sb-car-dot][aria-current="true"]').getAttribute('data-sb-car-dot');
  await expect.poll(current, { timeout: 3000 }).not.toBe('0');
  await pause.click();
  await expect(pause).toHaveAttribute('aria-pressed', 'true');
  await expect(pause).toHaveAttribute('aria-label', 'Lire');
  const held = await current();
  await page.waitForTimeout(900);
  expect(await current()).toBe(held);
  await pause.click();
  await expect(pause).toHaveAttribute('aria-pressed', 'false');
  await expect.poll(current, { timeout: 3000 }).not.toBe(held);
});
