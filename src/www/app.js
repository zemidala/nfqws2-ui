'use strict';

// Интерфейс nfqws2. Три компоновки на выбор (Оформление): боковое меню, вкладки по задачам с колонкой обзора,
// «вокруг сайта». Страницы и их адреса общие. Без сборки и внешних зависимостей, данные — через api.php.

const ICONS = {
  home: '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
  sites: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
  tests: '<path d="M9 3h6M10 3v6l-5 9a2 2 0 0 0 1.7 3h10.6a2 2 0 0 0 1.7-3l-5-9V3"/><path d="M7 15h10"/>',
  settings: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1 7 17M17 7l2.1-2.1"/>',
  log: '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h6"/>',
  refresh: '<path d="M21 12a9 9 0 1 1-2.6-6.4L21 8"/><path d="M21 3v5h-5"/>',
  logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
  ok: '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
  bad: '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
  alert: '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17.5v.5"/>',
  info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5v.5"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  x: '<path d="M6 6l12 12M18 6 6 18"/>',
  trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
  history: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
  back: '<path d="M15 18 9 12l6-6"/>',
  chev: '<path d="m9 18 6-6-6-6"/>',
  up: '<path d="m6 15 6-6 6 6"/>',
  down: '<path d="m6 9 6 6 6-6"/>',
  play: '<path d="M7 4v16l13-8z"/>',
  stop: '<rect x="6" y="6" width="12" height="12" rx="1"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
  edit: '<path d="M4 20h4L19 9l-4-4L4 16z"/>',
  wand: '<path d="m15 4 5 5L9 20l-5-5z"/><path d="M13 6l5 5"/>',
  menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
  dash: '<g fill="currentColor" stroke="none"><rect x="3" y="3" width="8.25" height="10.5"/><rect x="3" y="15" width="8.25" height="6"/><rect x="12.75" y="3" width="8.25" height="6"/><rect x="12.75" y="10.5" width="8.25" height="10.5"/></g>',
  gear: '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
  shield: '<path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.2 7.5 9.5 4.3-1.3 7.5-4.9 7.5-9.5V6z"/>',
  more: '<circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/>',
  pulse: '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
  layers: '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
  download: '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
  upload: '<path d="M12 20V9M7 14l5-5 5 5M5 4h14"/>',
  copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h8"/>',
  pin: '<path d="M9 4h6l-1 6 4 4H6l4-4z"/><path d="M12 14v7"/>',
  undo: '<path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/>',
  power: '<path d="M12 3v8"/><path d="M7 6.3a8 8 0 1 0 10 0"/>',
  dup: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M4 16V6a2 2 0 0 1 2-2h10"/><path d="M12 14h4"/>',
  git: '<circle cx="6" cy="5" r="2"/><circle cx="6" cy="19" r="2"/><circle cx="18" cy="7" r="2"/><path d="M6 7v10M18 9c0 5-7 4-11 8"/>',
  arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
  gift: '<rect x="3" y="8" width="18" height="5" rx="1"/><path d="M5 13v8h14v-8M12 8v13M12 8S10 3 7.5 4 9 8 12 8zM12 8s2-5 4.5-4S15 8 12 8z"/>',
};

const LIST_NAMES = {
  'user.list': 'Мой список сайтов', 'exclude.list': 'Исключения — не трогать', 'auto.list': 'Автосписок',
  'cloudflare.list': 'Сайты на Cloudflare', 'ipset.list': 'Мои IP-адреса', 'ipset_exclude.list': 'IP-исключения',
  'ipset_cf.list': 'IP Cloudflare', 'ipset_aws.list': 'IP Amazon AWS', 'ipset_do.list': 'IP DigitalOcean',
  'ipset_htz.list': 'IP Hetzner', 'ipset_ovh.list': 'IP OVH', 'ipset-games.list': 'IP игровых серверов',
  'ipset_gamefilter.list': 'IP игровых серверов (gamefilter)', 'youtube.list': 'YouTube', 'google.list': 'Google',
  'discord.list': 'Discord', 'telegram.list': 'Telegram', 'instagram.list': 'Instagram', 'twitch.list': 'Twitch',
  'whatsapp.list': 'WhatsApp', 'blizzard.list': 'Blizzard', 'ea.list': 'EA', 'aws_game.list': 'Игры на AWS', 'ipset_meta.list': 'IP Meta',
  'exclude_special.list': 'Особые исключения',
};

const VAR_INFO = {
  ISP_INTERFACE: ['Интерфейс провайдера', 'Через какой интерфейс идёт трафик в интернет. Несколько — через пробел.'],
  NFQWS_EXTRA_ARGS: ['Режим основного профиля', 'Для каких сайтов работают профили «HTTP/HTTPS» и «QUIC» по спискам сайтов.'],
  TCP_PORTS: ['TCP-порты в nfqws2', 'Какие TCP-порты вообще отправлять в nfqws2 (правила iptables). Через запятую, диапазон — 5000:5010.'],
  UDP_PORTS: ['UDP-порты в nfqws2', 'То же для UDP.'],
  IPV6_ENABLED: ['Обрабатывать IPv6', ''],
  LOG_LEVEL: ['Отладочный журнал', 'Подробный вывод nfqws2 — только для поиска проблем, сильно нагружает журнал.'],
  NFQWS_ARGS_CUSTOM: ['Свои профили', 'Запускаются первыми, профили разделяет --new.'],
  NFQWS_ARGS: ['HTTP/HTTPS', 'Стратегия профилей HTTP/HTTPS (по спискам сайтов и по IP).'],
  NFQWS_ARGS_QUIC: ['QUIC', 'Стратегия профилей QUIC (UDP 443).'],
  NFQWS_ARGS_UDP: ['UDP: игры, звонки', 'Стратегия для UDP.'],
  NFQWS_ARGS_IPSET: ['IP-списки', 'Общие для профилей «по IP-спискам».'],
  NFQWS_BASE_ARGS: ['Параметры запуска', 'Общие для всего процесса: lua-скрипты, блобы.'],
  MODE_LIST: ['Списки сайтов', 'Общие для профилей «по спискам сайтов».'],
  MODE_ALL: ['Исключения режима «все сайты»', ''],
};
const ARG_VARS = ['NFQWS_ARGS_CUSTOM', 'NFQWS_ARGS', 'NFQWS_ARGS_QUIC', 'NFQWS_ARGS_UDP', 'NFQWS_ARGS_IPSET', 'NFQWS_BASE_ARGS'];
const MODES = [['$MODE_LIST', 'Только сайты из списков'], ['$MODE_ALL', 'Все сайты, кроме исключений'], ['$MODE_AUTO', 'Списки + найденные автоматически']];
const LEVEL_RANK = { error: 3, warning: 2, info: 1 };
const LIST_OPTS = ['hostlist', 'hostlist-exclude', 'ipset', 'ipset-exclude', 'hostlist-domains', 'hostlist-exclude-domains', 'ipset-ip', 'ipset-exclude-ip', 'hostlist-auto'];
const LIST_ROLE = { hostlist: 'сайты', 'hostlist-exclude': 'кроме сайтов', ipset: 'IP', 'ipset-exclude': 'кроме IP', 'hostlist-domains': 'сайты', 'hostlist-exclude-domains': 'кроме сайтов', 'ipset-ip': 'IP', 'ipset-exclude-ip': 'кроме IP', 'hostlist-auto': 'автосписок' };

const S = { auth: null, state: null, catalog: null, conf: null, dirty: false, check: null };
const wideMq = window.matchMedia('(min-width: 1100px)');
const isWide = () => wideMq.matches;

// append/replaceChildren превращают null в текст "null" — пропускаем пустые значения
for (const m of ['append', 'replaceChildren']) {
  const orig = Element.prototype[m];
  Element.prototype[m] = function (...kids) {
    return orig.apply(this, kids.flat(Infinity).filter((k) => k != null && k !== false));
  };
}

// ============ утилиты ============

function h(tag, attrs, ...kids) {
  const e = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v == null || v === false) continue;
    if (k === 'class') e.className = v;
    else if (k === 'text') e.textContent = v;
    else if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
    else if (k === 'value') e.value = v;
    else if (k === 'checked' || k === 'selected' || k === 'disabled') e[k] = !!v;
    else e.setAttribute(k, v === true ? '' : v);
  }
  e.append(...kids);
  return e;
}

function icon(name) {
  const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  s.setAttribute('viewBox', '0 0 24 24');
  s.setAttribute('fill', 'none');
  s.setAttribute('stroke', 'currentColor');
  s.setAttribute('stroke-width', '2');
  s.setAttribute('stroke-linecap', 'round');
  s.setAttribute('stroke-linejoin', 'round');
  s.setAttribute('aria-hidden', 'true');
  s.innerHTML = ICONS[name];
  return s;
}

// Знак ZD: цвета фирменные, от темы не зависят
const LOGO = '<mask id="zd-cut" maskUnits="userSpaceOnUse" x="0" y="0" width="200" height="200"><rect width="200" height="200" fill="#fff"/><rect x="62" y="73" width="40" height="6" fill="#000"/><rect x="30" y="121" width="40" height="6" fill="#000"/><rect x="128" y="50" width="6" height="30" fill="#000"/><rect x="128" y="120" width="6" height="30" fill="#000"/></mask>'
  + '<rect x="10" y="10" width="180" height="180" rx="34" fill="#ff6a14"/><g fill="#07080b" mask="url(#zd-cut)"><path d="M34 56H98V76L60 124H98V144H34V124L72 76H34Z"/><path fill-rule="evenodd" d="M108 56H134C154 56 166 74 166 100C166 126 154 144 134 144H108ZM128 76V124H133C142 124 146 114 146 100C146 86 142 76 133 76Z"/></g>';
function svgEl(viewBox, html, cls, label) {
  const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  s.setAttribute('viewBox', viewBox);
  s.setAttribute('class', cls);
  if (label) { s.setAttribute('role', 'img'); s.setAttribute('aria-label', label); } else s.setAttribute('aria-hidden', 'true');
  s.innerHTML = html;
  return s;
}
function logo(size) {
  const s = svgEl('0 0 200 200', LOGO, 'zd', 'ZD');
  s.style.width = s.style.height = size + 'px';
  return s;
}

const btn = (label, onclick, cls = '', ic = null, attrs = {}) =>
  h('button', { class: 'btn ' + cls, type: 'button', onclick, ...attrs }, ic && icon(ic), label);
const chip = (text, cls = '') => h('span', { class: 'chip ' + cls, text });
const spinner = (text) => h('div', { class: 'skeleton' }, h('span', { class: 'spin' }), text);
const levelIcon = (lvl) => h('span', { class: 'lvl ' + lvl }, icon({ error: 'bad', warning: 'alert', info: 'info', ok: 'ok' }[lvl]));
const notice = (kind, title, text, ...extra) => h('div', { class: 'notice ' + kind },
  icon({ ok: 'ok', bad: 'bad', warn: 'alert', info: 'info' }[kind]),
  h('div', { class: 'grow' }, h('b', { text: title }), text ? h('div', { class: 't', text }) : null), ...extra);
const panel = (title, extra, ...body) => h('section', { class: 'panel' },
  title ? h('div', { class: 'panel-h' }, h('h2', { text: title }), extra) : null, ...body);
const plural = (n, one, few, many) => {
  const m10 = n % 10, m100 = n % 100;
  return `${n} ${m10 === 1 && m100 !== 11 ? one : m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14) ? few : many}`;
};
const fmtBytes = (b) => b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' МБ' : b >= 1024 ? Math.round(b / 1024) + ' КБ' : b + ' Б';
const fmtNum = (n) => n >= 1e6 ? (n / 1e6).toFixed(1).replace('.', ',') + ' млн' : n >= 1e4 ? Math.round(n / 1e3) + ' тыс' : n.toLocaleString('ru-RU');
const fmtDate = (t) => t ? new Date(t * 1000).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—';
const fmtTime = (t) => new Date(t * 1000).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
const fmtDay = (t) => {
  const d = new Date(t * 1000), now = new Date();
  const y = new Date(now); y.setDate(now.getDate() - 1);
  if (d.toDateString() === now.toDateString()) return 'Сегодня';
  if (d.toDateString() === y.toDateString()) return 'Вчера';
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: d.getFullYear() === now.getFullYear() ? undefined : 'numeric' });
};
const fmtAgo = (s) => s < 90 ? 'только что' : s < 3600 ? Math.round(s / 60) + ' мин назад' : s < 172800 ? Math.round(s / 3600) + ' ч назад' : Math.round(s / 86400) + ' дн назад';
const listName = (f) => LIST_NAMES[f] || f.replace(/\.list$/, '');
const base = (p) => String(p).split('/').pop();
const debounce = (fn, ms) => { let t; const d = (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; d.flush = (...a) => { clearTimeout(t); fn(...a); }; return d; };
const worst = (issues) => (issues || []).reduce((w, x) => (LEVEL_RANK[x.level] > (LEVEL_RANK[w] || 0) ? x.level : w), null);
const tokensOf = (s) => (s || '').trim().split(/\s+/).filter(Boolean);

function toast(text, opts = {}) {
  const t = h('div', { class: 'toast' + (opts.err ? ' err' : ''), role: opts.err ? 'alert' : 'status' }, h('span', { text }));
  if (opts.undo) t.append(h('button', { type: 'button', text: 'Вернуть', onclick: () => { t.remove(); opts.undo(); } }));
  document.getElementById('toasts').append(t);
  setTimeout(() => t.remove(), opts.undo ? 7000 : opts.err ? 7000 : 3000);
}

class ApiError extends Error {
  constructor(msg, data) { super(msg); this.data = data; }
}

async function api(cmd, data = {}) {
  const r = await fetch('api.php', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ cmd, ...data }),
  });
  let j = {};
  try { j = await r.json(); } catch { /* пусто */ }
  if (r.status === 401 && cmd !== 'login') {
    S.auth = false;
    renderLogin();
    throw new ApiError('Нужно войти', j);
  }
  if (!r.ok) throw new ApiError(j.error || 'Ошибка ' + r.status, j);
  return j;
}

async function guarded(fn, okText) {
  try {
    const r = await fn();
    if (okText) toast(okText);
    return r;
  } catch (e) {
    toast(e.message, { err: true });
    return undefined;
  }
}

// ============ оформление ============

function getLook() {
  try { return { variant: 'a', mode: 'auto', layout: 'menu', ...JSON.parse(localStorage.getItem('nfqws-ui-look') || '{}') }; } catch { return { variant: 'a', mode: 'auto', layout: 'menu' }; }
}
function applyLook(look = getLook()) {
  document.documentElement.dataset.variant = look.variant;
  document.documentElement.dataset.mode = look.mode;
  document.documentElement.dataset.layout = ['menu', 'tabs', 'site'].includes(look.layout) ? look.layout : 'menu';
  document.documentElement.toggleAttribute('data-simple', !!look.simple);
}
function setLook(patch) {
  const look = { ...getLook(), ...patch };
  try { localStorage.setItem('nfqws-ui-look', JSON.stringify(look)); } catch { /* нет хранилища */ }
  applyLook(look);
}
applyLook();

// ============ данные ============

async function loadState() {
  S.state = await api('state');
  updateChrome();
  return S.state;
}
async function loadCatalog() {
  if (!S.catalog) S.catalog = await api('catalog');
  return S.catalog;
}
async function loadConf() {
  S.conf = await api('conf_get');
  return S.conf;
}
const prof = (n) => S.state?.profiles.find((p) => p.index === n);
const confProf = (n) => S.state?.conf_profiles.find((p) => p.index === n);

function profName(p) {
  const s = p.source;
  if (!s) return 'Профиль';
  if (s.source === 'NFQWS_ARGS_CUSTOM') return 'Свой профиль ' + s.part;
  if (s.source === 'NFQWS_ARGS_UDP') return 'UDP: игры, звонки';
  if (s.source === 'NFQWS_ARGS_QUIC') return s.with === 'NFQWS_ARGS_IPSET' ? 'QUIC по IP' : 'QUIC по сайтам';
  return s.with === 'NFQWS_ARGS_IPSET' ? 'HTTP/HTTPS по IP' : 'HTTP/HTTPS по сайтам';
}
function profScope(p) {
  if (p.empty_hostlists) return 'списки пусты — весь трафик';
  if (p.has_host_filter && p.has_ip_filter) return 'сайты и IP из списков';
  if (p.has_host_filter) return 'сайты из списков';
  if (p.has_ip_filter) return 'IP из списков';
  return p.has_excludes ? 'все, кроме исключений' : 'весь трафик';
}
const portsText = (ports) => [ports.tcp && 'TCP ' + ports.tcp, ports.udp && 'UDP ' + ports.udp].filter(Boolean).join(' · ');
// Короткое описание стратегии: для circular — «перебор: N стратегий», иначе функции через «+»
const strategyText = (p) => {
  const fns = p.desync.map((d) => d.fn);
  if (fns.includes('circular')) {
    const n = new Set(p.desync.map((d) => (d.params.match(/(?:^|:)strategy=(\d+)/) || [])[1]).filter(Boolean)).size;
    return n ? `перебор: ${plural(n, 'стратегия', 'стратегии', 'стратегий')}` : 'перебор стратегий';
  }
  return [...new Set(fns)].join(' + ') || 'без стратегии';
};

// Замечания проверки, относящиеся к профилю конфига
function profIssues(p) {
  const st = S.state;
  if (!p.source) return [];
  const all = (st.lint?.issues || []).filter((x) => x.var === p.source.source);
  const mine = (x) => !x.msg.startsWith('Профиль #') || x.msg.startsWith(`Профиль #${p.index}:`);
  if (p.source.source !== 'NFQWS_ARGS_CUSTOM') return all.filter(mine);
  const toks = st.conf_tokens?.NFQWS_ARGS_CUSTOM || [];
  let part = 1;
  const partOf = toks.map((t) => (t === '--new' ? part++ : part));
  return all.filter((x) => mine(x) && (x.tok == null || partOf[x.tok] === p.source.part));
}

function listEffect(l, profiles = S.state.profiles) {
  if (!l.used.length) return 'не используется ни одним профилем';
  return l.used.map((u) => {
    const p = profiles.find((x) => x.index === u.profile);
    if (!p) return '#' + u.profile;
    if (u.role === 'exclude') return `исключает из #${p.index}`;
    return `#${p.index} ${profName(p)}: ` + (portsText(p.remaining) || 'перекрыт профилями выше');
  }).join('; ');
}

// ============ каркас ============

// Страницы: адрес и название. Адреса прежние (#/tests/…, #/settings/…, #/sites/…) — старые ссылки и закладки
// работают в любой компоновке; компоновка решает только, где показаны переходы между страницами.
const PAGES = {
  over: ['#/', 'Обзор'],
  pick: ['#/tests/pick', 'Подбор стратегии'], trace: ['#/tests/trace', 'Трассировка'],
  mon: ['#/tests/monitor', 'Мониторинг'], tg: ['#/tests/notify', 'Уведомления'],
  prof: ['#/settings', 'Профили'], lists: ['#/sites', 'Списки'],
  basic: ['#/settings/basic', 'Основное'], base: ['#/settings/base', 'Параметры запуска'], raw: ['#/settings/raw', 'Конфиг целиком'],
  backup: ['#/settings/backup', 'Резервные копии'], hist: ['#/settings/hist', 'История изменений'], log: ['#/log', 'Журнал'],
  look: ['#/settings/look', 'Оформление'], about: ['#/settings/about', 'О программе'],
  // ещё не сделаны: в меню видны, но заблокированы и помечены «скоро»
  diag: ['#/diag', 'Диагноз блокировки'], phist: ['#/tests/history', 'История подборов'], auto: ['#/tests/auto', 'Автоподбор'], asn: ['#/asn', 'Список по ASN'], report: ['#/settings/report', 'Отчёт для помощи'],
};
const NEW_PAGES = ['diag', 'phist', 'auto', 'asn', 'report'];   // только что появились — помечаются в меню (в упрощённом виде — нет)
const SOON_HINT = 'Ещё в разработке — появится в одной из следующих версий';
const SYS_PAGES = ['basic', 'base', 'raw', 'backup', 'hist', 'log', 'report', 'look', 'about'];
const SYS_NAV = ['basic', 'base', 'raw', 'backup', 'hist', 'log', 'report', 'look', 'about'];
// Компоновка «Боковое меню»: группы и их страницы
const MENU = [[null, ['over']], ['Проверка сайта', ['diag', 'pick', 'trace', 'phist']], ['Наблюдение', ['mon', 'auto', 'tg']], ['Обход', ['prof', 'lists', 'asn']], ['Система', SYS_NAV]];
// Упрощённый вид (галочка в «Оформлении»): шесть разделов, страницы раздела — вкладками над страницей (как в TOP_TABS)
const MENU_SIMPLE = [['over', 'Обзор', 'home', ['over']], ['pick', 'Проверка сайта', 'tests', ['diag', 'pick', 'trace', 'phist', 'site']], ['mon', 'Наблюдение', 'pulse', ['mon', 'auto', 'tg']],
  ['prof', 'Профили', 'layers', ['prof']], ['lists', 'Списки', 'sites', ['lists', 'asn']], ['basic', 'Система', 'settings', SYS_NAV]];
// Верхние вкладки компоновок: страница по клику, подпись, значок, страницы вкладки, «только на узком экране»
const TOP_TABS = {
  menu: [],
  tabs: [['over', 'Обзор', 'home', ['over'], true], ['pick', 'Проверка', 'tests', ['diag', 'pick', 'trace', 'phist', 'site']], ['mon', 'Мониторинг', 'pulse', ['mon', 'auto', 'tg']],
    ['prof', 'Профили', 'layers', ['prof']], ['lists', 'Списки', 'sites', ['lists', 'asn']], ['basic', 'Система', 'settings', SYS_NAV]],
  site: [['over', 'Обзор', 'home', ['over']], ['mon', 'Сайты', 'pulse', ['mon', 'diag', 'pick', 'trace', 'phist', 'auto', 'tg', 'site']],
    ['prof', 'Профили', 'layers', ['prof']], ['lists', 'Списки', 'sites', ['lists', 'asn']], ['basic', 'Система', 'settings', SYS_NAV]],
};
// Нижняя панель телефона — одна для всех компоновок; остальное — в меню
const BOTTOM = [['over', 'Обзор', 'home', ['over']], ['pick', 'Подбор', 'tests', ['diag', 'pick', 'trace', 'site']], ['mon', 'Мониторинг', 'pulse', ['mon', 'tg']], ['prof', 'Профили', 'layers', ['prof']]];

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
  if (r.tab === 'tests') return { monitor: 'mon', notify: 'tg', trace: 'trace', history: 'phist', auto: 'auto' }[r.arg] || 'pick';
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
const NAV_ICON = { 'Проверка сайта': 'search', 'Наблюдение': 'pulse', 'Обход': 'shield', 'Система': 'gear' };

// Упрощённый вид — по выбору в «Оформлении»; по умолчанию интерфейс полный
const simple = () => document.documentElement.hasAttribute('data-simple');

function menuNav() {
  const cur = pageOf();
  if (simple()) {
    // у раздела — отметка первой из его страниц, у которой она есть (упавшие сайты, ошибки, обновление); число снимков — только во вкладке
    return h('nav', { class: 'nav mnav', 'aria-label': 'Разделы' }, MENU_SIMPLE.map(([id, label, ic, pages]) =>
      h('a', { href: PAGES[id][0], 'data-pages': pages.join(' '), class: pages.includes(cur) ? 'on' : null },
        icon(ic), h('span', { class: 'nm', text: label }), pages.filter((x) => x !== 'backup').map(pageTail).find(Boolean) || null)));
  }
  return h('nav', { class: 'nav mnav', 'aria-label': 'Разделы' }, MENU.map(([head, ids]) => [
    head ? h('div', { class: 'nav-h' }, icon(NAV_ICON[head] || 'settings'), head) : null,
    ids.map((id) => PAGES[id][0]
      ? h('a', { href: PAGES[id][0], 'data-pages': id === 'pick' ? 'pick site' : id, class: id === cur || (id === 'pick' && cur === 'site') ? 'on' : null },
        id === 'over' ? h('span', { class: 'k-ic' }, icon('dash')) : null,
        h('span', { class: 'nm', text: PAGES[id][1] }), NEW_PAGES.includes(id) ? h('span', { class: 'tag new', text: 'новое' }) : pageTail(id))
      : soonItem(id))]));
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
  return h('nav', { class: 'subtabs', 'aria-label': tab[1] }, ids.map((id) => PAGES[id][0] ? h('a', { href: PAGES[id][0], class: id === page ? 'on' : null }, PAGES[id][1], NEW_PAGES.includes(id) && !simple() ? h('span', { class: 'tag new', text: 'новое' }) : null) : soonItem(id)));
}

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
        h('span', { class: 'svc-box' }, h('span', { class: 'svc-name', title: 'Версия nfqws2 на роутере' }, 'nfqws2', h('small', { id: 'ver' })),
          h('span', { id: 'svc', class: 'pill muted' }, h('span', { class: 'dot' }), '…'),
          h('span', { id: 'svc-ctl', class: 'svc-ctl' })))),
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
  // Ссылка на HTTPS — только если он включён (nfqws-ui-setup https on); в упрощённом виде — пунктом меню «⋯»
  const hp = st.ui?.https_port;
  const httpsUrl = location.protocol === 'http:' && hp ? `https://${location.hostname}:${hp}/${location.hash}` : null;
  document.getElementById('https-slot')?.replaceChildren(httpsUrl && !simple()
    ? h('a', { class: 'btn ghost small https-link', href: httpsUrl, title: 'Открыть по HTTPS: пароль и данные идут в зашифрованном виде', 'aria-label': 'Открыть по HTTPS' }, '🔒', h('span', { text: ' Открыть по HTTPS' }))
    : simple() && httpsUrl ? h('a', { href: httpsUrl, role: 'menuitem', title: 'Пароль и данные идут в зашифрованном виде' }, h('span', { class: 'mi', text: '🔒' }), 'Открыть по HTTPS') : []);
  const svc = document.getElementById('svc');
  svc.className = 'pill ' + (st.running ? 'ok' : 'bad');
  svc.replaceChildren(h('span', { class: 'dot' }), st.running ? 'работает' : 'остановлен');
  // Управление сервисом — всегда в верхней панели
  const busy = pendingActive();
  document.getElementById('svc-ctl').replaceChildren(
    st.running
      ? [btn(h('span', { class: 'ctl-label', text: 'Перезапустить' }), safeRestart, 'small ghost', 'power', { title: 'Перезапустить nfqws2 с проверкой: если сайты перестанут открываться или вы не подтвердите за 3 минуты, изменения откатятся сами', 'aria-label': 'Перезапустить', disabled: busy }),
        btn(h('span', { class: 'ctl-label', text: 'Остановить' }), () => service('stop'), 'small ghost danger', 'stop', { title: 'Остановить nfqws2 — обход блокировок перестанет работать', 'aria-label': 'Остановить', disabled: busy })]
      : btn(h('span', { class: 'ctl-label', text: 'Запустить' }), () => service('start'), 'small primary', 'play', { title: 'Запустить nfqws2', 'aria-label': 'Запустить' }));
  document.getElementById('ver').textContent = st.version ? 'v' + st.version : '';
  document.getElementById('ui-ver').textContent = st.ui?.version ? 'v' + st.ui.version : '';
  const need = st.running && (st.restart_needed || !st.in_sync) && !pendingActive();
  const u = st.ui?.update;
  const repoLink = document.getElementById('repo-link');
  if (repoLink) repoLink.title = `nfqws2-ui ${st.ui?.version || ''} — исходный код на GitHub. Версия и обновления: Настройки → О программе`;
  document.getElementById('banner').replaceChildren(
    need ? h('div', { class: 'banner' }, h('div', { class: 'banner-in' },
      h('span', { text: 'Конфиг изменён после запуска — изменения вступят в силу после перезапуска.' }),
      btn('Перезапустить с проверкой', safeRestart, 'warn small', 'refresh'))) : []);
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

async function undoLast(ask = true) {
  const u = S.state?.undo;
  if (ask && u && !confirm(`Отменить последнее изменение?\n\n${u.note || 'изменение'}\nФайлы: ${u.files.join(', ')}\n\nФайлы вернутся к состоянию до него. Саму отмену тоже можно отменить через «Историю изменений».`)) return;
  const r = await guarded(() => api('undo_last'));
  if (!r) return;
  toast(`Отменено: ${r.note || r.files.join(', ')}` + (r.conf ? '. Перезапустите nfqws2, чтобы применить.' : ''));
  setDirty(false);
  await loadState().catch(() => {});
  S.conf = null;
  route(true);
}

async function safeRestart() {
  const r = await guarded(() => api('safe_restart', { minutes: 3 }));
  if (!r) return;
  toast('nfqws2 перезапущен. Проверяю сайты…');
  await loadState().catch(() => {});
}

