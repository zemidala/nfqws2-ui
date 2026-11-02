// «Параметры запуска»: «не используется» у блоба — по тому, что роутер насчитал в конфиге (blobs_used), а не по сырому
// тексту: закомментированное использование не в счёт, несколько присваиваний — в счёт (Эдик, #779). conf_get подменён:
// в blobs_used убран первый объявленный блоб — у него должна появиться пометка, у остальных нет.
import { test, expect } from '../fixtures.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

test('пометка «не используется» берётся из blobs_used', async ({ app, page }) => {
  let first = null;
  await page.route('**/api.php*', async (route) => {
    let body = {};
    try { body = JSON.parse(route.request().postData() || '{}'); } catch { /* not JSON */ }
    if (body.cmd !== 'conf_get') return route.continue();
    const res = await route.fetch();
    const j = await res.json();
    const c = j.data || j;
    const names = [...(c.vars.NFQWS_BASE_ARGS || '').matchAll(/--blob=([^:\s]+):/g)].map((m) => m[1]);
    first = names[0];
    c.blobs_used = (c.blobs_used || []).filter((n) => n !== first).concat(names.slice(1));
    return route.fulfill({ response: res, json: j });
  });
  await app.open('#/settings/base', { variant: 'k' });
  const rows = page.locator('.blob-row');
  await expect(rows.first()).toBeVisible();
  test.skip(!first, 'в конфиге устройства нет блобов');
  await expect(rows.filter({ hasText: first }).first()).toContainText('не используется');
  const others = await rows.filter({ hasNotText: first }).count();
  await expect(rows.filter({ hasText: 'не используется' })).toHaveCount(1);
  expect(others).toBeGreaterThanOrEqual(0);
});
