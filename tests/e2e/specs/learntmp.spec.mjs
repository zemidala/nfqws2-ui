// 2026-11-11, the owner's router: installing the nfqws2-keenetic package with opkg left /tmp 0755 root, and the «learn»
// module (nfqws2 runs as nobody) could write nothing for days. The router puts 1777 back by itself; «Проблемы» say it
// only when that did not help. The state is patched in the answer — /tmp on the router is not touched.
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';
import { contrastReport } from 'pw-lib/contrast.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

const withTmp = (page, locked) => page.route('**/api.php*', async (route) => {
  let body = {};
  try { body = JSON.parse(route.request().postData() || '{}'); } catch { /* not JSON */ }
  if (body.cmd !== 'state') return route.continue();
  const res = await route.fetch();
  const j = await res.json();
  (j.data || j).learn_tmp_locked = locked;
  return route.fulfill({ response: res, json: j });
});

for (const variant of ['k', 'z']) for (const w of [390, 1366]) {
  test(`/tmp не дала писать — заметка в «Проблемах», стиль ${variant}, ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    await withTmp(page, true);
    await app.open('#/', { variant, mode: 'dark' });
    const item = page.locator('.problem', { hasText: 'Обучение не сохраняет выученное' }).first();
    await expect(item).toBeVisible();
    await expect(item).toContainText('chmod 1777 /tmp');
    await item.scrollIntoViewIfNeeded();
    await page.waitForTimeout(1000);   // blocks fade in: measured mid-way the text reads pale
    expect(await auditLayout(page)).toEqual([]);
    const pale = (await contrastReport(page)).filter((s) => s.includes('Обучение не сохраняет') || s.includes('nobody'));
    expect(pale).toEqual([]);
  });
}

test('права в порядке — заметки нет', async ({ app, page }) => {
  await withTmp(page, false);
  await app.open('#/', { variant: 'k' });
  await expect(page.locator('main h1').first()).toBeVisible();
  await page.waitForTimeout(1500);
  await expect(page.locator('.problem', { hasText: 'Обучение не сохраняет выученное' })).toHaveCount(0);
});
