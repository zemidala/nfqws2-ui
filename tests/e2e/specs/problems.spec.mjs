// «Проблемы»: кнопка исправления со списком значений не выезжает за блок (снимок Сергея Боева, #480:
// «Добавить blob» на стоковом конфиге nfqws2-keenetic). Замечание подкладывается в ответ state — конфиг не трогается.
import { test, expect } from '../fixtures.mjs';

const ISSUE = {
  var: 'NFQWS_ARGS_UDP', tok: 6, level: 'error',
  msg: 'Функции fake нужен параметр «blob» — без него она завершится ошибкой на каждом пакете',
  fix: { op: 'add_param', key: 'blob', label: 'Добавить blob', choices: ['quic_initial', 'tls_clienthello', 'fake_default_tls', 'fake_default_quic', 'fake_default_http', 'tls_client_hello_clone_long_name'] },
};

for (const [w, hgt] of [[390, 900], [1366, 900], [1920, 1000]]) {
  test(`кнопка со списком внутри блока — ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: hgt });
    await page.route('**/api.php*', async (route) => {
      let body = {};
      try { body = JSON.parse(route.request().postData() || '{}'); } catch { /* не JSON */ }
      if (body.cmd !== 'state') return route.continue();
      const res = await route.fetch();
      const j = await res.json();
      const st = j.data || j;
      st.lint = st.lint || { issues: [] };
      st.lint.issues = [ISSUE, ...(st.lint.issues || [])];
      return route.fulfill({ response: res, json: j });
    });
    await app.open('#/', { variant: 'k' });
    const btns = page.locator('.problem', { hasText: 'Добавить blob' });
    await expect(btns.first()).toBeVisible();
    for (const el of await btns.all()) {
      if (!(await el.isVisible())) continue;
      const box = await el.boundingBox();
      const b = await el.locator('.btn', { hasText: 'Добавить blob' }).boundingBox();
      expect(b.x + b.width, `кнопка ${Math.round(b.x + b.width)} > блок ${Math.round(box.x + box.width)}`).toBeLessThanOrEqual(box.x + box.width + 0.5);
    }
  });
}
