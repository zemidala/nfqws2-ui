// Имена профилей (NFQ-L60): --name=… и --new=… в конфиге, поле «Имя» в конструкторе.
// Меняет конфиг (без перезапуска nfqws2), поэтому ТОЛЬКО на стенде; в конце конфиг возвращается побайтно.
import { test, expect, ssh } from '../fixtures.mjs';
import { TARGETS } from '../playwright.config.mjs';

test('имена из --name и --new=имя видны, переименование пишется как --new + --name', async ({ app, target }) => {
  test.skip(target !== 'stand', 'меняет конфиг — только на стенде');
  const conf = TARGETS[target].conf;
  const md5 = () => ssh(target, `md5sum ${conf}`).split(' ')[0];
  test.skip(ssh(target, `grep -c '^NFQWS_ARGS_CUSTOM=' ${conf} || true`).trim() !== '0', 'на стенде уже есть свои профили');
  const before = md5();
  ssh(target, `cp ${conf} /tmp/names-spec.conf && printf '\\nNFQWS_ARGS_CUSTOM="--name=perviy --filter-tcp=443 --filter-l7=tls --lua-desync=multisplit:pos=1\\n--new=vtoroy\\n--filter-tcp=443 --filter-l7=tls --lua-desync=multisplit:pos=2"\\n' >> ${conf}`);
  try {
    await app.open('#/settings');
    const nav = app.page.locator('main');
    await expect(nav.getByText('perviy', { exact: true }).first()).toBeVisible();
    await expect(nav.getByText('vtoroy', { exact: true }).first()).toBeVisible();   // --new=имя — отдельный профиль, а не часть первого

    await app.open('#/settings/p2');
    await expect(app.page.locator('main h1', { hasText: '#2' })).toHaveText('#2 vtoroy');
    const name = app.page.locator('[data-fk="name"]');
    await expect(name).toHaveValue('vtoroy');

    await name.fill('a b');   // пробел сломал бы запуск — имя не принимается
    await expect(app.page.locator('.hint.error').filter({ hasText: 'Пробелы' })).toBeVisible();
    await expect(app.page.locator('.savebar')).toBeHidden();

    await name.fill('imya-2');
    await app.page.locator('.savebar').getByRole('button', { name: 'Сохранить' }).click();
    await expect.poll(() => ssh(target, `grep -A1 -x -- '--new' ${conf} || true`), { message: 'записано как --new + --name' }).toContain('--name=imya-2');
    expect(ssh(target, `grep -c -- '--new=vtoroy' ${conf} || true`).trim()).toBe('0');
  } finally {
    ssh(target, `cp /tmp/names-spec.conf ${conf} && rm -f /tmp/names-spec.conf`);
  }
  expect(md5()).toBe(before);
});
