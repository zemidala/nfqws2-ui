// The side columns stay in place while the middle of the page scrolls: on every page that has one, on all layouts.
import { test, expect } from '../fixtures.mjs';

const PAGES = ['#/diag', '#/tests/freeze', '#/tests/dns', '#/tests/conns', '#/settings/raw', '#/tests', '#/tests/trace', '#/tests/history', '#/tests/community', '#/tests/auto', '#/tests/learn', '#/tests/notify', '#/asn', '#/settings/basic', '#/settings/base', '#/settings/history', '#/settings/report', '#/settings/look', '#/settings/about', '#/sites', '#/settings/p1', '#/tests/monitor', '#/log'];
const SIDES = ['#side', '.rail', '.lnav', '.two > .help'];

// the wheel over a table scrolls the page too, when the table fits and when it has reached its end
test('колесо над таблицей двигает страницу', async ({ app, page }) => {
  test.setTimeout(90_000);
  await page.setViewportSize({ width: 1366, height: 700 });
  await app.open('#/settings/backup', { variant: 'z', mode: 'dark' });
  await page.waitForTimeout(1000);
  const box = page.locator('main .scroll:has(> table.tbl)').first();
  await expect(box).toBeVisible();
  await page.evaluate(() => { const d = document.createElement('div'); d.style.height = '1800px'; document.querySelector('main').append(d); scrollTo(0, 0); });
  await box.scrollIntoViewIfNeeded();
  const b = await box.boundingBox();
  await page.mouse.move(b.x + 80, b.y + Math.min(b.height / 2, 40));
  const y0 = await page.evaluate(() => window.scrollY);
  for (let i = 0; i < 12; i++) { await page.mouse.wheel(0, 200); await page.waitForTimeout(60); }
  const r = await page.evaluate(() => { const e = document.querySelector('main .scroll:has(> table.tbl)'); return { y: window.scrollY, inner: e.scrollTop, room: e.scrollHeight - e.clientHeight }; });
  console.log('WHEEL', y0, JSON.stringify(r));
  expect(r.y).toBeGreaterThan(y0);
});

for (const variant of ['z', 'k']) {
  for (const layout of ['menu', 'tabs', 'site']) {
    test(`колонки стоят на месте: стиль ${variant}, компоновка ${layout}`, async ({ app, page }) => {
      test.setTimeout(240_000);
      await page.setViewportSize({ width: 1920, height: 700 });
      const bad = [], seen = new Set();
      for (const hash of PAGES) {
        await app.open(hash, { variant, mode: 'dark', layout });
        await page.waitForTimeout(600);
        const r = await page.evaluate(async (sides) => {
          const found = sides.map((s) => [s, document.querySelector(s)]).filter(([, e]) => e && e.getBoundingClientRect().height > 40);
          // a page too short to scroll proves nothing: make it long
          const pad = document.createElement('div');
          pad.style.height = '1600px';
          // inside the column that scrolls: a column stays only while the block around it goes on
          const host = document.querySelector('.mcol') || document.querySelector('.lnav')?.parentElement?.lastElementChild || document.querySelector('.two > .panel') || document.querySelector('main');
          host.append(pad);
          scrollTo(0, 0);
          await new Promise((f) => requestAnimationFrame(f));
          const before = found.map(([, e]) => e.getBoundingClientRect().top);
          scrollTo(0, 500);
          await new Promise((f) => setTimeout(f, 60));
          const out = found.map(([s, e], i) => ({ s, moved: Math.round(before[i] - e.getBoundingClientRect().top), top: Math.round(e.getBoundingClientRect().top) }));
          pad.remove();
          scrollTo(0, 0);
          return { y: 500, out, top: Math.round(document.querySelector('.top')?.getBoundingClientRect().height || 0) };
        }, SIDES);
        for (const x of r.out) {
          seen.add(x.s);
          // it may rise only up to its resting place under the top bar
          if (x.top < r.top - 1 || x.top > r.top + 60) bad.push(`${hash} ${x.s}: сдвиг ${x.moved}, верх ${x.top}`);
        }
      }
      console.log(`STICKY ${variant} ${layout}: проверены ${[...seen].join(' ')}; ошибок ${bad.length}${bad.length ? '\n' + bad.slice(0, 14).join('\n') : ''}`);
      expect(bad).toEqual([]);
    });
  }
}
