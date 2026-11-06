'use strict';
// Interface: updating the interface, the loaded build.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ обновление nfqws2-ui ============

const REPO = 'https://github.com/zemidala/nfqws2-ui';

function updateSkipped(v) {
  try { return localStorage.getItem('nfqws-ui-update-skip') === v; } catch { return false; }
}

// Сборка, которую загрузил браузер: версия и хеш файлов из адреса app.js?v=… (подставляет scripts/build.sh)
const BUILD = (() => { try { return new URL(document.currentScript.src).searchParams.get('v') || ''; } catch { return ''; } })();

// Браузер может держать в кеше старую страницу. Сверяемся с роутером: если там уже другая сборка —
// предлагаем перезагрузить (при открытии и при возврате на вкладку, не чаще раза в 5 минут).
let freshChecked = 0;
async function routerBuild() {
  const text = await (await fetch('index.html?_=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' })).text();
  return text.match(/app\.js\?v=([^"]+)/)?.[1];
}
async function reloadStale() {
  // обновляем запись в кеше браузера и только потом перезагружаем страницу
  await fetch('index.html', { cache: 'reload', credentials: 'same-origin' }).catch(() => {});
  location.reload();
}
async function reloadIfStale() {
  const onRouter = BUILD ? await routerBuild().catch(() => null) : null;
  if (!onRouter || onRouter === BUILD) return false;
  await reloadStale();
  return true;
}
// Один раз после обновления до 1.5: разделы переехали из вкладок сверху в меню. Тем, кто компоновку уже выбрал, не показываем.
function layoutNote() {
  let seen = true;
  try { seen = !!localStorage.getItem('nfqws-ui-layout-note') || 'layout' in JSON.parse(localStorage.getItem('nfqws-ui-look') || '{}'); } catch { /* нет хранилища */ }
  if (seen || document.getElementById('layout-note')) return;
  const done = () => { try { localStorage.setItem('nfqws-ui-layout-note', '1'); } catch { /* нет хранилища */ } document.getElementById('layout-note')?.remove(); };
  document.getElementById('upd-banner')?.before(h('div', { id: 'layout-note', class: 'banner info' }, h('div', { class: 'banner-in' },
    h('span', { text: 'Вид изменился: разделы теперь в меню — слева на широком экране, кнопка «Меню» на узком. Привычные вкладки сверху можно вернуть в «Оформлении».' }),
    btn('Оформление', () => { done(); go(PAGES.look[0]); }, 'small primary'), btn('Понятно', done, 'small ghost'))));
}

async function checkFresh() {
  if (!BUILD || Date.now() - freshChecked < 300000) return;
  freshChecked = Date.now();
  try {
    const onRouter = await routerBuild();
    if (!onRouter || onRouter === BUILD || document.getElementById('stale')) return;
    document.getElementById('upd-banner')?.before(h('div', { id: 'stale', class: 'banner info' }, h('div', { class: 'banner-in' },
      h('span', { text: 'Интерфейс на роутере обновился, а в браузере открыта прежняя сборка.' }), btn('Перезагрузить', reloadStale, 'small primary', 'refresh'))));
  } catch { /* роутер недоступен — проверим в следующий раз */ }
}
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && S.auth) checkFresh(); });

// Проверка в фоне при открытии интерфейса — если последняя была больше 12 часов назад
async function autoUpdateCheck() {
  if (!S.state) { setTimeout(autoUpdateCheck, 3000); return; }
  const u = S.state.ui?.update;
  if (u && Date.now() / 1000 - u.checked < 12 * 3600) return;
  const r = await api('update_check').catch(() => null);
  if (r && S.state?.ui) { S.state.ui.update = { ...S.state.ui.update, ...r }; updateChrome(); }
}

