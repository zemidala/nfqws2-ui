'use strict';
// Interface: small helpers: buttons, notices, dates, plurals, API calls.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ утилиты ============

function h(tag, attrs, ...kids) {
  const e = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v == null || v === false) continue;
    if (k === 'class') e.className = v;
    else if (k === 'text') e.textContent = v;
    else if (k.startsWith('on')) { e.addEventListener(k.slice(2), v); (e.__on ||= []).push([k.slice(2), v]); }   // morph swaps them
    else if (k === 'value') e.value = v;
    else if (k === 'checked' || k === 'selected' || k === 'disabled') e[k] = !!v;
    else e.setAttribute(k, v === true ? '' : v);
  }
  e.append(...kids);
  return e;
}

// Bring a block drawn earlier to the look of a freshly drawn one, touching only what differs: a page that polls the
// router redraws its blocks every few seconds, and replacing them made everything blink, closed open <details>, lost
// text selection and restarted spinners. Nodes match by position, tag and data-key (give list rows a key so a row is
// never reused for another item). Listeners set through h() move to the kept node: a button in a row whose data
// changed acts on the new data. Listeners added by hand and typed values stay those of the kept node. «open» is the
// reader's: it is never synced
function morph(old, fresh) {
  if (old.nodeType !== fresh.nodeType || old.nodeName !== fresh.nodeName
    || (old.nodeType === 1 && (old.dataset?.key ?? '') !== (fresh.dataset?.key ?? ''))) {
    old.replaceWith(fresh);
    return fresh;
  }
  if (old.nodeType !== 1) {
    if (old.nodeValue !== fresh.nodeValue) old.nodeValue = fresh.nodeValue;
    return old;
  }
  // a widget whose handlers reach its own other nodes (a button opening its own menu list) is never merged: it stays
  // untouched while drawn the same, or is replaced as a whole
  if (fresh.dataset?.whole) {
    const sig = fresh.outerHTML;
    old.__sig ??= old.outerHTML;   // drawn by a plain insert: its look then is its signature
    if (old.__sig === sig) return old;
    fresh.__sig = sig;
    old.replaceWith(fresh);
    return fresh;
  }
  for (const a of [...old.attributes]) if (a.name !== 'open' && !fresh.hasAttribute(a.name)) old.removeAttribute(a.name);
  for (const a of fresh.attributes) if (a.name !== 'open' && old.getAttribute(a.name) !== a.value) old.setAttribute(a.name, a.value);
  if ('disabled' in old && old.disabled !== fresh.disabled) old.disabled = fresh.disabled;
  if (old.__on || fresh.__on) {
    for (const [t, f] of old.__on || []) old.removeEventListener(t, f);
    for (const [t, f] of fresh.__on || []) old.addEventListener(t, f);
    old.__on = fresh.__on;
  }
  morphKids(old, [...fresh.childNodes]);
  return old;
}
// Give a block or row its key for morph; empty values pass through
const keyed = (el, k) => { if (el && el.nodeType === 1) el.dataset.key = k; return el; };

