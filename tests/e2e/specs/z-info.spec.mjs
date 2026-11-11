// Style Z: the round «i» before an explanation. Typed in Inter at 13 px its dot almost touched the stem and it read as a
// bare stick (the owner, 2026-11-11). Now it is drawn: measured by pixels down the middle column — a dot, a gap of at
// least 2 px of the circle's colour, a stem.
import { test, expect } from '../fixtures.mjs';

for (const mode of ['dark', 'light']) {
  test(`Z: у «i» в пояснении видна точка, ${mode}`, async ({ app, page }) => {
    await page.setViewportSize({ width: 1366, height: 900 });
    await app.open('#/settings/basic', { variant: 'z', mode });
    const ex = page.locator('main .frow > .hint:not(.error, .warning, .bad, :empty), main .panel > p.sm.muted').first();
    await ex.scrollIntoViewIfNeeded();
    await page.waitForTimeout(800);
    const b = await ex.boundingBox();
    const shot = await page.screenshot({ clip: { x: b.x + 12, y: b.y + 10, width: 20, height: 20 } });
    const col = await page.evaluate(async (png) => {
      const img = new Image();
      img.src = 'data:image/png;base64,' + png;
      await img.decode();
      const c = document.createElement('canvas');
      c.width = img.width; c.height = img.height;
      const g = c.getContext('2d');
      g.drawImage(img, 0, 0);
      const d = g.getImageData(10, 0, 1, img.height).data;
      const out = [];
      for (let y = 0; y < img.height; y++) out.push(Math.min(d[y * 4], d[y * 4 + 1], d[y * 4 + 2]) > 200 ? 'w' : '.');
      return out.join('');
    }, shot.toString('base64'));
    // runs of white down the middle: the dot, then the stem, with a real gap between
    const runs = [...col.matchAll(/w+/g)].map((m) => [m.index, m.index + m[0].length]);
    expect(runs.length, `столбец: ${col}`).toBe(2);
    expect(runs[1][0] - runs[0][1], `зазор между точкой и палочкой: ${col}`).toBeGreaterThanOrEqual(2);
    expect(runs[1][1] - runs[1][0], `палочка: ${col}`).toBeGreaterThanOrEqual(5);
  });
}
