// Alignment review (run with PW_ALIGN=1): on every page, a checkbox / radio / switch and the text next to it share
// one vertical centre. Measures the control's box centre against the centre of the first line of its text.
// Read-only. Result: test-results/align/<variant>-<width>.json and a short summary in the log.
import { test } from '../fixtures.mjs';
import fs from 'node:fs';

const PAGES = ['#/', '#/diag', '#/tests/freeze', '#/tests/configs', '#/tests/pick', '#/tests/trace', '#/tests/history', '#/tests/community',
  '#/tests/monitor', '#/tests/auto', '#/tests/notify', '#/settings', '#/sites', '#/devices', '#/asn', '#/settings/basic', '#/settings/base',
  '#/settings/raw', '#/settings/backup', '#/settings/hist', '#/log', '#/settings/report', '#/settings/look', '#/settings/about'];
const TOL = 1;   // px: below that the eye does not see it

// in the page: every visible control with its first text line
const measure = () => {
  const firstLine = (root, skip) => {
    const w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, { acceptNode: (n) => n.textContent.trim() && !skip.contains(n) ? 1 : 3 });
    const n = w.nextNode();
    if (!n) return null;
    const r = document.createRange();
    r.selectNodeContents(n);
    const rects = [...r.getClientRects()].filter((x) => x.width > 0);
    return rects[0] ? { box: rects[0], text: n.textContent.trim().slice(0, 40) } : null;
  };
  const out = [];
  for (const c of document.querySelectorAll('main input[type=checkbox], main input[type=radio], main .sw[role=switch]')) {
    const b = c.getBoundingClientRect();
    if (!b.width || !b.height || getComputedStyle(c).visibility === 'hidden') continue;
    // the text that belongs to the control: its label, or the label/row it sits in
    const host = c.closest('label') || (c.id && document.querySelector(`label[for="${c.id}"]`)) || c.closest('.swl, .row, td, th');
    if (!host || host.tagName === 'TD' || host.tagName === 'TH') continue;   // table cells: the text is in another cell
    const t = firstLine(host, c);
    if (!t) continue;
    if (Math.abs(t.box.top - b.top) > 40) continue;   // text far away: not the control's own caption
    const dy = (b.top + b.height / 2) - (t.box.top + t.box.height / 2);
    out.push({ dy: Math.round(dy * 10) / 10, kind: c.type || 'switch', text: t.text, where: host.className || host.tagName.toLowerCase() });
  }
  // form rows «label — value» side by side: the label's first line against the value's first line
  // (a field or dropdown — its box centre; anything else — its first text line)
  const none = document.createElement('i');
  for (const row of document.querySelectorAll('main .frow')) {
    const lbl = row.querySelector(':scope > .lbl'), val = lbl?.nextElementSibling;
    if (!lbl || !val || val.classList.contains('hint')) continue;
    const lb = lbl.getBoundingClientRect(), vb = val.getBoundingClientRect();
    if (!lb.width || !vb.width || vb.left < lb.right || vb.top > lb.bottom) continue;   // stacked, not side by side
    const lt = firstLine(lbl, none);
    if (!lt) continue;
    const field = val.matches('input, select, textarea, .input, .select, .dd-btn') ? val : val.querySelector(':scope > .input, :scope > .select, :scope > .dd-btn');
    const isArea = field && (field.tagName === 'TEXTAREA' || field.querySelector?.('textarea'));
    const vc = field && !isArea ? (() => { const f = field.getBoundingClientRect(); return f.top + f.height / 2; })()
      : isArea ? (() => { const ta = field.tagName === 'TEXTAREA' ? field : field.querySelector('textarea'); const f = ta.getBoundingClientRect(), cs = getComputedStyle(ta);
        return f.top + parseFloat(cs.borderTopWidth) + parseFloat(cs.paddingTop) + parseFloat(cs.lineHeight) / 2; })()   // a multi-line field: its first line
      : (() => { const t = firstLine(val, none); return t ? t.box.top + t.box.height / 2 : null; })();
    if (vc === null) continue;
    const dy = vc - (lt.box.top + lt.box.height / 2);
    out.push({ dy: Math.round(dy * 10) / 10, kind: 'row-label', text: lt.text, where: 'frow' });
  }
  return out;
};

for (const [variant, mode] of [['z', 'dark'], ['k', 'light'], ['a', 'dark']]) {
  for (const width of [1366, 390]) {
    test(`выравнивание: стиль ${variant}, ширина ${width}`, async ({ app, page }) => {
      test.skip(!process.env.PW_ALIGN, 'обзор выравнивания — только с PW_ALIGN=1');
      test.setTimeout(300_000);
      await page.setViewportSize({ width, height: 900 });
      const bad = [];
      for (const hash of PAGES) {
        await app.open(hash, { variant, mode });
        await page.waitForTimeout(1200);
        for (const x of await page.evaluate(measure)) if (Math.abs(x.dy) > TOL) bad.push({ page: hash, ...x });
      }
      fs.mkdirSync('test-results/align', { recursive: true });
      fs.writeFileSync(`test-results/align/${variant}-${width}.json`, JSON.stringify(bad, null, 1));
      console.log(`${variant}-${mode}-${width}: смещено больше ${TOL} px — ${bad.length}`);
    });
  }
}
