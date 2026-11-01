// Copy buttons: config, profile and list text, launch parameters, logs, commands, README code blocks.
// The clipboard is replaced by a spy (the router is plain http — navigator.clipboard is absent there), so nothing leaves
// the browser; a separate test covers the fallback through a hidden field. Nothing is saved on the router.
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';

const spy = (page) => page.addInitScript(() => {
  window.__copied = [];
  Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async (t) => { window.__copied.push(t); } } });
});
const copied = (page) => page.evaluate(() => window.__copied);

test('Конфиг целиком: копирует текст редактора, галочка на 1,5 с', async ({ app, page }) => {
  await spy(page);
  await app.open('#/settings/raw');
  const b = page.locator('.cfg-tools .btn', { hasText: 'Копировать' });
  const w = (await b.boundingBox()).width;
  await page.locator('.cfg-body textarea').evaluate((t) => { t.value += '\n# test-copy'; t.dispatchEvent(new Event('input', { bubbles: true })); });
  await b.click();
  await expect(b).toHaveClass(/copied/);
  expect(Math.round((await b.boundingBox()).width)).toBe(Math.round(w));   // the button does not change width
  const [t] = await copied(page);
  expect(t).toContain('NFQWS_ARGS');
  expect(t.endsWith('# test-copy')).toBe(true);   // unsaved edits are copied too
  await expect(b).not.toHaveClass(/copied/, { timeout: 3000 });
});

test('профиль «Текст», список «Текстом», параметры запуска', async ({ app, page }) => {
  await spy(page);
  await page.addInitScript(() => { try { sessionStorage.setItem('nfqws-ui-pview', 'text'); } catch { /* no storage */ } });
  await app.open('#/settings/p1');
  await page.getByRole('button', { name: 'Копировать', exact: true }).click();
  await app.open('#/sites/user.list');
  await page.getByRole('button', { name: 'Текстом', exact: true }).click();
  await page.getByRole('button', { name: 'Копировать', exact: true }).click();
  await app.open('#/settings/base');
  await page.getByRole('button', { name: 'Копировать', exact: true }).click();
  const [prof, list, base] = await copied(page);
  expect(prof).toMatch(/^--/m);
  expect(list.length).toBeGreaterThan(0);
  expect(base).toContain('--lua-init=');
});

test('журнал и команды в «О программе»', async ({ app, page }) => {
  await spy(page);
  await app.open('#/log');
  const lb = page.locator('main .panel-h .btn', { hasText: 'Копировать' }).first();
  await expect(lb).toBeVisible();
  await lb.click();
  await app.open('#/settings/about');
  const cmd = page.locator('.cmd-row').first();
  await cmd.getByRole('button', { name: 'Скопировать' }).click();
  const [log, c] = await copied(page);
  expect(typeof log).toBe('string');
  expect(c).toBe(await cmd.locator('code').textContent());
});

test('блок кода в «Справке»: значок в углу', async ({ app, page }) => {
  await spy(page);
  await app.open('#/settings/readme');
  const box = page.locator('.copybox').first();
  await box.scrollIntoViewIfNeeded();
  await box.getByRole('button', { name: 'Скопировать' }).click();
  const [t] = await copied(page);
  expect(t).toBe(await box.locator('pre').textContent());
  // the icon sits inside the block and does not cover the frame
  const b = await box.locator('.btn').boundingBox(), p = await box.locator('pre').boundingBox();
  expect(b.x + b.width).toBeLessThanOrEqual(p.x + p.width);
  expect(b.y).toBeGreaterThanOrEqual(p.y);
});

test('без navigator.clipboard (http) — копирование через скрытое поле', async ({ app, page }) => {
  await page.addInitScript(() => {
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
    window.__exec = [];
    const orig = document.execCommand.bind(document);
    document.execCommand = (cmd, ...a) => { if (cmd === 'copy') { window.__exec.push(document.activeElement?.value); return true; } return orig(cmd, ...a); };
    window.prompt = () => { window.__prompted = true; return null; };
  });
  await app.open('#/settings/about');
  const cmd = page.locator('.cmd-row').first();
  await cmd.getByRole('button', { name: 'Скопировать' }).click();
  expect(await page.evaluate(() => window.__exec)).toEqual([await cmd.locator('code').textContent()]);
  expect(await page.evaluate(() => !!window.__prompted)).toBe(false);
  await expect(page.locator('textarea[readonly]')).toHaveCount(0);   // the hidden field is removed
});

for (const w of [390, 900]) {
  test(`вёрстка с кнопками копирования, ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    for (const hash of ['#/settings/raw', '#/settings/about', '#/settings/readme', '#/log', '#/settings/base']) {
      await app.open(hash);
      await page.waitForTimeout(300);
      // README tables on a phone are narrow columns by themselves (not the copy buttons) — checked separately
      const ign = ['ПРОКРУТКА ВБОК nav.subtabs', 'span.tk-', 'span.iss', ...(hash.endsWith('readme') ? ['СТОЛБИК td'] : [])];
      expect(await auditLayout(page, { ignore: ign }), hash).toEqual([]);
    }
  });
}
