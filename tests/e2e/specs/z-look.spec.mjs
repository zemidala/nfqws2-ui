// Style Z: surfaces with visible steps, the current menu item, tiles on the overview, both themes and widths.
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

const PAGES = ['#/', '#/settings/p1', '#/sites', '#/tests', '#/settings/look', '#/settings/about', '#/log'];

for (const mode of ['light', 'dark']) {
  test(`Z ${mode}: шрифты, поверхности, меню и плитки`, async ({ app, page }) => {
    await page.setViewportSize({ width: 1366, height: 900 });
    await app.open('#/', { variant: 'z', mode });
    await page.waitForTimeout(800);
    const r = await page.evaluate(() => {
      const c = (s) => getComputedStyle(document.querySelector(s));
      const root = getComputedStyle(document.documentElement);
      return { sans: root.getPropertyValue('--sans'), bg: c('body').backgroundColor, h1: c('main h1').fontFamily,
        on: { shadow: c('.mnav a.on').boxShadow, weight: c('.mnav a.on').fontWeight, bg: c('.mnav a.on').backgroundColor },
        head: { size: c('.mnav .nav-h').fontSize, tick: getComputedStyle(document.querySelector('.mnav .nav-h'), '::before').backgroundColor, item: c('.mnav .mlinks a').fontSize },
        tiles: document.querySelectorAll('.ov-tiles .tile').length };
    });
    console.log(mode, JSON.stringify(r));
    expect(r.sans).toContain('Golos Text');
    expect(r.h1).toContain('Geologica');
    expect(r.bg).toBe(mode === 'dark' ? 'rgb(7, 8, 11)' : 'rgb(245, 246, 249)');
    // the menu, local/design/bbb.html: the open page is a step of the fill, not bold, no bar; a section is an orange
    // tick and 15 px text over 13 px items
    expect(r.on.shadow).toBe('none');
    expect(r.on.weight).toBe('400');
    expect(r.on.bg).toBe(mode === 'dark' ? 'rgb(26, 30, 40)' : 'rgb(235, 238, 245)');
    expect(r.head).toEqual({ size: '15px', tick: 'rgb(255, 106, 20)', item: '13px' });
    expect(r.tiles).toBeGreaterThan(3);
  });
}

// what the layout and contrast checks cannot see: the signs of a template. A bar on the left is kept by the menu, the
// notes (.notice), the explanations (an «i» in their margin) and the DNS legend
const signs = () => {
  const out = new Set();
  for (const e of document.querySelectorAll('body *')) {
    const b = e.getBoundingClientRect();
    if (!b.width || !b.height || e.closest('.mnav, .notice, .dnsx-legend')) continue;
    const b4 = getComputedStyle(e, '::before');
    if ((b4.maskImage || b4.webkitMaskImage || '').includes('svg')) continue;
    const s = getComputedStyle(e);
    const name = `${e.tagName.toLowerCase()}.${String(e.className.baseVal ?? e.className).split(' ').slice(0, 2).join('.')}`;
    if (/inset \d+px 0(px)? /.test(s.boxShadow) || /rgba?\([^)]*\) \d+px 0px 0px 0px inset/.test(s.boxShadow)) { if (!/ 0px 0px 0px \d+px inset/.test(s.boxShadow)) out.add(`полоса сбоку: ${name}`); }
    const left = parseFloat(s.borderLeftWidth);
    if (left >= 2 && s.borderLeftStyle !== 'none' && parseFloat(s.borderRightWidth) < left && s.borderLeftColor !== 'rgba(0, 0, 0, 0)' && b.height > 16) out.add(`полоса сбоку: ${name}`);
    if ([...e.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim().length > 2)) {
      if (s.textTransform === 'uppercase') out.add(`капс: ${name} «${e.textContent.trim().slice(0, 20)}»`);
      if (parseFloat(s.letterSpacing) > 0.5) out.add(`разрядка: ${name}`);
    }
  }
  return [...out];
};

for (const mode of ['light', 'dark']) {
  test(`Z ${mode}: нет полос сбоку, капса и разрядки`, async ({ app, page }) => {
    test.setTimeout(120_000);
    await page.setViewportSize({ width: 1366, height: 900 });
    const all = new Set();
    for (const hash of [...PAGES, '#/tests/dns', '#/tests/monitor', '#/settings/basic', '#/settings/backup', '#/devices', '#/settings/raw']) {
      await app.open(hash, { variant: 'z', mode });
      await page.waitForTimeout(500);
      for (const x of await page.evaluate(signs)) all.add(`${hash} ${x}`);
    }
    console.log(`приметы ${mode}: ${all.size}\n${[...all].join('\n')}`);
    expect([...all]).toEqual([]);
  });
}

test('Z: подсказка без полосы', async ({ app, page }) => {
  await page.setViewportSize({ width: 1366, height: 900 });
  await app.open('#/', { variant: 'z', mode: 'dark' });
  await page.locator('main [data-tip], .top-in [data-tip]').first().hover();
  const tip = page.locator('.ui-tip');
  await expect(tip).toBeVisible();
  expect(await tip.evaluate((e) => getComputedStyle(e).boxShadow)).not.toContain('inset');
});

for (const [w, ht] of [[390, 844], [1366, 900], [1697, 922]]) {
  test(`Z: раскладка страниц, ${w}px`, async ({ app, page }) => {
    test.setTimeout(120_000);
    await page.setViewportSize({ width: w, height: ht });
    const out = [];
    for (const mode of ['light', 'dark']) {
      for (const hash of PAGES) {
        await app.open(hash, { variant: 'z', mode });
        await page.reload();   // a change of the hash alone does not reload the page, so the theme would stay
        await page.waitForTimeout(700);
        expect(await page.evaluate(() => document.documentElement.dataset.mode)).toBe(mode);
        const a = await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs', 'span.tk-', 'span.iss'] });
        if (a.length) out.push(`${mode} ${hash}: ${a.slice(0, 3).join(' | ')}`);
      }
    }
    console.log(`${w}: ${out.length ? out.join('\n') : 'чисто'}`);
    expect(out).toEqual([]);
  });
}
