// Таблицы данных не растут бесконечно: блок таблицы не выше min(70% окна, 720 px), прокрутка внутри, строка
// заголовка остаётся наверху. Таблицы справки (markdown) — без ограничения.
import { test, expect } from '../fixtures.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

const rows = (n) => Array.from({ length: n }, (_, i) => i);

for (const w of [390, 1366]) {
  test(`длинная таблица прокручивается внутри, заголовок наверху, ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 800 });
    const now = Math.floor(Date.now() / 1000);
    const S1 = { steps: ['--lua-desync=multisplit:pos=1'], name: 'multisplit pos=1' };
    await app.mock((b) => {
      if (b.cmd === 'learn_get') {
        return { module: true, updated: now, items: rows(60).map((i) => ({ key: 'learn_1_1', host: `site${i}.example.com`, profile: 1, cur: 1,
          strategies: [{ n: 1, ok: i, fail: 1, score: 0.8, last: now, ...S1 }] })) };
      }
    });
    await app.open('#/tests/learn', { variant: 'k' });
    const box = page.locator('.scroll:has(> table.learn-tbl)');
    await expect(page.locator('.learn-tbl tbody tr')).toHaveCount(60);
    const m = await box.evaluate((s) => ({ h: s.clientHeight, sh: s.scrollHeight, vh: innerHeight }));
    expect(m.h).toBeLessThanOrEqual(Math.min(m.vh * 0.7, 720) + 1);
    expect(m.sh).toBeGreaterThan(m.h);
    if (w > 760) {
      // scrolled to the middle: the header row is still at the top of the block
      const top = await box.evaluate((s) => { s.scrollTop = s.scrollHeight / 2; return s.getBoundingClientRect().top; });
      await page.waitForTimeout(100);
      const th = await page.locator('.learn-tbl thead th').first().boundingBox();
      expect(Math.abs(th.y - top)).toBeLessThanOrEqual(2);
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
  });
}

// every page with tables: no data table block taller than the limit
for (const hash of ['#/tests/history', '#/tests', '#/settings/backups', '#/settings/history', '#/tests/monitor', '#/tests/dns', '#/tests/community']) {
  test(`таблицы не выше лимита: ${hash}`, async ({ app, page }) => {
    await page.setViewportSize({ width: 1366, height: 800 });
    await app.open(hash, { variant: 'k' });
    await page.waitForTimeout(1500);
    const bad = await page.evaluate(() => [...document.querySelectorAll('.scroll:not(.md-tbl)')].filter((s) => s.querySelector(':scope > table.tbl'))
      .map((s) => s.clientHeight).filter((h) => h > Math.min(innerHeight * 0.7, 720) + 1));
    expect(bad).toEqual([]);
  });
}
