// #18 (KainXVIII, Keenetic с PPPoE): переходы в цепочки nfqws2 пропадают после перестройки межсетевого экрана.
// Роутер подключает их заново сам — в «Проблемах» заметка; ошибка остаётся, только если это не помогло.
// Состояние подкладывается в ответ state — правила на роутере не трогаются.
import { test, expect } from '../fixtures.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

const withIntercept = (page, patch) => page.route('**/api.php*', async (route) => {
  let body = {};
  try { body = JSON.parse(route.request().postData() || '{}'); } catch { /* не JSON */ }
  if (body.cmd !== 'state') return route.continue();
  const res = await route.fetch();
  const j = await res.json();
  const st = j.data || j;
  st.running = true;
  st.intercept = { ...(st.intercept || {}), ...patch };
  return route.fulfill({ response: res, json: j });
});

for (const w of [390, 1366]) {
  test(`подключены заново — заметка, не ошибка, ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    await withIntercept(page, { off: false, healed: { count: 3, last: Math.floor(Date.now() / 1000) - 600 }, heal_failed: false });
    await app.open('#/', { variant: 'k' });
    const item = page.locator('.problem', { hasText: 'Правила перехвата пропадали' }).first();
    await expect(item).toBeVisible();
    await expect(item).toContainText('3 раза');
    await expect(page.locator('.problem', { hasText: 'Правила перехвата не подключены' })).toHaveCount(0);
    await item.scrollIntoViewIfNeeded();
    await page.screenshot({ path: test.info().outputPath(`healed-${w}.png`) });
  });
}

test('не помогло — ошибка говорит об этом', async ({ app, page }) => {
  await withIntercept(page, { off: true, off_since: Math.floor(Date.now() / 1000) - 90, healed: null, heal_failed: true });
  await app.open('#/', { variant: 'k' });
  const item = page.locator('.problem', { hasText: 'Правила перехвата не подключены' }).first();
  await expect(item).toBeVisible();
  await expect(item).toContainText('Подключить их заново не получилось');
});
