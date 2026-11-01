// Вёрстка на телефоне, планшете и широком экране: ничего не уезжает за край, не обрезано, текст не зажат в столбик.
// Проверка общая (pw-lib/layout.mjs); задуманные исключения — в IGNORE.
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';

const PAGES = ['#/', '#/diag', '#/tests/freeze', '#/tests/dns', '#/tests/conns', '#/devices', '#/tests/configs', '#/tests/pick', '#/tests/trace', '#/tests/history', '#/tests/community', '#/tests/monitor',
  '#/tests/auto', '#/tests/notify', '#/settings', '#/settings/p1', '#/sites', '#/sites/user.list', '#/asn', '#/settings/basic', '#/settings/base',
  '#/settings/raw', '#/settings/backup', '#/settings/hist', '#/log', '#/settings/report', '#/settings/look', '#/settings/about'];
// полосы вкладок над страницей прокручиваются вбок намеренно
// редактор: подсветка (span.tk-*) шире окна и прокручивается вбок вместе с полем ввода — так задумано
const IGNORE = ['ПРОКРУТКА ВБОК nav.subtabs', 'span.tk-', 'span.iss'];   // span.iss — подсветка замечания в редакторе: строка прокручивается вбок, как и span.tk-

// 1920 и 2560 — широкий экран: там колонки «Мониторинга» и списков, колонка состояния справа (жалоба Эдика, #299)
for (const [w, h] of [[390, 844], [900, 1000], [1920, 1080], [2560, 1440]]) {
  test.describe(`ширина ${w}`, () => {
    test.use({ viewport: { width: w, height: h }, isMobile: w < 700, hasTouch: w < 700 });
    for (const hash of PAGES) {
      test(`${hash}`, async ({ app }) => {
        await app.open(hash);
        await app.page.waitForTimeout(800);   // часть блоков дорисовывается после ответа роутера
        const issues = await auditLayout(app.page, { ignore: IGNORE });
        expect(issues, `замечания к вёрстке ${hash} на ${w}px`).toEqual([]);
        // ячейка таблицы с display:flex перестаёт быть ячейкой: кнопки отрываются от строки (так было в «Резервных копиях»)
        // a column hidden on a narrow screen (display: none) is fine — only a cell turned into something else is wrong
        // .conn-tbl, .dnsx-tbl and .cardy (any wide table, cardTable() in app.js) turn rows into cards on a phone on purpose
        const cells = await app.page.$$eval('table:not(.conn-tbl, .dnsx-tbl, .cardy) td, table:not(.conn-tbl, .dnsx-tbl, .cardy) th', (els) => els.filter((e) => !['table-cell', 'none'].includes(getComputedStyle(e).display)).map((e) => e.outerHTML.slice(0, 80)));
        expect(cells, `ячейки таблиц ${hash} на ${w}px`).toEqual([]);
      });
    }
  });
}
