// NFQ-L240: the «Имя» field in the text view of a profile, tied to the --name line. Nothing is saved.
// NFQ-L241: a gap between the messages of «Диагноз блокировки».
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';
import { contrastReport } from 'pw-lib/contrast.mjs';
import { Z_ACCEPTED } from '../z-palette.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

for (const mode of ['light', 'dark']) {
  test(`профиль, «Текст»: поле «Имя» и строка --name идут вместе (${mode})`, async ({ app, page }) => {
    for (const w of [390, 1366]) {
      await page.setViewportSize({ width: w, height: 900 });
      await app.open('#/settings/p1', { variant: 'a', mode });
      await page.locator('main .vh').getByRole('button', { name: 'Текст' }).click();
      const field = page.getByLabel('Имя профиля');
      const text = page.locator('main .ed-ta');
      await expect(field).toBeVisible();
      const before = await text.inputValue();
      const was = await field.inputValue();

      await field.fill('e2e_name');
      await expect(text).toHaveValue(/^--name=e2e_name$/m);
      await expect(field).toBeFocused();
      await expect(text).toHaveValue(new RegExp(before.split('\n').filter((l) => !l.startsWith('--name=')).slice(-1)[0].replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));

      await text.fill((await text.inputValue()).replace('--name=e2e_name', '--name=from_text'));
      await expect(field).toHaveValue('from_text');

      await field.fill('');
      await expect(text).not.toHaveValue(/--name=/);

      await field.fill('e2e_second');
      expect((await text.inputValue()).match(/--name=/g)).toHaveLength(1);

      await field.fill('с пробелом');
      await expect(page.locator('main .hint.error')).toBeVisible();
      await expect(text).toHaveValue(/^--name=e2e_second$/m);

      await field.fill(was);
      // the editor scrolls sideways on its own, so its text may run past the edge; the editor itself and the page may not
      expect(await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs', 'span.tk-', 'span.iss'] })).toEqual([]);
      const box = await page.evaluate(() => {
        const r = (el) => { const b = el.getBoundingClientRect(); return { l: b.left, r: b.right, t: b.top, b: b.bottom, h: b.height }; };
        return { ed: r(document.querySelector('main .ed')), name: r(document.querySelector('main [data-fk="name"]')),
          row: r(document.querySelector('main .panel > .row')), scroll: document.documentElement.scrollWidth, win: innerWidth };
      });
      console.log(`w=${w} ${mode}`, JSON.stringify(box));
      expect(box.ed.l).toBeGreaterThanOrEqual(0);
      expect(box.ed.r).toBeLessThanOrEqual(w);
      expect(box.scroll).toBeLessThanOrEqual(box.win);
      expect(box.name.b).toBeLessThanOrEqual(box.row.t);
      expect(await contrastReport(page, { ignore: Z_ACCEPTED })).toEqual([]);
      await page.getByRole('button', { name: 'Отменить' }).click({ timeout: 2000 }).catch(() => {});
    }
  });
}

test('диагноз: между выводом и блоком «Если на устройстве…» есть отступ', async ({ app, page }) => {
  test.setTimeout(120_000);
  for (const w of [390, 1366]) {
    await page.setViewportSize({ width: w, height: 900 });
    await app.open('#/diag?host=ya.ru', { variant: 'a' });
    const next = page.locator('.diag-next');
    await expect(next).toBeVisible({ timeout: 90_000 });
    const gap = await next.evaluate((el) => el.getBoundingClientRect().top - el.previousElementSibling.getBoundingClientRect().bottom);
    expect(gap).toBe(10);
  }
});
