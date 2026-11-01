// «Вернуть как при установке» в «Истории изменений»: окно со списком файлов, возврат и перезапуск.
// initial_get, initial_reset и safe_restart подменены — конфиг и nfqws2 не трогаются.
import { test, expect } from '../fixtures.mjs';

const files = [
  { file: 'nfqws2.conf', exists: true, n_added: 3, n_removed: 4 },
  { file: 'old.list', exists: false, n_added: 5, n_removed: 0 },
];

test('окно: дата, файлы, «был удалён»; возврат вызывает перезапуск с проверкой', async ({ app }) => {
  const calls = [];
  await app.mock((b) => {
    if (b.cmd === 'initial_get') return { at: 1791403087, files };
    if (b.cmd === 'initial_reset') { calls.push('reset'); return { files: ['nfqws2.conf', 'old.list'] }; }
    if (b.cmd === 'safe_restart') { calls.push('restart'); return { ok: true, output: '', fixed: null, files: [] }; }
  });
  await app.open('#/settings/hist');
  await app.page.getByRole('button', { name: 'Вернуть как при установке' }).click();
  const dlg = app.page.getByRole('dialog', { name: 'Вернуть как при установке' });
  await expect(dlg).toContainText('07.10');
  await expect(dlg).toContainText('nfqws2.conf');
  await expect(dlg).toContainText('+3 −4 строк');
  await expect(dlg).toContainText('файл был удалён, появится снова');
  await expect(dlg).toContainText('Свои списки, созданные позже, останутся');
  await dlg.getByRole('button', { name: 'Вернуть и перезапустить с проверкой' }).click();
  await expect(dlg).toHaveCount(0);
  await expect.poll(() => calls.join(',')).toBe('reset,restart');
});

test('менять нечего — сообщение, окна нет', async ({ app }) => {
  await app.mock((b) => (b.cmd === 'initial_get' ? { at: 1791403087, files: [] } : undefined));
  await app.open('#/settings/hist');
  await app.page.getByRole('button', { name: 'Вернуть как при установке' }).click();
  await expect(app.page.getByText('возвращать нечего')).toBeVisible();
  await expect(app.page.getByRole('dialog')).toHaveCount(0);
});
