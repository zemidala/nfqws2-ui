'use strict';
// Interface: the overview column and the site check.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ колонка обзора ============

const SIDE = {};
// gather = false — только создать блоки (колонке состояния справа), не забирая проверку сайта со страницы
function sideBlocks(gather = true) {
  if (!SIDE.root) {
    SIDE.service = h('section', { class: 'blk' });
    SIDE.problems = h('section', { class: 'blk' });
    SIDE.backup = h('section', { class: 'blk' });
    SIDE.traffic = h('section', { class: 'blk' });
    SIDE.monitor = h('section', { class: 'blk' });
    SIDE.root = h('div', { class: 'side-in' });
  }
  // блоки могла забрать страница «Обзор», карточка сайта или колонка состояния — возвращаем на место
  if (gather) SIDE.root.append(SIDE.service, S.check.el, SIDE.problems, SIDE.monitor, SIDE.backup, SIDE.traffic);
  return SIDE.root;
}
// Слева на широком экране: «Вкладки» — колонка обзора, «Боковое меню» — меню, «Вокруг сайта» — ничего
function placeSide() {
  const side = document.getElementById('side');
  if (overviewCol()) {
    if (sideBlocks().parentNode !== side) side.replaceChildren(sideBlocks());
  } else if (isWide() && layout() === 'menu') {
    side.replaceChildren(menuNav());
  } else {
    side.replaceChildren();
  }
  updateSide();
}
// Колонка состояния справа — на страницах-формах, проверках и таблицах, когда для неё хватает ширины
// (показывает CSS по ширине области содержимого). Во «Вкладках» не нужна: обзор и так слева.
// Списки, профили, конфиг, журнал и мониторинг отдают всю ширину своему содержимому, «Обзор» — карточкам.
const RAIL_PAGES = ['diag', 'frz', 'dns', 'conns', 'cfg', 'pick', 'trace', 'phist', 'comm', 'auto', 'learn', 'tg', 'asn', 'basic', 'base', 'hist', 'report', 'look', 'about', 'site'];
// Where the page is drawn. With the status column the page goes into .mcol and the column stays put: moving between
// two such pages redraws only the middle, the column is not torn down and built again after the page has loaded.
function railTarget(main, page) {
  main.classList.toggle('formy', RAIL_PAGES.includes(page));   // строки форм на широком экране — подсказки справа от поля
  const wrap = main.querySelector(':scope > .mwrap');
  if (!isWide() || overviewCol() || !RAIL_PAGES.includes(page)) return main;
  if (wrap) return wrap.firstElementChild;
  sideBlocks(false);
  const col = h('div', { class: 'mcol' });
  main.replaceChildren(h('div', { class: 'mwrap' }, col, h('aside', { class: 'rail', 'aria-label': 'Состояние' }, SIDE.service, SIDE.problems, SIDE.monitor, SIDE.backup)));
  updateSide();
  return col;
}
function updateSide() {
  if (!SIDE.root || !S.state) return;   // блоки могут стоять в колонке или на странице «Обзор», причём не все сразу
  renderSideService(SIDE.service);
  renderSideProblems(SIDE.problems);
  renderSideBackup(SIDE.backup);
  renderSideTraffic(SIDE.traffic);
  renderSideMonitor(SIDE.monitor);
}

const SLOW_MS = 5000;   // an answer slower than this counts as «slow»
// Monitoring chart, one of the four looks from the approved mockup (local/design/monitoring-chart.html), chosen on the
// page (look.monChart). Input: checks as {ok, ms, tip} or null for none, oldest first. Ticks, columns and blocks are a
// fixed multiple of 4 px wide with 4 px gaps: at 125, 150 and 175 % scaling those land on whole screen pixels, so
// they stay even. When the cell is narrower, the oldest are cut.
const MON_CHARTS = [['ticks', 'Полоски'], ['hours', 'Часы'], ['cols', 'Столбики'], ['words', 'Словами']];
const monChart = () => MON_CHARTS.some(([k]) => k === getLook().monChart) ? getLook().monChart : 'ticks';
const chKind = (x) => !x ? 'none' : !x.ok ? 'bad' : x.ms > SLOW_MS ? 'slow' : 'ok';
// n checks → n/size buckets: failed if any failed, slow if any slow, the mean answer time; tooltips joined
function chBuckets(items, size) {
  const out = [];
  for (let i = 0; i < items.length; i += size) {
    const xs = items.slice(i, i + size).filter(Boolean);
    if (!xs.length) { out.push(null); continue; }
    const okMs = xs.filter((x) => x.ok).map((x) => x.ms);
    const bad = xs.some((x) => !x.ok), slow = okMs.some((m) => m > SLOW_MS);
    out.push({ ok: !bad, ms: slow ? Math.max(...okMs) : Math.round(okMs.reduce((a, b) => a + b, 0) / (okMs.length || 1)), tip: xs.map((x) => x.tip).filter(Boolean).join('\n') });
  }
  return out;
}
const chTicks = (items, cls = '') => h('div', { class: ('ch-ticks ' + cls).trim() }, items.map((x) => h('i', { class: chKind(x), title: x?.tip || null })));
function chCols(items, cls = '') {
  const max = Math.max(1, ...items.filter((x) => x?.ok).map((x) => Math.min(x.ms, 3000)));
  return h('div', { class: ('ch-cols ' + cls).trim() }, items.map((x) => h('i', { class: chKind(x), title: x?.tip || null,
    style: x?.ok && x.ms <= SLOW_MS ? `height:${Math.max(12, Math.round(Math.min(x.ms, 3000) / max * 100))}%` : null })));
}
// by the clock: yesterday and today, an hour per cell; hours still to come are empty frames
function chHours(checks) {
  const d = new Date(), t0 = new Date(d.getFullYear(), d.getMonth(), d.getDate() - 1).getTime() / 1000, now = d.getTime() / 1000;
  const cells = Array.from({ length: 48 }, () => []);
  for (const x of checks) { const i = Math.floor((x.t - t0) / 3600); if (i >= 0 && i < 48) cells[i].push(x); }
  const cell = (xs, i) => { const b = xs.length ? chBuckets(xs, xs.length)[0] : null; return h('i', { class: t0 + i * 3600 > now ? 'future' : chKind(b), title: b?.tip || null }); };
  return h('div', { class: 'ch-hours' }, h('span', { text: 'вчера' }), h('div', {}, cells.slice(0, 24).map((xs, i) => cell(xs, i))),
    h('span', { text: 'сегодня' }), h('div', {}, cells.slice(24).map((xs, i) => cell(xs, i + 24))));
}
// in words: no failures / still down for N / M failures, last one at …; under it 12 blocks of the history
function chWords(items) {
  const runs = []; let cur = null;
  items.forEach((x, i) => { if (x && !x.ok) { if (cur && cur.end === i - 1) cur.end = i; else runs.push(cur = { start: i, end: i }); } });
  const last = items.at(-1), down = last && !last.ok;
  const text = !runs.length ? h('b', { class: 'ok', text: 'без сбоев' })
    : down ? h('b', { class: 'bad', text: 'не открывается' + (runs.at(-1).end - runs.at(-1).start ? `, ${runs.at(-1).end - runs.at(-1).start + 1} проверок подряд` : '') })
    : h('span', {}, h('b', { class: 'bad', text: plural(runs.length, 'сбой', 'сбоя', 'сбоев') }), h('span', { class: 'muted', text: ' · последний ' + (items[runs.at(-1).end]?.when || '') }));
  return h('div', { class: 'ch-words' }, text, h('div', { class: 'ch-blocks' }, chBuckets(items, Math.ceil(items.length / 12)).map((x) => h('i', { class: chKind(x), title: x?.tip || null }))));
}
// the column's recent checks: response time in ms, 0 — failed (a server before 1.27 sends true/false)
// The same look as chosen on «Мониторинг», in a short row (the column on the right, the tile on «Обзор»)
function uptimeBar(recent) {
  const all = recent.map((v) => ({ ok: !!v, ms: v === true ? 1 : Number(v) || 0 }));
  const items = all.slice(-16);   // 16 × (4 + 4) px fits the row
  const k = monChart();
  if (k === 'cols') return chCols(items, 'mini');
  // hours: the last 10 pairs of checks (an hour each at the usual 30 min) as 8 px squares
  if (k === 'hours') return h('div', { class: 'ch-hmini' }, chBuckets(all.slice(-20), 2).map((x) => h('i', { class: chKind(x) })));
  if (k === 'words') {
    const bad = all.filter((x) => !x.ok).length;
    return h('span', { class: 'ch-wmini ' + (bad ? 'bad' : 'ok'), text: !bad ? 'без сбоев' : all.at(-1) && !all.at(-1).ok ? 'не открывается' : plural(bad, 'сбой', 'сбоя', 'сбоев') });
  }
  return chTicks(items, 'mini');
}