// Children: one with a data-key is matched to the old child with the same key wherever it is (a block that comes or
// goes, like a progress bar, does not shift its neighbours onto each other); the rest — by position. After each step
// the i-th child is the node for kids[i], so what is left past the end is what went away
function morphKids(parent, kids) {
  kids = kids.flat(Infinity).filter((k) => k != null && k !== false).map((k) => typeof k === 'string' ? document.createTextNode(k) : k);
  const keyOf = (n) => n.nodeType === 1 ? n.dataset.key || '' : '';
  const byKey = new Map();
  for (const c of parent.childNodes) if (keyOf(c)) byKey.set(keyOf(c), c);
  kids.forEach((k, i) => {
    const cur = parent.childNodes[i] || null;
    let old = cur && !keyOf(cur) ? cur : null;
    if (keyOf(k)) { old = byKey.get(keyOf(k)) || null; byKey.delete(keyOf(k)); }   // a repeated key gets a new node
    if (!old) parent.insertBefore(k, cur);
    else {
      if (old !== cur) parent.insertBefore(old, cur);
      morph(old, k);
    }
  });
  while (parent.childNodes.length > kids.length) parent.lastChild.remove();
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

// a text label goes into its own element: only an element can have its line box trimmed to the capitals (.btn > btn-l)
// a link to another page that sits in a block header — drawn as a small button like the other actions
const linkBtn = (href, label = 'Открыть', attrs = {}) => h('a', { class: 'btn small', href, ...attrs }, h('btn-l', {}, label));
const btn = (label, onclick, cls = '', ic = null, attrs = {}) =>
  h('button', { class: 'btn ' + cls, type: 'button', onclick, ...attrs }, ic && icon(ic), typeof label === 'string' && label ? h('btn-l', {}, label) : label);
// Copy button: copies get() and shows a tick on itself for 1.5 s (label stays — the button keeps its width).
// No label — icon only, for short texts (commands, code blocks)
function copyBtn(get, label = 'Копировать', cls = 'small ghost') {
  const b = btn(label, async () => {
    if (!await copyText(get(), true)) return;
    b.classList.add('copied');
    b.title = 'Скопировано';
    b.querySelector('svg')?.replaceWith(icon('ok'));
    clearTimeout(b.tm);
    b.tm = setTimeout(() => { b.classList.remove('copied'); b.title = label ? '' : 'Скопировать'; b.querySelector('svg')?.replaceWith(icon('copy')); }, 1500);
  }, cls + (label ? '' : ' icon'), 'copy', { 'data-whole': '1', ...(label ? {} : { title: 'Скопировать', 'aria-label': 'Скопировать' }) });   // it changes itself
  return b;
}
// Text of a <pre>: log views draw one <div> per line
const preText = (pre) => (pre.classList.contains('logv') ? [...pre.children].filter((x) => !x.classList.contains('muted')).map((x) => x.textContent).join('\n') : pre.textContent);
// <pre> with the copy icon in its top-right corner (code blocks, command output)
const copyBox = (pre) => h('div', { class: 'copybox', 'data-whole': '1' }, pre, copyBtn(() => preText(pre), '', 'small'));   // the button reads its own pre
// command with the copy icon right after it
const copyCode = (text, cls = 'mono') => h('span', { class: 'codewrap' }, h('code', { class: cls, text }), copyBtn(() => text, ''));
const chip = (text, cls = '') => h('span', { class: 'chip ' + cls, text });
// Подбор идёт с роутера, а у него временные метки TCP есть; Windows по умолчанию их не шлёт
const WIN_TS_CMD = 'netsh interface tcp set global timestamps=enabled';
const WIN_TS_HINT = `включите в Windows временные метки TCP — командная строка от имени администратора: ${WIN_TS_CMD}`;
// шаг, у которого из маскировок только tcp_ts=N: без меток в пакете nfqws2 её пропускает, фейк уходит как настоящий
const tsOnlyStep = (step) => {
  const parts = step.replace(/^--lua-desync=/, '').split(':');
  if (parts.some((x) => /^(tcp_md5|badsum|tcp_seq=|tcp_ack=|ip_ttl=|ip6_ttl=|ip_autottl=|ip6_autottl=|tcp_flags_(set|unset)=|ip6_(hopbyhop|destopt|routing|ah)|fool=)/.test(x))) return false;
  return parts.some((x) => x.startsWith('tcp_ts='));
};
const needsTs = (steps) => (steps || []).some(tsOnlyStep);
const tsChip = () => h('span', { class: 'chip', text: 'нужны метки TCP', title: `Маскировка только через tcp_ts: работает на устройствах с временными метками TCP (Linux, Android, macOS, iOS). На Windows — ${WIN_TS_HINT}` });
const spinner = (text) => h('div', { class: 'skeleton' }, h('span', { class: 'spin' }), text);
// Placeholder shaped like the content while it loads (a panel: heading and lines); fades in only after 0.15 s, so a
// fast load shows nothing. Spinners with a text stay where the wait is an action («Проверяю…»), not plain loading.
const skel = (panels = 1, lines = 4) => h('div', { class: 'skel', role: 'status', 'aria-busy': 'true' },
  h('span', { class: 'sr-only', text: 'Загрузка…' }),
  Array.from({ length: panels }, (_, p) => h('div', { class: 'skel-panel' }, h('i', { class: 'h' }),
    Array.from({ length: lines }, (_, i) => h('i', { style: `width:${[92, 78, 85, 64, 88, 70][(i + p) % 6]}%` })))));
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
// «\» в конце строки — перенос значения по строкам (оболочка его убирает), а не параметр
const tokensOf = (s) => (s || '').replace(/\\[ \t]*\r?\n/g, ' ').trim().split(/\s+/).filter(Boolean);

function toast(text, opts = {}) {
  const t = h('div', { class: 'toast' + (opts.err ? ' err' : ''), role: opts.err ? 'alert' : 'status' }, h('span', { text }));
  if (opts.undo) t.append(h('button', { type: 'button', text: 'Вернуть', onclick: () => { t.remove(); opts.undo(); } }));
  document.getElementById('toasts').append(t);
  setTimeout(() => t.remove(), opts.long ? 10000 : opts.undo ? 7000 : opts.err ? 7000 : 3000);
}

class ApiError extends Error {
  constructor(msg, data) { super(msg); this.data = data; }
}

// На слабом роутере (state.lowmem) — по одному запросу за раз: каждый запрос там — отдельный php-cgi на 10–15 МБ,
// и страница, разом запросившая несколько, вместе с проверкой сайтов съедала всю память роутера (NFQ-L130)
let apiBusy = 0;
const apiWaiting = [];
function apiTake() {
  if (apiBusy < (S.state?.lowmem ? 1 : 8)) { apiBusy++; return Promise.resolve(); }
  return new Promise((ok) => apiWaiting.push(ok));
}
function apiGive() {
  const next = apiWaiting.shift();
  if (next) next(); else apiBusy--;
}

async function api(cmd, data = {}) {
  await apiTake();
  let r;
  try {
    r = await fetch('api.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ cmd, ...data }),
    });
  } finally { apiGive(); }
  let j = null;
  const raw = await r.text().catch(() => '');
  try { j = JSON.parse(raw); } catch { /* не JSON */ }
  if (r.status === 401 && cmd !== 'login') {
    S.auth = false;
    renderLogin();
    throw new ApiError('Нужно войти', j || {});
  }
  if (!r.ok) throw new ApiError(j?.error || 'Ошибка ' + r.status, j || {});
  // пустой или не-JSON ответ — запрос не удался (процесс PHP погиб, сервер напечатал ошибку), а не «пустые данные»:
  // иначе страница падала дальше на отсутствующем поле с непонятным «Cannot read properties of undefined»
  if (!j || typeof j !== 'object') {
    const txt = raw.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    throw new ApiError(txt ? 'Роутер ответил не так, как ожидалось: ' + txt.slice(0, 200) : 'Роутер прислал пустой ответ — возможно, не хватило памяти', {});
  }
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

// navigator.clipboard needs a secure context: over plain http by a non-local address it is absent, then the old
// execCommand('copy') through a hidden field still works. The prompt is the last resort
async function copyText(text, quiet = false) {
  let ok = false;
  try { await navigator.clipboard.writeText(text); ok = true; } catch {
    const was = document.activeElement;
    const ta = h('textarea', { readonly: '', style: 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0' });
    ta.value = text;
    document.body.append(ta);
    ta.select();
    try { ok = document.execCommand('copy'); } catch { /* not supported */ }
    ta.remove();
    was?.focus?.({ preventScroll: true });
  }
  if (!ok) prompt('Скопируйте:', text);
  else if (!quiet) toast('Скопировано');
  return ok;
}

// Опрос хода проверки: следующий запрос — только после ответа на предыдущий и тем реже, чем дольше отвечает роутер.
// На слабом роутере (MIPS) запрос идёт около секунды, под нагрузкой подбора — дольше; с setInterval запросы
// накладывались, php-cgi запускались один поверх другого, и роутер переставал отвечать. tick() → false — хватит
let testPoll = null;
let testPollGen = 0;
function pollTest(tick, base) {
  stopTestPoll();
  const gen = testPollGen;
  const run = async () => {
    const t0 = performance.now();
    const more = await tick();
    if (more === false || gen !== testPollGen) return;
    testPoll = setTimeout(run, Math.max(base, 3 * (performance.now() - t0)));
  };
  run();
}

function stopTestPoll() {
  testPollGen++;
  clearTimeout(testPoll);
}

// bare — без заголовка: страница встроена в карточку сайта
// Сайты задания, которые podkop пускает в туннель: проверка с роутера уходит туда же, nfqws2 её не видит
function podkopNote(s) {
  const list = s?.podkop || [];
  if (!list.length) return null;
  const one = list.length === 1;
  return notice('warn', one ? `${list[0]} идёт через podkop` : `Через podkop идут: ${list.join(', ')}`,
    `Для ${one ? 'этого сайта' : 'этих сайтов'} роутер получает служебный адрес podkop (198.18.x), и проверочные запросы уходят в туннель, а не через nfqws2. Поэтому ${one ? 'его результат' : 'их результаты'} показывают работу туннеля: все стратегии и конфиги выглядят одинаково. Чтобы сравнить стратегии, возьмите сайт, который не идёт через podkop, или на время уберите этот сайт из podkop.`,
    newTag('pick:podkop'));
}
