// NFQ-L292, stage 2: «Поставить в роутер» on the best DNS servers. Everything is mocked — nothing changes on the device:
// the button and the window on Keenetic and OpenWrt, no button with podkop, the confirm banner, the same text in every style.
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';
import { contrastReport } from 'pw-lib/contrast.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

const now = Math.floor(Date.now() / 1000);
const row = (name, ip, dot, doh, ok, ms) => ({ name, ip, host: dot, dot_name: dot, doh_url: doh, doh: ok ? { ok: true, ms } : { ok: false, why: 'tls' }, dot: ok ? { ok: true, ms } : { ok: false, why: 'tls' },
  udp: { ok: true, ms, who: ip }, sub: { bad: 0, of: 5 } });
const q = { started: now - 60, finished: now - 1, running: false, upstreams: { hosts: [], ips: [] },
  servers: [{ name: 'DNS роутера', ip: '127.0.0.1', kind: 'router', udp: { st: 'ok', ms: 2 } }],
  sites: [{ host: 'rutracker.org', router: { st: 'fake', ips: ['10.0.0.1'] }, ref: ['104.21.32.39'] }] };
const x = { running: false, finished: now - 1, servers: [row('Cloudflare', '1.1.1.1', 'cloudflare-dns.com', 'https://cloudflare-dns.com/dns-query', true, 34),
  row('Quad9', '9.9.9.9', 'dns.quad9.net', 'https://dns.quad9.net/dns-query', true, 48), row('AdGuard', '94.140.14.14', 'dns.adguard-dns.com', 'https://dns.adguard-dns.com/dns-query', true, 52),
  { ...row('Google', '8.8.8.8', 'dns.google', 'https://dns.google/dns-query', false, 31), udp: { ok: true, ms: 31, who: '95.0.0.1', intercept: 'isp' } }] };
const KEE = { kind: 'keenetic', installed: null, current: [
  { line: 'https upstream https://dns.google/dns-query dnsm', proto: 'DoH', url: 'https://dns.google/dns-query', host: 'dns.google', ip: '', domain: '' },
  { line: 'tls upstream 9.9.9.9 domain dns.quad9.net', proto: 'DoT', ip: '9.9.9.9', host: '', domain: 'dns.quad9.net' }] };
const OWRT = { kind: 'openwrt', installed: false, current: [] };
const PODKOP = { kind: null, why: 'podkop', current: [] };
const mock = (app, info, extra = () => undefined) => app.mock((b) => b.cmd === 'dns_get' ? q : b.cmd === 'dnsx_get' ? x : b.cmd === 'dns_set_info' ? info : extra(b));

for (const [w, hgt] of [[390, 844], [1366, 900]]) {
  test(`Keenetic: кнопка на плитке и окно «Поставить в роутер», ${w}`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: hgt });
    await mock(app, KEE);
    await app.open('#/tests/dns', { variant: 'a' });
    const tiles = page.locator('.dnsx-tile');
    await expect(tiles).toHaveCount(3);
    await expect(tiles.getByRole('button', { name: 'Поставить в роутер' })).toHaveCount(3);
    await tiles.first().getByRole('button', { name: 'Поставить в роутер' }).click();
    const m = page.getByRole('dialog', { name: 'Поставить Cloudflare в роутер' });
    await expect(m).toBeVisible();
    await expect(m).toContainText('Добавится DoT 1.1.1.1, имя cloudflare-dns.com');
    await expect(m).toContainText('Добавится DoH https://cloudflare-dns.com/dns-query');
    await expect(m.getByRole('checkbox', { name: 'Убрать закрытые провайдером: Google (DoH)' })).toBeChecked();
    await expect(m).toContainText('Роутер сразу проверит, что DNS отвечает. Если нет — всё вернётся само. Если да — подтвердите за 3 минуты, иначе тоже откат.');
    for (const mode of ['light', 'dark']) {
      await page.evaluate((md) => { document.documentElement.dataset.mode = md; }, mode);
      await page.waitForTimeout(200);
      expect(await auditLayout(page), `раскладка, ${mode}`).toEqual([]);
      expect(await contrastReport(page, { ignore: ['(span.bad-t.sm)'] }), `контраст, ${mode}`).toEqual([]);
    }
  });
}

