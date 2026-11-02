// NFQ-L189: restoring a snapshot deletes lists that appeared after it, so the snapshot shows «как сейчас» again.
// Writes a list and restores a snapshot — ONLY on test stands. The result is checked byte for byte.
import { test, expect, ssh } from '../fixtures.mjs';
import { TARGETS } from '../playwright.config.mjs';

test('восстановление удаляет список, которого не было в снимке, и снимок становится «как сейчас»', async ({ app, page, target }) => {
  test.skip(target === 'router', 'меняет списки — только на стенде');
  test.setTimeout(180_000);
  const conf = TARGETS[target].conf;
  const dir = conf.replace(/[^/]+$/, '');
  const extra = `${dir}lists/e2e-restore.list`;
  const sum = () => ssh(target, `cd ${dir} && md5sum nfqws2.conf lists/* blobs/* 2>/dev/null | md5sum`).split(' ')[0];
  const before = sum();
  const { id } = await app.api('snapshot_create', { note: 'e2e restore' });
  try {
    ssh(target, `echo example.org > ${extra}`);
    await app.open('#/settings/backup');
    const row = page.locator('.tbl.snaps tbody tr').filter({ hasText: 'e2e restore' }).first();
    await expect(row).toContainText('e2e-restore.list');

    await row.getByRole('button', { name: 'Восстановить' }).click();
    const asked = await app.yes();
    await expect(page.locator('.toast').last()).toContainText('удалено: 1', { timeout: 60_000 });
    expect(asked).toContain('Удалятся (их не было в снимке): e2e-restore.list');

    await expect(page.locator('.tbl.snaps tbody tr').filter({ hasText: 'e2e restore' }).first()).toContainText('как сейчас', { timeout: 30_000 });
    expect(ssh(target, `test -e ${extra} && echo yes || echo no`).trim()).toBe('no');
    expect(sum()).toBe(before);
  } finally {
    ssh(target, `rm -f ${extra}`);
  }
  expect(id).toMatch(/^\d{8}-\d{6}/);
});
