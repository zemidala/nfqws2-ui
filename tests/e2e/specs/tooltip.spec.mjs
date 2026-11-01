// Own tooltips instead of the browser's: hover shows .ui-tip with the element's title, the title attribute is
// gone (so the browser shows nothing of its own), Escape and leaving hide it, it stays inside the window.
import { test, expect } from '../fixtures.mjs';

test('подсказка своя: по наведению, без системной, в пределах окна', async ({ app, page }) => {
  await app.open('#/settings/raw');
  const btn = page.locator('.cfg-tools .btn', { hasText: 'Оформить' });
  await expect(btn).toBeVisible();
  const text = await btn.getAttribute('title');
  expect(text).toBeTruthy();
  await btn.hover();
  const tip = page.locator('.ui-tip');
  await expect(tip).toBeVisible();
  await expect(tip).toHaveText(text);
  expect(await btn.getAttribute('title')).toBeNull();
  expect(await btn.getAttribute('data-tip')).toBe(text);
  await page.keyboard.press('Escape');
  await expect(tip).toBeHidden();
  // hover again, then move away
  await page.mouse.move(5, 5);
  await btn.hover();
  await expect(tip).toBeVisible();
  await page.mouse.move(5, 300);
  await expect(tip).toBeHidden();
  // an element near the right edge: the tooltip is moved inside the window
  const edge = page.locator('header [data-tip], header [title]').last();
  await edge.hover();
  await expect(tip).toBeVisible();
  const box = await tip.boundingBox();
  const w = await page.evaluate(() => document.documentElement.clientWidth);
  expect(box.x).toBeGreaterThanOrEqual(0);
  expect(box.x + box.width).toBeLessThanOrEqual(w);
});

test('title, заданный на лету, не показывается браузером', async ({ app, page }) => {
  await app.open('#/');
  const n = await page.evaluate(async () => {
    const b = document.querySelector('header button');
    b.title = 'проверка';
    await new Promise((r) => setTimeout(r, 0));
    return [b.hasAttribute('title'), b.dataset.tip];
  });
  expect(n).toEqual([false, 'проверка']);
});
