// What a layout audit does not see: every icon against its text, every row of controls against itself, in style Z.
import { test, expect } from '../fixtures.mjs';

const even = () => {
  const out = new Map();
  const add = (k) => out.set(k, (out.get(k) || 0) + 1);
  const name = (e) => `${e.tagName.toLowerCase()}.${String(e.className.baseVal ?? e.className).split(' ').slice(0, 2).join('.')}`;
  const textBox = (n) => { const r = document.createRange(); r.selectNodeContents(n); return r.getClientRects()[0]; };
  const firstText = (e) => { const w = document.createTreeWalker(e, NodeFilter.SHOW_TEXT); let n; while ((n = w.nextNode())) if (n.textContent.trim()) return n; return null; };
  for (const svg of document.querySelectorAll('main svg, main .lvl')) {
    const b = svg.getBoundingClientRect();
    if (!b.width || b.width > 30 || svg.closest('.chips')) continue;   // the cross of a chip and the plus button stand apart from any text
    let host = svg.parentElement, t = null;
    const cell = svg.closest('td');
    if (cell && !firstText(cell) && cell.nextElementSibling) t = firstText(cell.nextElementSibling);
    else t = firstText(host) || (host.parentElement && firstText(host.parentElement));
    if (!t) continue;
    const r = textBox(t);
    if (!r || Math.abs(r.left - b.right) > 60 || r.left < b.left) continue;
    const d = (b.top + b.bottom) / 2 - (r.top + r.bottom) / 2;
    if (Math.abs(d) > 2) add(`значок ${Math.round(d)}px от текста: ${name(svg.closest('td, li, div, a, span'))} «${t.textContent.trim().slice(0, 18)}»`);
  }
  for (const p of document.querySelectorAll('main *')) {
    if (p.matches('.vh')) continue;   // a status chip beside the page title is a label, not a control of the row
    const kids = [...p.children].filter((c) => c.matches('input.input, select, .btn, .dd-btn, .chip, .pchip') && c.getBoundingClientRect().height);
    if (kids.length < 2) continue;
    const rows = new Map();
    for (const c of kids) { const b = c.getBoundingClientRect(); const k = Math.round((b.top + b.bottom) / 2 / 24); (rows.get(k) || rows.set(k, []).get(k)).push([c, b]); }
    for (const row of rows.values()) {
      if (row.length < 2) continue;
      const hs = [...new Set(row.map(([, b]) => Math.round(b.height)))];
      if (Math.max(...hs) - Math.min(...hs) > 2) add(`разная высота ${hs.join('/')}: ${name(p)} [${row.map(([c]) => name(c)).join(' ')}]`);
    }
  }
  return [...out].map(([k, n]) => `${k}${n > 1 ? ' ×' + n : ''}`);
};

const PAGES = ['#/', '#/settings/p1', '#/settings/p3', '#/sites', '#/tests', '#/tests/history', '#/tests/phist', '#/tests/dns', '#/tests/freeze', '#/tests/conns', '#/tests/monitor', '#/settings/basic', '#/settings/backup', '#/settings/look', '#/devices', '#/log', '#/tests/community', '#/tests/auto'];

for (const w of [390, 1366]) {
  test(`Z: значки и ряды ровные, ${w}px`, async ({ app, page }) => {
    test.setTimeout(180_000);
    await page.setViewportSize({ width: w, height: 900 });
    const all = [];
    for (const hash of PAGES) {
      await app.open(hash, { variant: 'z', mode: 'dark' });
      await page.waitForTimeout(900);
      const r = await page.evaluate(even);
      if (r.length) all.push(`${hash}: ${r.join(' | ')}`);
    }
    console.log(`EVEN ${w}: ${all.length ? '\n' + all.join('\n') : 'ровно'}`);
    expect(all).toEqual([]);
  });
}
