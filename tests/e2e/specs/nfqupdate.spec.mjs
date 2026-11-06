// Обновление nfqws2 (пакет nfqws2-keenetic) — целиком на подмене: роутер «сообщает» о версии 9.9.9,
// установка, журнал и итог — подменённые. nfq_update_run и nfq_update_check до роутера не доходят никогда.
import { test, expect } from '../fixtures.mjs';

// a poll answered through the router can still be in flight when the test ends — let it go quietly
test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

const NEW = '9.9.9';
const OLD = '1.3.1';
const RESULT_OK = { from: OLD, to: NEW, rollback: false, ts: 0, running: true, snap: 'x', checks: [{ host: 'a.example', ok: true, was_ok: 1 }, { host: 'b.example', ok: true, was_ok: 1 }], worse: [], leftovers: 2 };
const RESULT_WORSE = { ...RESULT_OK, checks: [{ host: 'a.example', ok: true, was_ok: 1 }, { host: 'b.example', ok: false, was_ok: 1, reason: 'тайм-аут' }], worse: ['b.example'], leftovers: 0 };

const info = (over = {}) => ({ current: OLD, latest: NEW, available: true, notes: '- проверочный выпуск', url: 'https://example.invalid', date: 1792540000,
  checked: Math.floor(Date.now() / 1000), error: null, installed: true, running: false, result: null, rollback: null, ...over });

// status(n) — что отвечать на n-й опрос nfq_update_status после запуска
async function setup(app, status, { width } = {}) {
  let started = false, polls = 0;
  if (width) await app.page.setViewportSize({ width, height: 900 });
  await app.page.route('**/api.php*', async (route) => {
    let b = {};
    try { b = JSON.parse(route.request().postData() || '{}'); } catch { /* не JSON */ }
    if (b.cmd === 'state') {
      const res = await route.fetch().catch(() => null);   // тест мог уже закончиться
      if (!res) return;
      const j = await res.json();
      j.nfq_update = { latest: NEW, available: !started, running: false, rollback: started ? OLD : null };
      return route.fulfill({ response: res, json: j });
    }
    if (b.cmd === 'nfq_update_check') return route.fulfill({ json: info() });
    if (b.cmd === 'nfq_update_run') { started = true; return route.fulfill({ json: { ok: true } }); }
    if (b.cmd === 'nfq_update_status') {
      if (!started) return route.fulfill({ json: { ...info(), log: '', exit: null, job: null } });
      return route.fulfill({ json: status(polls++) });
    }
    return route.continue();
  });
  await app.open('#/');
}
const dlg = (app) => app.page.locator('.modal').last();

test('метка «↑ 9.9.9» в верхней панели открывает окно с описанием выпуска', async ({ app }) => {
  await setup(app, () => ({}));
  const up = app.page.locator('#nfq-up');
  await expect(up).toBeVisible();
  await expect(up).toContainText(`↑ ${NEW}`);
  await up.click();
  await expect(dlg(app)).toContainText(`Установлено: ${OLD}`);
  await expect(dlg(app)).toContainText('проверочный выпуск');
  await expect(dlg(app).getByRole('button', { name: `Обновить до ${NEW}` })).toBeVisible();
});

test('на телефоне — значок без текста, окно открывается', async ({ app }) => {
  await setup(app, () => ({}), { width: 390 });
  const up = app.page.locator('#nfq-up');
  await expect(up).toBeVisible();
  await expect(up.locator('.t')).toBeHidden();
  await up.click();
  await expect(dlg(app)).toContainText(`Установлено: ${OLD}`);
});

test('обновление прошло → «Готово», ссылка на файлы -opkg, кнопка «Вернуть»', async ({ app }) => {
  await setup(app, (n) => n < 1 ? { ...info(), running: true, log: 'Скачиваю…', exit: null }
    : { ...info({ current: NEW, available: false, rollback: OLD, result: RESULT_OK }), log: 'Скачиваю…\n✓ a.example\n[exit 0]', exit: 0, job: { done: 1 } });
  await app.page.locator('#nfq-up').click();
  await dlg(app).getByRole('button', { name: `Обновить до ${NEW}` }).click();
  await app.yes();   // «Обновить nfqws2 до …?»
  await expect(dlg(app)).toContainText(`Готово: nfqws2 ${NEW} работает`, { timeout: 30_000 });
  await expect(dlg(app)).toContainText('открываются 2 из 2');
  await expect(dlg(app).getByText('Посмотреть и сравнить')).toBeVisible();
  await expect(dlg(app).getByRole('button', { name: `Вернуть ${OLD}` })).toBeVisible();
});

