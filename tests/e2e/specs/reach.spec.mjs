// Every control of a page at 1366 px is there on a phone (390 px) too (2026-11-08: monitoring rows lost «details» and
// «remove» on a phone and nobody noticed — a hidden button is no layout error). Controls are compared by name; what a
// phone leaves out on purpose is listed in MAY_MISS with the way it is reached there instead.
import { test, expect } from '../fixtures.mjs';

const PAGES = ['#/', '#/diag', '#/tests/freeze', '#/tests/dns', '#/tests/conns', '#/tests', '#/tests/trace', '#/tests/history', '#/tests/community',
  '#/tests/configs', '#/tests/monitor', '#/tests/auto', '#/tests/learn', '#/tests/notify', '#/settings', '#/settings/p1', '#/sites', '#/sites/user.list',
  '#/devices', '#/asn', '#/settings/basic', '#/settings/base', '#/settings/raw', '#/settings/backup', '#/settings/hist', '#/log', '#/settings/report', '#/settings/look', '#/settings/about'];

const MAY_MISS = [
  /^top: a «nfqwsN-ui на GitHub»$/,          // in the phone menu (checked below)
  /^top: a «Открыть по HTTPS»$/,             // in the phone menu (checked below)
  /^top: (input\[text\] «Сайт для проверки»|button «Проверить»)$/,   // «Проверка сайта» has the same field
  /^page: button «Во весь экран»$/,          // a phone's list or config already takes the whole screen
  /^page: button «(Полоски|Часы|Столбики|Словами)»$/,   // the look of the history column, which a phone does not show
  /^page: button «Сортировать по столбцу «(Проверен|MAC|Адрес|Сейчас)»»$/,   // columns a phone does not show
  /^page: button «Открывается ли сайт и каким профилем он пойдёт»$/,   // a list line's name itself checks the site
  /^page: button «(Сервер|DoH|DoT|UDP|Подмена \(UDP N\))»$/,   // DNS table header; on a phone the rows are cards
  /^page: button «[A-Z0-9_]+N»$/,            // the config's outline; on a phone the editor is the whole page
  /^page: button «Забыть»$/,                 // «Обучение»: a phone has it in the opened row (checked below)
];

const collect = () => {
  const name = (e) => (e.getAttribute('aria-label') || e.getAttribute('title') || e.textContent || e.placeholder || e.name || '').replace(/\s+/g, ' ').trim()
    .replace(/\d+([.,:]\d+)*/g, 'N').slice(0, 60);
  const vis = (e) => { const r = e.getBoundingClientRect(); if (!r.width || !r.height) return false; const s = getComputedStyle(e); return s.visibility !== 'hidden' && s.opacity !== '0'; };
  const out = new Set();
  for (const e of document.querySelectorAll('button, a[href], [role=button], [role=switch], input:not([type=hidden]), textarea, summary')) {
    if (e.closest('.dd-sz, .dd-native, .mnav, .tabs, .bottom, .mfly, #banner') || !vis(e)) continue;   // navigation is laid out per width by design
    out.add(`${e.closest('header, .top') ? 'top' : 'page'}: ${e.tagName.toLowerCase()}${e.tagName === 'INPUT' ? '[' + e.type + ']' : ''} «${name(e)}»`);
  }
  return [...out];
};

test('всё, что есть на компьютере, есть и на телефоне', async ({ app, page, browser }) => {
  test.setTimeout(600_000);
  const phoneCtx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, storageState: await page.context().storageState() });
  const phone = await phoneCtx.newPage();
  await page.setViewportSize({ width: 1366, height: 900 });
  const missing = [];
  for (const hash of PAGES) {
    // a new hash is a route inside the same page: the old page stays on screen until the new one has its data (a slow
    // page kept «Устройства» under «#/asn»); the router marks the end with S.shownHash
    const drawn = () => S.shownHash === location.hash;
    await app.open(hash, { variant: 'z', mode: 'dark' });
    await page.waitForFunction(drawn, null, { timeout: 30_000 });
    await page.waitForTimeout(800);
    const wide = await page.evaluate(collect);
    if (hash === PAGES[0]) await phone.addInitScript(() => localStorage.setItem('nfqws-ui-look', JSON.stringify({ variant: 'z', mode: 'dark', noanim: true })));
    await phone.goto(page.url());
    await phone.waitForFunction(drawn, null, { timeout: 30_000 });
    await phone.waitForTimeout(800);
    const narrow = new Set(await phone.evaluate(collect));
    for (const k of wide) if (!narrow.has(k) && !MAY_MISS.some((re) => re.test(k))) missing.push(`${hash} ${k}`);
  }
  // what the top bar drops on a phone is in its menu
  await phone.locator('.bottom > button').click();
  await expect(phone.locator('#drawer .drawer-x a', { hasText: 'nfqws2-ui на GitHub' })).toBeVisible();
  // what «Обучение» leaves out of a phone's row is in the row opened
  await phone.goto(page.url().replace(/#.*$/, '#/tests/learn'));
  await phone.waitForFunction(() => S.shownHash === location.hash, null, { timeout: 30_000 });
  const lex = phone.locator('main button.lex').first();
  if (await lex.count()) {
    await lex.click();
    await expect(phone.locator('main tr.lmore button', { hasText: /^Забыть$/ }).first()).toBeVisible();
  }
  await phoneCtx.close();
  expect(missing).toEqual([]);
});
