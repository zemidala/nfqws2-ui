// «Чужой конфиг»: добавить конфиг текстом, запуск проверки, карточки со счётом, таблица по сайтам, «Профили…» → «Взять».
// Ответы cfg_start / cfg_get подменяются: настоящая проверка запускает второй nfqws2 и идёт минуту и дольше.
import { test, expect } from '../fixtures.mjs';
import { auditLayout } from 'pw-lib/layout.mjs';

const HOSTS = ['rutracker.org', 'youtube.com', 'discord.com', 'x.com', 'king.hr', 'u.gg'];
const per = (bad = {}) => Object.fromEntries(HOSTS.map((h) => [h, bad[h] ? { ok: 0, tries: 1, ms: null, reason: bad[h] } : { ok: 3, tries: 3, ms: 200, reason: null }]));
const PROFILE = ['--filter-tcp=443', '--filter-l7=tls', '--hostlist=/etc/nfqws2/lists/user.list', '--payload=tls_client_hello', '--lua-desync=fake:blob=tls_clienthello:tcp_md5'];
const DONE = {
  state: 'done', type: 'cfg', finished: 1761040000, repeats: 3, busy: null,
  sites: HOSTS.map((h) => ({ host: h, baseline: { ok: h === 'youtube.com', reason: h === 'youtube.com' ? null : 'тайм-аут' } })),
  configs: [
    { name: 'мой (сейчас)', mine: true, skip: null, per: per({ 'u.gg': 'тайм-аут' }), opened: 5, ms: 210, changes: [], warnings: 0, profiles: [PROFILE] },
    { name: 'Конфиг 1', skip: null, per: per({ 'king.hr': 'соединение зависает на объёме (после ~16–20 КБ)' }), opened: 5, ms: 230, changes: ['a → b'], warnings: 3, text: 'NFQWS_ARGS="--filter-tcp=443"\n', profiles: [PROFILE, PROFILE] },
    { name: 'Конфиг 2', skip: null, per: per(), opened: 6, ms: 190, changes: [], warnings: 0, text: 'NFQWS_ARGS="--filter-tcp=443"\n', profiles: [PROFILE] },
    { name: 'Конфиг 3', skip: 'Не проверяется: кроме переменных в нём есть команды (rm -rf /tmp/x) — такой конфиг выполнял бы их на роутере.', per: [], opened: 0, ms: null, changes: [], warnings: 0 },
  ],
};

async function mockDone(app, extra = () => undefined) {
  await app.mock((b) => (b.cmd === 'cfg_get' ? DONE : extra(b)));
}

test('итог: лучший сверху, свой — последним, у своего нет «Применить», непроверенный с причиной', async ({ app, page }) => {
  await mockDone(app);
  await app.open('#/tests/configs', { variant: 'k' });
  const cards = page.locator('.cfg-card');
  await expect(cards).toHaveCount(4);
  await expect(cards.nth(0)).toContainText('Конфиг 2');
  await expect(cards.nth(0)).toContainText('лучше вашего');
  await expect(cards.nth(0)).toHaveClass(/best/);
  await expect(cards.nth(1)).toContainText('как ваш');
  await expect(cards.nth(1)).toContainText('У вас открываются, а с ним нет: king.hr');
  await expect(cards.nth(1)).toContainText('Открылись, а у вас нет: u.gg');
  await expect(cards.nth(2)).toContainText('кроме переменных в нём есть команды');
  await expect(cards.nth(2).locator('.btn', { hasText: 'Применить' })).toHaveCount(0);
  await expect(cards.nth(3)).toContainText('мой (сейчас)');
  await expect(cards.nth(3).locator('.btn', { hasText: 'Применить' })).toHaveCount(0);
  await page.getByRole('button', { name: 'Подробно по сайтам' }).click();
  await expect(page.locator('.cfg-tbl tbody tr')).toHaveCount(HOSTS.length);
  await expect(page.locator('.cfg-tbl thead th')).toHaveCount(2 + 4);
});