// Окно «Что нового» и само обновление с живым журналом установки
function openUpdate(u, run = false) {
  // a fixed height: a growing log would stretch the centred window and move everything in it on every poll
  const log = h('pre', { class: 'box', style: 'height:40vh' });
  const status = h('div');
  const about = h('div', { class: 'stack', style: 'gap:12px' },
    u.notes ? h('div', { class: 'release-notes' }, md(u.notes)) : h('p', { class: 'sm muted', text: 'Описания изменений нет.' }),
    h('p', { class: 'sm muted' }, 'Обновление ставит пакет с GitHub (с проверкой контрольной суммы) и перезапускает веб-сервер интерфейса. nfqws2, конфиг, списки и доступ в интернет не затрагиваются. Из консоли: ', h('code', { text: 'nfqws-ui update' })));
  const body = h('div', { class: 'modal-b stack', style: 'gap:12px' },
    h('p', {}, `Установлено: `, h('b', { text: u.current }), ` · последняя: `, h('b', { text: u.latest || '—' }), ' · ', h('a', { href: u.url || REPO + '/releases', target: '_blank', rel: 'noopener', text: 'страница релиза' })),
    about, status, copyBox(log));
  log.hidden = true;
  const showLog = () => { about.remove(); log.hidden = false; };
  const go = btn('Обновить до ' + u.latest, () => start(), 'primary', 'download', { disabled: !u.can_update || u.running });
  const dlg = modal('nfqws2-ui ' + (u.latest || ''), body, u.available ? go : []);
  let timer = null;
  let started = 0;
  const poll = async () => {
    const r = await api('update_status').catch(() => null);   // пока lighttpd перезапускается, ответа нет — ждём
    if (!dlg.isConnected) return;
    // новая версия не приняла вход: журнал без входа не прочитать, а под окном уже форма входа прежнего кода —
    // перезагружаем страницу, войти можно будет уже в новой версии
    if (!S.auth && started) { status.replaceChildren(notice('ok', 'Интерфейс обновился', 'Нужно войти заново — страница перезагрузится.')); setTimeout(reloadStale, 2000); return; }
    // журнал пуст и установка не идёт — запуск не состоялся (раньше окно ждало бесконечно)
    if (r && !r.log.trim() && !r.running && started && Date.now() - started > 20000) {
      status.replaceChildren(notice('bad', 'Обновление не запустилось', 'Интерфейс не смог запустить установку на роутере. Обновите из консоли роутера командой ниже и напишите нам, на каком роутере это случилось.',
        h('div', { class: 'row' }, h('code', { text: 'nfqws-ui update' }), btn('Копировать', () => copyText('nfqws-ui update'), 'small ghost', 'copy'))));
      go.disabled = false;
      return;
    }
    if (r) {
      showLog();
      drawLog(log, r.log.trim().split('\n').filter(Boolean), 'Запускаю…');
      log.scrollTop = log.scrollHeight;
      if (r.exit !== null || (!r.running && r.version === u.latest)) {
        if (r.exit === 0 || r.version === u.latest) {
          status.replaceChildren(notice('ok', `Готово: nfqws2-ui ${r.version}`, 'Страница перезагрузится через 3 секунды.'));
          setTimeout(() => location.reload(), 3000);
        } else {
          status.replaceChildren(notice('bad', 'Обновление не удалось', 'Подробности — в журнале ниже. Интерфейс остался на прежней версии.'));
          go.disabled = false;
        }
        return;
      }
    }
    timer = setTimeout(poll, 2000);
  };
  async function start() {
    if (!await ask(`Обновить nfqws2-ui до ${u.latest}?\n\nВеб-сервер интерфейса перезапустится — страница на несколько секунд перестанет отвечать. nfqws2 и интернет это не затрагивает.`, 'Обновить')) return;
    go.disabled = true;
    const r = await guarded(() => api('update_run', { version: u.latest }));
    if (!r) { go.disabled = false; return; }
    status.replaceChildren(h('div', { class: 'row sm' }, h('span', { class: 'spin' }), 'Обновляю… не закрывайте окно'));
    showLog();
    started = Date.now();
    clearTimeout(timer);
    poll();
  }
  if (u.running) poll();
  if (run && u.available && u.can_update && !u.running) start();
}

