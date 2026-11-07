// Mockup check before showing it (run: PW_MOCKUP=local/design/<file>.html): at phone and desktop width nothing
// sticks out past the viewport, either the page itself or a block inside it. Mockups use #phone for phone mode.
import { test, expect } from '@playwright/test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { auditLayout } from 'pw-lib/layout.mjs';
import { contrastReport } from 'pw-lib/contrast.mjs';

const file = process.env.PW_MOCKUP;
const extra = process.env.PW_MOCKUP_HASH || '';   // e.g. "v=c" — the mockup reads its variant from the hash
for (const [w, hash] of [[390, '#phone' + (extra ? '-' + extra : '')], [1366, extra ? '#' + extra : '']]) {
  test(`макет без вылезания за край, ${w}`, async ({ page }) => {
    test.skip(!file, 'только с PW_MOCKUP=путь к макету');
    // a mockup built by script: if the script fails, the page is empty and «nothing sticks out» proves nothing
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e.message || e)));
    await page.setViewportSize({ width: w, height: 900 });
    await page.goto(pathToFileURL(path.resolve('..', '..', file)).href + hash);   // file is relative to the project root
    await page.waitForTimeout(500);
    expect(errors, 'ошибки JS в макете').toEqual([]);
    expect((await page.locator('body').innerText()).length, 'макет пустой').toBeGreaterThan(200);
    // PW_MOCKUP_AUDIT=1 — mockups made of the interface's own styles: the same layout and contrast checks as the pages
    if (process.env.PW_MOCKUP_AUDIT) {
      for (const mode of ['light', 'dark']) {
        await page.evaluate((m) => { document.documentElement.dataset.mode = m; }, mode);
        await page.waitForTimeout(300);
        expect(await auditLayout(page), `раскладка, ${mode}`).toEqual([]);
        expect(await contrastReport(page), `контраст, ${mode}`).toEqual([]);
      }
    }
    const out = await page.evaluate(() => {
      const W = document.documentElement.clientWidth, r = [];
      for (const e of document.querySelectorAll('body *')) { const b = e.getBoundingClientRect(); if (b.width && b.right > W + 0.5) r.push(`${e.tagName}.${e.className} ${Math.round(b.left)}–${Math.round(b.right)}`); }
      return r.slice(0, 10);
    });
    await page.screenshot({ path: `test-results/mockup-${w}${extra ? '-' + extra.replace(/\W/g, '') : ''}.png`, fullPage: true });   // to look at it, not only measure
    expect(out, 'вылезает за край').toEqual([]);
  });
}
