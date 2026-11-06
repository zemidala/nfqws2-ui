// Style Z on pages with their results open: blocks that exist only after a check has run. Runs real, harmless checks.
import { test, expect } from '../fixtures.mjs';
import { signs, tightButtons, rows } from '../z-checks.mjs';

test('Z: результаты проверок', async ({ app, page }) => {
  test.setTimeout(300_000);
  await page.setViewportSize({ width: 1366, height: 900 });
  const all = new Set();
  const scan = async (where) => {
    for (const fn of [signs, tightButtons, rows]) for (const x of await page.evaluate(fn)) all.add(`${where}: ${x}`);
  };
  await app.open('#/diag?host=ya.ru', { variant: 'z', mode: 'dark' });
  await expect(page.locator('main').getByRole('button', { name: 'Ещё раз' })).toBeVisible({ timeout: 90_000 });
  await scan('диагноз');
  await app.open('#/tests/dns', { variant: 'z', mode: 'dark' });
  await page.locator('main').getByRole('button', { name: 'Проверить' }).first().click();
  await expect(page.locator('main .dns-tile').first()).toBeVisible({ timeout: 60_000 });
  await page.waitForTimeout(20_000);
  await scan('DNS');
  for (const [where, hash] of [['подборы', '#/tests/history'], ['снимки', '#/settings/backup'], ['журнал', '#/log'], ['устройства', '#/devices'], ['обучение', '#/tests/learn'], ['изменения', '#/settings/history'], ['отчёт', '#/settings/report']]) {
    await app.open(hash, { variant: 'z', mode: 'dark' });
    await page.waitForTimeout(1000);
    await scan(where);
  }
  console.log(`RESULTS: ${all.size}\n${[...all].join('\n')}`);
  expect([...all]).toEqual([]);
});
