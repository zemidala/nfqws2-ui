// «Обрыв на 16 КБ»: свои адреса (rangerxxy, #729 п. 13 и 5). Проба не запускается: запуск и итог подменены.
import { test, expect } from '../fixtures.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

const now = Math.floor(Date.now() / 1000);
const step = '--lua-desync=hostfakesplit:repeats=4:tcp_ts=-600000:tcp_md5:host=ya.ru';
const RESULT = { state: 'done', started: now - 300, finished: now, nets: [
  { label: '203.0.113.7', host: '203.0.113.7', own: true, pad: { ip: '203.0.113.7', port: 443, sni: 'example.com' }, ip: '203.0.113.7', asn: 64500,
    baseline: { ok: false, reason: 'обрыв на объёме' }, freeze: true, stall: false, no_tcp: false,
    found: { name: 'ya.ru', steps: [step], strategy: 'hostfakesplit ×4 tcp_ts,tcp_md5', ms: 400 }, tried: [{ name: 'ya.ru' }] },
  { label: '203.0.113.8:8443', host: '203.0.113.8', own: true, pad: { ip: '203.0.113.8', port: 8443, sni: 'example.com' }, ip: '203.0.113.8', asn: 64501,
    baseline: { ok: false, reason: 'обрыв на объёме' }, freeze: true, stall: false, no_tcp: false,
    found: { name: 'ya.ru', steps: [step], strategy: 'hostfakesplit ×4 tcp_ts,tcp_md5', ms: 400 }, tried: [{ name: 'ya.ru' }] },
  { label: '198.51.100.9', host: '198.51.100.9', own: true, pad: { ip: '198.51.100.9', port: 443, sni: 'example.com' }, ip: '198.51.100.9', asn: 64502,
    baseline: { ok: false, reason: 'тайм-аут' }, freeze: false, stall: false, no_tcp: true, found: null, tried: [] },
] };

for (const w of [390, 1366]) {
  test(`свои адреса: уходят в пробу, «нет соединения» и порт не 443 названы, ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    let sent = null;
    await app.mock((b) => {
      if (b.cmd === 'freeze_start') { sent = b; return { ok: true }; }
      if (b.cmd === 'freeze_get') return RESULT;
    });
    await app.open('#/tests/freeze', { variant: 'k' });
    await page.getByRole('textbox', { name: 'Свои сайты и адреса' }).fill('203.0.113.7, 203.0.113.8:8443 king.hr');
    await page.getByRole('button', { name: 'Запустить пробу' }).click();
    await expect.poll(() => sent).not.toBeNull();
    expect(sent.hosts).toEqual(['203.0.113.7', '203.0.113.8:8443', 'king.hr']);

    const rows = page.locator('.frz-row');
    await expect(rows.filter({ hasText: '198.51.100.9' })).toContainText('нет соединения с адресом');
    const p8443 = rows.filter({ hasText: '203.0.113.8:8443' });
    await expect(p8443).toContainText('Найдено для порта 8443');
    await expect(p8443.locator('.btn', { hasText: 'Применить для сети' })).toHaveCount(0);
    await expect(rows.filter({ hasText: '203.0.113.7' }).first().locator('.btn', { hasText: /Применить для сети|В профиль/ })).toHaveCount(1);
    await page.screenshot({ path: test.info().outputPath(`own-ip-${w}.png`), fullPage: true });
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
  });
}

test('локальный адрес — отказ сервера с понятным текстом', async ({ app }) => {
  const r = await app.api('freeze_start', { hosts: ['10.0.0.1'] });
  expect(JSON.stringify(r)).toContain('нужен внешний IPv4-адрес');
});
