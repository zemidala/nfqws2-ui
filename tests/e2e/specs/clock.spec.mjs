// «N мин назад», «Сегодня» and the like count from the router's clock: the browser's clock may be off by hours
// (a Keenetic without NTP, an OpenWrt right after boot). Here the browser runs an hour ahead.
import { test, expect } from '../fixtures.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

test('«Последняя проверка» обновлений — по часам роутера, а не браузера', async ({ app, page }) => {
  await page.route('**/api.php*', async (route) => {
    let b = {};
    try { b = JSON.parse(route.request().postData() || '{}'); } catch { /* not JSON */ }
    if (b.cmd === 'update_check') return route.fulfill({ json: {} });
    if (b.cmd !== 'state') return route.fallback();
    const r = await route.fetch();
    const st = await r.json();
    st.ui = { ...st.ui, update: { ...(st.ui?.update || {}), checked: st.now - 120, latest: true, available: false, error: '' } };
    return route.fulfill({ response: r, json: st });
  });
  await page.clock.setFixedTime(Date.now() + 3600 * 1000);
  await app.open('#/settings/about');
  await expect(page.locator('main .frow', { hasText: 'Последняя проверка' })).toContainText('2 мин назад');
});
