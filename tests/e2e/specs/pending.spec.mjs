// Плашка «nfqws2 перезапущен. Всё работает?» на телефоне: текст и сайты не сжимаются в столбик по букве,
// кнопки уходят на свою строку (снимок reSigo, #543–544). Состояние подкладывается в ответ state — nfqws2 не трогается.
import { test, expect } from '../fixtures.mjs';

const HOSTS = ['rutracker.org', 'youtube.com', 'discord.com', 'x.com', 'nnmclub.to', 'www.coursera.org', 'www.linux.org.ru'];

for (const variant of ['k', 'a']) {
  for (const [w, hgt] of [[390, 900], [1366, 900]]) {
    test(`плашка подтверждения — ${variant}, ${w}px`, async ({ app, page }) => {
      await page.setViewportSize({ width: w, height: hgt });
      await page.route('**/api.php*', async (route) => {
        let body = {};
        try { body = JSON.parse(route.request().postData() || '{}'); } catch { /* не JSON */ }
        if (body.cmd !== 'state') return route.continue();
        const res = await route.fetch();
        const j = await res.json();
        const st = j.data || j;
        st.pending = { id: 'e2e', state: 'waiting', deadline: st.now + 170, files: ['nfqws2.conf'], checks: HOSTS.map((host) => ({ host, ok: true })) };
        return route.fulfill({ response: res, json: j });
      });
      await app.open('#/', { variant });
      const main = page.locator('.pending .item-main');
      await expect(main).toBeVisible();
      const mb = await main.boundingBox();
      expect(mb.width, `текст плашки ${Math.round(mb.width)} px`).toBeGreaterThan(Math.min(250, w - 60));
      // сайт — одной строкой, а не столбиком
      const chip = await page.locator('.pending .chip').first().boundingBox();
      expect(chip.height, `высота метки сайта ${Math.round(chip.height)} px`).toBeLessThan(40);
      const btn = await page.locator('.pending .btn', { hasText: 'Всё работает' }).boundingBox();
      expect(btn.x + btn.width).toBeLessThanOrEqual(w);
      await page.screenshot({ path: test.info().outputPath('pending.png') });
    });
  }
}
