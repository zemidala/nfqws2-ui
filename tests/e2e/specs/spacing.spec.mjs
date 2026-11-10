// One spacing grid (rhythm.css, local/design/spacing.html): every two neighbours one under the other stand 4, 8, 16 or
// 24 px apart — on every page, in styles Z and K, on a computer and on a phone. 2026-11-10: the same pair (a field and
// its explanation, two panels) stood 2, 6, 14 or 38 px apart from page to page. Only block boxes are measured: an
// inline box is its letters, not its line. What stands apart on purpose is in MAY_DIFFER.
import { test, expect } from '../fixtures.mjs';

const PAGES = ['#/', '#/diag', '#/tests/freeze', '#/tests/dns', '#/tests/conns', '#/tests', '#/tests/trace', '#/tests/history', '#/tests/community',
  '#/tests/configs', '#/tests/monitor', '#/tests/auto', '#/tests/learn', '#/tests/notify', '#/settings', '#/settings/p1', '#/sites', '#/sites/user.list',
  '#/devices', '#/asn', '#/settings/basic', '#/settings/base', '#/settings/raw', '#/settings/backup', '#/settings/hist', '#/log', '#/settings/report', '#/settings/look', '#/settings/about'];
const GRID = [4, 8, 16, 24];

const MAY_DIFFER = [
  / input\.input → span\.hint «Через какой интерфейс/,   // «Интерфейс провайдера»: the list of interfaces stands between the field and its note
];

const collect = () => {
  const vis = (e) => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0 && getComputedStyle(e).visibility !== 'hidden'; };
  const name = (e) => `${e.tagName.toLowerCase()}${typeof e.className === 'string' && e.className.trim() ? '.' + e.className.trim().split(/\s+/)[0] : ''}`;
  const out = [];
  // the containers the grid lays out; lists of rows (log lines, events, menus, table rows) touch or keep their own step
  const LAID = 'main, main :is(.mcol, .mwrap, .stack, .panel, .blk, .frow, details, .group, .seq, .step-main, .lhead, .cards2, .two, .ov-pair, .ov-tiles, .item-main)';
  for (const parent of document.querySelectorAll(LAID)) {
    const s = getComputedStyle(parent);
    // a column of parts: block flow, a grid or a column flex; a wrapping row is a line of controls, its lines are not parts
    if (!['block', 'grid', 'flow-root'].includes(s.display) && !(s.display === 'flex' && s.flexDirection.startsWith('column'))) continue;
    if (parent.style.gap === '0px' || parent.style.gap === '0') continue;   // a seamless list: rows touch on purpose
    const kids = [...parent.children].filter((e) => vis(e) && !getComputedStyle(e).display.startsWith('inline') && getComputedStyle(e).position !== 'absolute');
    for (let i = 1; i < kids.length; i++) {
      const a = kids[i - 1], z = kids[i], ra = a.getBoundingClientRect(), rz = z.getBoundingClientRect();
      if (rz.top < ra.bottom - 1 || rz.left >= ra.right || rz.right <= ra.left) continue;   // not one under the other
      if (s.display === 'grid' && s.gridTemplateColumns.split(' ').length > 1 && Math.abs(rz.left - ra.left) > 1) continue;   // another column of a grid
      out.push({ gap: Math.round(rz.top - ra.bottom), what: `${name(parent)}: ${name(a)} → ${name(z)} «${z.textContent.trim().replace(/\s+/g, ' ').slice(0, 30)}»` });
    }
  }
  return out;
};

for (const variant of ['z', 'k']) for (const width of [1366, 390]) {
  test(`отступы по сетке 4 / 8 / 16 / 24: стиль ${variant}, ${width} px`, async ({ app, page }) => {
    test.setTimeout(600_000);
    await page.setViewportSize({ width, height: 900 });
    const bad = [];
    for (const hash of PAGES) {
      await app.open(hash, { variant, mode: 'dark' });
      await page.waitForFunction(() => S.shownHash === location.hash, null, { timeout: 30_000 });
      await page.waitForTimeout(800);
      for (const x of await page.evaluate(collect)) {
        const line = `${hash} ${x.gap}px ${x.what}`;
        if (!GRID.includes(x.gap) && !MAY_DIFFER.some((re) => re.test(line))) bad.push(line);
      }
    }
    expect(bad).toEqual([]);
  });
}
