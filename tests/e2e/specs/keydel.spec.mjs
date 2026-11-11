// NFQ-L263: «Копии на NAS» — under the router's key a line says what it is for (it is no way into the router) and
// «Удалить ключ» removes it after a question; without a key the same line stands under «Создать ключ роутера».
// The snapshot list is mocked and remote_key_del is caught — nothing on the router changes.
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';
import { contrastReport } from 'pw-lib/contrast.mjs';
import { Z_ACCEPTED } from '../z-palette.mjs';

const now = Math.floor(Date.now() / 1000);
const PUB = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIL4q8Yb0m2Rk7xVnTj1sZp9cWf3dHa6uQe5oKt2yNr0B nfqws2-ui';
const snaps = (pub, enabled) => ({
  items: [], dir: '/etc/nfqws2/.snapshots', total: 0, local: 0,
  settings: { max_count: 50, max_days: 30, remote: { enabled, host: '192.0.2.10', port: 22, user: 'u', dir: '/volume1/b', keep_local: 5 } },
  remote: { tool: 'dbclient', pub, on: enabled && !!pub, state: { ok: now - 60 }, pending: 0, stored: 3, setup: pub ? 'mkdir -p /volume1/b && echo test' : '' },
});

async function open(app, page, st, look) {
  await app.mock((b) => {
    if (b.cmd === 'snapshots') return snaps(st.pub, st.enabled);
    if (b.cmd === 'remote_key_del') { st.deleted++; st.pub = ''; st.enabled = false; return { ok: true }; }
    return undefined;
  });
  await app.open('#/settings/backup', look);
  await expect(page.locator('main .panel', { hasText: 'Копии на NAS' })).toBeVisible();
}
const keyRow = (page) => page.locator('main .frow', { has: page.locator('.lbl', { hasText: 'Ключ роутера' }) });

for (const [w, mode, variant] of [[1366, 'dark', 'z'], [390, 'light', 'z'], [1366, 'light', 'k'], [390, 'dark', 'k'], [1366, 'dark', 'a'], [390, 'light', 'c']]) {
  test(`L263: ключ с пояснением и удалением, ${variant}, ${w} px, ${mode}`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    const st = { pub: PUB, enabled: true, deleted: 0 };
    await open(app, page, st, { variant, mode });
    const row = keyRow(page);
    await row.scrollIntoViewIfNeeded();
    await page.waitForTimeout(800);
    await expect(row).toContainText('Войти по нему на роутер нельзя');
    const del = row.getByRole('button', { name: 'Удалить ключ' });
    const copy = row.getByRole('button', { name: 'Копировать' });
    await expect(del).toBeVisible();
    await expect(copy).toBeVisible();
    // one row: both buttons of the same height and on one line of text
    const [a, b] = [await copy.boundingBox(), await del.boundingBox()];
    expect(Math.abs(a.height - b.height), 'высота кнопок').toBeLessThan(0.5);
    expect(Math.abs(a.y - b.y), 'кнопки в один ряд').toBeLessThan(0.5);
    expect(await auditLayout(page), 'раскладка с ключом').toEqual([]);
    expect(await contrastReport(page, { ignore: Z_ACCEPTED }), 'контраст с ключом').toEqual([]);

    await del.click();
    const text = await app.yes();
    expect(text).toContain('Удалить ключ роутера?');
    expect(text).toContain('и выключится');   // sending was on
    await expect.poll(() => st.deleted).toBe(1);
    await expect(row.getByRole('button', { name: 'Создать ключ роутера' })).toBeVisible();
    await expect(row).toContainText('Нужен, только чтобы отправлять снимки на NAS');
    await page.waitForTimeout(800);
    expect(await auditLayout(page), 'раскладка без ключа').toEqual([]);
    expect(await contrastReport(page, { ignore: Z_ACCEPTED }), 'контраст без ключа').toEqual([]);
  });
}

test('L263: отправка выключена — в вопросе не говорится, что она выключится', async ({ app, page }) => {
  const st = { pub: PUB, enabled: false, deleted: 0 };
  await open(app, page, st, { variant: 'k' });
  await keyRow(page).getByRole('button', { name: 'Удалить ключ' }).click();
  const m = page.locator('.modal.ask').last();
  await expect(m).toBeVisible();
  await expect(m).not.toContainText('и выключится');
  await m.locator('.modal-f .btn').first().click();   // «Отмена»
  await expect(m).toHaveCount(0);
  expect(st.deleted).toBe(0);
});
