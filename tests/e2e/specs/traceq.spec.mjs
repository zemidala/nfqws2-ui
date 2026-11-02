// «Трассировка» по QUIC (rangerxxy, #729 п. 17). Запуск и итог подменены — nfqws2 не трогается.
import { test, expect } from '../fixtures.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

const now = Math.floor(Date.now() / 1000);
const DONE = { state: 'done', type: 'trace', host: 'www.youtube.com', proto: 'quic', started: now - 5, finished: now, done: 1, total: 1,
  result: { ok: false, ms: null, reason: 'нет ответа', code: 0 }, trace_total: 4,
  trace: ['nfqws2 started', 'packet: id=1 len=1250', "desync profile 3 (noname) matches hostname='www.youtube.com'", 'LUA: fake'] };

for (const w of [390, 1366]) {
  test(`QUIC в трассировке: выбор уходит в trace_start, итог говорит про QUIC, ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    let sent = null;
    await app.mock((b) => {
      if (b.cmd === 'trace_start') { sent = b; return { ok: true }; }
      if (b.cmd === 'test_status') return sent ? DONE : { state: 'idle' };
    });
    await app.open('#/tests/trace', { variant: 'k' });
    await page.locator('#t-host').fill('www.youtube.com');
    // own dropdown: the native select is hidden, its button opens the list
    await page.locator('main .dd-btn[aria-label="Протокол"]').click();
    await page.locator('.dd-pop').getByRole('option', { name: /QUIC/ }).click();
    await page.getByRole('button', { name: 'Запустить трассировку' }).click();
    await expect.poll(() => sent).not.toBeNull();
    expect(sent.proto).toBe('quic');
    await expect(page.getByText('Не ответил по QUIC через текущую конфигурацию')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('main')).toContainText('#3');
    await page.screenshot({ path: test.info().outputPath(`trace-quic-${w}.png`), fullPage: true });
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
  });
}
