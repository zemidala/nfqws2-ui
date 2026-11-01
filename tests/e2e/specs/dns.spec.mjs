// DNS page (mockup local/design/dns-page.html): one «Проверить» runs the quick check (own DNS, five servers, sites)
// and the extended one (about fifty public servers). Drawn from mocked results: three verdict tiles, one advice, one
// table with the router's own DNS first, sites only when one has a stub. A real run is started and stopped on the
// device — the page never changes DNS settings, so it is safe anywhere.
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';

// ---- quick check (dns_get) ----
const pub = (name, ip, udp, dot = { ok: true, ms: 40 }, doh = { ok: true, ms: 60 }) => ({ name, ip, kind: 'public', udp, dot, doh });
const QUICK = {
  running: false, started: 1793470000, finished: 1793470030, isp_asn: 64500, isp_name: 'EXAMPLE-ISP',
  servers: [
    { name: 'DNS роутера', ip: '127.0.0.1', kind: 'router', udp: { st: 'ok', ms: 5, who: '203.0.113.9', asn: 64500, as_name: 'EXAMPLE-ISP' } },
    pub('Cloudflare', '1.1.1.1', { st: 'intercepted', why: 'isp', ms: 3, asn: 64500, as_name: 'EXAMPLE-ISP' }, { ok: false, ms: 0, why: 'refused' }),
    pub('Google', '8.8.8.8', { st: 'intercepted', why: 'other', ms: 12, asn: 43832, as_name: 'RIPN' }, { ok: true, ms: 33 }),
    pub('Яндекс', '77.88.8.8', { st: 'ok', ms: 20, asn: 13238, as_name: 'YANDEX' }, { ok: true, ms: 50 }, { ok: true, ms: 70 }),
  ],
};
const site = (host, st, ips = ['192.0.2.1']) => ({ host, ref: ['198.51.100.7'], router: { st, ips } });
const STUB = { ...QUICK, sites: [site('a.example', 'fake'), site('b.example', 'ok', ['198.51.100.7'])] };
const CLEAN = { ...QUICK, sites: [site('a.example', 'ok', ['198.51.100.7']), site('b.example', 'podkop', ['198.18.0.5'])] };

// ---- extended check (dnsx_get) ----
const S = (name, ip, doh, dot, udp, extra = {}) => ({ name, ip, doh, dot, udp, sub: { bad: 0, of: 5 }, ...extra });
const ok = (ms) => ({ ok: true, ms });
const EXT = {
  running: false, started: 1793470030, finished: 1793470107, total: 7, done: 7, step: 'tls',
  servers: [
    S('MSK-IX', '62.76.76.62', null, null, { ok: true, ms: 10, who: '193.232.93.60', asn: 43832, as_name: 'RIPN-NS5-RU-MSK' }, { sub: { bad: 2, of: 5 } }),
    S('Cloudflare', '1.1.1.1', ok(320), ok(197), { ok: true, ms: 12, who: '193.232.93.53', asn: 43832, as_name: 'RIPN-NS5-RU-MSK', intercept: 'ripn' }, { sub: { bad: 2, of: 5 } }),
    S('Comss.one', '83.220.169.155', ok(85), ok(58), { ok: true, ms: 12, who: '83.220.169.155', asn: 1, as_name: 'RU-JSCIOT' }),
    S('Яндекс', '77.88.8.8', ok(90), ok(68), { ok: true, ms: 23, who: '37.140.169.70', asn: 13238, as_name: 'YANDEX' }),
    S('Quad9', '9.9.9.9', ok(110), ok(95), { ok: true, ms: 30, who: '9.9.9.9', asn: 19281, as_name: 'QUAD9' }),
    S('Geohide', '45.155.204.190', { ok: false, why: 'syn' }, { ok: false, why: 'tls' }, { ok: false, why: 'timeout' }, { sub: null }),
    S('Mullvad', '', ok(268), ok(155), null, { sub: null }),
  ],
};

async function open(app, page, quick, ext, w = 1366) {
  await page.setViewportSize({ width: w, height: 900 });
  await app.mock((b) => b.cmd === 'dns_get' ? quick : b.cmd === 'dnsx_get' ? ext : undefined);
  await app.open('#/tests/dns');
  await expect(page.locator('main h1')).toHaveText('DNS-серверы');
}

