// NFQ-L181: deleting snapshots from the router. The snapshot list is mocked (NAS on, five kinds of snapshots);
// snapshot_delete is caught and not sent — nothing on the router changes.
import { test, expect } from '../fixtures.mjs';

const now = Math.floor(Date.now() / 1000);
const snap = (id, ago, extra) => ({ id, ts: now - ago, reason: 'вручную', note: '', size: 100000, differs: ['etc/nfqws2/nfqws2.conf'], partial: false, rollback: false, ...extra });
const ITEMS = [
  snap('20261029-120000', 600, { local: true, remote: false }),                                   // not sent yet
  snap('20261029-110000', 3600, { local: true, remote: true }),                                   // router + NAS
  snap('20261022-001200', 7 * 86400, { local: true, remote: false, pinned: true, rollback: true, reason: 'перед обновлением nfqws2' }),
  snap('20261019-162000', 10 * 86400, { local: true, remote: true, pinned: true }),
  snap('20261014-110200', 15 * 86400, { local: false, remote: true, differs: null }),              // NAS only
];
const SNAPS = {
  items: ITEMS, dir: '/etc/nfqws2/.snapshots', total: 400000, local: 4,
  settings: { max_count: 50, max_days: 30, remote: { enabled: true, host: '192.0.2.10', port: 22, user: 'u', dir: '/volume1/b', keep_local: 5 } },
  remote: { tool: 'dbclient', pub: 'ssh-ed25519 AAAA test', on: true, state: { ok: now - 60 }, pending: 2, stored: 3, setup: '' },
};

async function open(app, page, sent) {
  await app.mock((b) => {
    if (b.cmd === 'snapshots') return SNAPS;
    if (b.cmd === 'snapshot_delete') { sent.push(b.ids); return { deleted: b.ids.length, on_nas: 1, freed: 200000 }; }
    return undefined;
  });
  await app.open('#/settings/backup');
  await expect(page.locator('.tbl.snaps')).toBeVisible();
}

for (const vp of [{ width: 1366, height: 900 }, { width: 390, height: 800 }]) {
  test(`выбор снимков не сдвигает таблицу ${vp.width}`, async ({ app, page }) => {
    await page.setViewportSize(vp);
    await open(app, page, []);
    const boxes = page.locator('.tbl.snaps tbody input[type=checkbox]');
    await expect(boxes).toHaveCount(4);   // NAS only: nothing to delete on the router — no box in that row
    await expect(page.locator('.tbl.snaps tbody tr').nth(4).locator('td.chk input')).toHaveCount(0);
    const bar = page.locator('.snapsel');
    await expect(bar).toHaveClass(/off/);
    // table offset inside its panel: check() scrolls the page, so viewport coordinates would move anyway
    const top = () => page.locator('.tbl.snaps').evaluate((t) => t.getBoundingClientRect().top - t.closest('.panel').getBoundingClientRect().top);
    const before = await top();
    await boxes.nth(0).check();
    await expect(bar).not.toHaveClass(/off/);
    await expect(bar.locator('.cnt')).toHaveText('Выбрано: 1');
    expect(await top()).toBe(before);
    // «select all» takes only router snapshots
    await page.locator('.tbl.snaps thead input').check();
    await expect(bar.locator('.cnt')).toHaveText('Выбрано: 4');
    expect(await top()).toBe(before);
    await page.locator('.tbl.snaps thead input').uncheck();
    await expect(bar).toHaveClass(/off/);
    expect(await top()).toBe(before);
  });
}

test('окно удаления: предупреждения и отправка', async ({ app, page }) => {
  const sent = [];
  await open(app, page, sent);
  await page.locator('.tbl.snaps thead input').check();
  await page.locator('.snapsel .btn.danger').click();
  const dlg = page.locator('.modal');
  await expect(dlg.locator('h2')).toHaveText('Удалить снимки: 4?');
  const warn = dlg.locator('.snapwarn li');
  await expect(warn).toHaveCount(3);
  await expect(warn.nth(0)).toContainText('ещё не отправлен на NAS');
  await expect(warn.nth(1)).toContainText('«Вернуть» nfqws2 вернёт только программу');
  await expect(warn.nth(2)).toContainText('закреплён. Копия на NAS останется');
  await expect(dlg).toContainText('На NAS останутся копии 2 из 4');
  await dlg.getByRole('button', { name: 'Удалить' }).click();
  await expect(page.locator('.toast')).toContainText('Удалено с роутера: 4');
  expect(sent).toEqual([['20261029-120000', '20261029-110000', '20261022-001200', '20261019-162000']]);
});

test('один снимок с копией на NAS — без предупреждений', async ({ app, page }) => {
  await open(app, page, []);
  await page.locator('.tbl.snaps tbody input[type=checkbox]').nth(1).check();
  await page.locator('.snapsel .btn.danger').click();
  const dlg = page.locator('.modal');
  await expect(dlg.locator('.snapwarn')).toHaveCount(0);
  await expect(dlg).toContainText('На NAS копия останется');
});
