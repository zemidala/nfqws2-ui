// «Обзор» не шире окна (NFQ-L53): виды × стили × компоновки × ширины, страница без прокрутки вбок.
// Проверка идёт без режима телефона: в нём Chrome уменьшает страницу под её ширину, и innerWidth растёт вместе
// с вылезшим блоком — layout.spec на 390 px этого не видел (переключатель видов в стиле K был шире экрана).
import { test, expect } from '../fixtures.mjs';

const WIDTHS = [360, 390, 430, 480, 760, 900, 1100, 1280, 1366, 1440, 1760, 1920];
const VIEWS = ['brief', 'groups', 'tiles', 'masonry', 'panel'];

for (const variant of ['k', 'a']) for (const layout of ['menu', 'tabs', 'site']) {
  test(`«Обзор» не шире окна: стиль ${variant}, компоновка ${layout}`, async ({ app }) => {
    test.setTimeout(180_000);
    const bad = [];
    for (const overview of VIEWS) {
      await app.page.setViewportSize({ width: 1366, height: 800 });
      await app.open('#/', { variant, layout, overview });
      await app.page.waitForTimeout(800);   // блоки «Последние…» дорисовываются после ответа роутера
      for (const w of WIDTHS) {
        await app.page.setViewportSize({ width: w, height: 800 });
        await app.page.waitForTimeout(150);
        const over = await app.page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        if (over > 0) bad.push(`${overview} ${w}px: шире на ${over}px`);
      }
    }
    expect(bad, 'страница шире окна').toEqual([]);
  });
}
