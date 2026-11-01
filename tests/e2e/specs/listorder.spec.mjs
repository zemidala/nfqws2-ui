// NFQ-L193: list column — search, grouping (by kind / by profile / none) and order (name / entries).
// Works on the real lists of the device; nothing is saved on it.
import { test, expect } from '../fixtures.mjs';

const pick = async (page, label, option) => {
  await page.locator(`.lnav .dd-btn[aria-label="${label}"]`).click();
  await page.locator('.dd-pop .dd-o', { hasText: option }).click();
};
const rows = (page) => page.locator('.lnav .lnav-body a.lrow');
// numbers from the state, not from the text: big counts are shown short («14 тыс.»)
const entries = (page) => rows(page).evaluateAll((els) => els.map((a) => {
  const name = decodeURIComponent(a.getAttribute('href').split('/').pop());
  return S.state.lists.find((l) => l.name === name).entries || 0;
}));

test('группы по профилю, поиск, порядок по записям, выбор запоминается', async ({ app, page }) => {
  await app.open('#/sites');
  await expect(rows(page).first()).toBeVisible();
  const total = await page.evaluate(() => S.state.lists.length);

  await pick(page, 'Группы', 'По профилям');
  const heads = page.locator('.lnav .lsec-h:has(.pnum)');
  expect(await heads.count()).toBeGreaterThan(0);
  // every profile header names existing profiles; every connected list sits under at least one of them
  const ok = await page.evaluate(() => {
    const idx = new Set(S.state.profiles.map((p) => '#' + p.index));
    const nums = [...document.querySelectorAll('.lnav .lsec-h .pnum')].flatMap((e) => e.textContent.split(/,\s*/));
    const shown = new Set([...document.querySelectorAll('.lnav .lnav-body a.lrow')].map((a) => decodeURIComponent(a.getAttribute('href').split('/').pop())));
    return nums.every((n) => idx.has(n)) && S.state.lists.every((l) => shown.has(l.name));
  });
  expect(ok).toBe(true);

  // search narrows the column; nothing found shows a note
  await page.locator('.lnav input[type=search]').fill('user');
  const names = await rows(page).evaluateAll((els) => els.map((a) => a.getAttribute('href')));
  expect(names.length).toBeGreaterThan(0);
  expect(names.every((n) => /user/i.test(n))).toBe(true);
  await page.locator('.lnav input[type=search]').fill('zzzz-nothing');
  await expect(page.locator('.lnav .lnav-empty')).toHaveText('Ничего не найдено');
  await page.locator('.lnav input[type=search]').fill('');

  // no groups + by entries: one flat column sorted by the number of entries
  await pick(page, 'Группы', 'Без групп');
  await pick(page, 'Порядок', 'По записям');
  await expect(rows(page)).toHaveCount(total);
  const n = await entries(page);
  expect(n).toEqual([...n].sort((a, b) => b - a));

  // the choice survives a reload; back to the default afterwards
  await page.reload();
  await expect(page.locator('.lnav .dd-btn[aria-label="Группы"]')).toHaveText(/Без групп/);
  await expect(page.locator('.lnav .dd-btn[aria-label="Порядок"]')).toHaveText(/По записям/);
  await pick(page, 'Группы', 'Сайты и IP');
  await pick(page, 'Порядок', 'По имени');
  await expect(page.locator('.lnav .lsec-h', { hasText: 'Списки сайтов' })).toBeVisible();
});
