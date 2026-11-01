// NFQ-L98: вид списка («Списком» / «Текстом») запоминается в браузере; Ctrl+/ в тексте ставит и снимает «#».
// Содержимое user.list подменяется, на роутере ничего не сохраняется.
import { test, expect } from '../fixtures.mjs';

const CONTENT = 'a.example\nb.example\n\nc.example\n';
const mockList = (app) => app.mock((b) => b.cmd === 'list_get' && b.name === 'user.list' ? { content: CONTENT, issues: [] } : b.cmd === 'list_lint' ? { issues: [] } : undefined);
const textBtn = (page) => page.getByRole('button', { name: 'Текстом', exact: true });
const listBtn = (page) => page.getByRole('button', { name: 'Списком', exact: true });

test('вид списка запоминается', async ({ app, page }) => {
  await mockList(app);
  await app.open('#/sites/user.list');
  await expect(page.locator('.ent').first()).toBeVisible();
  await textBtn(page).click();
  await expect(page.locator('textarea.ed-ta')).toBeVisible();
  // другой список и обратно — оба открываются текстом
  await page.evaluate(() => { location.hash = '#/sites/exclude.list'; });
  await expect(page.locator('h2.ltitle')).toHaveText('exclude.list');
  await expect(page.locator('textarea.ed-ta')).toBeVisible();
  await page.evaluate(() => { location.hash = '#/sites/user.list'; });
  await expect(page.locator('h2.ltitle')).toHaveText('user.list');
  await expect(page.locator('textarea.ed-ta')).toBeVisible();
  await listBtn(page).click();
  await page.evaluate(() => { location.hash = '#/sites/exclude.list'; });
  await expect(page.locator('h2.ltitle')).toHaveText('exclude.list');
  await expect(page.locator('.ent').first()).toBeVisible();
  await expect(page.locator('textarea.ed-ta')).toHaveCount(0);
});

test('Ctrl+/ ставит и снимает комментарий, Ctrl+Z отменяет', async ({ app, page }) => {
  await mockList(app);
  await app.open('#/sites/user.list');
  await textBtn(page).click();
  const ta = page.locator('textarea.ed-ta');
  await expect(ta).toBeVisible();
  const val = () => ta.inputValue();
  // курсор в середине второй строки
  await ta.evaluate((t) => { t.focus(); t.setSelectionRange(13, 13); });
  await page.keyboard.press('Control+Slash');
  expect(await val()).toBe('a.example\n# b.example\n\nc.example\n');
  expect(await ta.evaluate((t) => t.selectionStart)).toBe(15);   // курсор сдвинулся вместе с текстом
  await page.keyboard.press('Control+Slash');
  expect(await val()).toBe(CONTENT);
  // выделение через пустую строку: пустые не трогаются
  await ta.evaluate((t) => { t.focus(); t.setSelectionRange(0, t.value.length); });
  await page.keyboard.press('Control+Slash');
  expect(await val()).toBe('# a.example\n# b.example\n\n# c.example\n');
  await expect(page.getByRole('button', { name: 'Сохранить' })).toBeVisible();
  await page.keyboard.press('Control+z');
  expect(await val()).toBe(CONTENT);
});
