// NFQ-L230: «После перезапуска проверять сайты» in «Основное» — off, «Перезапустить» is a plain restart.
// NFQ-L231: «и из профилей для других портов» in «Подбор стратегии» — goes with the start as the «ports» set.
// Changing commands are mocked: nothing restarts, the settings and the config stay.
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

for (const w of [390, 1366]) {
  test(`«Основное»: без проверки «Перезапустить» — обычный перезапуск, ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    const sent = [];
    await app.mock((b) => {
      if (b.cmd === 'settings_set') { sent.push(b); return { ok: true }; }
      if (b.cmd === 'service' || b.cmd === 'safe_restart') { sent.push(b); return { ok: true, output: '' }; }
    });
    await app.open('#/settings/basic', { variant: 'k' });
    const box = page.locator('#f-restart-check');
    await expect(box).toBeChecked();   // on by default
    await box.uncheck();
    await expect.poll(() => sent.find((x) => x.cmd === 'settings_set')).toMatchObject({ restart_check: false });
    await expect(page.locator('main')).toContainText('Отката нет');
    expect(await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs'] })).toEqual([]);
    if (w > 760) {
      const btn = page.locator('#svc-ctl').getByRole('button', { name: 'Перезапустить' });
      await expect(btn).toHaveAttribute('title', /без проверки/);
      await btn.click();
      await expect.poll(() => sent.some((x) => x.cmd === 'service')).toBe(true);
      expect(sent.find((x) => x.cmd === 'service')).toMatchObject({ action: 'restart' });
      expect(sent.some((x) => x.cmd === 'safe_restart')).toBe(false);
    }
  });
}

const pickProto = async (page, name) => {
  await page.locator('main .dd-btn[aria-label="Протокол"]').click();
  await page.locator('.dd-pop').getByRole('option', { name }).click();
};

test('«Подбор»: «и из профилей для других портов» уходит в оба режима, без конфига недоступна, для QUIC скрыта', async ({ app, page }) => {
  await page.setViewportSize({ width: 1366, height: 900 });
  const sent = [];
  await app.mock((b) => { if (b.cmd === 'test_start' || b.cmd === 'multi_start') { sent.push(b); return { ok: true }; } });
  await app.open('#/tests/pick', { variant: 'k' });
  const ports = page.locator('#t-ports');
  await expect(ports).toBeVisible();
  await expect(ports).not.toBeChecked();
  await page.locator('#t-cfg').uncheck();
  await expect(ports).toBeDisabled();
  await page.locator('#t-cfg').check();
  await expect(ports).toBeEnabled();
  await pickProto(page, /QUIC/);
  await expect(ports).toBeHidden();
  await pickProto(page, /HTTPS/);
  await ports.check();
  await page.locator('#t-host').fill('rutracker.org');
  await page.getByRole('button', { name: 'Запустить тест' }).click();
  await expect.poll(() => sent.length).toBe(1);
  expect(sent[0].sets).toEqual(expect.arrayContaining(['config', 'ports']));
  await page.getByRole('radio', { name: 'Несколько сайтов или список' }).click();
  await page.locator('textarea[aria-label="Сайты, по одному в строке"]').fill('rutracker.org\nnnmclub.to');
  await page.getByRole('button', { name: 'Запустить тест' }).click();
  await expect.poll(() => sent.length).toBe(2);
  expect(sent[1].sets).toEqual(expect.arrayContaining(['config', 'ports']));
  expect(await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs'] })).toEqual([]);
});
