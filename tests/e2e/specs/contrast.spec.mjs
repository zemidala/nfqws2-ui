// Контраст текста по APCA (WCAG 2 — для справки) на всех страницах, в четырёх стилях и двух темах.
// Это обзор, а не проверка «прошло / нет»: итог — test-results/contrast/<стиль>-<тема>.json, сводка — в выводе.
// Запуск (по требованию, в общем прогоне пропускается): set PW_CONTRAST=1 && pw.py nfqws2-ui/local/e2e --project=router -g контраст
import { test } from '../fixtures.mjs';
import { auditContrast } from 'pw-lib/contrast.mjs';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';

const PAGES = process.env.PW_CONTRAST_PAGES ? process.env.PW_CONTRAST_PAGES.split(',') : ['#/', '#/diag', '#/tests/freeze', '#/tests/dns', '#/devices', '#/tests/configs', '#/tests/pick', '#/tests/trace', '#/tests/history', '#/tests/community', '#/tests/monitor',
  '#/tests/auto', '#/tests/notify', '#/settings', '#/settings/p1', '#/sites', '#/sites/user.list', '#/asn', '#/settings/basic', '#/settings/base',
  '#/settings/raw', '#/settings/backup', '#/settings/hist', '#/log', '#/settings/report', '#/settings/look', '#/settings/about'];
const OUT = path.join(import.meta.dirname, '..', 'test-results', 'contrast');

test.skip(!process.env.PW_CONTRAST, 'обзор контраста — только по требованию (PW_CONTRAST=1)');

for (const variant of ['z', 'k', 'a', 'b', 'c']) {
  for (const mode of ['light', 'dark']) {
    test(`контраст: стиль ${variant}, тема ${mode}`, async ({ app }) => {
      test.setTimeout(240_000);
      await app.page.setViewportSize({ width: 1366, height: 900 });
      const all = new Map();
      for (const hash of PAGES) {
        // PW_SCHEME=night — every style but z in that colour scheme
        await app.open(hash, { variant, mode, ...(process.env.PW_SCHEME ? { scheme: process.env.PW_SCHEME } : {}) });
        await app.page.waitForTimeout(700);
        const { items } = await auditContrast(app.page);
        for (const g of items) {
          const key = `${g.txt}|${g.bg}|${g.px}|${g.weight >= 600 ? 'b' : 'n'}`;
          const x = all.get(key) || { ...g, count: 0, pages: [] };
          x.count += g.count;
          if (x.pages.length < 5 && !x.pages.includes(hash)) x.pages.push(hash);
          all.set(key, x);
        }
      }
      const list = [...all.values()].sort((a, b) => (Math.abs(a.lc) - a.need) - (Math.abs(b.lc) - b.need));
      mkdirSync(OUT, { recursive: true });
      writeFileSync(path.join(OUT, `${variant}-${mode}.json`), JSON.stringify(list, null, 1));
      const bad = list.filter((g) => !g.ok);
      console.log(`${variant}-${mode}: сочетаний ${list.length}, ниже порога APCA ${bad.length}, по WCAG 2 ниже нормы ${list.filter((g) => !g.wcagOk).length}`);
    });
  }
}
