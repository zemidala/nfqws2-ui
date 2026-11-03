'use strict';
// Interface: the frame: top bar, menu, drawer, undo, restart with a check.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ каркас ============

// Страницы: адрес и название. Адреса прежние (#/tests/…, #/settings/…, #/sites/…) — старые ссылки и закладки
// работают в любой компоновке; компоновка решает только, где показаны переходы между страницами.
const PAGES = {
  over: ['#/', 'Обзор'],
  pick: ['#/tests/pick', 'Подбор стратегии'], trace: ['#/tests/trace', 'Трассировка'],
  mon: ['#/tests/monitor', 'Мониторинг'], tg: ['#/tests/notify', 'Уведомления'],
  prof: ['#/settings', 'Профили'], lists: ['#/sites', 'Списки'], dev: ['#/devices', 'Устройства'],
  basic: ['#/settings/basic', 'Основное'], base: ['#/settings/base', 'Параметры запуска'], raw: ['#/settings/raw', 'Конфиг целиком'],
  backup: ['#/settings/backup', 'Резервные копии'], hist: ['#/settings/hist', 'История изменений'], log: ['#/log', 'Журнал'],
  look: ['#/settings/look', 'Оформление'], about: ['#/settings/about', 'О программе'],
  // ещё не сделаны: в меню видны, но заблокированы и помечены «скоро»
  diag: ['#/diag', 'Диагноз блокировки'], phist: ['#/tests/history', 'История подборов'], comm: ['#/tests/community', 'Стратегии сообщества'], frz: ['#/tests/freeze', 'Обрыв на 16 КБ'], dns: ['#/tests/dns', 'DNS-серверы'], conns: ['#/tests/conns', 'Соединения устройства'], learn: ['#/tests/learn', 'Что выучил роутер'],cfg: ['#/tests/configs', 'Чужой конфиг'], auto: ['#/tests/auto', 'Автоподбор'], asn: ['#/asn', 'Список по ASN'], report: ['#/settings/report', 'Отчёт для помощи'],
};
// Новые функции — метка «новое». КАЖДАЯ новая функция интерфейса записывается сюда:
// [страница из PAGES или «страница:функция», версия, в которой появилась, подпись для сообщения «Обновлено до…»].
// Метку видит тот, кто начал пользоваться интерфейсом до этой версии, пока не откроет страницу, и не дольше двух
// версий. У функции внутри страницы метка стоит и у страницы в меню; внутри — newTag('страница:функция') рядом с ней.
const FEATURES = [
  ['comm', '1.9.0', 'Стратегии сообщества'],
  ['pick:community', '1.9.0', 'стратегии сообщества в подборе'],
  ['pick:share', '1.9.0', '«Поделиться» и «Подтвердить» у найденной стратегии'],
  ['phist:share', '1.9.0', '«Поделиться» в истории подборов'],
  ['frz', '1.10.0', 'проба «Обрыв на 16 КБ» — какое имя в фейке снимает обрыв у зарубежных хостингов'],
  ['pick:catalog', '1.10.0', 'каталог стратегий zapret2 в подборе (сотни и тысячи стратегий, параллельно)'],
  ['pick:own', '1.14.0', 'своя стратегия в подборе: вставить из чата и проверить на сайте, не трогая конфиг'],
  ['pick:multi', '1.11.0', 'подбор одной стратегии для нескольких сайтов или списка'],
  ['pick:wide', '1.11.0', '«На других сайтах» у найденной стратегии — открывает ли она не только свой сайт'],
  ['tg:log', '1.11.0', 'уведомления не теряются, когда Telegram недоступен, и журнал последних сообщений'],
  ['about:session', '1.11.0', 'срок входа: сколько не выходить из интерфейса («О программе» → «Вход»)'],
  ['pick:quic', '1.13.0', 'подбор стратегии для QUIC (HTTP/3, UDP 443) и проверка QUIC в диагнозе'],
  ['prof:name', '1.15.0', 'имя профиля (--name, --new=имя): видно в интерфейсе вместо «Свой профиль N»'],
  ['look:wints', '1.15.0', 'напоминание про метки TCP в Windows можно скопировать и скрыть'],
  ['cfg:file', '1.24.0', '«Из файла» в «Чужом конфиге» — загрузить nfqws2.conf или .txt целиком'],
  ['backup:delete', '1.24.0', 'удаление снимков с роутера — по одному и пачкой'],
  ['hist:initial', '1.16.0', '«Вернуть как при установке» — конфиг и списки к началу истории одним действием'],
  ['lists:text', '1.17.0', 'список открывается тем видом, что выбран в прошлый раз; Ctrl+/ в тексте — комментарий'],
  ['over:exclude', '1.19.0', '«Проверить сайт»: сайт не заблокирован, а профиль его ломает — «Исключить из профиля»'],
  ['diag:exclude', '1.19.0', 'диагноз: «Исключить из профиля», когда сайт ломает nfqws2'],
  ['cfg', '1.20.0', '«Чужой конфиг» — проверить конфиг из чата на своих сайтах и сравнить с текущим, не ставя его'],
  ['over:leftovers', '1.21.0', '«Проблемы»: файлы -opkg от обновления nfqws2 — посмотреть и удалить'],
  ['pick:podkop', '1.21.0', 'подбор и проверки предупреждают, если сайт идёт через podkop'],
  ['raw:format', '1.21.0', '«Конфиг целиком»: кнопка «Оформить» — отступы и пустые строки к одному виду'],
  ['over:nfqupdate', '1.21.0', 'обновление nfqws2 из интерфейса: метка с новой версией в верхней панели, возврат прежней'],
  ['frz:inconf', '1.22.0', '«Обрыв на 16 КБ»: видно, что найденная стратегия уже стоит в конфиге и работает для этой сети'],
  ['frz:join', '1.23.0', '«Обрыв на 16 КБ»: сеть добавляется в профиль с той же стратегией одной строкой, а не новым профилем'],
  ['dev', '1.26.0', '«Устройства»: обход только для выбранных устройств домашней сети'],
  ['look:zd', '1.26.0', 'стиль «Z · zemidala» в «Оформлении»: фирменные цвета — чёрное меню, графит, оранжевые акценты'],
  ['dns', '1.26.0', '«DNS-серверы»: какие DNS отвечают с роутера, не перехвачен ли обычный DNS и не подменяет ли DNS роутера адреса'],
  ['lists:order', '1.26.0', '«Списки»: поиск, группы по профилю и порядок по имени или по числу записей'],
  ['mon:view', '1.26.0', '«Мониторинг»: сводка, таблица с сортировкой и общая шкала времени — видно, когда падало всё сразу'],
  ['look:scheme', '1.27.0', '«Оформление»: цветовые схемы для стилей A, B, C и K — Полночь, Океан, Сирень, Хвоя, Янтарь, Вишня, Графит'],
  ['mon:chart', '1.27.0', '«Мониторинг»: вид графика на выбор — полоски, часы, столбики или словами'],
  ['raw:full', '1.28.0', '«Конфиг целиком»: редактор во весь экран, выход по Esc'],
  ['raw:hide', '1.28.0', '«Конфиг целиком»: «Не показывать» у предупреждений и советов, список скрытых — «Скрыто»'],
  ['look:load', '1.28.0', '«Оформление»: нагрузка роутера в шапке — процессор и память'],
  ['dns:full', '1.28.0', '«DNS-серверы»: расширенная проверка — около 50 серверов, вид отказа, перехват и подмена у каждого, три лучших для вашей сети'],
  ['look:anim', '1.28.0', '«Оформление»: анимации при переходе между страницами, их можно выключить'],
  ['learn', '1.31.0', '«Что выучил роутер»: профили с обучением (learn) сами выбирают стратегию для каждого сайта по тому, какая его открывала'],
  ['learn:convert', '1.31.0', 'Кнопка «Перевести на обучение» у профиля с circular'],
  ['comm:share', '1.31.0', '«Поделиться» без аккаунта GitHub, «Помогать базе» и счётчик «Ваш вклад»'],
  ['dns:only', '1.31.0', '«DNS-серверы»: галочка «Только свои» — проверить свои серверы без публичных'],
  ['frz:only', '1.31.0', '«Обрыв на 16 КБ»: галочка «Только свои» — проверить свои сайты и адреса без опорных'],
  ['auto:pause', '1.31.0', '«Автоподбор»: своя пауза между попытками — от 1 до 168 часов'],
  ['lists:full', '1.31.0', '«Списки»: список во весь экран, выход по Esc'],
  ['dns:btn', '1.31.0', '«DNS-серверы»: «Проверить» — под полем своих серверов'],
  ['basic:restart', '1.32.0', '«Основное»: «После перезапуска проверять сайты» можно выключить — тогда перезапуск сразу, без проверки и отката'],
  ['pick:ports', '1.32.0', '«Подбор стратегии»: стратегии и из профилей для других портов — без правки filter-tcp'],
  ['look:kinds', '1.31.0', 'Стиль C: действие, выбор и вкл/выкл различаются видом — кнопка плотная, выбор в утопленной дорожке'],
  ['look:pins', '1.31.0', 'Меню: звёздочка закрепляет пункт в группе «Закреплённые» под «Обзором»; порядок — в «Оформлении»'],
  ['mon:ivl', '1.30.0', '«Мониторинг»: свой интервал проверки — в минутах или часах'],
  ['cfg:mine', '1.30.0', '«Чужой конфиг»: проверку своего конфига для сравнения можно выключить'],
  ['raw:nowarn', '1.30.0', '«Оформление»: не показывать предупреждения и советы в «Конфиге целиком»'],
  ['pick:tls12', '1.29.0', '«Подбор стратегии»: проверять как старые устройства (TLS 1.2) — для смарт-ТВ и приставок'],
  ['conns:stop', '1.29.0', '«Соединения устройства»: «Остановить» во время записи, порядок устройств; длительность и порядок запоминаются'],
  ['trace:quic', '1.29.0', '«Трассировка»: QUIC (HTTP/3) — какой профиль ловит первый пакет QUIC и что с ним делает стратегия'],
  ['dns:own', '1.29.0', '«DNS-серверы»: свои серверы — адрес, DoT или DoH — проверяются вместе с публичными'],
  ['frz:ip', '1.29.0', '«Обрыв на 16 КБ»: свой IP-адрес (например, VPS) — соединяется ли он и замирает ли на объёме'],
  ['conns', '1.27.0', '«Соединения устройства»: куда ходит программа на устройстве сети, приходят ли ответы и видит ли эти порты nfqws2'],
];
const vcmp = (a, b) => { const x = a.split('.').map(Number), y = b.split('.').map(Number); for (let i = 0; i < 3; i++) if (x[i] !== y[i]) return x[i] - y[i]; return 0; };
// на сколько минорных версий функция старше текущей (у сборки до выпуска — меньше нуля)
const vage = (v) => { const [a, b] = (S.state?.ui?.version || v).split('.').map(Number), [c, d] = v.split('.').map(Number); return (a - c) * 100 + (b - d); };
function isNewF(id) {
  const seen = S.state?.ui?.seen;
  const f = FEATURES.find((x) => x[0] === id);
  return !!(seen && f && !seen.open.includes(id) && vcmp(f[1], seen.since) > 0 && vage(f[1]) <= 1);
}
const pageFeatures = (page) => FEATURES.map((f) => f[0]).filter((id) => (id === page || id.startsWith(page + ':')) && isNewF(id));
const pageNew = (page) => pageFeatures(page).length > 0;
const NEW_TAG = () => h('span', { class: 'tag new', text: 'новое', title: 'Появилось в последнем обновлении' });
// метка у функции внутри страницы: видна весь этот заход на страницу, при следующем — уже нет
const newTag = (id) => S.newHere?.has(id) ? NEW_TAG() : null;

