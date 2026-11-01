// Окна, которые раньше проверялись только запросами: «Для чата», «Вставить чужой», поиск сети на странице ASN.
// Ничего не сохраняется: «Добавить профилем» и «Создать список» не нажимаются.
import { test, expect } from '../fixtures.mjs';

const modal = (app) => app.page.locator('.modal').last();

test('«Для чата»: текст профиля с подписью', async ({ app }) => {
  await app.open('#/settings/p1');
  await app.page.locator('main').getByRole('button', { name: 'Для чата' }).click();
  await expect(modal(app)).toContainText('для чата');
  await expect(modal(app).locator('textarea')).toHaveValue(/^# nfqws2 v[\d.]+ · .+\n--/);
});

test('«Вставить чужой»: профиль из конфига z2k разбирается, пути поправлены, nfqws2 принимает', async ({ app, target }) => {
  await app.open('#/settings');
  await app.page.locator('main').getByRole('button', { name: /Вставить чужой/ }).click();
  await modal(app).locator('textarea').fill('# из чата\nNFQWS_ARGS="--filter-tcp=443 --filter-l7=tls --hostlist=/opt/zapret2/lists/user.list \\\n'
    + '--lua-desync=tls_client_hello_clone:blob=cl:fallback=fake_default_tls --lua-desync=fake:blob=cl:tcp_md5:repeats=6"');
  await modal(app).getByRole('button', { name: 'Разобрать и проверить' }).click();
  await expect(modal(app)).toContainText('nfqws2 принимает этот профиль');
  await expect(modal(app)).toContainText('Пути к спискам поправлены');
  await expect(modal(app).locator('pre')).toContainText(target === 'stand' ? '/opt/etc/nfqws2/lists/user.list' : '/etc/nfqws2/lists/user.list');
  await expect(modal(app).getByRole('button', { name: 'Добавить профилем' })).toBeVisible();
});

test('ASN: поиск сети по номеру показывает префиксы', async ({ app }) => {
  await app.open('#/asn');
  await app.page.locator('main input[aria-label="Номер AS или сайт"]').fill('AS24940');
  await app.page.locator('main form').getByRole('button', { name: /Найти/ }).click();
  await expect(app.page.locator('main')).toContainText('AS24940 ·', { timeout: 45_000 });
  await expect(app.page.locator('main')).toContainText(/\d+ префикс/);
});
