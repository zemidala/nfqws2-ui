// «Делиться без GitHub» (NFQ-L224, макет local/design/share.html: А + окно из В). Приёмник подменён:
// share_info / share_send / share_get / share_set — ответы и то, что ушло, проверяются здесь.
import { test, expect } from '../fixtures.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

const INFO = { relay: true, asn: 64500, provider: 'Example ISP', target_asn: 24940, host_known: false, name: '', auto: false, repo: 'https://github.com/zemidala/nfqws2-strategies' };
const X = { name: 'hostfakesplit ×4', steps: ['--lua-desync=hostfakesplit:repeats=4:host=ya.ru'], ok: 3, tries: 3 };
const S = { host: 'king.hr', proto: 'tls' };

for (const w of [390, 1366]) {
  test(`«Ваш вклад»: числа, «Помогать базе», подпись, ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    const set = [];
    await app.mock((b) => {
      if (b.cmd === 'share_get') return { relay: true, auto: false, name: '', stats: { live: 3, waiting: 1, confirmed: 37, providers: 5 }, last: { t: Math.floor(Date.now() / 1000) - 3600, learned: 2, works: 3 }, queued: 0 };
      if (b.cmd === 'share_set') { set.push(b); return { ok: true }; }
    });
    await app.open('#/tests/community', { variant: 'k' });
    const p = page.locator('section.panel').filter({ hasText: 'Ваш вклад' });
    await expect(p).toContainText('4');
    await expect(p).toContainText('37');
    await expect(p).toContainText('роутеров подтвердили');
    await expect(p).toContainText('1 стратегия ждёт второго подтверждения');
    await p.getByText('Помогать базе').click();
    await expect.poll(() => set.length).toBe(1);
    expect(set[0]).toMatchObject({ auto: true });
    await p.getByRole('textbox', { name: 'Подпись в базе' }).fill('Vasya');
    await p.getByRole('textbox', { name: 'Подпись в базе' }).blur();
    await expect.poll(() => set.length).toBe(2);
    expect(set[1]).toMatchObject({ name: 'Vasya' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
    const out = await p.evaluate((el) => [...el.querySelectorAll('*')].filter((e) => e.getBoundingClientRect().right > el.getBoundingClientRect().right + 1).length);
    expect(out).toBe(0);
  });
}

test('«Поделиться» через приёмник: что уходит, имя частного сайта не уходит, кнопка → метка', async ({ app, page }) => {
  const sent = [];
  await app.mock((b) => {
    if (b.cmd === 'share_get') return { relay: true, auto: false, name: '', stats: null, last: null, queued: 0 };
    if (b.cmd === 'share_info') return INFO;
    if (b.cmd === 'share_send') { sent.push(b); return { ok: true, queued: 1 }; }
    if (b.cmd === 'share_set') return { ok: true };
  });
  await app.open('#/tests/community', { variant: 'k' });
  await page.evaluate(([x, s]) => { const b = shareBtn(x, s, true); b.id = 'sb'; document.querySelector('main').append(b); }, [X, S]);
  await page.locator('#sb').click();
  const dlg = page.locator('.modal');
  await expect(dlg).toContainText('Отправить стратегию в общую базу?');
  await expect(dlg).toContainText('AS64500');
  await expect(dlg).toContainText('сеть сайта — AS24940');
  await expect(dlg).toContainText('Имя сайта не уходит');
  await expect(dlg).toContainText('Дальше помогать базе самому');
  await dlg.getByRole('button', { name: 'Отправить' }).click();
  await expect.poll(() => sent.length).toBe(1);
  expect(sent[0]).toMatchObject({ kind: 'share', steps: X.steps, proto: 'tls', host: 'king.hr', net: 24940 });
  await expect(page.locator('main')).toContainText('отправлена · ждёт второго подтверждения');
  await expect(page.locator('#sb')).toHaveCount(0);
});

test('приёмник не ответил — в окне ошибка и запасной путь через GitHub', async ({ app, page }) => {
  await app.mock((b) => {
    if (b.cmd === 'share_get') return { relay: true, auto: false, name: '', stats: null, last: null, queued: 0 };
    if (b.cmd === 'share_info') return INFO;
    if (b.cmd === 'share_send') return { __status: 400, body: { error: 'Приёмник базы не ответил: код 502' } };
  });
  await app.open('#/tests/community', { variant: 'k' });
  await page.evaluate(([x, s]) => shareStrategy(x, s, null), [X, S]);
  const dlg = page.locator('.modal');
  await dlg.getByRole('button', { name: 'Отправить' }).click();
  await expect(dlg).toContainText('Не отправилось');
  await expect(dlg).toContainText('код 502');
  await dlg.getByRole('button', { name: 'Открыть форму на GitHub' }).click();
  await expect(page.locator('.modal')).toContainText('нужен аккаунт GitHub');
});

test('приёмника нет — как раньше, форма на GitHub', async ({ app, page }) => {
  await app.mock((b) => {
    if (b.cmd === 'share_get') return { relay: false };
    if (b.cmd === 'share_info') return { ...INFO, relay: false };
  });
  await app.open('#/tests/community', { variant: 'k' });
  await expect(page.locator('main')).not.toContainText('Ваш вклад');
  await page.evaluate(([x, s]) => shareStrategy(x, s, null), [X, S]);
  const dlg = page.locator('.modal');
  await expect(dlg.getByRole('button', { name: 'Открыть форму на GitHub' })).toBeEnabled();
  await expect(dlg).toContainText('нужен аккаунт GitHub');
});
