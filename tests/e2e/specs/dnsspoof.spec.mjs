// «Проверить сайт» при подмене DNS: вместо «профиль не помогает» — «DNS роутера подменяет адрес», ссылка на инструкцию
// и маршрут по настоящему адресу. Ответ probe подменён; без подмены — обычный итог.
import { test, expect } from '../fixtures.mjs';

const none = { profile: null, steps: [] };
const spoof = (realOk) => ({
  ok: false, code: null, ms: 5003, reason: 'нет ответа (тайм-аут) — похоже на блокировку',
  dns: { system: ['91.195.127.75'], real: ['172.67.182.196', '104.21.32.39'], isp: true, real_ok: realOk,
    real_code: realOk ? 301 : null, real_ms: 250, real_reason: realOk ? null : 'нет ответа (тайм-аут) — похоже на блокировку' },
  routes: { https: none, quic: none, http: none },
});
const box = (app) => app.page.locator('.notice').filter({ hasText: 'DNS роутера подменяет адрес' });

test('подмена, по настоящему адресу открывается → предупреждение, ссылка, маршрут по настоящему адресу', async ({ app }) => {
  await app.mock((b) => (b.cmd === 'probe' ? spoof(true) : undefined));
  await app.open('#/?q=rutracker.org');
  await expect(box(app)).toBeVisible({ timeout: 30_000 });
  await expect(box(app)).toContainText('сеть вашего провайдера');
  await expect(box(app)).toContainText('открывается (HTTP 301');
  await expect(box(app).getByRole('link', { name: 'Как включить защищённый DNS' })).toHaveAttribute('href', /wiki\/11\..*#13-dns/);
  await expect(app.page.getByText('Маршрут — по настоящему адресу 172.67.182.196')).toBeVisible();
  await expect(app.page.getByText('не помогает. Подберите')).toHaveCount(0);
  if (process.env.SHOT) for (const w of [1366, 390]) { await app.page.setViewportSize({ width: w, height: 900 }); await box(app).scrollIntoViewIfNeeded(); await app.page.screenshot({ path: `${process.env.SHOT}/dns-${w}.png` }); }
});

test('подмена, по настоящему тоже не открывается → совет сначала включить DNS', async ({ app }) => {
  await app.mock((b) => (b.cmd === 'probe' ? spoof(false) : undefined));
  await app.open('#/?q=rutracker.org');
  await expect(box(app)).toContainText('По настоящему адресу тоже не открывается', { timeout: 30_000 });
});

test('адрес не из сети провайдера → не «подмена», а сервер закрыт по IP, без совета про DNS', async ({ app }) => {
  const r = spoof(true);
  r.dns = { ...r.dns, system: ['157.240.222.60'], real: ['31.13.85.52'], isp: false, fake: false, real_code: 400 };
  await app.mock((b) => (b.cmd === 'probe' ? r : undefined));
  await app.open('#/?q=web.whatsapp.com');
  const other = app.page.locator('.notice').filter({ hasText: 'Не открывается по адресу, который дал роутер' });
  await expect(other).toBeVisible({ timeout: 30_000 });
  await expect(other).toContainText('закрыл этот сервер по IP');
  await expect(box(app)).toHaveCount(0);
  await expect(other.getByRole('link', { name: 'Как включить защищённый DNS' })).toHaveCount(0);
  await expect(other).not.toContainText('DNS-over-HTTPS');
});

test('без подмены — прежний итог', async ({ app }) => {
  await app.mock((b) => (b.cmd === 'probe' ? { ok: false, code: null, ms: 5003, reason: 'нет ответа (тайм-аут) — похоже на блокировку' } : undefined));
  await app.open('#/?q=rutracker.org');
  await expect(app.page.locator('.notice').filter({ hasText: 'Не открывается: нет ответа' })).toBeVisible({ timeout: 30_000 });
  await expect(box(app)).toHaveCount(0);
});
