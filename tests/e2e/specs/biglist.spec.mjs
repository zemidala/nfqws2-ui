// NFQ-L95: большой список (rkn.list на 125 тыс. записей) открывается «Текстом» — простым полем,
// без подсветки и номеров строк; маленький — как раньше. Ответ list_get подменяется, на роутере ничего не пишется.
import { test, expect } from '../fixtures.mjs';

const BIG = Array.from({ length: 125000 }, (_, i) => `site${i}.example`).join('\n') + '\n';

for (const vp of [{ width: 1280, height: 900 }, { width: 390, height: 800 }]) {
  test(`большой список текстом ${vp.width}`, async ({ app, page }) => {
    await page.setViewportSize(vp);
    await app.mock((b) => b.cmd === 'list_get' && b.name === 'user.list' ? { content: BIG, issues: [] } : b.cmd === 'list_lint' ? { issues: [] } : undefined);
    await app.open('#/sites/user.list');
    await expect(page.locator('.ent').first()).toBeVisible();
    await page.getByRole('button', { name: 'Текстом', exact: true }).click();
    await expect(page.locator('.ed.plain textarea.ed-ta')).toBeVisible();
    await expect(page.locator('.ed-gut div')).toHaveCount(0);
    expect(await page.locator('textarea.ed-ta').evaluate((t) => t.value.length)).toBe(BIG.length);
  });
}

test('маленький список текстом', async ({ app, page }) => {
  await app.open('#/sites/user.list');
  await page.getByRole('button', { name: 'Текстом', exact: true }).click();
  await expect(page.locator('textarea.ed-ta')).toBeVisible();
  await expect(page.locator('.ed.plain')).toHaveCount(0);
  await expect(page.locator('.ed-gut div').first()).toHaveText('1');
});
