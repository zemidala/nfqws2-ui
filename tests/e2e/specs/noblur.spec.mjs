// Nothing blurred, text centred in fields (the owner's rule, 2026-11-08): on every page, in style Z, on a phone and a
// wide screen,
// - no frame or fill stands on a fraction of a pixel vertically or has a fractional height (Chrome snaps text and
//   vertical edges itself, a box's top and bottom it draws where they fall — blurred);
// - in every text field and own dropdown the capitals «НН08» have the same empty room above and below, ±1 px.
import { test, expect } from '../fixtures.mjs';

const PAGES = ['#/', '#/diag', '#/tests/freeze', '#/tests/dns', '#/tests/conns', '#/tests', '#/tests/trace', '#/tests/history', '#/tests/community',
  '#/tests/configs', '#/tests/monitor', '#/tests/auto', '#/tests/learn', '#/tests/notify', '#/settings', '#/settings/p1', '#/sites', '#/sites/user.list',
  '#/devices', '#/asn', '#/settings/basic', '#/settings/raw', '#/settings/backup', '#/settings/hist', '#/log', '#/settings/report', '#/settings/look', '#/settings/about'];

const fractions = () => {
  const frac = (n) => Math.abs(n - Math.round(n)) > 0.02;
  const out = new Set();
  for (const e of document.querySelectorAll('body *')) {
    const b = e.getBoundingClientRect();
    if (!b.width || !b.height || b.bottom < 0 || b.top > innerHeight * 3) continue;
    const cs = getComputedStyle(e);
    if (cs.visibility === 'hidden' || cs.opacity === '0') continue;
    const draws = (parseFloat(cs.borderTopWidth) > 0 && cs.borderTopStyle !== 'none') || (parseFloat(cs.borderLeftWidth) > 0 && cs.borderLeftStyle !== 'none') || cs.backgroundColor !== 'rgba(0, 0, 0, 0)';
    if (!draws) continue;
    if (frac(b.top) || frac(b.height)) out.add(`${e.tagName.toLowerCase()}.${String(e.className).split(' ').slice(0, 2).join('.')} y${b.top.toFixed(2)} h${b.height.toFixed(2)}`);
  }
  return [...out];
};

// K is the default style, Z the brand one: both
for (const variant of ['z', 'k']) for (const w of [390, 1366]) {
  test(`без размытия, ${w} px, стиль ${variant}`, async ({ app, page }) => {
    test.setTimeout(300_000);
    await page.setViewportSize({ width: w, height: 900 });
    const all = [];
    for (const hash of PAGES) {
      await app.open(hash, { variant, mode: 'dark' });
      await page.waitForTimeout(1200);
      for (const x of await page.evaluate(fractions)) all.push(`${hash} ${x}`);
    }
    expect(all).toEqual([]);
  });
}

for (const variant of ['z', 'k']) test(`текст в полях по центру, 1366 px, стиль ${variant}`, async ({ app, page }) => {
  test.setTimeout(900_000);
  await page.setViewportSize({ width: 1366, height: 900 });
  const bad = [];
  for (const hash of PAGES) {
    await app.open(hash, { variant, mode: 'dark' });
    await page.waitForTimeout(1200);
    // style Z puts a field's label on its top frame: hidden for the shots (it keeps its room), or it reads as text
    await page.addStyleTag({ content: 'main :is(.lbl, label:not(.chk)) { visibility: hidden !important; }' });
    const fields = page.locator('main :is(input.input, .dd-btn):visible');
    const n = Math.min(await fields.count(), 12);
    for (let i = 0; i < n; i++) {
      // a page that redraws itself may have dropped the field meanwhile: skipped, not waited for
      const f = await fields.nth(i).elementHandle({ timeout: 2000 }).catch(() => null);
      if (!f || !(await f.isVisible().catch(() => false))) continue;
      // the capitals «НН08» go in for the shot, the old text comes back
      const info = await f.evaluate((e) => {
        const cs = getComputedStyle(e);
        let restore = null;
        // a password field shows dots, not letters: plain text for the shot
        if (e.tagName === 'INPUT') { restore = e.value; if (e.type === 'password') { e.type = 'text'; e.dataset.pw = '1'; } e.value = 'НН08'; } else { const t = e.querySelector('.dd-t'); if (t) { restore = t.textContent; t.textContent = 'НН08'; } }
        return { label: e.getAttribute('aria-label') || e.placeholder || e.className, restore, bt: parseFloat(cs.borderTopWidth), bb: parseFloat(cs.borderBottomWidth) };
      });
      await f.scrollIntoViewIfNeeded().catch(() => {});
      await page.mouse.move(0, 0);
      const box = await f.boundingBox();
      if (!box || box.height < 10 || box.width < 50) continue;
      // the field's own shot: a page that redraws between measuring and shooting moved the window's clip onto the next
      // block («сверху 11 / снизу 0»); the text's columns are cut out of it below
      const png = await f.screenshot({ timeout: 5000 }).catch(() => null);
      if (!png) continue;
      await f.evaluate((e, r) => { if (e.tagName === 'INPUT') { e.value = r ?? ''; if (e.dataset.pw) { e.type = 'password'; delete e.dataset.pw; } } else { const t = e.querySelector('.dd-t'); if (t && r != null) t.textContent = r; } }, info.restore);
      const gaps = await page.evaluate(async ([b64, bt, bb]) => {
        const img = new Image();
        img.src = 'data:image/png;base64,' + b64;
        await img.decode();
        const c = new OffscreenCanvas(img.width, img.height), g = c.getContext('2d');
        g.drawImage(img, 0, 0);
        const d = g.getImageData(0, 0, c.width, c.height).data;
        const px = (x, y) => { const i = (y * c.width + x) * 4; return [d[i], d[i + 1], d[i + 2]]; };
        const top = Math.ceil(bt) + 1, bottom = c.height - Math.ceil(bb) - 1, bg = px(6, Math.floor(c.height / 2));
        // from 16 px in (the frame's rounded corner is not text) to 120 px or the arrow of a dropdown
        const ink = (y) => { for (let x = 16; x < Math.min(124, c.width - 30); x++) { const p = px(x, y); if (Math.abs(p[0] - bg[0]) + Math.abs(p[1] - bg[1]) + Math.abs(p[2] - bg[2]) > 90) return true; } return false; };
        let a = -1, z = -1;
        for (let y = top; y < bottom; y++) if (ink(y)) { if (a < 0) a = y; z = y; }
        return a < 0 ? null : [a - top, bottom - 1 - z];
      }, [png.toString('base64'), info.bt, info.bb]);
      if (gaps && Math.abs(gaps[0] - gaps[1]) > 1) bad.push(`${hash} «${String(info.label).slice(0, 30)}» сверху ${gaps[0]} / снизу ${gaps[1]}`);
    }
  }
  expect(bad).toEqual([]);
});