// Открыли страницу — её новые функции больше не новые (на роутере: для всех устройств)
function seenPage(page) {
  const ids = pageFeatures(page);
  if (!ids.length) return;
  S.state.ui.seen.open.push(...ids);
  api('seen_mark', { ids }).catch(() => {});
  placeSide();
  updateChrome();
}

// После обновления, пока не закроют: что появилось, со ссылками
function updatedNote() {
  const seen = S.state?.ui?.seen;
  const ver = S.state?.ui?.version;
  if (!seen || !ver || seen.told === ver || document.getElementById('new-note')) return;
  const items = FEATURES.filter((f) => isNewF(f[0]));
  const told = () => { seen.told = ver; api('seen_mark', { ids: [], told: ver }).catch(() => {}); };
  if (!items.length) { told(); return; }
  // прочитанным сообщение считается, когда его закрыли или пошли по ссылке, а не когда показали
  const done = () => { document.getElementById('new-note')?.remove(); told(); };
  const page = (id) => id.split(':')[0];
  const links = [...new Map(items.map((f) => [page(f[0]), f])).values()].filter((f) => PAGES[page(f[0])]?.[0]);
  document.getElementById('upd-banner')?.before(h('div', { id: 'new-note', class: 'banner info' }, h('div', { class: 'banner-in' },
    h('span', {}, h('b', { text: `Обновлено до ${ver}. ` }), 'Новое: ', ...links.flatMap((f, i) => [i ? ', ' : '', h('a', { href: PAGES[page(f[0])][0], onclick: done, text: items.filter((x) => page(x[0]) === page(f[0])).map((x) => x[2]).join(', ') })]),
      '. В меню такие места отмечены «новое», пока вы их не откроете.'),
    btn('Что изменилось', () => { done(); go('#/settings/changelog'); }, 'small'), btn('Понятно', done, 'small ghost'))));
}
const SOON_HINT = 'Ещё в разработке — появится в одной из следующих версий';
const SYS_PAGES = ['basic', 'base', 'raw', 'backup', 'hist', 'log', 'report', 'look', 'about'];
const SYS_NAV = ['basic', 'base', 'raw', 'backup', 'hist', 'log', 'report', 'look', 'about'];
// Компоновка «Боковое меню»: группы и их страницы
const MENU = [[null, ['over']], ['Проверка сайта', ['diag', 'frz', 'dns', 'conns', 'pick', 'trace', 'phist', 'comm', 'cfg']], ['Наблюдение', ['mon', 'auto', 'learn', 'tg']], ['Обход', ['prof', 'lists', 'dev', 'asn']], ['Система', SYS_NAV]];
// Упрощённый вид (галочка в «Оформлении»): шесть разделов, страницы раздела — вкладками над страницей (как в TOP_TABS)
const MENU_SIMPLE = [['over', 'Обзор', 'home', ['over']], ['pick', 'Проверка сайта', 'tests', ['diag', 'frz', 'dns', 'conns', 'pick', 'trace', 'phist', 'comm', 'cfg', 'site']], ['mon', 'Наблюдение', 'pulse', ['mon', 'auto', 'learn', 'tg']],
  ['prof', 'Профили', 'layers', ['prof', 'dev']], ['lists', 'Списки', 'sites', ['lists', 'asn']], ['basic', 'Система', 'settings', SYS_NAV]];
