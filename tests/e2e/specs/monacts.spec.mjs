// Monitoring rows keep all three buttons — «check now», «details», «remove» — at every width (2026-11-08: on a phone two
// of them were hidden and a site could not be removed from monitoring at all). Each is on screen and the table fits its box.
import { test, expect } from '../fixtures.mjs';

for (const w of [390, 600, 700, 1366]) {
  for (const variant of ['k', 'z']) {
    test(`мониторинг: три кнопки в строке, ${w} px, стиль ${variant}`, async ({ app, page }) => {
      await page.setViewportSize({ width: w, height: 900 });
      await app.open('#/tests/monitor', { variant, mode: 'dark' });
      await expect(page.locator('.mon-tbl tbody tr').first()).toBeVisible();
      const r = await page.evaluate(() => {
        const W = document.documentElement.clientWidth, t = document.querySelector('.mon-tbl');
        const rows = [...t.querySelectorAll('tbody tr')].map((tr) => [...tr.querySelectorAll('.mon-acts .btn')]
          .filter((b) => b.getBoundingClientRect().width > 0)
          .map((b) => { const q = b.getBoundingClientRect(); return { kind: b.getAttribute('aria-label').split(' ')[0], off: q.left < 0 || q.right > W }; }));
        return { kinds: [...new Set(rows.map((x) => x.map((b) => b.kind).join('/')))], off: rows.flat().filter((b) => b.off).length,
          table: Math.round(t.getBoundingClientRect().width), box: t.parentElement.clientWidth };
      });
      expect(r.kinds).toEqual(['Проверить/Подробно/Убрать']);
      expect(r.off).toBe(0);
      expect(r.table).toBeLessThanOrEqual(r.box);
    });
  }
}