function renderSideMonitor(el) {
  const m = S.state.monitor;
  if (!m || !m.sites.length) { el.hidden = true; return; }
  el.hidden = false;
  const okN = m.sites.filter((x) => x.last && x.last[1]).length;
  el.replaceChildren(
    h('div', { class: 'blk-h' }, h('h2', { text: 'Мониторинг' }), linkBtn('#/tests/monitor')),
    h('p', { class: 'sm' }, h('span', { class: okN === m.sites.length ? 'status-ok' : 'status-bad', text: `${okN} из ${m.sites.length} открываются` }),
      m.last ? h('span', { class: 'muted', text: ' · ' + fmtAgo(S.state.now - m.last) }) : null, m.enabled ? null : h('span', { class: 'muted', text: ' · выключен' })),
    h('div', { class: 'mon-mini' }, m.sites.map((s) => h('button', { type: 'button', class: 'mon-row', onclick: () => checkHost(s.host), title: 'Проверить сейчас (повторный клик — свернуть)' },
      levelIcon(!s.last ? 'info' : s.last[1] ? 'ok' : 'error'), h('span', { class: 'ellipsis mono sm', text: s.host }), uptimeBar(s.recent)))));
}

function renderSideService(el) {
  const st = S.state;
  const need = st.running && (st.restart_needed || !st.in_sync);
  el.replaceChildren(
    h('div', { class: 'blk-h' }, h('h2', { text: 'Сервис' })),
    h('div', { class: 'svc' },
      h('div', { class: 'svc-state ' + svcState(st)[0] }, h('span', { class: 'dot' }), svcState(st)[1])),
    // restart / stop live in the top bar; here only where the bar has none of them — «Вкладки» on a wide screen
    layout() !== 'tabs' || !isWide() ? null : h('div', { class: 'svc-buttons' },
      st.running
        ? [svcBtn('Перезапустить', 'restart', safeRestart, 'small', 'power', { title: 'Перезапуск с проверкой: если сайты перестанут открываться или вы не подтвердите за 3 минуты, изменения откатятся сами', disabled: pendingActive() }),
          svcBtn('Остановить', 'stop', () => service('stop'), 'small danger', 'stop', { title: 'Остановить nfqws2 — обход блокировок перестанет работать', disabled: pendingActive() })]
        : svcBtn('Запустить', 'start', () => service('start'), 'small primary', 'play')),
    h('p', { class: 'muted sm num', text: [st.version && 'nfqws2 v' + st.version, st.running && st.process.started && 'запущен ' + fmtAgo(st.now - st.process.started), st.running && st.process.rss_kb && (st.process.rss_kb / 1024).toFixed(1).replace('.', ',') + ' МБ'].filter(Boolean).join(' · ') }),
    !st.running && st.stop ? stopNote(st.stop) : null,
    need && !pendingActive() ? notice('warn', 'Нужен перезапуск', 'Конфиг изменён после запуска nfqws2. После перезапуска интерфейс проверит сайты и откатит изменения, если вы их не подтвердите.'
      + (st.restart_why?.length ? ' Что отличается: ' + st.restart_why.join('; ') + '.' : ''), btn('Перезапустить с проверкой', safeRestart, 'small warn')) : null,
    st.rollback?.files.length && !pendingActive() ? h('div', { class: 'row sm' },
      h('span', { class: 'muted', text: `С ${fmtDate(st.rollback.since)} изменено: ${st.rollback.files.join(', ')}` }),
      btn('Вернуть рабочее состояние', rollbackToWorking, 'small ghost', 'history', { title: 'Вернуть файлы к моменту последнего запуска или подтверждения и перезапустить nfqws2' })) : null);
}

// Почему nfqws2 не работает и что с этим сделать
function stopNote(sp) {
  const act = sp.kind === 'conf' ? h('a', { class: 'btn small', href: PAGES.raw[0] }, 'Открыть конфиг')
    : sp.kind === 'disabled' ? btn('Включить автозапуск', async () => { if (await guarded(() => api('service', { action: 'enable' }), 'Автозапуск включён')) { await loadState().catch(() => {}); route(true); } }, 'small')
      : sp.kind === 'manual' ? h('a', { class: 'btn small', href: PAGES.log[0] }, 'Журнал') : null;
  return h('div', { class: 'notice bad' }, icon('bad'), h('div', { class: 'grow' },
    h('b', { text: 'Почему не работает' }), h('div', { class: 't', text: sp.text }),
    sp.kind !== 'conf' && sp.log?.length ? h('pre', { class: 'box sm', style: 'max-height:120px;margin-top:6px', text: sp.log.join('\n') }) : null),
    act ? h('div', { class: 'notice-actions' }, act) : null);
}

function issueTarget(x) {
  const p = S.state.conf_profiles.find((cp) => cp.source && cp.source.source === x.var && (x.var !== 'NFQWS_ARGS_CUSTOM' || profIssues(cp).includes(x)));
  if (p) return `#/settings/p${p.index}`;
  if (x.var === 'NFQWS_BASE_ARGS') return '#/settings/base';
  if (x.var) return '#/settings/basic';
  return '#/settings/raw';
}

// Keenetic, политика доступа: nfqws2 обрабатывает только её устройства, а запросы самого роутера — нет.
// Проверки роутера получают метку политики (p.probe) и идут как от её устройства; если пометить не вышло —
// мониторинг и «Проверить сайт» показывают сайт без обхода, а не то, что видят устройства.
function policyOnly() {
  const p = S.state.intercept?.policy;
  return p?.mode === 'only' && !p.probe ? p : null;
}
function policyText(p) {
  return `В Keenetic есть политика доступа «${p.name}», и nfqws2 обрабатывает только устройства из неё. Пометить проверочные запросы роутера меткой политики не удалось, они идут мимо nfqws2, поэтому проверка с роутера показывает сайт без обхода — «не открывается» здесь не значит, что обход не работает на устройствах. Подбор стратегии и диагноз работают как обычно: у них свои правила.`
    + ' Чтобы nfqws2 обрабатывал все устройства и сам роутер — переименуйте или удалите политику, либо задайте в конфиге POLICY_NAME с другим именем, и перезапустите nfqws2.';
}
const policyNote = () => { const p = policyOnly(); return p ? notice('warn', 'Проверка с роутера идёт мимо nfqws2', policyText(p)) : null; };
// спокойная строка для «Мониторинга»: проверки идут как от устройства политики
function policyProbeHint() {
  const p = S.state.intercept?.policy;
  return p?.mode === 'only' && p.probe ? h('p', { class: 'sm muted', text: `nfqws2 обрабатывает только политику доступа «${p.name}» — проверки с роутера идут как от её устройства: через то же подключение и через nfqws2.` }) : null;
}

// Ставит curl на роутере (или переставляет вместе с библиотекой, если он есть, но не запускается)
function curlInstallBtn(missing) {
  const note = h('span', { class: 'sm muted' });
  const b = btn(missing ? 'Установить curl' : 'Переустановить curl', async () => {
    b.disabled = true;
    note.textContent = 'ставлю — до 2–3 минут…';
    const r = await guarded(() => api('curl_install'));
    b.disabled = false;
    note.textContent = '';
    if (!r) return;
    if (r.ok) toast('curl установлен — подбор и проверки снова работают');
    else toast(`curl не заработал: ${r.error}. ${r.log ? 'Последние строки: ' + r.log.split('\n').slice(-3).join(' · ') : ''}`, { err: true });
    loadState().catch(() => {});
  }, 'small primary', 'download');
  return h('span', { class: 'row' }, b, note);
}