async function rollbackToWorking() {
  const rb = S.state?.rollback;
  if (!rb?.files.length) return;
  if (!confirm(`Вернуть рабочее состояние на ${fmtDate(rb.since)}?\n\nФайлы: ${rb.files.join(', ')}\n\nПосле этого nfqws2 перезапустится. Текущее состояние сохранится в историю и снимок.`)) return;
  const r = await guarded(() => api('safe_rollback'));
  if (!r) return;
  toast(`Возвращено: ${r.files.join(', ') || 'без изменений'}`);
  S.conf = null;
  await loadState().catch(() => {});
  route(true);
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
    pendingTimer = setInterval(() => { tick(); if (left() % 3 === 0) loadState().catch(() => {}); }, 1000);
    const checks = p.checks || [];
    const regressed = checks.filter((c) => c.regressed);
    box.replaceChildren(h('div', { class: 'pending ' + (regressed.length ? 'bad' : 'warn') }, h('div', { class: 'pending-in' },
      h('div', { class: 'item-main' },
        h('b', { text: p.state === 'checking' ? 'nfqws2 перезапущен — проверяю сайты…' : regressed.length ? `После изменений перестали открываться: ${regressed.map((c) => c.host).join(', ')}` : 'nfqws2 перезапущен. Всё работает?' }),
        h('span', { class: 'sm' }, 'Если не подтвердить, через ', timer, ' изменения откатятся сами' + (p.files?.length ? ` (${p.files.join(', ')})` : '') + '.'),
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
    return h('span', { class: 'row', style: 'gap:4px;flex-wrap:nowrap' }, sel, btn(f.label, () => run(sel.value), 'small primary', 'wand', attrs));
  }
  if (f.op === 'add_param') {
    const inp = h('input', { class: 'input mono', style: 'width:120px;min-height:26px;padding:1px 8px', placeholder: 'значение', 'aria-label': 'Значение ' + f.key });
    return h('span', { class: 'row', style: 'gap:4px;flex-wrap:nowrap' }, inp, btn(f.label, () => run(inp.value), 'small primary', 'wand', attrs));
  }
  return btn(f.label, () => run(), 'small', 'wand', attrs);
}

async function service(action) {
  const names = { restart: 'Перезапуск', stop: 'Остановка', start: 'Запуск' };
  if (action === 'stop' && !confirm('Остановить nfqws2? Заблокированные сайты перестанут открываться.')) return;
  toast(names[action] + '…');
  const r = await guarded(() => api('service', { action }));
  if (!r) return;
  if (!r.ok) modal('Не получилось: ' + action, h('div', { class: 'stack' }, h('p', { class: 'sm muted', text: 'Вот что ответил скрипт запуска:' }), h('pre', { class: 'box', text: r.output || 'без вывода' })));
  await loadState().catch(() => {});
  if (r.ok) toast(S.state?.running ? 'nfqws2 работает' : 'nfqws2 остановлен');
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

// ============ маршрутизация ============

let routeSeq = 0;
async function route(refresh = false) {
  if (!S.auth) return;
  const seq = ++routeSeq;
  let r = parseRoute();
  if (!r.tab && overviewCol()) {
    // обзор уже виден в колонке слева — открываем первую вкладку
    const q = r.q.get('q');
    history.replaceState(null, '', PAGES.pick[0]);
    if (q) S.check.run(q);
    r = parseRoute();
  }
  openDrawer(false);
  updateChrome();
  const main = document.getElementById('main');
  if (!S.state || refresh) {
    if (!S.state) main.replaceChildren(spinner('Загрузка…'));
    try { await loadState(); } catch (e) { main.replaceChildren(notice('bad', 'Не удалось получить состояние', e.message)); return; }
  }
  if (seq !== routeSeq) return;
  if (r.tab !== 'settings' || r.arg !== 'raw') setFocus(false);
  const views = { '': viewOverview, sites: viewSites, tests: viewTests, settings: viewSettings, log: viewLog, site: viewSite, diag: viewDiag, asn: viewAsn };
  main.replaceChildren(subTabs(pageOf(r)));
  placeSide();
  try {
    await (views[r.tab] || viewOverview)(main, r);
  } catch (e) {
    console.error(e);
    main.replaceChildren(notice('bad', 'Ошибка', e.message));
  }
  if (!r.q.get('var') && !r.q.get('hl')) window.scrollTo(0, 0);
}

window.addEventListener('hashchange', () => {
  if (S.dirty && !confirm('Есть несохранённые изменения. Уйти без сохранения?')) {
    history.replaceState(null, '', S.dirtyHash);
    return;
  }
  S.dirty = false;
  route();
});
window.addEventListener('beforeunload', (e) => { if (S.dirty) { e.preventDefault(); e.returnValue = ''; } });
wideMq.addEventListener('change', () => route());
const setDirty = (v) => { S.dirty = v; S.dirtyHash = location.hash; };

// ============ колонка обзора ============

const SIDE = {};
function sideBlocks() {
  if (!SIDE.root) {
    SIDE.service = h('section', { class: 'blk' });
    SIDE.problems = h('section', { class: 'blk' });
    SIDE.backup = h('section', { class: 'blk' });
    SIDE.traffic = h('section', { class: 'blk' });
    SIDE.monitor = h('section', { class: 'blk' });
    SIDE.root = h('div', { class: 'side-in' }, SIDE.service, S.check.el, SIDE.problems, SIDE.monitor, SIDE.backup, SIDE.traffic);
  }
  // блоки могла забрать страница «Обзор» или карточка сайта — возвращаем на место
  SIDE.root.append(SIDE.service, S.check.el, SIDE.problems, SIDE.monitor, SIDE.backup, SIDE.traffic);
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
function updateSide() {
  if (!SIDE.root || !S.state) return;   // блоки могут стоять в колонке или на странице «Обзор», причём не все сразу
  renderSideService(SIDE.service);
  renderSideProblems(SIDE.problems);
  renderSideBackup(SIDE.backup);
  renderSideTraffic(SIDE.traffic);
  renderSideMonitor(SIDE.monitor);
}

// Полоска последних проверок: зелёные — открылся, красные — нет
function uptimeBar(recent) {
  return h('span', { class: 'upbar', 'aria-hidden': 'true' }, recent.map((ok) => h('i', { class: ok ? 'ok' : 'bad' })));
}

function renderSideMonitor(el) {
  const m = S.state.monitor;
  if (!m || !m.sites.length) { el.hidden = true; return; }
  el.hidden = false;
  const okN = m.sites.filter((x) => x.last && x.last[1]).length;
  el.replaceChildren(
    h('div', { class: 'blk-h' }, h('h2', { text: 'Мониторинг' }), h('a', { class: 'sm', href: '#/tests/monitor', text: 'открыть' })),
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
      h('div', { class: 'svc-state ' + (st.running ? 'ok' : 'bad') }, h('span', { class: 'dot' }), st.running ? 'Работает' : 'Остановлен')),
    h('div', { class: 'svc-buttons' },
      st.running
        ? [btn('Перезапустить', safeRestart, 'small', 'power', { title: 'Перезапуск с проверкой: если сайты перестанут открываться или вы не подтвердите за 3 минуты, изменения откатятся сами', disabled: pendingActive() }),
          btn('Остановить', () => service('stop'), 'small danger', 'stop', { title: 'Остановить nfqws2 — обход блокировок перестанет работать', disabled: pendingActive() })]
        : btn('Запустить', () => service('start'), 'small primary', 'play'),
      btn('Обновить', () => route(true), 'small ghost', 'refresh', { title: 'Обновить данные на странице' })),
    h('p', { class: 'muted sm num', text: [st.version && 'nfqws2 v' + st.version, st.running && st.process.started && 'запущен ' + fmtAgo(st.now - st.process.started), st.running && st.process.rss_kb && (st.process.rss_kb / 1024).toFixed(1).replace('.', ',') + ' МБ'].filter(Boolean).join(' · ') }),
    !st.running && st.stop ? stopNote(st.stop) : null,
    need && !pendingActive() ? notice('warn', 'Нужен перезапуск', 'Конфиг изменён после запуска nfqws2. После перезапуска интерфейс проверит сайты и откатит изменения, если вы их не подтвердите.', btn('Перезапустить с проверкой', safeRestart, 'small warn')) : null,
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

function problemItems() {
  const st = S.state;
  const items = [];
  const lint = st.lint || { issues: [] };
  if (lint.dry_run && !lint.dry_run.ok) items.push({ level: 'error', title: 'nfqws2 не примет конфиг', text: lint.dry_run.message, href: '#/settings/raw' });
  for (const x of lint.issues) {
    if (x.msg.startsWith('nfqws2: ')) continue;
    const tok = x.var && x.tok != null ? st.conf_tokens?.[x.var]?.[x.tok] : null;
    const p = st.conf_profiles.find((cp) => cp.source?.source === x.var && profIssues(cp).includes(x));
    items.push({ level: x.level, title: p ? `Профиль #${p.index} · ${profName(p)}` : (VAR_INFO[x.var]?.[0] || 'Конфиг'), text: x.msg.replace(/^Профиль #\d+: /, ''), code: tok, href: issueTarget(x), fix: fixButton(x) });
  }
  if (st.rivals?.length) {
    items.push({ level: 'warning', title: 'Работает другой обходчик', text: `${st.rivals.join(', ')} — он правит те же пакеты, что и nfqws2: стратегии мешают друг другу, результаты подбора ненадёжны. Оставьте что-то одно.`, href: PAGES.log[0] });
  }
  if (!st.running && st.stop) items.push({ level: 'error', title: 'nfqws2 остановлен', text: st.stop.text, href: st.stop.kind === 'conf' ? PAGES.raw[0] : '#/' });
  for (const o of st.auto?.offers || []) {
    items.push({ level: 'warning', title: o.host, text: `перестал открываться — автоподбор нашёл рабочую стратегию: ${o.name}`, href: PAGES.auto[0], fix: btn('Применить', () => autoApply(o), 'small', 'ok', { title: o.list ? `Добавить сайт в ${o.list.name} — стратегия уже стоит в профиле #${o.list.profile}; с проверкой и откатом` : 'Отдельный профиль только для этого сайта; с проверкой и откатом' }) });
  }
  for (const p of st.profiles) {
    if (p.state === 'dead' || p.state === 'excludes-only') items.push({ level: 'warning', title: `Профиль #${p.index}`, text: p.state === 'dead' ? 'никогда не срабатывает — его порты забирают профили выше' : 'получает только исключения профилей выше', href: `#/settings/p${p.index}` });
  }
  for (const l of st.lists) {
    const pr = l.problems || {};
    if (pr.error || pr.warning || (pr.info && l.used.length)) {
      items.push({ level: pr.error ? 'error' : pr.warning ? 'warning' : 'info', title: listName(l.name) + (l.used.length ? '' : ' (не используется)'),
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
    h('div', { class: 'blk-h' }, h('h2', { text: 'Резервные копии' }), h('a', { class: 'sm', href: '#/settings/backup', text: 'открыть' })),
    s.count ? h('p', { class: 'sm' }, h('span', { class: 'status-ok', text: '✓ На роутере: ' }), `${plural(s.count, 'снимок', 'снимка', 'снимков')}, последний ${fmtAgo(S.state.now - s.last)}`) : h('p', { class: 'sm muted', text: 'Снимков пока нет' }),
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
    out.replaceChildren(
      h('div', {}, h('h3', { text: res.host }), h('div', { class: 'sm muted', text: res.ips.length ? 'IP: ' + res.ips.slice(0, 3).join(', ') + (res.ips.length > 3 ? ` +${res.ips.length - 3}` : '') : 'IP не определился' })),
      res.podkop?.proxy ? notice('info', 'Идёт через podkop (прокси на VPS)', 'Для клиентов сети этот сайт уходит в туннель podkop, nfqws2 видит только соединение до сервера прокси. Проверка ниже — с самого роутера, мимо podkop.') : null,
      probeBox, routesBlock(res), actionsBlock(res),
      h('div', { class: 'row' }, btn('Подробный диагноз', () => go(diagHref(res.host)), 'small', 'search', { title: 'По шагам: DNS, блокировка по имени или по адресу, заморозка — и поможет ли подбор' }),
        btn('Подобрать стратегию', () => go(pickHref(res.host)), 'small', 'tests'),
        h('span', { class: 'grow' }), btn('Свернуть', () => { collapse(); input.value = ''; }, 'small ghost', 'up')));
    shown = res.host;
    const pr = await api('probe', { host: res.host }).catch((e) => ({ ok: false, reason: e.message }));
    if (my === seq) probeBox.replaceChildren(probeVerdict(res, pr));
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

function probeVerdict(res, pr) {
  const p = res.routes.https.profile ? prof(res.routes.https.profile) : null;
  if (pr.ok) return notice('ok', 'Открывается', `HTTP ${pr.code}, ${pr.ms} мс.` + (p ? ` HTTPS — профиль #${p.index}.` : ' nfqws2 его не трогает — и не нужно.'));
  if (pr.reason && pr.reason.includes('DNS')) return notice('warn', 'Не открывается: ' + pr.reason, 'Проблема DNS, а не DPI — списки nfqws2 тут не помогут.');
  if (isFreeze(pr.reason)) return notice('bad', 'Открывается частично: ' + pr.reason, FREEZE_HINT + (p ? ` Сейчас HTTPS идёт через профиль #${p.index}.` : ''));
  if (!p) return notice('bad', 'Не открывается: ' + pr.reason, 'nfqws2 его не обрабатывает. Добавьте сайт в список, который ведёт в подходящий профиль, или подберите стратегию тестом.');
  return notice('bad', 'Не открывается: ' + pr.reason, `Профиль #${p.index} (${strategyText(p)}) не помогает. Подберите стратегию тестом или исключите сайт из этого профиля.`);
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
    else control = btn(`Убрать ${has.entry}`, () => { if (confirm(`Сайт входит в список через ${has.entry}. Убрать ${has.entry} целиком? Это затронет и другие его поддомены.`)) change('list_remove', has.entry); }, 'small danger', 'x');
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
  const view = OVERVIEWS.some(([v]) => v === look.overview) ? look.overview : simple() ? 'brief' : 'groups';
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
      HREF[id] ? h('a', { class: 'sm', href: HREF[id], text: 'открыть', onclick: (e) => e.stopPropagation() }) : null), block);
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
    tile(st.running ? 'ok' : 'bad', '#/', 'Сервис', st.running ? 'Работает' : 'Остановлен', st.running && st.process?.started ? 'запущен ' + fmtAgo(st.now - st.process.started) : 'обход не работает'),
    tile(!m.length ? '' : okN === m.length ? 'ok' : 'bad', PAGES.mon[0], 'Сайты', m.length ? `${okN} из ${m.length}` : '—', m.length ? (st.monitor.last ? 'проверены ' + fmtAgo(st.now - st.monitor.last) : 'ещё не проверялись') : 'мониторинг пуст'),
    tile(problems.length ? 'bad' : 'ok', problems[0]?.href || PAGES.raw[0], 'Проблемы', String(problems.length), problems.length ? problems[0].title : 'конфиг и списки в порядке'),
    tile(bad ? 'bad' : '', PAGES.prof[0], 'Профили', String(profs.length), bad ? `${bad} с проблемами` : 'все срабатывают'),
    tile('', PAGES.lists[0], 'Списки', `${used.length} из ${st.lists.length}`, fmtNum(used.reduce((a, l) => a + l.entries, 0)) + ' записей'),
    tile('', PAGES.backup[0], 'Копии', String(st.snap?.count || 0), st.snap?.last ? 'последняя ' + fmtAgo(st.now - st.snap.last) : 'снимков нет'));
}

function overviewExtra() {
  const st = S.state;
  const blk = (title, href, ...body) => h('section', { class: 'blk' },
    h('div', { class: 'blk-h' }, h('h2', { text: title }), href ? h('a', { class: 'sm', href, text: 'открыть' }) : null), ...body);
  const kv = (k, ...v) => h('div', { class: 'kv' }, h('span', { class: 'muted', text: k }), h('span', {}, ...v));
  const profs = st.conf_profiles;
  const bad = profs.filter((p) => worst(profIssues(p)) === 'error' || ['dead', 'excludes-only'].includes(p.state)).length;
  const used = st.lists.filter((l) => l.used.length);
  const withProblems = st.lists.filter((l) => l.problems?.error || l.problems?.warning).length;
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
      h('p', { class: 'sm' }, h('a', { href: '#/settings/readme', text: 'Справка' }), ' · ', h('a', { href: '#/settings/changelog', text: 'изменения по версиям' })));
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

// ============ Диагноз блокировки ============
// Шесть ступеней на роутере (api: diag): имя → соединение → запрос мимо nfqws2 → по имени или по адресу →
// открытый HTTP → запрос через nfqws2. Итог — один диагноз и что с ним делать.

const pickHref = (host) => layout() === 'site' ? `#/site/${encodeURIComponent(host)}/pick` : '#/tests?host=' + encodeURIComponent(host);
const diagHref = (host) => layout() === 'site' ? `#/site/${encodeURIComponent(host)}/diag` : '#/diag?host=' + encodeURIComponent(host);

const DIAG_STEPS = [['dns', 'Имя сайта (DNS)'], ['tcp', 'Соединение (TCP 443)'], ['direct', 'Запрос мимо nfqws2'],
  ['sni', 'По имени или по адресу'], ['http', 'Открытый HTTP (порт 80)'], ['via', 'Как открывается сейчас']];
const DIAG_KIND = { timeout: 'нет ответа при установке шифрования', reset: 'соединение сброшено', tls: 'обрыв при установке шифрования', alert: 'сервер отказал в соединении',
  stall: 'шифрование установлено, но ответа нет', freeze: 'загрузка замирает на объёме (16–20 КБ)', connect: 'не удалось подключиться', empty: 'пустой ответ', dns: 'имя не разрешается', other: 'ошибка соединения' };

// Диагноз: уровень плашки, заголовок, пояснение
function diagVerdictText(R) {
  const v = R.verdict;
  const p = v.profile ? `профиль #${v.profile}` : null;
  const how = R.direct && !R.direct.ok ? DIAG_KIND[R.direct.kind] || 'не открывается' : 'не открывается';
  return {
    ok: ['ok', 'Сайт открывается, блокировки нет', p ? `Открывается и мимо nfqws2, и через него (${p}).` : 'nfqws2 его не обрабатывает — и не нужно.'],
    fixed: ['ok', 'Заблокирован, но nfqws2 справляется', `Мимо nfqws2: ${how}. Через ${p || 'nfqws2'} сайт открывается — менять ничего не нужно.`],
    broken_by_nfqws: ['warn', 'Сайт не заблокирован — его ломает nfqws2', `Мимо nfqws2 сайт открывается, а через ${p || 'nfqws2'} — нет. Исключите сайт из этого профиля или подберите для него другую стратегию.`],
    nxdomain: ['warn', 'Имя сайта не находится', 'Такого имени нет ни у роутера, ни у защищённого DNS. Проверьте написание — подбор стратегии тут не поможет.'],
    dns_noanswer: ['bad', 'Роутер не получает адрес сайта', 'Защищённый DNS адрес знает, а резолвер роутера — нет. Дело в DNS, а не в nfqws2: стратегии и списки тут не помогут.'],
    dns_fake: ['bad', 'Роутер получает не тот адрес (подмена DNS)', 'По адресу от резолвера роутера сайт не открывается, а по адресу от защищённого DNS — открывается. Дело в DNS, а не в nfqws2.'],
    podkop_ok: ['info', 'Сайт идёт через podkop и открывается', 'Соединение уходит в туннель, nfqws2 его не видит.' + (R.direct ? (R.direct.ok ? ' Напрямую, мимо туннеля, сайт тоже открывается.' : ' Напрямую, мимо туннеля, сайт не открывается — туннель ему нужен.') : '')],
    podkop_fail: ['bad', 'Сайт идёт через podkop и не открывается', 'Соединение уходит в туннель, nfqws2 его не видит. Проблема на стороне туннеля или самого сайта.'],
    sni_block: ['bad', 'Блокировка по имени сайта (DPI)', 'Это тот случай, с которым справляется nfqws2. ' + (p ? `Нынешний ${p} не помогает — нужна другая стратегия.` : 'Сейчас nfqws2 этот сайт не обрабатывает.')],
    tls_block: ['bad', 'Соединение рвётся при установке шифрования', 'Похоже на блокировку DPI, но подтвердить, что режут именно по имени, не удалось. Подбор стратегии стоит попробовать.'],
    ip_block: ['bad', 'Заблокирован адрес (по IP)', 'Пакеты до сервера не доходят вовсе. nfqws2 меняет пакеты, а не маршрут, поэтому подбор стратегии не поможет — нужен туннель (podkop, VPN).'],
    port_block: ['bad', 'Не отвечает порт 443', 'Открытый HTTP на этом адресе отвечает, а HTTPS — нет. Стратегии nfqws2 это не исправят — нужен туннель (podkop, VPN).'],
    freeze: ['bad', 'Ограничение «16–20 КБ»', FREEZE_HINT],
    isp_page: ['bad', 'Вместо сайта — страница провайдера о блокировке', 'Провайдер подменяет ответ сайта. Подбор стратегии может помочь.'],
    site_error: ['warn', 'Сервер сайта отказал в соединении', 'Отказ пришёл от самого сервера, а не от провайдера — похоже на неполадку сайта.'],
    unknown: ['warn', 'Причину определить не удалось', 'Сайт не открывается, но ни под один известный вид блокировки это не подходит. Можно попробовать подбор стратегии.'],
  }[v.code] || ['warn', 'Причину определить не удалось', ''];
}

async function viewDiag(main, r, bare = false) {
  const KEY = 'nfqws-ui-recent';
  const recentGet = () => { try { return JSON.parse(localStorage.getItem(KEY)) || []; } catch { return []; } };
  const input = h('input', { class: 'input mono grow', id: 'd-host', value: r.q.get('host') || '', placeholder: 'rutracker.org', autocapitalize: 'off', autocorrect: 'off', spellcheck: 'false', inputmode: 'url', enterkeyhint: 'go', 'aria-label': 'Сайт для диагноза' });
  const verdictBox = h('div', {});
  const rows = {};
  const stepsEl = h('div', { class: 'dsteps' }, DIAG_STEPS.map(([id, title]) => (rows[id] = h('div', { class: 'dstep' }, h('span', { class: 'lvl' }), h('b', { text: title }), h('span', { class: 'sm muted' })))));
  const panelEl = panel('Что проверено', null, stepsEl);
  panelEl.hidden = true;
  const ICON = { ok: () => levelIcon('ok'), bad: () => levelIcon('error'), warn: () => levelIcon('warning'), info: () => levelIcon('info'), run: () => h('span', { class: 'spin' }), skip: () => h('span', { class: 'lvl faint', text: '–' }) };
  const set = (id, state, text) => { rows[id].firstChild.replaceWith(ICON[state]()); rows[id].lastChild.textContent = text; rows[id].classList.toggle('skip', state === 'skip'); };
  let seq = 0;

  async function run(raw) {
    const my = ++seq;
    let host = raw.trim().replace(/^[a-z]+:\/\//i, '').replace(/[/?#].*$/, '');
    if (!host) return;
    input.value = host;
    panelEl.hidden = false;
    verdictBox.replaceChildren();
    DIAG_STEPS.forEach(([id]) => set(id, 'skip', 'ожидает'));
    const R = {};
    const step = async (id, data) => {
      set(id, 'run', 'проверяю…');
      const res = await api('diag', { step: id, host, ...data });
      if (my !== seq || !stepsEl.isConnected) throw new Error('cancelled');
      return res;
    };
    try {
      // 1. имя
      const d = R.dns = await step('dns');
      host = d.host;
      const names = d.doh.filter((x) => x.ok).map((x) => x.name).join(' и ') || 'защищённый DNS';
      const sys = d.system.slice(0, 3).join(', ');
      const ref = d.ref.slice(0, 3).join(', ');
      set('dns', { ok: 'ok', podkop: 'info', differs: 'warn', unverified: 'warn' }[d.status] || 'bad', {
        ok: `Адрес ${sys} — тот же, что отвечает защищённый DNS (${names}). Подмены нет.`,
        nxdomain: d.doh_answered ? 'Такого имени нет — ни у роутера, ни у защищённого DNS.' : 'Роутер адрес не нашёл, а защищённый DNS недоступен — сверить не с чем.',
        no_system: `Роутер адрес не получил, а защищённый DNS отвечает: ${ref}.`,
        podkop: `Роутер отдаёт служебный адрес podkop (${sys}): сайт уходит в туннель. Настоящий адрес — ${ref}.`,
        fake: `Роутер получил ${sys} — это не адрес сайта. Защищённый DNS отвечает: ${ref}.`,
        differs: `Роутер получил ${sys}, защищённый DNS — ${ref}. У больших сетей ответы различаются; решит запрос к сайту.`,
        unverified: `Адрес ${sys}. Сверить не с чем: защищённый DNS ${d.doh_answered ? 'такого имени не знает' : 'недоступен'}.`,
      }[d.status]);
      if (d.status !== 'nxdomain') {
        // 2. соединение
        const tcp = R.tcp = (await step('tcp', { ips: d.ips })).results;
        const up = tcp.filter((x) => x.ok);
        const ip = up[0]?.ip;
        set('tcp', !up.length ? 'bad' : up.length < tcp.length ? 'warn' : 'ok', !up.length ? `Нет ответа ни от одного из ${plural(tcp.length, 'адреса', 'адресов', 'адресов')} — пакеты не доходят.`
          : `Установлено за ${up[0].ms} мс` + (up.length < tcp.length ? `; не отвечают: ${tcp.filter((x) => !x.ok).map((x) => x.ip).join(', ')}.` : ' — адрес не заблокирован.'));
        // 3. запрос мимо nfqws2
        if (ip) {
          const dr = R.direct = await step('direct', { ip });
          if (!dr.ok && d.alt.length) {
            const alt = await step('direct', { ip: d.alt[0] });
            if (alt.ok) R.alt_ok = true;
          }
          set('direct', dr.isp_page ? 'bad' : dr.ok ? 'ok' : 'bad', dr.isp_page ? 'Вместо сайта пришла страница провайдера о блокировке.'
            : dr.ok ? `Сайт отвечает: HTTP ${dr.code}, ${dr.ms} мс.` : `Не открывается: ${DIAG_KIND[dr.kind] || dr.error}.` + (R.alt_ok ? ' По адресу от защищённого DNS — открывается.' : ''));
          // 4. по имени или по адресу
          if (!dr.ok && !dr.isp_page && ['timeout', 'reset', 'tls', 'alert', 'stall', 'empty'].includes(dr.kind) && !R.alt_ok) {
            const s = R.sni = await step('sni', { ip });
            set('sni', s.by_name ? 'ok' : 'warn', s.foreign_addr?.blocked ? 'Имя сайта рвётся даже на постороннем адресе — режут именно по имени.'
              : s.foreign_name?.reached ? 'С именем ya.ru этот же адрес отвечает — значит, режут именно по имени сайта.'
                : 'Не показательно: с чужим именем этот адрес тоже не отвечает.');
          } else set('sni', 'skip', dr.ok ? 'Не нужно: сайт отвечает.' : 'Не проверялось.');
        } else {
          set('direct', 'skip', 'Не проверялось: соединения нет.');
          set('sni', 'skip', 'Не проверялось: соединения нет.');
        }
        // 5. открытый HTTP — когда HTTPS не отвечает
        if (!ip || (R.direct && !R.direct.ok && R.direct.kind !== 'freeze')) {
          const ht = R.http = await step('http', { ip: ip || d.ips[0] });
          set('http', ht.isp_page ? 'bad' : ht.ok ? 'ok' : 'warn', ht.isp_page ? 'Отвечает страница провайдера о блокировке.'
            : ht.ok ? `Отвечает: HTTP ${ht.code}` + (ht.redirect ? ` → ${ht.redirect.slice(0, 60)}` : '') + '.' : `Тоже не отвечает (${ht.kind === 'timeout' || ht.kind === 'stall' ? 'нет ответа' : DIAG_KIND[ht.kind] || 'ошибка'}).`);
        } else set('http', 'skip', 'Не нужно.');
        // 6. как сейчас
        const via = R.via = await step('via');
        const where = d.podkop ? 'через podkop' : via.profile ? `через профиль #${via.profile}` : 'nfqws2 его не обрабатывает';
        set('via', via.ok ? 'ok' : 'bad', (via.ok ? `Открывается: HTTP ${via.code}, ${via.ms} мс — ${where}.` : `Не открывается: ${via.reason} — ${where}.`) + (via.running ? '' : ' nfqws2 сейчас остановлен.'));
      } else {
        DIAG_STEPS.slice(1).forEach(([id]) => set(id, 'skip', 'Не проверялось.'));
      }
      R.verdict = await api('diag', { step: 'verdict', host, r: R });
      if (my !== seq) return;
      const [lvl, title, text] = diagVerdictText(R);
      const prof = R.verdict.profile;
      verdictBox.replaceChildren(notice(lvl, title, text, h('div', { class: 'notice-actions' },
        R.verdict.pick ? btn('Подобрать стратегию', () => go(pickHref(host)), 'small primary', 'tests') : null,
        prof && ['broken_by_nfqws', 'sni_block', 'tls_block', 'fixed'].includes(R.verdict.code) ? h('a', { class: 'btn small', href: `#/settings/p${prof}`, text: `Профиль #${prof}` }) : null,
        btn('Ещё раз', () => run(host), 'small ghost', 'refresh'))));
      try { localStorage.setItem(KEY, JSON.stringify([host, ...recentGet().filter((x) => x !== host)].slice(0, 8))); } catch { /* нет хранилища */ }
    } catch (e) {
      if (e.message === 'cancelled' || my !== seq) return;
      DIAG_STEPS.forEach(([id]) => { if (rows[id].querySelector('.spin')) set(id, 'bad', 'не удалось проверить'); });
      verdictBox.replaceChildren(notice('bad', 'Диагноз не завершён', e.message));
    }
  }

  const start = () => {
    const v = input.value.trim();
    if (!v) return;
    if (bare || r.q.get('host') === v) run(v); else go(diagHref(v.replace(/^[a-z]+:\/\//i, '').replace(/[/?#].*$/, '')));
  };
  main.append(
    bare ? null : h('div', { class: 'vh' }, h('h1', { text: 'Диагноз блокировки' })),
    panel(null, null,
      h('form', { class: 'row', onsubmit: (e) => { e.preventDefault(); start(); } }, input, h('button', { class: 'btn primary', type: 'submit' }, 'Проверить', icon('arrow'))),
      bare ? null : h('div', { class: 'recent' }, recentGet().map((x) => h('button', { class: 'chip', type: 'button', text: x, onclick: () => go(diagHref(x)) }))),
      h('p', { class: 'sm muted', text: 'Роутер по шагам выясняет, почему сайт не открывается: DNS, блокировка по имени или по адресу, заморозка — и поможет ли подбор стратегии. Занимает 10–30 секунд. Ваш трафик и основной nfqws2 не затрагиваются.' })),
    verdictBox, panelEl);
  if (r.q.get('host')) run(r.q.get('host'));
}

// ============ Карточка сайта: проверка, подбор и трассировка одного сайта (компоновка «Вокруг сайта») ============

async function viewSite(main, r) {
  const host = r.arg;
  if (!host) { go(PAGES.mon[0]); return; }
  const sub = ['diag', 'pick', 'trace', 'hist'].includes(r.sub) ? r.sub : 'check';
  const href = (s) => '#/site/' + encodeURIComponent(host) + (s === 'check' ? '' : '/' + s);
  main.append(
    h('div', { class: 'vh' }, h('a', { class: 'btn ghost small', href: PAGES.mon[0] }, icon('back'), 'Сайты'), h('h1', { class: 'site-name', text: host })),
    h('nav', { class: 'subtabs', 'aria-label': 'Сайт' },
      [['check', 'Проверка'], ['diag', 'Диагноз'], ['pick', 'Подбор стратегии'], ['trace', 'Трассировка'], ['hist', 'История подборов']].map(([s, t]) => h('a', { href: href(s), class: s === sub ? 'on' : null, text: t }))));
  if (sub === 'hist') return viewPickHist(main, { q: new URLSearchParams({ host }) }, true);
  if (sub === 'diag') return viewDiag(main, { q: new URLSearchParams({ host }) }, true);
  if (sub !== 'check') return viewTests(main, { arg: sub, q: new URLSearchParams({ host }) }, true);
  if (overviewCol()) main.append(h('p', { class: 'muted', text: 'Результат проверки — в колонке слева.' }));
  else main.append(S.check.el);
  if (S.check.shown() !== host) S.check.run(host);
}

// ============ редактор с подсветкой ============

function argPieces(tok) {
  if (tok === '--new') return [[tok, 'tk-new']];
  if (tok[0] === '#') return [[tok, 'tk-comment']];
  if (!tok.startsWith('--')) return [[tok, 'tk-bad']];
  const eq = tok.indexOf('=');
  if (eq < 0) return [[tok, 'tk-opt']];
  const name = tok.slice(0, eq);
  const val = tok.slice(eq + 1);
  const out = [[name, 'tk-opt'], ['=', 'tk-p']];
  if (name === '--lua-desync') {
    const parts = val.split(':');
    out.push([parts[0], 'tk-fn']);
    for (const p of parts.slice(1)) {
      out.push([':', 'tk-p']);
      const e = p.indexOf('=');
      if (e < 0) out.push([p, 'tk-key']);
      else out.push([p.slice(0, e), 'tk-key'], ['=', 'tk-p'], [p.slice(e + 1), 'tk-val']);
    }
  } else {
    out.push([val, 'tk-val']);
  }
  return out;
}

function tokenize(text) {
  const out = [];
  for (const m of text.matchAll(/\S+/g)) out.push({ text: m[0], start: m.index, end: m.index + m[0].length });
  return out;
}

// Добавляет в frag текст, подсвечивая совпадения поиска
function pushFind(frag, text, cls, find) {
  if (!text) return;
  if (find && text.toLowerCase().includes(find)) {
    const low = text.toLowerCase();
    let pos = 0;
    for (let i = low.indexOf(find); i >= 0; i = low.indexOf(find, pos)) {
      if (i > pos) frag.append(cls ? h('span', { class: cls, text: text.slice(pos, i) }) : text.slice(pos, i));
      frag.append(h('span', { class: 'hl-find' + (cls ? ' ' + cls : ''), text: text.slice(i, i + find.length) }));
      pos = i + find.length;
    }
    if (pos < text.length) frag.append(cls ? h('span', { class: cls, text: text.slice(pos) }) : text.slice(pos));
  } else {
    frag.append(cls ? h('span', { class: cls, text }) : text);
  }
}

function appendArgs(frag, text, issuesByTok, find) {
  let idx = 0;
  for (const m of text.matchAll(/\s+|\S+/g)) {
    const s = m[0];
    if (/^\s/.test(s)) { frag.append(s); continue; }
    const lvl = worst(issuesByTok?.[idx]);
    const wrap = lvl ? h('span', { class: 'iss ' + lvl }) : frag;
    const q1 = s[0] === '"' ? '"' : '';
    const q2 = s.length > 1 && s.endsWith('"') ? '"' : '';
    const core = s.slice(q1.length, s.length - q2.length);
    if (q1) pushFind(wrap, q1, 'tk-str', find);
    for (const [t, c] of argPieces(core)) if (t) pushFind(wrap, t, c, find);
    if (q2) pushFind(wrap, q2, 'tk-str', find);
    if (lvl) frag.append(wrap);
    idx++;
  }
}

function confVarRanges(text) {
  const out = {};
  for (const m of text.matchAll(/^([A-Z_][A-Z0-9_]*)="([^"]*)"/gm)) {
    out[m[1]] = { start: m.index + m[1].length + 2, end: m.index + m[1].length + 2 + m[2].length, line: text.slice(0, m.index).split('\n').length };
  }
  return out;
}

function paintConf(frag, text, issues, find) {
  const ranges = Object.entries(confVarRanges(text)).filter(([n]) => ARG_VARS.includes(n)).sort((a, b) => a[1].start - b[1].start);
  let pos = 0;
  const plain = (s) => {
    s.split(/(\n)/).forEach((line) => {
      if (line === '\n') { frag.append(line); return; }
      const cm = line.match(/^(\s*)(#.*)$/);
      const vm = line.match(/^([A-Z_][A-Z0-9_]*)(=)(.*)$/);
      if (cm) { frag.append(cm[1]); pushFind(frag, cm[2], 'tk-comment', find); }
      else if (vm) { pushFind(frag, vm[1], 'tk-var', find); pushFind(frag, '=', 'tk-p', find); appendArgs(frag, vm[3], null, find); }
      else appendArgs(frag, line, null, find);
    });
  };
  for (const [name, r] of ranges) {
    plain(text.slice(pos, r.start));
    const byTok = {};
    for (const x of issues) if (x.var === name && x.tok != null) (byTok[x.tok] ||= []).push(x);
    appendArgs(frag, text.slice(r.start, r.end), byTok, find);
    pos = r.end;
  }
  plain(text.slice(pos));
}

// Текстовое поле с подсвеченной подложкой. mode: 'args' | 'conf' | 'list'
function codeEditor({ value, mode, onInput, onCaret, rows = 4, maxHeight = 520, gutter = false, suggest = null }) {
  const hl = h('pre', { class: 'ed-hl', 'aria-hidden': 'true' });
  const ta = h('textarea', { class: 'ed-ta', spellcheck: 'false', autocapitalize: 'off', autocomplete: 'off', wrap: 'off', rows, value });
  const gut = gutter ? h('div', { class: 'ed-gut', 'aria-hidden': 'true' }) : null;
  const el = h('div', { class: 'ed' + (gutter ? ' gutter-on' : '') }, hl, ta, gut);
  let issues = [];
  let find = '';
  const fit = () => {
    if (!maxHeight) return;
    ta.style.height = 'auto';
    ta.style.height = Math.min(ta.scrollHeight + 2, maxHeight) + 'px';
  };
  const sync = () => { hl.scrollTop = ta.scrollTop; hl.scrollLeft = ta.scrollLeft; if (gut) gut.scrollTop = ta.scrollTop; };
  function lineMarks() {
    const marks = {};
    if (mode === 'list') { for (const x of issues) marks[x.line] = worst([x, ...(marks[x.line] ? [{ level: marks[x.line] }] : [])]); return marks; }
    const text = ta.value;
    if (mode === 'args') {
      const toks = tokenize(text);
      for (const x of issues) if (x.tok != null && toks[x.tok]) { const ln = text.slice(0, toks[x.tok].start).split('\n').length - 1; marks[ln] = worst([x, ...(marks[ln] ? [{ level: marks[ln] }] : [])]); }
    } else {
      const ranges = confVarRanges(text);
      for (const x of issues) {
        const r = ranges[x.var];
        if (!r) continue;
        const t = x.tok != null ? tokenize(text.slice(r.start, r.end))[x.tok] : null;
        const ln = text.slice(0, t ? r.start + t.start : r.start).split('\n').length - 1;
        marks[ln] = worst([x, ...(marks[ln] ? [{ level: marks[ln] }] : [])]);
      }
    }
    return marks;
  }
  function paint() {
    const text = ta.value;
    const frag = document.createDocumentFragment();
    if (mode === 'args') {
      const byTok = {};
      for (const x of issues) if (x.tok != null) (byTok[x.tok] ||= []).push(x);
      appendArgs(frag, text, byTok, find);
    } else if (mode === 'list') {
      const byLine = {};
      for (const x of issues) (byLine[x.line] ||= []).push(x);
      text.split('\n').forEach((line, i, all) => {
        const lvl = worst(byLine[i]);
        const cls = /^\s*#/.test(line) ? 'tk-comment' : '';
        if (lvl) { const w = h('span', { class: 'iss ' + lvl }); pushFind(w, line, cls, find); frag.append(w); } else pushFind(frag, line, cls, find);
        if (i < all.length - 1) frag.append('\n');
      });
    } else {
      paintConf(frag, text, issues, find);
    }
    frag.append('\n ');
    hl.replaceChildren(frag);
    if (gut) {
      const marks = lineMarks();
      const n = text.split('\n').length;
      const lines = [];
      for (let i = 0; i < n; i++) lines.push(h('div', { class: marks[i] === 'error' ? 'e' : marks[i] === 'warning' ? 'w' : null, text: String(i + 1) }));
      lines.push(h('div', { text: ' ' }));
      gut.replaceChildren(...lines);
    }
    sync();
  }
  const caret = () => { onCaret?.(ta.selectionStart, ta.value); suggest?.update(ta); };
  ta.addEventListener('input', () => { paint(); fit(); onInput?.(ta.value); caret(); });
  ta.addEventListener('scroll', sync);
  ['keyup', 'click', 'focus'].forEach((ev) => ta.addEventListener(ev, caret));
  ta.addEventListener('keydown', (e) => {
    if (e.key === 'Tab' && suggest?.accept(ta)) { e.preventDefault(); paint(); onInput?.(ta.value); }
  });
  requestAnimationFrame(() => { paint(); fit(); });
  return {
    el, ta,
    setIssues(list) { issues = list || []; paint(); },
    setFind(f) { find = (f || '').toLowerCase(); paint(); },
    setValue(v) { ta.value = v; paint(); fit(); },
    select(start, end) { ta.focus({ preventScroll: true }); ta.setSelectionRange(start, end); const ln = ta.value.slice(0, start).split('\n').length; ta.scrollTop = Math.max(0, (ln - 5) * 20.6); sync(); el.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); caret(); },
    repaint: paint,
  };
}

// ---------- подсказки ввода (Tab — принять первую) ----------

function createSuggest() {
  const box = h('div', { class: 'suggest', 'aria-live': 'polite' });
  let current = null;
  function context(ta) {
    const pos = ta.selectionStart;
    const text = ta.value;
    let s = pos;
    while (s > 0 && !/\s/.test(text[s - 1])) s--;
    const word = text.slice(s, pos);
    return { pos, s, word };
  }
  function candidates(word) {
    const cat = S.catalog;
    if (!cat) return null;
    const lists = S.state.lists.filter((l) => l.exists).map((l) => l.path);
    const blobs = declaredBlobs().concat(cat.lua.globals.filter((g) => /^(fake_|tls_|quic_)/.test(g)));
    if (word.startsWith('--') && !word.includes('=')) {
      const p = word.slice(2);
      return { prefix: word, items: Object.keys(cat.help.options).filter((o) => o.startsWith(p)).map((o) => '--' + o + (cat.help.options[o].value ? '=' : '')) };
    }
    let m = word.match(/^(--filter-l7=(?:[^,]*,)*)([^,]*)$/);
    if (m) return { prefix: word, items: cat.help.l7.filter((x) => x.startsWith(m[2])).map((x) => m[1] + x) };
    m = word.match(/^(--payload=(?:[^,]*,)*)([^,]*)$/);
    if (m) return { prefix: word, items: cat.help.payloads.filter((x) => x.startsWith(m[2])).map((x) => m[1] + x) };
    m = word.match(/^(--(?:hostlist|hostlist-exclude|ipset|ipset-exclude)=)(.*)$/);
    if (m) return { prefix: word, items: lists.filter((x) => x.startsWith(m[2]) || base(x).startsWith(m[2])).map((x) => m[1] + x) };
    m = word.match(/^--lua-desync=([^:]*)$/);
    if (m) return { prefix: word, items: Object.keys(cat.lua.functions).filter((f) => f.startsWith(m[1])).map((f) => '--lua-desync=' + f) };
    m = word.match(/^(--lua-desync=([^:]+)(?::[^:]*)*:)([^:=]*)$/);
    if (m) {
      const keys = allowedKeys(m[2]).map((x) => x.key);
      return { prefix: word, items: keys.filter((k) => k.startsWith(m[3])).map((k) => m[1] + k + (keyNeedsValue(m[2], k) ? '=' : '')) };
    }
    m = word.match(/^(.*:(?:blob|seqovl_pattern|pattern)=)([^:]*)$/);
    if (m) return { prefix: word, items: blobs.filter((b) => b.startsWith(m[2])).map((b) => m[1] + b) };
    return null;
  }
  return {
    el: box,
    update(ta) {
      const ctx = context(ta);
      const c = ctx.word ? candidates(ctx.word) : null;
      const items = c ? c.items.filter((x) => x !== ctx.word).slice(0, 12) : [];
      current = items.length ? { ctx, items } : null;
      box.replaceChildren(items.length ? [h('span', { class: 'muted', text: 'Подсказки:' }), items.map((it) => h('button', { type: 'button', text: it.replace(/^.*[=:,](?=[^=:,]*=?$)/, '') || it, title: it, onmousedown: (e) => { e.preventDefault(); insert(ta, ctx, it); } })), h('kbd', { text: 'Tab' })] : []);
    },
    accept(ta) {
      if (!current) return false;
      insert(ta, current.ctx, current.items[0]);
      return true;
    },
  };
  function insert(ta, ctx, text) {
    ta.setRangeText(text, ctx.s, ctx.pos, 'end');
    ta.dispatchEvent(new Event('input'));
    ta.focus();
  }
}

// Блобы, которые nfqws2 объявляет сам (как BUILTIN_BLOBS в api.php)
const BUILTIN_BLOBS = ['fake_default_tls', 'fake_default_http', 'fake_default_quic'];

// Блобы, которые можно подставить в blob=…: объявленные в параметрах запуска и встроенные
function declaredBlobs() {
  const own = tokensOf(S.conf?.vars?.NFQWS_BASE_ARGS).map((t) => (t.match(/^--blob=([^:]+):/) || [])[1]).filter(Boolean);
  return [...new Set([...own, ...BUILTIN_BLOBS])];
}

// Справка по аргументу под курсором
function helpFor(tokText, offset) {
  const cat = S.catalog;
  if (!cat || !tokText) return null;
  if (tokText[0] === '#') return { title: 'Комментарий', lines: ['Внутри кавычек «#» не комментарий: строка уйдёт в nfqws2 как аргумент. Пишите комментарии над переменной.'] };
  const eq = tokText.indexOf('=');
  const name = (eq < 0 ? tokText : tokText.slice(0, eq)).replace(/^--/, '');
  if (name === 'lua-desync' && eq >= 0) {
    const val = tokText.slice(eq + 1);
    const inVal = offset - eq - 1;
    const parts = val.split(':');
    let acc = 0, part = 0;
    for (; part < parts.length; part++) { if (inVal <= acc + parts[part].length) break; acc += parts[part].length + 1; }
    if (part > 0 && part < parts.length) return keyHelp(parts[0], parts[part].split('=')[0]);
    return fnHelp(parts[0]);
  }
  const o = cat.help.options[name];
  if (!o) return { title: '--' + name, lines: ['Нет такого параметра в справке nfqws2.'] };
  const lines = [o.desc || 'Без описания'];
  if (name === 'filter-l7') lines.push('Протоколы: ' + cat.help.l7.join(', '));
  if (name === 'payload' || name === 'payload-disable') lines.push('Типы: ' + cat.help.payloads.join(', '));
  return { title: o.syntax, sub: o.global ? 'общий параметр процесса' : 'параметр профиля', lines };
}
function fnHelp(fnName) {
  const fn = S.catalog?.lua.functions[fnName];
  if (!fn) return { title: fnName, lines: ['Такой функции нет в lua-скриптах.'] };
  const lines = [...fn.doc];
  if (fn.nfqws1) lines.unshift('Аналог в nfqws1: ' + fn.nfqws1);
  if (fn.required.length) lines.push('Обязательно: ' + fn.required.join(', '));
  const own = Object.values(fn.args);
  return { title: `lua-функция ${fnName}`, sub: fn.file, lines, list: own.length ? ['Параметры:', own] : null,
    std: fn.std.length ? fn.std.map((g) => `${g}: ${Object.keys(S.catalog.lua.std[g] || {}).join(', ')}`) : null };
}
function keyHelp(fnName, key) {
  const k = allowedKeys(fnName).find((x) => x.key === key);
  return { title: `${fnName} : ${key}`, sub: k ? k.src : 'не описан для этой функции', lines: [k ? k.desc : 'Нет в документации этой функции — возможно, параметр не действует.'] };
}
function allowedKeys(fnName) {
  const cat = S.catalog;
  const fn = cat?.lua.functions[fnName];
  if (!fn) return [];
  const out = [];
  const seen = new Set();
  const add = (key, desc, src) => { if (!seen.has(key)) { seen.add(key); out.push({ key, desc, src }); } };
  for (const [k, d] of Object.entries(fn.args)) add(k, d, 'параметр функции');
  for (const g of fn.std) for (const [k, d] of Object.entries(cat.lua.std[g] || {})) add(k, d, 'стандартный, ' + g);
  for (const k of fn.file_keys) add(k, k, 'параметр оркестратора/детектора');
  add('strategy', 'strategy=N — номер стратегии для circular (с 1 без пропусков)', 'оркестратор');
  return out;
}
function keyNeedsValue(fnName, key) {
  const k = allowedKeys(fnName).find((x) => x.key === key);
  if (!k) return true;
  return new RegExp('^' + key + '=').test(k.desc);
}

function renderHelp(box, info, tokIssues) {
  if (!info && !tokIssues?.length) { box.replaceChildren(h('span', { class: 'sm muted', text: 'Поставьте курсор на аргумент или шаг — здесь появится справка.' })); return; }
  box.replaceChildren(
    (tokIssues || []).map((x) => h('div', { class: 'help-iss ' + x.level }, levelIcon(x.level), h('span', { text: x.msg }))),
    info ? h('div', { class: 'help-t' }, h('code', { text: info.title }), info.sub ? h('span', { class: 'sm muted', text: ' · ' + info.sub }) : null) : null,
    info ? info.lines.map((l) => h('div', { text: l })) : null,
    info?.list ? h('div', {}, h('b', { text: info.list[0] }), h('ul', { class: 'mono' }, info.list[1].map((x) => h('li', { text: x })))) : null,
    info?.std ? h('details', {}, h('summary', { text: 'стандартные параметры' }), h('ul', { class: 'mono sm' }, info.std.map((x) => h('li', { text: x })))) : null);
}

function issuesListEl(issues, onPick, lineOf, fixFor = fixButton) {
  if (!issues.length) return null;
  return h('ul', { class: 'iss-list' }, issues.map((x) => h('li', {},
    h('div', { class: 'row', style: 'flex-wrap:nowrap;align-items:flex-start' },
      h('button', { type: 'button', class: 'iss-item', onclick: () => onPick?.(x) }, levelIcon(x.level),
        h('span', {}, lineOf ? h('span', { class: 'muted', text: lineOf(x) + ': ' }) : null, x.msg)),
      fixFor(x)))));
}

// ============ Сайты: менеджер списков ============

async function viewSites(main, r) {
  if (r.arg === '~dups') {
    const nav = h('nav', { class: 'nav lnav', 'aria-label': 'Списки' });
    const content = h('div', { class: 'stack', style: 'gap:14px' });
    main.append(h('div', { class: 'vh' }, h('h1', { text: 'Списки' })), h('div', { class: 'split lists' }, nav, content));
    drawListNav(nav, '~dups');
    return viewDups(content, r);
  }
  const lists = S.state.lists;
  const name = r.arg || (lists.find((l) => l.name === 'user.list') ? 'user.list' : lists[0]?.name);
  const nav = h('nav', { class: 'nav lnav', 'aria-label': 'Списки' });
  const content = h('div', { class: 'stack', style: 'gap:14px' });
  main.append(h('div', { class: 'vh' }, h('h1', { text: 'Списки' })), h('div', { class: 'split lists' }, nav, content));
  drawListNav(nav, name);
  if (name) await drawList(content, name);
}

// Колонка списков: два раздела — сайты и IP; в каждом сначала подключённые, неподключённые свёрнуты.
// Что свёрнуто — запоминается в браузере (nfqws-ui-lnav)
function drawListNav(nav, current) {
  const KEY = 'nfqws-ui-lnav';
  let fold = {};
  try { fold = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch { /* нет хранилища */ }
  const setFold = (k, v) => { fold[k] = v; try { localStorage.setItem(KEY, JSON.stringify(fold)); } catch { /* нет хранилища */ } };
  const row = (l) => {
    const pr = l.problems || {};
    const nice = LIST_NAMES[l.name];
    const file = l.name.replace(/\.list$/, '');
    const role = !l.used.length ? '' : l.used.every((u) => u.role === 'exclude') ? ' exc' : ' inc';
    const tip = l.name + (l.used.length ? '\n' + l.used.map((u) => `${u.role === 'exclude' ? 'исключает из' : 'читает'} профиль #${u.profile}`).join('\n') : '\nне подключён ни к одному профилю');
    return h('a', { href: '#/sites/' + encodeURIComponent(l.name), class: 'lrow' + (l.used.length ? '' : ' off') + (l.name === current ? ' on' : ''), title: tip },
      h('span', { class: 'bar' + role }),
      h('span', { class: 'nm' }, nice ? [h('span', { class: 't', text: nice }), h('i', { class: 'mono', text: file })] : h('span', { class: 't mono', text: file })),
      h('span', { class: 'tail num' },
        l.used.map((u) => h('span', { class: 'pchip' + (u.role === 'exclude' ? ' exc' : ''), text: '#' + u.profile })),
        pr.error ? h('span', { class: 'errdot' }) : pr.warning ? h('span', { class: 'warndot' }) : null, fmtNum(l.entries)));
  };
  const section = (kind, title) => {
    const all = S.state.lists.filter((l) => l.kind === kind);
    if (!all.length) return null;
    const used = all.filter((l) => l.used.length);
    const off = all.filter((l) => !l.used.length);
    const total = all.reduce((a, l) => a + (l.entries || 0), 0);
    // неподключённые раскрыты, если открыт один из них или подключённых в разделе нет
    const offOpen = !used.length || off.some((l) => l.name === current) || fold['off-' + kind] === true;
    const closed = fold[kind] === true && !all.some((l) => l.name === current);
    const box = h('div', { class: 'lsec' + (closed ? ' closed' : '') });
    const offBox = h('div', { class: 'lsec-off', hidden: !offOpen }, off.map(row));
    const offBtn = off.length && used.length ? h('button', { type: 'button', class: 'lsub', 'aria-expanded': String(offOpen), onclick: () => {
      offBox.hidden = !offBox.hidden;
      offBtn.setAttribute('aria-expanded', String(!offBox.hidden));
      setFold('off-' + kind, !offBox.hidden);
    } }, h('span', { class: 'ar', text: '▾' }), `не подключены — ${off.length}`) : null;
    box.append(
      h('button', { type: 'button', class: 'lsec-h', 'aria-expanded': String(!closed), onclick: (e) => {
        const c = box.classList.toggle('closed');
        e.currentTarget.setAttribute('aria-expanded', String(!c));
        setFold(kind, c);
      } }, h('span', { class: 'ar', text: '▾' }), h('b', { text: title }), h('span', { class: 'cnt num', text: `${plural(all.length, 'список', 'списка', 'списков')} · ${fmtNum(total)}` })),
      h('div', { class: 'lsec-b' }, used.map(row), offBtn, offBox));
    return box;
  };
  const act = (ic, label, onclick) => h('button', { class: 'ni', type: 'button', onclick }, h('span', { class: 'sq', text: ic }), h('span', { class: 'nm muted', text: label }));
  const file = h('input', { type: 'file', accept: '.list,.txt,text/plain', hidden: true, onchange: (e) => importListFile(e.target.files[0]) });
  nav.replaceChildren(
    section('host', 'Списки сайтов'), section('ip', 'IP-списки'),
    h('div', { class: 'lnav-acts' },
      act('+', 'Новый список', () => createList()),
      act('⇩', 'Загрузить по ссылке', importListUrl),
      act('⇧', 'Импорт из файла', () => file.click()), file,
      h('a', { href: '#/sites/~dups', class: current === '~dups' ? 'on' : null }, h('span', { class: 'sq', text: '≡' }), h('span', { class: 'nm', text: 'Дубликаты и конфликты' })),
      h('a', { href: PAGES.asn[0] }, h('span', { class: 'sq', text: '#' }), h('span', { class: 'nm', text: 'Собрать по ASN' }))));
}

async function createList(initial = '') {
  const name = prompt('Имя нового списка (латиница, цифры, _ и -):', initial);
  if (!name) return null;
  const r = await guarded(() => api('list_create', { name: name.trim() }));
  if (!r) return null;
  await loadState();
  if (!initial) go('#/sites/' + encodeURIComponent(r.name));
  return r.name;
}

async function importListFile(file) {
  if (!file) return;
  if (file.size > 5 * 1048576) { toast('Файл больше 5 МБ', { err: true }); return; }
  const text = await file.text();
  const name = await createList(file.name.replace(/\.(list|txt)$/i, '').replace(/[^A-Za-z0-9_-]/g, '_'));
  if (!name) return;
  if (await guarded(() => api('list_save', { name, content: text, note: 'импорт из файла ' + file.name }), 'Список импортирован')) {
    await loadState();
    go('#/sites/' + encodeURIComponent(name));
  }
}

// Одинаковые записи в нескольких списках, конфликты «включено и исключено», записи, покрытые доменом из другого списка
async function viewDups(content, r) {
  content.replaceChildren(spinner('Ищу повторы по всем спискам…'));
  const data = await api('dup_scan');
  const onlyUsed = r.q.get('all') !== '1';
  const items = data.items.filter((x) => !onlyUsed || (x.kind === 'covered' ? x.used >= 2 : x.used >= 2 || x.kind === 'conflict'));
  const kinds = {
    conflict: ['Конфликт', 'bad', 'Сайт одновременно в списке для обхода и в исключениях: исключение побеждает, обход для него не работает.'],
    dup: ['Повтор', 'warn', 'Одна и та же запись в нескольких списках.'],
    covered: ['Лишняя', '', 'Запись уже покрыта доменом из другого списка — поддомены учитываются автоматически.'],
  };
  const counts = { conflict: 0, dup: 0, covered: 0 };
  items.forEach((x) => counts[x.kind]++);
  const remove = async (list, entry) => {
    if (!await guarded(() => api('list_remove', { name: list, items: [entry] }))) return;
    toast(`Убрано из ${listName(list)}: ${entry}`, { undo: () => undoLast(false) });
    await loadState().catch(() => {});
    viewDups(content, r);
  };
  const role = (ln) => {
    const m = data.lists[ln];
    if (!m?.used) return 'не используется';
    return m.roles.includes('exclude') ? 'исключения' : 'обход';
  };
  const listChip = (ln, entry) => h('span', { class: 'chip ' + (data.lists[ln]?.roles.includes('exclude') ? 'warn' : data.lists[ln]?.used ? 'accent' : '') },
    h('a', { href: '#/sites/' + encodeURIComponent(ln), style: 'color:inherit', text: `${listName(ln)} · ${role(ln)}` }),
    h('button', { class: 'x', type: 'button', text: '×', title: `Убрать «${entry}» из ${ln}`, 'aria-label': `Убрать из ${ln}`, onclick: () => remove(ln, entry) }));
  // Массовое действие: убрать лишние записи (покрытые доменом из другого списка) разом по каждому списку
  const coveredByList = {};
  for (const x of items) if (x.kind === 'covered') for (const [ln, entry] of Object.entries(x.where)) (coveredByList[ln] ||= []).push(entry);
  content.replaceChildren(
    h('div', { class: 'vh' }, h('h1', { text: 'Дубликаты и конфликты' }),
      counts.conflict ? chip(plural(counts.conflict, 'конфликт', 'конфликта', 'конфликтов'), 'bad') : null,
      counts.dup ? chip(plural(counts.dup, 'повтор', 'повтора', 'повторов'), 'warn') : null,
      counts.covered ? chip(plural(counts.covered, 'лишняя', 'лишние', 'лишних')) : null,
      h('span', { class: 'grow' }),
      h('label', { class: 'row sm' }, h('input', { type: 'checkbox', checked: !onlyUsed, onchange: (e) => go('#/sites/~dups' + (e.target.checked ? '?all=1' : '')) }), 'включая неиспользуемые списки')),
    h('p', { class: 'sm muted', text: 'Поиск по всем спискам сайтов. Повторы внутри одного списка показывает сам список («Исправить автоматически»). Каждое удаление можно отменить.' }),
    Object.keys(coveredByList).length ? h('div', { class: 'row' }, h('span', { class: 'sm muted', text: 'Лишние записи:' }),
      Object.entries(coveredByList).map(([ln, entries]) => btn(`убрать ${entries.length} из ${listName(ln)}`, async () => {
        if (!confirm(`Убрать из ${ln} ${plural(entries.length, 'запись', 'записи', 'записей')}, которые уже покрыты доменами из других списков?`)) return;
        if (!await guarded(() => api('list_remove', { name: ln, items: entries }))) return;
        toast(`Убрано из ${listName(ln)}: ${entries.length}`, { undo: () => undoLast(false) });
        await loadState().catch(() => {});
        viewDups(content, r);
      }, 'small', 'wand'))) : null,
    items.length ? panel(null, null, h('div', { class: 'scroll' }, h('table', { class: 'tbl' },
      h('thead', {}, h('tr', {}, h('th', { text: 'Сайт' }), h('th', { text: 'Что' }), h('th', { text: 'Где (× — убрать оттуда)' }))),
      h('tbody', {}, items.slice(0, 400).map((x) => h('tr', {},
        h('td', {}, h('button', { class: 'btn ghost small mono', type: 'button', text: x.domain, title: 'Проверить сайт', onclick: () => checkHost(x.domain) })),
        h('td', {}, chip(kinds[x.kind][0], kinds[x.kind][1]), h('div', { class: 'sm muted', text: x.kind === 'covered' ? `покрыт «${x.parent}» из ${x.parent_in.map(listName).join(', ')}` : kinds[x.kind][2] })),
        h('td', {}, h('div', { class: 'chips' }, Object.entries(x.where).map(([ln, entry]) => listChip(ln, entry))))))))),
      items.length > 400 ? h('p', { class: 'sm muted', text: `Показаны первые 400 из ${items.length}.` }) : null)
      : notice('ok', 'Повторов и конфликтов нет', onlyUsed ? 'Среди используемых списков всё чисто.' : 'Во всех списках всё чисто.'));
}

async function importListUrl() {
  const url = prompt('Ссылка на список сайтов или IP (обновляется раз в сутки):', 'https://');
  if (!url || url === 'https://') return;
  const guess = (url.split('/').pop() || 'imported').replace(/\.(list|txt|lst)$/i, '').replace(/[^A-Za-z0-9_-]/g, '_').slice(0, 40);
  const name = await createList(guess);
  if (!name) return;
  if (!await guarded(() => api('sub_set', { list: name, url }))) return;
  toast('Скачиваю…');
  const r = await guarded(() => api('sub_run', { list: name }));
  if (r?.result?.status !== 'ok') toast('Не скачалось: ' + (r?.result?.error || ''), { err: true });
  await loadState();
  go('#/sites/' + encodeURIComponent(name));
}

async function drawList(content, name) {
  const meta = S.state.lists.find((l) => l.name === name);
  if (!meta || !meta.exists) { content.replaceChildren(notice('bad', 'Списка нет', name)); return; }
  let data = await api('list_get', { name });
  const isIp = meta.kind === 'ip';
  let raw = false;
  let filter = '';
  let sortMode = 'file';
  const selected = new Set();
  const readers = meta.used.map((u) => {
    const p = S.state.conf_profiles.find((x) => x.index === u.profile) || prof(u.profile);
    return h('a', { class: 'chip ' + (u.role === 'exclude' ? 'bad' : 'accent'), href: `#/settings/p${u.profile}`, text: `${u.role === 'exclude' ? 'исключает из ' : ''}#${u.profile} ${p ? profName(p) : ''}` });
  });

  // переключатель «Списком / Текстом» и число записей рисует draw()
  const viewSlot = h('span', { class: 'row' });
  const moreList = h('div', { class: 'menu-list', hidden: true, role: 'menu', style: 'right:0;left:auto' });
  const more = h('span', { class: 'menu' }, btn('', () => { moreList.hidden = !moreList.hidden; }, 'small icon', 'down', { 'aria-haspopup': 'menu', title: 'Ещё: переименовать, копия, удалить', 'aria-label': 'Ещё' }), moreList);
  const moreItem = (label, fn, attrs = {}) => h('button', { type: 'button', role: 'menuitem', text: label, onclick: () => { moreList.hidden = true; fn(); }, ...attrs });
  moreList.append(moreItem('Переименовать', () => renameList()), moreItem('Сделать копию', () => copyList()),
    moreItem('Удалить список', () => deleteList(), { disabled: !meta.removable, title: meta.removable ? null : meta.used.length ? 'Список используется профилями — сначала отключите его' : 'Этот список защищён' }));
  const head = h('div', { class: 'lhead' },
    h('div', { class: 'row' }, h('h2', { class: 'mono ltitle', text: name }), LIST_NAMES[name] ? h('span', { class: 'muted', text: LIST_NAMES[name] }) : null, readers,
      h('span', { class: 'grow' }), viewSlot, btn('История', () => openHistory(name, reload), 'small', 'history'), more),
    h('div', { class: 'row sm' }, meta.used.length ? null : h('span', { class: 'muted', text: 'Ни один профиль не читает этот список — правки в нём ничего не меняют.' }), attachMenu(meta)),
    subRow());

  function subRow() {
    const sub = (S.state.subs || []).find((x) => x.list === name);
    const setUrl = async (url) => {
      if (!await guarded(() => api('sub_set', { list: name, url }))) return false;
      await loadState();
      return true;
    };
    if (!sub) {
      return h('div', { class: 'row sm' }, h('button', { class: 'chip add', type: 'button', text: '+ обновлять по ссылке', onclick: async () => {
        const url = prompt('Ссылка на список (обновляется раз в сутки, в 04:05):', 'https://');
        if (!url || url === 'https://') return;
        if (await setUrl(url)) { toast('Скачиваю…'); await runSub(); }
      } }));
    }
    return h('div', { class: 'row sm' },
      levelIcon(sub.status === 'ok' ? 'ok' : sub.status === 'error' ? 'error' : 'info'),
      h('span', { class: 'muted', text: 'Обновляется по ссылке:' }), h('a', { class: 'mono ellipsis', style: 'max-width:40ch', href: sub.url, target: '_blank', rel: 'noopener', text: sub.url }),
      h('span', { class: sub.status === 'error' ? 'status-bad' : 'muted', text: sub.last ? `${fmtDate(sub.last)} · ` + (sub.status === 'ok' ? `${plural(sub.count || 0, 'запись', 'записи', 'записей')}` + (sub.changed ? ` (+${sub.changed.added} −${sub.changed.removed})` : ', без изменений') : sub.error || '') : 'ещё не обновлялся' }),
      btn('Обновить сейчас', runSub, 'small', 'refresh'),
      btn('Изменить', async () => { const url = prompt('Ссылка на список:', sub.url); if (url && url !== sub.url && await setUrl(url)) route(); }, 'small ghost'),
      btn('Отключить', async () => { if (confirm('Больше не обновлять список по ссылке? Сами записи останутся.') && await setUrl('')) route(); }, 'small ghost'));
  }
  async function runSub() {
    const r = await guarded(() => api('sub_run', { list: name }));
    if (!r) return;
    const s = r.result;
    if (s?.status === 'ok') toast(s.changed ? `Обновлено: +${s.changed.added} −${s.changed.removed}` : 'Список уже актуален');
    else toast('Не обновлено: ' + (s?.error || 'неизвестная ошибка'), { err: true });
    await loadState();
    route();
  }
  const body = h('div', { class: 'stack', style: 'gap:14px' });
  content.replaceChildren(h('section', { class: 'panel' }, head, body));

  async function reload() {
    data = await api('list_get', { name });
    await loadState().catch(() => {});
    drawListNav(document.querySelector('.lnav'), name);
    draw();
  }
  async function renameList() {
    const to = prompt('Новое имя списка:', name.replace(/\.list$/, ''));
    if (!to || to === name.replace(/\.list$/, '')) return;
    const r = await guarded(() => api('list_rename', { name, to }));
    if (!r) return;
    toast(r.conf_changed ? 'Переименовано, ссылки в конфиге обновлены — перезапустите nfqws2' : 'Переименовано');
    await loadState();
    go('#/sites/' + encodeURIComponent(r.name));
  }
  async function copyList() {
    const to = prompt('Имя копии:', name.replace(/\.list$/, '') + '-copy');
    if (!to) return;
    const r = await guarded(() => api('list_copy', { name, to }), 'Копия создана');
    if (r) { await loadState(); go('#/sites/' + encodeURIComponent(r.name)); }
  }
  async function deleteList() {
    if (!confirm(`Удалить ${name}? Перед удалением делается снимок, вернуть можно из истории.`)) return;
    if (await guarded(() => api('list_delete', { name }), 'Список удалён')) { await loadState(); go('#/sites'); }
  }

  function draw() {
    const all = data.content.split('\n');
    if (all[all.length - 1] === '') all.pop();
    const byLine = {};
    for (const x of data.issues) (byLine[x.line] ||= []).push(x);
    const fixable = data.issues.filter((x) => 'fix' in x).length;
    viewSlot.replaceChildren(
      h('span', { class: 'sm muted num', text: plural(all.filter((l) => l.trim() && !/^\s*#/.test(l)).length, 'запись', 'записи', 'записей') }),
      h('div', { class: 'seg', role: 'group', 'aria-label': 'Вид' },
        h('button', { type: 'button', class: raw ? '' : 'on', text: 'Списком', onclick: () => { raw = false; draw(); } }),
        h('button', { type: 'button', class: raw ? 'on' : '', text: 'Текстом', onclick: () => { raw = true; draw(); } })));
    // замечания к списку — плашкой с кнопкой исправления
    const prNow = S.state.lists.find((l) => l.name === name)?.problems || {};
    const prText = [prNow.error && plural(prNow.error, 'ошибка', 'ошибки', 'ошибок'), prNow.warning && plural(prNow.warning, 'повтор', 'повтора', 'повторов'), prNow.info && plural(prNow.info, 'лишняя запись', 'лишние записи', 'лишних записей')].filter(Boolean).join(', ');
    const tools = prText ? notice(prNow.error ? 'bad' : 'warn', prText[0].toUpperCase() + prText.slice(1), fixable ? '«*.домен» → «домен», повторы и лишние записи удаляются. Текущая версия сохранится в историю.' : 'Исправьте отмеченные записи вручную.',
      fixable ? btn(`Исправить (${fixable})`, async () => {
        const r = await guarded(() => api('list_fix', { name }));
        if (r) { toast(`Исправлено: ${r.fixed}`, { undo: () => undoLast(false) }); await reload(); }
      }, 'small', 'wand') : null) : null;

    if (raw) {
      const issBox = h('div', {});
      const ed = codeEditor({ value: data.content, mode: 'list', maxHeight: 0, gutter: true, onInput: (v) => { setDirty(v !== data.content); relint(v); } });
      ed.ta.style.height = '62vh';
      const showIss = (list) => {
        ed.setIssues(list);
        issBox.replaceChildren(issuesListEl(list, (x) => {
          const lines = ed.ta.value.split('\n');
          const start = lines.slice(0, x.line).reduce((a, l) => a + l.length + 1, 0);
          ed.select(start, start + lines[x.line].length);
        }, (x) => 'строка ' + (x.line + 1)));
      };
      const relint = debounce(async (v) => { const r = await api('list_lint', { name, content: v }).catch(() => null); if (r) showIss(r.issues); }, 500);
      showIss(data.issues);
      body.replaceChildren(tools, ed.el, issBox, h('div', { class: 'row' },
        h('span', { class: 'sm muted grow', text: 'Одна запись на строку, # в начале — комментарий. Поддомены учитываются автоматически, ^домен — без поддоменов, «*» не поддерживается.' }),
        btn('Сохранить', async () => {
          if (await guarded(() => api('list_save', { name, content: ed.ta.value }), 'Сохранено')) { setDirty(false); await reload(); }
        }, 'primary')));
      return;
    }

    const addInput = h('input', { class: 'input grow', id: 'add-entry', placeholder: isIp ? 'Добавить: 1.2.3.0/24, можно несколько через пробел' : 'Добавить: example.com, можно несколько через пробел', autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'Добавить записи' });
    const add = async () => {
      const items = addInput.value.split(/[\s,]+/).filter(Boolean);
      if (!items.length) return;
      const r = await guarded(() => api('list_add', { name, items }));
      if (!r) return;
      toast(r.changed ? `Добавлено: ${r.changed}` : 'Уже есть в списке');
      addInput.value = '';
      await reload();
    };
    addInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); add(); } });
    const search = h('input', { class: 'input list-search', id: 'list-filter', placeholder: 'Поиск в списке', value: filter, autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'Поиск в списке' });
    const sortSel = h('select', { class: 'select', 'aria-label': 'Порядок', onchange: (e) => { sortMode = e.target.value; drawEntries(); } },
      [['file', 'Как в файле'], ['az', 'По алфавиту'], ['issues', 'Сначала с замечаниями']].map(([v, t]) => h('option', { value: v, text: t, selected: v === sortMode })));
    const bulk = h('div', { class: 'bulk', hidden: true });
    const listBox = h('div', { class: 'entries' });

    const drawBulk = () => {
      bulk.hidden = selected.size === 0;
      if (!selected.size) return;
      // выбор — по номерам строк, чтобы из повторов отмечался и удалялся только один
      const picked = [...selected].sort((a, b) => a - b);
      const items = [...new Set(picked.map((i) => all[i].trim()))];
      bulk.replaceChildren(h('b', { text: 'Выбрано: ' + picked.length }),
        targetMenu('Переместить в…', (to) => moveTo(items, to, false)),
        targetMenu('Копировать в…', (to) => moveTo(items, to, true)),
        btn('Удалить', () => removeLines(picked), 'small danger', 'trash'),
        h('span', { class: 'grow' }), btn('Снять выделение', () => { selected.clear(); drawEntries(); drawBulk(); }, 'small ghost'));
    };
    const removeLines = async (idx) => {
      const r = await guarded(() => api('list_remove_lines', { name, lines: idx.map((i) => ({ n: i, t: all[i] })) }));
      if (!r) return;
      selected.clear();
      toast(idx.length === 1 ? `Удалено: ${all[idx[0]].trim()}` : `Удалено: ${idx.length}`, { undo: () => undoLast(false) });
      reload();
    };
    const moveTo = async (items, to, copy) => {
      if (!to) return;
      const r = await guarded(() => api('list_move', { name, to, items, copy }));
      if (!r) return;
      toast(`${copy ? 'Скопировано' : 'Перенесено'} в ${to}: ${items.length}`);
      selected.clear();
      reload();
    };
    let dragFrom = null;
    const drawEntries = () => {
      const q = filter.trim().toLowerCase();
      let shown = all.map((l, i) => [l, i]).filter(([l]) => l.trim() && (!q || l.toLowerCase().includes(q)));
      if (sortMode === 'az') shown.sort((a, b) => a[0].trim().localeCompare(b[0].trim()));
      if (sortMode === 'issues') shown.sort((a, b) => (LEVEL_RANK[worst(byLine[b[1]])] || 0) - (LEVEL_RANK[worst(byLine[a[1]])] || 0));
      const canDrag = sortMode === 'file' && !q;
      const LIMIT = 500;
      listBox.replaceChildren(
        shown.slice(0, LIMIT).map(([l, i]) => {
          const t = l.trim();
          const isComment = t.startsWith('#');
          const iss = byLine[i];
          const lvl = worst(iss);
          const row = h('div', { class: 'ent' + (isComment ? ' cm' : '') + (selected.has(i) ? ' sel-on' : ''), draggable: canDrag ? 'true' : null });
          if (canDrag) {
            row.addEventListener('dragstart', (e) => { dragFrom = i; row.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
            row.addEventListener('dragend', () => row.classList.remove('dragging'));
            row.addEventListener('dragover', (e) => { e.preventDefault(); row.classList.add('drop'); });
            row.addEventListener('dragleave', () => row.classList.remove('drop'));
            row.addEventListener('drop', (e) => { e.preventDefault(); row.classList.remove('drop'); reorder(dragFrom, i); });
          }
          row.append(
            isComment ? h('span') : h('input', { type: 'checkbox', checked: selected.has(i), 'aria-label': 'Выбрать ' + t, onchange: (e) => { e.target.checked ? selected.add(i) : selected.delete(i); row.classList.toggle('sel-on', e.target.checked); drawBulk(); } }),
            canDrag ? h('span', { class: 'grip', title: 'Перетащите, чтобы изменить порядок', text: '⋮⋮' }) : h('span'),
            isComment || isIp ? h('span', { class: 't', text: t }) : h('button', { type: 'button', class: 't' + (lvl === 'error' ? ' error' : ''), text: t, title: 'Проверить сайт', onclick: () => checkHost(t.replace(/^\^/, '').replace(/^\*\./, '')) }));
          if (!isComment) {
            const host = t.replace(/^\^/, '').replace(/^\*\./, '');
            row.append(h('span', { class: 'note ' + (lvl || 'faint'), text: iss ? iss[0].msg : isIp ? '' : t.startsWith('^') ? 'без поддоменов' : 'и поддомены', title: iss ? iss.map((x) => x.msg).join('\n') : null }),
              isIp ? h('span') : btn('Проверить', () => checkHost(host), 'small ghost chk', null, { title: 'Открывается ли сайт и каким профилем он пойдёт' }),
              h('button', { class: 'btn ghost small icon', type: 'button', title: 'Удалить', 'aria-label': 'Удалить ' + t, onclick: () => removeLines([i]) }, icon('x')));
          }
          return row;
        }),
        shown.length > LIMIT ? h('p', { class: 'sm muted', style: 'padding:10px', text: `Показаны первые ${LIMIT} из ${shown.length} — уточните поиск.` }) : null,
        !shown.length ? h('p', { class: 'sm muted', style: 'padding:10px', text: q ? 'Ничего не найдено' : 'Список пуст' }) : null);
    };
    const reorder = async (from, to) => {
      if (from == null || from === to) return;
      const lines = [...all];
      const [moved] = lines.splice(from, 1);
      lines.splice(to > from ? to - 1 : to, 0, moved);
      if (await guarded(() => api('list_save', { name, content: lines.join('\n'), note: 'порядок записей' }))) reload();
    };
    search.addEventListener('input', () => { filter = search.value; drawEntries(); });
    drawEntries();
    drawBulk();
    body.replaceChildren(tools,
      h('div', { class: 'row' }, addInput, btn('Добавить', add, 'primary', 'plus')),
      h('div', { class: 'row' }, search, sortSel),
      bulk, listBox,
      h('p', { class: 'sm muted', text: 'Порядок меняется перетаскиванием за ⋮⋮. nfqws2 перечитывает списки сам — перезапуск не нужен.' }));
  }
  draw();

  function targetMenu(label, onPick) {
    const wrap = h('span', { class: 'menu' });
    const list = h('div', { class: 'menu-list', hidden: true, role: 'menu' });
    const others = S.state.lists.filter((l) => l.name !== name && l.editable && l.kind === meta.kind);
    const item = (l) => h('button', { type: 'button', role: 'menuitem', onclick: () => { list.hidden = true; onPick(l.name); } }, h('span', { text: listName(l.name) }), h('span', { class: 'faint mono sm', text: l.name }));
    list.append(h('div', { class: 'nav-h', text: 'Используемые' }), others.filter((l) => l.used.length).map(item),
      h('div', { class: 'nav-h', text: 'Другие' }), others.filter((l) => !l.used.length).map(item),
      h('button', { type: 'button', role: 'menuitem', text: '+ Новый список…', onclick: async () => { list.hidden = true; const n = await createList('new-list'); if (n) onPick(n); } }));
    const b = btn(label, () => { list.hidden = !list.hidden; b.setAttribute('aria-expanded', String(!list.hidden)); }, 'small', null, { 'aria-haspopup': 'menu', 'aria-expanded': 'false' });
    wrap.append(b, list);
    return wrap;
  }
}

// Подключение списка к профилю из менеджера списков
function attachMenu(meta) {
  const wrap = h('span', { class: 'menu' });
  const list = h('div', { class: 'menu-list', hidden: true, role: 'menu' });
  const profiles = S.state.conf_profiles.filter((p) => p.source && !meta.used.some((u) => u.profile === p.index));
  const opt = meta.kind === 'ip' ? 'ipset' : 'hostlist';
  for (const p of profiles) {
    for (const exclude of [false, true]) {
      list.append(h('button', { type: 'button', role: 'menuitem', onclick: async () => {
        list.hidden = true;
        await attachListToProfile(p, (exclude ? opt + '-exclude' : opt), meta.path);
      } }, h('span', { text: `#${p.index} ${profName(p)}` }), h('span', { class: 'faint sm', text: exclude ? 'как исключение' : 'как ' + (opt === 'ipset' ? 'IP' : 'сайты') })));
    }
  }
  const b = h('button', { class: 'chip add', type: 'button', text: '+ подключить к профилю', 'aria-haspopup': 'menu', onclick: () => { list.hidden = !list.hidden; } });
  wrap.append(b, list);
  return wrap;
}

async function attachListToProfile(p, opt, path) {
  await loadConf();
  const target = listTargetVar(p, opt);
  const toks = target.tokens();
  toks.splice(target.insertAt(toks), 0, `--${opt}=${path}`);
  const vars = target.build(toks);
  if (target.shared && !confirm(target.shared + ' Продолжить?')) return;
  if (await saveVars(vars, `список ${base(path)} подключён к профилю #${p.index}`)) {
    toast('Подключено. Перезапустите nfqws2, чтобы применить.');
    route(true);
  }
}

// Куда в конфиге дописывать списки профиля
function listTargetVar(p, opt) {
  const s = p.source;
  const v = S.conf.vars;
  if (s.source === 'NFQWS_ARGS_CUSTOM') {
    const parts = splitParts(tokensOf(v.NFQWS_ARGS_CUSTOM));
    return {
      tokens: () => [...parts[s.part - 1]],
      insertAt: (t) => t.findIndex((x) => /^--(payload|lua-desync|out-range|in-range)=/.test(x)) >= 0 ? t.findIndex((x) => /^--(payload|lua-desync|out-range|in-range)=/.test(x)) : t.length,
      build: (t) => { parts[s.part - 1] = t; return { NFQWS_ARGS_CUSTOM: joinParts(parts) }; },
    };
  }
  if (s.with === 'NFQWS_ARGS_IPSET' && opt.startsWith('ipset')) {
    return { tokens: () => tokensOf(v.NFQWS_ARGS_IPSET), insertAt: (t) => t.length, build: (t) => ({ NFQWS_ARGS_IPSET: t.join('\n') }), shared: 'IP-списки общие для профилей «QUIC по IP» и «HTTP/HTTPS по IP».' };
  }
  if (s.with === 'NFQWS_EXTRA_ARGS' && opt.startsWith('hostlist')) {
    const key = opt === 'hostlist-exclude' ? 'MODE_ALL' : 'MODE_LIST';
    return { tokens: () => tokensOf(v[key]), insertAt: (t) => t.length, build: (t) => ({ [key]: t.join('\n') }), shared: 'Списки сайтов общие для профилей «QUIC по сайтам» и «HTTP/HTTPS по сайтам».' };
  }
  const key = s.source;
  return {
    tokens: () => tokensOf(v[key]),
    insertAt: (t) => t.findIndex((x) => /^--(payload|lua-desync|out-range|in-range)=/.test(x)) >= 0 ? t.findIndex((x) => /^--(payload|lua-desync|out-range|in-range)=/.test(x)) : t.length,
    build: (t) => ({ [key]: t.join('\n') }),
    shared: key === 'NFQWS_ARGS' || key === 'NFQWS_ARGS_QUIC' ? `Переменная ${key} общая для двух профилей — список добавится в оба.` : null,
  };
}

const splitParts = (toks) => toks.reduce((acc, t) => { if (t === '--new') acc.push([]); else acc[acc.length - 1].push(t); return acc; }, [[]]);
const joinParts = (parts) => parts.map((p) => p.join('\n')).join('\n--new\n');

// Сохранение переменных конфига с проверкой; при ошибках предлагает сохранить всё равно (кроме фатальных)
async function saveVars(vars, note, force = false) {
  try {
    const res = await api('conf_save_vars', { vars, note, force });
    await loadState();
    await loadConf();
    return res;
  } catch (e) {
    if (e.data?.issues) {
      const errs = e.data.issues.filter((x) => x.level === 'error').map((x) => '• ' + x.msg).slice(0, 6).join('\n');
      if (e.data.fatal) { toast('Не сохранено: nfqws2 с таким конфигом не запустится. ' + (e.data.dry_run?.message || ''), { err: true }); return null; }
      if (confirm('В конфиге есть ошибки:\n' + errs + '\n\nnfqws2 запустится, но эти места будут работать неправильно. Сохранить всё равно?')) return saveVars(vars, note, true);
      return null;
    }
    toast(e.message, { err: true });
    return null;
  }
}

// ============ Настройки ============

async function viewSettings(main, r) {
  await Promise.all([loadCatalog(), loadConf()]);
  const pane = r.arg || 'p' + (S.state.conf_profiles.find((p) => profIssues(p).some((x) => x.level === 'error'))?.index || 1);
  const content = h('div', { class: 'stack', style: 'gap:14px' });
  const m = pane.match(/^p(\d+)$/);
  if (m) {
    // «Профили»: слева список профилей, справа выбранный
    const nav = h('nav', { class: 'nav lnav scrollable', 'aria-label': 'Профили' });
    main.append(h('div', { class: 'vh' }, h('h1', { text: 'Профили' })), h('div', { class: 'split settings' }, nav, content));
    drawSettingsNav(nav, pane);
    return viewProfile(content, Number(m[1]), r);
  }
  // остальные страницы настроек открываются из меню или вкладок «Система»
  main.append(content);
  const panes = { basic: paneBasic, base: paneBase, raw: paneRaw, backup: paneBackup, hist: paneHistory, look: paneLook, about: paneAbout, report: paneReport, readme: (c) => paneDoc(c, 'readme'), changelog: (c) => paneDoc(c, 'changelog') };
  await (panes[pane] || paneBasic)(content, r);
}

function drawSettingsNav(nav, current) {
  const st = S.state;
  const customs = st.conf_profiles.filter((p) => p.source?.source === 'NFQWS_ARGS_CUSTOM');
  const item = (id, label, tail) => h('a', { href: '#/settings/' + id, class: id === current ? 'on' : null }, label, tail ? h('span', { class: 'tail' }, tail) : null);
  let dragFrom = null;
  nav.replaceChildren(
    h('div', { class: 'nav-h', text: 'Профили — порядок проверки' }),
    st.conf_profiles.map((p) => {
      const iss = profIssues(p);
      const lvl = worst(iss);
      const isCustom = p.source?.source === 'NFQWS_ARGS_CUSTOM';
      const a = item('p' + p.index, [h('span', { class: 'pn ' + (lvl === 'error' ? 'err' : p.state) , text: p.index }), h('span', { class: 'nm', text: profName(p) })],
        [lvl === 'error' ? h('span', { class: 'errdot', title: 'Есть ошибки' }) : lvl === 'warning' ? h('span', { class: 'warndot', title: 'Есть предупреждения' }) : null, isCustom ? h('span', { class: 'grip', title: 'Перетащите, чтобы изменить порядок', text: '⋮⋮' }) : h('span', { title: 'Порядок задаёт скрипт запуска', text: '🔒︎' })]);
      if (isCustom && customs.length > 1) {
        a.draggable = true;
        a.addEventListener('dragstart', () => { dragFrom = p.source.part; });
        a.addEventListener('dragover', (e) => { e.preventDefault(); a.classList.add('drag-over'); });
        a.addEventListener('dragleave', () => a.classList.remove('drag-over'));
        a.addEventListener('drop', (e) => { e.preventDefault(); a.classList.remove('drag-over'); moveCustom(dragFrom, p.source.part); });
      }
      return a;
    }),
    h('button', { class: 'ni', type: 'button', onclick: addCustomProfile }, h('span', { class: 'pn', text: '+' }), h('span', { class: 'nm muted', text: 'Свой профиль' })),
    h('button', { class: 'ni', type: 'button', onclick: pasteProfile }, h('span', { class: 'pn', text: '⇩' }), h('span', { class: 'nm muted', text: 'Вставить чужой' })));
}

// Профиль одним куском для чата: подпись + параметры
function shareProfile(index, args) {
  const st = S.state;
  const prov = h('input', { class: 'input grow', value: st.ui.provider || '', placeholder: 'название провайдера', 'aria-label': 'Провайдер' });
  const ta = h('textarea', { class: 'input mono', rows: 7, readonly: true, style: 'width:100%;resize:vertical' });
  const draw = () => { ta.value = `# nfqws2 ${st.version ? 'v' + st.version : ''} · ${prov.value.trim() || 'провайдер не указан'} · ${st.ui.platform}\n${args.join(' ')}`; };
  prov.addEventListener('input', draw);
  const keep = () => { if (prov.value.trim() !== (st.ui.provider || '')) api('provider_set', { provider: prov.value.trim() }).then((r) => { st.ui.provider = r.provider; }).catch(() => {}); };
  draw();
  modal(`Профиль #${index} — для чата`, h('div', { class: 'stack' },
    h('div', { class: 'row' }, h('span', { class: 'sm muted', text: 'Провайдер' }), prov,
      btn('Определить', async () => { const r = await guarded(() => api('provider_detect')); if (r) { prov.value = r.provider; draw(); keep(); } }, 'small', 'search', { title: 'Роутер спросит у RIPEstat, чьей сети принадлежит ваш внешний адрес. Сам адрес в текст не попадает.' })),
    ta, h('p', { class: 'sm faint', text: 'В тексте только параметры профиля и подпись. Пути к спискам остаются как у вас — получателю их поправит «Вставить чужой».' })),
    btn('Скопировать', () => { keep(); copyText(ta.value); }, 'primary', 'copy'));
}

// Чужой профиль из чата: разбираем текст, отбрасываем лишнее, проверяем у nfqws2, даём испытать на сайте
function pasteProfile() {
  const ta = h('textarea', { class: 'input mono', rows: 6, style: 'width:100%;resize:vertical', placeholder: '--filter-tcp=443 --filter-l7=tls --lua-desync=fake:blob=tls_clienthello:tcp_md5 …', spellcheck: 'false' });
  const out = h('div', { class: 'stack' });
  const site = h('input', { class: 'input mono', placeholder: 'сайт для проверки, например rutracker.org', style: 'max-width:300px', autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'Сайт для проверки' });
  let res = null;
  // строки-подписи и комментарии убираем; кавычки, обратные косые и переносы — тоже
  const parse = () => ta.value.split('\n').filter((l) => !/^\s*#/.test(l)).join(' ').replace(/["'`\\]/g, ' ').replace(/^[A-Z_]+=/, '').split(/\s+/).filter(Boolean);
  const check = async () => {
    const parts = splitParts(parse()).filter((x) => x.length);
    if (!parts.length) { toast('Вставьте текст профиля', { err: true }); return; }
    res = await guarded(() => api('profile_check', { tokens: parts[0] }));
    if (!res) return;
    const bad = res.missing.length || !res.steps.length || (res.dry_run && !res.dry_run.ok);
    out.replaceChildren(
      parts.length > 1 ? notice('info', `В тексте ${plural(parts.length, 'профиль', 'профиля', 'профилей')} — взят первый`, 'Остальные вставьте по одному.') : null,
      res.tokens.length ? h('pre', { class: 'box', style: 'white-space:pre-wrap;word-break:break-all', text: res.tokens.join('\n') }) : null,
      res.rejected.length ? notice('warn', 'Отброшено', res.rejected.map((x) => `${x.tok} — ${x.why}`).join('; ')) : null,
      res.fixed.length ? notice('info', 'Пути к спискам поправлены под этот роутер', res.fixed.map((x) => `${x.from} → ${x.to}`).join('; ')) : null,
      res.missing.length ? notice('bad', 'Нет файлов, на которые ссылается профиль', res.missing.join(', ') + ' — создайте такие списки или уберите эти параметры из текста.') : null,
      res.dry_run && !res.dry_run.ok ? notice('bad', 'nfqws2 не принимает такой профиль', res.dry_run.message) : null,
      !res.steps.length ? notice('bad', res.tokens.length ? 'В профиле не осталось стратегии' : 'Параметров профиля в тексте не нашлось', res.tokens.length ? 'Без шагов --lua-desync профиль ничего не делает.' : '') : null,
      !bad ? notice('ok', 'nfqws2 принимает этот профиль', 'Можно испытать его стратегию на сайте или сразу добавить.') : null,
      !bad ? h('div', { class: 'row' },
        res.steps.length ? [site, btn('Проверить на сайте', test, 'small', 'play', { title: 'Стратегия профиля прогоняется отдельным nfqws2 на проверочных соединениях роутера — ваш трафик не затрагивается' })] : null,
        h('span', { class: 'grow' }), btn('Добавить профилем', add, 'small primary', 'ok')) : null);
  };
  const test = async () => {
    if (!site.value.trim()) { toast('Укажите сайт', { err: true }); return; }
    if (!await guarded(() => api('test_start', { host: site.value.trim(), sets: [], steps: res.steps, repeats: 3 }))) return;
    const host = site.value.trim().replace(/^[a-z]+:\/\//i, '').replace(/[/?#].*$/, '');
    bg.close();
    go(pickHref(host));
  };
  const add = async () => {
    await loadConf();
    const cur = S.conf.vars.NFQWS_ARGS_CUSTOM.trim() ? splitParts(tokensOf(S.conf.vars.NFQWS_ARGS_CUSTOM)) : [];
    if (!confirm('Добавить профиль первым в «свои профили»?\n\nОн будет проверяться раньше остальных; порядок потом можно поменять перетаскиванием. После сохранения nfqws2 перезапустится с проверкой и откатит изменения, если вы их не подтвердите.')) return;
    if (!await saveVars({ NFQWS_ARGS_CUSTOM: joinParts([res.tokens, ...cur]) }, 'вставлен чужой профиль')) return;
    bg.close();
    await safeRestart();
    go('#/settings/p1');
  };
  const bg = modal('Вставить чужой профиль', h('div', { class: 'stack' },
    h('p', { class: 'sm muted', text: 'Вставьте текст профиля из чата как есть — подписи, кавычки и переносы уберутся сами. Останутся только параметры, которые знает nfqws2 на этом роутере.' }),
    ta, h('div', { class: 'row' }, btn('Разобрать и проверить', check, 'primary small')), out));
}

async function moveCustom(from, to) {
  if (!from || from === to) return;
  const parts = splitParts(tokensOf(S.conf.vars.NFQWS_ARGS_CUSTOM));
  const [moved] = parts.splice(from - 1, 1);
  parts.splice(to - 1, 0, moved);
  if (await saveVars({ NFQWS_ARGS_CUSTOM: joinParts(parts) }, `порядок своих профилей: ${from} → ${to}`)) {
    toast('Порядок изменён. Перезапустите nfqws2, чтобы применить.');
    go('#/settings/p' + to);
  }
}

async function addCustomProfile() {
  const parts = S.conf.vars.NFQWS_ARGS_CUSTOM.trim() ? splitParts(tokensOf(S.conf.vars.NFQWS_ARGS_CUSTOM)) : [];
  parts.push(['--filter-tcp=443', '--filter-l7=tls', '--payload=tls_client_hello', '--lua-desync=multisplit:pos=1,midsld']);
  if (await saveVars({ NFQWS_ARGS_CUSTOM: joinParts(parts) }, 'добавлен свой профиль ' + parts.length)) {
    toast('Профиль добавлен в конец своих профилей');
    go('#/settings/p' + parts.length);
  }
}

// ---------- модель профиля для конструктора ----------

function splitTok(t) {
  const eq = t.indexOf('=');
  return eq < 0 ? [t.replace(/^--/, ''), null] : [t.slice(2, eq), t.slice(eq + 1)];
}
function parseModel(tokens) {
  const m = { tcp: null, udp: null, l7: null, filters: [], lists: [], seq: [] };
  for (const t of tokens) {
    const [n, v] = t.startsWith('--') ? splitTok(t) : [null, null];
    if (n === 'filter-tcp') m.tcp = v ? v.split(',') : [];
    else if (n === 'filter-udp') m.udp = v ? v.split(',') : [];
    else if (n === 'filter-l7') m.l7 = v ? v.split(',') : [];
    else if (n && n.startsWith('filter-')) m.filters.push({ tok: t });
    else if (n && LIST_OPTS.includes(n)) m.lists.push({ opt: n, val: v || '' });
    else if (n === 'payload') m.seq.push({ type: 'payload', vals: v ? v.split(',') : [] });
    else if (n === 'out-range' || n === 'in-range') m.seq.push({ type: 'range', opt: n, val: v || '' });
    else if (n === 'lua-desync' && v) {
      const ps = v.split(':');
      m.seq.push({ type: 'desync', fn: ps[0], params: ps.slice(1).map((p) => { const e = p.indexOf('='); return e < 0 ? { k: p, v: null } : { k: p.slice(0, e), v: p.slice(e + 1) }; }) });
    } else m.seq.push({ type: 'raw', tok: t });
  }
  return m;
}
// Сериализация: фильтры и списки вперёд (их позиция не важна), шаги — в заданном порядке
function serializeModel(m) {
  const tokens = [];
  const owners = [];
  const push = (t, o) => { tokens.push(t); owners.push(o); };
  if (m.tcp && m.tcp.length) push('--filter-tcp=' + m.tcp.join(','), 'tcp');
  if (m.udp && m.udp.length) push('--filter-udp=' + m.udp.join(','), 'udp');
  if (m.l7 && m.l7.length) push('--filter-l7=' + m.l7.join(','), 'l7');
  for (const f of m.filters) push(f.tok, f);
  for (const l of m.lists) push(`--${l.opt}=${l.val}`, l);
  for (const it of m.seq) {
    if (it.type === 'payload') push('--payload=' + it.vals.join(','), it);
    else if (it.type === 'range') push(`--${it.opt}=${it.val}`, it);
    else if (it.type === 'desync') push('--lua-desync=' + [it.fn, ...it.params.map((p) => (p.v === null ? p.k : `${p.k}=${p.v}`))].join(':'), it);
    else push(it.tok, it);
  }
  return { tokens, owners };
}

// ---------- профиль: конструктор и текст ----------

async function viewProfile(content, index, r) {
  const p = confProf(index);
  if (!p || !p.source) { content.replaceChildren(notice('bad', 'Нет такого профиля', 'Профиль #' + index)); return; }
  const s = p.source;
  const v = S.conf.vars;
  const isCustom = s.source === 'NFQWS_ARGS_CUSTOM';
  const customParts = isCustom ? splitParts(tokensOf(v.NFQWS_ARGS_CUSTOM)) : null;
  const origMain = isCustom ? customParts[s.part - 1] : tokensOf(v[s.source]);
  let model = parseModel(origMain);
  // «Для кого» у составных профилей берётся из общих переменных
  let ipsetModel = s.with === 'NFQWS_ARGS_IPSET' ? parseModel(tokensOf(v.NFQWS_ARGS_IPSET)) : null;
  let mode = s.with === 'NFQWS_EXTRA_ARGS' ? v.NFQWS_EXTRA_ARGS : null;
  let modeListModel = s.with === 'NFQWS_EXTRA_ARGS' ? parseModel(tokensOf(v.MODE_LIST)) : null;
  let modeAllModel = s.with === 'NFQWS_EXTRA_ARGS' ? parseModel(tokensOf(v.MODE_ALL)) : null;
  const orig = JSON.stringify([model, ipsetModel, mode, modeListModel, modeAllModel]);
  // Стратегия из теста: заменяем шаги профиля (фильтры, списки и типы пакетов остаются)
  let applied = null;
  try { applied = r?.q.get('apply') ? JSON.parse(r.q.get('apply')) : null; } catch { applied = null; }
  if (Array.isArray(applied) && applied.every((t) => typeof t === 'string' && t.startsWith('--lua-desync='))) {
    const keep = model.seq.filter((it) => it.type !== 'desync');
    model.seq = [...keep, ...parseModel(applied).seq];
  } else {
    applied = null;
  }
  let view = sessionStorage.getItem('nfqws-ui-pview') || 'build';
  let issues = profIssues(p);
  let dryRun = S.state.lint?.dry_run;
  let helpInfo = null;
  let helpIss = null;

  const mainOffset = () => {
    if (!isCustom) return 0;
    let off = 0;
    for (let i = 0; i < s.part - 1; i++) off += customParts[i].length + 1;
    return off;
  };
  const buildVars = () => {
    const out = {};
    const tokens = serializeModel(model).tokens;
    if (isCustom) { const parts = customParts.map((x) => x); parts[s.part - 1] = tokens; out.NFQWS_ARGS_CUSTOM = joinParts(parts); } else out[s.source] = tokens.join('\n');
    if (ipsetModel) out.NFQWS_ARGS_IPSET = serializeModel(ipsetModel).tokens.join('\n');
    if (mode !== null) { out.NFQWS_EXTRA_ARGS = mode; out.MODE_LIST = serializeModel(modeListModel).tokens.join('\n'); out.MODE_ALL = serializeModel(modeAllModel).tokens.join('\n'); }
    return out;
  };
  const changed = () => JSON.stringify([model, ipsetModel, mode, modeListModel, modeAllModel]) !== orig;

  const head = h('div', {});
  const body = h('div', {});
  const bar = h('div', { class: 'savebar', hidden: true });
  const helpBox = h('aside', { class: 'help', 'aria-label': 'Справка' });
  content.replaceChildren(head, body, bar);

  function drawHead() {
    const lvl = worst(issues);
    const nErr = issues.filter((x) => x.level === 'error').length;
    const nWarn = issues.filter((x) => x.level === 'warning').length;
    const shared = s.source === 'NFQWS_ARGS' || s.source === 'NFQWS_ARGS_QUIC';
    head.replaceChildren(h('div', { class: 'stack' },
      h('div', { class: 'vh' }, h('h1', { text: `#${index} ${profName(p)}` }),
        nErr ? chip(plural(nErr, 'ошибка', 'ошибки', 'ошибок'), 'bad') : null, nWarn ? chip(plural(nWarn, 'предупреждение', 'предупреждения', 'предупреждений'), 'warn') : null,
        !lvl ? chip('ошибок нет', 'ok') : null,
        h('span', { class: 'grow' }),
        isCustom ? [btn('', () => moveCustom(s.part, s.part - 1), 'small icon', 'up', { title: 'Выше', 'aria-label': 'Переместить выше', disabled: s.part === 1 }),
          btn('', () => moveCustom(s.part, s.part + 1), 'small icon', 'down', { title: 'Ниже', 'aria-label': 'Переместить ниже', disabled: s.part === customParts.length }),
          btn('Копия', dupCustom, 'small', 'copy'), btn('Удалить', delCustom, 'small danger', 'trash')] : null,
        btn('Для чата', () => shareProfile(index, p.args), 'small', 'copy', { title: 'Текст профиля с подписью: версия nfqws2, провайдер, система — чтобы поделиться в чате' }),
        h('div', { class: 'seg', role: 'group', 'aria-label': 'Способ правки' },
          h('button', { type: 'button', class: view === 'build' ? 'on' : '', text: 'Конструктор', onclick: () => setView('build') }),
          h('button', { type: 'button', class: view === 'text' ? 'on' : '', text: 'Текст', onclick: () => setView('text') }))),
      h('p', { class: 'sm muted' }, `${VAR_INFO[s.source][0]} · переменная ${s.source}${isCustom ? `, часть ${s.part}` : ''}` + (shared ? ' — общая с соседним профилем, правка стратегии затронет оба' : '') + ' · ',
        h('span', { text: portsText(p.remaining) ? 'реально доходит: ' + portsText(p.remaining) : 'до профиля ничего не доходит' })),
      applied ? notice('info', 'Стратегия подставлена из теста', 'Шаги профиля заменены стратегией, которая сработала в тесте. Проверьте и сохраните — или нажмите «Отменить».') : null,
      issues.filter((x) => x.tok == null || x.msg.startsWith('Профиль #')).map((x) => notice(x.level === 'error' ? 'bad' : x.level === 'warning' ? 'warn' : 'info', x.msg.replace(/^Профиль #d+: /, ''), null, fixButton(x, changed() ? 'Сначала сохраните или отмените свои правки' : null)))));
  }
  function setView(vw) {
    view = vw;
    try { sessionStorage.setItem('nfqws-ui-pview', vw); } catch { /* нет хранилища */ }
    drawHead();
    draw();
  }
  async function dupCustom() {
    const parts = customParts.map((x) => [...x]);
    parts.splice(s.part, 0, serializeModel(model).tokens);
    if (await saveVars({ NFQWS_ARGS_CUSTOM: joinParts(parts) }, `копия своего профиля ${s.part}`)) go('#/settings/p' + (index + 1));
  }
  async function delCustom() {
    if (!confirm(`Удалить свой профиль ${s.part}? Перед удалением делается снимок.`)) return;
    const parts = customParts.filter((_, i) => i !== s.part - 1);
    if (await saveVars({ NFQWS_ARGS_CUSTOM: parts.length ? joinParts(parts) : '' }, `удалён свой профиль ${s.part}`)) go('#/settings');
  }

  // Замечания, относящиеся к элементу модели
  const ownerIssues = () => {
    const { owners } = serializeModel(model);
    const off = mainOffset();
    const map = new Map();
    for (const x of issues) {
      if (x.tok == null) continue;
      const o = owners[x.tok - off];
      if (o) (map.get(o) || map.set(o, []).get(o)).push(x);
    }
    return map;
  };

  const relint = debounce(async () => {
    try {
      const res = await api('lint', { vars: buildVars() });
      dryRun = res.dry_run;
      const mainVar = s.source;
      issues = res.issues.filter((x) => x.var === mainVar && (!x.msg.startsWith('Профиль #') || x.msg.startsWith(`Профиль #${index}:`)));
      if (isCustom) {
        const off = mainOffset();
        const len = serializeModel(model).tokens.length;
        issues = issues.filter((x) => x.tok == null ? x.msg.startsWith(`Профиль #${index}:`) : x.tok >= off && x.tok < off + len);
      }
      if (ipsetModel) issues.push(...res.issues.filter((x) => x.var === 'NFQWS_ARGS_IPSET').map((x) => ({ ...x, tok: null })));
      drawHead();
      if (view === 'build') keepFocus(draw); else textEd?.setIssues(issues.map((x) => ({ ...x, tok: x.tok == null ? null : x.tok - mainOffset() })));
      drawBar();
    } catch (e) { /* проверка не критична */ }
  }, 600);

  const touch = () => { setDirty(changed()); drawBar(); relint(); };
  function drawBar() {
    const c = changed();
    bar.hidden = !c;
    if (!c) return;
    bar.replaceChildren(h('span', {}, `Изменено: профиль #${index}. `, dryRun ? (dryRun.ok ? h('span', { class: 'status-ok', text: 'nfqws2 --dry-run: пройдено' }) : h('span', { class: 'status-bad', text: 'nfqws2 --dry-run: ' + dryRun.message })) : null),
      btn('Отменить', () => { setDirty(false); route(); }, 'ghost'),
      btn('Сохранить', async () => {
        if (await saveVars(buildVars(), `профиль #${index} ${profName(p)}`)) { setDirty(false); toast('Сохранено. Перезапустите nfqws2, чтобы применить.'); route(); }
      }, 'primary'));
  }

  let textEd = null;
  function draw() {
    if (view === 'text') { drawText(); return; }
    const oi = ownerIssues();
    body.replaceChildren(h('div', { class: 'two' }, h('div', { class: 'panel' },
      groupWhen(oi), groupWho(oi), groupWhat(oi)), helpBox));
    renderHelp(helpBox, helpInfo, helpIss);
  }

  function drawText() {
    const off = mainOffset();
    const help = h('div', { class: 'help' });
    const sug = createSuggest();
    const issBox = h('div', {});
    const text = serializeModel(model).tokens.join('\n');
    textEd = codeEditor({
      value: text, mode: 'args', gutter: true, suggest: sug, maxHeight: 640,
      onInput: (val) => { model = parseModel(tokensOf(val)); touch(); },
      onCaret: (pos, t) => {
        const tk = tokenize(t).map((x, i) => ({ ...x, i })).find((x) => pos >= x.start && pos <= x.end);
        renderHelp(help, tk ? helpFor(tk.text, pos - tk.start) : null, tk ? issues.filter((x) => x.tok - off === tk.i) : null);
      },
    });
    const local = issues.map((x) => ({ ...x, tok: x.tok == null ? null : x.tok - off }));
    textEd.setIssues(local);
    issBox.replaceChildren(issuesListEl(local.filter((x) => x.tok != null), (x) => { const t = tokenize(textEd.ta.value)[x.tok]; if (t) textEd.select(t.start, t.end); },
      (x) => { const t = tokenize(textEd.ta.value)[x.tok]; return t ? 'строка ' + textEd.ta.value.slice(0, t.start).split('\n').length : ''; }));
    renderHelp(help, null);
    body.replaceChildren(h('div', { class: 'panel' }, textEd.el, sug.el, issBox, help,
      h('p', { class: 'sm muted', text: 'По одному аргументу на строку. Tab — принять подсказку. Правки здесь и в конструкторе — одно и то же.' })));
  }

  // --- «Когда срабатывает» ---
  function groupWhen(oi) {
    const portRow = (key, label) => {
      const arr = model[key];
      if (arr === null) return h('div', { class: 'frow' }, h('span', { class: 'lbl', text: label }), h('div', {}, h('button', { class: 'chip add', type: 'button', text: '+ добавить фильтр ' + label, onclick: () => { model[key] = []; touch(); draw(); } })));
      const inp = h('input', { class: 'input mono', style: 'width:130px;min-height:26px;padding:1px 8px', placeholder: '443 или 5000-5010', 'data-fk': 'port-' + key, 'aria-label': 'Добавить порт ' + label });
      const addPort = () => {
        const val = inp.value.trim();
        if (!val) return;
        if (!/^~?(\d{1,5}(-\d{1,5})?|\*)$/.test(val)) { toast('Порт — число, диапазон 5000-5010 или *', { err: true }); return; }
        arr.push(val); touch(); draw();
      };
      inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); addPort(); } });
      const lvl = worst(oi.get(key));
      return [h('div', { class: 'frow' }, h('span', { class: 'lbl', text: label }),
        h('div', { class: 'chips' }, arr.map((x, i) => h('span', { class: 'chip mono' + (lvl ? ' ' + (lvl === 'error' ? 'bad' : 'warn') : '') }, x, h('button', { class: 'x', type: 'button', 'aria-label': 'Убрать ' + x, text: '×', onclick: () => { arr.splice(i, 1); if (!arr.length) model[key] = null; touch(); draw(); } }))), inp, btn('', addPort, 'small icon', 'plus', { 'aria-label': 'Добавить' }))),
      (oi.get(key) || []).map((x) => h('div', { class: 'hint ' + x.level, style: 'grid-column:2' }, levelIcon(x.level), ' ', x.msg))];
    };
    const l7 = model.l7 || [];
    const l7add = h('select', { class: 'mini-sel', 'aria-label': 'Добавить протокол', onchange: (e) => { if (e.target.value) { model.l7 = [...l7, e.target.value]; touch(); draw(); } } },
      h('option', { value: '', text: '+ протокол' }), S.catalog.help.l7.filter((x) => !l7.includes(x)).map((x) => h('option', { value: x, text: x })));
    return h('div', { class: 'group' }, h('div', { class: 'group-h' }, h('h3', { text: 'Когда срабатывает' })),
      portRow('tcp', 'TCP-порты'), portRow('udp', 'UDP-порты'),
      h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Протоколы' }), h('div', { class: 'chips' },
        l7.length ? l7.map((x, i) => h('span', { class: 'chip accent' }, x, h('button', { class: 'x', type: 'button', text: '×', 'aria-label': 'Убрать ' + x, onclick: () => { l7.splice(i, 1); model.l7 = l7.length ? l7 : null; touch(); draw(); } }))) : h('span', { class: 'sm muted', text: 'любые' }), l7add)),
      model.filters.length ? h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Другие фильтры' }), h('div', { class: 'chips' }, model.filters.map((f, i) => h('span', { class: 'chip mono' }, f.tok, h('button', { class: 'x', type: 'button', text: '×', onclick: () => { model.filters.splice(i, 1); touch(); draw(); } }))))) : null);
  }

  // --- «Для кого» ---
  function listChips(m, oi, opts) {
    return [m.lists.map((l, i) => {
      const lvl = worst(oi?.get(l));
      const isFile = !/-domains$|-ip$/.test(l.opt);
      const label = `${LIST_ROLE[l.opt]}: ${isFile ? listName(base(l.val)) : l.val}`;
      return h('span', { class: 'chip ' + (lvl === 'error' ? 'bad' : /exclude/.test(l.opt) ? 'warn' : 'ok'), title: (oi?.get(l) || []).map((x) => x.msg).join('\n') || l.val },
        isFile ? h('a', { href: '#/sites/' + encodeURIComponent(base(l.val)), text: label, style: 'color:inherit' }) : label,
        h('button', { class: 'x', type: 'button', text: '×', 'aria-label': 'Убрать ' + label, onclick: () => { m.lists.splice(i, 1); touch(); draw(); } }));
    }), addListSelect(m, opts)];
  }
  function addListSelect(m, opts) {
    const sel = h('select', { class: 'mini-sel', 'aria-label': 'Добавить список', onchange: (e) => {
      const [opt, path] = e.target.value.split('|');
      if (!opt) return;
      m.lists.push({ opt, val: path }); touch(); draw();
    } }, h('option', { value: '', text: '+ список' }));
    for (const opt of opts) {
      const kind = opt.startsWith('ipset') ? 'ip' : 'host';
      const g = h('optgroup', { label: LIST_ROLE[opt] });
      S.state.lists.filter((l) => l.kind === kind && l.exists && !m.lists.some((x) => x.opt === opt && x.val === l.path)).forEach((l) => g.append(h('option', { value: `${opt}|${l.path}`, text: listName(l.name) + ' · ' + l.name })));
      sel.append(g);
    }
    return sel;
  }
  function groupWho(oi) {
    const rows = [];
    if (s.with === 'NFQWS_ARGS_IPSET') {
      rows.push(h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'IP-списки' }), h('div', { class: 'chips' }, listChips(ipsetModel, null, ['ipset', 'ipset-exclude']))),
        h('p', { class: 'hint sm muted', text: 'Общие для «QUIC по IP» и «HTTP/HTTPS по IP» (переменная NFQWS_ARGS_IPSET).' }));
    } else if (s.with === 'NFQWS_EXTRA_ARGS') {
      rows.push(h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Режим' }),
        h('select', { class: 'select', 'aria-label': 'Режим', onchange: (e) => { mode = e.target.value; touch(); draw(); } }, MODES.map(([val, t]) => h('option', { value: val, text: t, selected: mode === val })))));
      if (mode !== '$MODE_ALL') rows.push(h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Списки сайтов' }), h('div', { class: 'chips' }, listChips(modeListModel, null, ['hostlist']))));
      if (mode !== '$MODE_LIST') rows.push(h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Исключения' }), h('div', { class: 'chips' }, listChips(modeAllModel, null, ['hostlist-exclude']))));
      rows.push(h('p', { class: 'hint sm muted', text: 'Общие для «QUIC по сайтам» и «HTTP/HTTPS по сайтам» (MODE_LIST / MODE_ALL).' }));
    }
    const own = model.lists.length || !s.with;
    return h('div', { class: 'group' }, h('div', { class: 'group-h' }, h('h3', { text: 'Для кого' }), h('span', { class: 'sm muted', text: profScope(p) })),
      rows,
      own ? h('div', { class: 'frow' }, h('span', { class: 'lbl', text: s.with ? 'Списки профиля' : 'Списки' }),
        h('div', { class: 'chips' }, model.lists.length ? null : h('span', { class: 'sm muted', text: 'весь трафик на этих портах' }), listChips(model, oi, ['hostlist', 'hostlist-exclude', 'ipset', 'ipset-exclude']))) : null);
  }

  // --- «Что делает» ---
  function groupWhat(oi) {
    const seq = model.seq;
    const hasCircular = seq.some((x) => x.type === 'desync' && x.fn === 'circular');
    let dragFrom = null;
    const move = (from, to) => { if (to < 0 || to >= seq.length || from === to) return; const [it] = seq.splice(from, 1); seq.splice(to, 0, it); touch(); draw(); };
    const ctl = (i) => h('div', { class: 'step-ctl' },
      btn('', () => move(i, i - 1), 'small icon ghost', 'up', { 'aria-label': 'Выше', disabled: i === 0 }),
      btn('', () => move(i, i + 1), 'small icon ghost', 'down', { 'aria-label': 'Ниже', disabled: i === seq.length - 1 }),
      btn('', () => { seq.splice(i, 1); touch(); draw(); }, 'small icon ghost', 'x', { 'aria-label': 'Удалить шаг' }));
    const wrapStep = (i, it, cls, badge, mainEl) => {
      const iss = oi.get(it) || [];
      const lvl = worst(iss);
      const el = h('div', { class: 'step ' + (cls || '') + (lvl === 'error' || lvl === 'warning' ? ' ' + lvl : ''), draggable: 'true' },
        h('span', { class: 'grip', title: 'Перетащите, чтобы изменить порядок', text: '⋮⋮' }), badge, mainEl, ctl(i));
      el.addEventListener('dragstart', (e) => { if (e.target.closest('input,select,button')) { e.preventDefault(); return; } dragFrom = i; el.classList.add('dragging'); });
      el.addEventListener('dragend', () => el.classList.remove('dragging'));
      el.addEventListener('dragover', (e) => { e.preventDefault(); el.classList.add('drop'); });
      el.addEventListener('dragleave', () => el.classList.remove('drop'));
      el.addEventListener('drop', (e) => { e.preventDefault(); el.classList.remove('drop'); move(dragFrom, i); });
      return el;
    };
    const issueLines = (it) => (oi.get(it) || []).map((x) => {
      const need = x.msg.match(/нужен параметр «([a-z0-9_]+)»/);
      const fixEl = x.fix ? fixButton(x, changed() ? 'Сначала сохраните или отмените свои правки' : null) || (need && it.type === 'desync' ? quickFix(it, need[1]) : null) : need && it.type === 'desync' ? quickFix(it, need[1]) : null;
      return h('div', { class: 'fix' + (x.level === 'warning' || x.level === 'info' ? ' warning' : '') }, h('b', { text: x.level === 'error' ? 'Ошибка.' : 'Внимание.' }), h('span', { text: x.msg }), fixEl);
    });
    let stratN = 0;
    const items = seq.map((it, i) => {
      if (it.type === 'payload') {
        const add = h('select', { class: 'mini-sel', 'aria-label': 'Добавить тип пакетов', onchange: (e) => { if (e.target.value) { it.vals.push(e.target.value); touch(); draw(); } } },
          h('option', { value: '', text: '+ тип' }), S.catalog.help.payloads.filter((x) => !it.vals.includes(x)).map((x) => h('option', { value: x, text: x })));
        return wrapStep(i, it, 'orch', h('span', { class: 'sn o', text: 'P' }), h('div', { class: 'step-main' },
          h('div', { class: 'step-t' }, h('b', { text: 'Типы пакетов' }), h('span', { class: 'sm muted', text: 'к ним применяются шаги ниже' })),
          h('div', { class: 'chips' }, it.vals.map((x, j) => h('span', { class: 'chip mono' }, x, h('button', { class: 'x', type: 'button', text: '×', onclick: () => { it.vals.splice(j, 1); touch(); draw(); } }))), add), issueLines(it)));
      }
      if (it.type === 'range') {
        return wrapStep(i, it, 'orch', h('span', { class: 'sn o', text: 'R' }), h('div', { class: 'step-main' },
          h('div', { class: 'step-t' }, h('b', { text: it.opt === 'out-range' ? 'Исходящие пакеты' : 'Входящие пакеты' }),
            h('input', { class: 'input mono', style: 'width:120px;min-height:26px;padding:1px 8px', value: it.val, 'data-fk': 'range-' + i, 'aria-label': 'Диапазон', oninput: (e) => { it.val = e.target.value; touch(); } }),
            h('span', { class: 'sm muted', text: 'например <n2 — первые 2 пакета' })), issueLines(it)));
      }
      if (it.type === 'raw') {
        return wrapStep(i, it, 'orch', h('span', { class: 'sn o', text: '·' }), h('div', { class: 'step-main' },
          h('input', { class: 'input mono', value: it.tok, 'data-fk': 'raw-' + i, 'aria-label': 'Аргумент', oninput: (e) => { it.tok = e.target.value; touch(); } }), issueLines(it)));
      }
      // шаг стратегии (lua-desync)
      const fn = S.catalog.lua.functions[it.fn];
      const strat = it.params.find((x) => x.k === 'strategy');
      const badge = it.fn === 'circular' ? h('span', { class: 'sn o', text: '⟳', title: 'Перебор стратегий' }) : h('span', { class: 'sn', text: strat ? strat.v : ++stratN });
      const fnSel = h('select', { class: 'fn-sel', 'data-fk': 'fn-' + i, 'aria-label': 'Функция', onfocus: () => { helpInfo = fnHelp(it.fn); helpIss = oi.get(it); renderHelp(helpBox, helpInfo, helpIss); },
        onchange: (e) => { it.fn = e.target.value; touch(); draw(); } },
        Object.keys(S.catalog.lua.functions).sort().map((f) => h('option', { value: f, text: f, selected: f === it.fn })));
      const params = h('div', { class: 'params' }, it.params.map((pr, j) => {
        const missingVal = pr.v === '';
        const el = h('span', { class: 'param' + (pr.v === null ? ' flag' : '') + (missingVal ? ' missing' : '') },
          h('button', { class: 'k', type: 'button', text: pr.k, title: keyHelp(it.fn, pr.k).lines[0], onclick: () => { helpInfo = keyHelp(it.fn, pr.k); renderHelp(helpBox, helpInfo, oi.get(it)); } }),
          pr.v !== null ? h('input', { class: 'v', value: pr.v, size: Math.max(3, pr.v.length + 1), 'data-fk': `v-${i}-${j}`, 'aria-label': pr.k,
            onfocus: () => { helpInfo = keyHelp(it.fn, pr.k); renderHelp(helpBox, helpInfo, oi.get(it)); },
            oninput: (e) => { pr.v = e.target.value; e.target.size = Math.max(3, pr.v.length + 1); touch(); } }) : null,
          h('button', { class: 'x', type: 'button', text: '×', 'aria-label': 'Убрать ' + pr.k, onclick: () => { it.params.splice(j, 1); touch(); draw(); } }));
        return el;
      }), addParamSelect(it));
      const doc = fn?.doc?.[0] || (fn ? '' : 'нет такой функции в lua-скриптах');
      return wrapStep(i, it, it.fn === 'circular' ? 'orch' : '', badge, h('div', { class: 'step-main' },
        h('div', { class: 'step-t' }, fnSel, strat && hasCircular ? h('span', { class: 'sm muted', text: `стратегия ${strat.v}` }) : null, h('span', { class: 'sm muted ellipsis', text: doc })),
        params, issueLines(it)));
    });
    const addStep = h('select', { class: 'select', 'aria-label': 'Добавить', onchange: (e) => {
      const val = e.target.value;
      if (!val) return;
      if (val === 'payload') seq.push({ type: 'payload', vals: ['tls_client_hello'] });
      else if (val === 'range') seq.push({ type: 'range', opt: 'out-range', val: '<n2' });
      else seq.push({ type: 'desync', fn: val, params: (S.catalog.lua.functions[val]?.required || []).map((k) => ({ k, v: '' })) });
      touch(); draw();
    } }, h('option', { value: '', text: '+ Добавить шаг…' }),
    h('optgroup', { label: 'Шаг стратегии' }, ['multisplit', 'multidisorder', 'fake', 'fakedsplit', 'fakeddisorder', 'hostfakesplit', 'circular'].filter((f) => S.catalog.lua.functions[f]).map((f) => h('option', { value: f, text: f + ' — ' + (S.catalog.lua.functions[f].doc[0] || '').slice(0, 60) }))),
    h('optgroup', { label: 'Все функции' }, Object.keys(S.catalog.lua.functions).sort().map((f) => h('option', { value: f, text: f }))),
    h('optgroup', { label: 'Условия' }, h('option', { value: 'payload', text: 'Типы пакетов (--payload)' }), h('option', { value: 'range', text: 'Диапазон пакетов (--out-range)' })));
    return h('div', { class: 'group' }, h('div', { class: 'group-h' }, h('h3', { text: 'Что делает' }),
      hasCircular ? h('span', { class: 'sm muted', text: 'circular перебирает стратегии 1, 2, … при сбоях' }) : h('span', { class: 'sm muted', text: 'шаги выполняются сверху вниз' })),
    h('div', { class: 'seq' }, items, addStep));
  }

  function addParamSelect(it) {
    const keys = allowedKeys(it.fn).filter((k) => !it.params.some((pr) => pr.k === k.key));
    return h('select', { class: 'mini-sel', 'aria-label': 'Добавить параметр', onchange: (e) => {
      const k = e.target.value;
      if (!k) return;
      it.params.push({ k, v: keyNeedsValue(it.fn, k) ? '' : null });
      touch(); draw();
      requestAnimationFrame(() => body.querySelector(`[data-fk="v-${model.seq.indexOf(it)}-${it.params.length - 1}"]`)?.focus());
    } }, h('option', { value: '', text: '+ параметр' }),
    keys.map((k) => h('option', { value: k.key, text: `${k.key} — ${k.desc.replace(/^[a-z0-9_]+(\[?=[^\s—-]*\]?)?\s*[—-]?\s*/, '').slice(0, 50)}` })));
  }

  function quickFix(it, key) {
    if (key === 'blob') {
      const blobs = declaredBlobs();
      const sel = h('select', { class: 'mini-sel mono', 'aria-label': 'Блоб' }, blobs.map((b) => h('option', { value: b, text: b })));
      return [h('span', { text: 'Подставить:' }), sel, btn('Исправить', () => {
        const ex = it.params.find((x) => x.k === 'blob');
        if (ex) ex.v = sel.value; else it.params.unshift({ k: 'blob', v: sel.value });
        touch(); draw();
      }, 'small primary')];
    }
    return btn(`Добавить ${key}`, () => { it.params.push({ k: key, v: '' }); touch(); draw(); }, 'small');
  }

  drawHead();
  draw();
  drawBar();
  if (applied) touch();
}

// Перерисовка с сохранением фокуса и курсора в поле с data-fk
function keepFocus(fn) {
  const a = document.activeElement;
  const key = a?.dataset?.fk;
  const sel = key && 'selectionStart' in a ? [a.selectionStart, a.selectionEnd] : null;
  fn();
  if (!key) return;
  const b = document.querySelector(`[data-fk="${key}"]`);
  if (!b) return;
  b.focus({ preventScroll: true });
  if (sel && b.setSelectionRange) try { b.setSelectionRange(...sel); } catch { /* не поле ввода */ }
}

// ---------- Основное ----------

async function paneBasic(content) {
  const sys = await api('sysinfo').catch(() => null);
  const v = S.conf.vars;
  const orig = { ...v };
  const vals = { ISP_INTERFACE: v.ISP_INTERFACE, NFQWS_EXTRA_ARGS: v.NFQWS_EXTRA_ARGS, TCP_PORTS: v.TCP_PORTS, UDP_PORTS: v.UDP_PORTS, IPV6_ENABLED: v.IPV6_ENABLED, LOG_LEVEL: v.LOG_LEVEL };
  const iss = (k) => (S.state.lint?.issues || []).filter((x) => x.var === k);
  const bar = h('div', { class: 'savebar', hidden: true });
  const changedKeys = () => Object.keys(vals).filter((k) => vals[k] !== orig[k]);
  const refresh = () => {
    const c = changedKeys();
    setDirty(c.length > 0);
    bar.hidden = !c.length;
    bar.replaceChildren(h('span', { text: 'Изменено: ' + c.map((k) => VAR_INFO[k][0]).join(', ') }), btn('Отменить', () => { setDirty(false); route(); }, 'ghost'),
      btn('Сохранить', async () => {
        const vars = {};
        for (const k of changedKeys()) vars[k] = vals[k];
        if (await saveVars(vars, 'основные настройки')) { setDirty(false); toast('Сохранено. Перезапустите nfqws2, чтобы применить.'); route(); }
      }, 'primary'));
  };
  const hints = (k) => [VAR_INFO[k][1] ? h('span', { class: 'hint', text: VAR_INFO[k][1] }) : null, iss(k).map((x) => h('span', { class: 'hint row', style: `color:var(--${x.level === 'error' ? 'bad' : x.level === 'warning' ? 'warn' : 'accent'})` }, x.msg, fixButton(x)))];
  const input = (k) => [h('label', { class: 'lbl', for: 'f-' + k, text: VAR_INFO[k][0] }), h('input', { class: 'input mono', id: 'f-' + k, value: vals[k], spellcheck: 'false', oninput: (e) => { vals[k] = e.target.value; refresh(); } }), hints(k)];
  const toggle = (k) => [h('span', { class: 'lbl', text: VAR_INFO[k][0] }), h('label', { class: 'row' }, h('input', { type: 'checkbox', id: 'f-' + k, checked: vals[k] === '1', onchange: (e) => { vals[k] = e.target.checked ? '1' : '0'; refresh(); } }), h('span', { text: 'включено' })), hints(k)];
  content.append(h('div', { class: 'vh' }, h('h1', { text: 'Основное' })),
    h('section', { class: 'panel' },
      h('div', { class: 'frow' }, input('ISP_INTERFACE'), sys ? ifacePicker(sys.ifaces, () => vals.ISP_INTERFACE, (val) => { vals.ISP_INTERFACE = val; document.getElementById('f-ISP_INTERFACE').value = val; refresh(); }, 'f-ISP_INTERFACE') : null),
      h('div', { class: 'frow' }, h('label', { class: 'lbl', for: 'f-mode', text: VAR_INFO.NFQWS_EXTRA_ARGS[0] }),
        h('select', { class: 'select', id: 'f-mode', onchange: (e) => { vals.NFQWS_EXTRA_ARGS = e.target.value; refresh(); } }, MODES.map(([val, t]) => h('option', { value: val, text: t, selected: vals.NFQWS_EXTRA_ARGS === val }))), hints('NFQWS_EXTRA_ARGS')),
      h('div', { class: 'frow' }, input('TCP_PORTS')), h('div', { class: 'frow' }, input('UDP_PORTS')),
      h('div', { class: 'frow' }, toggle('IPV6_ENABLED')), h('div', { class: 'frow' }, toggle('LOG_LEVEL'))),
    bar);
}

// Что делают lua-скрипты из пакета zapret2
const LUA_INFO = {
  'zapret-lib.lua': 'базовая библиотека, нужна всегда',
  'zapret-antidpi.lua': 'приёмы обхода: fake, multisplit, hostfakesplit…',
  'zapret-auto.lua': 'автоматика: circular, перебор стратегий',
  'zapret-obfs.lua': 'обфускация протоколов',
  'zapret-pcap.lua': 'запись пакетов в pcap — для отладки',
  'zapret-tests.lua': 'самопроверка lua-движка — для отладки',
};

// Выбор интерфейса провайдера: автоопределение по маршруту по умолчанию и список интерфейсов роутера
function ifacePicker(ifaces, get, set, inputId) {
  const box = h('div', { class: 'iface-pick' });
  const list = () => get().trim().split(/\s+/).filter(Boolean);
  // «интернет» — интерфейсы с маршрутом по умолчанию; туннели (VPN) только если других нет
  const auto = () => {
    const d = ifaces.filter((x) => x.default.length && x.usable);
    const direct = d.filter((x) => x.kind !== 'tunnel');
    return (direct.length ? direct : d).map((x) => x.name);
  };
  const KIND = { ethernet: '', ppp: 'PPPoE', tunnel: 'туннель', bridge: 'мост', wifi: 'Wi-Fi' };
  const draw = () => {
    const cur = list();
    const a = auto();
    const missing = cur.filter((n) => !ifaces.some((x) => x.name === n));
    const item = (x) => h('button', { type: 'button', class: 'iface' + (cur.includes(x.name) ? ' on' : '') + (x.usable ? '' : ' dim'), 'aria-pressed': String(cur.includes(x.name)),
      title: (x.usable ? '' : 'Скорее всего не подходит: ' + (x.kind === 'bridge' ? 'мост локальной сети' : x.kind === 'wifi' ? 'точка доступа Wi-Fi' : 'нет адреса или это порт моста') + '. ') + 'Клик — добавить или убрать.',
      onclick: () => { const c = list(); set((c.includes(x.name) ? c.filter((n) => n !== x.name) : [...c, x.name]).join(' ')); draw(); } },
      h('span', { class: 'mono', text: x.name }),
      x.logical.length ? h('span', { class: 'muted', text: x.logical.join(', ') }) : null,
      x.default.length ? chip('интернет · ' + x.default.join('+'), 'ok') : null,
      KIND[x.kind] ? h('span', { class: 'muted', text: KIND[x.kind] }) : null,
      x.ips[0] ? h('span', { class: 'faint mono', text: x.ips[0] }) : null,
      x.up ? null : h('span', { class: 'status-bad', text: 'выключен' }));
    const good = ifaces.filter((x) => x.usable);
    const other = ifaces.filter((x) => !x.usable);
    box.replaceChildren(
      h('div', { class: 'row' },
        a.length ? btn('Определить автоматически', () => { set(a.join(' ')); draw(); }, 'small', 'wand', { title: 'Интерфейс маршрута по умолчанию: ' + a.join(', ') }) : h('span', { class: 'sm status-bad', text: 'Маршрута по умолчанию нет — интернет на роутере не настроен?' }),
        a.length && a.join(' ') === cur.join(' ') ? h('span', { class: 'sm status-ok', text: '✓ совпадает с маршрутом по умолчанию' }) : null),
      missing.length ? h('div', { class: 'sm status-bad', text: 'Нет на этом роутере: ' + missing.join(', ') + ' — nfqws2 не будет видеть трафик. Выберите интерфейс ниже.' }) : null,
      h('div', { class: 'iface-list' }, good.map(item)),
      other.length ? h('details', { class: 'sm' }, h('summary', { text: `другие интерфейсы (${other.length}) — мосты, порты, Wi-Fi` }), h('div', { class: 'iface-list' }, other.map(item))) : null);
  };
  draw();
  // ручная правка поля — перерисовать отметки
  if (inputId) setTimeout(() => document.getElementById(inputId)?.addEventListener('input', draw));
  return box;
}

// ---------- Параметры запуска ----------

async function paneBase(content) {
  const [sys] = await Promise.all([api('sysinfo').catch(() => null), loadCatalog()]);
  const orig = S.conf.vars.NFQWS_BASE_ARGS.trim().split(/\s+/).filter(Boolean).join('\n');
  let issues = (S.state.lint?.issues || []).filter((x) => x.var === 'NFQWS_BASE_ARGS');
  const help = h('div', { class: 'help' });
  const sug = createSuggest();
  const issBox = h('div', {});
  const bar = h('div', { class: 'savebar', hidden: true });
  const chipsLua = h('div', { class: 'chips' });
  const chipsBlob = h('div', { class: 'chips' });
  const adders = h('div', { class: 'row base-add' });
  const relint = debounce(async (val) => {
    const r = await api('lint', { vars: { NFQWS_BASE_ARGS: val } }).catch(() => null);
    if (!r) return;
    issues = r.issues.filter((x) => x.var === 'NFQWS_BASE_ARGS');
    ed.setIssues(issues);
    showIss();
  }, 700);
  const ed = codeEditor({ value: orig, mode: 'args', gutter: true, suggest: sug, maxHeight: 520,
    onInput: (val) => { setDirty(val !== orig); bar.hidden = val === orig; relint(val); drawParts(); },
    onCaret: (pos, t) => { const tk = tokenize(t).map((x, i) => ({ ...x, i })).find((x) => pos >= x.start && pos <= x.end); renderHelp(help, tk ? helpFor(tk.text, pos - tk.start) : null, tk ? issues.filter((x) => x.tok === tk.i) : null); } });
  // Уже подключённое — метками; что можно добавить — списками (только то, чего ещё нет)
  function drawParts() {
    const toks = tokensOf(ed.ta.value);
    const luaOn = toks.filter((t) => t.startsWith('--lua-init=@')).map((t) => base(t.slice(12)));
    const blobToks = toks.filter((t) => t.startsWith('--blob='));
    const blobNames = blobToks.map((t) => t.slice(7).split(':')[0]);
    const blobFiles = blobToks.map((t) => base(t.replace(/^[^@]*@/, '')));
    chipsLua.replaceChildren(...(luaOn.length ? luaOn.map((f) => chip(f, 'mono')) : [h('span', { class: 'sm muted', text: 'не подключены — стратегии --lua-desync работать не будут' })]));
    chipsBlob.replaceChildren(...(blobToks.length ? blobToks.map((t) => chip(t.slice(7).replace(/:@.*\//, ' → '), 'mono')) : [h('span', { class: 'sm muted', text: 'нет' })]));
    if (!sys) { adders.replaceChildren(); return; }
    const used = new Set(toks.map((t) => t.replace(/^--/, '').split('=')[0]));
    // задаёт init-скрипт (user, qnum, pidfile, debug, bind-fix), добавляются своими списками (lua-init, blob)
    // или ломают запуск: version/help/dry-run/intercept завершают процесс, fwmark расходится с метками в iptables
    const SKIP = ['user', 'uid', 'qnum', 'lua-init', 'blob', 'daemon', 'pidfile', 'dry-run', 'debug', 'bind-fix4', 'bind-fix6', 'version', 'help', 'intercept', 'fwmark'];
    const opts = Object.entries(S.catalog?.help?.options || {}).filter(([n, o]) => o.global && !SKIP.includes(n) && !used.has(n));
    const pick = (label, items, onPick) => h('select', { class: 'select', 'aria-label': label, disabled: !items.length, onchange: (e) => { const x = items[e.target.selectedIndex - 1]; e.target.selectedIndex = 0; if (x) onPick(x); } },
      h('option', { text: items.length ? label : label + ' — всё уже добавлено' }), items.map((x) => h('option', { text: x.text })));
    adders.replaceChildren(
      pick('+ lua-скрипт', sys.lua.filter((f) => !luaOn.includes(f)).map((f) => ({ f, text: f + (LUA_INFO[f] ? ' — ' + LUA_INFO[f] : '') })),
        (x) => addTok('--lua-init=@' + sys.lua_dir + '/' + x.f)),
      pick('+ блоб', sys.blobs.filter((f) => !blobFiles.includes(f)).map((f) => ({ f, text: f })),
        (x) => { let n = x.f.replace(/\.[^.]+$/, '').replace(/[^\w-]/g, '_'); while (blobNames.includes(n)) n += '_2'; addTok('--blob=' + n + ':@' + sys.blob_dir + '/' + x.f); toast('Блоб «' + n + '» — это имя подставляется в шаги: blob=' + n); }),
      pick('+ параметр', opts.map(([n, o]) => ({ n, o, text: o.syntax + (o.desc ? ' — ' + o.desc.slice(0, 70) : '') })),
        (x) => addTok('--' + x.n + (x.o.value ? '=' : ''), x.o.value)));
  }
  // Дописать аргумент в конец; если нужно значение — курсор после «=»
  function addTok(tok, needValue = false) {
    const cur = ed.ta.value.replace(/\s+$/, '');
    const text = (cur ? cur + '\n' : '') + tok;
    ed.setValue(text);
    setDirty(text !== orig); bar.hidden = text === orig; relint(text); drawParts();
    ed.ta.focus();
    ed.select(text.length, text.length);
    if (needValue) toast('Впишите значение после «=»');
  }
  const showIss = () => issBox.replaceChildren(issuesListEl(issues, (x) => { const t = tokenize(ed.ta.value)[x.tok]; if (t) ed.select(t.start, t.end); }));
  ed.setIssues(issues);
  showIss();
  drawParts();
  renderHelp(help, null);
  bar.append(h('span', { text: 'Изменены параметры запуска' }), btn('Отменить', () => { setDirty(false); route(); }, 'ghost'),
    btn('Сохранить', async () => { if (await saveVars({ NFQWS_BASE_ARGS: ed.ta.value }, 'параметры запуска')) { setDirty(false); toast('Сохранено. Перезапустите nfqws2.'); route(); } }, 'primary'));
  content.append(h('div', { class: 'vh' }, h('h1', { text: 'Параметры запуска' })),
    h('section', { class: 'panel' },
      h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Lua-скрипты' }), chipsLua),
      h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Блобы' }), chipsBlob,
        h('span', { class: 'hint', text: 'Имена блобов подставляются в шаги стратегий (blob=…).' })),
      h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Добавить' }), adders,
        h('span', { class: 'hint', text: 'В списках — только то, чего ещё нет. Строка допишется в конец, дальше её можно поправить в редакторе.' })),
      ed.el, sug.el, issBox, help),
    bar);
}

// ---------- Конфиг целиком ----------

async function paneRaw(content) {
  setFocus(overviewCol());   // конфигу нужна ширина: колонка обзора сворачивается
  const conf = S.conf;
  let issues = S.state.lint.issues;
  let dry = S.state.lint.dry_run;
  let findText = '';
  let findIdx = 0;
  const help = h('div', { class: 'help' });
  const sug = createSuggest();
  const outline = h('nav', { class: 'outline', 'aria-label': 'Структура конфига' });
  const status = h('div', { class: 'cfg-status' });
  const counts = h('span', { class: 'row' });
  const findN = h('span', { class: 'sm muted num' });
  const extra = h('div', { class: 'cfg-extra' });
  const banner = h('div', { class: 'cfg-adapt' });
  let caretInfo = 'строка 1, столбец 1';
  const tokAt = (pos, text) => {
    for (const [name, rg] of Object.entries(confVarRanges(text))) {
      if (pos < rg.start || pos > rg.end) continue;
      const toks = tokenize(text.slice(rg.start, rg.end));
      const i = toks.findIndex((t) => pos - rg.start >= t.start && pos - rg.start <= t.end);
      if (i >= 0) return { name, i, text: toks[i].text, start: rg.start + toks[i].start, end: rg.start + toks[i].end };
    }
    return null;
  };
  const ed = codeEditor({
    value: conf.content, mode: 'conf', maxHeight: 0, gutter: true, suggest: sug,
    onInput: (val) => { setDirty(val !== conf.content); relint(val); drawOutline(); drawStatus(); },
    onCaret: (pos, text) => {
      const before = text.slice(0, pos).split('\n');
      caretInfo = `строка ${before.length}, столбец ${before[before.length - 1].length + 1}`;
      drawStatus();
      const t = tokAt(pos, text);
      renderHelp(help, t ? helpFor(t.text, pos - t.start) : null, t ? issues.filter((x) => x.var === t.name && x.tok === t.i) : null);
    },
  });
  const locate = (x) => {
    const rg = confVarRanges(ed.ta.value)[x.var];
    if (!rg) return null;
    if (x.tok == null) return { start: rg.start, end: rg.start };
    const t = tokenize(ed.ta.value.slice(rg.start, rg.end))[x.tok];
    return t ? { start: rg.start + t.start, end: rg.start + t.end } : null;
  };
  function drawOutline() {
    const text = ed.ta.value;
    const ranges = [];
    for (const m of text.matchAll(/^([A-Z_][A-Z0-9_]*)=/gm)) ranges.push({ name: m[1], pos: m.index, line: text.slice(0, m.index).split('\n').length });
    outline.replaceChildren(h('div', { class: 'nav-h', text: 'Структура' }), ranges.map((r) => {
      const lvl = worst(issues.filter((x) => x.var === r.name));
      return h('button', { type: 'button', class: lvl || '', onclick: () => ed.select(r.pos, r.pos + r.name.length) }, r.name, h('span', { class: 'tail', text: (lvl ? '● ' : '') + r.line }));
    }));
  }
  function drawCounts() {
    const e = issues.filter((x) => x.level === 'error').length;
    const w = issues.filter((x) => x.level === 'warning').length;
    counts.replaceChildren(e ? chip(plural(e, 'ошибка', 'ошибки', 'ошибок'), 'bad') : null, w ? chip(plural(w, 'предупреждение', 'предупреждения', 'предупреждений'), 'warn') : null, !e && !w ? chip('ошибок нет', 'ok') : null,
      e || w ? btn('К ошибке ↓', () => { const x = issues.find((i) => i.level === 'error') || issues.find((i) => i.level === 'warning'); const p = x && locate(x); if (p) ed.select(p.start, p.end); }, 'small') : null);
  }
  function drawStatus() {
    const lines = ed.ta.value.split('\n').length;
    status.replaceChildren(h('span', { text: caretInfo }), h('span', { text: plural(lines, 'строка', 'строки', 'строк') }),
      dry ? h('span', { class: dry.ok ? 'status-ok' : 'status-bad', text: 'nfqws2 --dry-run: ' + (dry.ok ? 'пройдено' : dry.message) }) : h('span', { text: 'проверка: ошибка синтаксиса' }),
      h('span', { class: 'grow' }), h('span', { text: `сохранён ${fmtDate(conf.mtime)} · перед сохранением делается снимок` }));
  }
  // Конфиг от другой системы (Keenetic ↔ OpenWrt, zapret2) переделывается прямо в редакторе — сохраняет пользователь
  const adaptInEditor = async () => {
    const r = await guarded(() => api('conf_adapt', { content: ed.ta.value }));
    if (!r) return;
    const before = ed.ta.value;
    const put = (text) => { ed.setValue(text); setDirty(text !== conf.content); relint(text); drawOutline(); };
    put(r.text);
    toast(`В редакторе: ${plural(r.changes.length, 'правка', 'правки', 'правок')}. Проверьте и сохраните.`, { undo: () => put(before) });
  };
  const fixFor = (x) => x.fix?.op === 'adapt' ? btn(x.fix.label, adaptInEditor, 'small primary', 'wand') : fixButton(x);
  function drawBanner() {
    const x = issues.find((i) => i.fix?.op === 'adapt');
    banner.replaceChildren(x ? h('div', { class: 'notice ' + (x.level === 'error' ? 'bad' : 'warn') }, icon(x.level === 'error' ? 'bad' : 'alert'),
      h('div', { class: 'grow' }, h('b', { text: x.msg }),
        h('ul', { class: 't' }, x.fix.changes.map((c) => h('li', { class: 'mono', text: c }))),
        h('div', { class: 't sm', text: 'Правки попадут в редактор — проверьте их и нажмите «Сохранить».' })),
      h('div', { class: 'notice-actions' }, btn(x.fix.label, adaptInEditor, 'small primary', 'wand'))) : []);
  }
  function drawExtra() {
    drawBanner();
    extra.replaceChildren(issuesListEl(issues, (x) => { const p = locate(x); if (p) ed.select(p.start, p.end); }, (x) => { const p = locate(x); return (x.var || 'конфиг') + (p ? ', строка ' + ed.ta.value.slice(0, p.start).split('\n').length : ''); }, fixFor), sug.el, help);
  }
  const relint = debounce(async (val) => {
    const r = await api('lint_raw', { content: val }).catch(() => null);
    if (!r) return;
    issues = r.issues;
    dry = r.dry_run;
    ed.setIssues(issues);
    drawOutline(); drawCounts(); drawStatus(); drawExtra();
  }, 800);
  const find = h('input', { class: 'input', id: 'cfg-find', placeholder: 'Найти (Enter — следующее)', 'aria-label': 'Найти в конфиге', oninput: (e) => {
    findText = e.target.value;
    ed.setFind(findText);
    const n = findText ? ed.ta.value.toLowerCase().split(findText.toLowerCase()).length - 1 : 0;
    findN.textContent = findText ? plural(n, 'совпадение', 'совпадения', 'совпадений') : '';
    findIdx = 0;
  } });
  find.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' || !findText) return;
    e.preventDefault();
    const low = ed.ta.value.toLowerCase();
    let i = low.indexOf(findText.toLowerCase(), findIdx);
    if (i < 0) i = low.indexOf(findText.toLowerCase());
    if (i >= 0) { ed.select(i, i + findText.length); findIdx = i + 1; find.focus(); }
  });
  document.addEventListener('keydown', function onKey(e) {
    if (!find.isConnected) { document.removeEventListener('keydown', onKey); return; }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') { e.preventDefault(); find.focus(); find.select(); }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(); }
  });
  const save = async (force = false) => {
    try {
      await api('conf_save_raw', { content: ed.ta.value, force, note: 'правка конфига текстом' });
      toast('Конфиг сохранён. Перезапустите nfqws2, чтобы применить.');
      setDirty(false);
      await loadState();
      await loadConf();
      route();
    } catch (e) {
      if (e.data?.issues) {
        issues = e.data.issues; dry = e.data.dry_run;
        ed.setIssues(issues); drawOutline(); drawCounts(); drawStatus(); drawExtra();
        if (e.data.fatal) toast('Не сохранено: nfqws2 с таким конфигом не запустится', { err: true });
        else if (confirm('В конфиге есть ошибки (подсвечены). nfqws2 запустится, но эти места будут работать неправильно. Сохранить всё равно?')) save(true);
      } else toast(e.message, { err: true });
    }
  };
  const tools = h('div', { class: 'cfg-tools' }, find, findN, counts,
    btn('По аргументу на строку', () => {
      let text = ed.ta.value;
      for (const [name, rg] of Object.entries(confVarRanges(text)).filter(([n]) => ARG_VARS.includes(n)).sort((a, b) => b[1].start - a[1].start)) {
        const indent = ' '.repeat(name.length + 2);
        text = text.slice(0, rg.start) + tokensOf(text.slice(rg.start, rg.end)).join('\n' + indent) + text.slice(rg.end);
      }
      ed.setValue(text); setDirty(text !== conf.content); relint(text); drawOutline();
    }, 'small'),
    h('span', { class: 'grow' }),
    btn('Сравнить с сохранённым', () => openDiff('nfqws2.conf: изменения', conf.content, ed.ta.value), 'small'),
    btn('История', () => openHistory('nfqws2.conf', () => route(true)), 'small', 'history'),
    btn('Сохранить', () => save(), 'primary small'));
  ed.setIssues(issues);
  content.append(h('div', { class: 'vh' }, h('h1', { text: 'Конфиг целиком' }), h('span', { class: 'sm muted mono', text: S.state?.ui?.conf_file || 'nfqws2.conf' }), h('span', { class: 'grow' }),
    overviewCol() ? btn('Показать обзор', () => setFocus(false), 'small') : null),
  h('div', { class: 'cfg' }, tools, banner, outline, h('div', { class: 'cfg-body' }, ed.el), status, extra));
  drawOutline(); drawCounts(); drawStatus(); drawExtra();
}

// ---------- Резервные копии ----------

async function paneBackup(content) {
  const data = await api('snapshots');
  const reasonText = { 'вручную': 'вручную', 'перед изменением': 'перед изменением', 'ежедневный': 'ежедневный', 'перед восстановлением': 'перед восстановлением', 'исходный': 'исходный' };
  const fileInput = h('input', { type: 'file', accept: '.tar.gz,.tgz,application/gzip', hidden: true, onchange: async (e) => {
    const f = e.target.files[0];
    if (!f) return;
    if (!confirm(`Восстановить конфиг и списки из «${f.name}»? Текущее состояние сохранится снимком.`)) return;
    const fd = new FormData();
    fd.append('archive', f);
    const r = await fetch('api.php?upload=restore', { method: 'POST', body: fd, credentials: 'same-origin' });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) { toast(j.error || 'Не удалось восстановить', { err: true }); return; }
    toast(j.changed.length ? `Восстановлено файлов: ${j.changed.length}. Перезапустите nfqws2.` : 'Архив совпадает с текущим состоянием');
    route(true);
  } });
  const maxCount = h('input', { class: 'input num', type: 'number', min: 5, max: 500, value: data.settings.max_count, style: 'width:90px', id: 'snap-count', 'aria-label': 'Не больше снимков' });
  const maxDays = h('input', { class: 'input num', type: 'number', min: 1, max: 3650, value: data.settings.max_days, style: 'width:90px', id: 'snap-days', 'aria-label': 'Хранить дней' });
  const rows = data.items.map((e) => h('tr', {},
    h('td', { class: 'num nowrap', text: fmtDate(e.ts) }),
    h('td', {}, reasonText[e.reason] || e.reason, e.note ? h('div', { class: 'sm muted ellipsis', style: 'max-width:40ch', text: e.note }) : null),
    h('td', { class: 'sm' }, e.differs.length ? h('span', { class: 'muted', text: 'отличается: ' + e.differs.map(base).slice(0, 4).join(', ') + (e.differs.length > 4 ? ` и ещё ${e.differs.length - 4}` : '') }) : h('span', { class: 'status-ok', text: 'как сейчас' })),
    h('td', { class: 'num', text: fmtBytes(e.size) }),
    h('td', { class: 'row', style: 'flex-wrap:nowrap;gap:2px' },
      btn('Сравнить', () => compareSnapshot(e), 'small ghost', null, { disabled: !e.differs.length }),
      btn('Восстановить', async () => {
        if (!confirm(`Вернуть конфиг и списки к снимку от ${fmtDate(e.ts)}? Текущее состояние сохранится отдельным снимком.`)) return;
        const r = await guarded(() => api('snapshot_restore', { id: e.id }));
        if (r) { toast(`Восстановлено файлов: ${r.changed.length}. Перезапустите nfqws2.`); route(true); }
      }, 'small ghost', null, { disabled: !e.differs.length }),
      h('a', { class: 'btn small ghost icon', href: 'api.php?download=' + e.id, title: 'Скачать', 'aria-label': 'Скачать снимок' }, icon('download')),
      btn('', async () => { await guarded(() => api('snapshot_pin', { id: e.id, pinned: !e.pinned })); route(); }, 'small ghost icon' + (e.pinned ? ' primary' : ''), 'pin', { title: e.pinned ? 'Закреплён — не удаляется автоматически' : 'Закрепить', 'aria-label': 'Закрепить' }))));
  content.append(
    h('div', { class: 'vh' }, h('h1', { text: 'Резервные копии' }), h('span', { class: 'grow' }),
      btn('Создать снимок сейчас', async () => { const note = prompt('Подпись к снимку (необязательно):', ''); if (note === null) return; if (await guarded(() => api('snapshot_create', { note }), 'Снимок создан')) route(true); }, 'primary', 'plus')),
    h('div', { class: 'cards2' },
      panel('На роутере', chip(`${plural(data.items.length, 'снимок', 'снимка', 'снимков')} · ${fmtBytes(data.total)}`, 'num'),
        h('p', { class: 'sm muted', text: 'Снимок — конфиг, все списки, блобы и настройки интерфейса. Создаётся перед изменениями через интерфейс (не чаще раза в 15 минут), раз в сутки в 04:05 и вручную.' }),
        h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Хранить' }), h('div', { class: 'row' }, maxDays, h('span', { class: 'sm', text: 'дней, не больше' }), maxCount, h('span', { class: 'sm', text: 'снимков' }),
          btn('Применить', async () => { if (await guarded(() => api('settings_set', { snapshots: { max_count: maxCount.value, max_days: maxDays.value } }), 'Сохранено')) route(); }, 'small'))),
        h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Где' }), h('span', { class: 'mono sm', text: data.dir })),
        h('p', { class: 'sm faint', text: 'Пять последних снимков и закреплённые не удаляются никогда.' })),
      panel('Вне роутера', null,
        h('div', { class: 'row' }, h('a', { class: 'btn', href: 'api.php?download=current' }, icon('download'), 'Скачать архив'),
          h('label', { class: 'btn' }, icon('upload'), 'Восстановить из файла', fileInput)),
        h('p', { class: 'sm muted', text: 'Архив .tar.gz сохраняется на устройство, с которого открыт интерфейс — ПК или телефон. Из него же можно восстановить: перед этим делается снимок текущего состояния.' }),
        h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Копия на NAS' }), h('span', { class: 'sm muted', text: 'настраивается следующим этапом — нужен ключ доступа к NAS' })))),
    panel('Снимки на роутере', null, data.items.length ? h('div', { class: 'scroll' }, h('table', { class: 'tbl' },
      h('thead', {}, h('tr', {}, h('th', { text: 'Когда' }), h('th', { text: 'Почему' }), h('th', { class: 'wide', text: 'По сравнению с текущим' }), h('th', { class: 'num', text: 'Размер' }), h('th'))),
      h('tbody', {}, rows))) : h('p', { class: 'muted', text: 'Снимков пока нет.' })));
}

async function compareSnapshot(e) {
  const files = e.differs;
  const body = h('div', { class: 'modal-b' });
  const bg = modal(`Снимок от ${fmtDate(e.ts)}: отличия`, body, []);
  const pick = h('select', { class: 'select', 'aria-label': 'Файл', onchange: () => show(pick.value) }, files.map((f) => h('option', { value: f, text: base(f) })));
  const box = h('div', { class: 'stack' });
  body.replaceChildren(h('div', { class: 'stack' }, h('div', { class: 'row' }, h('span', { class: 'sm muted', text: 'Файл:' }), pick), box));
  async function show(f) {
    box.replaceChildren(spinner('Загрузка…'));
    const r = await api('snapshot_file', { id: e.id, file: f }).catch((x) => ({ error: x.message }));
    if (r.error) { box.replaceChildren(notice('warn', 'Файла нет в снимке', 'Он появился позже, чем сделан снимок.')); return; }
    box.replaceChildren(h('p', { class: 'sm muted', text: r.current === null ? 'Сейчас этого файла нет.' : 'Красным — как было в снимке, зелёным — как сейчас.' }), renderDiff(diffLines(r.content, r.current ?? '')));
  }
  show(files[0]);
  return bg;
}

// ---------- История изменений ----------

async function paneHistory(content, r) {
  const fileFilter = r.q.get('file') || '';
  const data = await api('history_log', { file: fileFilter, limit: 300 });
  const files = [...new Set((await api('history_log', { limit: 1000 })).items.map((e) => e.file).filter((f) => f !== '*'))].sort();
  const srcIcon = { 'интерфейс': ['edit', 'info'], 'вне интерфейса': ['alert', 'warning'], 'обновление пакета': ['download', 'info'], 'восстановление': ['history', 'info'] };
  const box = h('div', {});
  let day = '';
  for (const e of data.items) {
    const d = fmtDay(e.ts);
    if (d !== day) { box.append(h('div', { class: 'tday', text: d })); day = d; }
    const [ic, lvl] = srcIcon[e.source] || ['info', 'info'];
    const detail = h('details', { ontoggle: async (ev) => {
      if (!ev.target.open || ev.target.dataset.loaded) return;
      ev.target.dataset.loaded = '1';
      const df = await api('history_diff', { id: e.id }).catch(() => null);
      if (df) ev.target.append(renderDiff(diffLines(df.from ?? '', df.to ?? '')));
    } }, h('summary', { text: 'что изменилось' }));
    const summary = e.file === '*' ? e.note : [e.file === 'nfqws2.conf' ? 'Конфиг' : listName(e.file), e.to === null ? 'удалён' : e.from === null ? 'создан' : null, e.note].filter(Boolean).join(': ');
    box.append(h('div', { class: 'tev' },
      h('span', { class: 'tm', text: fmtTime(e.ts) }),
      h('span', { class: 'lvl ' + lvl }, icon(ic)),
      h('div', { class: 'item-main' }, h('div', { text: summary }),
        h('div', { class: 'who' }, e.source, e.n_added || e.n_removed ? ` · +${e.n_added || 0} −${e.n_removed || 0} строк` : ''),
        e.file !== '*' ? detail : null),
      e.file !== '*' && e.path ? btn('Откатить', async () => {
        if (!confirm(`Вернуть ${e.file} к состоянию до этого изменения (${fmtDate(e.ts)})? Текущая версия останется в истории.`)) return;
        if (await guarded(() => api('history_revert', { id: e.id }), 'Изменение откачено')) route(true);
      }, 'small') : h('span')));
  }
  content.append(h('div', { class: 'vh' }, h('h1', { text: 'История изменений' }), h('span', { class: 'grow' }),
    S.state.undo ? btn('Отменить последнее', () => undoLast(true), 'small', 'undo', { title: S.state.undo.note }) : null,
    S.state.rollback?.files.length ? btn('Вернуть рабочее состояние', rollbackToWorking, 'small', 'history', { title: 'На ' + fmtDate(S.state.rollback.since) + ': ' + S.state.rollback.files.join(', ') }) : null,
    h('select', { class: 'select', 'aria-label': 'Файл', onchange: (ev) => go('#/settings/hist' + (ev.target.value ? '?file=' + encodeURIComponent(ev.target.value) : '')) },
      h('option', { value: '', text: 'Все файлы' }), files.map((f) => h('option', { value: f, text: f, selected: f === fileFilter })))),
  panel(null, null, h('p', { class: 'sm muted', text: 'Все правки конфига и списков: через интерфейс, по SSH и при обновлении пакета (правки вне интерфейса находятся каждые 10 минут и при каждом открытии). Откат возвращает файл к состоянию до выбранного изменения.' }),
    data.items.length ? box : h('p', { class: 'muted', text: 'Изменений пока нет.' })));
}

// ---------- О программе и обновления ----------

async function paneAbout(content) {
  const st = S.state;
  const u = st.ui?.update || {};
  const link = (href, text) => h('a', { href, target: '_blank', rel: 'noopener', text });
  const row = (label, ...v) => h('div', { class: 'frow' }, h('span', { class: 'lbl', text: label }), h('span', {}, ...v));
  const check = async () => {
    const r = await guarded(() => api('update_check', { force: true }));
    if (!r) return;
    S.state.ui.update = { ...u, ...r };
    updateChrome();
    toast(r.error ? 'Не удалось проверить: ' + r.error : r.available ? `Есть новая версия: ${r.latest}` : 'У вас последняя версия');
    route();
  };
  const cmd = (c, text) => h('div', { class: 'frow cmd-row' }, h('code', { class: 'lbl mono', text: c }), h('span', { class: 'sm', text }));
  content.append(h('div', { class: 'vh' }, h('h1', { text: 'О программе' })),
    panel(null, null,
      h('div', { class: 'about-brand' }, logo(48), h('div', {}, h('h2', { text: 'nfqws2-ui' }), h('div', { class: 'sm muted', text: 'веб-интерфейс для nfqws2 · zemidala' }))),
      row('Версия', h('b', { text: st.ui?.version || '?' }), BUILD.includes('-') ? h('span', { class: 'muted mono', title: 'Хеш файлов интерфейса, загруженных в браузер', text: '  сборка ' + BUILD.split('-').pop() }) : null),
      row('nfqws2', st.version ? 'v' + st.version : 'не определена'),
      row('Исходный код', link(REPO, REPO.replace('https://', ''))),
      row('Описание и помощь', h('a', { href: '#/settings/readme', text: 'справка (README)' }), ' · ', h('a', { href: '#/settings/changelog', text: 'изменения по версиям' }), ' · ', link(REPO + '/discussions/categories/q-a', 'задать вопрос'), ' · ', link(REPO + '/issues/new/choose', 'сообщить о проблеме'), ' · ', link('https://t.me/nfqws2_ui', 'чат в Telegram')),
      row('Лицензия', 'MIT')),
    panel('Обновления', null,
      u.available
        ? notice('info', `Доступна версия ${u.latest}`, 'Обновление ставит пакет с GitHub с проверкой контрольной суммы. nfqws2, конфиг и списки не затрагиваются.',
          h('div', { class: 'notice-actions' }, btn('Что нового', () => openUpdate(u), 'small'), u.can_update ? btn('Обновить', () => openUpdate(u, true), 'small primary', 'download', { disabled: u.running }) : null))
        : u.latest ? notice('ok', 'У вас последняя версия', null) : null,
      row('Последняя проверка', u.checked ? fmtAgo(Date.now() / 1000 - u.checked) : 'ещё не было', u.error ? h('span', { class: 'status-bad', text: ' — ' + u.error }) : null),
      h('div', { class: 'row' }, btn('Проверить сейчас', check, 'small', 'refresh'), link(u.url || REPO + '/releases', 'все релизы')),
      h('p', { class: 'sm muted', text: 'Интерфейс сам проверяет обновления раз в 12 часов и раз в сутки по расписанию. Если вышла новая версия, сверху появится плашка.' })),
    panel('Из консоли роутера', null,
      cmd('nfqws-ui update', 'обновить до последней версии'),
      cmd('nfqws-ui update --check', 'только проверить'),
      cmd('nfqws-ui update v1.0.0 --force', 'поставить конкретную версию (откат)'),
      cmd('nfqws-ui status', 'адреса, порты, HTTPS'),
      cmd('nfqws-ui help', 'все команды; nfqws-ui — короткое имя для nfqws-ui-setup')));
}

// ---------- Оформление ----------

// Схемы компоновок для выбора
const LAYOUT_THUMB = {
  menu: '<rect width="120" height="68" rx="4" fill="var(--bg)" stroke="var(--line)"/><rect width="120" height="10" rx="4" fill="var(--surface)" stroke="var(--line)"/><rect y="10" width="30" height="58" fill="var(--surface)" stroke="var(--line)"/><path d="M5 17h18M5 23h14M5 35h18M5 41h12M5 47h16M5 59h14" stroke="var(--faint)" stroke-width="2" stroke-linecap="round"/><rect x="3" y="26" width="24" height="6" rx="2" fill="var(--accent-soft)"/><rect x="36" y="16" width="78" height="20" rx="2" fill="var(--surface)" stroke="var(--line)"/><rect x="36" y="40" width="78" height="22" rx="2" fill="var(--surface)" stroke="var(--line)"/>',
  tabs: '<rect width="120" height="68" rx="4" fill="var(--bg)" stroke="var(--line)"/><rect width="120" height="10" rx="4" fill="var(--surface)" stroke="var(--line)"/><rect x="30" y="3" width="50" height="4" rx="2" fill="var(--accent)"/><rect y="10" width="28" height="58" fill="var(--surface)" stroke="var(--line)"/><rect x="34" y="16" width="44" height="3" rx="1.5" fill="var(--faint)"/><rect x="34" y="24" width="80" height="16" rx="2" fill="var(--surface)" stroke="var(--line)"/><rect x="34" y="44" width="80" height="18" rx="2" fill="var(--surface)" stroke="var(--line)"/>',
  site: '<rect width="120" height="68" rx="4" fill="var(--bg)" stroke="var(--line)"/><rect width="120" height="10" rx="4" fill="var(--surface)" stroke="var(--line)"/><rect x="30" y="3" width="40" height="4" rx="2" fill="var(--accent)"/><rect x="88" y="3" width="26" height="4" rx="2" fill="var(--line)"/><rect x="18" y="16" width="84" height="6" rx="2" fill="var(--accent-soft)"/><rect x="18" y="26" width="84" height="36" rx="2" fill="var(--surface)" stroke="var(--line)"/><path d="M22 34h76M22 41h76M22 48h76M22 55h76" stroke="var(--line)"/>',
};

async function paneLook(content) {
  const look = getLook();
  const card = (v, title, text, thumb) => h('label', { class: 'look' }, h('input', { type: 'radio', name: 'look', value: v, checked: look.variant === v, onchange: () => { setLook({ variant: v }); } }), thumb, h('b', { text: title }), h('span', { class: 'sm muted', text }));
  const i = (cls, style) => h('i', { class: cls, style });
  // Компоновка: где показаны переходы между страницами. Страницы и их адреса одни и те же.
  const setLayout = (v) => { setLook({ layout: v }); renderShell(); route(); };
  const lay = (v, title, text) => h('label', { class: 'look' }, h('input', { type: 'radio', name: 'layout', value: v, checked: look.layout === v, onchange: () => setLayout(v) }),
    svgEl('0 0 120 68', LAYOUT_THUMB[v], 'lthumb'), h('b', { text: title }), h('span', { class: 'sm muted', text }));
  content.append(h('div', { class: 'vh' }, h('h1', { text: 'Оформление' })),
    panel(null, null,
      h('label', { class: 'row', style: 'align-items:flex-start' }, h('input', { type: 'checkbox', checked: !!look.simple, style: 'margin-top:3px', onchange: (e) => { setLook({ simple: e.target.checked }); renderShell(); route(); } }),
        h('span', {}, h('b', { text: 'Упрощённый вид' }), h('br'), h('span', { class: 'sm muted', text: 'Боковое меню — шесть разделов, страницы раздела — вкладками над страницей; «Обзор» по умолчанию «Кратко»; обновить, HTTPS, GitHub и выход — под кнопкой «⋯». Для тех, кому полный вид кажется перегруженным. Функции те же.' })))),
    panel(null, null,
      h('fieldset', { class: 'looks' }, h('legend', { class: 'sm muted', text: 'Компоновка — как разложены разделы' }),
        lay('menu', 'Боковое меню', 'Все разделы списком слева, любой — в один клик.'),
        lay('tabs', 'Вкладки по задачам', 'Вкладки сверху, слева постоянная колонка обзора.'),
        lay('site', 'Вокруг сайта', 'Таблица сайтов и карточка сайта: проверка, подбор, трассировка рядом.'))),
    panel(null, null,
      h('fieldset', { class: 'looks' }, h('legend', { class: 'sm muted', text: 'Стиль интерфейса' }),
        card('a', 'A · Консоль', 'Плотно, панель с разделителями. Больше всего данных на экране.', h('span', { class: 'thumb th-a' }, h('span', { class: 'tl' }, i('acc'), i(), i(), i()), h('span', { class: 'tr' }, i(), i('', 'width:70%'), i('acc', 'width:40%')))),
        card('b', 'B · Карточки', 'Мягкие карточки и воздух. Удобнее на телефоне.', h('span', { class: 'thumb th-b' }, h('span', { class: 'tl' }, i(), i(), i('acc')), h('span', { class: 'tr' }, i(), i()))),
        card('c', 'C · Схема', 'Путь соединения через профили — главный объект.', h('span', { class: 'thumb th-c' }, h('span', { class: 'tl' }, i(), i(), i()), h('span', { class: 'tr' }, [0, 1, 2].map((k) => h('span', { class: 'pl' }, h('span', { class: 'nd' }), i(k === 0 ? 'acc' : '')))))),
        card('k', 'K · Keenetic', 'Как веб-интерфейс Keenetic: светлое меню с разделами, белые карточки, синие кнопки.', h('span', { class: 'thumb th-k' }, h('span', { class: 'tl' }, i('acc'), i(), i(), i('acc'), i()), h('span', { class: 'tr' }, i('card'), i('card'))))),
      h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Тема' }),
        h('div', { class: 'seg', role: 'radiogroup', 'aria-label': 'Тема' }, [['auto', 'Как в системе'], ['light', 'Светлая'], ['dark', 'Тёмная']].map(([m, t]) =>
          h('button', { type: 'button', role: 'radio', 'aria-checked': String(look.mode === m), class: look.mode === m ? 'on' : '', text: t, onclick: () => { setLook({ mode: m }); route(); } })))),
      h('p', { class: 'sm muted', text: 'Выбор хранится в этом браузере: на телефоне и компьютере можно выбрать разное.' })));
}

// ============ Тесты ============

let testPoll = null;
async function viewMonitor(main) {
  const m = await api('monitor_get');
  const cfg = m.settings;
  let sites = [...cfg.sites];
  const add = h('input', { class: 'input mono grow', id: 'mon-add', placeholder: 'добавить сайт, например rutracker.org', autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'Добавить сайт' });
  const interval = h('select', { class: 'select', 'aria-label': 'Как часто' }, [10, 30, 60, 180, 720].map((n) => h('option', { value: n, text: n < 60 ? `каждые ${n} мин` : `каждые ${n / 60} ч`, selected: n === cfg.interval })));
  const enabled = h('input', { type: 'checkbox', id: 'mon-on', checked: cfg.enabled });
  const listBox = h('div', { class: 'stack', style: 'gap:0' });
  const nextInfo = h('p', { class: 'sm muted' });
  const checking = new Set();
  const save = async (extra = {}) => guarded(() => api('monitor_set', { sites, interval: Number(interval.value), enabled: enabled.checked, ...extra }));
  // Проверка отдельных сайтов сразу — результат попадает в историю мониторинга
  const checkNow = async (hosts) => {
    hosts.forEach((x) => checking.add(x));
    draw();
    const r = await guarded(() => api('monitor_run', { hosts }));
    hosts.forEach((x) => checking.delete(x));
    if (r?.data) m.data = r.data;
    draw();
    loadState().catch(() => {});
  };
  const drawNext = () => {
    const now = Math.floor(Date.now() / 1000);
    if (!enabled.checked) { nextInfo.textContent = 'Автоматические проверки выключены — сайты проверяются только кнопками.'; return; }
    const next = (m.data.last || 0) + Number(interval.value) * 60;
    const mins = Math.max(0, Math.round((next - now) / 60));
    nextInfo.textContent = (m.data.last ? `Последняя автоматическая проверка ${fmtAgo(now - m.data.last)}. ` : '') +
      (mins <= 1 ? 'Следующая — в ближайшие 10 минут.' : `Следующая ≈ через ${mins} мин (плюс-минус 10 минут: расписание проверяется раз в 10 минут).`);
  };
  const draw = () => { drawNext(); listBox.replaceChildren(sites.length ? sites.map((host, i) => {
    const hist = m.data.sites[host] || [];
    const last = hist[hist.length - 1];
    const okCount = hist.filter((x) => x[1]).length;
    if (checking.has(host)) {
      return h('div', { class: 'act' }, h('span', { class: 'spin' }), h('div', { class: 'item-main' }, h('b', { class: 'mono', text: host }), h('span', { class: 'sm muted', text: 'проверяю…' })));
    }
    return h('div', { class: 'act mon-site' },
      levelIcon(!last ? 'info' : !last[1] ? 'error' : last[2] > 5000 ? 'warning' : 'ok'),
      h('div', { class: 'item-main' }, h('b', { class: 'mono', text: host }),
        h('span', { class: 'sm muted', text: last ? (last[1] ? (last[2] > 5000 ? `открывается медленно, ${(last[2] / 1000).toFixed(1).replace(".", ",")} с` : `открывается, ${last[2]} мс`) : `не открывается: ${last[3]}`) + ` · ${fmtAgo(Math.floor(Date.now() / 1000) - last[0])}` + (hist.length ? ` · доступность ${Math.round(okCount / hist.length * 100)}% за ${plural(hist.length, 'проверку', 'проверки', 'проверок')}` : '') : 'ещё не проверялся' })),
      uptimeBar(hist.slice(-48).map((x) => x[1])),
      btn('Проверить', () => checkNow([host]), 'small ghost chk-now', 'refresh', { title: 'Проверить сейчас и записать результат в историю', 'aria-label': 'Проверить ' + host }),
      btn('', () => checkHost(host), 'small icon ghost', 'search', { title: 'Подробно: какой профиль обрабатывает сайт', 'aria-label': 'Подробно о ' + host }),
      btn('', async () => { sites.splice(i, 1); await save(); draw(); }, 'small icon ghost', 'x', { title: 'Убрать из мониторинга', 'aria-label': 'Убрать ' + host }));
  }) : h('p', { class: 'muted', text: 'Сайтов нет — добавьте хотя бы один.' })); };
  const addSite = async () => {
    const hs = add.value.split(/[\s,]+/).filter(Boolean);
    if (!hs.length) return;
    const before = new Set(sites);
    sites = [...new Set([...sites, ...hs])];
    add.value = '';
    if (!await save()) return;
    const mm = await api('monitor_get');
    sites = mm.settings.sites;
    // новые сайты проверяем сразу, не дожидаясь расписания
    const added = sites.filter((x) => !before.has(x));
    if (added.length) checkNow(added); else draw();
  };
  add.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); addSite(); } });
  draw();
  main.append(
    panel('Сайты', h('div', { class: 'row' },
      h('label', { class: 'row sm' }, enabled, 'включён'), interval,
      btn('Проверить все сейчас', async () => { toast('Проверяю…'); const r = await guarded(() => api('monitor_run')); if (r) { m.data = r.data; draw(); await loadState(); } }, 'small', 'refresh')),
      h('p', { class: 'sm muted', text: 'Сайты открываются с самого роутера через основной nfqws2. История — последние 96 проверок. При смене состояния «открывается ↔ нет» может прийти сообщение в Telegram — см. «Уведомления».' }),
      nextInfo,
      h('div', { class: 'row' }, add, btn('Добавить', addSite, 'primary', 'plus')), listBox));
  [interval, enabled].forEach((x) => x.addEventListener('change', () => { save(); drawNext(); }));
}

// Уведомления в Telegram о сайтах из мониторинга
async function viewNotify(main) {
  const m = await api('monitor_get', { ifaces: true });
  const tgToken = h('input', { class: 'input mono', id: 'tg-token', type: 'password', placeholder: m.tg.token_set ? 'задан — оставьте пустым, чтобы не менять' : '123456:ABC…', autocomplete: 'off', 'aria-label': 'Токен бота' });
  const tgChat = h('input', { class: 'input mono grow', id: 'tg-chat', value: m.tg.chat, placeholder: 'ID чата, например 123456789', 'aria-label': 'ID чата' });
  // Каким путём слать: api.telegram.org у многих закрыт, тогда нужен туннель или прокси
  const KIND = { tunnel: 'туннель', ppp: 'PPP', ethernet: '' };
  const ifaces = m.ifaces || [];
  // сохранённого интерфейса сейчас может не быть (туннель выключен) — показываем его, чтобы выбор не потерялся молча
  if (m.tg.iface && !ifaces.some((x) => x.name === m.tg.iface)) ifaces.push({ name: m.tg.iface, kind: '', ips: [], gone: true });
  const via = h('select', { class: 'select', id: 'tg-via', onchange: () => drawVia() },
    [['', 'как обычно'], ['iface', 'через интерфейс (туннель)'], ['proxy', 'через прокси']].map(([v, t]) => h('option', { value: v, text: t, selected: v === m.tg.via })));
  const iface = h('select', { class: 'select mono', id: 'tg-iface', 'aria-label': 'Интерфейс' },
    ifaces.length ? null : h('option', { value: '', text: 'нет интерфейсов с адресом' }),
    ifaces.map((x) => h('option', { value: x.name, selected: x.name === m.tg.iface,
      text: x.name + (x.gone ? ' — сейчас не найден' : [KIND[x.kind], x.logical?.filter((n) => n !== x.name).join(', '), x.default?.length ? 'основной выход в интернет' : ''].filter(Boolean).map((t) => ' · ' + t).join('')) })));
  const proxy = h('input', { class: 'input mono', id: 'tg-proxy', value: m.tg.proxy, placeholder: 'socks5://192.168.1.10:1080 или http://адрес:порт', autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'Адрес прокси' });
  const viaIface = h('div', { class: 'frow' }, h('label', { class: 'lbl', for: 'tg-iface', text: 'Интерфейс' }), iface,
    h('p', { class: 'hint', text: 'Запрос уйдёт через этот интерфейс, если через него есть маршрут до Telegram. Выберите туннель (WireGuard, AmneziaWG, OpenVPN), который ведёт туда, где Telegram открыт.' }));
  const viaProxy = h('div', { class: 'frow' }, h('label', { class: 'lbl', for: 'tg-proxy', text: 'Прокси' }), proxy,
    h('p', { class: 'hint', text: 'SOCKS5 или HTTP-прокси. С логином: socks5://логин:пароль@адрес:порт — пароль хранится на роутере и в интерфейсе не показывается.' }));
  const drawVia = () => { viaIface.hidden = via.value !== 'iface'; viaProxy.hidden = via.value !== 'proxy'; };
  drawVia();
  const save = () => guarded(() => api('monitor_set', { tg_token: tgToken.value || '••••', tg_chat: tgChat.value, tg_iface: iface.value, tg_proxy: proxy.value, tg_via: via.value }));
  // «Найти»: роутер сам спрашивает у Telegram, кто недавно писал боту, — ID чата не нужно узнавать на стороне
  const TG_TYPE = { private: 'личный чат', group: 'группа', supergroup: 'группа', channel: 'канал' };
  const found = h('div', { class: 'stack', style: 'gap:6px', hidden: true });
  const findChats = async () => {
    if (!await save()) return;
    found.hidden = false;
    found.replaceChildren(spinner('Спрашиваю у Telegram…'));
    const r = await api('tg_chats').catch((e) => ({ error: e.message }));
    if (r.error) { found.replaceChildren(notice('bad', 'Не получилось', r.error)); return; }
    if (!r.chats.length) {
      found.replaceChildren(notice('info', 'Боту пока никто не писал', 'Откройте своего бота в Telegram, отправьте ему любое сообщение (в группе — команду /start) и нажмите «Найти» ещё раз. Telegram хранит сообщения сутки.'));
      return;
    }
    found.replaceChildren(h('span', { class: 'sm muted', text: 'Кто писал боту за последние сутки — нажмите нужный чат:' }),
      h('div', { class: 'chips' }, r.chats.map((c) => h('button', { type: 'button', class: 'chip add', title: 'Подставить этот ID', onclick: () => { tgChat.value = c.id; found.hidden = true; toast(`ID чата: ${c.id}. Нажмите «Отправить проверочное».`); } },
        `${c.name || (c.username ? '@' + c.username : 'без имени')} · ${TG_TYPE[c.type] || c.type} · `, h('b', { class: 'mono', text: c.id })))));
  };
  // Какой бот подключён и куда он пишет: по точкам вместо токена и числу ID этого не понять
  const who = h('div', { class: 'frow', hidden: true });
  const drawWho = async () => {
    const r = await api('tg_info').catch((e) => ({ error: e.message }));
    if (!main.contains(who)) return;
    who.hidden = !r.bot && !r.error;
    if (who.hidden) return;
    const chatName = (c) => c.name || (c.username ? '@' + c.username : 'без имени');
    who.replaceChildren(h('span', { class: 'lbl', text: 'Подключён' }), r.error
      ? h('span', { class: 'sm', style: 'color:var(--bad)', text: r.error })
      : h('span', { class: 'sm' }, 'бот ', h('b', { text: r.bot.username ? '@' + r.bot.username : r.bot.name }), r.bot.username && r.bot.name ? ` «${r.bot.name}»` : '',
        r.chat ? [' → пишет в ', h('b', { text: chatName(r.chat) }), ` (${TG_TYPE[r.chat.type] || r.chat.type})`]
          : r.chat_error ? [' → ', h('span', { style: 'color:var(--bad)', text: r.chat_error })] : ' → ID чата не указан'));
  };
  main.append(h('div', { class: 'vh' }, h('h1', { text: 'Уведомления' })),
    panel('Уведомления в Telegram', null,
      h('p', { class: 'sm muted', text: 'Сообщение приходит, когда сайт из мониторинга перестаёт или снова начинает открываться. Создайте бота у @BotFather, укажите его токен, напишите боту любое сообщение и нажмите «Найти» у поля «ID чата» — роутер сам покажет ваш чат. Токен хранится только на роутере.' }),
      h('div', { class: 'frow' }, h('label', { class: 'lbl', for: 'tg-token', text: 'Токен бота' }), tgToken),
      h('div', { class: 'frow' }, h('label', { class: 'lbl', for: 'tg-via', text: 'Отправлять' }), via,
        h('p', { class: 'hint', text: 'Если Telegram у провайдера закрыт и проверочное сообщение не уходит, отправляйте через туннель или прокси.' })),
      viaIface, viaProxy,
      h('div', { class: 'frow' }, h('label', { class: 'lbl', for: 'tg-chat', text: 'ID чата' }),
        h('div', { class: 'stack', style: 'gap:8px' },
          h('div', { class: 'row' }, tgChat, btn('Найти', findChats, '', 'search', { title: 'Показать чаты, из которых боту недавно писали' })),
          found)),
      who,
      h('div', { class: 'row' }, btn('Сохранить', async () => { if (await save()) { toast('Сохранено'); drawWho(); } }, 'primary'),
        btn('Отправить проверочное', async () => {
          if (!await save()) return;
          drawWho();
          const r = await guarded(() => api('notify_test'));
          if (r) toast('Сообщение отправлено ' + r.via);
        }))));
  // не ждём Telegram, чтобы страница открылась сразу
  if (m.tg.token_set) drawWho();
}

// bare — без заголовка: страница встроена в карточку сайта
async function viewTests(main, r, bare = false) {
  await loadCatalog();
  clearInterval(testPoll);
  if (r.arg === 'history') return viewPickHist(main, r);
  if (r.arg === 'auto') return viewAuto(main);
  const tab = ['trace', 'monitor', 'notify'].includes(r.arg) ? r.arg : 'pick';
  if (tab === 'monitor') {
    main.append(h('div', { class: 'vh' }, h('h1', { text: layout() === 'site' ? 'Сайты' : 'Мониторинг' })));
    return viewMonitor(main);
  }
  if (tab === 'notify') return viewNotify(main);
  const host = h('input', { class: 'input mono grow', id: 't-host', value: r.q.get('host') || S.check?.input.value || '', placeholder: 'rutracker.org', autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'Сайт для теста' });
  host.addEventListener('input', () => { host.dataset.edited = '1'; });
  if (r.q.get('host')) host.dataset.edited = '1';
  const proto = h('select', { class: 'select', 'aria-label': 'Протокол' }, h('option', { value: 'https', text: 'HTTPS (TLS, TCP 443)' }), h('option', { value: 'http', text: 'HTTP (TCP 80)' }));
  const setCfg = h('input', { type: 'checkbox', id: 't-cfg', checked: true });
  const setStd = h('input', { type: 'checkbox', id: 't-std', checked: true });
  const setHist = h('input', { type: 'checkbox', id: 't-hist', checked: true });
  const repeats = h('select', { class: 'select', 'aria-label': 'Повторов' }, [1, 2, 3, 5].map((n) => h('option', { value: n, text: plural(n, 'повтор', 'повтора', 'повторов'), selected: n === 3 })));
  const refine = h('input', { type: 'checkbox', id: 't-refine' });
  const out = h('div', { class: 'stack', style: 'gap:14px' });
  const startBtn = btn(tab === 'trace' ? 'Запустить трассировку' : 'Запустить тест', start, 'primary', 'play');
  let routeInfo = null;
  let routeLoading = null;
  let lastStatus = null;

  async function start() {
    const cmd = tab === 'trace' ? 'trace_start' : 'test_start';
    const sets = [setCfg.checked && 'config', setStd.checked && 'std', setHist.checked && 'hist', setHist.checked && 'other'].filter(Boolean);
    if (tab !== 'trace' && !sets.length) { toast('Выберите, что пробовать', { err: true }); return; }
    if (!await guarded(() => api(cmd, { host: host.value, proto: proto.value, sets, repeats: Number(repeats.value), refine: refine.checked }))) return;
    poll();
  }
  function poll() {
    clearInterval(testPoll);
    const tick = async () => {
      if (!out.isConnected) { clearInterval(testPoll); return; }
      const s = await api('test_status').catch(() => null);
      if (!s) return;
      drawStatus(s);
      if (!['running', 'starting'].includes(s.state)) clearInterval(testPoll);
    };
    tick();
    testPoll = setInterval(tick, 1500);
  }

  function drawStatus(s) {
    const running = ['running', 'starting'].includes(s.state);
    startBtn.disabled = running;
    lastStatus = s;
    // поле «Сайт» показывает сайт, для которого показаны результаты, пока его не начали править
    if (s.host && !host.dataset.edited && host.value !== s.host) host.value = s.host;
    // для рекомендации нужно знать, через какой профиль сайт идёт сейчас
    if (!running && s.host && s.type !== 'trace' && (!routeInfo || routeInfo.host !== s.host) && routeLoading !== s.host) {
      routeLoading = s.host;
      api('check', { host: s.host }).then((r) => { routeInfo = r; routeLoading = null; if (out.isConnected) drawStatus(lastStatus); }).catch(() => { routeLoading = null; });
    }
    if (s.state === 'idle') { out.replaceChildren(); return; }
    if ((s.type === 'trace') !== (tab === 'trace') && !running) { out.replaceChildren(); return; }
    const head = running ? h('section', { class: 'panel' },
      h('div', { class: 'row' }, h('span', { class: 'spin' }), h('b', { text: `${s.type === 'trace' ? 'Трассировка' : 'Тест'} ${s.host}` }), h('span', { class: 'grow' }),
        h('span', { class: 'sm muted num', text: s.total ? `${s.done} из ${s.total}` : 'запуск…' }), btn('Остановить', () => api('test_stop'), 'small danger', 'stop')),
      s.total ? h('div', { class: 'progress', style: 'height:6px;border-radius:99px;background:var(--soft);overflow:hidden' }, h('i', { style: `display:block;height:100%;width:${Math.round(s.done / s.total * 100)}%;background:var(--accent)` })) : null,
      s.current ? h('p', { class: 'sm muted', text: 'Сейчас: ' + s.current }) : null) : null;
    if (s.state === 'error') { out.replaceChildren(notice('bad', s.title || 'Тест не удался', s.error || '', s.host && s.title ? btn('Подробный диагноз', () => go(diagHref(s.host)), 'small', 'search') : null)); return; }
    if (s.type === 'trace') { out.replaceChildren(head, s.state === 'done' ? traceResult(s) : null); return; }
    out.replaceChildren(head, s.results?.length || s.baseline ? pickResult(s, running) : null);
  }

  function pickResult(s, running) {
    const rep = s.repeats || Number(repeats.value);
    const res = [...(s.results || [])];
    const good = res.filter((x) => x.ok > 0 && x.ok === x.tries && x.tries >= Math.min(rep, x.tries)).sort((a, b) => b.ok - a.ok || a.ms - b.ms);
    const best = good[0];
    const baseline = s.baseline;
    const rows = res.sort((a, b) => (b.ok / Math.max(1, b.tries)) - (a.ok / Math.max(1, a.tries)) || (a.ms ?? 1e9) - (b.ms ?? 1e9)).map((x) => {
      const full = x.ok && x.ok === x.tries;
      return h('tr', {},
        h('td', {}, levelIcon(full ? 'ok' : x.ok ? 'warning' : 'error')),
        h('td', {}, h('div', { text: x.name }), h('code', { class: 'sm muted', style: 'word-break:break-all', text: x.steps.map((t) => t.replace('--lua-desync=', '')).join('  ') })),
        h('td', { class: 'sm muted' }, x.from, x.hist ? h('div', { class: 'nowrap' }, chip('работала ' + fmtDate(x.hist), 'ok')) : null),
        h('td', { class: 'sm', text: full ? `открылся ${x.ok} из ${x.ok}` : x.ok ? `${x.ok} из ${x.tries}` : x.reason || 'не открылся' }),
        h('td', { class: 'num', text: x.ms ? x.ms + ' мс' : '—' }),
        h('td', {}, x.ok ? applyMenu(x, s) : null));
    });
    return h('div', { class: 'stack', style: 'gap:14px' },
      flow(s, running, res, best),
      baseline ? (baseline.ok ? notice('info', 'Без обхода сайт открывается', `HTTP ${baseline.code}, ${baseline.ms} мс — блокировки нет, стратегия не нужна.`)
        : notice('warn', 'Без обхода сайт не открывается: ' + baseline.reason, baseline.reason === 'соединение сброшено' || baseline.reason === 'обрыв TLS' ? 'Похоже на блокировку по SNI (DPI) — обход поможет.' : isFreeze(baseline.reason) ? (best ? 'Это ограничение ТСПУ «16–20 КБ» для зарубежных хостингов. Ниже — стратегия, которая его снимает.' : FREEZE_HINT) : 'Похоже на блокировку по DPI или IP.')) : null,
      !running ? recommendation(s, good, best) : null,
      !running && best ? h('div', { class: 'notice ok' }, icon('ok'), h('div', { class: 'grow' },
        h('b', { text: `Работают ${good.length} из ${res.length}. Быстрее всех — ${best.name}, ${best.ms} мс.` }),
        h('div', { class: 't', text: best.from }), h('code', { class: 'sm', style: 'word-break:break-all', text: best.steps.join(' ') })),
        h('div', { class: 'notice-actions' }, applyMenu(best, s, 'primary'), btn('Скопировать', () => copyText(best.steps.join('\n')), 'small', 'copy'))) : null,
      !running && !best && res.length ? notice('bad', 'Ни одна стратегия не помогла', isFreeze(baseline?.reason) ? FREEZE_HINT : 'Попробуйте больше повторов, протокол HTTP или другой сайт. Если без обхода соединение не устанавливается вовсе — возможно, заблокирован IP, тогда nfqws2 не поможет.') : null,
      refinePanel(s, rep),
      panel(`Результаты для ${s.host}`, h('span', { class: 'sm muted num', text: `${res.length} стратегий · ${rep} повт.` + (s.finished ? ` · ${Math.round((s.finished - s.started))} с` : '') }),
        h('div', { class: 'scroll' }, h('table', { class: 'tbl' }, h('thead', {}, h('tr', {}, h('th'), h('th', { text: 'Стратегия' }), h('th', { text: 'Откуда' }), h('th', { text: 'Результат' }), h('th', { class: 'num', text: 'Время' }), h('th'))), h('tbody', {}, rows)))));
  }

  // Ход подбора: без обхода → перебор → уточнение → итог
  function flow(s, running, res, best) {
    const now = running ? (s.phase || 'baseline') : 'done';
    const order = ['baseline', 'pick', 'refine', 'done'];
    const cell = (id, title, text) => h('div', { class: order.indexOf(id) < order.indexOf(now) || now === 'done' ? 'done' : id === now ? 'now' : '' }, h('b', { text: title }), h('span', { text }));
    const tried = res.filter((x) => !x.refined);
    const full = tried.filter((x) => x.ok && x.ok === x.tries).length;
    const part = tried.filter((x) => x.ok && x.ok < x.tries).length;
    return h('div', { class: 'flow' },
      cell('baseline', '1 · Без обхода', s.baseline ? (s.baseline.ok ? 'сайт открывается' : s.baseline.reason) : 'проверяю…'),
      cell('pick', '2 · Перебор', res.length ? `${plural(tried.length, 'стратегия', 'стратегии', 'стратегий')}: работают ${full}` + (part ? `, почти — ${part}` : '') : 'ожидает'),
      cell('refine', '3 · Уточнение', s.refine ? `основа — ${s.refine.base}` : now === 'done' ? 'не понадобилось' : 'если рабочих не найдётся'),
      cell('done', '4 · Итог', now !== 'done' ? '' : best ? best.name : 'рабочей стратегии нет'));
  }

  // Что перебрало уточнение: зелёное — открылся каждый раз, жёлтое — не каждый, красное — не открылся
  function refinePanel(s, rep) {
    const rf = s.refine;
    if (!rf) return null;
    return panel(`Уточнение: ${rf.base}`, h('span', { class: 'sm muted', text: rf.from }),
      rf.tried?.length ? h('p', { class: 'sm muted', text: `Сначала уточнялась «${rf.tried.join('», «')}» — ничего не дала, взята следующая.` }) : null,
      rf.axes.filter(Boolean).map((a) => h('div', { class: 'frow' }, h('span', { class: 'lbl', text: a.title }),
        h('div', { class: 'tune' }, a.items.map((it) => h('span', { class: (it.ok >= rep ? 'y' : it.ok ? 'h' : '') + (it.picked ? ' best' : ''),
          title: it.ok ? `открылся ${it.ok} из ${it.tries}` + (it.ms ? `, ${it.ms} мс` : '') : it.reason || 'не открылся', text: it.label }))))),
      rf.state === 'time' ? notice('warn', 'Уточнение остановлено по времени', 'На эту фазу отведено 5 минут; ниже — то, что успели проверить.') : null,
      h('p', { class: 'sm faint', text: 'Зелёное — открылся каждый раз, жёлтое — не каждый, красное — не открылся. Рамкой отмечено то, что вошло в итоговую стратегию. Значения перебираются по одной строке за раз: каждая следующая строка проверяется уже с выбранным в предыдущей.' }),
      rf.final ? h('p', { class: 'sm' }, 'Итог уточнения: ', h('b', { class: 'mono', text: rf.final }), ' — он есть в таблице ниже.') : rf.state === 'done' ? h('p', { class: 'sm muted', text: 'Лучше исходной стратегии ничего не нашлось.' }) : null);
  }

  function traceResult(s) {
    const lines = s.trace || [];
    const first = lines.findIndex((l) => l.startsWith('packet:'));
    const setup = first >= 0 ? lines.slice(0, first) : lines;
    const work = first >= 0 ? lines.slice(first) : [];
    // Сводка: имя сайта, выбранный профиль, действия стратегии, предупреждения lua
    let hostname = null;
    let profile = null;
    const actions = [];
    const warnings = [];
    for (const l of work) {
      const hn = l.match(/hostname='([^']+)'/);
      if (hn) hostname = hn[1];
      // профиль 0 — «без действий» для ещё не опознанных пакетов, его не считаем
      const pm = l.match(/^desync profile (\d+) \(([^)]*)\) matches/);
      if (pm && pm[1] !== '0' && (profile === null || hostname)) profile = Number(pm[1]);
      const lm = l.match(/^LUA: (.*?)(?: : [0-9A-F]{2}( [0-9A-F]{2})+.*)?$/);
      if (lm) (/not inside|not found|skipped|error|cannot|fail/i.test(lm[1]) ? warnings : actions).push(lm[1]);
      if (/^LUA ERROR|error/i.test(l) && !lm) warnings.push(l);
    }
    const p = profile ? prof(profile) : null;
    let q = '';
    const pre = h('pre', { class: 'box', style: 'max-height:60vh' });
    const draw = () => drawLog(pre, work.filter((l) => !q || l.toLowerCase().includes(q)));
    draw();
    return h('div', { class: 'stack', style: 'gap:14px' },
      s.result.ok ? notice('ok', `Открылся через текущую конфигурацию: HTTP ${s.result.code}, ${s.result.ms} мс`) : notice('bad', 'Не открылся через текущую конфигурацию: ' + s.result.reason),
      panel('Что произошло', null,
        h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Имя сайта' }), h('span', { class: 'mono', text: hostname || 'не определено (нет SNI/Host)' })),
        h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Профиль' }), profile ? h('a', { href: `#/settings/p${profile}`, text: `#${profile} ${p ? profName(p) : ''}` }) : h('span', { text: 'ни один — соединение прошло без обработки' })),
        actions.length ? h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Действия' }), h('ol', { class: 'sm mono', style: 'margin:0;padding-left:18px;display:grid;gap:2px' }, actions.map((a) => h('li', { text: a })))) : null,
        warnings.length ? h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Предупреждения' }), h('div', { class: 'stack', style: 'gap:4px' }, warnings.map((w) => h('div', { class: 'help-iss warning' }, levelIcon('warning'), h('span', { class: 'mono sm', text: w }))))) : null),
      panel('Журнал обработки', h('span', { class: 'sm muted num', text: plural(work.length, 'строка', 'строки', 'строк') }),
        h('input', { class: 'input', placeholder: 'Фильтр', 'aria-label': 'Фильтр журнала', oninput: (e) => { q = e.target.value.toLowerCase(); draw(); } }), pre,
        h('details', {}, h('summary', { text: `запуск nfqws2 (${setup.length} строк)` }), (() => { const p = h('pre', { class: 'box', style: 'max-height:40vh' }); drawLog(p, setup); return p; })())));
  }

  // Куда можно применить стратегию и что это затронет. Для неопытного главный вариант — «только для этого сайта».
  function applyTargets(x, s) {
    const steps = x.steps;
    const port = s.proto === 'http' ? 80 : 443;
    const rp = routeProfile(s);
    const covers = (p) => (p.ports.tcp || '').split(',').some((x) => { const [a, b] = x.split('-').map(Number); return port >= a && port <= (b || a); });
    const lt = listTarget(x, s);
    const opts = [{
      title: `Только для ${s.host}`, recommended: !lt,
      desc: `Создаётся отдельный профиль в начале списка. Стратегия будет работать только для ${s.host} и его поддоменов — остальные сайты не затронет.`,
      run: () => addSiteProfile(steps, s),
    }];
    if (lt) {
      opts.unshift({
        title: `Добавить ${s.host} в ${listName(lt.list.name)}`, recommended: true,
        desc: `Эта стратегия уже стоит в профиле #${lt.profile}${confProf(lt.profile) ? ' ' + profName(confProf(lt.profile)) : ''}, сайта просто нет в его списке ${lt.list.name}. Новый профиль не нужен, перезапуск тоже — nfqws2 перечитает список сам.`,
        run: () => addToProfileList(lt, x, s),
      });
    }
    for (const p of S.state.conf_profiles.filter((x) => x.source && covers(x))) {
      const shared = p.source.source !== 'NFQWS_ARGS_CUSTOM' ? S.state.conf_profiles.filter((q) => q !== p && q.source?.source === p.source.source) : [];
      const reach = portsText(p.remaining) || 'до профиля ничего не доходит';
      opts.push({
        title: `Заменить стратегию профиля #${p.index} ${profName(p)}`,
        dim: p.index !== rp,
        desc: (p.index === rp ? `Сейчас ${s.host} идёт через этот профиль. ` : `${s.host} сюда не попадает — для этого сайта ничего не изменится. `)
          + `Стратегия поменяется для всех, кого обрабатывает профиль: ${profScope(p)} (${reach}).`
          + (shared.length ? ` Настройка общая с ${shared.map((q) => '#' + q.index).join(', ')} — они изменятся тоже.` : ''),
        run: () => go(`#/settings/p${p.index}?apply=${encodeURIComponent(JSON.stringify(steps))}`),
      });
    }
    return opts;
  }

  function applyMenu(x, st, cls = '', label = 'Применить…') {
    const wrap = h('span', { class: 'menu' });
    const list = h('div', { class: 'menu-list wide', hidden: true, role: 'menu', style: 'right:0;left:auto' },
      h('div', { class: 'nav-h', text: 'Куда применить эту стратегию' }),
      applyTargets(x, st).map((o) => h('button', { type: 'button', role: 'menuitem', class: 'opt' + (o.dim ? ' dim' : ''), onclick: () => { list.hidden = true; o.run(); } },
        h('span', { class: 'row', style: 'gap:6px' }, h('b', { text: o.title }), o.recommended ? chip('рекомендуется', 'ok') : null),
        h('span', { class: 'sm muted', text: o.desc }))));
    const b = btn(label, () => { list.hidden = !list.hidden; }, 'small ' + cls, null, { 'aria-haspopup': 'menu' });
    wrap.append(b, list);
    return wrap;
  }

  // Сработала стратегия, взятая из профиля #N, а сайт до него не дошёл только потому, что его нет в списке профиля.
  // Тогда достаточно записи в списке. Профили с circular не предлагаем: какая из стратегий включится, там не угадать.
  function listTarget(x, s) {
    if (!routeInfo || routeInfo.host !== s.host) return null;
    const route = routeInfo.routes[s.proto === 'http' ? 'http' : 'https'].steps;
    for (const m of (x.from || '').matchAll(/#(\d+)(?=,|$)/g)) {
      const n = +m[1];
      if (route.find((r) => r.profile === n)?.miss !== 'hostlist') continue;
      const lists = S.state.lists.filter((l) => l.kind === 'host' && l.editable && l.exists && l.used.some((u) => u.profile === n && u.role === 'include'));
      const list = lists.find((l) => l.name === 'user.list') || lists[0];
      if (list) return { profile: n, list };
    }
    return null;
  }

  // Запись в список профиля: без перезапуска; потом смотрим, через какой профиль сайт пошёл на самом деле
  async function addToProfileList(lt, x, s) {
    if (!confirm(`Добавить ${s.host} в ${lt.list.name}?\n\nПрофиль #${lt.profile} уже использует стратегию, которая сработала в тесте. Новый профиль не создаётся, nfqws2 перечитает список сам — перезапуск не нужен.`)) return;
    if (!await guarded(() => api('list_add', { name: lt.list.name, items: [s.host] }))) return;
    api('pick_applied', { host: s.host, steps: x.steps, target: `список ${lt.list.name} профиля #${lt.profile}` }).catch(() => {});
    await loadState().catch(() => {});
    routeInfo = await api('check', { host: s.host }).catch(() => routeInfo);
    const now = routeProfile(s);
    const undo = { undo: () => undoLast(false) };
    if (now === lt.profile) toast(`${s.host} добавлен в ${lt.list.name} и теперь идёт через профиль #${lt.profile}`, undo);
    else {
      const st = routeInfo.routes[s.proto === 'http' ? 'http' : 'https'].steps.find((r) => r.profile === lt.profile);
      toast(`${s.host} добавлен в ${lt.list.name}, но через профиль #${lt.profile} не пошёл: ` + (now ? `его перехватывает профиль #${now}` : st?.why || 'причина не определена'), { err: true, ...undo });
    }
    if (out.isConnected) drawStatus(lastStatus);
  }

  // Отдельный профиль «только для этого сайта» в начале своих профилей, затем перезапуск с проверкой
  async function addSiteProfile(steps, s) {
    const http = s.proto === 'http';
    await loadConf();
    const cur = S.conf.vars.NFQWS_ARGS_CUSTOM.trim() ? splitParts(tokensOf(S.conf.vars.NFQWS_ARGS_CUSTOM)) : [];
    const part = [`--filter-tcp=${http ? 80 : 443}`, `--filter-l7=${http ? 'http' : 'tls'}`, `--hostlist-domains=${s.host}`, `--payload=${http ? 'http_req' : 'tls_client_hello'}`, ...steps];
    if (!confirm(`Создать профиль только для ${s.host}?\n\nОн встанет первым (#1), остальные профили сдвинутся на один номер. Другие сайты не затронет.\n\nПосле сохранения nfqws2 перезапустится с проверкой: если сайты перестанут открываться или вы не подтвердите за 3 минуты, всё вернётся как было.`)) return;
    if (!await saveVars({ NFQWS_ARGS_CUSTOM: joinParts([part, ...cur]) }, `профиль только для ${s.host}`)) return;
    api('pick_applied', { host: s.host, steps, target: `отдельный профиль для ${s.host}` }).catch(() => {});
    await safeRestart();
    go('#/settings/p1');
  }

  // Через какой профиль тестовый сайт идёт сейчас (по проверке сайта)
  function routeProfile(s) {
    if (!routeInfo || routeInfo.host !== s.host) return null;
    return routeInfo.routes[s.proto === 'http' ? 'http' : 'https'].profile;
  }

  // Рекомендация по итогам теста — простыми словами
  function recommendation(s, good, best) {
    const b = s.baseline;
    if (b?.ok) return notice('info', 'Менять ничего не нужно', `${s.host} открывается и без обхода — блокировки сейчас нет.`);
    if (!routeInfo || routeInfo.host !== s.host) return h('div', { class: 'skeleton sm' }, h('span', { class: 'spin' }), 'Смотрю, через какой профиль сайт идёт сейчас…');
    const rp = routeProfile(s);
    const p = rp ? confProf(rp) : null;
    const currentWorks = rp && good.some((x) => x.from.includes(`#${rp}`));
    if (currentWorks) {
      return notice('ok', 'Менять ничего не нужно', `Сейчас ${s.host} идёт через профиль #${rp} ${p ? profName(p) : ''}, и его стратегия в тесте сработала. Если у вас сайт всё равно не открывается, причина скорее в браузере (HTTP/3 — QUIC), DNS или в том, что сайт идёт через podkop.`);
    }
    // стратегия уже есть в одном из профилей — проще добавить сайт в его список, чем заводить профиль
    for (const x of good) {
      const lt = listTarget(x, s);
      if (!lt) continue;
      return h('div', { class: 'notice warn' }, icon('alert'),
        h('div', { class: 'grow' }, h('b', { text: `Рекомендация: добавить ${s.host} в ${listName(lt.list.name)}` }),
          h('div', { class: 't', text: `В тесте сработала стратегия профиля #${lt.profile}${confProf(lt.profile) ? ' ' + profName(confProf(lt.profile)) : ''}, но ${s.host} нет в его списке ${lt.list.name}, поэтому ${rp ? `сайт идёт через профиль #${rp}` : 'nfqws2 этот сайт не обрабатывает'}. Достаточно записи в списке: новый профиль не нужен, перезапуск тоже.` })),
        h('div', { class: 'notice-actions' }, btn(`Добавить в ${lt.list.name}`, () => addToProfileList(lt, x, s), 'small primary', 'plus'), applyMenu(x, s, '', 'Другие варианты…')));
    }
    if (!best) return null;
    const why = rp ? `Сейчас ${s.host} идёт через профиль #${rp} ${p ? profName(p) : ''}, но его стратегия в тесте не помогла.` : `Сейчас nfqws2 этот сайт не обрабатывает.`;
    return h('div', { class: 'notice warn' }, icon('alert'),
      h('div', { class: 'grow' }, h('b', { text: 'Рекомендация: применить лучшую стратегию только для этого сайта' }),
        h('div', { class: 't', text: `${why} Отдельный профиль для ${s.host} ничего не сломает у других сайтов. После применения nfqws2 перезапустится с проверкой и сам откатит изменения, если что-то пойдёт не так.` })),
      h('div', { class: 'notice-actions' }, btn(`Применить только для ${s.host}`, () => addSiteProfile(best.steps, s), 'small primary', 'ok'), applyMenu(best, s, '', 'Другие варианты…')));
  }

  const hist = await api('tests_history').catch(() => ({ items: [] }));
  main.append(
    bare ? null : h('div', { class: 'vh' }, h('h1', { text: tab === 'trace' ? 'Трассировка' : 'Подбор стратегии' })),
    panel(null, null,
      h('div', { class: 'frow' }, h('label', { class: 'lbl', for: 't-host', text: 'Сайт' }), h('div', { class: 'row' }, host, proto)),
      tab === 'pick' ? [
        h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Что пробовать' }), h('div', { class: 'row' },
          h('label', { class: 'row' }, setCfg, 'стратегии из вашего конфига'), h('label', { class: 'row' }, setStd, 'стандартный набор'),
          h('label', { class: 'row', title: 'Стратегии из истории подборов: сначала работавшие для этого сайта, затем до пяти помогавших другим сайтам' }, setHist, 'что работало раньше'))),
        h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Повторов' }), h('div', { class: 'row' }, repeats, h('span', { class: 'sm muted', text: 'Стратегия засчитывается, если сайт открылся каждый раз.' }))),
        h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Уточнение' }), h('div', { class: 'stack', style: 'gap:2px' },
          h('label', { class: 'row' }, refine, 'искать вариант полегче, даже если рабочая стратегия найдена'),
          h('span', { class: 'sm muted', text: 'Если рабочих стратегий нет, подбор сам пробует довести лучшую: меняет число фейков (до 20), способ их порчи и имя в фейке. Это ещё 2–5 минут.' })))] :
        h('p', { class: 'sm muted', text: 'Трассировка открывает сайт через копию текущей конфигурации с подробным журналом nfqws2 — видно, какой профиль сработал и что сделали стратегии.' }),
      h('div', { class: 'row' }, startBtn, h('span', { class: 'sm muted grow note-wide', text: 'Работает отдельный процесс nfqws2 на очереди 301 только для проверочных соединений роутера. Ваш трафик и основной nfqws2 не затрагиваются.' }))),
    out,
    tab === 'pick' && !bare && hist.items.length ? panel('Последние подборы', h('a', { class: 'sm', href: PAGES.phist[0], text: 'вся история' }), h('div', { class: 'scroll' }, h('table', { class: 'tbl' },
      h('thead', {}, h('tr', {}, h('th', { text: 'Когда' }), h('th', { text: 'Сайт' }), h('th', { text: 'Без обхода' }), h('th', { class: 'num', text: 'Сработало' }), h('th', { text: 'Лучшая' }))),
      h('tbody', {}, hist.items.slice(0, 5).map((x) => h('tr', {}, h('td', { class: 'date', text: fmtDate(x.ts) }), h('td', { class: 'mono' }, h('a', { href: pickHistHref(x.host), text: x.host })),
        h('td', { text: x.baseline?.ok ? 'открывается' : x.baseline?.reason || '—' }), h('td', { class: 'num', text: `${x.ok} из ${x.total}` }), h('td', { class: 'mono sm', text: x.best || '—' }))))))) : null);
  poll();
}

// ============ История подборов: что и когда работало для каждого сайта ============

const pickHistHref = (host) => layout() === 'site' ? `#/site/${encodeURIComponent(host)}/hist` : PAGES.phist[0] + '?host=' + encodeURIComponent(host);
const pickOutcome = (x) => x.baseline?.ok ? 'открывается и без обхода' : x.best ? x.best : x.state === 'stopped' ? 'остановлен' : 'ничего не помогло';

// Быстрая проверка: только то, что для этого сайта уже работало
async function pickAgain(host, proto) {
  if (!await guarded(() => api('test_start', { host, proto: proto || 'https', sets: ['hist'], repeats: 3 }))) return;
  go(pickHref(host));
}

// bare — без заголовка: страница встроена в карточку сайта
async function viewPickHist(main, r, bare = false) {
  const host = r.q.get('host');
  if (host) return viewPickHistSite(main, host, bare);
  const all = (await api('tests_history').catch(() => ({ items: [] }))).items;
  const sites = new Map();
  for (const x of all) {
    if (!sites.has(x.host)) sites.set(x.host, { host: x.host, last: x, runs: 0, works: 0, applied: null });
    const g = sites.get(x.host);
    g.runs++;
    g.works += x.works || 0;
    if (!g.applied && x.applied) g.applied = x.applied;
  }
  const body = h('tbody');
  const search = h('input', { class: 'input', type: 'search', placeholder: 'Найти сайт', 'aria-label': 'Найти сайт', style: 'max-width:260px', oninput: () => draw() });
  function draw() {
    const q = search.value.trim().toLowerCase();
    const rows = [...sites.values()].filter((g) => !q || g.host.includes(q));
    body.replaceChildren(...rows.map((g) => h('tr', {},
      h('td', {}, levelIcon(g.last.baseline?.ok ? 'info' : g.last.best ? 'ok' : 'error')),
      h('td', {}, h('a', { class: 'mono', href: pickHistHref(g.host), text: g.host })),
      h('td', { class: 'date' }, fmtDate(g.last.ts), g.last.auto ? [' ', chip('авто')] : null),
      h('td', { class: 'sm' }, pickOutcome(g.last), g.applied ? h('div', { class: 'sm muted', text: `применена ${fmtDate(g.applied.ts)}: ${g.applied.name}` }) : null),
      h('td', { class: 'num', text: g.runs }),
      h('td', {}, h('div', { class: 'row', style: 'justify-content:flex-end;flex-wrap:nowrap' },
        g.works ? btn('Проверить снова', () => pickAgain(g.host, g.last.proto), 'small', 'play', { title: 'Быстрый подбор только по тому, что для этого сайта уже работало' }) : null,
        btn('', async () => { if (confirm(`Удалить историю подборов для ${g.host}?`) && await guarded(() => api('picks_delete', { host: g.host }), 'Удалено')) route(true); }, 'small ghost', 'trash', { 'aria-label': 'Удалить историю сайта', title: 'Удалить историю сайта' }))))));
    if (!rows.length) body.append(h('tr', {}, h('td', { colspan: 6, class: 'muted', text: 'Ничего не найдено.' })));
  }
  draw();
  main.append(
    bare ? null : h('div', { class: 'vh' }, h('h1', { text: 'История подборов' })),
    !sites.size ? panel(null, null, h('p', { class: 'muted', text: 'Подбор стратегии ещё не запускался. После каждого подбора здесь останется, что и когда сработало, — при следующей поломке это проверяется первым.' }),
      h('div', { class: 'row' }, h('a', { class: 'btn primary', href: PAGES.pick[0] }, 'Подобрать стратегию')))
    : panel(null, null,
      h('div', { class: 'row' }, search, h('span', { class: 'grow' }), h('span', { class: 'sm muted num', text: `${plural(sites.size, 'сайт', 'сайта', 'сайтов')} · ${plural(all.length, 'подбор', 'подбора', 'подборов')}` }),
        btn('Очистить всё', async () => { if (confirm('Удалить всю историю подборов? На профили и конфиг это не влияет.') && await guarded(() => api('picks_delete', {}), 'История очищена')) route(true); }, 'small danger', 'trash')),
      h('div', { class: 'scroll' }, h('table', { class: 'tbl' },
        h('thead', {}, h('tr', {}, h('th'), h('th', { text: 'Сайт' }), h('th', { text: 'Последний подбор' }), h('th', { class: 'wide', text: 'Итог' }), h('th', { class: 'num', text: 'Запусков' }), h('th'))), body)),
      h('p', { class: 'sm faint', text: 'Хранится до 100 подборов, не больше 10 на сайт. Работавшие стратегии подбор пробует первыми — галочка «что работало раньше».' })));
}

async function viewPickHistSite(main, host, bare) {
  const runs = (await api('tests_history', { host }).catch(() => ({ items: [] }))).items;
  // сводка по стратегиям: в скольких запусках пробовалась и в скольких открыла сайт каждый раз
  const strat = new Map();
  for (const e of runs) {
    for (const x of e.results || []) {
      if (!x.steps) continue;
      const key = x.steps.join(' ');
      if (!strat.has(key)) strat.set(key, { name: x.name, steps: x.steps, seen: 0, full: 0, last: null, ms: null, refined: x.refined });
      const g = strat.get(key);
      g.seen++;
      if (x.ok >= (e.repeats || 1)) { g.full++; if (!g.last) { g.last = e.ts; g.ms = x.ms; } }
    }
  }
  const list = [...strat.values()].sort((a, b) => (b.last || 0) - (a.last || 0) || b.full - a.full);
  const applied = runs.find((e) => e.applied)?.applied;
  const proto = runs[0]?.proto || 'https';
  main.append(
    bare ? null : h('div', { class: 'vh' }, h('a', { class: 'btn ghost small', href: PAGES.phist[0] }, icon('back'), 'Вся история'), h('h1', { class: 'site-name', text: host })),
    !runs.length ? panel(null, null, h('p', { class: 'muted', text: `Для ${host} подбор ещё не запускался.` }), h('div', { class: 'row' }, h('a', { class: 'btn primary', href: pickHref(host) }, 'Подобрать стратегию'))) : [
      panel(null, null, h('div', { class: 'row' },
        list.some((g) => g.full) ? btn('Проверить снова', () => pickAgain(host, proto), 'primary', 'play') : null,
        h('a', { class: 'btn', href: pickHref(host) }, 'Полный подбор'), h('a', { class: 'btn', href: diagHref(host) }, 'Диагноз'), h('span', { class: 'grow' }),
        btn('Удалить историю', async () => { if (confirm(`Удалить историю подборов для ${host}?`) && await guarded(() => api('picks_delete', { host }), 'Удалено')) { if (bare) route(true); else go(PAGES.phist[0]); } }, 'small danger', 'trash')),
        h('p', { class: 'sm muted', text: '«Проверить снова» пробует только то, что для этого сайта уже работало, — это секунды, а не минуты. Применить стратегию можно из результатов проверки.' }),
        applied ? h('p', { class: 'sm' }, 'Применена ', h('b', { text: fmtDate(applied.ts) }), ': ', h('span', { class: 'mono', text: applied.name }), applied.target ? ` — ${applied.target}` : '') : null),
      panel('Что работало', h('span', { class: 'sm muted num', text: plural(list.length, 'стратегия', 'стратегии', 'стратегий') }),
        list.length ? h('div', { class: 'scroll' }, h('table', { class: 'tbl' },
          h('thead', {}, h('tr', {}, h('th'), h('th', { text: 'Стратегия' }), h('th', { text: 'Работала' }), h('th', { class: 'nowrap', text: 'Последний раз' }), h('th', { class: 'num', text: 'Время' }), h('th'))),
          h('tbody', {}, list.map((g) => h('tr', {},
            h('td', {}, levelIcon(g.full === g.seen ? 'ok' : g.full ? 'warning' : 'error')),
            h('td', { style: 'min-width:220px' }, h('div', {}, g.name, g.refined ? ' ' : null, g.refined ? chip('уточнение') : null), h('code', { class: 'sm muted', style: 'word-break:break-all', text: g.steps.map((t) => t.replace('--lua-desync=', '')).join('  ') })),
            h('td', { class: 'sm nowrap', text: g.full ? `в ${g.full} из ${plural(g.seen, 'запуска', 'запусков', 'запусков')}` : 'открывала не каждый раз' }),
            h('td', { class: 'date', text: g.last ? fmtDate(g.last) : '—' }),
            h('td', { class: 'num nowrap', text: g.ms ? g.ms + ' мс' : '—' }),
            h('td', {}, btn('Скопировать', () => copyText(g.steps.join('\n')), 'small', 'copy'))))))) : h('p', { class: 'muted', text: 'Ни одна стратегия этот сайт пока не открыла.' })),
      panel('Запуски', h('span', { class: 'sm muted num', text: String(runs.length) }),
        h('div', { class: 'stack', style: 'gap:6px' }, runs.map((e) => h('details', { class: 'prun' },
          h('summary', {}, levelIcon(e.baseline?.ok ? 'info' : e.best ? 'ok' : 'error'), h('span', { class: 'date', text: fmtDate(e.ts) }), e.auto ? chip('авто') : null,
            h('span', { class: 'grow ellipsis', text: pickOutcome(e) }),
            h('span', { class: 'sm muted num nowrap', text: `${e.ok} из ${e.total}` + (e.dur ? ` · ${e.dur} с` : '') })),
          h('p', { class: 'sm muted', text: 'Без обхода: ' + (e.baseline?.ok ? 'открывается' : e.baseline?.reason || 'нет данных') + (e.proto === 'http' ? ' · HTTP' : '') + (e.applied ? ` · применена ${e.applied.name}` : '') }),
          e.results?.length ? h('div', { class: 'scroll' }, h('table', { class: 'tbl' }, h('tbody', {}, e.results.map((x) => h('tr', {},
            h('td', {}, levelIcon(x.ok && x.ok === x.tries ? 'ok' : x.ok ? 'warning' : 'error')),
            h('td', { text: x.name }), h('td', { class: 'sm muted', text: x.from }),
            h('td', { class: 'sm', text: x.ok ? `${x.ok} из ${x.tries}` : x.reason || 'не открылся' }),
            h('td', { class: 'num', text: x.ms ? x.ms + ' мс' : '—' })))))) : h('p', { class: 'sm faint', text: 'Запись сделана прежней версией — подробностей нет.' })))))]);
}

// ============ Автоподбор при поломке ============

// Применить найденное автоподбором: отдельный профиль только для сайта, роутер сам проверяет и откатывает
async function autoApply(o) {
  const host = o.host;
  // сработала стратегия существующего профиля — достаточно записи в его списке, перезапуск не нужен
  const ask = o.list
    ? `Добавить ${host} в ${o.list.name}?\n\nНайденная стратегия уже стоит в профиле #${o.list.profile}, сайта просто нет в его списке. Новый профиль не создаётся, nfqws2 не перезапускается. Роутер сам проверит ${host}: если сайт не откроется, запись уберётся обратно.`
    : `Применить найденную стратегию только для ${host}?\n\nБудет создан отдельный профиль (или заменена стратегия уже созданного). nfqws2 перезапустится — на несколько секунд обход прервётся. Роутер сам проверит ${host} и сайты мониторинга и вернёт конфиг, если что-то пойдёт не так. Это займёт до минуты.`;
  if (!confirm(ask)) return;
  toast(o.list ? 'Добавляю в список и проверяю…' : 'Применяю и проверяю — до минуты…');
  const r = await guarded(() => api('auto_apply', { host }));
  S.conf = null;
  await loadState().catch(() => {});
  if (r) toast(r.text);
  route(true);
}

const AUTO_KIND = { start: 'info', found: 'ok', applied: 'ok', none: 'error', rolled: 'warning', fail: 'warning', skip: 'info' };

async function viewAuto(main) {
  const a = await api('auto_get');
  const cfg = a.settings;
  const enabled = h('input', { type: 'checkbox', id: 'au-on', checked: cfg.enabled });
  const apply = h('input', { type: 'checkbox', id: 'au-apply', checked: cfg.apply });
  const fails = h('select', { class: 'select', 'aria-label': 'Сколько неудач подряд' }, [2, 3, 4].map((n) => h('option', { value: n, text: `${n} проверки подряд`, selected: n === cfg.fails })));
  const pause = h('select', { class: 'select', 'aria-label': 'Пауза между попытками' }, [6, 12, 24].map((n) => h('option', { value: n, text: `${n} часов`, selected: n === cfg.pause })));
  const save = async () => {
    if (apply.checked && !cfg.apply && !confirm('Разрешить роутеру самому менять конфиг?\n\nКогда сайт из мониторинга сломается и стратегия найдётся, роутер создаст отдельный профиль только для этого сайта и перезапустит nfqws2 — без вашего участия, в любое время суток. Если сайт не откроется или сломается другой сайт мониторинга, конфиг вернётся как был. Чужие профили и списки не меняются.')) { apply.checked = false; return; }
    if (await guarded(() => api('auto_set', { enabled: enabled.checked, apply: apply.checked, fails: Number(fails.value), pause: Number(pause.value) }), 'Сохранено')) { await loadState().catch(() => {}); route(true); }
  };
  [enabled, apply, fails, pause].forEach((el) => el.addEventListener('change', save));
  main.append(
    h('div', { class: 'vh' }, h('h1', { text: 'Автоподбор' })),
    !a.monitor.enabled || !a.monitor.sites ? notice('warn', 'Мониторинг выключен или пуст', 'Автоподбор узнаёт о поломке от мониторинга: включите его и добавьте сайты, за которыми нужно следить.', h('a', { class: 'btn small', href: PAGES.mon[0] }, 'Мониторинг')) : null,
    a.running ? notice('info', `Сейчас идёт автоподбор для ${a.running}`, 'Ход виден на странице подбора.', h('a', { class: 'btn small', href: pickHref(a.running) }, 'Открыть')) : null,
    a.offers.length ? panel('Найдено — ждёт вашего решения', null, a.offers.map((o) => h('div', { class: 'stack', style: 'gap:8px' },
      h('div', { class: 'item-main' }, h('span', { class: 'row', style: 'gap:8px' }, levelIcon('ok'), h('b', { class: 'mono', text: o.host })),
        h('span', { class: 'sm muted', text: `${fmtDate(o.ts)} · ${o.name} · ${o.from}` }),
        h('code', { class: 'sm muted', style: 'word-break:break-all', text: o.steps.map((t) => t.replace('--lua-desync=', '')).join('  ') }),
        o.list ? h('span', { class: 'sm', text: `Эта стратегия уже стоит в профиле #${o.list.profile} — достаточно добавить сайт в ${o.list.name}: без нового профиля и без перезапуска.` }) : null),
      h('div', { class: 'row' }, btn(o.list ? `Добавить в ${o.list.name}` : 'Применить', () => autoApply(o), 'small primary', o.list ? 'plus' : 'ok'),
        h('a', { class: 'btn small', href: pickHistHref(o.host) }, 'История'),
        btn('Отклонить', async () => { if (await guarded(() => api('auto_dismiss', { host: o.host }))) { await loadState().catch(() => {}); route(true); } }, 'small ghost'))))) : null,
    panel(null, null,
      h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Автоподбор' }), h('div', { class: 'stack', style: 'gap:2px' },
        h('label', { class: 'row', style: 'flex-wrap:nowrap;align-items:flex-start' }, enabled, 'подбирать стратегию, когда сайт из мониторинга перестаёт открываться'),
        h('span', { class: 'sm muted', text: `Следит за сайтами мониторинга (сейчас ${a.monitor.sites}). Упавший сайт перепроверяется через 10 минут; если он не открывается и снова — запускается подбор. Первым пробуется то, что для этого сайта уже работало.` }))),
      h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Что делать с найденным' }), h('div', { class: 'stack', style: 'gap:2px' },
        h('label', { class: 'row', style: 'flex-wrap:nowrap;align-items:flex-start' }, apply, 'применять самому — записью в список профиля, если его стратегия подошла, иначе отдельным профилем только для этого сайта'),
        h('span', { class: 'sm muted', text: 'Без галочки найденное только предлагается: здесь, в «Проблемах» и в Telegram. С галочкой роутер сам создаёт профиль для сайта, перезапускает nfqws2 и проверяет: если сайт не открылся или сломался другой сайт мониторинга — возвращает конфиг как был.' }))),
      h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Считать поломкой' }), h('div', { class: 'row' }, fails, h('span', { class: 'sm muted', text: 'неудачных, после того как сайт открывался' }))),
      h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Повторять не чаще' }), h('div', { class: 'row' }, h('span', { class: 'sm muted', text: 'раз в' }), pause, h('span', { class: 'sm muted', text: 'для одного сайта' }))),
      h('p', { class: 'sm faint', text: 'Автоподбор не запускается, если не открывается больше половины сайтов мониторинга (похоже на обрыв связи), если nfqws2 остановлен или сайт идёт через podkop. За один проход — один сайт.' + (a.tg ? '' : ' Чтобы узнавать о находках сразу, настройте Telegram в «Уведомлениях».') })),
    panel('Журнал', a.log.length ? btn('Очистить', async () => { if (await guarded(() => api('auto_clear'))) route(true); }, 'small ghost') : null,
      a.queue.length ? h('p', { class: 'sm', text: 'В очереди: ' + a.queue.join(', ') }) : null,
      a.log.length ? h('div', { class: 'stack', style: 'gap:0' }, a.log.map((e) => h('div', { class: 'act' }, levelIcon(AUTO_KIND[e.kind] || 'info'),
        h('div', { class: 'item-main' }, h('span', {}, h('a', { class: 'mono', href: pickHistHref(e.host), text: e.host }), h('span', { class: 'sm faint', text: ' · ' + fmtDate(e.ts) })),
          h('span', { class: 'sm muted', text: e.text })))))
        : h('p', { class: 'muted', text: cfg.enabled ? 'Пока ничего не происходило — все сайты мониторинга открываются.' : 'Автоподбор выключен.' })));
}

// ============ Отчёт для помощи ============

async function paneReport(content) {
  const sites = h('input', { class: 'input mono grow', placeholder: 'до трёх сайтов через пробел — необязательно', autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'Сайты для проверки' });
  const hide = h('input', { type: 'checkbox', id: 'rep-hide' });
  const ta = h('textarea', { class: 'input mono', rows: 22, readonly: true, style: 'width:100%;resize:vertical;font-size:12px', hidden: true });
  const acts = h('div', { class: 'row', hidden: true },
    btn('Скопировать', () => copyText(ta.value), 'primary', 'copy'),
    btn('Скачать .txt', () => { const a = h('a', { href: URL.createObjectURL(new Blob([ta.value], { type: 'text/plain' })), download: 'nfqws2-report.txt' }); document.body.append(a); a.click(); a.remove(); }, '', 'download'),
    h('span', { class: 'sm muted', text: 'Перечитайте перед отправкой: отчёт никуда не уходит сам, его отправляете вы.' }));
  const make = btn('Собрать отчёт', async () => {
    make.disabled = true;
    const r = await guarded(() => api('report', { sites: sites.value.split(/[\s,]+/).filter(Boolean).slice(0, 3), hide: hide.checked, build: BUILD.split('-').pop() }));
    make.disabled = false;
    if (!r) return;
    ta.value = r.text; ta.hidden = false; acts.hidden = false;
  }, 'primary', 'play');
  content.append(
    h('div', { class: 'vh' }, h('h1', { text: 'Отчёт для помощи' })),
    panel(null, null,
      h('p', { class: 'sm muted', text: 'Текст, который можно приложить к вопросу в чате или в issue: версии, состояние сервиса, замечания к конфигу, профили, названия списков, мониторинг, итоги подборов. Паролей, токена Telegram, внешнего адреса и содержимого списков в нём нет.' }),
      h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Проверить сайты' }), sites),
      h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Имена сайтов' }), h('label', { class: 'row', style: 'flex-wrap:nowrap' }, hide, 'заменить на «сайт-1», «сайт-2»…')),
      h('div', { class: 'row' }, make)),
    acts, ta);
}

// ============ Список по ASN ============

const ASN_PRESETS = [[24940, 'Hetzner'], [16276, 'OVH'], [14061, 'DigitalOcean'], [51167, 'Contabo'], [63949, 'Akamai (Linode)'], [13335, 'Cloudflare'], [16509, 'Amazon AWS'], [20473, 'Vultr']];

async function viewAsn(main) {
  const saved = (await api('asn_get').catch(() => ({ items: [] }))).items;
  const q = h('input', { class: 'input mono grow', placeholder: 'AS24940 или имя сайта', autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'Номер AS или сайт' });
  const out = h('div', { class: 'stack' });
  const look = async (v) => {
    if (v) q.value = v;
    if (!q.value.trim()) return;
    out.replaceChildren(spinner('Спрашиваю RIPEstat…'));
    const r = await guarded(() => api('asn_lookup', { q: q.value.trim() }));
    if (!r) { out.replaceChildren(); return; }
    const auto = h('input', { type: 'checkbox', id: 'asn-auto', checked: true });
    out.replaceChildren(panel(`AS${r.asn} · ${r.holder || 'владелец не указан'}`, h('span', { class: 'sm muted num', text: plural(r.count, 'префикс', 'префикса', 'префиксов') }),
      r.via ? h('p', { class: 'sm muted', text: r.via }) : null,
      r.count ? h('pre', { class: 'box sm', text: r.sample.join('\n') + (r.count > r.sample.length ? `\n… и ещё ${r.count - r.sample.length}` : '') }) : notice('warn', 'У этой сети нет анонсированных префиксов', 'Проверьте номер AS.'),
      r.count > 3000 ? notice('warn', 'Очень большая сеть', 'Такой список затронет множество посторонних сайтов и сервисов — подумайте, нужен ли он целиком.') : null,
      h('p', { class: 'sm faint', text: (r.v6 ? 'IPv4 и IPv6.' : 'Только IPv4: IPv6 в конфиге выключен.') + ' Вложенные префиксы убраны.' }),
      r.count ? h('div', { class: 'row' }, h('label', { class: 'row', style: 'flex-wrap:nowrap' }, auto, 'обновлять раз в сутки'), h('span', { class: 'grow' }),
        btn(r.exists ? `Обновить ${r.name}` : `Создать список ${r.name}`, async () => {
          const c = await guarded(() => api('asn_create', { asn: r.asn, auto: auto.checked }), 'Готово');
          if (!c) return;
          await loadState().catch(() => {});
          go('#/sites/' + encodeURIComponent(c.name));
        }, 'primary', 'ok')) : null));
  };
  main.append(
    h('div', { class: 'vh' }, h('h1', { text: 'Список по ASN' })),
    panel(null, null,
      h('p', { class: 'sm muted', text: 'Собирает список IP-адресов целой сети (хостинга, облака) по номеру её автономной системы. Нужен, когда блокировка идёт по адресам хостинга, а не по имени сайта. Данные — RIPEstat.' }),
      h('form', { class: 'row', onsubmit: (e) => { e.preventDefault(); look(); } }, q, h('button', { class: 'btn primary', type: 'submit' }, 'Найти', icon('arrow'))),
      h('div', { class: 'recent' }, ASN_PRESETS.map(([n, t]) => h('button', { class: 'chip', type: 'button', text: t, title: 'AS' + n, onclick: () => look('AS' + n) }))),
      h('p', { class: 'sm faint', text: 'Можно ввести имя сайта — роутер найдёт, в чьей сети он живёт. Готовый список подключается к профилю на странице «Профили» (поле «Списки IP»).' })),
    out,
    saved.length ? panel('Собранные списки', null, saved.map((x) => h('div', { class: 'act' }, levelIcon(x.error ? 'warning' : 'ok'),
      h('div', { class: 'item-main' }, h('span', {}, h('a', { class: 'mono', href: '#/sites/' + encodeURIComponent(x.list), text: x.list }), h('span', { class: 'sm faint', text: ` · AS${x.asn} ${x.holder}` })),
        h('span', { class: 'sm muted', text: `${plural(x.count, 'префикс', 'префикса', 'префиксов')} · ${x.auto ? 'обновляется раз в сутки' : 'не обновляется'} · ${fmtDate(x.last)}` + (x.error ? ` · ${x.error}` : '') })),
      btn('Обновить', () => look('AS' + x.asn), 'small ghost', 'refresh'),
      btn(x.auto ? 'Не обновлять' : 'Обновлять', async () => { if (await guarded(() => api('asn_set', { asn: x.asn, auto: !x.auto }))) route(true); }, 'small ghost')))) : null);
}

async function copyText(text) {
  try { await navigator.clipboard.writeText(text); toast('Скопировано'); } catch { prompt('Скопируйте:', text); }
}

// ============ Журнал ============

async function viewLog(main) {
  const data = await api('log');
  const st = S.state;
  let q = '';
  const pre = h('pre', { class: 'box', style: 'max-height:65vh' });
  const draw = () => {
    const lines = (data.syslog || []).slice().reverse().filter((l) => !q || l.toLowerCase().includes(q));
    drawLog(pre, lines, q ? 'Ничего не найдено' : 'Журнал пуст');
  };
  const search = h('input', { class: 'input', id: 'log-filter', placeholder: 'Фильтр', 'aria-label': 'Фильтр журнала', oninput: (e) => { q = e.target.value.toLowerCase(); draw(); } });
  draw();
  main.append(h('div', { class: 'vh' }, h('h1', { text: 'Журнал' }), h('span', { class: 'grow' }), btn('Обновить', () => route(), 'small', 'refresh')),
    data.syslog
      ? panel('Системный журнал nfqws2', null, h('p', { class: 'sm muted', text: 'Последние 300 строк logread, новые сверху.' }), search, pre)
      : panel('Системный журнал nfqws2', null, h('p', { class: 'sm muted', text: 'На Keenetic системный журнал ведёт прошивка: откройте его в веб-интерфейсе роутера (раздел «Диагностика») и найдите строки nfqws2. Файлы журналов nfqws2 показаны ниже.' })),
    data.files.map((f) => panel(f.name, h('span', { class: 'sm muted', text: fmtBytes(f.size) }), (() => { const p = h('pre', { class: 'box' }); drawLog(p, (f.tail || '').split('\n').filter(Boolean), 'пусто'); return p; })())),
    panel('Трафик в очередь nfqws2', null, h('div', { class: 'scroll' }, h('table', { class: 'tbl' },
      h('thead', {}, h('tr', {}, h('th', { text: 'Направление' }), h('th', { text: 'Что' }), h('th', { class: 'num', text: 'Пакетов' }), h('th', { class: 'num', text: 'Объём' }))),
      h('tbody', {}, st.iptables.length ? st.iptables.map((x) => h('tr', {}, h('td', { text: x.dir === 'out' ? 'исходящие' : 'входящие' }), h('td', { text: x.proto + (x.what === 'data' ? ', первые пакеты' : ', ' + x.what) }),
        h('td', { class: 'num', text: x.pkts.toLocaleString('ru-RU') }), h('td', { class: 'num', text: fmtBytes(x.bytes) })))
        : h('tr', {}, h('td', { colspan: 4, class: 'muted', text: 'Правил нет — сервис остановлен?' })))))));
}

// ============ сравнение, история файла, диалоги ============

function diffLines(a, b) {
  const A = a.replace(/\n$/, '').split('\n');
  const B = b.replace(/\n$/, '').split('\n');
  const n = A.length, m = B.length;
  if (n * m > 4e6) {
    const sa = new Set(A), sb = new Set(B);
    return [...A.filter((x) => !sb.has(x)).map((t) => ['-', t]), ...B.filter((x) => !sa.has(x)).map((t) => ['+', t])];
  }
  const W = m + 1;
  const dp = new Uint32Array((n + 1) * W);
  for (let i = n - 1; i >= 0; i--) for (let j = m - 1; j >= 0; j--) dp[i * W + j] = A[i] === B[j] ? dp[(i + 1) * W + j + 1] + 1 : Math.max(dp[(i + 1) * W + j], dp[i * W + j + 1]);
  const out = [];
  let i = 0, j = 0;
  while (i < n && j < m) {
    if (A[i] === B[j]) { out.push([' ', A[i]]); i++; j++; }
    else if (dp[(i + 1) * W + j] >= dp[i * W + j + 1]) out.push(['-', A[i++]]);
    else out.push(['+', B[j++]]);
  }
  while (i < n) out.push(['-', A[i++]]);
  while (j < m) out.push(['+', B[j++]]);
  return out;
}

function renderDiff(ops) {
  const box = h('div', { class: 'diff' });
  if (!ops.some((o) => o[0] !== ' ')) { box.append(h('div', { class: 'gap', text: 'Без изменений' })); return box; }
  const CTX = 3;
  const keep = ops.map(() => false);
  ops.forEach((op, k) => { if (op[0] !== ' ') for (let d = -CTX; d <= CTX; d++) if (ops[k + d]) keep[k + d] = true; });
  let skipped = 0;
  const flush = () => { if (skipped) { box.append(h('div', { class: 'gap', text: `… ${plural(skipped, 'строка без изменений', 'строки без изменений', 'строк без изменений')}` })); skipped = 0; } };
  ops.forEach((op, k) => {
    if (!keep[k]) { skipped++; return; }
    flush();
    box.append(h('div', { class: op[0] === '+' ? 'add' : op[0] === '-' ? 'del' : '', text: (op[0] === ' ' ? '  ' : op[0] + ' ') + op[1] }));
  });
  flush();
  return box;
}

function modal(title, body, footer) {
  const close = () => { bg.remove(); document.removeEventListener('keydown', esc); };
  const esc = (e) => { if (e.key === 'Escape') close(); };
  const bg = h('div', { class: 'modal-bg', onclick: (e) => { if (e.target === bg) close(); } },
    h('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
      h('div', { class: 'modal-h' }, h('h2', { text: title }), h('button', { class: 'btn ghost icon', type: 'button', onclick: close, 'aria-label': 'Закрыть' }, icon('x'))),
      body, h('div', { class: 'modal-f' }, btn('Закрыть', close, 'ghost'), footer)));
  document.addEventListener('keydown', esc);
  document.body.append(bg);
  bg.close = close;
  return bg;
}

// ============ Markdown: README, история версий, описание релиза ============
// Небольшой разбор без внешних библиотек: заголовки, абзацы, вложенные списки, таблицы, код, цитаты, ссылки.
// HTML и картинки пропускаются. Всё строится через DOM, поэтому текст из релиза не может выполнить код.

const mdSlug = (s) => s.toLowerCase().replace(/[^\p{L}\p{N}\s-]/gu, '').trim().replace(/\s/g, '-');

function mdInline(s, onAnchor) {
  const out = [];
  const re = /`([^`]+)`|\*\*(.+?)\*\*|\[([^\]]*)\]\(([^)\s]+)\)/g;
  let last = 0;
  for (const m of s.matchAll(re)) {
    if (m.index > last) out.push(s.slice(last, m.index));
    if (m[1] !== undefined) out.push(h('code', { text: m[1] }));
    else if (m[2] !== undefined) out.push(h('b', {}, mdInline(m[2], onAnchor)));
    else {
      const href = m[4];
      const kids = mdInline(m[3], onAnchor);
      if (href.startsWith('#')) out.push(h('a', { href: '#', onclick: (e) => { e.preventDefault(); onAnchor?.(decodeURIComponent(href.slice(1))); } }, kids));
      else if (/^https?:\/\//.test(href)) out.push(h('a', { href, target: '_blank', rel: 'noopener' }, kids));
      else if (/^CHANGELOG\.md$/i.test(href)) out.push(h('a', { href: '#/settings/changelog' }, kids));
      else if (/^README\.md$/i.test(href)) out.push(h('a', { href: '#/settings/readme' }, kids));
      else out.push(h('a', { href: REPO + '/blob/main/' + href.replace(/^\.?\//, ''), target: '_blank', rel: 'noopener' }, kids));
    }
    last = m.index + m[0].length;
  }
  if (last < s.length) out.push(s.slice(last));
  return out;
}

function md(text, onAnchor) {
  const lines = text.replace(/\r/g, '').split('\n');
  const root = h('div', { class: 'md' });
  const inl = (s) => mdInline(s, onAnchor);
  let para = [];
  const flush = () => { if (para.length) root.append(h('p', {}, inl(para.join(' ')))); para = []; };
  const isList = (l) => /^(\s*)([-*]|\d+\.)\s+/.exec(l);
  for (let i = 0; i < lines.length; i++) {
    const l = lines[i];
    // картинки, значки и HTML-вставки (скриншоты) — пропускаем
    if (/^\s*(\[?!\[|<\/?(p|img|br|div|details|summary)\b)/i.test(l)) { flush(); continue; }
    if (/^```/.test(l)) {
      flush();
      const code = [];
      while (++i < lines.length && !/^```/.test(lines[i])) code.push(lines[i]);
      root.append(h('pre', { class: 'box', text: code.join('\n') }));
      continue;
    }
    const hm = /^(#{1,4})\s+(.*)$/.exec(l);
    if (hm) {
      flush();
      root.append(h('h' + Math.min(hm[1].length + 1, 5), { id: 'md-' + mdSlug(hm[2]) }, inl(hm[2])));
      continue;
    }
    if (/^\|/.test(l)) {
      flush();
      const rows = [];
      for (; i < lines.length && /^\|/.test(lines[i]); i++) rows.push(lines[i].replace(/^\||\|\s*$/g, '').split('|').map((c) => c.trim()));
      i--;
      const body = rows.filter((r) => !r.every((c) => /^:?-+:?$/.test(c)));
      const head = rows.length > 1 && rows[1].every((c) => /^:?-+:?$/.test(c)) ? body.shift() : null;
      root.append(h('div', { class: 'scroll' }, h('table', { class: 'tbl' },
        head && head.some(Boolean) ? h('thead', {}, h('tr', {}, head.map((c) => h('th', {}, inl(c))))) : null,
        h('tbody', {}, body.map((r) => h('tr', {}, r.map((c) => h('td', {}, inl(c)))))))));
      continue;
    }
    if (/^>\s?/.test(l)) {
      flush();
      const q = [];
      for (; i < lines.length && /^>\s?/.test(lines[i]); i++) q.push(lines[i].replace(/^>\s?/, ''));
      i--;
      root.append(h('blockquote', {}, inl(q.join(' '))));
      continue;
    }
    if (isList(l)) {
      flush();
      // Список со вложенностью по отступу; строки с отступом без маркера — продолжение пункта
      const top = { el: null, indent: -1, items: [] };
      const stack = [top];
      let cur = null;
      for (; i < lines.length; i++) {
        const x = lines[i];
        const lm = isList(x);
        if (lm) {
          const ind = lm[1].length;
          const ordered = /\d/.test(lm[2]);
          while (stack.length > 1 && ind < stack[stack.length - 1].indent) stack.pop();
          let lvl = stack[stack.length - 1];
          if (ind > lvl.indent) {
            const el = h(ordered ? 'ol' : 'ul');
            (cur || root).append(el);
            lvl = { el, indent: ind };
            stack.push(lvl);
          }
          cur = h('li', {}, inl(x.slice(lm[0].length)));
          lvl.el.append(cur);
          lvl.li = cur;
        } else if (/^\s+\S/.test(x) && cur) {
          // продолжение относится к пункту, у которого маркер левее отступа строки
          const ind = x.length - x.trimStart().length;
          const owner = [...stack].reverse().find((v) => v.li && v.indent < ind) || stack[stack.length - 1];
          owner.li.append(h('p', {}, inl(x.trim())));
        } else if (x.trim() === '' && (isList(lines[i + 1] || '') || /^\s+\S/.test(lines[i + 1] || ''))) {
          continue;
        } else {
          i--;
          break;
        }
      }
      continue;
    }
    if (l.trim() === '') { flush(); continue; }
    if (/^---+$/.test(l.trim())) { flush(); root.append(h('hr')); continue; }
    para.push(l.trim());
  }
  flush();
  return root;
}

// Страница с документом из пакета: README или история версий
async function paneDoc(content, name) {
  const r = await api('doc', { name });
  let body;
  const toAnchor = (id) => body.querySelector('#md-' + CSS.escape(id))?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  body = md(r.text, toAnchor);
  const heads = [...body.querySelectorAll('h3')];
  content.append(h('div', { class: 'vh' }, h('h1', { text: name === 'readme' ? 'Справка' : 'Изменения по версиям' }), h('span', { class: 'grow' }),
    h('a', { class: 'sm', href: REPO + (name === 'readme' ? '#readme' : '/blob/main/CHANGELOG.md'), target: '_blank', rel: 'noopener', text: 'на GitHub' })),
    heads.length > 3 ? panel(null, null, h('div', { class: 'md-toc' }, heads.map((x) =>
      h('a', { href: '#', onclick: (e) => { e.preventDefault(); x.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, text: x.textContent })))) : null,
    panel(null, null, body));
}

// ============ подсветка журналов ============
// Время, уровень syslog, процесс, пути, файлы, параметры, адреса, ошибки и предупреждения. Строка с ошибкой
// или предупреждением выделяется целиком.

const LOG_RE = new RegExp([
  String.raw`(?<time>^\w{3} \w{3} +\d+ \d\d:\d\d:\d\d \d{4}|\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d(?:[.,]\d+)?|\b\d\d:\d\d:\d\d(?:\.\d+)?\b)`,
  String.raw`(?<lvl>\b(?:kern|user|daemon|auth|authpriv|syslog|cron|local\d)\.(?:emerg|alert|crit|err|warn|warning|notice|info|debug)\b)`,
  String.raw`(?<proc>\b[\w.-]+\[\d+\]:)`,
  String.raw`(?<url>https?://[^\s"'<>]+)`,
  String.raw`(?<path>(?<![\w/.-])/(?:[\w.+-]+/?)+)`,
  String.raw`(?<opt>(?<![\w-])--[a-z][\w-]*)`,
  String.raw`(?<ip>\b\d{1,3}(?:\.\d{1,3}){3}(?::\d{1,5})?(?:/\d{1,2})?\b)`,
  String.raw`(?<file>\b[\w.-]+\.(?:list|lua|bin|conf|log|json|ipk|sh|txt|gz|pcap)\b)`,
  String.raw`(?<err>\b(?:errors?|fail(?:ed|ure|s)?|fatal|cannot|can't|could not|denied|refused|invalid|not found|no such|timed? ?out|abort(?:ed)?)\b|[Оо]шибк[а-я]*|не удалось|не найден[а-я]*|нет доступа|НЕ РАБОТАЕТ)`,
  String.raw`(?<warn>\b(?:warn(?:ing)?s?|deprecated|skipp(?:ed|ing)|retry(?:ing)?)\b|[Вв]нимание|[Пп]редупрежд[а-я]*)`,
  String.raw`(?<ok>\b(?:OK|ok|success(?:ful(?:ly)?)?|started|ready|done|loaded|matches)\b|[Гг]отово|установлен|запущен)`,
].join('|'), 'giu');

function logLine(line) {
  const out = [];
  let last = 0;
  let mark = '';
  for (const m of line.matchAll(LOG_RE)) {
    const kind = Object.keys(m.groups).find((k) => m.groups[k] !== undefined);
    let cls = kind;
    if (kind === 'lvl') {
      const l = m[0].split('.')[1];
      // busybox crond пишет каждый запуск задания как cron.err — это не ошибка
      cls = /emerg|alert|crit|err/.test(l) && !(m[0] === 'cron.err' && / cmd /.test(line)) ? 'err' : /warn/.test(l) ? 'warn' : 'lvl';
    }
    if (cls === 'err') mark = 'err';
    else if (cls === 'warn' && !mark) mark = 'warn';
    if (m.index > last) out.push(line.slice(last, m.index));
    out.push(h('span', { class: 'lg-' + cls, text: m[0] }));
    last = m.index + m[0].length;
  }
  if (last < line.length) out.push(line.slice(last));
  return h('div', { class: 'lg' + (mark ? ' lg-' + mark + '-line' : '') }, out.length ? out : '\u00a0');
}

// Журнал в элемент pre: строки с подсветкой или текст-заглушка
function drawLog(pre, lines, empty = 'Пусто') {
  pre.classList.add('logv');
  pre.replaceChildren(...(lines.length ? lines.map(logLine) : [h('div', { class: 'lg muted', text: empty })]));
}

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
    const text = await (await fetch('index.html?_=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' })).text();
    const onRouter = text.match(/app\.js\?v=([^"]+)/)?.[1];
    if (!onRouter || onRouter === BUILD || document.getElementById('stale')) return;
    const reload = async () => {
      // обновляем запись в кеше браузера и только потом перезагружаем страницу
      await fetch('index.html', { cache: 'reload', credentials: 'same-origin' }).catch(() => {});
      location.reload();
    };
    document.getElementById('upd-banner')?.before(h('div', { id: 'stale', class: 'banner info' }, h('div', { class: 'banner-in' },
      h('span', { text: 'Интерфейс на роутере обновился, а в браузере открыта прежняя сборка.' }), btn('Перезагрузить', reload, 'small primary', 'refresh'))));
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
  const log = h('pre', { class: 'box', style: 'max-height:40vh' });
  const status = h('div');
  const body = h('div', { class: 'modal-b stack', style: 'gap:12px' },
    h('p', {}, `Установлено: `, h('b', { text: u.current }), ` · последняя: `, h('b', { text: u.latest || '—' }), ' · ', h('a', { href: u.url || REPO + '/releases', target: '_blank', rel: 'noopener', text: 'страница релиза' })),
    u.notes ? h('div', { class: 'release-notes' }, md(u.notes)) : h('p', { class: 'sm muted', text: 'Описания изменений нет.' }),
    h('p', { class: 'sm muted' }, 'Обновление ставит пакет с GitHub (с проверкой контрольной суммы) и перезапускает веб-сервер интерфейса. nfqws2, конфиг, списки и доступ в интернет не затрагиваются. Из консоли: ', h('code', { text: 'nfqws-ui update' })),
    status, log);
  log.hidden = true;
  const go = btn('Обновить до ' + u.latest, () => start(), 'primary', 'download', { disabled: !u.can_update || u.running });
  const dlg = modal('nfqws2-ui ' + (u.latest || ''), body, u.available ? go : []);
  let timer = null;
  const poll = async () => {
    const r = await api('update_status').catch(() => null);   // пока lighttpd перезапускается, ответа нет — ждём
    if (!dlg.isConnected) return;
    if (r) {
      log.hidden = false;
      drawLog(log, r.log.trim().split('\n').filter(Boolean));
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
    if (!confirm(`Обновить nfqws2-ui до ${u.latest}?\n\nВеб-сервер интерфейса перезапустится — страница на несколько секунд перестанет отвечать. nfqws2 и интернет это не затрагивает.`)) return;
    go.disabled = true;
    const r = await guarded(() => api('update_run', { version: u.latest }));
    if (!r) { go.disabled = false; return; }
    status.replaceChildren(h('div', { class: 'row sm' }, h('span', { class: 'spin' }), 'Обновляю… не закрывайте окно'));
    clearTimeout(timer);
    poll();
  }
  if (u.running) poll();
  if (run && u.available && u.can_update && !u.running) start();
}

function openDiff(title, a, b) {
  modal(title, h('div', { class: 'modal-b' }, h('p', { class: 'sm muted', text: 'Красным — как сохранено, зелёным — как сейчас в редакторе.' }), renderDiff(diffLines(a, b))), []);
}

async function openHistory(name, onRestored) {
  const isConf = name === 'nfqws2.conf';
  const body = h('div', { class: 'modal-b' }, spinner('Загрузка…'));
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
      if (!confirm('Заменить файл выбранной версией? Текущая версия останется в истории.')) return;
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
}

// ============ старт ============

async function start() {
  try {
    const s = await api('session');
    S.auth = s.auth;
    S.authEnabled = s.auth_enabled;
  } catch (e) {
    document.getElementById('app').replaceChildren(h('div', { class: 'login' }, notice('bad', 'Роутер не отвечает', e.message)));
    return;
  }
  if (!S.auth) { renderLogin(); return; }
  S.check ||= createCheck();
  renderShell();
  SIDE.root = null;
  route(true);
  layoutNote();
  setTimeout(autoUpdateCheck, 3000);
  setTimeout(checkFresh, 1500);
}

setInterval(() => {
  if (S.auth && document.visibilityState === 'visible' && !document.querySelector('.modal-bg') && !document.activeElement?.matches('textarea, input, select')) {
    loadState().catch(() => {});
  }
}, 30000);

start();

// Один общий обработчик: клик мимо выпадающего меню закрывает все открытые меню
document.addEventListener('click', (e) => {
  document.querySelectorAll('.menu-list:not([hidden])').forEach((list) => {
    if (!list.parentElement.contains(e.target)) list.hidden = true;
  });
});
