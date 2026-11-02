'use strict';
// Interface: page: the whole config as text.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

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
    onInput: (val) => { mark(val !== conf.content); relint(val); drawOutline(); drawStatus(); },
    onCaret: (pos, text) => {
      const before = text.slice(0, pos).split('\n');
      caretInfo = `строка ${before.length}, столбец ${before[before.length - 1].length + 1}`;
      drawStatus();
      const t = tokAt(pos, text);
      renderHelp(help, t ? helpFor(t.text, pos - t.start) : null, t ? issues.filter((x) => x.var === t.name && x.tok === t.i) : null);
    },
  });
  const locate = (x) => {
    // a whole-config note (no var) may carry «at» — the place it is about (the first comment in quotes)
    const at = x.var ? x : x.at;
    const rg = at && confVarRanges(ed.ta.value)[at.var];
    if (!rg) return null;
    if (at.tok == null) return { start: rg.start, end: rg.start };
    const t = tokenize(ed.ta.value.slice(rg.start, rg.end))[at.tok];
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
  // full screen: notes and help fold into a strip at the bottom and slide up over the text on a click
  function drawDrawer() {
    const n = issues.length;
    drawerBtn.replaceChildren(icon('chev'), h('span', { text: n ? `Замечания: ${n} · справка` : 'Справка по аргументу' }));
    drawerBtn.setAttribute('aria-expanded', String(cfgEl.classList.contains('xopen')));
  }
  function drawCounts() {
    drawDrawer();
    const e = issues.filter((x) => x.level === 'error').length;
    const w = issues.filter((x) => x.level === 'warning').length;
    const notes = issues.filter((x) => x.level === 'info' && (x.var || x.at));
    const hidN = Object.keys(S.state.lint?.hidden || {}).length;
    // замечание «к сведению» подчёркнуто в тексте — по метке курсор встаёт на место, справка под редактором показывает текст
    let ni = 0;
    const toNote = () => { const x = notes[ni++ % notes.length]; const p = locate(x); if (p) ed.select(p.start, p.end); };
    counts.replaceChildren(e ? chip(plural(e, 'ошибка', 'ошибки', 'ошибок'), 'bad') : null, w ? chip(plural(w, 'предупреждение', 'предупреждения', 'предупреждений'), 'warn') : null, !e && !w ? chip('ошибок нет', 'ok') : null,
      notes.length ? h('button', { type: 'button', class: 'chip', title: 'Не ошибка, а пояснение. Нажмите — курсор встанет на место, текст появится под редактором', onclick: toNote }, plural(notes.length, 'замечание', 'замечания', 'замечаний')) : null,
      hidN ? h('button', { type: 'button', class: 'chip', title: 'Замечания, скрытые кнопкой «Не показывать». Нажмите — список, вернуть любое', onclick: hiddenNotesDialog }, `Скрыто: ${hidN}`) : null,
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
    const put = (text) => { ed.setValue(text); mark(text !== conf.content); relint(text); drawOutline(); };
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
    if (!force && ed.ta.value === conf.content) { toast('Изменений нет — сохранять нечего'); return; }
    try {
      await api('conf_save_raw', { content: ed.ta.value, force, note: 'правка конфига текстом' });
      toast('Конфиг сохранён. Перезапустите nfqws2, чтобы применить.');
      mark(false);
      await loadState();
      await loadConf();
      route();
    } catch (e) {
      if (e.data?.issues) {
        issues = e.data.issues; dry = e.data.dry_run;
        ed.setIssues(issues); drawOutline(); drawCounts(); drawStatus(); drawExtra();
        if (e.data.fatal) toast('Не сохранено: nfqws2 с таким конфигом не запустится', { err: true });
        else if (await ask('Сохранить конфиг с ошибками?\n\nОни подсвечены в тексте. nfqws2 запустится, но эти места будут работать неправильно.', 'Сохранить всё равно', 'danger')) save(true);
      } else toast(e.message, { err: true });
    }
  };
  // Full screen: the editor with its bar, notes and status takes the whole browser window; Esc or the same button —
  // back. Not on a phone: there the editor already spans the width
  // While full, the block lives in <body>: inside main (a size container) a fixed box may be held by main's frame.
  // The choice survives a save (the page redraws) and ends with Esc, the button or leaving the page.
  const cfgEl = h('div', { class: 'cfg' });   // filled below, once the bar is built
  const spot = document.createComment('cfg');
  const setFull = (on) => {
    S.cfgFull = on;
    if (on && cfgEl.parentNode !== document.body) { cfgEl.replaceWith(spot); document.body.append(cfgEl); }
    if (!on && spot.parentNode) spot.replaceWith(cfgEl);
    cfgEl.classList.toggle('full', on);
    document.documentElement.classList.toggle('cfg-fs', on);   // the page behind does not scroll (no second scrollbar)
    fsBtn.replaceChildren(icon(on ? 'shrink' : 'expand'));
    fsBtn.setAttribute('aria-label', on ? 'Обычный размер' : 'Во весь экран');
    fsBtn.dataset.tip = on ? 'Обычный размер (Esc)' : 'Во весь экран — редактор на всё окно браузера, выход по Esc';
    ed.ta.focus();
  };
  // «Save» and «Compare» work only when the text differs from the saved file
  const diffBtn = btn('Сравнить с сохранённым', () => openDiff('nfqws2.conf: изменения', conf.content, ed.ta.value), 'small', null,
    { disabled: true, title: 'Что изменилось в редакторе по сравнению с файлом на роутере. Доступно, когда есть изменения' });
  const saveBtn = btn('Сохранить', () => save(), 'primary small', null, { disabled: true, title: 'Доступно, когда в тексте есть изменения. Ctrl+S' });
  const mark = (v) => { setDirty(v); saveBtn.disabled = diffBtn.disabled = !v; };
  const drawerBtn = h('button', { type: 'button', class: 'cfg-xh', onclick: () => { cfgEl.classList.toggle('xopen'); drawDrawer(); } });
  const fsBtn = h('button', { type: 'button', class: 'btn small icon cfg-fs', 'aria-label': 'Во весь экран', 'data-tip': 'Во весь экран — редактор на всё окно браузера, выход по Esc',
    onclick: () => setFull(!cfgEl.classList.contains('full')) }, icon('expand'));
  // capture phase: runs before a dropdown, menu or dialog closes itself on the same Esc — then full screen stays
  document.addEventListener('keydown', function onEsc(e) {
    if (!cfgEl.isConnected) { document.removeEventListener('keydown', onEsc, true); return; }
    if (e.key !== 'Escape' || !cfgEl.classList.contains('full') || document.querySelector('.modal-bg, .dd-pop, .more-pop:not([hidden]), .menu-list')) return;
    e.preventDefault();
    if (cfgEl.classList.contains('xopen')) { cfgEl.classList.remove('xopen'); drawDrawer(); } else setFull(false);
  }, true);
  // after «don't show» elsewhere on the page the editor's notes are checked again (the server leaves hidden ones out)
  S.lintHook = () => { if (ed.ta.isConnected) { relint(ed.ta.value); return true; } S.lintHook = null; return false; };
  const outlineBtn = btn('Структура', () => { setLook({ nooutline: !getLook().nooutline }); outlineBtn.classList.toggle('on', !getLook().nooutline); }, 'small' + (getLook().nooutline ? '' : ' on'), 'layers',
    { title: 'Показать или скрыть колонку «Структура» — без неё больше места под текст конфига' });
  const tools = h('div', { class: 'cfg-tools' }, outlineBtn, find, findN, counts,
    // меняются только пробелы и переносы; сервер сверяет, что аргументы nfqws2 те же, иначе отказывает
    btn('Оформить', async () => {
      const r = await guarded(() => api('conf_format', { content: ed.ta.value }));
      if (!r) return;
      if (r.same) { toast('Конфиг уже оформлен'); return; }
      const ta = ed.ta;
      ta.focus();
      ta.setSelectionRange(0, ta.value.length);
      // insertText — чтобы Ctrl+Z вернул прежний вид
      if (!document.execCommand('insertText', false, r.content)) ed.setValue(r.content);
      ta.setSelectionRange(0, 0);
      ta.scrollTop = 0;
      const text = ta.value;
      mark(text !== conf.content); relint(text); drawOutline();
      toast(`Оформлено: ${plural(r.lines, 'строка', 'строки', 'строк')} — только пробелы и переносы, аргументы nfqws2 те же. Ещё не сохранено; Ctrl+Z — вернуть как было`);
    }, 'small', 'wand', { title: 'Привести конфиг к одному виду: параметр на строку, выравнивание под кавычку, пустая строка перед --new. Меняются только пробелы и переносы — nfqws2 получит те же аргументы. Текст попадает в редактор, сохранение — как обычно' }),
    newTag('raw:format'),
    copyBtn(() => ed.ta.value, 'Копировать', 'small'),
    fsBtn, newTag('raw:full'),
    h('span', { class: 'grow' }),
    diffBtn,
    btn('История', () => openHistory('nfqws2.conf', () => route(true)), 'small', 'history'),
    saveBtn);
  ed.setIssues(issues);
  cfgEl.append(tools, banner, outline, h('div', { class: 'cfg-body' }, ed.el), status, h('div', { class: 'cfg-x' }, drawerBtn, extra));
  if (S.cfgFull) queueMicrotask(() => setFull(true));   // was full before the redraw (a save)
  content.append(h('div', { class: 'vh' }, h('h1', { text: 'Конфиг целиком' }), h('span', { class: 'sm muted mono', text: S.state?.ui?.conf_file || 'nfqws2.conf' }), h('span', { class: 'grow' }),
    overviewCol() ? btn('Показать обзор', () => setFocus(false), 'small') : null),
  cfgEl);
  drawOutline(); drawCounts(); drawStatus(); drawExtra();
}
