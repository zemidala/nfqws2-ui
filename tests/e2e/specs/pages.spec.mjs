// Все страницы открываются: заголовок на месте, ошибок JS нет, нет плашки об ошибке вместо страницы.
// «Обзор» — во всех трёх компоновках и четырёх стилях.
import { test, expect } from '../fixtures.mjs';

const PAGES = [
  ['#/', 'Обзор'], ['#/diag', 'Диагноз'], ['#/tests/freeze', 'Обрыв'], ['#/tests/dns', 'DNS-серверы'], ['#/tests/conns', 'Соединения устройства'],['#/devices', 'Устройства'],['#/tests/configs', 'Чужой конфиг'], ['#/tests/pick', 'Подбор'], ['#/tests/trace', 'Трассировка'],
  ['#/tests/history', 'История подборов'], ['#/tests/community', 'сообщества'], ['#/tests/monitor', 'Мониторинг'], ['#/tests/auto', 'Автоподбор'],
  ['#/tests/notify', 'Уведомления'], ['#/settings', 'Профили'], ['#/sites', 'Списки'], ['#/asn', 'ASN'], ['#/settings/basic', 'Основное'],
  ['#/settings/base', 'Параметры запуска'], ['#/settings/raw', 'Конфиг'], ['#/settings/backup', 'Резервные копии'], ['#/settings/hist', 'История изменений'],
  ['#/log', 'Журнал'], ['#/settings/report', 'Отчёт'], ['#/settings/look', 'Оформление'], ['#/settings/about', 'О программе'],
];

for (const [hash, title] of PAGES) {
  test(`страница ${hash} открывается`, async ({ app }) => {
    await app.open(hash);
    await expect(app.page.locator('main h1').first()).toContainText(title);
    await expect(app.page.locator('main > .notice.bad')).toHaveCount(0);
  });
}

for (const layout of ['menu', 'tabs', 'site']) {
  for (const variant of ['k', 'a', 'b', 'c']) {
    test(`«Обзор»: компоновка ${layout}, стиль ${variant}`, async ({ app }) => {
      await app.open('#/', { layout, variant });
      await expect(app.page.locator('html')).toHaveAttribute('data-layout', layout);
      await expect(app.page.locator('html')).toHaveAttribute('data-variant', variant);
    });
  }
}
