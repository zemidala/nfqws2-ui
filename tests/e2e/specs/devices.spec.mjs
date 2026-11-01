// NFQ-L191: «Устройства». devices_get / devices_set are mocked — nothing on the router changes.
import { test, expect } from '../fixtures.mjs';

const dev = (mac, name, on, extra = {}) => ({ mac, name, ip: '192.168.1.' + mac.slice(-2).replace(/^0/, ''), online: true, on, saved: true, manual: false, ...extra });
const ITEMS = [
  dev('aa:00:00:00:00:10', 'DESKTOP-TEST', true), dev('aa:00:00:00:00:11', 'Phone', true), dev('aa:00:00:00:00:12', 'TV-Box', true),
  dev('aa:00:00:00:00:13', 'Station', false), dev('aa:00:00:00:00:14', 'Laptop', true), dev('aa:00:00:00:00:15', 'Tablet', true, { online: false }),
  dev('aa:00:00:00:00:16', 'Printer', true), dev('aa:00:00:00:00:17', 'NAS', true), dev('3c:22:fb:00:10:9e', '', false, { manual: true, online: false, ip: '' }),
];
const GET = { keenetic: false, new_on: true, items: ITEMS, active: true, ok: true, running: true };

async function open(app, page, sent) {
  await app.mock((b) => {
    if (b.cmd === 'devices_get') return GET;
    if (b.cmd === 'devices_set') { sent.push(b); return { ...GET, items: ITEMS.map((x) => ({ ...x, on: b.list[x.mac]?.on ?? x.on })) }; }
    return undefined;
  });
  await app.open('#/devices');
  await expect(page.locator('table.devs')).toBeVisible();
}

for (const vp of [{ width: 1366, height: 600 }, { width: 390, height: 640 }]) {
  test(`переключатель виден, «Сохранить» не прокручивает наверх ${vp.width}`, async ({ app, page }) => {
    await page.setViewportSize(vp);
    const sent = [];
    await open(app, page, sent);
    const sw = page.locator('table.devs .sw');
    await expect(sw).toHaveCount(ITEMS.length);
    // every switch is inside the viewport width (no column pushed out to the right)
    for (const box of await sw.evaluateAll((els) => els.map((e) => e.getBoundingClientRect().right))) expect(box).toBeLessThanOrEqual(vp.width);
    const last = page.getByRole('switch', { name: 'Обход для 3c:22:fb:00:10:9e' });   // switched off in ITEMS
    await last.scrollIntoViewIfNeeded();
    await last.click();
    await expect(last).toHaveAttribute('aria-checked', 'true');
    await page.mouse.wheel(0, 2000);
    await page.waitForTimeout(300);
    const before = await page.evaluate(() => window.scrollY);
    expect(before).toBeGreaterThan(0);
    await page.locator('.savebar').getByRole('button', { name: 'Сохранить' }).click();
    await expect.poll(() => sent.length).toBe(1);
    expect(sent[0].list['3c:22:fb:00:10:9e'].on).toBe(true);
    await expect(page.locator('.savebar')).toBeHidden();
    await page.waitForTimeout(500);
    // stays put; only when it was at the very bottom the page gets shorter by the save bar and the browser clamps
    const [after, maxY] = await page.evaluate(() => [window.scrollY, document.documentElement.scrollHeight - window.innerHeight]);
    expect(after).toBeGreaterThanOrEqual(Math.min(before, maxY) - 5);
    expect(after).toBeGreaterThan(0);
  });
}

test('сортировка по заголовку: «Обход», повторный щелчок — обратно, «Устройство» — по имени', async ({ app, page }) => {
  await page.evaluate(() => { try { localStorage.removeItem('nfqws-ui-dev-sort'); } catch { /* */ } });
  await open(app, page, []);
  const states = () => page.locator('table.devs tbody .sw').evaluateAll((els) => els.map((e) => e.getAttribute('aria-checked')));
  const names = () => page.locator('table.devs tbody tr td:first-child').allTextContents();
  const byName = [...ITEMS].map((x) => (x.name || x.mac)).sort((a, b) => a.toLowerCase().localeCompare(b.toLowerCase(), 'ru'));
  expect((await names()).map((t) => t.replace('вписан вручную', ''))).toEqual(byName);
  await page.locator('table.devs th').filter({ hasText: 'Обход' }).getByRole('button').click();
  const on = await states();
  expect(on.indexOf('false')).toBeGreaterThan(on.lastIndexOf('true'));   // all switched on first
  await expect(page.locator('table.devs th[aria-sort="ascending"]')).toContainText('Обход');
  await page.locator('table.devs th').filter({ hasText: 'Обход' }).getByRole('button').click();
  const off = await states();
  expect(off.indexOf('true')).toBeGreaterThan(off.lastIndexOf('false'));
  await expect(page.locator('table.devs th[aria-sort="descending"]')).toContainText('Обход');
});

test('MAC вписывается вручную, неверный отклоняется', async ({ app, page }) => {
  await open(app, page, []);
  const input = page.getByLabel('MAC-адрес');
  await input.fill('zz:zz');
  await page.getByRole('button', { name: 'Добавить вручную' }).click();
  await expect(page.locator('.toast').last()).toContainText('Не похоже на MAC-адрес');
  await input.fill('A4-83-E7-00-52-0B');
  await page.getByRole('button', { name: 'Добавить вручную' }).click();
  await expect(page.locator('table.devs tbody tr').filter({ hasText: 'a4:83:e7:00:52:0b' })).toContainText('вписан вручную');
  await expect(page.locator('.savebar')).toBeVisible();
});

test('Keenetic: объяснение про политику доступа, без таблицы', async ({ app, page }) => {
  await app.mock((b) => (b.cmd === 'devices_get' ? { keenetic: true, new_on: true, items: [], active: false, ok: null, running: true } : undefined));
  await app.open('#/devices');
  await expect(page.locator('main')).toContainText('политикой доступа');
  await expect(page.locator('table.devs')).toHaveCount(0);
});
