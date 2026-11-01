// «Проверить сайт»: через профиль не открылся, мимо nfqws2 открылся → «его ломает профиль #N» и окно исключения.
// probe, exclude_preview, exclude_apply и safe_restart подменены — конфиг и списки не меняются.
import { test, expect } from '../fixtures.mjs';

const fail = { ok: false, code: null, ms: 5003, reason: 'нет ответа (тайм-аут) — похоже на блокировку' };
const preview = (p) => ({ host: 'rutracker.org', profile: p, name: 'Весь-HTTPS', options: [
  { mode: 'name', title: 'Только rutracker.org', hint: 'Имя в exclude.list.', files: [{ name: 'exclude.list', add: ['rutracker.org'] }], token: null },
  { mode: 'ip', title: 'По адресу 172.67.182.196', hint: 'Для программ и игр.', files: [{ name: 'ipset_exclude_ui.list', add: ['172.67.182.196'] }],
    token: '--ipset-exclude=/etc/nfqws2/lists/ipset_exclude_ui.list' },
] });
const box = (app) => app.page.locator('.notice').filter({ hasText: 'Сайт не заблокирован — его ломает профиль' });

test('мимо nfqws2 открывается → итог «ломает профиль», окно, исключение по адресу', async ({ app }) => {
  const calls = [];
  let prof = null;
  await app.mock((b) => {
    if (b.cmd === 'probe') return { ...fail, direct: { ok: true, code: 200, ms: 120, kind: 'ok', ip: '172.67.182.196', profile: 1 } };
    if (b.cmd === 'exclude_preview') { prof = b.profile; return preview(b.profile); }
    if (b.cmd === 'exclude_apply') { calls.push(b); return { files: ['ipset_exclude_ui.list', 'nfqws2.conf'], title: 'По адресу 172.67.182.196', token: 'x' }; }
    if (b.cmd === 'safe_restart') return { ok: true, output: '', fixed: null, files: ['nfqws2.conf'] };
    return undefined;
  });
  await app.open('#/?q=rutracker.org');
  await expect(box(app)).toBeVisible({ timeout: 30_000 });
  await expect(box(app)).toContainText('Мимо nfqws2 открывается: HTTP 200');
  await expect(box(app)).not.toContainText('похоже на блокировку');
  await box(app).getByRole('button', { name: /Исключить из профиля/ }).click();
  const dlg = app.page.locator('.modal');
  await expect(dlg.getByText('Только rutracker.org')).toBeVisible();
  await expect(dlg.locator('.diff')).toContainText('+ rutracker.org');
  if (process.env.SHOT) await app.page.screenshot({ path: `${process.env.SHOT}/exclude-dialog.png` });
  await dlg.getByText('По адресу 172.67.182.196').click();
  await expect(dlg.locator('.diff')).toContainText('+ --ipset-exclude=');
  await dlg.getByRole('button', { name: 'Исключить и перезапустить с проверкой' }).click();
  await expect.poll(() => calls.length).toBe(1);
  expect(calls[0]).toMatchObject({ host: 'rutracker.org', mode: 'ip', profile: prof });
});

test('мимо nfqws2 тоже не открывается → прежний итог, без кнопки', async ({ app }) => {
  await app.mock((b) => (b.cmd === 'probe' ? { ...fail, direct: { ok: false, code: 0, ms: 5000, kind: 'timeout', ip: '172.67.182.196', profile: 1 } } : undefined));
  await app.open('#/?q=rutracker.org');
  await expect(app.page.locator('.notice').filter({ hasText: 'Не открывается: нет ответа' })).toBeVisible({ timeout: 30_000 });
  await expect(box(app)).toHaveCount(0);
});
