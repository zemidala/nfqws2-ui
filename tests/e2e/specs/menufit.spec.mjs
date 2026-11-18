// Drop-down menus (.menu-list) stay inside the window: a menu tied to the button's right edge near the left side
// of the window opens to the right instead, one that does not fit either way is moved inside, edges on whole pixels.
import { test, expect } from '../fixtures.mjs';

const inside = async (page, list) => {
  const r = await list.evaluate((m) => { const a = m.getBoundingClientRect(); return { l: a.left, r: a.right, W: document.documentElement.clientWidth }; });
  expect(r.l, 'левый край меню').toBeGreaterThanOrEqual(8);
  expect(r.r, 'правый край меню').toBeLessThanOrEqual(r.W - 8);
  return r;
};

for (const [w, hgt] of [[390, 844], [1366, 800]]) {
  test(`меню «Ещё» в списке в пределах окна, ${w}`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: hgt });
    await app.open('#/sites/user.list');
    const more = page.locator('main .btn.icon[aria-haspopup="menu"]').first();
    await expect(more).toBeVisible();
    await more.click();
    const list = more.locator('xpath=..').locator('.menu-list');
    await expect(list).toBeVisible();
    await inside(page, list);
  });

  test(`меню у края окна сдвигается внутрь, ${w}`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: hgt });
    await app.open('#/tests/pick');
    for (const [side, style] of [['left:2px', 'right:0;left:auto'], ['right:2px', '']]) {
      await page.evaluate(([side, style]) => {
        document.getElementById('mf')?.remove();
        const list = h('div', { class: 'menu-list wide', hidden: true, role: 'menu', style }, h('button', { type: 'button', text: 'Пункт меню' }));
        document.body.append(h('div', { id: 'mf', style: `position:fixed;top:60px;z-index:999;${side}` },
          h('span', { class: 'menu' }, btn('Применить…', () => { list.hidden = !list.hidden; }, 'small'), list)));
      }, [side, style]);
      await page.locator('#mf .btn').click();
      const list = page.locator('#mf .menu-list');
      await expect(list).toBeVisible();
      await page.waitForTimeout(200);
      const r = await inside(page, list);
      expect(Number.isInteger(r.l) || Number.isInteger(r.r), `край на целом пикселе (${side})`).toBe(true);
    }
  });
}