// Верхние вкладки компоновок: страница по клику, подпись, значок, страницы вкладки, «только на узком экране»
const TOP_TABS = {
  menu: [],
  tabs: [['over', 'Обзор', 'home', ['over'], true], ['pick', 'Проверка', 'tests', ['diag', 'frz', 'dns', 'conns', 'pick', 'trace', 'phist', 'comm', 'cfg', 'site']], ['mon', 'Мониторинг', 'pulse', ['mon', 'auto', 'learn', 'tg']],
    ['prof', 'Профили', 'layers', ['prof', 'dev']], ['lists', 'Списки', 'sites', ['lists', 'asn']], ['basic', 'Система', 'settings', SYS_NAV]],
  site: [['over', 'Обзор', 'home', ['over']], ['mon', 'Сайты', 'pulse', ['mon', 'diag', 'frz', 'dns', 'conns', 'pick', 'trace', 'phist', 'comm', 'cfg', 'auto', 'learn', 'tg', 'site']],
    ['prof', 'Профили', 'layers', ['prof', 'dev']], ['lists', 'Списки', 'sites', ['lists', 'asn']], ['basic', 'Система', 'settings', SYS_NAV]],
};
// Нижняя панель телефона — одна для всех компоновок; остальное — в меню
const BOTTOM = [['over', 'Обзор', 'home', ['over']], ['pick', 'Подбор', 'tests', ['diag', 'frz', 'pick', 'trace', 'site']], ['mon', 'Мониторинг', 'pulse', ['mon', 'tg']], ['prof', 'Профили', 'layers', ['prof']]];

const layout = () => document.documentElement.dataset.layout || 'menu';
// Колонка обзора слева — только в компоновке «Вкладки» на широком экране
const overviewCol = () => isWide() && layout() === 'tabs';

