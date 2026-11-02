// «DNS-серверы»: свои серверы (rangerxxy, #729 п. 3). Проверка не запускается: запуск и итог подменены.
import { test, expect } from '../fixtures.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

const now = Math.floor(Date.now() / 1000);
const pubRow = (name, ip, ms) => ({ name, ip, dot: { ok: true, ms }, doh: { ok: true, ms }, udp: { ok: true, ms, who: ip, asn: 13335, as_name: 'CLOUDFLARENET' }, sub: { bad: 0, of: 5 } });
const DNS = { running: false, started: now - 90, finished: now - 30, own_servers: ['203.0.113.53', 'dns.example.org'],
  servers: [{ kind: 'router', name: 'DNS роутера', ip: '192.168.1.1', udp: { ok: true, ms: 2, who: '1.1.1.1', asn: 13335, as_name: 'CLOUDFLARENET' } }], sites: [] };
const DNSX = { running: false, started: now - 90, finished: now - 30, total: 4, done: 4, servers: [
  pubRow('Cloudflare', '1.1.1.1', 5), pubRow('Google', '8.8.8.8', 9),
  { name: 'Свой: 203.0.113.53', ip: '203.0.113.53', dot: null, doh: null, udp: { ok: true, ms: 30, who: '203.0.113.53', asn: 64500, as_name: 'TEST' }, sub: { bad: 1, of: 5 }, mine: true },
  { name: 'Свой: dns.example.org', ip: '', dot: { ok: false, why: 'syn' }, doh: null, udp: null, sub: null, mine: true },
] };

for (const w of [390, 1366]) {
  test(`свои серверы: поле заполнено, уходят в dns_start, в таблице первыми, ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    let sent = null;
    await app.mock((b) => {
      if (b.cmd === 'dns_get') return DNS;
      if (b.cmd === 'dnsx_get') return DNSX;
      if (b.cmd === 'dns_start') { sent = b; return { ...DNS, running: false }; }
    });
    await app.open('#/tests/dns', { variant: 'k' });
    const field = page.getByRole('textbox', { name: 'Свои DNS-серверы' });
    await expect(field).toHaveValue('203.0.113.53, dns.example.org');
    const names = await page.locator('.dnsx-tbl tbody tr:not(.own):not(.sep) td.nm').allTextContents();
    expect(names.slice(0, 2)).toEqual(['Свой: 203.0.113.53', 'Свой: dns.example.org']);
    await page.screenshot({ path: test.info().outputPath(`dns-own-${w}.png`), fullPage: true });
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);

    await field.fill('203.0.113.53 https://dns.example.org/dns-query');
    await page.locator('main .vh').getByRole('button', { name: /Проверить/ }).click();   // not the site check in the top bar
    await expect.poll(() => sent).not.toBeNull();
    expect(sent.own).toEqual(['203.0.113.53', 'https://dns.example.org/dns-query']);
  });
}
