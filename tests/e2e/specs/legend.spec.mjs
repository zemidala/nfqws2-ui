// The legends of «DNS-серверы» and «Мониторинг» are rows of items with a gap between them. In 1.33.3 a spacing rule
// made every grey caption in a panel a block, the legends too, and their items ran together into one line
// («…(режут по имени)SYN DROP — адрес закрытTIMEOUT…») — twice reported by the owner.
import { test, expect } from '../fixtures.mjs';

for (const variant of ['z', 'k', 'a', 'b', 'c']) for (const w of [390, 1366]) {
  test(`легенды в ряд с промежутками, ${variant}, ${w}`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    for (const [hash, sel] of [['#/tests/dns', '.dnsx-legend'], ['#/tests/monitor', '.mtl-legend']]) {
      await app.open(hash, { variant, mode: 'dark' });
      const lg = page.locator('main ' + sel).first();
      if (sel === '.mtl-legend') {   // the monitor's legend belongs to its table or chart and is hidden on a phone
        await page.waitForTimeout(2000);
        if (!await lg.isVisible()) continue;
      } else await expect(lg, hash).toBeVisible({ timeout: 20_000 });
      const r = await lg.evaluate((e) => {
        const kids = [...e.children].map((k) => k.getBoundingClientRect()).filter((b) => b.width);
        let touching = 0;
        for (let i = 1; i < kids.length; i++) if (Math.abs(kids[i].top - kids[i - 1].top) < 1 && kids[i].left - kids[i - 1].right < 8) touching++;
        return { display: getComputedStyle(e).display, items: kids.length, touching };
      });
      expect(r.display, hash).toMatch(/flex|grid/);
      expect(r.items, hash).toBeGreaterThan(1);
      expect(r.touching, `${hash}: пункты легенды вплотную`).toBe(0);
    }
  });
}