test('до первой проверки: объяснение и кнопка', async ({ app, page }) => {
  await open(app, page, {}, {});
  await expect(page.locator('main .vh .btn.primary')).toBeEnabled();
  await expect(page.locator('main .vh .btn.primary')).toHaveText('Проверить');
  await expect(page.locator('main')).toContainText('Нажмите «Проверить»');
  await expect(page.locator('.dns-tile')).toHaveCount(0);
});

test('подмена: три вывода, «Что делать» с тремя лучшими, подменённый сайт', async ({ app, page }) => {
  await open(app, page, STUB, EXT);
  const tiles = page.locator('.dns-tile');
  await expect(tiles).toHaveCount(3);
  await expect(tiles.nth(0)).toContainText('Подменяет 1 сайт из 2');
  await expect(tiles.nth(1)).toContainText('Перехвачен');
  await expect(tiles.nth(1)).toContainText('сервер провайдера');
  // with the extended check done, the secure tile counts its servers
  await expect(tiles.nth(2)).toContainText('Работает у 5 из 6');
  await expect(tiles.nth(2)).toContainText('Geohide');
  // advice: the three fastest without interception and stubs
  const adv = page.locator('.dns-adv.bad');
  await expect(adv.locator('h2')).toHaveText('Что делать');
  const best = adv.locator('.dnsx-tile');
  await expect(best).toHaveCount(3);
  await expect(best.nth(0)).toContainText('Comss.one');
  await expect(best.nth(0)).toContainText('12 мс');
  await expect(best.nth(1)).toContainText('Яндекс');
  await expect(best.nth(2)).toContainText('Quad9');
  await expect(page.locator('.dns-site', { hasText: 'a.example' })).toContainText('подменён');
  await expect(page.locator('main h2', { hasText: 'Подменённые сайты' })).toBeVisible();
  await expect(page.locator('main .vh .btn.primary')).toHaveText('Проверить снова');
});

test('таблица: свой DNS первым, чистые сверху, отказы названы, сортировка', async ({ app, page }) => {
  await open(app, page, STUB, EXT);
  const all = page.locator('.dnsx-tbl tbody tr');
  await expect(all.first()).toHaveClass(/own/);
  await expect(all.first()).toContainText('DNS роутера');
  await expect(page.locator('.dnsx-tbl tbody tr.sep')).toHaveCount(1);
  const rows = page.locator('.dnsx-tbl tbody tr:not(.own):not(.sep)');
  await expect(rows.first()).toContainText('Comss.one');
  await expect(rows.first()).toHaveClass(/best/);
  // intercepted and stub-giving servers go below the clean ones
  const names = await rows.locator('td.nm').allTextContents();
  expect(names.indexOf('Cloudflare')).toBeGreaterThan(names.indexOf('Quad9'));
  const geo = rows.filter({ hasText: 'Geohide' });
  await expect(geo).toContainText('SYN DROP');
  await expect(geo).toContainText('TLS DROP');
  await expect(geo).toContainText('TIMEOUT');
  await expect(rows.filter({ hasText: 'Cloudflare' }).locator('td.who .bad-t')).toHaveCount(1);
  // intercepted on port 53: the stubs are not this server's — «перехват», and one line above the table says why
  await expect(rows.filter({ hasText: 'Cloudflare' }).locator('td.sub')).toHaveText('перехват');
  await expect(rows.filter({ hasText: 'MSK-IX' }).locator('td.sub')).toHaveText('2 из 5');
  await expect(page.locator('.dns-ic')).toContainText('Порт 53 перехвачен у 1 сервера');
  await expect(page.locator('.dns-ic')).toContainText('по DoH и DoT они отвечают сами');
  // sort by name: the same collation as the page
  await page.locator('.dnsx-tbl th button', { hasText: 'Сервер' }).click();
  await expect.poll(async () => {
    const n = await rows.locator('td.nm').allTextContents();
    return n.join('|') === [...n].sort((a, b) => a.localeCompare(b)).join('|');
  }).toBe(true);
});

test('всё чисто: «Менять ничего не нужно», сайты свёрнуты', async ({ app, page }) => {
  await open(app, page, CLEAN, EXT);
  const adv = page.locator('.dns-adv.ok');
  await expect(adv.locator('h2')).toHaveText('Менять ничего не нужно');
  await expect(adv).toContainText('Comss.one');
  await expect(page.locator('.dns-adv.bad')).toHaveCount(0);
  const more = page.locator('details.dns-more-panel');
  await expect(more).toContainText('подмены нет');
  await more.locator('summary').click();
  await expect(more).toContainText('podkop');
});

