// NFQ-L260: a pick for YouTube's hosts or by QUIC says under its result that only the server's answer was checked —
// video is slowed down rather than closed, so «all green» did not mean the video plays. Other picks say nothing.
// Shown on recorded answers: nothing runs on the router.
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';
import { contrastReport } from 'pw-lib/contrast.mjs';
import { Z_ACCEPTED } from '../z-palette.mjs';

const pick = (host, proto) => ({
  state: 'done', type: 'pick', host, proto, started: 1794068000, finished: 1794068059, done: 3, total: 3, repeats: 2, current: null,
  baseline: { ok: false, code: 0, ms: 4003, reason: proto === 'quic' ? 'нет ответа' : 'тайм-аут' },
  results: [
    { name: 'fake ×6', from: 'стандартный набор', steps: ['--lua-desync=fake:blob=fake_default_quic:repeats=6'], ok: 2, tries: 2, ms: 120 },
    { name: 'fake ×11', from: 'стандартный набор', steps: ['--lua-desync=fake:blob=fake_default_quic:repeats=11'], ok: 2, tries: 2, ms: 140 },
  ],
});
const CASES = [
  ['googlevideo.com', 'quic', 'Скорость видео так не проверить', /первый пакет QUIC.*обычный HTTPS/],
  ['rr4---sn-ab5l6nrz.googlevideo.com', 'https', 'Скорость видео так не проверить', /первые 64 КБ/],
  ['discord.com', 'quic', 'По QUIC видно только ответ сервера', /первый пакет/],
  ['rutracker.org', 'https', null, null],
];

for (const [w, mode, variant] of [[1366, 'dark', 'z'], [390, 'light', 'z'], [1366, 'light', 'k'], [390, 'dark', 'k']]) {
  test(`L260: пояснение под подбором, ${variant}, ${w} px, ${mode}`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    let cur = CASES[0];
    await app.mock((b) => b.cmd === 'test_status' ? pick(cur[0], cur[1]) : undefined);
    for (const c of CASES) {
      cur = c;
      await app.open('#/tests?n=' + c[0], { variant, mode });
      await expect(page.locator('main section.panel', { hasText: 'Результаты для ' + c[0] })).toBeVisible({ timeout: 20_000 });
      await page.waitForTimeout(800);
      const note = page.locator('main .notice.info', { hasText: /так не проверить|видно только ответ сервера/ });
      if (!c[2]) { await expect(note).toHaveCount(0); continue; }
      await expect(note).toHaveCount(1);
      await expect(note).toContainText(c[2]);
      await expect(note).toContainText(c[3]);
      // NFQ-L261: video notes send to checking a whole config; the QUIC-only note does not
      await expect(note.locator('a[href="#/tests/configs"]')).toHaveCount(c[2].startsWith('Скорость') ? 1 : 0);
      expect(await page.locator('main').innerText(), c[0]).not.toMatch(/\bnull\b|undefined/);
      expect(await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs'] }), c[0]).toEqual([]);
      expect(await contrastReport(page, { ignore: Z_ACCEPTED }), c[0]).toEqual([]);
    }
  });
}
