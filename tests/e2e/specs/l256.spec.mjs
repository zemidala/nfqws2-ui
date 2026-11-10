// NFQ-L256: a pick for several sites names its step under the counter (the long first step looked like nothing
// happened). NFQ-L259: 0.0.0.0 from the router's resolver is the DNS blocking the name, not a provider's stub — the
// check says so instead of «turn on DoH/DoT». Shown on recorded answers: nothing runs on the router.
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';
import { contrastReport } from 'pw-lib/contrast.mjs';
import { Z_ACCEPTED } from '../z-palette.mjs';

const multi = (phase, done, total) => ({ state: 'running', type: 'multi', host: 'user.list', proto: 'https', started: 1794068000, done, total, phase,
  current: phase === 'baseline' ? 'rutracker.org без обхода' : 'пачка 2', list: { name: 'user.list' }, sites: [], hosts: [] });
const dns = (system, isp) => ({ ok: false, reason: 'не открывается', dns: { system, real: ['93.184.215.14'], isp, fake: true, real_ok: true, real_code: 200, real_ms: 120 } });

for (const [w, mode, variant] of [[1366, 'dark', 'z'], [390, 'light', 'z'], [1366, 'light', 'k'], [390, 'dark', 'k']]) {
  test(`L256: шаги подбора по списку, ${variant}, ${w} px, ${mode}`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    let st = multi('baseline', 3, 10);
    await app.mock((b) => b.cmd === 'test_status' ? st : undefined);
    await app.open('#/tests', { variant, mode });
    const step = page.locator('main section.panel[data-key="run"] .sm', { hasText: 'Шаг' });
    await expect(step).toHaveText('Шаг 1 из 3 — каждый сайт без обхода: что открывается и так, в подборе не участвует. Обычно до минуты', { timeout: 20_000 });
    await page.waitForTimeout(1000);   // blocks fade in: measured mid-way they look pale
    expect(await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs'] }), 'шаг 1').toEqual([]);
    expect(await contrastReport(page, { ignore: Z_ACCEPTED }), 'шаг 1').toEqual([]);
    st = multi('scan', 12, 57);
    await expect(step).toHaveText('Шаг 2 из 3 — все стратегии на всех сайтах, пачками', { timeout: 10_000 });
    st = multi('confirm', 50, 57);
    await expect(step).toHaveText('Шаг 3 из 3 — лучшие стратегии перепроверяются по одной', { timeout: 10_000 });
  });

  test(`L259: 0.0.0.0 от DNS роутера, ${variant}, ${w} px, ${mode}`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    let pr = dns(['0.0.0.0'], false);
    await app.mock((b) => b.cmd === 'probe' ? pr : undefined);
    await app.open('#/', { variant, mode });
    const box = page.locator('section.blk', { hasText: 'Проверить сайт' }).first();
    const run = async () => {
      await box.locator('input').first().fill('example.com');
      await box.locator('input').first().press('Enter');
    };
    await run();
    const sink = box.locator('.notice', { hasText: 'DNS роутера блокирует это имя' });
    await expect(sink).toBeVisible({ timeout: 30_000 });
    await expect(sink).toContainText('фильтром рекламы и фишинга');
    await expect(sink).not.toContainText('DNS-over-HTTPS');
    await page.waitForTimeout(1000);
    expect(await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs'] }), '0.0.0.0').toEqual([]);
    expect(await contrastReport(page, { ignore: Z_ACCEPTED }), '0.0.0.0').toEqual([]);
    // a provider's stub keeps its own advice
    pr = dns(['10.10.34.35'], true);
    await box.locator('input').first().fill('');
    await run();
    await expect(box.locator('.notice', { hasText: 'DNS роутера подменяет адрес' })).toContainText('DNS-over-HTTPS', { timeout: 30_000 });
  });

  test(`L259: 0.0.0.0 в «Диагнозе», ${variant}, ${w} px, ${mode}`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    await app.mock((b) => b.cmd !== 'diag' ? undefined
      : b.step === 'dns' ? { host: 'example.com', status: 'fake', system: ['0.0.0.0'], ref: ['93.184.215.14'], doh: [{ name: 'Cloudflare', ok: true, ips: ['93.184.215.14'] }],
        doh_answered: 1, podkop: false, ips: ['93.184.215.14'], alt: [] }
        : b.step === 'verdict' ? { code: 'dns_fake', pick: false, profile: null } : undefined);
    await app.open('#/diag?host=example.com', { variant, mode });
    await expect(page.locator('main .notice', { hasText: 'DNS роутера блокирует это имя' })).toContainText('Резолвер роутера отвечает 0.0.0.0', { timeout: 90_000 });
    await expect(page.locator('main')).toContainText('так DNS сам блокирует имя');
    await expect(page.locator('main')).not.toContainText('часто так выглядит заглушка провайдера');
    await page.waitForTimeout(1000);
    expect(await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs'] }), 'диагноз').toEqual([]);
    expect(await contrastReport(page, { ignore: Z_ACCEPTED }), 'диагноз').toEqual([]);
  });
}
