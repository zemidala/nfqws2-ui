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
    // a slow device is still rebuilding the page for a moment: the place comes back when it is done
    await expect.poll(() => page.evaluate(() => Math.round(window.scrollY)), { timeout: 10_000 }).toBe(was);
  }
  await page.locator('label.look input[name="look"]').nth(1).evaluate((e) => e.click(), null, { timeout: 15_000 });   // a real click would scroll to it
  // another style has another height, so the page may rest on its new bottom, but it does not go back to the top
  await expect.poll(() => page.evaluate(() => window.scrollY), { timeout: 10_000 }).toBeGreaterThan(was - 200);
});

test('другой адрес открывается сверху', async ({ app, page }) => {
  await page.setViewportSize({ width: 1366, height: 500 });
  await app.open('#/settings/look', { variant: 'k' });
  await page.evaluate(() => window.scrollTo(0, 400));
  expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
  await page.evaluate(() => { location.hash = '#/settings/basic'; });
  await expect.poll(() => page.evaluate(() => window.scrollY), { timeout: 10_000 }).toBeLessThan(2);
});
