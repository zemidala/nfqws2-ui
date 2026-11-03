// NFQ-L223, mockup local/design/l223.html, variant A everywhere: a list on the whole window; «Проверить» under the
// own DNS servers; pinned menu items. user.list is mocked, list_save never reaches the router.
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

const CONTENT = Array.from({ length: 80 }, (_, i) => `site${i}.example`).join('\n') + '\n';

test('список во весь экран: на всё окно, «Сохранить» в шапке, Ctrl+S, Esc — обратно', async ({ app, page }) => {
  await page.setViewportSize({ width: 1366, height: 800 });
  const saved = [];
  await app.mock((b) => {
    if (b.cmd === 'list_get' && b.name === 'user.list') return { content: CONTENT, issues: [] };
    if (b.cmd === 'list_lint') return { issues: [] };
    if (b.cmd === 'list_save') { saved.push(b.content); return { ok: true }; }
  });
  await app.open('#/sites/user.list', { variant: 'k', listText: true });
  const fs = page.getByRole('button', { name: 'Во весь экран' });
  await fs.click();
  const panel = page.locator('body > .lpanel.full');
  await expect(panel).toBeVisible();
  const box = await panel.boundingBox();
  expect(box).toMatchObject({ x: 0, y: 0, width: 1366, height: 800 });
  // the bar: name, count, view, «Сохранить», back; history, menu and readers hidden
  await expect(panel.locator('.lhead .lfs-only .btn')).toHaveText('Сохранить');
  await expect(panel.getByRole('button', { name: 'История' })).toBeHidden();
  // the text fills the window down to the bottom (no empty band under it)
  const ta = await panel.locator('textarea.ed-ta').boundingBox();
  expect(ta.y + ta.height).toBeGreaterThan(800 - 60);
  await panel.locator('textarea.ed-ta').press('End');
  await panel.locator('textarea.ed-ta').type('x');
  await panel.locator('textarea.ed-ta').press('Control+s');
  await expect.poll(() => saved.length).toBe(1);
  await expect(panel).toBeVisible();   // a save keeps full screen
  // one row in the bar: the buttons of equal height, on one line
  const bs = await panel.locator('.lhead > .row:first-child :is(.btn, .seg)').evaluateAll((els) => els.filter((e) => e.offsetParent).map((e) => { const r = e.getBoundingClientRect(); return [Math.round(r.top), Math.round(r.height)]; }));
  expect(new Set(bs.map((x) => x[0] + x[1] / 2)).size).toBeLessThanOrEqual(1);
  await page.keyboard.press('Escape');
  await expect(page.locator('body > .lpanel')).toHaveCount(0);
  await expect(page.locator('main .lpanel')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Во весь экран' })).toBeVisible();
});

test('список во весь экран: уход со страницы снимает его', async ({ app, page }) => {
  await page.setViewportSize({ width: 1366, height: 800 });
  await app.mock((b) => b.cmd === 'list_get' && b.name === 'user.list' ? { content: CONTENT, issues: [] } : undefined);
  await app.open('#/sites/user.list', { variant: 'k' });
  await page.getByRole('button', { name: 'Во весь экран' }).click();
  await expect(page.locator('body > .lpanel.full')).toBeVisible();
  await page.evaluate(() => { location.hash = '#/settings/look'; });
  await expect(page.locator('main h1')).toHaveText('Оформление');
  await expect(page.locator('body > .lpanel')).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.classList.contains('cfg-fs'))).toBe(false);
});

test('список на телефоне: кнопки «Во весь экран» нет', async ({ app, page }) => {
  await page.setViewportSize({ width: 390, height: 800 });
  await app.mock((b) => b.cmd === 'list_get' && b.name === 'user.list' ? { content: CONTENT, issues: [] } : undefined);
  await app.open('#/sites/user.list', { variant: 'k' });
  await expect(page.locator('h2.ltitle')).toHaveText('user.list');
  await expect(page.getByRole('button', { name: 'Во весь экран' })).toBeHidden();
});

