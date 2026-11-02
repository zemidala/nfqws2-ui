// «Подбор стратегии»: проверять как старые устройства (TLS 1.2) — смарт-ТВ и приставки (вопрос в группе 02.11).
// Галочка только для HTTPS, уходит и в обычный подбор, и в подбор для нескольких сайтов. Запуск подменён.
import { test, expect } from '../fixtures.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

const pickProto = async (page, name) => {
  await page.locator('main .dd-btn[aria-label="Протокол"]').click();
  await page.locator('.dd-pop').getByRole('option', { name }).click();
};

for (const w of [390, 1366]) {
  test(`галочка TLS 1.2: только HTTPS, уходит в оба режима, ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    const sent = [];
    await app.mock((b) => { if (b.cmd === 'test_start' || b.cmd === 'multi_start') { sent.push(b); return { ok: true }; } });
    await app.open('#/tests/pick', { variant: 'k' });
    const box = page.locator('#t-tls12');
    await expect(box).toBeVisible();
    await pickProto(page, 'HTTP (TCP 80)');
    await expect(box).toBeHidden();
    await pickProto(page, /QUIC/);
    await expect(box).toBeHidden();
    await pickProto(page, /HTTPS/);
    await expect(box).toBeVisible();
    await box.check();
    await box.scrollIntoViewIfNeeded();
    await page.screenshot({ path: test.info().outputPath(`tls12-${w}.png`) });
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);

    await page.locator('#t-host').fill('rutracker.org');
    await page.getByRole('button', { name: 'Запустить тест' }).click();
    await expect.poll(() => sent.length).toBe(1);
    expect(sent[0]).toMatchObject({ cmd: 'test_start', tls12: true, proto: 'https' });

    await page.getByRole('radio', { name: 'Несколько сайтов или список' }).click();
    await expect(box).toBeVisible();
    await page.locator('textarea[aria-label="Сайты, по одному в строке"]').fill('rutracker.org\nnnmclub.to');
    await page.getByRole('button', { name: 'Запустить тест' }).click();
    await expect.poll(() => sent.length).toBe(2);
    expect(sent[1]).toMatchObject({ cmd: 'multi_start', tls12: true });
  });
}
