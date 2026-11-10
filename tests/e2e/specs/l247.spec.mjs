// NFQ-L247: «Устройства» on Keenetic says first what the page is for, as on OpenWrt — the how-to alone left the
// question «what is this page for?». Keenetic only (the stand); elsewhere the OpenWrt line is checked to stay.
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';
import { contrastReport } from 'pw-lib/contrast.mjs';
import { Z_ACCEPTED } from '../z-palette.mjs';

for (const [w, mode, variant] of [[1366, 'dark', 'z'], [390, 'light', 'z'], [1366, 'light', 'k'], [390, 'dark', 'k']]) {
  test(`L247: зачем страница «Устройства», ${variant}, ${w} px, ${mode}`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    await app.open('#/devices', { variant, mode });
    const lead = page.locator('main p.dev-lead');
    await expect(lead).toHaveText(/^Кто в домашней сети идёт через nfqws2/, { timeout: 20_000 });
    await page.waitForTimeout(1000);   // blocks fade in: measured mid-way they look pale
    expect(await auditLayout(page), 'вёрстка').toEqual([]);
    expect(await contrastReport(page, { ignore: Z_ACCEPTED }), 'контраст').toEqual([]);
    // nothing on a fraction of a pixel: the line sits right above the panel
    const frac = await page.evaluate(() => [...document.querySelectorAll('main p.dev-lead, main section.panel')].map((e) => e.getBoundingClientRect())
      .filter((r) => Math.abs(r.top - Math.round(r.top)) > 0.02 || Math.abs(r.height - Math.round(r.height)) > 0.02).length);
    expect(frac).toBe(0);
  });
}
