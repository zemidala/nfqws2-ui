// Run with PW_FONTS=1. Every text of style Z is drawn with a real face: its weight is loaded, nothing is painted by the browser.
import { test, expect } from '../fixtures.mjs';

const PAGES = ['#/', '#/settings/basic', '#/settings/p1', '#/sites', '#/tests', '#/tests/history', '#/tests/dns', '#/tests/monitor', '#/settings/raw', '#/log'];

test('Z: только настоящие начертания шрифтов', async ({ app, page }) => {
  test.skip(!process.env.PW_FONTS, 'только с настоящими шрифтами (PW_FONTS=1)');
  test.setTimeout(180_000);
  await page.setViewportSize({ width: 1366, height: 900 });
  const all = new Map();
  for (const hash of PAGES) {
    await app.open(hash, { variant: 'z', mode: 'dark' });
    await page.reload();
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(1500);
    const r = await page.evaluate(async () => {
      await document.fonts.ready;
      const faces = {};
      for (const f of document.fonts) if (f.status === 'loaded') (faces[f.family.replace(/"/g, '')] ||= new Set()).add(f.weight);
      const out = {};
      for (const e of document.querySelectorAll('body *')) {
        if (![...e.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim()) || !e.getBoundingClientRect().width) continue;
        const s = getComputedStyle(e);
        const fam = s.fontFamily.split(',')[0].replace(/"/g, '').trim();
        if (!faces[fam]?.has(s.fontWeight)) out[`${fam} ${s.fontWeight}`] = e.textContent.trim().slice(0, 18);
      }
      return { n: Object.keys(faces).length, faces: Object.fromEntries(Object.entries(faces).map(([k, v]) => [k, [...v].sort().join(',')])), out, synth: getComputedStyle(document.documentElement).fontSynthesisWeight };
    });
    if (hash === '#/') console.log('FACES', JSON.stringify(r.faces), r.synth);
    expect(r.n).toBeGreaterThan(0);
    for (const [k, v] of Object.entries(r.out)) all.set(k, `${hash} «${v}»`);
  }
  console.log('FAUX', JSON.stringify([...all]));
  expect([...all]).toEqual([]);
});
