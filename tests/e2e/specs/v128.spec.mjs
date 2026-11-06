// 1.28: config editor full screen, «don't show» on config notes, router load in the top bar, page animations.
// Hiding writes settings.json on the device; the test shows the note again at the end.
import { test, expect } from '../fixtures.mjs';

// a checkbox in «Оформление» by its bold title — the way a person switches it
const lookBox = (app, title) => app.page.locator('label.chk', { has: app.page.locator('b', { hasText: title }) }).locator('input');

test('«Конфиг целиком» во весь экран: на всё окно, Esc — обратно', async ({ app }) => {
  await app.page.setViewportSize({ width: 1366, height: 800 });
  await app.open('#/settings/raw');
  const cfg = app.page.locator('.cfg');
  const fs = app.page.locator('.cfg-fs');
  await fs.click();
  await expect(cfg).toHaveClass(/full/);
  const box = await cfg.boundingBox();
  expect(box.x).toBe(0); expect(box.y).toBe(0);
  expect(Math.round(box.width)).toBe(1366); expect(Math.round(box.height)).toBe(800);
  // the editor takes the free height, the window does not scroll
  const ta = await app.page.locator('.cfg-body .ed-ta').boundingBox();
  expect(ta.height).toBeGreaterThan(400);
  // the text right after «Структура» (or from the left edge without it) and to the right edge; the bar in one line
  expect(ta.x).toBeLessThan(300);
  expect(Math.round(ta.x + ta.width)).toBeGreaterThan(1366 - 30);
  const ol = app.page.locator('.cfg.full > .outline');
  if (await ol.isVisible()) expect((await ol.boundingBox()).x).toBe(0);
  // one line — unless the config has notes: their chips (on a test stand's config) take a second line at 1366
  if (!await app.page.locator('.cfg.full > .cfg-tools .chip').count()) expect((await app.page.locator('.cfg.full > .cfg-tools').boundingBox()).height).toBeLessThan(60);
  expect(await app.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  // the page behind does not scroll: no second scrollbar beside the editor's own, the block spans the whole width
  expect(await app.page.evaluate(() => getComputedStyle(document.documentElement).overflowY)).toBe('hidden');
  expect(await app.page.evaluate(() => Math.round(document.querySelector('.cfg.full').getBoundingClientRect().width) === innerWidth)).toBe(true);
  // notes and help: folded into a strip, slide up on a click; Esc folds them first, then leaves full screen
  const strip = app.page.locator('.cfg.full .cfg-xh');
  const extra = app.page.locator('.cfg.full .cfg-extra');
  await expect(strip).toBeVisible();
  await expect(extra).toBeHidden();
  const sb = await strip.boundingBox();
  expect(Math.round(sb.y + sb.height)).toBeLessThanOrEqual(800 - 20);   // above the status line
  await strip.click();
  await expect(extra).toBeVisible();
  expect((await extra.boundingBox()).height).toBeLessThanOrEqual(800 * 0.45 + 2);
  await app.page.keyboard.press('Escape');
  await expect(extra).toBeHidden();
  await expect(cfg).toHaveClass(/full/);
  await app.page.keyboard.press('Escape');
  await expect(cfg).not.toHaveClass(/full/);
  expect(await app.page.evaluate(() => getComputedStyle(document.documentElement).overflowY)).not.toBe('hidden');
  await expect(app.page.locator('main .cfg-extra')).toBeVisible();   // normal size — the block is open as before
  // on a phone there is no button
  await app.page.setViewportSize({ width: 390, height: 800 });
  await expect(fs).toBeHidden();
});

test('во весь экран: сохранение (Ctrl+S) не выкидывает, редактор вне main', async ({ app, target }) => {
  test.skip(target !== 'stand', 'сохраняет конфиг (тот же текст) — только на стенде');
  await app.page.setViewportSize({ width: 1366, height: 800 });
  await app.open('#/settings/raw');
  await app.page.locator('.cfg-fs').click();
  await expect(app.page.locator('body > .cfg.full')).toHaveCount(1);
  // nothing changed — «Save» is grey and Ctrl+S only says so
  const saveB = app.page.locator('.cfg-tools button', { hasText: 'Сохранить' });
  const diffB = app.page.locator('.cfg-tools button', { hasText: 'Сравнить с сохранённым' });
  await expect(saveB).toBeDisabled(); await expect(diffB).toBeDisabled();
  const ta = app.page.locator('.cfg-body .ed-ta');
  await ta.focus();
  await app.page.keyboard.press('Control+S');
  await expect(app.page.locator('.toast').last()).toContainText('Изменений нет');
  // an empty line at the end (the router trims it, nfqws2 gets the same) makes the text different
  await app.page.keyboard.press('Control+End');
  await app.page.keyboard.press('Enter');
  await expect(saveB).toBeEnabled(); await expect(diffB).toBeEnabled();
  await app.page.keyboard.press('Backspace');
  await expect(saveB).toBeDisabled();
  await app.page.keyboard.press('Enter');
  await app.page.keyboard.press('Control+S');
  await expect(app.page.locator('.toast').last()).toContainText('Конфиг сохранён', { timeout: 20_000 });
  await expect(app.page.locator('body > .cfg.full')).toHaveCount(1, { timeout: 15_000 });
  await expect(app.page.locator('main .cfg')).toHaveCount(0);
  // the empty line goes away again: the stand's file ends up byte for byte as before
  await app.page.waitForLoadState('networkidle');   // the page redraws after a save: type into the new editor
  await expect(saveB).toBeDisabled({ timeout: 15_000 });
  await ta.focus();
  await app.page.keyboard.press('Control+End');
  await app.page.keyboard.press('Backspace');
  await expect(saveB).toBeEnabled();
  await app.page.keyboard.press('Control+S');
  await expect(app.page.locator('.toast').last()).toContainText('Конфиг сохранён', { timeout: 20_000 });
  await expect(saveB).toBeDisabled({ timeout: 15_000 });
  await app.page.keyboard.press('Escape');
  await expect(app.page.locator('main .cfg:not(.full)')).toHaveCount(1);
  // leaving the page ends full screen
  await app.page.locator('.cfg-fs').click();
  await app.page.evaluate(() => { location.hash = '#/settings/look'; });
  await expect(app.page.locator('.cfg')).toHaveCount(0);
});

test('нагрузка: два запроса подряд не дают случайных 0 или 100 %', async ({ app }) => {
  await app.open('#/');
  await app.api('load'); await app.page.waitForTimeout(1500);
  const a = await app.api('load');
  const b = await app.api('load');
  expect(a.cpu).not.toBeNull();
  expect(b.cpu).toBe(a.cpu);
});

test('«Не показывать»: замечание пропадает из конфига и «Проблем», «Скрыто» возвращает', async ({ app }) => {
  await app.open('#/settings/raw');
  const st = await app.api('state');
  const note = (st.lint.issues || []).find((x) => x.key);
  test.skip(!note, 'в конфиге устройства нет предупреждений и советов');
  const item = app.page.locator('.cfg-extra .iss-list li', { hasText: note.msg }).first();
  await expect(item).toBeVisible();
  try {
    await item.getByRole('button', { name: 'Не показывать' }).click();
    await expect(item).toBeHidden();
    await expect.poll(async () => (await app.api('state')).lint.issues.some((x) => x.key === note.key)).toBe(false);
    const hid = app.page.locator('.cfg-tools .chip', { hasText: /^Скрыто: \d+/ });
    await expect(hid).toBeVisible();
    // errors have no button
    for (const li of await app.page.locator('.cfg-extra .iss-list li').all()) {
      if (await li.locator('.lvl-error, .ic-bad').count()) await expect(li.getByRole('button', { name: 'Не показывать' })).toHaveCount(0);
    }
    await hid.click();
    const dlg = app.page.locator('.modal');
    await expect(dlg).toContainText(note.msg.slice(0, 40));
    await dlg.locator('.hid-row', { hasText: note.msg.slice(0, 40) }).getByRole('button', { name: 'Показать снова' }).click();
    await expect.poll(async () => (await app.api('state')).lint.issues.some((x) => x.key === note.key)).toBe(true);
  } finally {
    await app.api('lint_hide', { key: note.key, msg: note.msg, on: false });
  }
});

test('нагрузка роутера в шапке: только с галочкой, цифры похожи на правду', async ({ app }) => {
  await app.page.setViewportSize({ width: 1920, height: 900 });   // on a narrower window the numbers leave the bar when it is full
  await app.open('#/settings/look');
  await expect(app.page.locator('#load')).toBeHidden();
  await lookBox(app, 'Показывать нагрузку роутера').check();
  const load = app.page.locator('#load');
  await expect(load).toBeVisible();
  await expect(load).toContainText(/Память \d{1,3} %/);
  const r = await app.api('load');
  expect(r.mem_total).toBeGreaterThan(10000);
  expect(r.mem_free).toBeGreaterThan(0);
  // the second reading has a CPU figure (the first only stores the base)
  await app.page.waitForTimeout(2200);
  const r2 = await app.api('load');
  expect(r2.cpu).toBeGreaterThanOrEqual(0); expect(r2.cpu).toBeLessThanOrEqual(100);
  // header stays in one line at 390 and 1366
  for (const w of [390, 1366]) {
    await app.page.setViewportSize({ width: w, height: 800 });
    expect(await app.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `шапка шире окна при ${w}`).toBe(true);
    const top = await app.page.locator('.top-in').boundingBox();
    expect(top.height, `шапка в две строки при ${w}`).toBeLessThan(70);
  }
});

// Page switches go through the browser's view transitions: the old page stays as a picture until the new one is
// built (no skeleton, no second copy in the DOM), then a fade on another page; a plain cut (html.vt-cut) for the same
// page, with animations off and with «reduce motion».
test('переход между страницами: через переход браузера, без заготовок и дублей; галочка и «уменьшить движение» выключают затухание', async ({ app }) => {
  await app.open('#/settings/look');
  await app.page.evaluate(() => {
    window.__vt = [];
    const orig = document.startViewTransition.bind(document);
    document.startViewTransition = (cb) => { window.__vt.push(document.documentElement.classList.contains('vt-cut') ? 'cut' : 'fade'); return orig(cb); };
  });
  const go = async (hash) => {
    const n = await app.page.evaluate(() => window.__vt.length);
    const dups = await app.page.evaluate((hs) => new Promise((done) => {
      let skel = false, two = false;
      const t0 = performance.now();
      const tick = () => {
        if (document.querySelector('main .skel')) skel = true;
        if (document.querySelectorAll('main h1').length > 1) two = true;
        if (performance.now() - t0 < 1500) requestAnimationFrame(tick); else done({ skel, two });
      };
      location.hash = hs; tick();
    }), hash);
    const kinds = await app.page.evaluate((k) => window.__vt.slice(k), n);
    return { ...dups, kind: kinds[0] };
  };
  let r = await go('#/sites');
  expect(r.skel, 'заготовки при переходе').toBe(false);
  expect(r.two, 'два заголовка — копия страницы в DOM').toBe(false);
  expect(r.kind).toBe('fade');
  r = await go('#/sites/user.list');
  expect(r.kind, 'та же страница — без затухания').toBe('cut');
  await go('#/settings/look');
  await lookBox(app, 'Анимации').uncheck();
  r = await go('#/sites');
  expect(r.kind, 'анимации выключены').toBe('cut');
  await go('#/settings/look');
  await lookBox(app, 'Анимации').check();
  await app.page.emulateMedia({ reducedMotion: 'reduce' });
  r = await go('#/sites');
  expect(r.kind, '«уменьшить движение»').toBe('cut');
});
