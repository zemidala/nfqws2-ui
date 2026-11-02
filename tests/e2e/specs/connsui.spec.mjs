// «Соединения устройства»: «Записать» ↔ «Остановить», порядок устройств, длительность и порядок запоминаются
// (Stanislav, #768–769). Ответы роутера подменены — запись не идёт.
import { test, expect } from '../fixtures.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

const DEVICES = { items: [
  { ip: '192.168.1.20', name: 'Телефон', flows: 5 }, { ip: '192.168.1.3', name: '', flows: 40 }, { ip: '192.168.1.115', name: 'ПК', flows: 12 },
], noacct: false };

for (const w of [390, 900, 1366]) {
  test(`кнопка, порядок и запоминание, ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    let state = {};
    const calls = [];
    await app.mock((b) => {
      if (b.cmd === 'conns_devices') return DEVICES;
      if (b.cmd === 'conns_get') return state;
      if (b.cmd === 'conns_start') { calls.push(b); state = { running: true, id: 'x', ip: b.ip, secs: b.secs, started: Date.now() / 1000, elapsed: 2, flows: [] }; return state; }
      if (b.cmd === 'conns_stop') { calls.push(b); state = { running: false, id: 'x', ip: state.ip, secs: state.secs, started: state.started, finished: Date.now() / 1000, elapsed: 4, stopped: true, flows: [] }; return state; }
    });
    // clean only on the first load of the test: the reload below must see what the page saved
    await page.addInitScript(() => { if (!sessionStorage.getItem('e2e-conns')) { localStorage.removeItem('nfqws-ui-conns'); sessionStorage.setItem('e2e-conns', '1'); } });
    await app.open('#/tests/conns', { variant: 'k' });
    const form = page.locator('.conn-form');
    const devBtn = form.locator('.dd-btn[aria-label="Устройство"]');
    // default order: most connections first
    await expect(devBtn).toContainText('192.168.1.3');
    await form.locator('.dd-btn[aria-label="Порядок устройств"]').click();
    await page.locator('.dd-pop').getByRole('option', { name: /IP ↑/ }).click();
    await devBtn.click();
    const order = await page.locator('.dd-pop [role="option"]').allTextContents();
    expect(order.map((t) => t.match(/192\.168\.1\.\d+/)[0])).toEqual(['192.168.1.3', '192.168.1.20', '192.168.1.115']);
    await page.keyboard.press('Escape');
    await form.locator('.dd-btn[aria-label="Сколько записывать"]').click();
    await page.locator('.dd-pop').getByRole('option', { name: '30 секунд' }).click();

    const go = form.locator('.btn.primary, .btn.danger').last();
    const w0 = (await go.boundingBox()).width;
    await go.click();
    await expect(go).toHaveText('Остановить запись');
    await expect(go).toHaveClass(/danger/);
    expect(Math.round((await go.boundingBox()).width)).toBe(Math.round(w0));   // the row does not jump
    expect(calls[0]).toMatchObject({ cmd: 'conns_start', secs: 30 });
    await page.screenshot({ path: test.info().outputPath(`conns-${w}.png`) });
    await go.click();
    await expect.poll(() => calls.filter((c) => c.cmd === 'conns_stop').length).toBe(1);
    await expect(go).toHaveText('Записать', { timeout: 10_000 });
    await expect(page.locator('main')).toContainText('остановлена на 4 с');
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);

    // a reload keeps the length and the order
    await page.reload();
    await expect(form.locator('.dd-btn[aria-label="Сколько записывать"]')).toContainText('30 секунд');
    await expect(form.locator('.dd-btn[aria-label="Порядок устройств"]')).toContainText('IP ↑');
  });
}