test('OpenWrt: окно говорит про пакет https-dns-proxy', async ({ app, page }) => {
  await mock(app, OWRT);
  await app.open('#/tests/dns');
  await page.locator('.dnsx-tile', { hasText: 'Quad9' }).getByRole('button', { name: 'Поставить в роутер' }).click();
  await expect(page.getByRole('dialog', { name: 'Поставить Quad9 в роутер' })).toContainText('Будет установлен пакет https-dns-proxy (около 100 КБ), в нём сервер https://dns.quad9.net/dns-query. dnsmasq станет спрашивать его вместо DNS провайдера.');
});

test('podkop: кнопок нет, вместо них объяснение', async ({ app, page }) => {
  await mock(app, PODKOP);
  await app.open('#/tests/dns');
  await expect(page.locator('.dnsx-tile')).toHaveCount(3);
  await expect(page.getByRole('button', { name: 'Поставить в роутер' })).toHaveCount(0);
  await expect(page.locator('.dns-adv')).toContainText('Поставить сервер отсюда нельзя: DNS роутера ведёт podkop, и менять его в обход podkop нельзя. Сервер задаётся в настройках podkop.');
});

test('плашка «DNS роутера изменён» и запуск с убиранием закрытых', async ({ app, page }) => {
  let sent = null;
  let st = null;
  await mock(app, KEE, (b) => {
    if (b.cmd === 'dns_set_start') { sent = b; return KEE; }
    if (b.cmd === 'state' && sent) return undefined;
    return undefined;
  });
  await page.route('**/api.php*', async (route) => {
    let b = {};
    try { b = JSON.parse(route.request().postData() || '{}'); } catch { /* not JSON */ }
    if (b.cmd !== 'state' || !sent) return route.fallback();
    const r = await route.fetch();
    st = await r.json();
    st.dns_set = { state: 'pending', name: 'Cloudflare', kind: 'keenetic', deadline: st.now + 170 };
    return route.fulfill({ response: r, json: st });
  });
  await app.open('#/tests/dns');
  await page.locator('.dnsx-tile').first().getByRole('button', { name: 'Поставить в роутер' }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Поставить' }).click();
  await expect.poll(() => sent?.remove).toEqual(['https upstream https://dns.google/dns-query dnsm']);
  expect(sent.name).toBe('Cloudflare');
  const banner = page.locator('#pending .pending', { hasText: 'DNS роутера' });
  await expect(banner).toContainText('DNS роутера изменён — DNS отвечает');
  await expect(banner).toContainText(/Если не подтвердить, через 2:\d\d изменения откатятся сами\./);
  await expect(banner.getByRole('button', { name: 'Всё работает — оставить' })).toBeVisible();
  await expect(banner.getByRole('button', { name: 'Откатить сейчас' })).toBeVisible();
});

test('во всех стилях один и тот же текст окна', async ({ app, page }) => {
  await mock(app, KEE);
  const texts = {};
  for (const variant of ['a', 'b', 'c', 'k', 'z']) {
    await app.open('#/tests/dns', { variant });
    await page.locator('.dnsx-tile').first().getByRole('button', { name: 'Поставить в роутер' }).click();
    const m = page.getByRole('dialog');
    await expect(m).toBeVisible();
    texts[variant] = (await m.innerText()).replace(/\s+/g, ' ').trim();
    await page.keyboard.press('Escape');
    await expect(m).toHaveCount(0);
  }
  for (const v of ['b', 'c', 'k', 'z']) expect(texts[v], `стиль ${v} против a`).toBe(texts.a);
});