test('после обновления сайт перестал открываться → предупреждение и «Вернуть»', async ({ app }) => {
  await setup(app, () => ({ ...info({ current: NEW, available: false, rollback: OLD, result: RESULT_WORSE }), log: '✗ b.example\n[exit 0]', exit: 0, job: { done: 1 } }));
  await app.page.locator('#nfq-up').click();
  await dlg(app).getByRole('button', { name: `Обновить до ${NEW}` }).click();
  await app.yes();   // «Обновить nfqws2 до …?»
  await expect(dlg(app)).toContainText('часть сайтов перестала открываться', { timeout: 30_000 });
  await expect(dlg(app)).toContainText('b.example');
  await expect(dlg(app).getByRole('button', { name: `Вернуть ${OLD}` })).toBeVisible();
});

test('установка упала → «Не получилось» с причиной', async ({ app }) => {
  await setup(app, () => ({ ...info(), log: 'ОШИБКА: сумма файла не сходится\n[exit 1]', exit: 1, job: { done: 1, error: 'сумма файла не сходится с выпуском на GitHub' } }));
  await app.page.locator('#nfq-up').click();
  await dlg(app).getByRole('button', { name: `Обновить до ${NEW}` }).click();
  await app.yes();   // «Обновить nfqws2 до …?»
  await expect(dlg(app)).toContainText('Не получилось', { timeout: 30_000 });
  await expect(dlg(app)).toContainText('сумма файла не сходится');
});

for (const width of [390, 1366]) {
  test(`журнал растёт, а окно и «Копировать» стоят на месте, ${width}px`, async ({ app }) => {
    test.setTimeout(60_000);
    const line = (i) => `строка журнала ${i}`;
    await setup(app, (n) => ({ ...info(), running: n < 6, exit: null, job: null, log: Array.from({ length: (n + 1) * 4 }, (_, i) => line(i)).join('\n') + '\n' }), { width });
    await app.page.locator('#nfq-up').click();
    await dlg(app).getByRole('button', { name: `Обновить до ${NEW}` }).click();
    await app.yes();
    const log = dlg(app).locator('pre.box');
    await expect(log).toBeVisible();
    await expect(dlg(app)).not.toContainText('проверочный выпуск');
    const place = () => dlg(app).locator('.copybox > .btn').evaluate((el) => { const r = el.getBoundingClientRect(); return [r.left, r.top]; });
    const was = await place();
    await expect(log).toContainText(line(23), { timeout: 20_000 });
    expect(await place()).toEqual(was);
    const box = await log.boundingBox();
    expect(box.y + box.height).toBeLessThanOrEqual(await dlg(app).locator('.modal-b').evaluate((el) => el.getBoundingClientRect().bottom));
  });
}

test('сообщение о неудаче не пропадает после обновления окна', async ({ app }) => {
  await setup(app, () => ({ ...info(), running: false, exit: 1, job: { error: 'проверочная причина' }, log: 'строка\n' }));
  await app.page.locator('#nfq-up').click();
  await dlg(app).getByRole('button', { name: `Обновить до ${NEW}` }).click();
  await app.yes();
  await expect(dlg(app)).toContainText('проверочная причина');
  await app.page.waitForTimeout(3000);
  await expect(dlg(app)).toContainText('проверочная причина');
});

test('пункт в «Проблемах» «Вышла nfqws2 9.9.9» открывает то же окно', async({ app }) => {
  await setup(app, () => ({}));
  const all = app.page.getByRole('button', { name: /Показать все/ }).first();
  if (await all.isVisible().catch(() => false)) await all.click();
  const item = app.page.locator('.problem', { hasText: `Вышла nfqws2 ${NEW}` }).first();
  await expect(item).toBeVisible();
  await item.getByRole('button', { name: 'Обновить…' }).click();
  await expect(dlg(app)).toContainText(`Установлено: ${OLD}`);
});
