// Uploads the six site images through Studio's own media page and returns their media ids.
import path from 'node:path';

export const IMAGES = [
  ['workbench', 'faisal-workbench.jpg'], ['profile', 'about/portrait-profile.png'], ['smile', 'about/portrait-smile.png'],
  ['peoria', 'projects/peoria-hardwood-floors.png'], ['nicola', 'projects/nicola.png'], ['ai', 'projects/ai-flooring-visualizer.png'],
];
const ids = (page) => page.evaluate(() => [...document.querySelectorAll('input[name=media_id]')].map((i) => Number(i.value)));

export async function uploadImages(page, dir) {
  await page.goto('plugins/studio-builder/admin/media.php');
  const before = new Set(await ids(page));
  await page.getByRole('button', { name: /^Upload Assets$/ }).first().click();
  await page.locator('input[type=file]').first().setInputFiles(IMAGES.map(([, f]) => path.join(dir, f)));
  await page.getByRole('button', { name: /Upload to Studio/ }).click();
  await page.waitForLoadState('networkidle');
  const flash = (await page.locator('.alert, [role=alert]').allInnerTexts()).join(' ');
  const fresh = (await ids(page)).filter((i) => !before.has(i)).sort((a, b) => a - b);
  if (fresh.length !== IMAGES.length) throw new Error(`Expected ${IMAGES.length} new media items, got ${fresh.length}. ${flash}`);
  return Object.fromEntries(IMAGES.map(([k], i) => [k, fresh[i]]));
}
