// Нет модуля curl в PHP (php8-mod-curl): страница называет причину, а не падает на «reading 'filter'».
// Живая часть — только когда модуль на устройстве действительно выключен (PW_PHPCURL_OFF=1).
import { test, expect } from '../fixtures.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

test('пункт «В PHP нет модуля curl» в «Проблемах»', async ({ app }) => {
  await app.page.route('**/api.php*', async (route) => {
    let b = {};
    try { b = JSON.parse(route.request().postData() || '{}'); } catch { /* не JSON */ }
    if (b.cmd !== 'state') return route.continue();
    const res = await route.fetch();
    const j = await res.json();
    j.php_curl = "PHP Warning: PHP Startup: Unable to load dynamic library 'curl.so'";
    return route.fulfill({ response: res, json: j });
  });
  await app.open('#/');
  const item = app.page.locator('main').getByText('В PHP нет модуля curl').first();
  await expect(item).toBeVisible();
  await expect(app.page.getByRole('button', { name: 'Установить модуль curl' }).first()).toBeVisible();
});

test('диагноз без модуля curl называет причину', async ({ app }) => {
  test.skip(!process.env.PW_PHPCURL_OFF, 'модуль curl на устройстве включён');
  await app.open('#/diag');
  await app.page.fill('#d-host', 'rutracker.org');
  await app.page.locator('main').getByRole('button', { name: /^Проверить/ }).click();
  const n = app.page.locator('main .notice').filter({ hasText: /./ }).first();
  await expect(n).toContainText('В PHP нет модуля curl', { timeout: 30_000 });
  await expect(n).not.toContainText('filter');
});

// PHP старее модуля (undefined symbol): кнопка обновляет PHP, а не переставляет модуль. Запрос подменён — ничего не ставится
for (const w of [390, 1366]) {
  test(`пункт «PHP старее модуля curl» и кнопка «Обновить PHP», ${w}px`, async ({ app }) => {
    await app.page.setViewportSize({ width: w, height: 900 });
    const sent = [];
    await app.page.route('**/api.php*', async (route) => {
      let b = {};
      try { b = JSON.parse(route.request().postData() || '{}'); } catch { /* не JSON */ }
      if (b.cmd === 'php_curl_install') { sent.push(b); return route.fulfill({ json: { ok: false, error: 'в источнике пакетов нет более новой версии PHP', log: '', code: 3 } }); }
      if (b.cmd !== 'state') return route.continue();
      const res = await route.fetch();
      const j = await res.json();
      j.php_curl = "PHP Warning: PHP Startup: Unable to load dynamic library 'curl.so' (tried: /opt/lib/php8/curl.so (/opt/lib/php8/curl.so: undefined symbol: zend_register_internal_class_with_flags))";
      j.php_upgrade = "opkg update >/dev/null 2>&1; P=$(opkg list-upgradable | awk '/^(php8|libpcre2|libxml2)/{print $1}'); opkg upgrade $P";
      return route.fulfill({ response: res, json: j });
    });
    await app.open('#/');
    await expect(app.page.locator('main').getByText('PHP старее модуля curl').first()).toBeVisible();
    await expect(app.page.locator('main').getByText('В PHP нет модуля curl')).toHaveCount(0);
    const b = app.page.getByRole('button', { name: 'Обновить PHP' }).first();
    await expect(b).toBeVisible();
    await app.page.screenshot({ path: test.info().outputPath('old.png'), fullPage: true });
    await b.click();
    await expect.poll(() => sent.length).toBe(1);
    await expect(app.page.locator('.toast.err')).toContainText('нет более новой версии PHP');
  });
}
