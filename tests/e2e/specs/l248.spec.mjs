// NFQ-L248: the router's DNS gives a stub, the pick probes the real address and says so. The page is shown on a recorded
// result (statuses as the stand returned them with a stubbed rutracker.org), so nothing runs on the router.
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';
import { contrastReport } from 'pw-lib/contrast.mjs';

const steps = ['--lua-desync=fake:blob=tls_clienthello:tcp_md5', '--lua-desync=multisplit:pos=1,midsld'];
const filter = ['--filter-tcp=443', '--filter-l7=tls', '--payload=tls_client_hello'];
const PICK = {
  state: 'done', type: 'pick', host: 'rutracker.org', proto: 'https', started: 1794063586, finished: 1794063647, done: 13, total: 13,
  phase: 'pick', repeats: 2, current: null, dns_pin: { ip: '172.67.182.196', system: ['10.20.30.40'] },
  baseline: { ok: false, code: 0, ms: 4003, reason: 'тайм-аут' },
  results: [
    { name: 'fake tcp_md5 + multisplit', from: 'стандартный набор', steps, profile: [...filter, ...steps], ok: 2, tries: 2, ms: 314, reason: null },
    { name: 'multisplit pos=2', from: 'стандартный набор', steps: ['--lua-desync=multisplit:pos=2'], profile: [...filter, '--lua-desync=multisplit:pos=2'], ok: 0, tries: 1, ms: null, reason: 'тайм-аут' },
  ],
};
const ONLY_DNS = { ...PICK, baseline: { ok: true, code: 200, ms: 412, reason: null } };
const MULTI = {
  state: 'done', type: 'multi', host: 'сайтов: 2', proto: 'https', started: 1794064085, finished: 1794064156, done: 13, total: 13,
  phase: 'confirm', repeats: 1, current: null, list: null, hosts: ['rutracker.org', 'nnmclub.to'], parallel: 5, parallel_max: 15, candidates: 12,
  sites: [{ host: 'rutracker.org', dns_pin: { ip: '172.67.182.196', system: ['10.20.30.40'] }, baseline: 'тайм-аут' }, { host: 'nnmclub.to', baseline: 'тайм-аут' }],
  results: [{ name: 'fake tcp_md5 + multisplit', from: 'стандартный набор', steps, profile: [...filter, ...steps], scan: 2, scan_sites: ['rutracker.org', 'nnmclub.to'], confirmed: true,
    per: { 'rutracker.org': { ok: 1, tries: 1, ms: 318, reason: null }, 'nnmclub.to': { ok: 1, tries: 1, ms: 280, reason: null } }, opened: 2, ms: 299 }],
};

const RUNS = [[1366, 'light', 'z'], [1366, 'dark', 'z'], [390, 'light', 'z'], [390, 'dark', 'z'],
  ...['k', 'a', 'b', 'c'].flatMap((v) => [[1366, 'light', v], [1366, 'dark', v]])];
for (const [w, mode, variant] of RUNS) {
  test(`L248: подмена DNS в итоге подбора, ${variant}, ${w} px, ${mode}`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    let status = PICK;
    await app.mock((b) => b.cmd === 'test_status' ? status : undefined);
    for (const [n, [s, title]] of [[PICK, 'DNS роутера подменяет адрес сайта'], [ONLY_DNS, 'Сайт закрыт только подменой DNS'], [MULTI, 'DNS роутера подменяет адрес:']].entries()) {
      status = s;
      await app.open('#/tests', { variant, mode });
      if (n) await page.reload();   // the same hash again: goto only moves the hash, the page keeps the old status
      const box = s === MULTI ? page.locator('main div.sm', { hasText: title }) : page.locator('main .notice.warn', { hasText: title });
      await expect(box).toBeVisible({ timeout: 20_000 });
      const text = await box.innerText();
      if (s === MULTI) {
        expect(text).toContain('rutracker.org');
        expect(text).not.toContain('nnmclub.to');
      } else expect(text).toMatch(/10\.20\.30\.40.*172\.67\.182\.196/s);
      // the real address opens without bypass: one notice, not «no block» beside «the strategy removes the block»
      if (s === ONLY_DNS) await expect(page.locator('main .notice', { hasText: 'Без обхода сайт открывается' })).toHaveCount(0);
      await expect(box.getByRole('link', { name: 'DNS-серверы' })).toHaveAttribute('href', '#/tests/dns');
      await page.waitForTimeout(1000);   // blocks fade in: measured mid-way they look pale
      expect(await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs'] })).toEqual([]);
      // only the new block is judged here: the rest of the page has its own contrast run
      expect((await contrastReport(page)).filter((x) => /подменя|DNS-серверы|172\.67|rutracker\.org/.test(x))).toEqual([]);
    }
  });
}