function parseRoute() {
  const raw = location.hash.replace(/^#\/?/, '');
  const [path, query] = raw.split('?');
  const parts = path.split('/').filter(Boolean).map(decodeURIComponent);
  return { tab: parts[0] || '', arg: parts[1] || '', sub: parts[2] || '', q: new URLSearchParams(query || '') };
}
const go = (hash) => { location.hash = hash; };

function pageOf(r = parseRoute()) {
  if (r.tab === 'sites') return 'lists';
  if (r.tab === 'log') return 'log';
  if (r.tab === 'site') return 'site';
  if (r.tab === 'diag') return 'diag';
  if (r.tab === 'asn') return 'asn';
  if (r.tab === 'devices') return 'dev';
  if (r.tab === 'tests') return { monitor: 'mon', notify: 'tg', trace: 'trace', history: 'phist', community: 'comm', freeze: 'frz', dns: 'dns', conns: 'conns', configs: 'cfg', auto: 'auto', learn: 'learn' }[r.arg] || 'pick';
  if (r.tab === 'settings') return SYS_PAGES.includes(r.arg) ? r.arg : ['readme', 'changelog'].includes(r.arg) ? 'about' : 'prof';
  return 'over';
}

// Отметка у пункта меню: ошибки конфига, число снимков, упавшие сайты, обновление
function pageTail(id) {
  const st = S.state;
  if (!st) return null;
  const issues = st.lint?.issues || [];
  const dot = (lvl) => lvl === 'error' ? h('span', { class: 'errdot', title: 'Есть ошибки' }) : lvl === 'warning' ? h('span', { class: 'warndot', title: 'Есть предупреждения' }) : null;
  let tail = null;
  if (id === 'prof') tail = dot(worst(st.conf_profiles.flatMap((p) => profIssues(p))));
  else if (id === 'basic') tail = dot(worst(issues.filter((x) => ['ISP_INTERFACE', 'TCP_PORTS', 'UDP_PORTS'].includes(x.var))) && 'warning');
  else if (id === 'base') tail = dot(worst(issues.filter((x) => x.var === 'NFQWS_BASE_ARGS')) && 'warning');
  else if (id === 'raw') tail = dot(worst(issues) === 'error' ? 'error' : null);
  else if (id === 'backup') tail = st.snap?.count ? h('span', { class: 'num', text: st.snap.count }) : null;
  else if (id === 'about') tail = st.ui?.update?.available ? h('span', { class: 'chip ok', text: 'обновление' }) : null;
  else if (id === 'auto') tail = st.auto?.offers?.length ? h('span', { class: 'chip warn num', title: 'Найдены стратегии — ждут применения', text: st.auto.offers.length }) : null;
  else if (id === 'mon') {
    const down = (st.monitor?.sites || []).filter((x) => x.last && !x.last[1]).length;
    tail = down ? h('span', { class: 'chip bad num', title: 'Не открываются', text: down }) : null;
  }
  return tail ? h('span', { class: 'tail' }, tail) : null;
}

// Значки групп меню: показываются в стиле K («как в Keenetic»)
const PIN_HEAD = 'Закреплённые';
const NAV_ICON = { [PIN_HEAD]: 'pin', 'Проверка сайта': 'search', 'Наблюдение': 'pulse', 'Обход': 'shield', 'Система': 'gear' };
// pinned menu pages, in their order; only pages the full menu has (an old id from another version drops out)
const menuPins = () => (Array.isArray(getLook().pins) ? getLook().pins : []).filter((id, i, a) => PAGES[id]?.[0] && id !== 'over' && a.indexOf(id) === i && MENU.some(([, ids]) => ids.includes(id)));
function pinPage(id, on, order) {
  const now = menuPins();
  setLook({ pins: order || (on ? [...now.filter((x) => x !== id), id] : now.filter((x) => x !== id)) });
  placeSide();
  if (!document.getElementById('drawer')?.hidden) openDrawer(true);
}

// Упрощённый вид — по выбору в «Оформлении»; по умолчанию интерфейс полный
const simple = () => document.documentElement.hasAttribute('data-simple');

function menuNav() {
  const cur = pageOf();
  if (simple()) {
    // у раздела — отметка первой из его страниц, у которой она есть (упавшие сайты, ошибки, обновление); число снимков — только во вкладке
    return h('nav', { class: 'nav mnav', 'aria-label': 'Разделы' }, MENU_SIMPLE.map(([id, label, ic, pages]) =>
      h('a', { href: PAGES[id][0], 'data-pages': pages.join(' '), class: pages.includes(cur) ? 'on' : null },
        icon(ic), h('span', { class: 'nm', text: label }), pages.some(pageNew) ? h('span', { class: 'tail' }, NEW_TAG()) : pages.filter((x) => x !== 'backup').map(pageTail).find(Boolean) || null)));
  }
  // группа — заголовок и её страницы; в свёрнутом меню (полоска значков) страницы группы всплывают рядом со значком
  const mini = document.documentElement.hasAttribute('data-navmini');
  // pinned pages (Оформление → «Закреплённые в меню»): copies in their own group under «Обзор»; a star on each item
  // pins and unpins (the same list with its order is on the look page — for the keyboard too)
  const pins = menuPins();
  const menu = pins.length ? [MENU[0], [PIN_HEAD, pins], ...MENU.slice(1)] : MENU;
  const star = (id) => mini ? null : h('span', { class: 'mpin' + (pins.includes(id) ? ' on' : ''), 'aria-hidden': 'true', 'data-tip': pins.includes(id) ? 'Открепить' : 'Закрепить наверху меню',
    text: pins.includes(id) ? '★' : '☆', onclick: (e) => { e.preventDefault(); e.stopPropagation(); pinPage(id, !pins.includes(id)); } });
  return h('nav', { class: 'nav mnav', 'aria-label': 'Разделы' }, menu.map(([head, ids]) => h('div', { class: 'mgrp' + (ids.some((id) => id === cur || (id === 'pick' && cur === 'site')) ? ' here' : '') + (ids.some(pageNew) ? ' has-new' : '') },
    head ? h('div', { class: 'nav-h', tabindex: mini ? 0 : null, title: mini ? head : null }, icon(NAV_ICON[head] || 'settings'), h('span', { class: 'nh-t', text: head })) : null,
    h('div', { class: head ? 'mlinks' : 'mtop' }, head && mini ? h('div', { class: 'mfly-h', text: head }) : null,
      ids.map((id) => PAGES[id][0]
        ? h('a', { href: PAGES[id][0], 'data-pages': id === 'pick' ? 'pick site' : id, class: id === cur || (id === 'pick' && cur === 'site') ? 'on' : null, title: mini && !head ? PAGES[id][1] : null },
          id === 'over' ? h('span', { class: 'k-ic' }, icon('dash')) : null,
          h('span', { class: 'nm', text: PAGES[id][1] }), pageNew(id) ? h('span', { class: 'tail' }, NEW_TAG()) : pageTail(id), head ? star(id) : null)
        : soonItem(id))))),
  // свернуть меню в полоску значков — как «Скрыть меню» у Keenetic; только на широком экране
  h('button', { type: 'button', class: 'nav-mini', title: mini ? 'Развернуть меню' : 'Свернуть меню в полоску значков — больше места под страницу', 'aria-label': mini ? 'Развернуть меню' : 'Свернуть меню',
    onclick: () => { setLook({ navmini: !mini }); placeSide(); } }, icon(mini ? 'menu' : 'back'), h('span', { class: 'nh-t', text: 'Свернуть меню' })));
}

// Пункт для ещё не сделанной страницы: виден, но не нажимается
const soonItem = (id) => h('span', { class: 'soon', title: SOON_HINT, 'aria-disabled': 'true' },
  h('span', { class: 'nm', text: PAGES[id][1] }), h('span', { class: 'tag', text: 'скоро' }));

// Меню на узком экране выезжает слева
function openDrawer(on = true) {
  const d = document.getElementById('drawer');
  if (!d) return;
  d.hidden = !on;
  if (on) d.firstChild.replaceChildren(menuNav());
}

// Второй ряд вкладок — страницы текущей верхней вкладки («Вкладки», «Вокруг сайта») или раздела (упрощённое боковое меню)
function subTabs(page) {
  const tab = (layout() === 'menu' ? (simple() ? MENU_SIMPLE : []) : TOP_TABS[layout()]).find((t) => t[3].includes(page));
  const ids = tab ? tab[3].filter((id) => id !== 'site') : [];
  if (ids.length < 2 || page === 'site') return null;
  return h('nav', { class: 'subtabs', 'aria-label': tab[1] }, ids.map((id) => PAGES[id][0] ? h('a', { href: PAGES[id][0], class: id === page ? 'on' : null }, PAGES[id][1], pageNew(id) ? NEW_TAG() : null) : soonItem(id)));
}

// Router load in the top bar (Оформление → «Показывать нагрузку роутера»): CPU, memory used, load average.
// A number turns yellow over 80 % CPU or under 15 % free memory
function drawLoad() {
  const el = document.getElementById('load'), elM = document.getElementById('load-m');
  const l = S.state?.load;
  if (!el) return;
  el.hidden = !getLook().showLoad || !l;
  if (elM) elM.hidden = el.hidden;
  if (el.hidden) return;
  S.loadAt = Date.now();
  const memUsed = l.mem_total && l.mem_free != null ? Math.round(100 * (1 - l.mem_free / l.mem_total)) : null;
  const up = l.uptime ? (l.uptime >= 86400 ? Math.floor(l.uptime / 86400) + ' д ' : '') + Math.floor(l.uptime % 86400 / 3600) + ' ч' : '';
  const chip = (label, val, warn, cls = '') => h('span', { class: 'lchip' + (warn ? ' warn' : '') + cls }, h('span', {}, label + ' ', h('b', { text: val })));
  el.replaceChildren(
    l.cpu != null ? chip('ЦП', l.cpu + ' %', l.cpu > 80) : null,
    memUsed != null ? chip('Память', memUsed + ' %', memUsed > 85) : null,
    l.la ? chip('Нагрузка', String(l.la[0]).replace('.', ','), false, ' opt') : null,
    h('span', { class: 'lsep' }));
  if (elM) elM.replaceChildren(...[...el.children].map((c) => c.cloneNode(true)));
  el.dataset.tip = [l.mem_total ? `свободно ${Math.round(l.mem_free / 1024)} из ${Math.round(l.mem_total / 1024)} МБ` : '',
    l.la ? 'нагрузка ' + l.la.map((x) => String(x).replace('.', ',')).join(' ') : '', up ? 'работает ' + up : ''].filter(Boolean).join(' · ');
}
// a light request every 10 s while the numbers are shown; on a weak router (lowmem) every request is a php-cgi
// process, so there only when the usual state has not brought fresh numbers for 30 s (it skips while one types)
setInterval(async () => {
  if (!S.auth || !getLook().showLoad || document.visibilityState !== 'visible') return;
  if (S.state?.lowmem && Date.now() - (S.loadAt || 0) < 29000) return;
  const r = await api('load').catch(() => null);
  if (r && S.state) { S.state.load = r; drawLoad(); }
}, 10000);

function renderShell() {
  const link = ([id, label, ic, pages, narrow]) => h('a', { href: PAGES[id][0], 'data-pages': pages.join(' '), title: label, 'aria-label': label, class: narrow ? 'only-narrow' : null }, icon(ic), h('span', { text: label }));
  const quick = h('input', { class: 'input', placeholder: 'проверить сайт…', autocapitalize: 'off', autocorrect: 'off', spellcheck: 'false', inputmode: 'url', enterkeyhint: 'go', 'aria-label': 'Сайт для проверки' });
  document.getElementById('app').replaceChildren(
    h('header', { class: 'top' },
      h('div', { class: 'top-in' },
        h('button', { class: 'btn ghost icon', id: 'burger', type: 'button', title: 'Меню', 'aria-label': 'Меню', onclick: () => openDrawer() }, icon('menu')),
        h('a', { class: 'brand', href: PAGES.about[0], title: 'nfqws2-ui — о программе' }, logo(26), h('span', {}, 'nfqws2-ui', h('small', { id: 'ui-ver' }))),
        simple() ? null : h('a', { class: 'btn ghost small repo-link', id: 'repo-link', href: REPO, target: '_blank', rel: 'noopener', title: 'nfqws2-ui на GitHub', 'aria-label': 'nfqws2-ui на GitHub' }, icon('git')),
        h('nav', { class: 'tabs', 'aria-label': 'Разделы' }, TOP_TABS[layout()].map(link)),
        simple() ? null : h('span', { id: 'https-slot' }),
        h('button', { class: 'btn ghost small', type: 'button', id: 'undo-btn', hidden: true, onclick: () => undoLast(true) }, icon('undo'), h('span', { class: 'undo-label', text: 'Отменить' })),
        h('button', { class: 'btn ghost small', type: 'button', id: 'focus-off', hidden: true, onclick: () => setFocus(false) }, 'Показать обзор'),
        simple() ? moreMenu() : [
          h('button', { class: 'btn ghost icon', id: 'refresh-top', type: 'button', title: 'Обновить', 'aria-label': 'Обновить', onclick: () => route(true) }, icon('refresh')),
          S.authEnabled ? h('button', { class: 'btn ghost icon', type: 'button', title: 'Выйти', 'aria-label': 'Выйти', onclick: logout }, icon('logout')) : null],
        h('span', { class: 'grow' }),
        h('form', { class: 'quick', onsubmit: (e) => { e.preventDefault(); const v = quick.value.trim(); if (!v) return; quick.value = ''; quick.blur(); checkHost(v); } },
          quick, h('button', { class: 'btn primary icon', type: 'submit', title: 'Проверить: открывается ли сайт и каким профилем nfqws2 он пойдёт', 'aria-label': 'Проверить' }, icon('arrow'))),
        h('span', { class: 'grow' }),
        h('span', { class: 'svc-box' }, h('span', { id: 'load', class: 'load', hidden: true }),
          h('span', { class: 'svc-name', title: 'Версия nfqws2 на роутере' }, 'nfqws2', h('small', { id: 'ver' })),
          h('button', { id: 'nfq-up', class: 'nfq-up', type: 'button', hidden: true, onclick: () => openNfqUpdate() }),
          h('span', { id: 'svc', class: 'pill muted' }, h('span', { class: 'dot' }), '…'),
          h('span', { id: 'svc-ctl', class: 'svc-ctl' }))),
      // on a narrow screen the load has no room in the bar — a thin line under it
      h('div', { id: 'load-m', class: 'load load-m', hidden: true })),
    h('div', { id: 'banner' }),
    h('div', { id: 'upd-banner' }),
    h('div', { id: 'pending', 'aria-live': 'polite' }),
    h('div', { class: 'layout' }, h('aside', { id: 'side', 'aria-label': 'Обзор' }), h('main', { id: 'main' })),
    h('nav', { class: 'bottom', 'aria-label': 'Разделы' }, BOTTOM.map(link),
      h('button', { type: 'button', onclick: () => openDrawer() }, icon('menu'), h('span', { text: 'Меню' }))),
    h('div', { id: 'drawer', class: 'drawer', hidden: true, onclick: (e) => { if (e.target.id === 'drawer' || e.target.closest('a')) openDrawer(false); } }, h('div', { class: 'drawer-in' })));
}

// Упрощённый вид: редкое из верхней панели — под «⋯»: обновить данные, HTTPS, GitHub, выход
function moreMenu() {
  const pop = h('div', { class: 'more-pop', hidden: true, role: 'menu', onclick: (e) => { if (e.target.closest('a, button')) pop.hidden = true; } },
    h('button', { type: 'button', role: 'menuitem', onclick: () => route(true) }, icon('refresh'), 'Обновить данные'),
    h('span', { id: 'https-slot' }),
    h('a', { href: REPO, target: '_blank', rel: 'noopener', role: 'menuitem', id: 'repo-link' }, icon('git'), 'nfqws2-ui на GitHub'),
    h('a', { href: PAGES.about[0], role: 'menuitem' }, icon('info'), 'О программе'),
    S.authEnabled ? h('button', { type: 'button', role: 'menuitem', onclick: logout }, icon('logout'), 'Выйти') : null);
  const b = h('button', { class: 'btn ghost icon', type: 'button', id: 'more-btn', title: 'Ещё: обновить данные, HTTPS, GitHub, выход', 'aria-label': 'Ещё', 'aria-haspopup': 'menu',
    onclick: (e) => { e.stopPropagation(); pop.hidden = !pop.hidden; } }, icon('more'));
  if (!moreMenu.bound) {
    moreMenu.bound = true;
    document.addEventListener('click', (e) => { const p = document.querySelector('.more-pop'); if (p && !p.hidden && !p.contains(e.target)) p.hidden = true; });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') document.querySelector('.more-pop')?.setAttribute('hidden', ''); });
  }
  return h('span', { class: 'more-wrap' }, b, pop);
}

function setFocus(on) {
  document.documentElement.dataset.focus = on ? '1' : '0';
  const b = document.getElementById('focus-off');
  if (b) b.hidden = !on || !overviewCol();
}

function updateChrome() {
  const st = S.state;
  const page = pageOf();
  document.querySelectorAll('[data-pages]').forEach((a) => a.classList.toggle('on', a.dataset.pages.split(' ').includes(page)));
  if (!st) return;
  // точка «новое» на вкладках и разделах нижней панели; на кнопках меню — если новое есть где-то ещё
  document.querySelectorAll('.tabs [data-pages], .bottom [data-pages]').forEach((a) => a.classList.toggle('has-new', a.dataset.pages.split(' ').some(pageNew)));
  const inBottom = BOTTOM.flatMap((b) => b[3]);
  const elsewhere = Object.keys(PAGES).some((id) => pageNew(id) && !inBottom.includes(id));
  document.getElementById('burger')?.classList.toggle('has-new', Object.keys(PAGES).some(pageNew));
  document.querySelector('.bottom > button')?.classList.toggle('has-new', elsewhere);
  // Ссылка на HTTPS — только если он включён (nfqws-ui-setup https on); в упрощённом виде — пунктом меню «⋯»
  const hp = st.ui?.https_port;
  const httpsUrl = location.protocol === 'http:' && hp ? `https://${location.hostname}:${hp}/${location.hash}` : null;
  document.getElementById('https-slot')?.replaceChildren(httpsUrl && !simple()
    ? h('a', { class: 'btn ghost small https-link', href: httpsUrl, title: 'Открыть по HTTPS: пароль и данные идут в зашифрованном виде', 'aria-label': 'Открыть по HTTPS' }, '🔒', h('span', { text: ' Открыть по HTTPS' }))
    : simple() && httpsUrl ? h('a', { href: httpsUrl, role: 'menuitem', title: 'Пароль и данные идут в зашифрованном виде' }, h('span', { class: 'mi', text: '🔒' }), 'Открыть по HTTPS') : []);
  const svc = document.getElementById('svc');
  const [svcLvl, svcText] = svcState(st);
  svc.className = 'pill ' + svcLvl;
  svc.replaceChildren(h('span', { class: 'dot' }), svcText.toLowerCase());
  // Управление сервисом — всегда в верхней панели
  const busy = pendingActive();
  document.getElementById('svc-ctl').replaceChildren(
    st.running
      ? [svcBtn(h('span', { class: 'ctl-label', text: 'Перезапустить' }), 'restart', safeRestart, 'small', 'power', { title: restartTip(), 'aria-label': 'Перезапустить', disabled: busy }),
        svcBtn(h('span', { class: 'ctl-label', text: 'Остановить' }), 'stop', () => service('stop'), 'small danger', 'stop', { title: 'Остановить nfqws2 — обход блокировок перестанет работать', 'aria-label': 'Остановить', disabled: busy })]
      : svcBtn(h('span', { class: 'ctl-label', text: 'Запустить' }), 'start', () => service('start'), 'small primary', 'play', { title: 'Запустить nfqws2', 'aria-label': 'Запустить' }));
  document.getElementById('ver').textContent = st.version ? 'v' + st.version : '';
  drawLoad();
  // вышла новая версия nfqws2 — метка за номером версии (на узком экране — значок с точкой)
  const nu = st.nfq_update, upB = document.getElementById('nfq-up');
  if (upB) {
    upB.hidden = !(nu?.available || nu?.running);
    upB.title = nu?.running ? 'Идёт обновление nfqws2' : `Вышла nfqws2 ${nu?.latest} — обновить`;
    upB.setAttribute('aria-label', upB.title);
    upB.replaceChildren(icon('upload'), h('span', { class: 't', text: nu?.running ? '…' : '↑ ' + (nu?.latest || '') }));
  }
  document.getElementById('ui-ver').textContent = st.ui?.version ? 'v' + st.ui.version : '';
  const need = st.running && (st.restart_needed || !st.in_sync) && !pendingActive();
  const u = st.ui?.update;
  const repoLink = document.getElementById('repo-link');
  if (repoLink) repoLink.title = `nfqws2-ui ${st.ui?.version || ''} — исходный код на GitHub. Версия и обновления: Настройки → О программе`;
  document.getElementById('banner').replaceChildren(
    need ? h('div', { class: 'banner' }, h('div', { class: 'banner-in' },
      h('span', { text: 'Конфиг изменён после запуска — изменения вступят в силу после перезапуска.', title: (st.restart_why || []).join('; ') }),
      btn(restartLabel(), safeRestart, 'warn small', 'refresh'))) : []);
  // Плашка обновления — отдельно: #banner на широком экране скрыт (там «нужен перезапуск» показывает колонка слева)
  document.getElementById('upd-banner').replaceChildren(
    u?.available && !updateSkipped(u.latest) ? h('div', { class: 'banner info' }, h('div', { class: 'banner-in' },
      h('span', {}, icon('gift'), ` Вышла новая версия nfqws2-ui ${u.latest} (у вас ${u.current}).`),
      btn('Что нового', () => openUpdate(u), 'small ghost'),
      u.can_update ? btn(u.running ? 'Обновляется…' : 'Обновить', () => openUpdate(u, true), 'small primary', 'download', { disabled: u.running }) : null,
      h('button', { type: 'button', class: 'btn ghost icon small', title: 'Не напоминать об этой версии', 'aria-label': 'Скрыть', onclick: () => { try { localStorage.setItem('nfqws-ui-update-skip', u.latest); } catch { /* нет хранилища */ } updateChrome(); } }, icon('x')))) : []);
  const undo = document.getElementById('undo-btn');
  if (undo) {
    undo.hidden = !st.undo || pendingActive();
    undo.title = st.undo ? `Отменить последнее изменение: ${st.undo.note || st.undo.files.join(', ')} (${fmtDate(st.undo.ts)})` : '';
  }
  renderPending();
  updateSide();
}

// ---------- отмена, перезапуск с проверкой, откат к рабочему состоянию ----------

const pendingActive = () => ['checking', 'waiting'].includes(S.state?.pending?.state);

async function pollPending() {
  const was = S.state?.pending;
  const r = await api('pending_get');
  const p = r.pending;
  if (p && p.id === was?.id && p.state === was?.state && JSON.stringify(p.checks) === JSON.stringify(was?.checks)) return;
  if (['checking', 'waiting'].includes(p?.state) && p.id === was?.id) {
    S.state.pending = p;
    S.state.now = r.now;
    renderPending();
    return;
  }
  if (p?.state === 'rolled_back') S.conf = null;   // конфиг вернулся к прежнему
  await loadState();
}

async function undoLast(confirmIt = true) {
  const u = S.state?.undo;
  if (confirmIt && u && !await ask(`Отменить последнее изменение?\n\n${u.note || 'изменение'}\nФайлы: ${u.files.join(', ')}\n\nФайлы вернутся к состоянию до него. Саму отмену тоже можно отменить через «Историю изменений».`, 'Вернуть как было')) return;
  const r = await guarded(() => api('undo_last'));
  if (!r) return;
  toast(`Отменено: ${r.note || r.files.join(', ')}` + (r.conf ? '. Перезапустите nfqws2, чтобы применить.' : ''));
  setDirty(false);
  await loadState().catch(() => {});
  S.conf = null;
  route(true);
}

// A service action in flight (restart / stop / start): its button spins, all service buttons wait
async function svcBusy(action, fn) {
  S.svcAction = action;
  updateChrome();
  updateSide();
  try { return await fn(); } finally {
    S.svcAction = null;
    updateChrome();
    updateSide();
  }
}
// the service state for badges: while a restart / stop / start runs — its name in the warning colour, not the old state
function svcState(st) {
  const busy = { restart: 'Перезапуск', stop: 'Остановка', start: 'Запуск' }[S.svcAction];
  return busy ? ['warn', busy] : st.running ? ['ok', 'Работает'] : ['bad', 'Остановлен'];
}
// a service button: while its action runs — a spinning arrow instead of the icon (same size, the label stays)
function svcBtn(label, action, onclick, cls, ic, attrs = {}) {
  const on = S.svcAction === action;
  return btn(label, onclick, cls + (on ? ' busy' : ''), on ? 'refresh' : ic, { ...attrs, disabled: attrs.disabled || !!S.svcAction, 'aria-busy': on ? 'true' : null });
}

// «Основное» → «После перезапуска проверять сайты» (on by default); off — a plain restart: no check, no rollback
const restartChecks = () => S.state?.ui?.restart_check !== false;
const restartTip = () => restartChecks()
  ? 'Перезапуск с проверкой: если сайты перестанут открываться или вы не подтвердите за 3 минуты, изменения откатятся сами'
  : 'Перезапуск без проверки сайтов и без отката — так выбрано в «Система → Основное»';
const restartLabel = () => restartChecks() ? 'Перезапустить с проверкой' : 'Перезапустить';

async function safeRestart() {
  if (!restartChecks()) return service('restart');
  await svcBusy('restart', async () => {
    const r = await guarded(() => api('safe_restart', { minutes: 3 }));
    if (!r) return;
    toast(r.fixed ? `nfqws2 перезапущен (${r.fixed}). Проверяю сайты…` : 'nfqws2 перезапущен. Проверяю сайты…', r.fixed ? { long: true } : undefined);
    await loadState().catch(() => {});
  });
}

async function rollbackToWorking() {
  const rb = S.state?.rollback;
  if (!rb?.files.length) return;
  if (!await ask(`Вернуть рабочее состояние на ${fmtDate(rb.since)}?\n\nФайлы: ${rb.files.join(', ')}\n\nПосле этого nfqws2 перезапустится. Текущее состояние сохранится в историю и снимок.`, 'Вернуть')) return;
  const r = await guarded(() => api('safe_rollback'));
  if (!r) return;
  toast(`Возвращено: ${r.files.join(', ') || 'без изменений'}`);
  S.conf = null;
  await loadState().catch(() => {});
  route(true);
}

// Конфиг и списки — к началу истории (обычно — установка интерфейса). Свои списки, созданные позже, остаются
async function resetInitial() {
  const r = await guarded(() => api('initial_get'));
  if (!r) return;
  if (!r.files.length) { toast('С начала истории конфиг и списки не менялись — возвращать нечего'); return; }
  const go = btn('Вернуть и перезапустить с проверкой', async () => {
    go.disabled = true;
    const x = await guarded(() => api('initial_reset'));
    bg.close();
    if (!x) return;
    toast(`Возвращено: ${x.files.join(', ') || 'без изменений'}`);
    S.conf = null;
    await safeRestart();
    route(true);
  }, 'warn');
  const bg = modal('Вернуть как при установке', h('div', { class: 'modal-b' },
    h('p', { text: `Конфиг nfqws2 и списки станут такими, какими были ${fmtDate(r.at)}, когда интерфейс начал вести историю (обычно это и есть установка). Что вернётся:` }),
    h('ul', {}, r.files.map((f) => h('li', {}, h('span', { class: 'mono', text: f.file }),
      h('span', { class: 'muted sm', text: f.exists ? ` — +${f.n_added} −${f.n_removed} строк` : ' — файл был удалён, появится снова' })))),
    h('p', { class: 'sm muted', text: 'Свои списки, созданные позже, останутся, но конфиг на них ссылаться перестанет. Настройки интерфейса (мониторинг, уведомления, история подборов) не меняются. Перед возвратом делается снимок, а «Отменить» в шапке вернёт всё обратно.' }),
    h('p', { class: 'sm', text: 'После возврата nfqws2 перезапустится с проверкой: если сайты мониторинга перестанут открываться или вы не подтвердите за 3 минуты, всё вернётся как сейчас.' })), go);
}

let pendingTimer = null;
function renderPending() {
  const box = document.getElementById('pending');
  if (!box) return;
  const p = S.state?.pending;
  clearInterval(pendingTimer);
  if (!p) { box.replaceChildren(); return; }
  if (pendingActive()) {
    const clientOffset = Date.now() / 1000 - S.state.now;
    const left = () => Math.max(0, Math.round(p.deadline - (Date.now() / 1000 - clientOffset)));
    const timer = h('b', { class: 'num' });
    const tick = () => { const s = left(); timer.textContent = `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`; };
    tick();
    // новый запрос — только когда прошлый вернулся: на слабом роутере запрос идёт дольше секунды.
    // Опрашиваем только ход проверки (pending_get), полное состояние — когда она закончилась (NFQ-L130)
    let busy = false;
    pendingTimer = setInterval(() => {
      tick();
      if (left() % 3 === 0 && !busy) { busy = true; pollPending().catch(() => {}).finally(() => { busy = false; }); }
    }, 1000);
    const checks = p.checks || [];
    const regressed = checks.filter((c) => c.regressed);
    box.replaceChildren(h('div', { class: 'pending ' + (regressed.length ? 'bad' : 'warn') }, h('div', { class: 'pending-in' },
      h('div', { class: 'item-main' },
        h('b', { text: p.state === 'checking' ? 'nfqws2 перезапущен — проверяю сайты…' : regressed.length ? `После изменений перестали открываться: ${regressed.map((c) => c.host).join(', ')}` : 'nfqws2 перезапущен. Всё работает?' }),
        h('span', { class: 'sm' }, 'Если не подтвердить, через ', timer, ' изменения откатятся сами' + (p.files?.length ? ` (${p.files.join(', ')})` : '') + '.'),
        p.mem_low ? h('span', { class: 'sm', text: `На роутере мало свободной памяти (${p.mem} МБ). Пока идёт проверка, лучше не открывать другие страницы интерфейса.` }) : null,
        checks.length ? h('div', { class: 'chips', style: 'margin-top:4px' }, checks.map((c) => chip((c.ok ? '✓ ' : '✗ ') + c.host, c.ok ? 'ok' : 'bad'))) : null),
      p.state === 'checking' ? h('span', { class: 'spin' }) : null,
      btn('Всё работает — оставить', async () => { if (await guarded(() => api('safe_confirm'), 'Изменения подтверждены')) loadState(); }, regressed.length ? 'small' : 'small primary', 'ok'),
      btn('Откатить сейчас', async () => { if (await guarded(() => api('safe_rollback'), 'Изменения откачены')) { S.conf = null; await loadState(); route(true); } }, regressed.length ? 'small warn' : 'small danger', 'history'))));
    return;
  }
  let dismissed = '';
  try { dismissed = localStorage.getItem('nfqws-ui-pending-seen') || ''; } catch { /* нет хранилища */ }
  if (p.state === 'rolled_back' && p.id !== dismissed && S.state.now - (p.finished || 0) < 1800) {
    box.replaceChildren(h('div', { class: 'pending bad' }, h('div', { class: 'pending-in' },
      h('div', { class: 'item-main' }, h('b', { text: 'Изменения откачены: ' + (p.reason || '') }),
        h('span', { class: 'sm', text: (p.rolled_files?.length ? 'Возвращены: ' + p.rolled_files.join(', ') + '. ' : '') + 'nfqws2 перезапущен с прежними настройками.' })),
      btn('Понятно', () => { try { localStorage.setItem('nfqws-ui-pending-seen', p.id); } catch { /* нет хранилища */ } box.replaceChildren(); }, 'small'))));
    return;
  }
  box.replaceChildren();
}

// Кнопка автоисправления замечания проверки конфига
function fixButton(x, disabledReason = null) {
  const f = x.fix;
  if (!f || typeof f !== 'object' || !f.op) return null;
  const run = async (choice) => {
    if (S.dirty) { toast('Сначала сохраните или отмените свои правки', { err: true }); return; }
    const r = await guarded(() => api('fix_apply', { var: x.var, tok: x.tok, msg: x.msg, choice }));
    if (!r) return;
    toast('Исправлено: ' + f.label, { undo: () => undoLast(false) });
    S.conf = null;
    await loadState().catch(() => {});
    route();
  };
  const attrs = disabledReason ? { disabled: true, title: disabledReason } : { title: (f.changes?.length ? 'Будет сделано:\n' + f.changes.join('\n') + '\n\n' : '') + 'Исправить автоматически. Отменить можно кнопкой «Отменить» или в истории изменений.' };
  if (f.choices?.length) {
    const sel = h('select', { class: 'mini-sel mono', 'aria-label': 'Значение' }, f.choices.map((c) => h('option', { value: c, text: c })));
    // на телефоне ряд сжимается до ширины блока, а уже список — кнопка остаётся целой
    return h('span', { class: 'row', style: 'gap:4px;flex-wrap:nowrap;min-width:0;max-width:100%' }, sel, btn(f.label, () => run(sel.value), 'small primary', 'wand', attrs));
  }
  if (f.op === 'add_param') {
    const inp = h('input', { class: 'input mono', style: 'width:120px;min-height:26px;padding:1px 8px', placeholder: 'значение', 'aria-label': 'Значение ' + f.key });
    return h('span', { class: 'row', style: 'gap:4px;flex-wrap:nowrap' }, inp, btn(f.label, () => run(inp.value), 'small primary', 'wand', attrs));
  }
  return btn(f.label, () => run(), 'small', 'wand', attrs);
}

async function service(action) {
  const names = { restart: 'Перезапуск', stop: 'Остановка', start: 'Запуск' };
  if (action === 'stop' && !await ask('Остановить nfqws2? Заблокированные сайты перестанут открываться.', 'Остановить', 'danger')) return;
  toast(names[action] + '…');
  const r = await svcBusy(action, async () => {
    const res = await guarded(() => api('service', { action }));
    if (res) await loadState().catch(() => {});
    return res;
  });
  if (!r) return;
  if (!r.ok) modal('Не получилось: ' + action, h('div', { class: 'stack modal-b' }, h('p', { class: 'sm muted', text: 'Вот что ответил скрипт запуска:' }), copyBox(h('pre', { class: 'box', text: r.output || 'без вывода' }))));
  if (r.fixed) toast('Исправлено: ' + r.fixed, { long: true });
  else if (r.ok) toast(S.state?.running ? 'nfqws2 работает' : 'nfqws2 остановлен');
  route();
}

async function logout() {
  await api('logout').catch(() => {});
  S.auth = false;
  renderLogin();
}

function renderLogin(error) {
  const user = h('input', { class: 'input', id: 'login-user', name: 'user', value: 'root', autocomplete: 'username', 'aria-label': 'Пользователь' });
  const pass = h('input', { class: 'input', id: 'login-pass', name: 'password', type: 'password', autocomplete: 'current-password', placeholder: 'Пароль роутера', 'aria-label': 'Пароль' });
  const err = h('div', { class: 'sm', style: 'color:var(--bad)', text: error || '', role: 'alert' });
  const form = h('form', { class: 'panel', onsubmit: async (e) => {
    e.preventDefault();
    err.textContent = '';
    try {
      await api('login', { user: user.value, password: pass.value });
      S.auth = true;
      // вход мог понадобиться после обновления: тогда в браузере прежний код — загружаем новый
      if (await reloadIfStale()) return;
      start();
    } catch (x) {
      err.textContent = x.message;
    }
  } },
  h('div', { class: 'row' }, logo(34), h('h1', { text: 'nfqws2 — вход' })),
  h('p', { class: 'muted', text: 'Логин и пароль — как для SSH роутера.' }),
  user, pass, err, h('button', { class: 'btn primary', type: 'submit', text: 'Войти' }));
  document.getElementById('app').replaceChildren(h('div', { class: 'login' }, form));
  pass.focus();
}
