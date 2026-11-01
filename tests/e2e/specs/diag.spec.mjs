// Диагноз блокировки: редкие итоги (закрыт адрес, «дело в DNS», заморозка) — с подменой ответов ступеней,
// обычный — по-настоящему. Проверяется, что страница доходит до итога и пишет нужный заголовок.
import { test, expect } from '../fixtures.mjs';

const run = async (app, host) => {
  await app.page.fill('#d-host', host);
  await app.page.locator('main').getByRole('button', { name: /^Проверить/ }).click();
};
const verdict = (app) => app.page.locator('main .notice').filter({ hasText: /./ }).first();

test('настоящий диагноз: сайт доходит до итога', async ({ app }) => {
  await app.open('#/diag');
  await run(app, 'ya.ru');
  await expect(verdict(app)).toContainText('блокировки нет', { timeout: 60_000 });
});

test('адрес закрыт, другие DNS не помогли → «Заблокирован адрес»', async ({ app }) => {
  await app.mock((b) => {
    if (b.cmd !== 'diag') return;
    if (b.step === 'tcp') return { results: [{ ip: '185.205.69.12', ok: false, ms: 4002, error: 'нет ответа' }] };
    if (b.step === 'other') return { checked: [], ok: false, ip: null, from: null, open: false };
    if (b.step === 'http') return { ok: false, kind: 'timeout', code: 0 };
    if (b.step === 'via') return { ok: false, reason: 'нет ответа (тайм-аут)', profile: 2, running: true };
  });
  await app.open('#/diag');
  await run(app, 'tuta.com');
  await expect(verdict(app)).toContainText('Заблокирован адрес', { timeout: 60_000 });
});

test('адрес от своего DNS закрыт, от другого отвечает → «дело в DNS» и кнопка подбора', async ({ app }) => {
  await app.mock((b) => {
    if (b.cmd !== 'diag') return;
    if (b.step === 'tcp') return { results: [{ ip: '157.240.205.174', ok: false, ms: 4002, error: 'нет ответа' }] };
    if (b.step === 'other') return { checked: [{ ip: '157.240.0.174', from: 'Quad9', tcp: true, open: false }], ok: true, ip: '157.240.0.174', from: 'Quad9', open: false };
    if (b.step === 'http') return { ok: false, kind: 'timeout', code: 0 };
    if (b.step === 'via') return { ok: false, reason: 'нет ответа (тайм-аут)', profile: 2, running: true };
  });
  await app.open('#/diag');
  await run(app, 'instagram.com');
  await expect(verdict(app)).toContainText('Закрыт адрес, который выдаёт ваш DNS', { timeout: 60_000 });
  await expect(verdict(app)).toContainText('157.240.0.174');
  await expect(app.page.getByRole('button', { name: 'Подобрать стратегию' })).toBeVisible();
});

test('заморозка на объёме → «Ограничение 16–20 КБ»', async ({ app }) => {
  await app.mock((b) => {
    if (b.cmd !== 'diag') return;
    if (b.step === 'tcp') return { results: [{ ip: '91.98.156.82', ok: true, ms: 40 }] };
    if (b.step === 'direct') return { ok: false, kind: 'freeze', rc: 28, code: 200, tls: true, ms: 8000, size: 16000, redirect: '', error: '', ip: b.ip, isp_page: false };
    if (b.step === 'via') return { ok: false, reason: 'соединение зависает на объёме', profile: 2, running: true };
  });
  await app.open('#/diag');
  await run(app, 'king.hr');
  await expect(verdict(app)).toContainText('16–20 КБ', { timeout: 60_000 });
});

// Сервер вместо JSON напечатал предупреждение PHP (display_errors = On в Entware) — страница называет ответ,
// а не падает на «Cannot read properties of undefined (reading 'filter')»
test('ответ не JSON → понятная ошибка вместо «reading filter»', async ({ app }) => {
  await app.page.route('**/api.php*', async (route) => {
    let b = {};
    try { b = JSON.parse(route.request().postData() || '{}'); } catch { /* не JSON */ }
    if (b.cmd === 'diag' && b.step === 'dns') return route.fulfill({ status: 200, contentType: 'text/html', body: '<br />\n<b>Warning</b>: something in <b>api.php</b> on line 1<br />\n{"host":"x"' });
    return route.continue();
  });
  await app.open('#/diag');
  await run(app, 'rutracker.org');
  const n = verdict(app);
  await expect(n).toContainText('Роутер ответил не так, как ожидалось: Warning', { timeout: 30_000 });
  await expect(n).not.toContainText('filter');
});
