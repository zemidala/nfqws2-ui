// NFQ-L184: own dropdowns over hidden native selects. Pick by mouse and keyboard, the native value and change
// event follow, search in long lists, opening upwards at the window edge, a sheet on a phone. Nothing is saved.
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';

const repeatsBtn = (page) => page.locator('main .dd-btn[aria-label="Повторов"]');
const repeatsSel = (page) => page.locator('main select[aria-label="Повторов"]');

test('нет видимых системных списков', async ({ app, page }) => {
  for (const hash of ['#/tests', '#/settings/p1', '#/tests/auto', '#/settings/hist']) {
    await app.open(hash);
    await page.waitForTimeout(300);
    const visible = await page.locator('select').evaluateAll((xs) => xs.filter((x) => x.offsetParent !== null).length);
    expect(visible, hash).toBe(0);
  }
});

test('мышью: выбор меняет значение и шлёт change', async ({ app, page }) => {
  await app.open('#/tests');
  await expect(repeatsBtn(page)).toHaveText(/3 повтора/);
  await repeatsSel(page).evaluate((s) => { window.__chg = 0; s.addEventListener('change', () => window.__chg++); });
  await repeatsBtn(page).click();
  const pop = page.locator('.dd-pop');
  await expect(pop).toBeVisible();
  await expect(pop.locator('.dd-o[aria-selected="true"]')).toHaveText('3 повтора');
  await pop.locator('.dd-o', { hasText: '5 повторов' }).click();
  await expect(pop).toHaveCount(0);
  await expect(repeatsBtn(page)).toHaveText(/5 повторов/);
  expect(await repeatsSel(page).inputValue()).toBe('5');
  expect(await page.evaluate(() => window.__chg)).toBe(1);
  await expect(repeatsBtn(page)).toBeFocused();
});

test('клавиатура: стрелки, буква, Enter, Esc', async ({ app, page }) => {
  await app.open('#/tests');
  await repeatsBtn(page).focus();
  await page.keyboard.press('ArrowDown');
  await expect(page.locator('.dd-pop')).toBeVisible();
  await page.keyboard.press('ArrowUp');
  await page.keyboard.press('Enter');
  expect(await repeatsSel(page).inputValue()).toBe('2');
  await page.keyboard.press('Enter');
  await page.keyboard.press('5');   // first letter jump: «5 повторов»
  await page.keyboard.press('Escape');
  await expect(page.locator('.dd-pop')).toHaveCount(0);
  expect(await repeatsSel(page).inputValue()).toBe('2');
  await page.keyboard.press(' ');
  await page.keyboard.press('5');
  await page.keyboard.press('Enter');
  expect(await repeatsSel(page).inputValue()).toBe('5');
});

test('код меняет значение и подписи — кнопка следом', async ({ app, page }) => {
  await app.open('#/tests');
  await repeatsSel(page).evaluate((s) => { s.value = '1'; });
  await expect(repeatsBtn(page)).toHaveText(/1 повтор/);
  await repeatsSel(page).evaluate((s) => { s.options[0].text = 'один раз'; });
  await expect(repeatsBtn(page)).toHaveText(/один раз/);
});

test('длинный список: поиск и группы, у края — вверх', async ({ app, page }) => {
  await page.setViewportSize({ width: 1280, height: 700 });
  await app.open('#/settings/p1');
  const add = page.locator('main .dd-btn', { hasText: 'Добавить шаг' });
  await add.scrollIntoViewIfNeeded();
  await page.evaluate(() => window.scrollBy(0, 99999));
  await add.click();
  const pop = page.locator('.dd-pop');
  await expect(pop.locator('.dd-q')).toBeFocused();
  const b = await add.boundingBox(), p = await pop.boundingBox();
  if (b.y > 700 / 2) expect(p.y + p.height).toBeLessThanOrEqual(b.y + 1);   // opened upwards
  await page.keyboard.type('multisplit');
  await expect(pop.locator('.dd-g').first()).toBeVisible();
  await expect(pop.locator('.dd-o').first()).toContainText('multisplit');
  await page.keyboard.type('zzz');
  await expect(pop.locator('.dd-none')).toHaveText('Ничего не найдено');
  await page.keyboard.press('Escape');
  await expect(pop).toHaveCount(0);
});

test('телефон: список снизу экрана, «Готово» закрывает', async ({ app, page }) => {
  await page.setViewportSize({ width: 390, height: 800 });
  await app.open('#/tests');
  await repeatsBtn(page).click();
  const pop = page.locator('.dd-pop.sheet');
  await expect(pop).toBeVisible();
  await pop.evaluate((e) => Promise.all(e.getAnimations().map((a) => a.finished)));   // the sheet slides in (animations)
  const p = await pop.boundingBox();
  expect(Math.round(p.y + p.height)).toBe(800);
  expect(Math.round(p.width)).toBe(390);
  expect(await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs'] })).toEqual([]);
  await pop.getByRole('button', { name: 'Готово' }).click();
  await expect(pop).toHaveCount(0);
  await page.locator('main .dd-btn[aria-label="Повторов"]').click();
  await page.locator('.dd-back').click({ position: { x: 20, y: 20 } });
  await expect(page.locator('.dd-pop')).toHaveCount(0);
});
