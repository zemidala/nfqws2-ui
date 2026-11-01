// Общее для сценариев: временная сессия на роутере (по ssh), страница с cookie и выбранным оформлением,
// сбор ошибок JS. Сессия создаётся на время прогона и удаляется — пароль не нужен и нигде не хранится.
import { test as base, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { TARGETS } from './playwright.config.mjs';

export { expect };

// Команда на роутере; вывод без предупреждений ssh
export function ssh(target, cmd) {
  return execFileSync('ssh', [...TARGETS[target].ssh, cmd], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], timeout: 120_000 });
}

export const test = base.extend({
  target: ['router', { option: true, scope: 'worker' }],

  // одна сессия на прогон (воркер один)
  sid: [async ({ target }, use) => {
    const sid = 'e2e' + randomBytes(12).toString('hex');
    ssh(target, `mkdir -p /tmp/nfqws-ui-sess && printf 'auth|b:1;' > /tmp/nfqws-ui-sess/sess_${sid} && chmod 600 /tmp/nfqws-ui-sess/sess_${sid}`);
    await use(sid);
    ssh(target, `rm -f /tmp/nfqws-ui-sess/sess_${sid}`);
  }, { scope: 'worker' }],

  // ошибки JS на странице — сценарий падает, если они были
  errors: async ({ page }, use) => {
    const list = [];
    page.on('pageerror', (e) => list.push(String(e.message || e)));
    page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) list.push(m.text()); });
    await use(list);
    expect(list, 'ошибки JS на странице').toEqual([]);
  },

  // открыть страницу интерфейса: app.open('#/diag', { layout: 'tabs', variant: 'a' })
  app: async ({ page, context, sid, baseURL, errors }, use) => {
    await context.addCookies([{ name: 'PHPSESSID', value: sid, url: baseURL }]);
    // шрифты Google: сценарии не зависят от интернета (2026-10-23 fonts.gstatic.com висел ~20 с, и каждый тест шёл 40 с)
    await context.route(/^https:\/\/fonts\.(googleapis|gstatic)\.com\//, (r) => r.abort());
    const app = {
      page,
      errors,
      async open(hash = '#/', look = {}) {
        await page.addInitScript((lk) => {
          try {
            localStorage.setItem('nfqws-ui-look', JSON.stringify(lk));
            localStorage.setItem('nfqws-ui-layout-note', '1');   // подсказка «вид изменился» не нужна
          } catch { /* нет хранилища */ }
        }, look);
        await page.goto(baseURL + hash);
        await expect(page.locator('main h1').first()).toBeVisible();
      },
      // подменить ответы роутера: fn(запрос) возвращает объект-ответ или undefined (тогда идёт на роутер)
      async mock(fn) {
        await page.route('**/api.php*', async (route) => {
          let body = {};
          try { body = JSON.parse(route.request().postData() || '{}'); } catch { /* не JSON (загрузка файла) */ }
          const r = await fn(body);
          if (r === undefined) return route.continue();
          if (r && r.__status) return route.fulfill({ status: r.__status, contentType: 'application/json', body: JSON.stringify(r.body || {}) });
          return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(r) });
        });
      },
      // вызов API интерфейса из браузера (с той же сессией)
      // (запросом Playwright: cookie та же, открытая страница не нужна, подмены ответов на него не действуют)
      api: async (cmd, data = {}) => (await page.request.post(baseURL + 'api.php', { data: { cmd, ...data } })).json(),
    };
    await use(app);
  },
});
