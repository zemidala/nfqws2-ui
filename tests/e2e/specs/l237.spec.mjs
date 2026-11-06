// NFQ-L238: «Включён / Выключен» in the profile head — --skip in the profile, nfqws2 keeps it and does not use it.
// NFQ-L237: «проверять как старые устройства (TLS 1.2)» in «Диагноз блокировки» — every step goes with tls12.
import { test, expect, ssh } from '../fixtures.mjs';
import { TARGETS } from '../playwright.config.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

// changes the config (no restart) and puts the file back byte for byte — only on the stand
test('профиль: «Выключен» пишет --skip, в списке отмечен, следующим профилям не мешает; «Включён» убирает', async ({ app, page, target }) => {
  test.skip(target === 'router', 'меняет конфиг — только на стендах');
  test.setTimeout(120_000);
  const conf = TARGETS[target].conf;
  const keep = '/tmp/l238-conf.bak';
  ssh(target, `cp -p ${conf} ${keep}`);
  try {
    for (const w of [390, 1366]) {
      await page.setViewportSize({ width: w, height: 900 });
      await app.open('#/settings/p1');
      const sw = page.locator('main .vh').getByRole('button', { name: 'Включён' });
      await expect(sw).toHaveAttribute('aria-pressed', 'true');
      expect(await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs'] })).toEqual([]);
      const src = (await app.api('state')).conf_profiles[0].source.source;
      const first = (vars) => (src === 'NFQWS_ARGS_CUSTOM' ? vars[src].split(/\n--new\n/)[0] : vars[src]);
      // a shared variable or errors already in the config ask first; a clean own profile does not
      const confirm = async (name) => {
        const ask = page.locator('.modal.ask').last();
        await expect(ask.or(page.locator('main .vh').getByRole('button', { name }))).toBeVisible({ timeout: 30_000 });
        if (await ask.isVisible()) await ask.locator('.modal-f .btn').last().click();
      };
      await sw.click();
      await confirm('Выключен');
      await expect(page.locator('main .vh').getByRole('button', { name: 'Выключен' })).toHaveAttribute('aria-pressed', 'false', { timeout: 30_000 });
      await expect(page.locator('main')).toContainText('Профиль выключен');
      expect(first((await app.api('conf_get')).vars)).toMatch(/(^|\s)--skip(\s|$)/);
      const st = await app.api('state');
      expect(st.conf_profiles[0].state).toBe('off');
      // nothing of the next profiles is «taken» by the switched-off one
      expect(st.conf_profiles.some((p) => (p.taken || []).some((t) => t.by === 1))).toBe(false);
      if (w > 760) await expect(page.locator('nav.lnav a').first().locator('.pn')).toHaveClass(/\boff\b/);
      expect(await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs'] })).toEqual([]);
      await page.locator('main .vh').getByRole('button', { name: 'Выключен' }).click();
      await confirm('Включён');
      await expect(page.locator('main .vh').getByRole('button', { name: 'Включён' })).toBeVisible({ timeout: 30_000 });
      expect(first((await app.api('conf_get')).vars)).not.toMatch(/(^|\s)--skip(\s|$)/);
      expect((await app.api('state')).conf_profiles[0].state).not.toBe('off');
    }
  } finally {
    ssh(target, `cp -p ${keep} ${conf} && rm -f ${keep}`);
  }
});

test('диагноз: галочка TLS 1.2 уходит в каждый шаг, заголовок говорит об этом, подбор открывается с tls12', async ({ app, page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1366, height: 900 });
  const steps = [];
  page.on('request', (q) => { if (q.method() === 'POST' && q.url().includes('api.php')) { try { const b = q.postDataJSON(); if (b.cmd === 'diag') steps.push(b); } catch { /* not json */ } } });
  await app.open('#/diag', { variant: 'k' });
  const box = page.locator('#d-tls12');
  await expect(box).not.toBeChecked();
  expect(await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs'] })).toEqual([]);
  await box.check();
  await page.locator('#d-host').fill('rutracker.org');
  await page.locator('main form').getByRole('button', { name: 'Проверить' }).click();
  await expect(page).toHaveURL(/tls12=1/);
  await expect(page.locator('main')).toContainText('Что проверено — по TLS 1.2');
  await expect(page.locator('main').getByRole('button', { name: 'Ещё раз' })).toBeVisible({ timeout: 90_000 });
  expect(steps.length).toBeGreaterThan(3);
  expect(steps.every((b) => b.tls12 === true)).toBe(true);
  await expect(page.locator('#d-tls12')).toBeChecked();
  const pick = page.locator('main').getByRole('button', { name: 'Подобрать стратегию' });
  if (await pick.count()) {
    await pick.click();
    await expect(page.locator('#t-tls12')).toBeChecked();
  }
});
