// Revision history: pages of 25, tick rows, Delete asks first and removes only the ticked revisions.
import { test, expect } from '@playwright/test';

const REVIEWS = '/plugins/studio-builder/admin/reviews.php';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'admin screen; run once');
});

test('the history pages by 25, and bulk Delete removes the ticked revisions', async ({ page, request }) => {
  test.setTimeout(180_000);
  await page.goto(REVIEWS);
  const token = await page.locator('input[name="_csrf"]').first().inputValue();
  const someRevision = await page.locator('input.data-row-select').first().inputValue();

  const countRollbacks = async () => {
    await page.goto(`${REVIEWS}?kind=rollback&per=25`);
    const pager = page.locator('.sb-pager span').first();
    return (await pager.count()) ? Number((await pager.innerText()).match(/of (\d+)/)[1]) : await page.locator('#studio-revision-list .data-row').count();
  };
  const rollbacksBefore = await countRollbacks();
  await page.goto(REVIEWS);

  // Restoring a snapshot adds a "rollback" revision: do it 26 times, so the Rollbacks filter has two pages. A Restore
  // button carries the page's draft as the screen showed it (`restore:<revision>:<draft>`); each restore makes a new draft,
  // which the next list shows on its newest row (that restore), so every request names the draft it is replacing.
  let draft = (await page.locator(`button[value^="restore:${someRevision}:"]`).getAttribute('value')).split(':')[2];
  for (let i = 0; i < 26; i++) {
    const r = await request.post(REVIEWS, { form: { _csrf: token, row_action: `restore:${someRevision}:${draft}` } });
    expect(r.ok()).toBe(true);
    const html = await r.text();
    expect(html).toContain('alert-success');
    draft = html.match(/value="restore:\d+:(\d+)"/)[1];
  }

  await page.goto(`${REVIEWS}?kind=rollback&per=25`);
  const pager = page.locator('.sb-pager');
  await expect(pager).toContainText('Showing 1–25 of');
  const total = Number((await pager.locator('span').first().innerText()).match(/of (\d+)/)[1]);
  expect(total).toBe(rollbacksBefore + 26);
  await expect(page.locator('#studio-revision-list .data-row')).toHaveCount(25);
  await pager.getByRole('link', { name: '2', exact: true }).click();
  await expect(page.locator('.sb-pager')).toContainText(`Showing 26–${Math.min(50, total)} of ${total}`);

  // Tick every row on the first page: the bar appears, Delete asks first, then 25 revisions go.
  await page.goto(`${REVIEWS}?kind=rollback&per=25`);
  await page.locator('.data-list-select-all').check();
  await expect(page.locator('.data-list-selected-count')).toHaveText('25');
  let asked = '';
  page.once('dialog', (d) => { asked = d.message(); void d.dismiss(); });
  await page.getByRole('button', { name: 'Delete', exact: true }).click();
  expect(asked).toContain('25');
  await expect(page.locator('#studio-revision-list .data-row')).toHaveCount(25); // dismissed: nothing deleted

  page.once('dialog', (d) => void d.accept());
  await page.getByRole('button', { name: 'Delete', exact: true }).click();
  // The newest restored snapshot is now the page's current draft, so it is kept: 24 go, 1 stays.
  await expect(page.locator('.alert-success')).toContainText('24 revision(s) deleted, 1 kept');

  // Two of this spec's restores are left: the current draft (first, cannot be deleted) and one more, which goes.
  await page.goto(`${REVIEWS}?kind=rollback&per=25`);
  const second = page.locator('#studio-revision-list .data-row').nth(1);
  await second.locator('.data-row-summary').click();
  page.once('dialog', (d) => void d.accept());
  await second.getByRole('button', { name: /Delete/ }).click();
  await expect(page.locator('.alert-success')).toContainText('1 revision(s) deleted');
  expect(await countRollbacks()).toBe(rollbacksBefore + 1); // only the page's current draft remains
});

test('a page\'s published revision cannot be deleted, even when asked', async ({ page, request }) => {
  await page.goto(REVIEWS);
  const token = await page.locator('input[name="_csrf"]').first().inputValue();
  // The page's current draft is in use; find a row marked so and ask for it by name.
  const rows = page.locator('#studio-revision-list .data-row');
  const count = await rows.count();
  let inUseId = null;
  for (let i = 0; i < count && inUseId === null; i++) {
    const row = rows.nth(i);
    await row.locator('.data-row-summary').click();
    if ((await row.locator('.data-row-detail').innerText()).includes('cannot be deleted')) inUseId = await row.locator('input.data-row-select').inputValue();
  }
  test.skip(inUseId === null, 'no revision of this page is in use on the first page');
  const res = await request.post(REVIEWS, { form: { _csrf: token, bulk_action: 'delete_revisions', 'ids[]': inUseId }, maxRedirects: 0 });
  expect(res.status()).toBe(303);
  expect(res.headers()['location']).toContain('skipped=1');
  await page.goto(REVIEWS);
  await expect(page.locator(`input.data-row-select[value="${inUseId}"]`)).toHaveCount(1);
});
