// «Соединения устройства»: the device list, a finished recording on mocked data (UDP with no reply, a port nfqws2
// does not see, a stalled flow), the hints and the «only with problems» filter. One real 30 s recording at the end.
import { test, expect } from '../fixtures.mjs';

const f = (proto, dst, dport, op, ob, rp, rb, extra = {}) => ({ proto, dst, dport, op, ob, rp, rb, stall: 0, n: 1, noans: rp ? 0 : 1, stalled: 0, first: 0, seen: true, ...extra });
const DATA = {
  running: false, ip: '192.168.1.50', secs: 60, started: 1793480000, finished: 1793480062, elapsed: 60, offload: false,
  flows: [
    f('tcp', '198.51.100.20', 443, 300, 40000, 280, 900000, { as_name: 'EXAMPLE-CDN' }),
    f('udp', '203.0.113.7', 50003, 900, 120000, 0, 0, { seen: false, as_name: 'EXAMPLE-VOICE' }),
    f('udp', '203.0.113.8', 19302, 400, 50000, 380, 48000, { profile: 5, l7: 'discord' }),
    f('tcp', '192.0.2.44', 6112, 60, 21000, 20, 16800, { stall: 16800, stalled: 1, seen: false }),
    // five connections to one server, three without an answer: the row must not say «отвечает»
    f('tcp', '192.0.2.60', 7000, 50, 9000, 40, 30000, { n: 5, noans: 3, as_name: 'EXAMPLE-GAME' }),
    f('udp', '198.18.0.12', 443, 30, 9000, 30, 12000, { podkop: true, seen: undefined }),
  ],
};

test('итог записи: подсказки, таблица, фильтр', async ({ app, page }) => {
  await app.mock((b) => b.cmd === 'conns_get' ? DATA : b.cmd === 'conns_devices' ? { items: [{ ip: '192.168.1.50', name: 'phone', flows: 12 }] } : undefined);
  await app.open('#/tests/conns');
  await expect(page.locator('main h1')).toHaveText('Соединения устройства');
  await expect(page.locator('main .dd-btn[aria-label="Устройство"]')).toContainText('phone — 192.168.1.50');
  await expect(page.locator('.notice.bad', { hasText: 'UDP без ответа' })).toContainText('203.0.113.7:50003');
  await expect(page.locator('.notice.warn', { hasText: 'Порт мимо nfqws2' })).toContainText('UDP 50003, TCP 6112');
  const rows = page.locator('.conn-b tbody tr');
  await expect(rows).toHaveCount(6);
  // problems first: no answer and stalled on top
  await expect(rows.nth(0)).toContainText('нет ответа');
  await expect(page.locator('.conn-b')).toContainText('замер на 16 КБ');
  const row = (ip) => page.locator('.conn-b tr', { hasText: ip });
  await expect(row('192.0.2.60')).toContainText('нет ответа у 3 из 5');
  // what nfqws2 does is the status's hint
  await expect(row('203.0.113.8').locator('td:has(.c-st)')).toHaveAttribute('title', /если это discord/);
  await expect(row('198.18.0.12').locator('td:has(.c-st)')).toHaveAttribute('title', /идёт через podkop/);
  // filter buttons with counts
  const tab = (t) => page.locator('.conn-tab', { hasText: t });
  await expect(tab('Все')).toHaveText('Все 6');
  await tab('Без ответа').click();
  await expect(tab('Без ответа')).toHaveText('Без ответа 2');
  await expect(rows).toHaveCount(2);
  await tab('Замерли').click();
  await expect(rows).toHaveCount(1);
  await tab('Мимо nfqws2').click();
  await expect(rows).toHaveCount(2);
  await tab('Все').click();
  await expect(rows).toHaveCount(6);
});

for (const w of [390, 1366]) {
  test(`без вылезания за край, ${w}`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    await app.mock((b) => b.cmd === 'conns_get' ? DATA : b.cmd === 'conns_devices' ? { items: [] } : undefined);
    await app.open('#/tests/conns');
    await expect(page.locator('.conn-b tbody tr')).toHaveCount(6);
    // the table scrolls inside its own frame on a phone; what stands in the frame is not past the page's edge
    const over = await page.evaluate(() => [...document.querySelectorAll('main *')].filter((e) => !e.closest('.conn-box') && e.getBoundingClientRect().right > document.documentElement.clientWidth + 0.5).length);
    expect(over).toBe(0);
  });
}

// picture for the Wiki on the mocked data (no real home devices in a public page): PW_WIKISHOT=1
test('снимок для Wiki', async ({ app, page }) => {
  test.skip(!process.env.PW_WIKISHOT, 'только по требованию (PW_WIKISHOT=1)');
  await page.setViewportSize({ width: 1366, height: 1000 });
  await app.mock((b) => b.cmd === 'conns_get' ? DATA : b.cmd === 'conns_devices' ? { items: [{ ip: '192.168.1.50', name: 'phone', flows: 12 }] } : undefined);
  await app.open('#/tests/conns', { variant: 'k', mode: 'light' });
  await page.locator('.conn-b tbody tr').first().waitFor();
  await page.waitForTimeout(500);
  await page.evaluate(() => scrollTo(0, 0));
  const r = await page.locator('main').boundingBox();   // the sticky header stays out: clip the window, not the element
  await page.screenshot({ path: '../../local/wiki/images/shots/p7-conns.png', clip: { x: r.x, y: r.y, width: r.width, height: Math.min(r.height, 1000 - r.y) } });
});

test('настоящая запись на 30 секунд доходит до конца', async ({ app, page, target }) => {
  test.setTimeout(120_000);
  await app.open('#/tests/conns');
  const devs = await page.locator('main select[aria-label="Устройство"] option').evaluateAll((o) => o.map((x) => x.value).filter(Boolean));
  test.skip(!devs.length, 'за устройством нет домашних устройств с соединениями');
  await page.locator('main .dd-btn[aria-label="Сколько записывать"]').click();
  await page.locator('.dd-pop .dd-o', { hasText: '30 секунд' }).click();
  await page.locator('main button', { hasText: 'Записать' }).click();
  await expect(page.locator('.notice.info', { hasText: 'Записываю' })).toBeVisible();
  await expect(page.locator('main button', { hasText: 'Записать' })).toBeEnabled({ timeout: 90_000 });
  await expect(page.locator('main .panel h2', { hasText: 'Соединения 192.' })).toBeVisible();
});
