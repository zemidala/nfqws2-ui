// A redraw of the same address keeps the page where it was; another address starts from the top.
import { test, expect } from '../fixtures.mjs';

test('«Оформление»: смена темы и стиля не прокручивает страницу наверх', async ({ app, page }) => {
  await page.setViewportSize({ width: 1366, height: 500 });
  await app.open('#/settings/look', { variant: 'k' });
  const themes = page.locator('main .seg[role="radiogroup"]').last().getByRole('radio');
  await expect(themes.first()).toBeVisible();
  await themes.nth(1).scrollIntoViewIfNeeded();
  await page.evaluate(() => window.scrollBy(0, 80));
  const was = await page.evaluate(() => Math.round(window.scrollY));
  expect(was).toBeGreaterThan(0);
  for (const i of [1, 2, 0]) {
    await page.locator('main .seg[role="radiogroup"]').last().getByRole('radio').nth(i).click();
    await page.waitForTimeout(500);
    expect(await page.evaluate(() => Math.round(window.scrollY))).toBe(was);
  }
  await page.evaluate(() => document.querySelectorAll('label.look input[name="look"]')[1].click());   // a real click would scroll to it
  await page.waitForTimeout(400);
  expect(await page.evaluate(() => Math.round(window.scrollY))).toBe(was);
});

test('другой адрес открывается сверху', async ({ app, page }) => {
  await page.setViewportSize({ width: 1366, height: 500 });
  await app.open('#/settings/look', { variant: 'k' });
  await page.evaluate(() => window.scrollTo(0, 400));
  expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
  await page.evaluate(() => { location.hash = '#/settings/basic'; });
  await page.waitForTimeout(900);
  expect(await page.evaluate(() => window.scrollY)).toBe(0);
});
