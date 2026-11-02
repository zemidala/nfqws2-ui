// Предложения после 1.29.0: свой интервал мониторинга, «Чужой конфиг» без своего для сравнения, предупреждения
// «Конфига целиком» выключаются в «Оформлении». Изменяющие команды подменены — настройки и конфиг не меняются.
import { test, expect } from '../fixtures.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

for (const w of [390, 1366]) {
  test(`«Мониторинг»: свой интервал в минутах и часах, ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    const sent = [];
    await app.mock((b) => { if (b.cmd === 'monitor_set') { sent.push(b.interval); return { ok: true }; } });
    await app.open('#/tests/monitor', { variant: 'k' });
    const sel = page.locator('main .dd-btn[aria-label="Как часто"]');
    await sel.click();
    await page.locator('.dd-pop').getByRole('option', { name: 'свой…' }).click();
    const num = page.getByRole('spinbutton', { name: 'Свой интервал' });
    await expect(num).toBeVisible();
    await num.fill('45');
    await expect.poll(() => sent.at(-1)).toBe(50);   // rounded to 10 minutes: the cron pass runs every 10
    await expect(num).toHaveValue('50');
    await page.locator('main .dd-btn[aria-label="Единица интервала"]').click();
    await page.locator('.dd-pop').getByRole('option', { name: 'ч' }).click();
    await num.fill('2');
    await expect.poll(() => sent.at(-1)).toBe(120);
    await page.screenshot({ path: test.info().outputPath(`mon-ivl-${w}.png`), clip: { x: 0, y: 0, width: w, height: 400 } });
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
  });
}

test('«Чужой конфиг»: галочка своего конфига стоит, снятая — уходит mine: false', async ({ app, page }) => {
  let sent = null;
  await app.mock((b) => { if (b.cmd === 'cfg_start') { sent = b; return { ok: true }; } });
  await app.open('#/tests/configs', { variant: 'k' });
  const mine = page.locator('#cfg-mine');
  await expect(mine).toBeChecked();
  await page.getByRole('button', { name: 'Текстом' }).click();
  await page.locator('.modal textarea').fill('NFQWS_ARGS="--filter-tcp=443 --lua-desync=fake:blob=fake_default_tls"\n');
  await page.locator('.modal .modal-f .btn').last().click();
  await mine.uncheck();
  await page.locator('main').getByRole('button', { name: 'Проверить' }).click();
  await expect.poll(() => sent).not.toBeNull();
  expect(sent.mine).toBe(false);
});

test('сервер: без своего конфига и без чужих — понятный отказ', async ({ app }) => {
  const r = await app.api('cfg_start', { configs: [], hosts: ['ya.ru'], mine: false });
  expect(JSON.stringify(r)).toContain('Добавьте хотя бы один конфиг');
});

test('«Оформление» → без предупреждений: в «Конфиге целиком» только ошибки и пометка', async ({ app, page }) => {
  const warn = { var: 'NFQWS_ARGS', tok: 0, level: 'warning', msg: 'проверочное предупреждение e2e' };
  const err = { var: 'NFQWS_ARGS', tok: 0, level: 'error', msg: 'проверочная ошибка e2e' };
  await page.route('**/api.php*', async (route) => {
    let body = {};
    try { body = JSON.parse(route.request().postData() || '{}'); } catch { /* not JSON */ }
    if (!['state', 'lint_raw'].includes(body.cmd)) return route.continue();
    const res = await route.fetch();
    const j = await res.json();
    const st = j.data || j;
    const lint = body.cmd === 'state' ? (st.lint ||= { issues: [] }) : st;
    lint.issues = [err, warn, ...(lint.issues || [])];
    return route.fulfill({ response: res, json: j });
  });
  await app.open('#/settings/raw', { variant: 'k' });
  await expect(page.locator('.iss-item', { hasText: 'проверочное предупреждение e2e' })).toBeVisible();
  await app.open('#/settings/look', { variant: 'k' });
  await page.locator('label.chk', { hasText: 'Предупреждения в «Конфиге целиком»' }).locator('input').uncheck();
  await app.page.goto(app.page.url().replace(/#.*/, '#/settings/raw'));
  await expect(page.locator('.iss-item', { hasText: 'проверочная ошибка e2e' })).toBeVisible();
  await expect(page.locator('.iss-item', { hasText: 'проверочное предупреждение e2e' })).toHaveCount(0);
  await expect(page.locator('main')).toContainText('предупреждения выключены');
});