for (const w of [390, 1366]) {
  test(`вёрстка, ${w}px`, async ({ app, page }) => {
    await open(app, page, STUB, EXT, w);
    await expect(page.locator('.dnsx-tbl')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);
    expect(await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs'] })).toEqual([]);
  });
}

test('ход проверки: таблица сразу целиком, ячейки заполняются на месте, без перерисовки', async ({ app, page }) => {
  // three polls: the quick check running, the extended one half way, everything done
  const pending = (r) => ({ ...r, udp: null, dot: null, doh: null, sub: null, wait: { udp: true, ...(r.dot ? { dot: true } : {}), ...(r.doh ? { doh: true } : {}) } });
  const steps = [
    [{ running: true, started: 1793470000, step: 'servers', servers: QUICK.servers.slice(0, 1), sites: [] }, { ...EXT, running: true, queued: true, done: 0, servers: EXT.servers.map(pending) }],
    [STUB, { ...EXT, running: true, done: 3, step: 'udp', servers: EXT.servers.map((r, i) => i < 3 ? { ...r, dot: null, doh: null, wait: { dot: !!r.dot, doh: !!r.doh } } : pending(r)) }],
    [STUB, EXT],
  ];
  // before the button nothing was checked; after it every poll (dns_get, then dnsx_get) gives the next step
  let n = -1;
  await page.setViewportSize({ width: 1366, height: 900 });
  await app.mock((b) => {
    if (b.cmd === 'dns_start') { n = 0; return steps[0][0]; }
    // each step holds for two polls (the page polls every 2 s), so it can be looked at
    if (b.cmd === 'dns_get') return n < 0 ? {} : steps[Math.min(n >> 1, 2)][0];
    if (b.cmd === 'dnsx_get') return n < 0 ? {} : steps[Math.min(n++ >> 1, 2)][1];
    return undefined;
  });
  await app.open('#/tests/dns');
  await page.locator('main .vh .btn.primary').click();
  const rows = page.locator('.dnsx-tbl tbody tr:not(.own):not(.sep)');
  await expect(rows.first()).toContainText('MSK-IX');
  await expect(page.locator('.dns-tile.wait')).toHaveCount(3);
  await expect(page.locator('.dns-adv.wait')).toBeVisible();
  expect(await page.locator('.dnsx-tbl .spin.xs').count()).toBeGreaterThan(10);
  // mark the elements with a JS property (attributes are synced to the fresh drawing): a redraw would replace them
  await page.evaluate(() => { window.__tbl = document.querySelector('.dnsx-tbl'); window.__tile = document.querySelector('.dns-tiles'); });
  const first = await rows.locator('td.nm').allTextContents();
  // the list order holds while it runs
  await expect.poll(async () => (await rows.locator('td.nm').allTextContents()).join('|'), { timeout: 10_000 }).toBe(first.join('|'));
  await expect(page.locator('main .vh .btn.primary')).toBeEnabled({ timeout: 20_000 });
  expect(await page.evaluate(() => window.__tbl.isConnected && window.__tile.isConnected)).toBe(true);
  await expect(page.locator('.dnsx-tbl .spin')).toHaveCount(0);
  await expect(page.locator('.dns-tile.wait')).toHaveCount(0);
  // done: the best go first
  await expect(rows.first()).toContainText('Comss.one');
});

test('настоящая проверка: запуск и остановка на устройстве', async ({ app, page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1366, height: 900 });
  await app.open('#/tests/dns');
  const [q, x] = [await app.api('dns_get'), await app.api('dnsx_get')];
  test.skip(q.running || x.running, 'на устройстве уже идёт проверка DNS');
  await page.locator('main .vh .btn.primary').click();
  // the progress sits in the head of «Серверы», with the table already in place
  const prog = page.locator('main .panel', { has: page.locator('.dnsx-step') });
  await expect(prog).toBeVisible({ timeout: 15_000 });
  await expect(prog.locator('.dnsx-tbl')).toBeVisible();
  await expect(page.locator('main .vh .btn.primary')).toBeDisabled();
  // the block's own «Остановить» — the one in the top bar stops nfqws2
  await prog.getByRole('button', { name: 'Остановить' }).click();
  await expect.poll(async () => (await app.api('dns_get')).running || (await app.api('dnsx_get')).running, { timeout: 90_000 }).toBe(false);
  await expect(page.locator('main .vh .btn.primary')).toBeEnabled({ timeout: 10_000 });
  await expect(page.locator('.dns-tile').first()).toBeVisible();
});
