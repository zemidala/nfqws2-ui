// «Обрыв на 16 КБ»: найденная стратегия уже в конфиге (NFQ-L128, идея Denis #541). Итог пробы берётся настоящий
// (прошлая проба на роутере), второй случай — подменой in_conf. Проба не запускается, конфиг не трогается.
import { test, expect } from '../fixtures.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

// без итога пробы с находками (например, на стенде, где пробу не запускали) проверять нечего — пропуск до перехвата:
// test.skip внутри обработчика запроса тест не останавливает
test.beforeEach(async ({ app }) => {
  const j = await app.api('freeze_get');
  const st = j.data || j;
  test.skip(!st?.nets?.some((n) => n.found), 'на устройстве нет итога пробы с находками');
});

async function freezeRoute(page, edit) {
  await page.route('**/api.php*', async (route) => {
    let body = {};
    try { body = JSON.parse(route.request().postData() || '{}'); } catch { /* не JSON */ }
    if (body.cmd !== 'freeze_get') return route.continue();
    const res = await route.fetch();
    const j = await res.json();
    const st = j.data || j;
    if (!st.nets?.some((n) => n.found)) test.skip(true, 'на устройстве нет итога пробы с находками');
    if (edit) edit(st);
    return route.fulfill({ response: res, json: j });
  });
}

for (const w of [390, 1366]) {
  test(`уже работает — кнопки нет, ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    let net = null;
    await freezeRoute(page, (st) => {
      net = st.nets.find((n) => n.found && !n.found.via);
      st.nets = [net];
      net.in_conf = { profiles: [1], route: 1, why: '' };
    });
    await app.open('#/tests/freeze', { variant: 'k' });
    const row = page.locator('.frz-row').first();
    await expect(row).toContainText('Уже работает');
    await expect(row.locator('.btn', { hasText: 'Применить для сети' })).toHaveCount(0);
    // the probe's notice by its text: the device may also show «Нужен перезапуск» left by other scenarios
    await expect(page.locator('main .notice', { hasText: 'Обрыв есть' })).toContainText('применять ничего не нужно');
    await page.screenshot({ path: test.info().outputPath('works.png'), fullPage: true });
  });

  test(`есть в профиле, но адрес не попадает — кнопка есть, ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    await freezeRoute(page, (st) => {
      const net = st.nets.find((n) => n.found && !n.found.via);
      st.nets = [net];
      net.in_conf = { profiles: [1], route: null, why: 'IP 1.2.3.4 в исключениях (ipset_nc.list)' };
    });
    await app.open('#/tests/freeze', { variant: 'a' });
    const row = page.locator('.frz-row').first();
    await expect(row).toContainText('уже есть в профиле #1');
    await expect(row).toContainText('в исключениях');
    await expect(row.locator('.btn', { hasText: 'Применить для сети' })).toHaveCount(1);
    await page.screenshot({ path: test.info().outputPath('notreach.png'), fullPage: true });
  });

  // NFQ-L133 (идея Denis #556): та же стратегия в профиле по IP-спискам — сеть вливается в него строкой --ipset.
  // freeze_join и перезапуск подменены: на роутере ничего не меняется.
  test(`можно добавить в профиль — две кнопки, ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    let net = null;
    await freezeRoute(page, (st) => {
      net = st.nets.find((n) => n.found && !n.found.via);
      st.nets = [net];
      net.in_conf = { profiles: [1], route: null, why: 'IP не входит в IP-списки профиля', join: 1 };
    });
    const sent = [];
    await page.route('**/api.php*', async (route) => {
      let body = {};
      try { body = JSON.parse(route.request().postData() || '{}'); } catch { /* не JSON */ }
      if (body.cmd === 'freeze_join') { sent.push(body); return route.fulfill({ json: { list: `ipset_as${body.asn}.list`, count: 10, profile: body.profile } }); }
      if (body.cmd === 'safe_restart') return route.fulfill({ json: { ok: true, output: '' } });
      return route.fallback();
    });
    await app.open('#/tests/freeze', { variant: 'k' });
    const row = page.locator('.frz-row').first();
    await expect(row).toContainText('можно добавить в него');
    await expect(row.locator('.btn', { hasText: 'В профиль #1' })).toHaveCount(1);
    await expect(row.locator('.btn', { hasText: 'Отдельным профилем' })).toHaveCount(1);
    await expect(row.locator('.btn', { hasText: 'Применить для сети' })).toHaveCount(0);
    await page.screenshot({ path: test.info().outputPath('join.png'), fullPage: true });
    page.once('dialog', (d) => d.accept());
    await row.locator('.btn', { hasText: 'В профиль #1' }).click();
    await expect.poll(() => sent.length).toBe(1);
    expect(sent[0]).toMatchObject({ asn: net.asn, profile: 1 });
  });
}

test('настоящий итог роутера отрисовывается и не пропадает', async ({ app, page }) => {
  await app.open('#/tests/freeze', { variant: 'k' });
  await expect(page.locator('.frz-row').first()).toBeVisible();
  await page.waitForTimeout(8000);   // страница перерисовывается по обновлению состояния — итог должен остаться
  await expect(page.locator('.frz-row').first()).toBeVisible();
  await page.screenshot({ path: test.info().outputPath('real.png') });
});