// Обновление самого nfqws2 (пакет nfqws2-keenetic) и возврат прежней версии
async function openNfqUpdate() {
  const status = h('div');
  const log = h('pre', { class: 'box', style: 'height:40vh', hidden: true });   // fixed height — see openUpdate
  const head = h('div', { class: 'stack', style: 'gap:12px' }, spinner('Проверяю…'));
  const body = h('div', { class: 'modal-b stack', style: 'gap:12px' }, head, status, copyBox(log));
  const go = btn('Обновить', null, 'primary', 'download', { hidden: true });
  const back = btn('Вернуть', null, 'warn', 'undo', { hidden: true });
  const recheck = btn('Проверить снова', () => draw(true), 'ghost', 'refresh');
  const dlg = modal('Обновление nfqws2', body, [recheck, back, go]);
  let timer = null;
  let u = null;

  const result = (r) => {
    const worse = r.worse || [];
    const ok = (r.checks || []).filter((c) => c.ok).length;
    return h('div', { class: 'stack', style: 'gap:8px' },
      notice(!r.running ? 'bad' : worse.length ? 'warn' : 'ok',
        !r.running ? `nfqws2 ${r.to} не запустился` : worse.length ? `nfqws2 ${r.to} работает, но часть сайтов перестала открываться` : `Готово: nfqws2 ${r.to} работает`,
        (r.checks?.length ? `Сайты мониторинга: открываются ${ok} из ${r.checks.length}.` : 'Сайтов мониторинга нет — проверьте нужные сайты сами.')
          + (worse.length ? ` До ${r.rollback ? 'возврата' : 'обновления'} открывались, а теперь нет: ${worse.join(', ')}. Сбой бывает и разовым — проверьте ещё раз через минуту.` : '')
          + (!r.rollback && (worse.length || !r.running) ? ` Вернуть ${r.from} можно кнопкой ниже: прежний пакет и ваш конфиг со списками из снимка.` : '')),
      r.leftovers ? h('p', { class: 'sm' }, 'Менеджер пакетов положил рядом с вашими файлами новые стандартные версии (-opkg). ',
        h('a', { href: '#', onclick: (e) => { e.preventDefault(); openLeftovers(); }, text: 'Посмотреть и сравнить' })) : null);
  };

  const draw = async (force = false) => {
    recheck.disabled = true;
    const c = force ? await api('nfq_update_check', { force: true }).catch((e) => { toast(e.message, { err: true }); return null; }) : null;
    u = await api('nfq_update_status').catch((e) => { head.replaceChildren(notice('bad', e.message)); return null; });
    recheck.disabled = false;
    if (!u || !dlg.isConnected) return;
    if (!force && !u.checked) return draw(true);   // ни разу не проверяли (только что обновились) — спросить GitHub сразу
    if (c?.error) toast('GitHub не ответил: ' + c.error, { err: true });
    if (u.running) { log.hidden = false; go.disabled = back.disabled = recheck.disabled = true; }
    drawHead();
    go.hidden = !(u.available && u.installed);
    go.replaceChildren(icon('download'), 'Обновить до ' + u.latest);
    go.onclick = () => run(u.latest, false);
    back.hidden = !u.rollback;
    back.replaceChildren(icon('undo'), 'Вернуть ' + u.rollback);
    back.onclick = () => run(u.rollback, true);
    if (u.running) poll();
    // итог прошлого обновления — пока можно вернуть прежнюю версию (после возврата он уже не нужен)
    else if (u.result && u.rollback) status.replaceChildren(h('p', { class: 'sm muted', text: `Обновление ${u.result.from} → ${u.result.to}, ${fmtDate(u.result.ts)}:` }), result(u.result));
    else if (log.hidden) status.replaceChildren();   // with the log on screen the status holds the outcome of this run
  };

  // while the log is on screen, only the versions: the release notes would push the log out of the window on a phone
  const drawHead = () => {
    const quiet = !log.hidden;
    head.replaceChildren(
      !u.installed ? notice('warn', 'nfqws2 стоит не пакетом', 'Пакета nfqws2-keenetic в системе нет — обновляйте nfqws2 тем же способом, каким ставили.') : null,
      h('p', {}, 'Установлено: ', h('b', { text: u.current || '—' }), ' · последняя: ', h('b', { text: u.latest || '—' }),
        u.date ? ` · вышла ${fmtDate(u.date).split(',')[0]}` : '', ' · ', h('a', { href: u.url, target: '_blank', rel: 'noopener', text: 'выпуск на GitHub' })),
      !quiet && u.available && u.notes ? h('div', { class: 'release-notes' }, md(u.notes.replace(/\r/g, ''))) : null,
      !quiet && !u.available && u.latest && !u.rollback ? h('p', { class: 'sm muted', text: 'У вас последняя версия.' }) : null,
      !quiet && (u.available || u.rollback) ? h('p', { class: 'sm muted', text: 'Ставится пакет из выпуска на GitHub, его контрольная сумма сверяется. Перед обновлением делается снимок. Ваши конфиг и списки менеджер пакетов не трогает: если стандартные изменились, рядом появятся файлы -opkg. После установки nfqws2 перезапустится, и интерфейс проверит сайты мониторинга. Прежнюю версию можно вернуть кнопкой — вместе с конфигом и списками из снимка.' }) : null,
      u.error ? h('p', { class: 'sm muted', text: 'Последняя проверка не удалась: ' + u.error }) : null);
  };

  const poll = async () => {
    clearTimeout(timer);
    const r = await api('nfq_update_status').catch(() => null);
    if (!dlg.isConnected) return;
    if (r) {
      drawLog(log, r.log.trim().split('\n').filter(Boolean), 'Запускаю…');
      log.scrollTop = log.scrollHeight;
      if (r.exit !== null && !r.running) {
        go.disabled = back.disabled = false;
        status.replaceChildren(r.exit === 0 && r.result ? result(r.result) : notice('bad', 'Не получилось', (r.job?.error || 'Подробности — в журнале ниже.') + ' Установленная версия не менялась, если пакет не успел встать.'));
        await loadState().catch(() => {});
        route();
        draw();
        return;
      }
    }
    timer = setTimeout(poll, 2000);
  };

  async function run(ver, rollback) {
    const q = rollback
      ? `Вернуть nfqws2 ${ver}?\n\nВстанет прежний пакет, конфиг и списки вернутся из снимка перед обновлением. nfqws2 перезапустится — на несколько секунд обход прервётся.`
      : `Обновить nfqws2 до ${ver}?\n\nПеред этим делается снимок. nfqws2 перезапустится — на несколько секунд обход прервётся.`;
    if (!await ask(q, rollback ? 'Вернуть' : 'Обновить')) return;
    go.disabled = back.disabled = recheck.disabled = true;
    const r = await guarded(() => api('nfq_update_run', { version: ver, rollback }));
    if (!r) { go.disabled = back.disabled = recheck.disabled = false; return; }
    status.replaceChildren(h('div', { class: 'row sm' }, h('span', { class: 'spin' }), rollback ? 'Возвращаю… не закрывайте окно' : 'Обновляю… не закрывайте окно'));
    log.hidden = false;
    drawHead();
    poll();
  }
  draw();
}

