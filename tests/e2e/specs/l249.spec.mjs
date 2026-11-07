// NFQ-L249, mockup local/design/l249.html, variants Б + Б: under a strategy's time — the first byte and the speed;
// «Соединения устройства» — the name under the address, «Подобрать» in its own column, the .pcap in a folded
// «Для опытных», an offer to install tcpdump. Shown on recorded answers: nothing runs on the router.
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';
import { contrastReport } from 'pw-lib/contrast.mjs';

const filter = ['--filter-tcp=443', '--filter-l7=tls', '--payload=tls_client_hello'];
const st = (name, steps, x) => ({ name, from: 'стандартный набор', steps, profile: [...filter, ...steps], ...x });
const PICK = {
  state: 'done', type: 'pick', host: 'nnmclub.to', proto: 'https', started: 1794068000, finished: 1794068059, done: 13, total: 13, phase: 'speed', repeats: 2, current: null,
  baseline: { ok: false, code: 0, ms: 4003, reason: 'тайм-аут' },
  results: [
    st('fake tcp_md5 + multisplit', ['--lua-desync=fake:blob=tls_clienthello:tcp_md5', '--lua-desync=multisplit:pos=1,midsld'], { ok: 2, tries: 2, ms: 650, ttfb: 567, kbps: 370, kb: 148 }),
    st('fake tcp_seq + multidisorder', ['--lua-desync=fake:blob=tls_clienthello:tcp_seq=-10000', '--lua-desync=multidisorder:pos=1,midsld'], { ok: 2, tries: 2, ms: 292, ttfb: 291, kbps: 1540, kb: 148 }),
    st('hostfakesplit google.com', ['--lua-desync=hostfakesplit:host=google.com:tcp_md5'], { ok: 2, tries: 2, ms: 677, ttfb: 569 }),
    st('multisplit pos=2', ['--lua-desync=multisplit:pos=2'], { ok: 0, tries: 1, ms: null, reason: 'тайм-аут' }),
  ],
};
const flow = (x) => ({ proto: 'tcp', dport: 443, n: 1, noans: 0, stalled: 0, op: 10, ob: 4000, rp: 10, rb: 9000, stall: 0, seen: true, ...x });
const CONNS = {
  running: false, id: 'abc', ip: '192.168.1.140', secs: 60, started: 1794069988, finished: 1794070050, names: true, capture: true, pcap: 1260158, noacct: false,
  flows: [
    flow({ dst: '203.0.113.14', names: ['rr4---sn-ab5l6nrz.googlevideo.com'], as_name: 'GOOGLE', n: 5, stalled: 3, stall: 16384 }),
    flow({ dst: '198.51.100.7', names: ['gateway.discord.gg', 'discord.com'], as_name: 'CLOUDFLARENET', noans: 1, rp: 0, rb: 0 }),
    flow({ dst: '198.51.100.201', proto: 'udp', dport: 50007, as_name: 'DISCORD', noans: 1, rp: 0, rb: 0 }),
    flow({ dst: '203.0.113.80', dport: 80, names: ['example.com'], noans: 1, rp: 0, rb: 0 }),
    flow({ dst: '203.0.113.46', names: ['www.youtube.com'], as_name: 'GOOGLE' }),
  ],
};
const DEVICES = (tcpdump) => ({ items: [{ ip: '192.168.1.140', name: 'Pixel-8', flows: 37 }], noacct: false, tcpdump });

const RUNS = [[1366, 'light', 'z'], [1366, 'dark', 'z'], [390, 'light', 'z'], [390, 'dark', 'z'],
  ...['k', 'a', 'b', 'c'].flatMap((v) => [[1366, 'light', v], [1366, 'dark', v]])];