for (const w of [390, 1366]) {
  test(`«DNS-серверы»: «Проверить» под полями, слева, ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    await app.mock((b) => {
      if (b.cmd === 'dns_get') return { servers: [], sites: [], own_servers: [], own_only: false };
      if (b.cmd === 'dnsx_get') return {};
    });
    await app.open('#/tests/dns', { variant: 'k' });
    const btn = page.locator('main').getByRole('button', { name: /^Проверить/ }).first();
    const [b, own, only, h1] = await Promise.all([btn.boundingBox(), page.getByRole('textbox', { name: 'Свои DNS-серверы' }).boundingBox(),
      page.getByRole('checkbox', { name: /Только свои/ }).boundingBox(), page.locator('main h1').boundingBox()]);
    expect(b.y).toBeGreaterThan(only.y);   // under the fields
    expect(b.y).toBeGreaterThan(own.y);
    expect(b.x).toBeLessThan(w / 2);       // on the left, not in the corner
    expect(b.y).toBeGreaterThan(h1.y + h1.height);
    expect(await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs'] })).toEqual([]);
  });
}

test('меню: звёздочка закрепляет пункт под «Обзором», порядок и «Открепить» — в «Оформлении»', async ({ app, page }) => {
  await page.setViewportSize({ width: 1366, height: 900 });
  await app.open('#/settings/look', { variant: 'k' });
  const nav = page.locator('#side .mnav');
  await expect(nav.locator('.nav-h', { hasText: 'Закреплённые' })).toHaveCount(0);
  for (const name of ['Конфиг целиком', 'Списки']) {
    const a = nav.locator('.mlinks a', { hasText: name }).last();
    await a.hover();
    await a.locator('.mpin').click();
  }
  const grp = nav.locator('.mgrp', { has: page.locator('.nav-h', { hasText: 'Закреплённые' }) });
  await expect(grp.locator('a .nm')).toHaveText(['Конфиг целиком', 'Списки']);
  // right under «Обзор»: the second group of the menu
  await expect(nav.locator('.mgrp').nth(1)).toContainText('Закреплённые');
  // still in its own group too, and the click on the star did not open the page
  await expect(nav.locator('.mgrp', { hasText: 'Система' }).locator('a .nm', { hasText: 'Конфиг целиком' })).toHaveCount(1);
  await expect(page.locator('main h1')).toHaveText('Оформление');
  // the look page (drawn again — it was drawn before the pins): order and unpin
  await page.evaluate(() => { location.hash = '#/'; });
  await expect(page.locator('main h1').first()).toHaveText('Обзор');
  await page.evaluate(() => { location.hash = '#/settings/look'; });
  await expect(page.locator('main h1')).toHaveText('Оформление');
  const list = page.locator('.olist');
  await expect(list.locator('.olist-it .grow')).toHaveText(['Конфиг целиком', 'Списки']);
  await page.getByRole('button', { name: 'Ниже: Конфиг целиком' }).click();
  await expect(list.locator('.olist-it .grow')).toHaveText(['Списки', 'Конфиг целиком']);
  await expect(grp.locator('a .nm')).toHaveText(['Списки', 'Конфиг целиком']);
  await list.locator('.olist-it', { hasText: 'Списки' }).getByRole('button', { name: 'Открепить' }).click();
  await expect(grp.locator('a .nm')).toHaveText(['Конфиг целиком']);
  // the row of a pinned item: name and star on one line, the star inside the item
  const a = grp.locator('a').first();
  const [ra, rs] = await Promise.all([a.boundingBox(), a.locator('.mpin').boundingBox()]);
  expect(rs.x + rs.width).toBeLessThanOrEqual(ra.x + ra.width);
  expect(Math.abs((rs.y + rs.height / 2) - (ra.y + ra.height / 2))).toBeLessThanOrEqual(2);
  expect(await auditLayout(page)).toEqual([]);
});
