// Screenshot of «DNS-серверы» on the mockup's own data (local/design/dns-servers.html, variant Б), to put the page
// next to the mockup and compare element by element. Run on demand: PW_DNSMOCK=1 pw.py … -g "макет DNS"
import { test } from '../fixtures.mjs';

const ok = (ms) => ({ ok: true, ms });
const pub = (name, ip, udp, dot, doh) => ({ name, ip, kind: 'public', udp, dot, doh });
const isp = (ms) => ({ st: 'intercepted', why: 'isp', ms, asn: 64500, as_name: 'EXAMPLE-ISP' });
const DATA = {
  running: false, started: 1793471962, finished: 1793472000,
  servers: [
    { name: 'DNS роутера', ip: '192.168.1.1', kind: 'router', udp: { st: 'ok', ms: 12 } },
    { name: 'DNS провайдера', ip: '195.98.64.65', kind: 'isp', udp: { st: 'ok', ms: 9 } },
    pub('Cloudflare', '1.1.1.1', isp(8), { ok: false, ms: 0, why: 'tls' }, ok(41)),
    pub('Google', '8.8.8.8', isp(9), ok(36), ok(44)),
    pub('Quad9', '9.9.9.9', isp(9), ok(29), ok(33)),
    pub('AdGuard DNS', '94.140.14.14', isp(10), ok(52), ok(58)),
    pub('Яндекс', '77.88.8.8', { st: 'ok', ms: 14, asn: 13238, as_name: 'YANDEX' }, ok(18), ok(21)),
  ],
  sites: [
    ['rutracker.org', 'fake', 'fake'], ['nnmclub.to', 'fake', 'fake'], ['x.com', 'ok', 'none'],
    ['instagram.com', 'ok', 'ok'], ['linkedin.com', 'ok', 'ok'], ['ntc.party', 'ok', 'ok'],
  ].map(([host, r, i]) => ({ host, ref: ['203.0.113.10'], router: { st: r, ips: ['203.0.113.10'] }, isp: { st: i, ips: [] } })),
};

for (const [w, mode] of [[1366, 'dark'], [390, 'dark']]) {
  test(`макет DNS: снимок ${w}`, async ({ app, page }) => {
    test.skip(!process.env.PW_DNSMOCK, 'только по требованию (PW_DNSMOCK=1)');
    await page.setViewportSize({ width: w, height: w > 500 ? 1700 : 3000 });   // fullPage resizes the window and the page re-renders
    await app.mock((b) => b.cmd === 'dns_get' ? DATA : undefined);
    await app.open('#/tests/dns', { variant: 'z', mode });
    await page.locator('.dns-site').last().waitFor();
    await page.waitForTimeout(500);
    await page.screenshot({ path: `test-results/dnsmock-${test.info().project.name}-${w}.png` });
  });
}