// Ставит модуль curl для PHP (php8-mod-curl): без него не работает всё, что ходит в сеть из интерфейса.
// Если модуль собран под более новый PHP (state.php_upgrade), та же кнопка обновляет PHP со всеми модулями
function phpCurlInstallBtn() {
  const note = h('span', { class: 'sm muted' });
  const b = btn(S.state.php_upgrade ? 'Обновить PHP' : 'Установить модуль curl', async () => {
    b.disabled = true;
    note.textContent = S.state.php_upgrade ? 'обновляю — до 3 минут…' : 'ставлю — до 2–3 минут…';
    const r = await guarded(() => api('php_curl_install'));
    b.disabled = false;
    note.textContent = '';
    if (!r) return;
    if (r.ok) toast('Модуль curl установлен — диагноз, подбор и проверки снова работают');
    else toast(`Модуль curl не заработал: ${r.error}. ${r.log ? 'Последние строки: ' + r.log.split('\n').slice(-3).join(' · ') : ''}`, { err: true });
    loadState().catch(() => {});
  }, 'small primary', 'download');
  return h('span', { class: 'row' }, b, note);
}

function problemItems() {
  const st = S.state;
  const items = [];
  const pol = policyOnly();
  if (pol && st.running) items.push({ level: 'warning', title: `Только политика «${pol.name}»`, text: policyText(pol), href: PAGES.raw[0] });
  // off — переходов нет дольше 20 с; одно чтение посреди перестройки правил (Keenetic) ошибкой не считается
  // the router puts the jumps back by itself (once a minute at most); the error stays only when that did not help
  if (st.running && st.intercept?.off) {
    items.push({ level: 'error', title: 'Правила перехвата не подключены', text: 'Цепочки nfqws2 в iptables есть, но переходов в них из POSTROUTING / PREROUTING нет — пакеты до nfqws2 не доходят, обход не работает. '
      + (st.intercept.heal_failed ? 'Подключить их заново не получилось. ' : '') + 'Перезапустите nfqws2; если повторяется — пришлите «Отчёт для помощи».', href: PAGES.log[0] });
  } else if (st.running && st.intercept?.healed) {
    const hd = st.intercept.healed;
    items.push({ level: 'info', title: 'Правила перехвата пропадали', text: `Переходы в цепочки nfqws2 исчезали, и обход на это время не работал. Интерфейс подключил их заново: ${plural(hd.count, 'раз', 'раза', 'раз')}, последний — ${fmtDate(hd.last)}. `
      + 'Обычно так бывает, когда роутер перестраивает межсетевой экран (например, при переподключении PPPoE), а скрипт запуска nfqws2 не вернул правила.', href: PAGES.log[0] });
  }
  if (st.running && st.devices === false) {
    items.push({ level: 'warning', title: 'Выбор устройств не действует', text: 'Правила для устройств поставить не удалось — все устройства идут через nfqws2.', href: PAGES.dev[0] });
  }
  const lint = st.lint || { issues: [] };
  if (lint.dry_run && !lint.dry_run.ok) items.push({ level: 'error', title: 'nfqws2 не примет конфиг', text: lint.dry_run.message, href: '#/settings/raw' });
  for (const x of lint.issues) {
    if (x.msg.startsWith('nfqws2: ')) continue;
    const winTs = x.msg.includes(WIN_TS_CMD);
    if (winTs && getLook().hideWinTs) continue;
    const tok = x.var && x.tok != null ? st.conf_tokens?.[x.var]?.[x.tok] : null;
    const p = st.conf_profiles.find((cp) => cp.source?.source === x.var && profIssues(cp).includes(x));
    items.push({ level: x.level, title: p ? `Профиль #${p.index} · ${profName(p)}` : (VAR_INFO[x.var]?.[0] || 'Конфиг'), text: x.msg.replace(/^Профиль #\d+: /, ''), code: tok, href: issueTarget(x), fix: winTs ? winTsActions() : fixButton(x) });
  }
  if (st.php_curl && st.php_upgrade) {
    items.push({ level: 'error', title: 'PHP старее модуля curl',
      text: 'Модуль php8-mod-curl собран под более новую версию PHP, чем стоит на роутере, поэтому не загружается. Через него роутер ходит в сеть: диагноз, подбор, мониторинг, стратегии сообщества и проверка обновлений без него не работают. '
        + `PHP ответил: «${st.php_curl}». Переустановка модуля не поможет — нужно обновить сам PHP вместе с модулями и библиотеками. Кнопка делает это; вручную — ${st.php_upgrade}`,
      href: PAGES.log[0], fix: phpCurlInstallBtn() });
  } else if (st.php_curl) {
    items.push({ level: 'error', title: 'В PHP нет модуля curl',
      text: 'Модуль php8-mod-curl не установлен или не загружается. Через него роутер ходит в сеть: диагноз, подбор, мониторинг, стратегии сообщества и проверка обновлений без него не работают. '
        + `PHP ответил: «${st.php_curl}». Кнопка ставит модуль заново; вручную — ${S.state.pkg_apk ? 'apk add php8-mod-curl' : 'cd /tmp && opkg download php8-mod-curl libcurl && opkg --cache /tmp install --force-reinstall php8-mod-curl libcurl'}.`,
      href: PAGES.log[0], fix: phpCurlInstallBtn() });
  }
  if (st.curl) {
    const missing = /not found|No such file|не найден/i.test(st.curl);
    items.push({ level: 'error', title: missing ? 'Не установлена программа curl' : 'Программа curl не запускается',
      text: (missing ? 'На роутере нет программы curl. ' : 'curl установлен, но не запускается — обычно ему не хватает библиотеки после обновления других пакетов. ')
        + `Подбор стратегий, проба «Обрыв на 16 КБ» и диагноз сайта открывают сайты именно ею: без неё ни одна стратегия не откроет сайт, и это выглядело бы как блокировка. Система ответила: «${st.curl}».`,
      href: PAGES.log[0], fix: curlInstallBtn(missing) });
  }
  if (st.running && st.pid_lost) {
    items.push({ level: 'error', title: 'nfqws2 не перезапускается', text: `Скрипт запуска nfqws2 не видит работающий процесс (PID ${st.process?.pid}): его файл PID с ним не совпадает. Так бывает, если при перезапуске рядом работал ещё один nfqws2. Пока это так, «Перезапустить» и restart в консоли его не трогают — новый конфиг не применяется. Кнопка остановит процесс сама и запустит сервис заново.`,
      href: PAGES.log[0], fix: btn('Перезапустить', safeRestart, 'small', 'refresh', { title: 'Остановить работающий nfqws2 и запустить заново, с проверкой сайтов и откатом' }) });
  }
  if (st.rivals?.length) {
    items.push({ level: 'warning', title: 'Работает другой обходчик', text: `${st.rivals.join(', ')} — он правит те же пакеты, что и nfqws2: стратегии мешают друг другу, результаты подбора ненадёжны. Оставьте что-то одно.`, href: PAGES.log[0] });
  }
  for (const f of st.logs?.files || []) {
    if (f.used < st.logs.warn) continue;
    const low = st.logs.total && st.logs.free / st.logs.total < 0.15;
    items.push({ level: f.used >= st.logs.bad || low ? 'error' : 'warning', title: 'Журнал nfqws2 занимает память',
      text: `${f.name} — ${fmtBytes(f.used)} оперативной памяти роутера (${LOG_DIR_HINT})` + (low ? `, свободно всего ${fmtBytes(st.logs.free)}` : '') + '.'
        + (st.logs.debug ? ' Отладка включена — журнал продолжит расти; больше 50 МБ программа сама подрезает его до последнего 1 МБ.' : ''),
      href: PAGES.log[0], fix: logActions(f.name, st.logs.debug) });
  }
  if (st.nfq_update?.available && !st.nfq_update.running) {
    items.push({ level: 'info', title: `Вышла nfqws2 ${st.nfq_update.latest}`, text: `Установлена ${st.version}. Обновление ставит пакет из выпуска на GitHub, перед ним делается снимок, после — проверка сайтов; прежнюю версию можно вернуть кнопкой.`,
      href: PAGES.about[0], fix: [btn('Обновить…', openNfqUpdate, 'small', 'download'), newTag('over:nfqupdate')] });
  }
  if (st.leftovers?.length) {
    items.push({ level: 'info', title: 'Остались файлы от обновления nfqws2', text: `${plural(st.leftovers.length, 'файл', 'файла', 'файлов')} с новой стандартной версией конфига или списков (${st.leftovers.slice(0, 3).map((x) => x.replace(/^lists\//, '')).join(', ')}${st.leftovers.length > 3 ? ', …' : ''}). nfqws2 их не читает — можно сравнить со своими и удалить.`,
      href: PAGES.raw[0], fix: [btn('Посмотреть', openLeftovers, 'small', 'search'), newTag('over:leftovers')] });
  }
  if (!st.running && st.stop) items.push({ level: 'error', title: 'nfqws2 остановлен', text: st.stop.text, href: st.stop.kind === 'conf' ? PAGES.raw[0] : '#/' });
  for (const o of st.auto?.offers || []) {
    items.push({ level: 'warning', title: o.host, text: `перестал открываться — автоподбор нашёл рабочую стратегию: ${o.name}`, href: PAGES.auto[0], fix: btn('Применить', () => autoApply(o), 'small', 'ok', { title: o.list ? `Добавить сайт в ${o.list.name} — стратегия уже стоит в профиле #${o.list.profile}; с проверкой и откатом` : 'Отдельный профиль только для этого сайта; с проверкой и откатом' }) });
  }
  for (const p of st.profiles) {
    if (p.state === 'dead' || p.state === 'excludes-only') items.push({ level: 'warning', title: `Профиль #${p.index}`, text: p.state === 'dead' ? 'никогда не срабатывает — его порты забирают профили выше' : 'получает только исключения профилей выше', href: `#/settings/p${p.index}` });
  }
  // неподключённые сервер не проверяет (listUsed в api.php)
  for (const l of st.lists.filter((x) => x.used.length)) {
    const pr = l.problems || {};
    if (pr.error || pr.warning || pr.info) {
      items.push({ level: pr.error ? 'error' : pr.warning ? 'warning' : 'info', title: listName(l.name),
        text: [pr.error && plural(pr.error, 'ошибка', 'ошибки', 'ошибок'), pr.warning && plural(pr.warning, 'повтор', 'повтора', 'повторов'), pr.info && plural(pr.info, 'лишняя запись', 'лишние записи', 'лишних записей')].filter(Boolean).join(', '),
        href: '#/sites/' + encodeURIComponent(l.name),
        fix: l.editable ? btn('Исправить', async () => {
          const r = await guarded(() => api('list_fix', { name: l.name }));
          if (!r) return;
          toast(`${listName(l.name)}: исправлено ${r.fixed}`, { undo: () => undoLast(false) });
          await loadState().catch(() => {});
          route();
        }, 'small', 'wand', { title: '«*.домен» → «домен», удалить повторы и лишние записи. Отменить можно кнопкой «Отменить».' }) : null });
    }
  }
  return items.sort((a, b) => LEVEL_RANK[b.level] - LEVEL_RANK[a.level]);
}

const LOG_DIR_HINT = '/var/log хранится в ОЗУ';
async function clearLog(name) {
  if (!await ask(`Очистить ${name}? Записи пропадут; nfqws2 продолжит писать в этот файл с начала, перезапуск не нужен.`, 'Очистить', 'danger', 'trash')) return false;
  const r = await guarded(() => api('log_clear', { name }), 'Журнал очищен');
  if (r) { S.state.logs = r.logs; updateSide(); }
  return !!r;
}
// Замечание «маскировка только через tcp_ts»: текст пункта — ссылка, команду из него не выделить.
// Кто уже включил метки на своём Windows, скрывает пункт — в этом браузере, вернуть можно в «Оформлении»
function winTsActions() {
  return h('span', { class: 'row', style: 'gap:6px' },
    btn('Копировать команду', () => copyText(WIN_TS_CMD), 'small', 'copy', { title: WIN_TS_CMD }),
    btn('Не показывать', () => { setLook({ hideWinTs: true }); toast('Скрыто в этом браузере. Вернуть: Оформление → «Напоминать про метки TCP в Windows»'); updateSide(); }, 'small ghost', null,
      { title: 'Метки TCP на компьютере уже включены — не напоминать в этом браузере' }));
}
function logActions(name, debug) {
  return h('span', { class: 'row', style: 'gap:6px' },
    btn('Очистить', async () => { if (await clearLog(name)) route(); }, 'small', 'trash'),
    debug ? btn('Выключить отладку', async () => {
      if (!(await saveVars({ LOG_LEVEL: '0' }, 'выключен отладочный журнал'))) return;
      toast('Отладка выключена в конфиге — перезапускаю nfqws2 с проверкой');
      safeRestart();
    }, 'small ghost', null, { title: 'LOG_LEVEL=0 и перезапуск nfqws2 с проверкой' }) : null);
}

function renderSideProblems(el) {
  const items = problemItems();
  const counts = { error: 0, warning: 0, info: 0 };
  items.forEach((x) => counts[x.level]++);
  const LIMIT = 6;
  el.replaceChildren(
    h('div', { class: 'blk-h' }, h('h2', { text: 'Проблемы' }),
      counts.error ? chip(counts.error, 'bad num') : null, counts.warning ? chip(counts.warning, 'warn num') : null, counts.info ? chip(counts.info, 'num') : null),
    items.length ? h('div', { class: 'problems' }, items.slice(0, el.dataset.all ? items.length : LIMIT).map((x) =>
      h('div', { class: 'problem' }, levelIcon(x.level),
        h('div', { class: 'item-main' },
          h('a', { href: x.href, class: 'problem-link' }, h('b', { text: x.title }), x.code ? h('code', { class: 'ellipsis', text: x.code }) : null, h('span', { class: 'sm', text: x.text })),
          x.fix ? h('div', { style: 'margin-top:4px' }, x.fix) : null)))) : h('div', { class: 'row sm' }, levelIcon('ok'), 'Проблем не найдено'),
    items.length > LIMIT && !el.dataset.all ? btn(`Показать все (${items.length})`, () => { el.dataset.all = '1'; renderSideProblems(el); }, 'small ghost') : null);
}

function renderSideBackup(el) {
  const s = S.state.snap || {};
  el.replaceChildren(
    h('div', { class: 'blk-h' }, h('h2', { text: 'Резервные копии' }), linkBtn('#/settings/backup')),
    s.count ? h('p', { class: 'sm' }, h('span', { class: 'status-ok', text: '✓ На роутере: ' }), `${plural(s.local ?? s.count, 'снимок', 'снимка', 'снимков')}` + (s.remote != null ? `, на NAS: ${s.remote}` : '') + `, последний ${fmtAgo(S.state.now - s.last)}`) : h('p', { class: 'sm muted', text: 'Снимков пока нет' }),
    h('p', { class: 'sm' }, h('a', { class: 'icon-link', href: 'api.php?download=current' }, icon('download'), 'Скачать архив сейчас')));
}

function renderSideTraffic(el) {
  const rows = S.state.iptables;
  const sum = (dir, proto) => rows.filter((x) => x.dir === dir && (!proto || x.proto === proto)).reduce((a, x) => a + x.pkts, 0);
  el.replaceChildren(
    h('div', { class: 'blk-h' }, h('h2', { text: 'Трафик в nfqws2' })),
    rows.length ? h('div', { class: 'row sm num', style: 'gap:14px' },
      h('span', {}, 'TCP ', h('b', { text: fmtNum(sum('out', 'TCP')) })), h('span', {}, 'UDP ', h('b', { text: fmtNum(sum('out', 'UDP')) })), h('span', {}, 'ответы ', h('b', { text: fmtNum(sum('in')) })))
      : h('p', { class: 'sm muted', text: 'Правил iptables нет — сервис остановлен?' }),
    h('p', { class: 'sm faint', text: 'Пакетов с загрузки правил; в nfqws2 идут только первые пакеты соединений.' }));
}

// ---------- проверка сайта ----------

function createCheck() {
  const KEY = 'nfqws-ui-recent';
  const recentGet = () => { try { return JSON.parse(localStorage.getItem(KEY)) || []; } catch { return []; } };
  const recentAdd = (host) => { try { localStorage.setItem(KEY, JSON.stringify([host, ...recentGet().filter((x) => x !== host)].slice(0, 8))); } catch { /* нет хранилища */ } };
  const input = h('input', { class: 'input', id: 'check-host', placeholder: 'сайт или ссылка', autocapitalize: 'off', autocorrect: 'off', spellcheck: 'false', inputmode: 'url', enterkeyhint: 'go', 'aria-label': 'Сайт для проверки' });
  const out = h('div', { class: 'stack' });
  const recent = h('div', { class: 'recent' });
  const drawRecent = () => recent.replaceChildren(recentGet().map((x) => h('button', { class: 'chip', type: 'button', text: x, onclick: () => run(x) })));
  let seq = 0;
  let shown = null;   // сайт, результат которого сейчас развёрнут
  // Свернуть результат (и отменить проверку, если она ещё идёт)
  const collapse = () => { seq++; shown = null; out.replaceChildren(); closeBtn.hidden = true; };
  const closeBtn = h('button', { class: 'btn ghost icon small', type: 'button', hidden: true, title: 'Свернуть результат', 'aria-label': 'Свернуть результат', onclick: () => { collapse(); input.value = ''; } }, icon('up'));
  async function run(host) {
    host = host.trim();
    if (!host) return;
    input.value = host;
    const my = ++seq;
    shown = host;
    closeBtn.hidden = false;
    out.replaceChildren(spinner('Проверяю…'));
    let res;
    try { res = await api('check', { host }); } catch (e) { out.replaceChildren(notice('bad', e.message)); return; }
    if (my !== seq) return;
    recentAdd(res.host);
    drawRecent();
    const probeBox = h('div', {}, spinner('Открываю сайт с роутера…'));
    const routesEl = routesBlock(res);
    out.replaceChildren(
      h('div', {}, h('h3', { text: res.host }), h('div', { class: 'sm muted', text: res.ips.length ? 'IP: ' + res.ips.slice(0, 3).join(', ') + (res.ips.length > 3 ? ` +${res.ips.length - 3}` : '') : 'IP не определился' })),
      res.podkop?.proxy ? notice('info', 'Идёт через podkop (прокси на VPS)', 'Для клиентов сети этот сайт уходит в туннель podkop, nfqws2 видит только соединение до сервера прокси. Проверка ниже — с самого роутера, мимо podkop.') : null,
      probeBox, routesEl, actionsBlock(res),
      h('div', { class: 'row' }, btn('Подробный диагноз', () => go(diagHref(res.host)), 'small', 'search', { title: 'По шагам: DNS, блокировка по имени или по адресу, заморозка — и поможет ли подбор' }),
        btn('Подобрать стратегию', () => go(pickHref(res.host)), 'small', 'tests'),
        h('span', { class: 'grow' }), btn('Свернуть', () => { collapse(); input.value = ''; }, 'small ghost', 'up')));
    shown = res.host;
    const pr = await api('probe', { host: res.host }).catch((e) => ({ ok: false, reason: e.message }));
    if (my !== seq) return;
    probeBox.replaceChildren(...[probeVerdict(res, pr), !pr.ok && !pr.dns ? policyNote() : null].filter(Boolean));
    // адрес подменён — маршрут через профили по настоящему адресу
    if (pr.dns && pr.routes) routesEl.replaceWith(h('div', {}, h('p', { class: 'sm muted', text: `Маршрут — по настоящему адресу ${pr.dns.real[0]}:` }), routesBlock({ ...res, routes: pr.routes })));
  }
  const el = h('section', { class: 'blk' },
    h('div', { class: 'blk-h' }, h('h2', { text: 'Проверить сайт' }), closeBtn),
    h('form', { class: 'search', onsubmit: (e) => { e.preventDefault(); run(input.value); } }, input, h('button', { class: 'btn primary check-go', type: 'submit', title: 'Проверить: открывается ли сайт и каким профилем nfqws2 он пойдёт' }, 'Проверить', icon('arrow'))),
    recent, out);
  input.addEventListener('keydown', (e) => { if (e.key === 'Escape' && shown) { e.preventDefault(); collapse(); input.value = ''; } });
  drawRecent();
  // Повторный клик по тому же сайту (в мониторинге) сворачивает результат
  const toggle = (host) => { if (shown && shown === host.trim()) { collapse(); input.value = ''; return false; } run(host); return true; };
  return { el, run, input, toggle, collapse, shown: () => shown };
}

function checkHost(host) {
  if (overviewCol()) {
    if (S.check.toggle(host)) S.check.el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    return;
  }
  // в адрес попадает только имя сайта: «https://сайт/страница» → «сайт»
  const name = host.trim().replace(/^[a-z]+:\/\//i, '').replace(/[/?#].*$/, '');
  const hash = layout() === 'site' ? '#/site/' + encodeURIComponent(name) : '#/?q=' + encodeURIComponent(host.trim());
  if (location.hash === hash) route(); else go(hash);
}

// Ограничение ТСПУ «16-20 КБ»: соединение с зарубежным хостингом замирает на объёме. Стратегии nfqws2 его не снимают.
const isFreeze = (reason) => !!reason && reason.includes('на объёме');
const FREEZE_HINT = 'Похоже на ограничение ТСПУ «16-20 КБ»: соединения с зарубежными хостингами (Hetzner, DigitalOcean, OVH, Contabo…) зависают после ~16–20 КБ — маленькие страницы открываются, остальное нет. Обычные стратегии его не снимают, но иногда помогает много фейков с разрешённым именем — это пробует подбор с уточнением. Если не поможет и он — нужен VPN или прокси. Проверить провайдера можно чекером hyperion-cs.github.io/dpi-checkers/ru/tcp-16-20.';

// Инструкция: как включить защищённый DNS в роутере
const wikiDns = () => REPO + '/wiki/' + encodeURIComponent('11.-Решение-возможных-проблем') + '#' + encodeURIComponent('13-dns-роутера-подменяет-адрес-заглушка-провайдера');

// DNS роутера отдаёт не тот адрес (обычно заглушку провайдера): стратегии nfqws2 тут ни при чём
function dnsSpoofNotice(sp) {
  const sys = sp.system.join(', ');
  const real = sp.real.slice(0, 2).join(', ');
  // адрес не из сети провайдера: это не подмена, а другой сервер того же сайта, закрытый по IP
  if (sp.fake === false) return notice('warn', 'Не открывается по адресу, который дал роутер',
    `Роутер получил ${sys}, а защищённый DNS отвечает ${real}. Это не подмена: адрес не из сети провайдера, просто большие сети (Meta, Google, CDN) отдают разным DNS разные серверы. `
    + `По адресу ${sp.real[0]} сайт через nfqws2 открывается (HTTP ${sp.real_code}, ${sp.real_ms} мс), а по ${sp.system[0]} — нет. Скорее всего, провайдер закрыл этот сервер по IP, и стратегия nfqws2 тут не поможет. DNS менять не нужно.`);
  return notice(sp.real_ok ? 'warn' : 'bad', 'Не открывается: DNS роутера подменяет адрес',
    `Роутер получил ${sys}` + (sp.isp ? ' — это сеть вашего провайдера, похоже на заглушку' : '') + `, а защищённый DNS отвечает ${real}. `
    + (sp.real_ok ? `По настоящему адресу сайт через nfqws2 открывается (HTTP ${sp.real_code}, ${sp.real_ms} мс), так что стратегия ни при чём. Устройства, которые берут адреса у роутера, тоже получают неверный адрес.`
      : `По настоящему адресу тоже не открывается: ${sp.real_reason}. Сначала включите защищённый DNS и проверьте ещё раз; не откроется — подберите стратегию.`)
    + ' Включите в роутере DNS-over-HTTPS или DNS-over-TLS.',
    h('div', { class: 'row' }, h('a', { class: 'btn small', href: wikiDns(), target: '_blank', rel: 'noopener' }, 'Как включить защищённый DNS'),
      h('a', { class: 'btn small', href: PAGES.dns[0] }, 'Какой DNS у вас работает')));
}

function probeVerdict(res, pr) {
  const p = res.routes.https.profile ? prof(res.routes.https.profile) : null;
  if (pr.dns) return dnsSpoofNotice(pr.dns);
  if (pr.ok) return notice('ok', 'Открывается', `HTTP ${pr.code}, ${pr.ms} мс.` + (p ? ` HTTPS — профиль #${p.index}.` : ' nfqws2 его не трогает — и не нужно.'));
  if (pr.reason && pr.reason.includes('DNS')) return notice('warn', 'Не открывается: ' + pr.reason, 'Проблема DNS, а не DPI — списки nfqws2 тут не помогут.');
  if (isFreeze(pr.reason)) return notice('bad', 'Открывается частично: ' + pr.reason, FREEZE_HINT + (p ? ` Сейчас HTTPS идёт через профиль #${p.index}.` : ''));
  if (!p) return notice('bad', 'Не открывается: ' + pr.reason, 'nfqws2 его не обрабатывает. Добавьте сайт в список, который ведёт в подходящий профиль, или подберите стратегию тестом.');
  if (pr.direct?.ok) return brokenNotice(res.host, p, `Через профиль #${p.index} «${profName(p)}»: ${pr.reason.replace(/ — похоже.*$/, '')}. Мимо nfqws2 открывается: HTTP ${pr.direct.code} за ${pr.direct.ms} мс. Обход этому сайту не нужен — исключите его из профиля.`);
  return notice('bad', 'Не открывается: ' + pr.reason, `Профиль #${p.index} (${strategyText(p)}) не помогает. Подберите стратегию тестом или исключите сайт из этого профиля.`);
}

// Сайт не заблокирован, а профиль его ломает: итог с кнопкой «Исключить из профиля #N»
function brokenNotice(host, p, text) {
  return notice('warn', `Сайт не заблокирован — его ломает профиль #${p.index}`, text,
    h('div', { class: 'notice-actions full' }, btn(`Исключить из профиля #${p.index}`, () => excludeDialog(host, p.index), 'primary small'), newTag('over:exclude'),
      btn('Подобрать стратегию', () => go(pickHref(host)), 'ghost small', 'tests')));
}

// Окно: что исключить (имя, домен, адрес, сеть) и что для этого изменится; потом — перезапуск с проверкой
async function excludeDialog(host, index) {
  const wait = modal(`Исключить из профиля #${index}`, h('div', { class: 'modal-b' }, spinner('Смотрю, что можно исключить…')));
  const r = await api('exclude_preview', { host, profile: index }).catch((e) => { toast(e.message, { err: true }); return null; });
  wait.close();
  if (!r) return;
  let mode = r.options[0]?.mode;
  const changes = h('div', { class: 'diff' });
  const drawChanges = () => {
    const o = r.options.find((x) => x.mode === mode);
    const lines = [];
    o.files.forEach((f) => {
      lines.push(h('div', { text: f.name }));
      (f.add || []).forEach((x) => lines.push(h('div', { class: 'add', text: '+ ' + x })));
      (f.remove || []).forEach((x) => lines.push(h('div', { class: 'del', text: '− ' + x })));
    });
    if (o.token) lines.push(h('div', { text: `nfqws2.conf, профиль #${index}` }), h('div', { class: 'add', text: '+ ' + o.token }));
    changes.replaceChildren(...lines);
  };
  const opts = r.options.map((o, k) => h('label', { class: 'ex-opt' },
    h('input', { type: 'radio', name: 'ex-mode', value: o.mode, checked: k === 0, onchange: () => { mode = o.mode; drawChanges(); } }),
    h('span', {}, h('b', { text: o.title }), h('br'), h('span', { class: 'sm muted', text: o.hint }))));
  drawChanges();
  const go = btn('Исключить и перезапустить с проверкой', async () => {
    go.disabled = true;
    const x = await guarded(() => api('exclude_apply', { host, profile: index, mode }));
    bg.close();
    if (!x) return;
    toast(`Исключено: ${x.title}. Файлы: ${x.files.join(', ')}`);
    S.conf = null;
    await safeRestart();
    route(true);
  }, 'primary');
  const name = r.name ? ` «${r.name}»` : '';
  const bg = modal(`Исключить из профиля #${index}${name}`, h('div', { class: 'modal-b stack' },
    h('div', { class: 'stack ex-opts' }, opts),
    h('div', { class: 'sm muted', text: 'Что изменится:' }), changes,
    h('p', { class: 'sm', text: 'После этого nfqws2 перезапустится с проверкой: если сайты мониторинга перестанут открываться или вы не подтвердите за 3 минуты, всё вернётся как было. «Отменить» в шапке тоже вернёт.' })), go);
}

function routesBlock(res) {
  const labels = { https: 'HTTPS', quic: 'QUIC', http: 'HTTP' };
  return h('div', { class: 'routes' }, Object.entries(res.routes).map(([k, rt]) => {
    const p = rt.profile ? prof(rt.profile) : null;
    const relevant = rt.steps.filter((s) => !s.skip);
    const last = relevant[relevant.length - 1];
    return h('div', { class: 'route' }, h('div', { class: 'route-k', text: labels[k] }),
      h('div', { class: 'item-main' },
        p ? h('div', {}, h('a', { href: `#/settings/p${p.index}`, text: `#${p.index} ${profName(p)}` }), ' ', chip(strategyText(p), 'accent'))
          : h('div', {}, h('b', { text: 'не обрабатывается' }), h('span', { class: 'sm muted', text: ' — ' + (last ? last.why : 'нет профилей на этот порт') })),
        relevant.length ? h('details', {}, h('summary', { text: 'почему' }),
          h('ul', { class: 'steps-why' }, relevant.map((s) => h('li', { class: s.ok ? 'ok' : '', text: `#${s.profile}: ${s.ok ? '✓' : '✗'} ${s.why}` })))) : null));
  }));
}

function actionsBlock(res) {
  const box = h('div', { class: 'stack' });
  if (res.in_lists.length) {
    box.append(h('div', { class: 'chips' }, h('span', { class: 'sm muted', text: 'Есть в: ' }),
      res.in_lists.map((x) => h('a', { class: 'chip ' + (x.used ? 'accent' : ''), href: '#/sites/' + encodeURIComponent(x.list), text: listName(x.list) + (x.entry !== res.host ? ` (как ${x.entry})` : '') + (x.used ? '' : ' · не исп.') }))));
  }
  if (res.ips[0] === res.host) return box;
  const rows = S.state.lists.filter((l) => l.kind === 'host' && l.used.length && l.editable).map((l) => {
    const has = res.in_lists.find((x) => x.list === l.name);
    const isExclude = l.used.every((u) => u.role === 'exclude');
    const change = async (cmd, item) => {
      if (!await guarded(() => api(cmd, { name: l.name, items: [item] }))) return;
      await loadState().catch(() => {});
      toast(cmd === 'list_add' ? `Добавлено в ${l.name}` : `Удалено из ${l.name}`, {
        undo: async () => { await guarded(() => api(cmd === 'list_add' ? 'list_remove' : 'list_add', { name: l.name, items: [item] })); await loadState().catch(() => {}); S.check.run(res.host); },
      });
      S.check.run(res.host);
    };
    let control;
    if (!has) control = btn(isExclude ? 'Исключить' : 'Добавить', () => change('list_add', res.host), 'small', 'plus');
    else if (has.entry === res.host) control = btn('Убрать', () => change('list_remove', res.host), 'small danger', 'x');
    else control = btn(`Убрать ${has.entry}`, async () => { if (await ask(`Убрать ${has.entry} целиком?\n\nСайт входит в список через ${has.entry}. Это затронет и другие его поддомены.`, 'Убрать', 'danger')) change('list_remove', has.entry); }, 'small danger', 'x');
    return h('div', { class: 'act' }, h('div', { class: 'item-main' }, h('b', { text: listName(l.name) }), h('span', { class: 'sm muted', text: listEffect(l) })), control);
  });
  box.append(h('div', {}, rows));
  return box;
}

// Виды страницы «Обзор»: одни и те же блоки, разложенные по-разному. Выбор хранится вместе с оформлением.
const OVERVIEWS = [['brief', 'Кратко'], ['groups', 'Группы'], ['tiles', 'Плитки'], ['masonry', 'Кладка'], ['panel', 'Панель']];

async function viewOverview(main, r) {
  sideBlocks();
  const look = getLook();
  const view = OVERVIEWS.some(([v]) => v === look.overview) ? look.overview : simple() ? 'brief' : isKLike(look.variant) ? 'masonry' : 'groups';   // в стиле K — отдельные карточки, как «Системный монитор»
  const B = { svc: SIDE.service, check: S.check.el, prob: SIDE.problems, mon: SIDE.monitor, backup: SIDE.backup, traffic: SIDE.traffic, ...overviewExtra() };
  const grp = (title, ...blocks) => h('div', { class: 'ogrp' }, h('h3', { text: title }), ...blocks);
  const body = {
    // главное сверху (всё ли работает, «сайт не открывается?»), остальное — свёрнутыми строками с итогом
    brief: () => overviewBrief(B),
    // три колонки по смыслу, внутри группы блоки соприкасаются
    groups: () => h('div', { class: 'ov ov-groups' }, grp('Сейчас', B.svc, B.check, B.prob, B.mon, B.traffic), grp('Настроено', B.profs, B.lists, B.backup), grp('Недавно', B.picks, B.changes, B.ver)),
    // ряд плиток с главными цифрами, ниже слева главное, справа недавнее
    tiles: () => h('div', { class: 'ov ov-tiles' }, overviewTiles(),
      h('div', { class: 'ov-cols' }, h('div', { class: 'stack' }, B.check, h('div', { class: 'ov-pair' }, B.mon, B.profs)), h('div', { class: 'stack' }, B.prob, B.picks, B.changes, B.lists, B.traffic, B.ver))),
    // отдельные карточки, плотно уложенные в колонки
    masonry: () => h('div', { class: 'ov ov-masonry' }, B.svc, B.check, B.prob, B.mon, B.profs, B.lists, B.picks, B.changes, B.backup, B.traffic, B.ver),
    // одна панель, поделённая линиями на ячейки
    panel: () => h('div', { class: 'ov ov-panel' }, B.svc, B.check, B.prob, B.mon, B.profs, h('div', { class: 'ocell' }, B.lists, B.backup, B.traffic), B.picks, B.changes, B.ver),
  }[view]();
  main.append(
    h('div', { class: 'vh' }, h('h1', { text: 'Обзор' }), h('span', { class: 'grow' }),
      h('div', { class: 'seg', role: 'radiogroup', 'aria-label': 'Вид обзора' }, OVERVIEWS.map(([v, t]) =>
        h('button', { type: 'button', role: 'radio', 'aria-checked': String(v === view), class: v === view ? 'on' : '', text: t, onclick: () => { setLook({ overview: v }); route(); } })))),
    body);
  updateSide();
  if (r.q.get('q')) S.check.run(r.q.get('q'));
}

// «Кратко»: строка «всё ли работает», поле проверки сайта и свёрнутые подробности.
// Что раскрыто, запоминается в браузере; при остановке или замечаниях нужный блок раскрыт сам.
function overviewBrief(B) {
  const st = S.state;
  const KEY = 'nfqws-ui-ov-open';
  let open = [];
  try { open = JSON.parse(localStorage.getItem(KEY)) || []; } catch { /* нет хранилища */ }
  const m = st.monitor?.sites || [];
  const okN = m.filter((x) => x.last && x.last[1]).length;
  const problems = problemItems().filter((x) => x.level !== 'info');
  const profs = st.conf_profiles;
  const bad = profs.filter((p) => worst(profIssues(p)) === 'error' || ['dead', 'excludes-only'].includes(p.state)).length;
  const used = st.lists.filter((l) => l.used.length);
  const [lvl, title] = !st.running ? ['bad', 'nfqws2 остановлен'] : problems.length ? ['warn', 'Есть замечания']
    : m.length && okN < m.length ? ['warn', 'Не все сайты открываются'] : ['ok', 'Всё работает'];
  const facts = [st.running && st.process?.started ? 'nfqws2 запущен ' + fmtAgo(st.now - st.process.started) : null,
    m.length ? `${okN} из ${m.length} сайтов открываются` : null,
    problems.length ? plural(problems.length, 'замечание', 'замечания', 'замечаний') + ' к конфигу и спискам' : 'замечаний к конфигу нет'].filter(Boolean);
  const HREF = { svc: null, prob: null, mon: PAGES.mon[0], profs: PAGES.prof[0], lists: PAGES.lists[0], picks: PAGES.phist[0], changes: PAGES.hist[0], backup: PAGES.backup[0], more: PAGES.about[0] };
  const fold = (id, label, summary, block, force) => {
    const d = h('details', { class: 'ofold', open: force || open.includes(id) }, h('summary', {}, h('b', { text: label }), h('span', { class: 'sm faint ellipsis' }, summary),
      HREF[id] ? linkBtn(HREF[id], 'Открыть', { onclick: (e) => e.stopPropagation() }) : null), block);
    d.addEventListener('toggle', () => {
      // раскрытое самим интерфейсом (остановка, замечания, упавший сайт) не запоминаем — иначе останется раскрытым навсегда
      if (force && d.open) return;
      open = d.open ? [...new Set([...open, id])] : open.filter((x) => x !== id);
      try { localStorage.setItem(KEY, JSON.stringify(open)); } catch { /* нет хранилища */ }
    });
    return d;
  };
  const picksSum = h('span', {});
  const changesSum = h('span', {});
  B.picksReq?.then((r) => { const x = r.items?.[0]; picksSum.textContent = x ? `${x.host} · ${fmtDate(x.ts)}` : 'ещё не запускался'; }).catch(() => {});
  B.changesReq?.then((r) => { const x = r.items?.[0]; changesSum.textContent = x ? `${x.note || x.file} · ${fmtDate(x.ts)}` : 'не было'; }).catch(() => {});
  const folds = [
    problems.length ? fold('prob', 'Замечания', plural(problems.length, 'замечание', 'замечания', 'замечаний'), B.prob, true) : null,
    fold('svc', 'Сервис', [st.version && 'nfqws2 v' + st.version, st.running ? 'работает' : 'остановлен'].filter(Boolean).join(' · '), B.svc, !st.running),
    m.length ? fold('mon', 'Мониторинг', `${okN} из ${m.length}` + (st.monitor.last ? ' · ' + fmtAgo(st.now - st.monitor.last) : ''), B.mon, okN < m.length) : null,
    fold('profs', 'Профили', `${profs.length} · ` + (bad ? `${bad} с проблемами` : 'все в порядке'), B.profs),
    fold('lists', 'Списки', `${used.length} из ${st.lists.length} используются · ${fmtNum(used.reduce((a, l) => a + l.entries, 0))} записей`, B.lists),
    fold('picks', 'Последние подборы', picksSum, B.picks),
    fold('changes', 'Последние изменения', changesSum, B.changes),
    fold('backup', 'Резервные копии', st.snap?.count ? `${st.snap.count} · последняя ` + fmtAgo(st.now - st.snap.last) : 'снимков нет', B.backup),
    fold('more', 'Трафик и версии', `nfqws2-ui v${st.ui?.version || '?'}` + (st.ui?.update?.available ? ` · есть ${st.ui.update.latest}` : ''), h('div', {}, B.traffic, B.ver)),
  ].filter(Boolean);
  return h('div', { class: 'ov ov-brief' },
    h('section', { class: 'blk ov-state ' + lvl }, h('span', { class: 'big' }, h('span', { class: 'dot' }), title), h('span', { class: 'sm muted', text: facts.join(' · ') })),
    h('section', { class: 'blk ov-ask' }, h('h2', { text: 'Сайт не открывается?' }), B.check),
    h('div', { class: 'blk ov-folds' }, folds));
}

// Плитки с главными цифрами — состояние читается с одного взгляда
function overviewTiles() {
  const st = S.state;
  const tile = (cls, href, label, value, sub) => h('a', { class: 'tile ' + cls, href }, h('small', { text: label }), h('b', { text: value }), h('span', { text: sub }));
  const m = st.monitor?.sites || [];
  const okN = m.filter((x) => x.last && x.last[1]).length;
  const problems = problemItems().filter((x) => x.level !== 'info');
  const profs = st.conf_profiles;
  const bad = profs.filter((p) => worst(profIssues(p)) === 'error' || ['dead', 'excludes-only'].includes(p.state)).length;
  const used = st.lists.filter((l) => l.used.length);
  return h('div', { class: 'tiles' },
    tile(svcState(st)[0], '#/', 'Сервис', svcState(st)[1], st.running && st.process?.started ? 'запущен ' + fmtAgo(st.now - st.process.started) : 'обход не работает'),
    tile(!m.length ? '' : okN === m.length ? 'ok' : 'bad', PAGES.mon[0], 'Сайты', m.length ? `${okN} из ${m.length}` : '—', m.length ? (st.monitor.last ? 'проверены ' + fmtAgo(st.now - st.monitor.last) : 'ещё не проверялись') : 'мониторинг пуст'),
    tile(problems.length ? 'bad' : 'ok', problems[0]?.href || PAGES.raw[0], 'Проблемы', String(problems.length), problems.length ? problems[0].title : 'конфиг и списки в порядке'),
    tile(bad ? 'bad' : '', PAGES.prof[0], 'Профили', String(profs.length), bad ? `${bad} с проблемами` : 'все срабатывают'),
    tile('', PAGES.lists[0], 'Списки', `${used.length} из ${st.lists.length}`, fmtNum(used.reduce((a, l) => a + l.entries, 0)) + ' записей'),
    tile('', PAGES.backup[0], 'Копии', String(st.snap?.count || 0), st.snap?.last ? 'последняя ' + fmtAgo(st.now - st.snap.last) : 'снимков нет'));
}

function overviewExtra() {
  const st = S.state;
  const blk = (title, href, ...body) => h('section', { class: 'blk' },
    h('div', { class: 'blk-h' }, h('h2', { text: title }), href ? linkBtn(href) : null), ...body);
  const kv = (k, ...v) => h('div', { class: 'kv' }, h('span', { class: 'muted', text: k }), h('span', {}, ...v));
  const profs = st.conf_profiles;
  const bad = profs.filter((p) => worst(profIssues(p)) === 'error' || ['dead', 'excludes-only'].includes(p.state)).length;
  const used = st.lists.filter((l) => l.used.length);
  const withProblems = used.filter((l) => l.problems?.error || l.problems?.warning).length;
  const changes = h('div', { class: 'over-rows' }, h('span', { class: 'sm muted', text: 'загрузка…' }));
  const picks = h('div', { class: 'over-rows' }, h('span', { class: 'sm muted', text: 'загрузка…' }));
  const u = st.ui?.update || {};
  const out = {};
  out.profs = blk('Профили', PAGES.prof[0],
      h('p', { class: 'sm' }, plural(profs.length, 'профиль', 'профиля', 'профилей'), bad ? h('span', { class: 'status-bad', text: ` · ${bad} с проблемами` }) : h('span', { class: 'status-ok', text: ' · все в порядке' })),
      h('div', { class: 'over-rows' }, profs.map((p) => h('a', { class: 'over-row', href: `#/settings/p${p.index}`, title: profScope(p) },
        h('span', { class: 'pn ' + (worst(profIssues(p)) === 'error' ? 'err' : p.state), text: p.index }),
        h('span', { class: 'ellipsis', text: profName(p) }), h('span', { class: 'sm faint ellipsis', text: strategyText(p) })))));
  out.lists = blk('Списки', PAGES.lists[0],
      h('p', { class: 'sm' }, `${used.length} из ${st.lists.length} используются · ${fmtNum(used.reduce((a, l) => a + l.entries, 0))} записей`,
        withProblems ? h('span', { class: 'status-bad', text: ` · ${withProblems} с замечаниями` }) : null),
      h('div', { class: 'over-rows' }, [...used].sort((a, b) => b.entries - a.entries).slice(0, 6).map((l) => h('a', { class: 'over-row', href: '#/sites/' + encodeURIComponent(l.name), title: l.name },
        h('span', { class: 'ellipsis', text: listName(l.name) }), h('span', { class: 'sm faint num', text: fmtNum(l.entries) })))));
  out.picks = blk('Последние подборы', PAGES.phist[0], picks);
  out.changes = blk('Последние изменения', PAGES.hist[0], changes);
  out.ver = blk('Версии', PAGES.about[0],
      kv('nfqws2', st.version ? 'v' + st.version : 'не определена'),
      kv('nfqws2-ui', 'v' + (st.ui?.version || '?'), u.available ? h('a', { class: 'chip ok', href: PAGES.about[0], text: `есть ${u.latest}` }) : null),
      kv('Конфиг', h('a', { class: 'mono', href: PAGES.raw[0], text: st.ui?.conf_file || 'nfqws2.conf' })),
      kv('Изменён', st.conf_mtime ? fmtAgo(st.now - st.conf_mtime) : '—'),
      h('p', { class: 'sm' }, h('a', { href: '#/settings/readme', text: 'Справка' }), ' · ', h('a', { href: REPO + '/wiki/nfqws2%E2%80%90ui', target: '_blank', rel: 'noopener', text: 'инструкция' }), ' · ', h('a', { href: '#/settings/changelog', text: 'изменения по версиям' })));
  out.picksReq = api('tests_history');
  out.changesReq = api('history_log', { limit: 6 });
  out.picksReq.then((r) => {
    const items = (r.items || []).slice(0, 5);
    picks.replaceChildren(items.length ? items.map((x) => h('a', { class: 'over-row', href: pickHistHref(x.host) },
      levelIcon(x.baseline?.ok ? 'info' : x.best ? 'ok' : 'error'), h('span', { class: 'ellipsis mono', text: x.host }),
      h('span', { class: 'sm faint ellipsis', text: x.baseline?.ok ? 'открывается и так' : x.best || 'ничего не помогло' })))
      : h('span', { class: 'sm muted', text: 'Подбор стратегии ещё не запускался.' }));
  }).catch(() => picks.replaceChildren());
  out.changesReq.then((r) => {
    changes.replaceChildren(r.items.length ? r.items.map((e) => h('div', { class: 'over-row' },
      h('span', { class: 'sm faint num nowrap', text: fmtDate(e.ts) }),
      h('span', { class: 'ellipsis', title: e.note || '', text: e.file === '*' ? e.note : [e.file === 'nfqws2.conf' ? 'Конфиг' : listName(e.file), e.note].filter(Boolean).join(': ') })))
      : h('span', { class: 'sm muted', text: 'Изменений пока не было.' }));
  }).catch(() => changes.replaceChildren());
  return out;
}

// Применить найденное автоподбором: отдельный профиль только для сайта, роутер сам проверяет и откатывает
async function autoApply(o) {
  const host = o.host;
  // сработала стратегия существующего профиля — достаточно записи в его списке, перезапуск не нужен
  const q = o.list
    ? `Добавить ${host} в ${o.list.name}?\n\nНайденная стратегия уже стоит в профиле #${o.list.profile}, сайта просто нет в его списке. Новый профиль не создаётся, nfqws2 не перезапускается. Роутер сам проверит ${host}: если сайт не откроется, запись уберётся обратно.`
    : `Применить найденную стратегию только для ${host}?\n\nБудет создан отдельный профиль (или заменена стратегия уже созданного). nfqws2 перезапустится — на несколько секунд обход прервётся. Роутер сам проверит ${host} и сайты мониторинга и вернёт конфиг, если что-то пойдёт не так. Это займёт до минуты.`;
  if (!await ask(q, o.list ? 'Добавить' : 'Применить')) return;
  toast(o.list ? 'Добавляю в список и проверяю…' : 'Применяю и проверяю — до минуты…');
  const r = await guarded(() => api('auto_apply', { host }));
  S.conf = null;
  await loadState().catch(() => {});
  if (r) toast(r.text);
  route(true);
}
