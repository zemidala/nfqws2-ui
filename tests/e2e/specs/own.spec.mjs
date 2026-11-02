// Подбор: поле «Своя стратегия» (NFQ-L54). Разбор и проверка — настоящие (strategy_check на роутере),
// запуск подбора подменён: смотрим, что уходит в test_start.
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';

const GOOD = [
  '# из чата: для ютуба',
  'NFQWS_ARGS_CUSTOM="--filter-tcp=443 --filter-l7=tls --lua-desync=fake:blob=fake_default_tls:tcp_md5:repeats=6 \\',
  '  --lua-desync=multisplit:pos=1,midsld',
  '--new',
  'multisplit:pos=2"',
].join('\n');

async function openField(app, look = {}) {
  await app.open('#/tests/pick', look);
  await app.page.locator('details.own-st > summary').click();
  return app.page.locator('textarea[aria-label="Свои стратегии"]');
}

test('свои стратегии из текста чата уходят в подбор списком шагов', async ({ app }) => {
  let sent = null;
  await app.mock((b) => { if (b.cmd === 'test_start') { sent = b; return { ok: true }; } });
  const ta = await openField(app);
  await ta.fill(GOOD);
  await app.page.locator('#t-host').fill('rutracker.org');
  await app.page.getByRole('button', { name: 'Запустить тест' }).click();
  await expect.poll(() => sent).not.toBeNull();
  expect(sent.own).toEqual([
    ['--lua-desync=fake:blob=fake_default_tls:tcp_md5:repeats=6', '--lua-desync=multisplit:pos=1,midsld'],
    ['--lua-desync=multisplit:pos=2'],
  ]);
  await expect(app.page.getByText('В проверке участвуют только шаги --lua-desync')).toBeVisible();   // фильтры чужого профиля названы
});

test('непонятная стратегия названа до запуска, подбор не стартует', async ({ app }) => {
  let sent = null;
  await app.mock((b) => { if (b.cmd === 'test_start') { sent = b; return { ok: true }; } });
  const ta = await openField(app);
  await ta.fill('--lua-desync=multisplit:pos=2\n--lua-desync=nosuchfunc:pos=1\n--lua-desync=fake:blob=some_foreign_blob:tcp_md5');
  await app.page.locator('#t-host').fill('rutracker.org');
  await app.page.getByRole('button', { name: 'Запустить тест' }).click();
  await expect(app.page.getByText('Стратегия 2 не годится')).toBeVisible();
  await expect(app.page.getByText('функции «nosuchfunc» нет')).toBeVisible();
  await expect(app.page.getByText('Стратегия 3 не годится')).toBeVisible();
  await expect(app.page.getByText('блоб «some_foreign_blob»')).toBeVisible();
  if (process.env.L54_SHOT) await app.page.locator('details.own-st').locator('xpath=..').screenshot({ path: process.env.L54_SHOT });
  await app.page.waitForTimeout(500);
  expect(sent).toBeNull();
});

test('только своя: галочки сняты — подбор всё равно запускается', async ({ app }) => {
  let sent = null;
  await app.mock((b) => { if (b.cmd === 'test_start') { sent = b; return { ok: true }; } });
  const ta = await openField(app);
  await ta.fill('--lua-desync=multisplit:pos=2');
  for (const id of ['#t-cfg', '#t-std', '#t-hist', '#t-comm']) await app.page.locator(id).uncheck();
  await app.page.locator('#t-host').fill('rutracker.org');
  await app.page.getByRole('button', { name: 'Запустить тест' }).click();
  await expect.poll(() => sent).not.toBeNull();
  expect(sent.sets).toEqual([]);
  expect(sent.own).toEqual([['--lua-desync=multisplit:pos=2']]);
});

// rangerxxy, #729 п. 12: своя стратегия и в подборе для нескольких сайтов
test('«Несколько сайтов»: поле есть, свои уходят в multi_start, и одни свои — тоже запуск', async ({ app }) => {
  let sent = null;
  await app.mock((b) => { if (b.cmd === 'multi_start') { sent = b; return { ok: true }; } });
  await app.open('#/tests/pick');
  await app.page.getByRole('radio', { name: 'Несколько сайтов или список' }).click();
  await expect(app.page.locator('details.own-st')).toBeVisible();
  await app.page.locator('details.own-st > summary').click();
  await app.page.locator('textarea[aria-label="Свои стратегии"]').fill('--lua-desync=multisplit:pos=2');
  for (const id of ['#t-cfg', '#t-std', '#t-hist', '#t-comm']) await app.page.locator(id).uncheck();
  await app.page.locator('textarea[aria-label="Сайты, по одному в строке"]').fill('rutracker.org\nnnmclub.to');
  await app.page.getByRole('button', { name: 'Запустить тест' }).click();
  await expect.poll(() => sent).not.toBeNull();
  expect(sent.sets).toEqual([]);
  expect(sent.own).toEqual([['--lua-desync=multisplit:pos=2']]);
});

test.describe('телефон', () => {
  test.use({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  test('раскрытое поле с замечаниями помещается на 390 px', async ({ app }) => {
    await app.mock((b) => { if (b.cmd === 'test_start') return { ok: true }; });
    const ta = await openField(app);
    await ta.fill('--filter-tcp=443 --lua-desync=nosuchfunc:pos=1');
    await app.page.locator('#t-host').fill('rutracker.org');
    await app.page.getByRole('button', { name: 'Запустить тест' }).click();
    await expect(app.page.getByText('Стратегия 1 не годится')).toBeVisible();
    expect(await auditLayout(app.page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs'] })).toEqual([]);
    expect(await app.page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
  });
  test('«Несколько сайтов» с раскрытым полем помещается на 390 px', async ({ app }) => {
    await app.open('#/tests/pick');
    await app.page.getByRole('radio', { name: 'Несколько сайтов или список' }).click();
    await app.page.locator('details.own-st > summary').click();
    await app.page.screenshot({ path: test.info().outputPath('multi-own-390.png'), fullPage: true });
    expect(await auditLayout(app.page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs'] })).toEqual([]);
    expect(await app.page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
  });
});
