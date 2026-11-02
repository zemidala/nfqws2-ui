// Перезапуск nfqws2: пока идёт, значок на кнопке крутится, кнопки сервиса ждут, размер кнопки тот же
// (rangerxxy, #729 п. 9). Ответ safe_restart подменён с задержкой — nfqws2 не перезапускается.
import { test, expect } from '../fixtures.mjs';

test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

for (const [w, variant] of [[1366, 'k'], [390, 'k'], [1366, 'z']]) {
  test(`«Перезапустить» крутится, пока идёт перезапуск, ${w}px, стиль ${variant}`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: 900 });
    let release;
    const hold = new Promise((r) => { release = r; });
    let asked = 0;
    await app.mock(async (b) => {
      if (b.cmd === 'safe_restart') { asked++; await hold; return { ok: true }; }
    });
    await app.open('#/', { variant });
    const box = page.locator('#svc-ctl');
    const restart = box.getByRole('button', { name: 'Перезапустить' });
    if (!await restart.isVisible().catch(() => false)) test.skip(true, 'nfqws2 не запущен');
    const before = await restart.boundingBox();

    await restart.click();
    await expect(restart).toHaveAttribute('aria-busy', 'true');
    await expect(restart).toBeDisabled();
    await expect(box.getByRole('button', { name: 'Остановить' })).toBeDisabled();
    const anim = await restart.locator('svg').evaluate((s) => getComputedStyle(s).animationName);
    expect(anim).toBe('spin');
    const during = await restart.boundingBox();
    expect(Math.round(during.height)).toBe(Math.round(before.height));
    expect(Math.round(during.width)).toBe(Math.round(before.width));
    await page.waitForTimeout(300);
    await page.screenshot({ path: test.info().outputPath(`busy-${w}-${variant}.png`), clip: { x: 0, y: 0, width: w, height: 70 } });
    await restart.click({ force: true }).catch(() => {});   // a second click while busy does nothing
    // weak routers send one request at a time — the restart may still wait in the queue: poll, then make sure it stays 1
    await expect.poll(() => asked).toBe(1);
    await page.waitForTimeout(500);
    expect(asked).toBe(1);

    release();
    await expect(restart).not.toHaveAttribute('aria-busy', 'true');
    await expect(restart).toBeEnabled();
  });
}
