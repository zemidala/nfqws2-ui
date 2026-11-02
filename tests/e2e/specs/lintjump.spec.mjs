// «Конфиг целиком»: нажатие на замечание ставит курсор на место, о котором оно, — и у замечаний на весь конфиг
// (комментарий в кавычках, nfqws первой версии). Сергей Боев, 02.11. Конфиг не сохраняется.
import { test, expect } from '../fixtures.mjs';

const ta = (app) => app.page.locator('textarea.ed-ta').first();
const selected = (app) => ta(app).evaluate((t) => t.value.slice(t.selectionStart, t.selectionEnd));

test('замечание «Комментарий внутри кавычек» ведёт к первому комментарию', async ({ app }) => {
  await app.open('#/settings/raw');
  const conf = await ta(app).inputValue();
  // first comment inside quotes: a fresh variable at the end, so the note exists whatever the device config is
  const text = conf.replace(/\nNFQWS_ARGS_CUSTOM="[^"]*"/g, '')
    + '\nNFQWS_ARGS_CUSTOM="--filter-tcp=443 # comment-here\n--lua-desync=fake:blob=tls_clienthello"\n';
  await ta(app).fill(text);
  const note = app.page.locator('.iss-item', { hasText: 'внутри кавычек' });
  await expect(note).toBeVisible({ timeout: 15_000 });
  await expect(note).toContainText(/строка \d+/);
  await note.click();
  const sel = await selected(app);
  expect(sel.startsWith('#'), `выделено «${sel}»`).toBe(true);
  // the selected line is inside the visible part of the editor
  const vis = await ta(app).evaluate((t) => {
    const lh = parseFloat(getComputedStyle(t).lineHeight);
    const top = (t.value.slice(0, t.selectionStart).split('\n').length - 1) * lh;
    return top >= t.scrollTop - lh && top <= t.scrollTop + t.clientHeight;
  });
  expect(vis).toBe(true);
});
