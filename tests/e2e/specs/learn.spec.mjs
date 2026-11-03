// «Что выучил роутер» (NFQ-L222, макет local/design/learn.html, вариант А). Ответы learn_get / learn_forget подменены.
import { test, expect } from '../fixtures.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

const now = Math.floor(Date.now() / 1000);
const S1 = { steps: ['--lua-desync=hostfakesplit:repeats=4:tcp_ts=-600000:tcp_md5:host=ya.ru'], name: 'hostfakesplit ×4 tcp_ts,tcp_md5 ya.ru' };
const S2 = { steps: ['--lua-desync=multisplit:pos=1,midsld'], name: 'multisplit pos=1,midsld' };
const S3 = { steps: ['--lua-desync=fake:blob=tls_clienthello:tls_mod=rnd,dupsid,sni=fonts.google.com'], name: 'fake sni=fonts.google.com' };
const strat = (n, s, ok, fail, last) => ({ n, ok, fail, score: (ok + 1) / (ok + fail + 2), last, ...s });
const DATA = { module: true, updated: now - 120, items: [
  { key: 'learn_2_1', host: 'king.hr', profile: 2, cur: 3, strategies: [strat(1, S1, 0, 2, now - 90000), strat(2, S2, 0, 1, now - 89000), strat(3, S3, 3, 1, now - 80000)] },
  { key: 'learn_2_1', host: 'rutracker.org', profile: 2, cur: 1, strategies: [strat(1, S1, 14, 0, now - 600), strat(2, S2, 0, 2, now - 7000), strat(3, S3, 0, 0, null)] },
  { key: 'learn_2_1', host: 'very-long-subdomain.of.a-site-with-a-long-name.example.com', profile: 2, cur: 2, strategies: [strat(1, S1, 0, 1, now - 300), strat(2, S2, 6, 0, now - 200), strat(3, S3, 0, 0, null)] },
] };

for (const w of [390, 900, 1366]) {
  test(`таблица сайтов, раскрытие, «Забыть», ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    const sent = [];
    await app.mock((b) => {
      if (b.cmd === 'learn_get') return DATA;
      if (b.cmd === 'learn_forget') { sent.push(b); return { ...DATA, items: DATA.items.filter((x) => x.host !== b.host) }; }
    });
    await app.open('#/tests/learn', { variant: 'k' });
    const rows = page.locator('.learn-tbl tbody tr:not(.lmore)');
    await expect(rows).toHaveCount(3);
    const rt = rows.filter({ hasText: 'rutracker.org' });
    await expect(rt).toContainText('hostfakesplit');
    await expect(rt).toContainText('94 %');
    await expect(rt).toContainText('14 / 0');
    await rt.locator('.lex').click();
    const more = page.locator('.learn-tbl tr.lmore');
    await expect(more).toContainText('не пробовалась');
    await expect(more).toContainText('сейчас');
    await page.waitForTimeout(300);
    await page.screenshot({ path: test.info().outputPath(`learn-${w}.png`), fullPage: true });
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
    // nor inside the table's block: no sideways scrolling anywhere
    expect(await page.locator('.learn-tbl').evaluate((t) => { const s = t.closest('.scroll'); return s.scrollWidth - s.clientWidth; })).toBeLessThanOrEqual(0);
    await more.getByRole('button', { name: 'Забыть' }).click();
    await app.yes();
    await expect.poll(() => sent.length).toBe(1);
    expect(sent[0]).toMatchObject({ key: 'learn_2_1', host: 'rutracker.org' });
    await expect(rows).toHaveCount(2);
  });
}

test('пусто — объяснение, как включить обучение', async ({ app, page }) => {
  await app.mock((b) => { if (b.cmd === 'learn_get') return { module: false, updated: null, items: [] }; });
  await app.open('#/tests/learn', { variant: 'k' });
  await expect(page.locator('main')).toContainText('Пока ничего не выучено');
  await expect(page.locator('main')).toContainText('Модуля обучения на роутере нет');
});
