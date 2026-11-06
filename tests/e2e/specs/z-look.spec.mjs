// Style Z: surfaces with visible steps, the current menu item, tiles on the overview, both themes and widths.
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

const PAGES = ['#/', '#/settings/p1', '#/sites', '#/tests', '#/settings/look', '#/settings/about', '#/log'];

for (const mode of ['light', 'dark']) {
  test(`Z ${mode}: шрифты, поверхности, меню и плитки`, async ({ app, page }) => {
    await page.setViewportSize({ width: 1366, height: 900 });
    await app.open('#/', { variant: 'z', mode });
    await page.waitForTimeout(800);
    const r = await page.evaluate(() => {
      const c = (s) => getComputedStyle(document.querySelector(s));
      const root = getComputedStyle(document.documentElement);
      return { sans: root.getPropertyValue('--sans'), bg: c('body').backgroundColor, h1: c('main h1').fontFamily,
        on: { shadow: c('.mnav a.on').boxShadow, weight: c('.mnav a.on').fontWeight, bg: c('.mnav a.on').backgroundColor }, tiles: document.querySelectorAll('.ov-tiles .tile').length };
    });
    console.log(mode, JSON.stringify(r));
    expect(r.sans).toContain('Golos Text');
    expect(r.h1).toContain('Geologica');
    expect(r.bg).toBe(mode === 'dark' ? 'rgb(27, 33, 48)' : 'rgb(232, 236, 243)');
    expect(r.on.shadow).toContain('rgb(255, 106, 20)');
    expect(r.on.weight).toBe('700');
    expect(r.tiles).toBeGreaterThan(3);
  });
}

for (const [w, ht] of [[390, 844], [1366, 900], [1697, 922]]) {
  test(`Z: раскладка страниц, ${w}px`, async ({ app, page }) => {
    test.setTimeout(120_000);
    await page.setViewportSize({ width: w, height: ht });
    const out = [];
    for (const mode of ['light', 'dark']) {
      for (const hash of PAGES) {
        await app.open(hash, { variant: 'z', mode });
        await page.waitForTimeout(500);
        const a = await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs', 'span.tk-', 'span.iss'] });
        if (a.length) out.push(`${mode} ${hash}: ${a.slice(0, 3).join(' | ')}`);
      }
    }
    console.log(`${w}: ${out.length ? out.join('\n') : 'чисто'}`);
    expect(out).toEqual([]);
  });
}
