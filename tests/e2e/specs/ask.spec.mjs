// Свои окна подтверждения и ввода вместо браузерных confirm() / prompt() (пожелание rangerxxy, #729 п. 18).
// Ничего не меняет на роутере: ответы на изменяющие команды подменены, «Остановить» отменяется.
import { test, expect } from '../fixtures.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

test('«Остановить» — своё окно: «Отмена» и Esc ничего не делают, кнопка — красная «Остановить»', async ({ app, page }) => {
  const sent = [];
  await app.mock((b) => { if (b.cmd === 'service') { sent.push(b); return { ok: true }; } });
  let native = 0;
  page.on('dialog', (d) => { native++; d.dismiss(); });
  await app.open('#/');
  const stop = page.locator('.svc-box').getByRole('button', { name: 'Остановить' });
  if (!await stop.isVisible().catch(() => false)) test.skip(true, 'nfqws2 не запущен');

  await stop.click();
  const m = page.locator('.modal.ask');
  await expect(m).toBeVisible();
  await expect(m.locator('h2')).toHaveText('Остановить nfqws2?');
  await expect(m).toContainText('Заблокированные сайты перестанут открываться.');
  const ok = m.locator('.modal-f .btn').last();
  await expect(ok).toHaveText('Остановить');
  await expect(ok).toHaveClass(/danger/);
  await expect(ok).toBeFocused();
  await m.getByRole('button', { name: 'Отмена' }).click();
  await expect(m).toHaveCount(0);

  await stop.click();
  await expect(m).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(m).toHaveCount(0);
  expect(sent).toEqual([]);

  await stop.click();
  await app.yes();
  await expect.poll(() => sent.length).toBe(1);
  expect(sent[0].action).toBe('stop');
  expect(native).toBe(0);
});

for (const w of [390, 1366]) {
  test(`«Новый список» — своё поле, Enter создаёт, пустое не уходит, ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    const sent = [];
    await app.mock((b) => { if (b.cmd === 'list_create') { sent.push(b); return { __status: 400, body: { error: 'проверка: не создаю' } }; } });
    await app.open('#/sites');
    await page.evaluate(() => { createList(); });   // not awaited: it waits for the window
    const m = page.locator('.modal.ask');
    await expect(m).toBeVisible();
    await expect(m.locator('h2')).toHaveText('Новый список');
    const input = m.locator('input.input');
    await expect(input).toBeFocused();
    await page.waitForTimeout(400);   // the window fades in
    // nothing covers the window: the point in the middle of each button belongs to the window
    const covered = await m.locator('.modal-f .btn, input.input').evaluateAll((els) => els.filter((el) => {
      const r = el.getBoundingClientRect();
      return !el.contains(document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2));
    }).length);
    expect(covered).toBe(0);
    await page.screenshot({ path: test.info().outputPath(`newlist-${w}.png`) });
    // window fits the screen and the buttons share one height
    const box = await m.boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(w);
    const hs = await m.locator('.modal-f .btn').evaluateAll((bs) => bs.map((b) => Math.round(b.getBoundingClientRect().height)));
    expect(new Set(hs).size).toBe(1);

    await page.keyboard.press('Enter');          // empty — stays open
    await expect(m).toBeVisible();
    expect(sent).toEqual([]);
    await input.fill('e2e_ask');
    await page.keyboard.press('Enter');
    await expect.poll(() => sent.length).toBe(1);
    expect(sent[0].name).toBe('e2e_ask');
    await expect(m).toHaveCount(0);
  });
}

test('уход со страницы с несохранёнными правками — своё окно; «Отмена» оставляет, «Уйти» уходит', async ({ app, page }) => {
  await app.open('#/settings/raw');
  const ta = page.locator('textarea.ed-ta').first();
  await ta.press('End');
  await ta.type(' ');
  const here = await page.evaluate(() => location.hash);
  await page.evaluate(() => { location.hash = '#/'; });
  const m = page.locator('.modal.ask');
  await expect(m.locator('h2')).toHaveText('Уйти без сохранения?');
  expect(await page.evaluate(() => location.hash)).toBe(here);
  await m.getByRole('button', { name: 'Отмена' }).click();
  expect(await page.evaluate(() => location.hash)).toBe(here);
  await page.evaluate(() => { location.hash = '#/'; });
  await app.yes();
  await expect.poll(() => page.evaluate(() => location.hash)).toBe('#/');
});