function openDiff(title, a, b) {
  modal(title, h('div', { class: 'modal-b' }, h('p', { class: 'sm muted', text: 'Красным — как сохранено, зелёным — как сейчас в редакторе.' }), renderDiff(diffLines(a, b))), []);
}

async function openHistory(name, onRestored) {
  const isConf = name === 'nfqws2.conf';
  const body = h('div', { class: 'modal-b' }, skel(1, 4));
  const restoreBtn = btn('Восстановить эту версию', null, 'warn', 'history', { disabled: true });
  const bg = modal('История: ' + name, body, restoreBtn);
  const [hist, cur] = await Promise.all([api('history', { name }), isConf ? api('conf_get') : api('list_get', { name })]).catch((e) => { body.replaceChildren(notice('bad', e.message)); return []; });
  if (!hist) return;
  if (!hist.items.length) { body.replaceChildren(h('p', { class: 'muted', text: 'Сохранённых версий пока нет.' })); return; }
  const kinds = { history: 'Версия', template: 'Шаблон пакета', bak: 'Ручная копия' };
  const diffBox = h('div', { class: 'stack' });
  const versions = h('div', { class: 'versions' });
  let selected = null;
  const select = async (item) => {
    selected = item;
    versions.querySelectorAll('.version').forEach((x) => x.classList.toggle('on', x.dataset.id === item.id + item.mtime));
    restoreBtn.disabled = true;
    diffBox.replaceChildren(spinner('Загрузка версии…'));
    const v = await api('history_get', { name, id: item.id }).catch((e) => { diffBox.replaceChildren(notice('bad', e.message)); });
    if (!v || selected !== item) return;
    const same = v.content === cur.content;
    diffBox.replaceChildren(h('p', { class: 'sm muted', text: same ? 'Эта версия совпадает с текущей.' : 'Красным — как было в выбранной версии, зелёным — как сейчас.' }), same ? null : renderDiff(diffLines(v.content, cur.content)));
    restoreBtn.disabled = same;
    restoreBtn.onclick = async () => {
      if (!await ask('Заменить файл выбранной версией? Текущая версия останется в истории.', 'Заменить')) return;
      try {
        await (isConf ? api('conf_save_raw', { content: v.content, force: true, note: 'восстановлена версия от ' + fmtDate(item.mtime) }) : api('list_save', { name, content: v.content, note: 'восстановлена версия от ' + fmtDate(item.mtime) }));
        toast('Версия восстановлена');
        bg.close();
        await loadState().catch(() => {});
        onRestored?.();
      } catch (e) {
        toast(e.data?.fatal ? 'Эта версия не проходит проверку nfqws2 — восстановление отменено' : e.message, { err: true });
      }
    };
  };
  hist.items.forEach((it) => versions.append(h('button', { class: 'version', type: 'button', 'data-id': it.id + it.mtime, onclick: () => select(it) },
    h('b', { text: it.kind === 'history' ? fmtDate(it.mtime) : kinds[it.kind] }),
    h('small', { text: it.kind === 'history' ? `${it.source}${it.name && it.name !== it.source ? ' · ' + it.name : ''}` : `${it.name} · ${fmtDate(it.mtime)}` }))));
  body.replaceChildren(h('div', { class: 'hist' }, versions, diffBox));
  select(hist.items[0]);
  // шаблон пакета (имя-opkg, имя.apk-new) можно удалить прямо отсюда
  const leftover = (it) => it.kind === 'template' && /(-opkg|\.apk-new)$/.test(it.name) ? (isConf ? '' : 'lists/') + it.name : null;
  const delBtn = btn('Удалить шаблон', async () => {
    const path = selected && leftover(selected);
    if (!path || !await ask(`Удалить ${selected.name}? Это новая стандартная версия файла из пакета nfqws2 — ваш ${name} не изменится.`, 'Удалить', 'danger', 'trash')) return;
    const r = await guarded(() => api('leftovers_delete', { names: [path] }));
    if (!r) return;
    toast('Шаблон удалён');
    bg.close();
    await loadState().catch(() => {});
    route();
  }, 'ghost', 'trash', { hidden: true });
  restoreBtn.before(delBtn);
  versions.addEventListener('click', () => { delBtn.hidden = !(selected && leftover(selected)); });
  delBtn.hidden = !leftover(hist.items[0]);
}

