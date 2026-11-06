// Окно обновления — целиком на подмене: роутер «сообщает» о версии 9.9.9, установка и журнал — подменённые.
// update_run до роутера не доходит никогда (подмена отвечает на него всегда).
import { test, expect } from '../fixtures.mjs';

const NEW = '9.9.9';

// state — настоящий, но с «доступным обновлением»; status — что отвечать на update_status (номер опроса → ответ)
async function setup(app, status) {
  let polls = 0;
  await app.page.route('**/api.php*', async (route) => {
    let b = {};
    try { b = JSON.parse(route.request().postData() || '{}'); } catch { /* не JSON */ }
    if (b.cmd === 'state') {
      const res = await route.fetch();
      const j = await res.json();
      j.ui.update = { ...j.ui.update, latest: NEW, available: true, can_update: true, running: false, notes: '- проверочное обновление' };
      return route.fulfill({ response: res, json: j });
    }
    if (b.cmd === 'update_check') return route.fulfill({ json: { current: '1.12.0', latest: NEW, available: true, can_update: true, notes: '- проверочное обновление', checked: Math.floor(Date.now() / 1000) } });
    if (b.cmd === 'update_run') return route.fulfill({ json: { ok: true } });
    if (b.cmd === 'update_status') {
      const r = status(polls++);
      return r.__status ? route.fulfill({ status: r.__status, json: r.body || {} }) : route.fulfill({ json: r });
    }
    return route.continue();
  });
  await app.open('#/');
  await app.page.locator('#upd-banner').getByRole('button', { name: 'Обновить' }).click();
  await app.yes();   // «Обновить nfqws2-ui до …?»
}
const dlg = (app) => app.page.locator('.modal').last();

for (const width of [390, 1366]) {
  test(`журнал растёт, а окно и «Копировать» стоят на месте, ${width}px`, async ({ app }) => {
    test.setTimeout(60_000);
    await app.page.setViewportSize({ width, height: 900 });
    const line = (i) => `строка журнала ${i}`;
    await setup(app, (n) => ({ running: n < 6, exit: null, version: '1.12.0', log: Array.from({ length: (n + 1) * 4 }, (_, i) => line(i)).join('\n') + '\n' }));
    const log = dlg(app).locator('pre.box');
    await expect(log).toBeVisible();
    await expect(dlg(app)).not.toContainText('проверочное обновление');
    const place = () => dlg(app).locator('.copybox > .btn').evaluate((el) => { const r = el.getBoundingClientRect(); return [r.left, r.top]; });
    const was = await place();
    await expect(log).toContainText(line(23), { timeout: 20_000 });
    expect(await place()).toEqual(was);
  });
}

test('обновление прошло → «Готово», страница перезагрузится', async ({ app }) => {
  await setup(app, (n) => n < 2 ? { running: true, log: 'Скачиваю nfqws2-ui v9.9.9…', exit: null, version: '1.12.0' }
    : { running: false, log: 'Скачиваю…\nГотово: nfqws2-ui 9.9.9.\n[exit 0]', exit: 0, version: NEW });
  await expect(dlg(app)).toContainText(`Готово: nfqws2-ui ${NEW}`, { timeout: 30_000 });
});

test('установка упала → «Обновление не удалось», кнопка снова доступна', async ({ app }) => {
  await setup(app, (n) => n < 1 ? { running: true, log: 'Скачиваю…', exit: null, version: '1.12.0' }
    : { running: false, log: 'Скачиваю…\nконтрольная сумма не совпала\n[exit 1]', exit: 1, version: '1.12.0' });
  await expect(dlg(app)).toContainText('Обновление не удалось', { timeout: 30_000 });
  await expect(dlg(app).getByRole('button', { name: /Обновить до/ })).toBeEnabled();
});

test('новая версия просит войти (401) → окно не замирает, говорит «войти заново»', async ({ app }) => {
  await setup(app, (n) => n < 1 ? { running: true, log: 'Скачиваю…', exit: null, version: '1.12.0' } : { __status: 401, body: { error: 'auth' } });
  await expect(app.page.getByText('Нужно войти заново')).toBeVisible({ timeout: 30_000 });
});

test('установка не началась (журнал пуст 20 с) → «Обновление не запустилось» и команда для консоли', async ({ app }) => {
  test.setTimeout(90_000);
  await setup(app, () => ({ running: false, log: '', exit: null, version: '1.12.0' }));
  await expect(dlg(app)).toContainText('Обновление не запустилось', { timeout: 45_000 });
  await expect(dlg(app)).toContainText('nfqws-ui update');
});
