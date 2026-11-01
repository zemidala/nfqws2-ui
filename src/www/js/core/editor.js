'use strict';
// Interface: the code editor with highlighting and input hints.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ редактор с подсветкой ============

function argPieces(tok) {
  if (isNewArg(tok)) return [[tok, 'tk-new']];
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

const ED_PLAIN_CHARS = 200000;   // больше — без подсветки и номеров строк

// «#» в начале строк под курсором или выделением: если все непустые строки закомментированы — снять, иначе поставить.
// Замена через insertText, чтобы Ctrl+Z её отменял (прямая запись в value стирает историю отмены)
function toggleComment(ta) {
  const v = ta.value;
  const s0 = ta.selectionStart, e0 = ta.selectionEnd;
  const from = v.lastIndexOf('\n', s0 - 1) + 1;
  const endAt = e0 > s0 && v[e0 - 1] === '\n' ? e0 - 1 : e0;
  let to = v.indexOf('\n', endAt);
  if (to < 0) to = v.length;
  const lines = v.slice(from, to).split('\n');
  const filled = lines.filter((l) => l.trim());
  if (!filled.length) return;
  const off = filled.every((l) => /^\s*#/.test(l));
  const out = lines.map((l) => !l.trim() ? l : off ? l.replace(/^(\s*)# ?/, '$1') : '# ' + l).join('\n');
  ta.focus();
  ta.setSelectionRange(from, to);
  if (!document.execCommand('insertText', false, out)) {
    ta.setRangeText(out, from, to, 'end');
    ta.dispatchEvent(new Event('input'));
  }
  // одна строка без выделения — курсор остаётся на своём месте в тексте строки
  if (s0 === e0 && lines.length === 1) { const p = Math.max(from, s0 + out.length - (to - from)); ta.setSelectionRange(p, p); }
  else ta.setSelectionRange(from, from + out.length);
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
    // большой текст (списки на сотни тысяч строк) — простое поле: подсветка и номера строк
    // на каждую строку вешают телефон и переполняют стек
    const big = text.length > ED_PLAIN_CHARS;
    el.classList.toggle('plain', big);
    if (big) { hl.replaceChildren(); gut?.replaceChildren(); sync(); return; }
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
      const lines = document.createDocumentFragment();
      for (let i = 0; i < n; i++) lines.append(h('div', { class: marks[i] === 'error' ? 'e' : marks[i] === 'warning' ? 'w' : marks[i] === 'info' ? 'i' : null, text: String(i + 1) }));
      lines.append(h('div', { text: ' ' }));
      gut.replaceChildren(lines);
    }
    sync();
  }
  const caret = () => { onCaret?.(ta.selectionStart, ta.value); suggest?.update(ta); };
  ta.addEventListener('input', () => { paint(); fit(); onInput?.(ta.value); caret(); });
  ta.addEventListener('scroll', sync);
  ['keyup', 'click', 'focus'].forEach((ev) => ta.addEventListener(ev, caret));
  ta.addEventListener('keydown', (e) => {
    if (e.key === 'Tab' && suggest?.accept(ta)) { e.preventDefault(); paint(); onInput?.(ta.value); }
    // Ctrl+/ (⌘+/) — закомментировать или раскомментировать строки; только в списках:
    // в конфиге строки стратегий стоят внутри кавычек, и «#» там не комментарий, а аргумент nfqws2
    if (mode === 'list' && (e.ctrlKey || e.metaKey) && !e.altKey && (e.code === 'Slash' || e.key === '/')) { e.preventDefault(); toggleComment(ta); }
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
  let tagged = false;
  return h('ul', { class: 'iss-list' }, issues.map((x) => {
    const tag = x.key && !tagged ? (tagged = true, newTag('raw:hide')) : null;
    const li = h('li', {},
      h('div', { class: 'row iss-row' },
        h('button', { type: 'button', class: 'iss-item', onclick: () => onPick?.(x) }, levelIcon(x.level),
          h('span', {}, lineOf ? h('span', { class: 'muted', text: lineOf(x) + ': ' }) : null, x.msg)),
        h('div', { class: 'iss-acts' }, fixFor(x),
          // config warnings and tips only (they carry a key); errors stay — nfqws2 will not start with them
          x.key ? btn('Не показывать', async () => { if (await lintHide(x, true)) li.remove(); }, 'small', null,
            { title: 'Скрыть это замечание на всех страницах и в «Проблемах». Вернуть — «Скрыто» в «Конфиге целиком»' }) : null, tag)));
    return li;
  }));
}

// «Don't show» on a config note (or show it again). The router keeps the list, so every browser sees the same.
// A page that keeps its own copy of the notes (the config editor) sets S.lintHook to re-check after the change.
async function lintHide(x, on) {
  const r = await guarded(() => api('lint_hide', { key: x.key, msg: x.msg, on }));
  if (!r) return false;
  S.state.lint = r.lint;
  updateSide();
  if (S.lintHook?.()) { /* the config editor re-checks itself */ }
  // a page without its own hook (a profile, «Основное») shows the note again by redrawing, unless there are unsaved edits
  else if (!on && !S.dirty && !document.querySelector('.modal-bg')) route();
  if (on) toast('Замечание скрыто', { undo: () => lintHide(x, false) });
  return true;
}

function hiddenNotesDialog() {
  const list = h('div', { class: 'stack', style: 'gap:0' });
  const draw = () => {
    const hid = Object.entries(S.state.lint?.hidden || {});
    list.replaceChildren(hid.length ? hid.map(([key, msg]) => h('div', { class: 'row hid-row' }, h('span', { class: 'grow', text: msg }),
      btn('Показать снова', async () => { if (await lintHide({ key, msg }, false)) draw(); }, 'small'))): h('p', { class: 'muted', text: 'Скрытых замечаний нет.' }));
  };
  draw();
  modal('Скрытые замечания', h('div', {}, h('p', { class: 'sm muted', style: 'margin-top:0', text: 'Ошибки не скрываются. Если в конфиге изменится то, о чём говорит замечание, и его текст станет другим, оно снова появится.' }), list));
}