async function check(page, what) {
  await page.waitForTimeout(1000);   // blocks fade in: measured mid-way they look pale
  expect(await page.locator('main').innerText(), `${what}: «null» в тексте`).not.toMatch(/\bnull\b|undefined/);
  expect(await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs'] }), what).toEqual([]);
  expect(await contrastReport(page), what).toEqual([]);
}

for (const [w, mode, variant] of RUNS) {
  test(`L249: скорость в подборе, ${variant}, ${w} px, ${mode}`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    await app.mock((b) => b.cmd === 'test_status' ? PICK : undefined);
    await app.open('#/tests', { variant, mode });
    // the results table only: «Последние подборы» below may hold the same strategy names
    const row = (name) => page.locator('main section.panel', { hasText: 'Результаты для nnmclub.to' }).locator('tr', { hasText: name });
    await expect(row('fake tcp_md5 + multisplit')).toBeVisible({ timeout: 20_000 });
    // the first byte only where it says something of its own; the speed for the measured ones
    await expect(row('fake tcp_md5 + multisplit').locator('td.num .sm')).toHaveText('ответ 567 мс · 370 КБ/с');
    await expect(row('fake tcp_seq + multidisorder').locator('td.num .sm')).toHaveText('1,5 МБ/с');
    await expect(row('hostfakesplit google.com').locator('td.num .sm')).toHaveText('ответ 569 мс');
    await expect(row('multisplit pos=2').locator('td.num .sm')).toHaveCount(0);
    await expect(page.locator('main .notice.ok .t').first()).toHaveText(/^Скачивание до 1 МБ: 1,5 МБ\/с\. /);
    await check(page, 'подбор');
  });

  test(`L249: имена в «Соединениях», ${variant}, ${w} px, ${mode}`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    let tcpdump = true;
    await app.mock((b) => b.cmd === 'conns_get' ? CONNS : b.cmd === 'conns_devices' ? DEVICES(tcpdump) : undefined);
    await app.open('#/tests/conns', { variant, mode });
    const row = (ip) => page.locator('main .conn-tbl tr', { hasText: ip });
    await expect(row('198.51.100.7')).toBeVisible({ timeout: 20_000 });
    // the address first, the name and network under it
    await expect(row('198.51.100.7').locator('td.c-dst > .mono')).toHaveText('198.51.100.7');
    await expect(row('198.51.100.7').locator('td.c-dst > .sm')).toHaveText('gateway.discord.gg, discord.com · CLOUDFLARENET');
    // «Подобрать»: a named web connection with a problem; not for UDP, not for one that answers
    await expect(row('198.51.100.7').getByRole('link', { name: 'Подобрать' })).toHaveAttribute('href', /host=gateway\.discord\.gg/);
    await expect(row('203.0.113.80').getByRole('link', { name: 'Подобрать' })).toHaveAttribute('href', /host=example\.com&proto=http/);
    await expect(row('198.51.100.201').getByRole('link', { name: 'Подобрать' })).toHaveCount(0);
    await expect(row('203.0.113.46').getByRole('link', { name: 'Подобрать' })).toHaveCount(0);
    await expect(page.locator('main label.chk', { hasText: 'Определять имена сайтов' }).locator('input')).toBeChecked();
    // .pcap folded under «Для опытных»
    const more = page.locator('main details', { hasText: 'Для опытных' });
    await expect(more.getByRole('link', { name: 'Скачать .pcap' })).toBeHidden();
    await more.locator('summary').click();
    await expect(more.getByRole('link', { name: 'Скачать .pcap' })).toHaveAttribute('href', 'api.php?download=conns-pcap');
    await expect(more).toContainText('1,2 МБ');
    await check(page, 'соединения');
    // no tcpdump: an offer to install it instead of the checkbox
    tcpdump = false;
    await page.reload();
    await expect(page.locator('main .notice.info', { hasText: 'нет tcpdump' }).getByRole('button', { name: 'Установить tcpdump' })).toBeVisible({ timeout: 20_000 });
    await expect(page.locator('main label.chk', { hasText: 'Определять имена сайтов' })).toHaveCount(0);
    await check(page, 'без tcpdump');
  });
}
