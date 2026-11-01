// Подбор стратегии: запуск и остановка по-настоящему (несколько секунд, проверочная очередь — не основной nfqws2).
// «Остановить» — только кнопка в строке хода подбора: такая же кнопка в шапке останавливает сам nfqws2.
import { test, expect } from '../fixtures.mjs';

test('подбор запускается, показывает ход и останавливается', async ({ app }) => {
  const st0 = await app.api('test_status');
  test.skip(st0.state === 'running', 'на роутере уже идёт подбор — не мешаем');
  await app.open('#/tests/pick?host=rutracker.org');
  await expect(app.page.locator('main input[aria-label]').first()).toBeVisible();
  await app.page.locator('main').getByRole('button', { name: 'Запустить тест' }).click();
  const progress = app.page.locator('main .row', { hasText: /\d+ из \d+|запуск…/ }).filter({ has: app.page.getByRole('button', { name: 'Остановить' }) }).first();
  await expect(progress).toBeVisible({ timeout: 30_000 });
  await progress.getByRole('button', { name: 'Остановить' }).click();
  await expect.poll(async () => (await app.api('test_status')).state, { timeout: 60_000 }).not.toBe('running');
  // основной nfqws2 не тронут
  const s = await app.api('state');
  expect(s.running).toBe(true);
});