test('«Профили…» → «Взять» открывает «Вставить чужой» с текстом профиля', async ({ app, page }) => {
  await mockDone(app, (b) => (b.cmd === 'profile_check' ? { tokens: b.tokens, steps: b.tokens.filter((t) => t.startsWith('--lua-desync=')), rejected: [], fixed: [], missing: [], dry_run: { ok: true } } : undefined));
  await app.open('#/tests/configs', { variant: 'k' });
  await page.locator('.cfg-card', { hasText: 'Конфиг 1' }).getByRole('button', { name: 'Профили…' }).click();
  const m = page.locator('.modal');
  await expect(m.locator('.row', { hasText: '#2' })).toContainText('user.list');
  await m.getByRole('button', { name: 'Взять' }).first().click();
  await expect(page.locator('.modal textarea')).toHaveValue(PROFILE.join('\n'));
  await expect(page.locator('.modal')).toContainText('nfqws2 принимает этот профиль');
});

test('добавить конфиг текстом и запустить: на роутер уходят конфиг и сайты', async ({ app, page }) => {
  let sent = null;
  let running = false;
  await app.mock((b) => {
    if (b.cmd === 'cfg_start') { sent = b; running = true; return { ok: true }; }
    if (b.cmd === 'cfg_get') return running ? { state: 'running', type: 'cfg', total: 8, done: 3, current: 'Конфиг 1: x.com', busy: null, sites: [], configs: [] } : { state: 'idle', type: 'cfg', busy: null };
    return undefined;
  });
  await app.open('#/tests/configs', { variant: 'k' });
  await page.getByRole('button', { name: 'Текстом' }).click();
  await page.locator('.modal textarea').fill('NFQWS_ARGS="--filter-tcp=443 --lua-desync=fake:blob=tls_clienthello"\n');
  await page.locator('.modal input').fill('из чата');
  await page.locator('.modal').getByRole('button', { name: 'Добавить' }).click();
  await expect(page.locator('.chip', { hasText: 'из чата' })).toBeVisible();
  await page.getByLabel('Сайты для проверки').fill('rutracker.org, king.hr');
  await page.locator('main').getByRole('button', { name: 'Проверить', exact: true }).click();
  await expect(page.getByText('Проверяю конфиги')).toBeVisible();
  expect(sent.hosts).toEqual(['rutracker.org', 'king.hr']);
  expect(sent.configs.map((c) => c.name)).toContain('из чата');
});

for (const [w, hgt] of [[390, 844], [1366, 900]]) {
  test(`вёрстка итога — ${w}px`, async ({ app, page }) => {
    await page.setViewportSize({ width: w, height: hgt });
    await mockDone(app);
    await app.open('#/tests/configs', { variant: 'k' });
    await page.getByRole('button', { name: 'Подробно по сайтам' }).click();
    await page.waitForTimeout(300);
    expect(await auditLayout(page, { ignore: ['ПРОКРУТКА ВБОК nav.subtabs'] })).toEqual([]);
  });
}

// NFQ-L182: «Из файла» — several files at once; CRLF and BOM are cleaned, a non-config and an oversized file are refused
test('«Из файла»: конфиги из файлов, неподходящие отклоняются', async ({ app, page }) => {
  let sent = null;
  await app.mock((b) => {
    if (b.cmd === 'cfg_get') return { state: 'idle' };
    if (b.cmd === 'cfg_start') { sent = b; return { ok: true }; }
    return undefined;
  });
  await app.open('#/tests/configs');
  const conf = '\uFEFFNFQWS_ARGS="--filter-tcp=443"\r\nNFQWS_ARGS_CUSTOM=""\r\n';
  await page.locator('input[type=file]').setInputFiles([
    { name: 'nfqws2.conf', mimeType: 'text/plain', buffer: Buffer.from(conf) },
    { name: 'from chat.txt', mimeType: 'text/plain', buffer: Buffer.from('NFQWS_ARGS="--filter-udp=443"\n') },
    { name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('just some text\n') },
    { name: 'big.conf', mimeType: 'text/plain', buffer: Buffer.from('NFQWS_ARGS=""\n' + '#'.repeat(70000)) },
  ]);
  await expect(page.locator('.chip.accent')).toHaveCount(2);
  await expect(page.locator('.chip.accent').nth(0)).toContainText('nfqws2');
  await expect(page.locator('.chip.accent').nth(1)).toContainText('from chat');
  await expect(page.locator('.toast.err')).toHaveCount(2);
  await page.locator('main').getByRole('button', { name: 'Проверить', exact: true }).click();
  await expect.poll(() => sent).not.toBeNull();
  expect(sent.configs[0].text).toBe('NFQWS_ARGS="--filter-tcp=443"\nNFQWS_ARGS_CUSTOM=""\n');
});
