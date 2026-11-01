// Сохранение → перезапуск с проверкой → откат. Меняет конфиг и перезапускает nfqws2, поэтому ТОЛЬКО на стенде.
// Итог сверяется побайтно: после отката конфиг тот же, nfqws2 работает.
import { test, expect, ssh } from '../fixtures.mjs';
import { TARGETS } from '../playwright.config.mjs';

test('свой профиль → перезапуск с проверкой → «Откатить сейчас» возвращает конфиг побайтно', async ({ app, target }) => {
  test.skip(target !== 'stand', 'меняет конфиг — только на стенде');
  test.setTimeout(180_000);
  const conf = TARGETS[target].conf;
  const md5 = () => ssh(target, `md5sum ${conf}`).split(' ')[0];
  const before = md5();
  const st = await app.api('state');
  test.skip(['checking', 'waiting'].includes(st.pending?.state), 'на стенде уже ждёт подтверждения другой перезапуск');

  await app.open('#/settings');
  await app.page.locator('main').getByRole('button', { name: /Свой профиль/ }).click();
  await expect.poll(md5, { message: 'конфиг сохранён' }).not.toBe(before);

  await app.page.getByRole('button', { name: 'Перезапустить с проверкой' }).filter({ visible: true }).first().click();
  const pending = app.page.locator('#pending');
  await expect(pending).toContainText('nfqws2 перезапущен', { timeout: 60_000 });
  await pending.getByRole('button', { name: 'Откатить сейчас' }).click();
  await expect(pending).toContainText('Изменения откачены', { timeout: 60_000 });
  await expect(pending).toContainText('nfqws2.conf');
  await pending.getByRole('button', { name: 'Понятно' }).click();
  await expect(pending).toBeEmpty();

  await expect.poll(md5, { message: 'конфиг вернулся', timeout: 30_000 }).toBe(before);
  await expect.poll(async () => (await app.api('state')).running, { timeout: 30_000 }).toBe(true);
});

// Two rounds in a row: a rollback is the working state again, so the second rollback must not bring back the config
// of the first, failed change (it did: the process start read a second before the restore entries in the history)
test('два перезапуска с откатом подряд — второй откат возвращает исходный конфиг, а не первую неудачную правку', async ({ app, target }) => {
  test.skip(target !== 'stand', 'меняет конфиг — только на стенде');
  test.setTimeout(300_000);
  const conf = TARGETS[target].conf;
  const md5 = () => ssh(target, `md5sum ${conf}`).split(' ')[0];
  const before = md5();
  const st = await app.api('state');
  test.skip(['checking', 'waiting'].includes(st.pending?.state), 'на стенде уже ждёт подтверждения другой перезапуск');
  const round = async () => {
    await app.open('#/settings');
    await app.page.locator('main').getByRole('button', { name: /Свой профиль/ }).click();
    await expect.poll(md5, { message: 'конфиг сохранён' }).not.toBe(before);
    await app.page.getByRole('button', { name: 'Перезапустить с проверкой' }).filter({ visible: true }).first().click();
    const pending = app.page.locator('#pending');
    await expect(pending).toContainText('nfqws2 перезапущен', { timeout: 60_000 });
    await pending.getByRole('button', { name: 'Откатить сейчас' }).click();
    await expect(pending).toContainText('Изменения откачены', { timeout: 60_000 });
    await pending.getByRole('button', { name: 'Понятно' }).click();
    await expect.poll(md5, { message: 'конфиг вернулся', timeout: 30_000 }).toBe(before);
  };
  await round();
  await round();
  await expect.poll(async () => (await app.api('state')).running, { timeout: 30_000 }).toBe(true);
});