// Файлы, которые менеджер пакетов положил при обновлении nfqws2 рядом с изменёнными вами: новые стандартные версии
async function openLeftovers() {
  const body = h('div', { class: 'modal-b' }, skel(1, 4));
  const allBtn = btn('Удалить все', null, 'warn', 'trash', { disabled: true });
  const bg = modal('Файлы от обновления nfqws2', body, allBtn);
  const del = async (names) => {
    const r = await guarded(() => api('leftovers_delete', { names }));
    if (!r) return;
    toast(r.deleted === 1 ? 'Файл удалён' : `Удалено файлов: ${r.deleted}`);
    await loadState().catch(() => {});
    route();
    draw();
  };
  const row = (x) => {
    const editable = x.base === 'nfqws2.conf' || (x.name.startsWith('lists/') && /\.list$/.test(x.base));
    const state = !x.own ? 'вашего файла с таким именем нет' : x.same ? 'совпадает с вашим' : 'отличается от вашего';
    return h('div', { class: 'item' }, h('div', { class: 'item-main' },
      h('span', { class: 'item-title', text: x.name }),
      h('span', { class: 'sm muted', text: `${fmtDate(x.mtime)} · ${fmtBytes(x.size)} · ${state}` }),
      h('div', { class: 'row', style: 'margin-top:6px' },
        editable && x.own && !x.same ? btn('Сравнить', () => { bg.close(); openHistory(x.base, () => route()); }, 'small', 'history', { title: `Открыть историю ${x.base} — там шаблон можно сравнить с вашим файлом` }) : null,
        btn('Удалить', () => del([x.name]), 'small', 'trash'))));
  };
  const draw = async () => {
    const r = await api('leftovers').catch((e) => { body.replaceChildren(notice('bad', e.message)); });
    if (!r) return;
    allBtn.disabled = !r.items.length;
    if (!r.items.length) { body.replaceChildren(h('p', { class: 'muted', text: 'Таких файлов больше нет.' })); return; }
    allBtn.onclick = async () => { if (await ask(`Удалить ${plural(r.items.length, 'файл', 'файла', 'файлов')}? Ваши конфиг и списки не изменятся.`, 'Удалить', 'danger', 'trash')) del(r.items.map((x) => x.name)); };
    body.replaceChildren(
      h('p', { class: 'sm muted', text: 'Когда вы меняли конфиг или список, менеджер пакетов при обновлении nfqws2 его не трогает, а кладёт рядом новую стандартную версию с припиской -opkg (на OpenWrt 25 — .apk-new). nfqws2 эти файлы не читает. Если они не нужны для сравнения, их можно удалить; при следующем обновлении пакет может положить их снова.' }),
      h('div', {}, r.items.map(row)));
  };
  draw();
}
