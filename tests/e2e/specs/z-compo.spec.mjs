// Style Z in every arrangement: all overview kinds on all layouts, both themes, narrow and wide.
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';
import { signs, tightButtons, rows, topBar } from '../z-checks.mjs';

const LAYOUTS = ['menu', 'tabs', 'site'];
const OVERVIEWS = ['brief', 'groups', 'tiles', 'masonry', 'panel'];

for (const [w, ht] of [[390, 844], [1100, 800], [1280, 800], [1366, 900], [1697, 922]]) {
  test(`Z: все компоновки, ${w}px`, async ({ app, page }) => {
    test.setTimeout(420_000);
    await page.setViewportSize({ width: w, height: ht });
    const all = new Set();
    let first = true;
    for (const mode of ['dark', 'light']) {
      for (const layout of LAYOUTS) {
        for (const overview of OVERVIEWS) {
          const look = { variant: 'z', mode, layout, overview, showLoad: true };
          await page.evaluate((lk) => { try { localStorage.setItem('nfqws-ui-look', JSON.stringify(lk)); } catch { /* the first round */ } }, look).catch(() => {});
          if (first) { await app.open('#/', look); first = false; }
          await page.reload();
          await expect(page.locator('main h1').first()).toBeVisible();
          await page.waitForTimeout(450);
          const lay = await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs', 'span.tk-', 'span.iss'] });
          const [a, b, c, d] = [await page.evaluate(signs), await page.evaluate(tightButtons), await page.evaluate(rows), await page.evaluate(topBar)];
          for (const x of [...a, ...b, ...c, ...d, ...lay]) all.add(mode === 'dark' ? `${layout}: ${x}` : `${layout}: ${x} (светлая)`);
        }
      }
    }
    console.log(`COMPO ${w}: ${all.size}${all.size ? '\n' + [...all].slice(0, 30).join('\n') : ''}`);
    expect([...all]).toEqual([]);
  });
}
