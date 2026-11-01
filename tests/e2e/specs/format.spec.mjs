// «Конфиг целиком» → «Оформить»: на настоящем конфиге устройства, без сохранения.
// Меняются только пробелы и переносы; Ctrl+Z возвращает как было; повторное нажатие — «уже оформлен».
import { test, expect } from '../fixtures.mjs';

const btnFormat = (app) => app.page.locator('.cfg-tools button', { hasText: 'Оформить' });
const ta = (app) => app.page.locator('textarea.ed-ta').first();
const toks = (s) => s.replace(/\\[ \t]*\n/g, ' ').trim().split(/\s+/);

test('«Оформить»: параметр на строку, пустая строка перед --new, аргументы те же; Ctrl+Z возвращает', async ({ app }) => {
  await app.open('#/settings/raw');
  await expect(btnFormat(app)).toBeVisible();
  const before = await ta(app).inputValue();
  await btnFormat(app).click();
  await expect(app.page.locator('.toast').last()).toContainText(/Оформлено|уже оформлен/, { timeout: 15_000 });
  const after = await ta(app).inputValue();
  // каждая строка многострочного значения — один параметр (или целый комментарий), выравнивание под кавычку
  for (const m of after.matchAll(/^([A-Z_][A-Z0-9_]*)="([^"]*\n[^"]*)"/gm)) {
    const pad = ' '.repeat(m[1].length + 2);
    for (const line of m[2].split('\n').slice(1)) {
      if (line === '') continue;
      expect(line.startsWith(pad), `${m[1]}: строка «${line}» не под кавычкой`).toBe(true);
      const t = line.slice(pad.length);
      expect(t.startsWith('#') || !/\s/.test(t), `${m[1]}: в строке «${t}» больше одного параметра`).toBe(true);
    }
    expect(m[2], `${m[1]}: --new без пустой строки перед ним`).not.toMatch(/[^\n]\n[ ]*--new(\s|=|$)/m);
  }
  // аргументы каждой переменной те же
  const vars = (s) => [...s.matchAll(/^([A-Z_][A-Z0-9_]*)="([^"]*)"/gm)].map((m) => [m[1], toks(m[2].replace(/#[^\n]*/g, ''))]);
  expect(vars(after)).toEqual(vars(before));
  // повторно — уже оформлен
  await btnFormat(app).click();
  await expect(app.page.locator('.toast').last()).toContainText('уже оформлен', { timeout: 15_000 });
  // Ctrl+Z — прежний текст (если что-то менялось)
  if (after !== before) {
    await ta(app).focus();
    await app.page.keyboard.press('Control+Z');
    await expect.poll(() => ta(app).inputValue()).toBe(before);
  }
});
