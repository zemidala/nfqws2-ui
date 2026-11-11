// The README's GitHub alerts («> [!IMPORTANT]», «> [!WARNING]» — the notice about use and risk) are for the
// repository page only: «Справка» in the interface leaves them out instead of showing «[!IMPORTANT]» as text (2026-11-11).
import { test, expect } from '../fixtures.mjs';

test('«Справка» без плашек GitHub', async ({ app, page }) => {
  await app.open('#/settings/readme', { variant: 'k' });
  const doc = page.locator('main .md');
  await expect(doc).toContainText('Веб-интерфейс для');
  await expect(doc).not.toContainText('[!');
  await expect(doc).not.toContainText('Материал подготовлен');
  await expect(doc).not.toContainText('на свой страх и риск');
  await expect(doc).toContainText('любое изменение можно откатить');   // the text around them stays
});
