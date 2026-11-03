// NFQ-L223: the service state reads «Перезапуск» while a restart runs; «Только свои» on «DNS-серверы» and
// «Обрыв на 16 КБ»; an own pause in «Автоподбор». Changing commands are mocked — nothing restarts or is saved.
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

test('во время перезапуска состояние — «Перезапуск», после — снова «Работает»', async ({ app, page }) => {
  await page.setViewportSize({ width: 1366, height: 900 });
  let release;
  const held = new Promise((r) => { release = r; });
  // the restart never reaches the router: held, then refused
  await app.mock(async (b) => { if (b.cmd === 'safe_restart') { await held; return { __status: 500, body: { error: 'проверка' } }; } });
  await app.open('#/', { variant: 'k' });
  const pill = page.locator('#svc');
  await expect(pill).toHaveText('работает');
  await page.locator('#svc-ctl').getByRole('button', { name: 'Перезапустить' }).click();
  await expect(pill).toHaveText('перезапуск');
  await expect(pill).toHaveClass(/\bwarn\b/);
  const side = page.locator('.svc-state').first();
  if (await side.count()) await expect(side).toHaveText('Перезапуск');
  release();
  await expect(pill).toHaveText('работает');
  await expect(pill).toHaveClass(/\bok\b/);
});

for (const w of [390, 1366]) {
  test(`«DNS-серверы»: «Только свои» уходит с запуском, ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    const sent = [];
    await app.mock((b) => {
      if (b.cmd === 'dns_start') { sent.push(b); return { running: false, servers: [], sites: [] }; }
      if (b.cmd === 'dns_get') return { servers: [], sites: [], own_servers: [], own_only: false };
      if (b.cmd === 'dnsx_get') return {};
    });
    await app.open('#/tests/dns', { variant: 'k' });
    const only = page.getByRole('checkbox', { name: /Только свои/ });
    await expect(only).not.toBeChecked();
    await only.check();
    await page.getByRole('textbox', { name: 'Свои DNS-серверы' }).fill('9.9.9.9');
    await page.locator('main').getByRole('button', { name: 'Проверить' }).first().click();
    await expect.poll(() => sent.length).toBe(1);
    expect(sent[0]).toMatchObject({ own: ['9.9.9.9'], only: true });
    expect(await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs'] })).toEqual([]);
  });

  test(`«Обрыв на 16 КБ»: «Только свои» уходит с запуском, ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    const sent = [];
    await app.mock((b) => {
      if (b.cmd === 'freeze_start') { sent.push(b); return { ok: true }; }
      if (b.cmd === 'freeze_get') return { state: 'idle', type: 'freeze' };
    });
    await app.open('#/tests/freeze', { variant: 'k' });
    await page.getByRole('textbox', { name: 'Свои сайты и адреса' }).fill('203.0.113.5:8443');
    await page.getByRole('checkbox', { name: /Только свои/ }).check();
    await page.getByRole('button', { name: 'Запустить пробу' }).click();
    await expect.poll(() => sent.length).toBe(1);
    expect(sent[0]).toMatchObject({ hosts: ['203.0.113.5:8443'], only: true });
    expect(await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs'] })).toEqual([]);
  });

  test(`«Автоподбор»: своя пауза в часах, от 1 до 168, ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    const sent = [];
    await app.mock((b) => { if (b.cmd === 'auto_set') { sent.push(b.pause); return { ok: true }; } });
    await app.open('#/tests/auto', { variant: 'k' });
    await page.locator('main .dd-btn[aria-label="Пауза между попытками"]').click();
    await page.locator('.dd-pop').getByRole('option', { name: 'свой…' }).click();
    const num = page.getByRole('spinbutton', { name: 'Своя пауза, часов' });
    await expect(num).toBeVisible();
    await num.fill('500');
    await num.press('Tab');
    await expect.poll(() => sent.at(-1)).toBe(168);
    // the row: the select, the number and «ч» on one line, same height as the select
    const [sel, n] = await Promise.all([page.locator('main .dd-btn[aria-label="Пауза между попытками"]').boundingBox(), num.boundingBox()]);
    expect(Math.abs(sel.height - n.height)).toBeLessThanOrEqual(1);
    expect(Math.abs(sel.y - n.y)).toBeLessThanOrEqual(1);
    expect(await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs'] })).toEqual([]);
  });
}
