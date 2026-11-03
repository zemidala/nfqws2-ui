// «Перевести на обучение» (NFQ-L222): circular → learn точечной правкой текста, модуль в папке lua, --lua-init,
// перезапуск с проверкой. Меняет конфиг и перезапускает nfqws2 — ТОЛЬКО на стенде.
import { test, expect, ssh } from '../fixtures.mjs';
import { TARGETS } from '../playwright.config.mjs';

test('профиль с circular → «Перевести на обучение» → learn, модуль, --lua-init, перезапуск', async ({ app, target }) => {
  test.skip(target !== 'stand', 'меняет конфиг — только на стенде');
  test.setTimeout(180_000);
  const conf = TARGETS[target].conf;
  const st = await app.api('state');
  test.skip(['checking', 'waiting'].includes(st.pending?.state), 'на стенде уже ждёт подтверждения другой перезапуск');

  // a profile of our own with circular, the module not yet in the lua folder
  const circular = ['--name=Перебор', '--filter-tcp=443', '--filter-l7=tls', '--hostlist-domains=example.com', '--payload=tls_client_hello',
    '--lua-desync=circular:fails=2:time=300', '--lua-desync=pass:strategy=1', '--lua-desync=fake:blob=0x00000000:repeats=1:strategy=2'];
  const { vars } = await app.api('conf_get');
  const custom = vars.NFQWS_ARGS_CUSTOM.trim();
  const saved = await app.api('conf_save_vars', { vars: { NFQWS_ARGS_CUSTOM: circular.join('\n') + (custom ? '\n--new\n' + custom : '') }, note: 'проверка: профиль с circular' });
  expect(saved.ok).toBe(true);
  ssh(target, 'rm -f /opt/etc/nfqws2/lua/nfqws-ui.lua');

  await app.open('#/settings/p1');
  const conv = app.page.locator('main').getByRole('button', { name: 'Перевести на обучение' });
  await expect(conv).toBeVisible();
  // layout, measured: on a phone nothing goes sideways, the button is as tall as the other small buttons
  for (const w of [390, 1366]) {
    await app.page.setViewportSize({ width: w, height: 900 });
    await app.page.waitForTimeout(200);
    expect(await app.page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
    const box = await conv.boundingBox();
    expect(box.x + box.width).toBeLessThanOrEqual(w);
    const small = await app.page.locator('main button.small:not(.icon)').evaluateAll((b) => b.map((x) => x.getBoundingClientRect().height).filter(Boolean));
    expect(Math.abs(box.height - Math.min(...small))).toBeLessThanOrEqual(1);
  }
  await conv.click();
  await app.yes();
  const pending = app.page.locator('#pending');
  await expect(pending).toContainText('Всё работает', { timeout: 90_000 });
  await pending.getByRole('button', { name: /Всё работает/ }).click();

  const text = ssh(target, `cat ${conf}`);
  expect(text).toContain('--lua-desync=learn:fails=2:time=300');
  expect(text).not.toContain('--lua-desync=circular:fails=2:time=300');
  expect(text).toMatch(/--payload=all\s+--in-range=-s34228\s+--lua-desync=learn:fails=2:time=300\s+--payload=tls_client_hello/);
  expect(text).toMatch(/--lua-init=@\S*\/nfqws-ui\.lua/);
  expect(ssh(target, 'ls /opt/etc/nfqws2/lua/nfqws-ui.lua')).toContain('nfqws-ui.lua');
  await expect.poll(async () => (await app.api('state')).running, { timeout: 30_000 }).toBe(true);
  // the editor now shows learn, not the button
  await app.open('#/settings/p1');
  await expect(app.page.locator('main')).toContainText('learn запоминает');
  await expect(app.page.locator('main').getByRole('button', { name: 'Перевести на обучение' })).toHaveCount(0);

  // the test profile goes away again
  const after = (await app.api('conf_get')).vars.NFQWS_ARGS_CUSTOM;
  const parts = after.split(/\n--new\n/);
  expect(parts[0]).toContain('--name=Перебор');
  expect((await app.api('conf_save_vars', { vars: { NFQWS_ARGS_CUSTOM: parts.slice(1).join('\n--new\n') }, note: 'проверка: профиль с circular убран' })).ok).toBe(true);
});
