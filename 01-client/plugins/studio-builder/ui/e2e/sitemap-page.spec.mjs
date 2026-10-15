// /sitemap.xml: a browser sees a readable Kohevo page, a crawler and ?format=xml get the XML.
import { test, expect } from '@playwright/test';

const BROWSER = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

test('the sitemap is a readable page for a browser and XML for everyone else', async ({ request }, info) => {
  test.skip(info.project.name !== 'desktop', 'one request check');
  const page = await request.get('/sitemap.xml', { headers: { Accept: BROWSER } });
  expect(page.status()).toBe(200);
  expect(page.headers()['content-type']).toContain('text/html');
  expect(page.headers()['vary']).toContain('Accept');
  expect(await page.text()).toContain('Kohevo Studio');

  const crawler = await request.get('/sitemap.xml', { headers: { Accept: '*/*' } });
  expect(crawler.headers()['content-type']).toContain('application/xml');
  expect(await crawler.text()).toContain('<urlset');

  const raw = await request.get('/sitemap.xml?format=xml', { headers: { Accept: BROWSER } });
  expect(raw.headers()['content-type']).toContain('application/xml');
});
