// NFQ-L292: secure DNS closed by the provider — the DNS page leads to a pick for DNS servers (DoH on 443, DoT on 853).
// The DNS page answers are mocked: a real block of DoT/DoH cannot be made at home. The pick page is the real one.
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';
import { contrastReport } from 'pw-lib/contrast.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

const now = Math.floor(Date.now() / 1000);
const srv = (name, ip, host, doh, dot, ms) => ({ name, ip, host, doh: doh ? { ok: true, ms } : { ok: false, why: 'tls' }, dot: dot ? { ok: true, ms } : { ok: false, why: 'tls' },
  udp: { ok: true, ms, who: '95.0.0.1', intercept: 'isp' }, sub: { bad: 0, of: 5 } });
const quick = (upstreams) => ({ started: now - 60, finished: now - 1, running: false, upstreams,
  servers: [{ name: 'DNS роутера', ip: '127.0.0.1', kind: 'router', udp: { st: 'ok', ms: 2 } }],
  sites: [{ host: 'rutracker.org', router: { st: 'fake', ips: ['10.0.0.1'] }, ref: ['104.21.32.39'] }] });
const ext = (servers) => ({ running: false, started: now - 50, finished: now - 1, servers });

const allClosed = ext([srv('Google', '8.8.8.8', 'dns.google', false, false, 31), srv('Cloudflare', '1.1.1.1', 'cloudflare-dns.com', false, false, 34),
  srv('Quad9', '9.9.9.9', 'dns.quad9.net', false, false, 48), srv('AdGuard', '94.140.14.14', 'dns.adguard-dns.com', false, false, 52)]);
const open = (r) => ({ ...r, udp: { ok: true, ms: r.udp.ms, who: r.ip } });
const partClosed = ext([srv('Google', '8.8.8.8', 'dns.google', false, false, 31), open(srv('Cloudflare', '1.1.1.1', 'cloudflare-dns.com', true, true, 34)),
  open(srv('Quad9', '9.9.9.9', 'dns.quad9.net', true, true, 48))]);

const mockDns = (app, q, x) => app.mock((b) => b.cmd === 'dns_get' ? q : b.cmd === 'dnsx_get' ? x : undefined);

for (const [w, hgt] of [[390, 844], [1366, 900]]) {
  test(`DNS закрыт целиком: блок «Что делать» ведёт в подбор для DNS, ${w}`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: hgt });
    await mockDns(app, quick({ hosts: [], ips: [] }), allClosed);
    await app.open('#/tests/dns', { variant: 'a' });
    const adv = page.locator('.dns-adv', { has: page.getByRole('heading', { name: 'Защищённый DNS закрыт провайдером' }) });
    await expect(adv).toBeVisible();
    await expect(adv).toContainText('подбор проверит стратегии на самых быстрых серверах, Google, Cloudflare и Quad9, по DoH и по DoT.');
    for (const mode of ['light', 'dark']) {
      await page.evaluate((m) => { document.documentElement.dataset.mode = m; }, mode);
      await page.waitForTimeout(200);
      expect(await auditLayout(page), `раскладка, ${mode}`).toEqual([]);
      expect(await contrastReport(page, { ignore: ['(span.bad-t.sm)'] }), `контраст, ${mode}`).toEqual([]);
    }
    await adv.getByRole('button', { name: 'Подобрать стратегию для DNS' }).click();
    await expect(page).toHaveURL(/#\/tests\/pick\?dns=dns\.google%408\.8\.8\.8%2Ccloudflare-dns\.com%401\.1\.1\.1%2Cdns\.quad9\.net%409\.9\.9\.9/);
  });
}

test('во всех стилях один и тот же текст: блок DNS и форма подбора', async ({ app, page }) => {
  await mockDns(app, quick({ hosts: [], ips: [] }), allClosed);
  const texts = {};
  for (const variant of ['a', 'b', 'c', 'k', 'z']) {
    await app.open('#/tests/dns', { variant });
    const adv = page.locator('.dns-adv').first();
    await expect(adv).toBeVisible();
    const a = (await adv.innerText()).replace(/новое/g, '').replace(/\s+/g, ' ').trim();
    await app.open('#/tests/pick?dns=dns.google@8.8.8.8', { variant });
    await expect(page.locator('main h1').first()).toHaveText('Подбор стратегии');
    await expect(page.getByRole('checkbox', { name: 'DoT — TLS, порт 853' })).toBeVisible();
    const f = (await page.locator('main .panel').first().innerText()).replace(/новое/g, '').replace(/\s+/g, ' ').trim();
    texts[variant] = a + ' || ' + f;
  }
  for (const v of ['b', 'c', 'k', 'z']) expect(texts[v], `стиль ${v} против a`).toBe(texts.a);
});

test('закрыт сервер, который стоит в роутере: строка со ссылкой на подбор', async ({ app, page }) => {
  await mockDns(app, quick({ hosts: ['dns.google'], ips: [] }), partClosed);
  await app.open('#/tests/dns');
  const line = page.locator('.dns-adv p', { hasText: 'который стоит в роутере' });
  await expect(line).toHaveText('Google, который стоит в роутере, провайдер закрыл. Чтобы оставить его, подберите стратегию для DNS.');
  await expect(line.getByRole('link')).toHaveAttribute('href', /\?dns=dns\.google%408\.8\.8\.8$/);
});

for (const [w, hgt] of [[390, 844], [1366, 900]]) {
  test(`подбор для DNS: серверы вписаны, DoH и DoT, без протокола и списка, ${w}`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: hgt });
    await app.mock((b) => b.cmd === 'test_status' ? { state: 'idle' } : undefined);
    await app.open('#/tests/pick?dns=dns.google@8.8.8.8,cloudflare-dns.com@1.1.1.1', { variant: 'a' });
    const main = page.locator('main');
    await expect(main.getByRole('radio', { name: 'DNS-серверы' })).toHaveAttribute('aria-checked', 'true');
    await expect(main.getByRole('textbox', { name: 'DNS-серверы, по одному в строке' })).toHaveValue('dns.google\ncloudflare-dns.com');
    await expect(main.locator('.frow .lbl', { hasText: /^Серверы$/ })).toBeVisible();
    await expect(main.getByRole('checkbox', { name: 'DoH — HTTPS, порт 443' })).toBeChecked();
    await expect(main.getByRole('checkbox', { name: 'DoT — TLS, порт 853' })).toBeChecked();
    await expect(main.getByRole('combobox', { name: 'Протокол' })).toBeHidden();
    await expect(main.getByRole('button', { name: 'Другие случайные' })).toBeHidden();
    for (const mode of ['light', 'dark']) {
      await page.evaluate((m) => { document.documentElement.dataset.mode = m; }, mode);
      await page.waitForTimeout(200);
      expect(await auditLayout(page), `раскладка, ${mode}`).toEqual([]);
      expect(await contrastReport(page), `контраст, ${mode}`).toEqual([]);
    }
    await main.getByRole('radio', { name: 'Один сайт' }).click();
    await expect(main.getByRole('checkbox', { name: 'DoT — TLS, порт 853' })).toBeHidden();
    await expect(main.locator('#t-host')).toBeVisible();
  });
}
