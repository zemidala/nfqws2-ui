'use strict';
// Interface: page: profile builder and its text.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ---------- модель профиля для конструктора ----------

function splitTok(t) {
  const eq = t.indexOf('=');
  return eq < 0 ? [t.replace(/^--/, ''), null] : [t.slice(2, eq), t.slice(eq + 1)];
}
function parseModel(tokens) {
  const m = { name: '', tcp: null, udp: null, l7: null, filters: [], lists: [], seq: [] };
  for (const t of tokens) {
    const [n, v] = t.startsWith('--') ? splitTok(t) : [null, null];
    if (n === 'name') m.name = v || '';
    else if (n === 'filter-tcp') m.tcp = v ? v.split(',') : [];
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
  if (m.name) push('--name=' + m.name, 'name');
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

  // Номер первого аргумента профиля в переменной — по нему замечания проверки привязываются к полям.
  // Замечания из состояния считаны с конфига как есть: там «--new=имя» — один аргумент, а в модели это
  // «--name=имя» в начале профиля (splitParts). После своей проверки (relint) текст уже записан как --new + --name
  let issuesRaw = true;
  const mainOffset = () => {
    if (!isCustom) return 0;
    const named = issuesRaw ? tokensOf(v.NFQWS_ARGS_CUSTOM).filter(isNewArg).map((t) => t !== '--new') : [];
    let off = 0;
    for (let i = 0; i < s.part - 1; i++) off += customParts[i].length + 1 - (named[i - 1] ? 1 : 0);
    return off - (named[s.part - 2] ? 1 : 0);
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
    if (!await ask(`Удалить свой профиль ${s.part}? Перед удалением делается снимок.`, 'Удалить', 'danger', 'trash')) return;
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
      issuesRaw = false;
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
      groupName(oi), groupWhen(oi), groupWho(oi), groupWhat(oi)), helpBox));
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
    body.replaceChildren(h('div', { class: 'panel' },
      h('div', { class: 'row' }, h('span', { class: 'sm muted grow', text: 'По одному аргументу на строку. Tab — принять подсказку. Правки здесь и в конструкторе — одно и то же.' }),
        copyBtn(() => textEd.ta.value, 'Копировать', 'small')),
      textEd.el, sug.el, issBox, help));
  }

  // --- Имя: --name=… (nfqws2 пишет его в журнал; интерфейс показывает вместо «Свой профиль N»).
  // У профилей из общей переменной (NFQWS_ARGS с IPSET и EXTRA_ARGS) имя досталось бы обоим — там поля нет
  function groupName(oi) {
    if (s.with) return null;
    const NAME_RE = /^[\p{L}\p{N}_.-]{0,32}$/u;
    const hint = h('div', { class: 'hint' });   // inside the field row: its CSS puts the hint beside the field or under it
    const showHint = (val) => {
      const ok = NAME_RE.test(val);
      hint.className = 'hint' + (ok ? '' : ' error');
      hint.replaceChildren(ok ? h('span', { class: 'sm muted', text: 'Необязательно. Видно в интерфейсе вместо стандартного названия и в журнале nfqws2. Буквы, цифры, «_», «-», «.» — без пробелов, до 32 знаков.' })
        : [levelIcon('error'), ' Пробелы, кавычки и другие знаки в имени сломают запуск — только буквы, цифры, «_», «-», «.», до 32 знаков.']);
    };
    const inp = h('input', { class: 'input', style: 'max-width:260px', value: model.name, placeholder: VAR_INFO[s.source][0] + (isCustom ? ' ' + s.part : ''), maxlength: 32,
      'data-fk': 'name', 'aria-label': 'Имя профиля', autocapitalize: 'off', spellcheck: 'false',
      oninput: (e) => { const val = e.target.value.trim(); showHint(val); if (NAME_RE.test(val)) { model.name = val; touch(); } } });
    showHint(model.name);
    return h('div', { class: 'group' }, h('div', { class: 'frow' }, h('span', { class: 'lbl' }, 'Имя ', newTag('prof:name')), inp, hint,
      (oi.get('name') || []).map((x) => h('div', { class: 'hint ' + x.level }, levelIcon(x.level), ' ', x.msg))));
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
        h('div', { class: 'chips' }, arr.map((x, i) => h('span', { class: 'chip mono' + (lvl ? ' ' + (lvl === 'error' ? 'bad' : 'warn') : '') }, x, h('button', { class: 'x', type: 'button', 'aria-label': 'Убрать ' + x, text: '×', onclick: () => { arr.splice(i, 1); if (!arr.length) model[key] = null; touch(); draw(); } }))), inp, btn('', addPort, 'small icon', 'plus', { 'aria-label': 'Добавить' })),
        // notes inside the field row: its CSS puts them under the field on a phone, beside the label column otherwise
        (oi.get(key) || []).map((x) => h('div', { class: 'hint ' + x.level }, levelIcon(x.level), ' ', x.msg)))];
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
      // a list of names breaks after a comma, not in the middle of a name (zero-width space after each comma)
      const label = `${LIST_ROLE[l.opt]}: ${isFile ? listName(base(l.val)) : l.val.replace(/,/g, ',​')}`;
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
    // learn (nfqws-ui.lua) is an orchestrator over strategy=N steps, like circular
    const isOrch = (f) => f === 'circular' || f === 'learn';
    const orchFn = seq.find((x) => x.type === 'desync' && isOrch(x.fn))?.fn;
    const hasCircular = !!orchFn;
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
      const badge = isOrch(it.fn) ? h('span', { class: 'sn o', text: '⟳', title: it.fn === 'learn' ? 'Обучение: выбор стратегии по успеху' : 'Перебор стратегий' }) : h('span', { class: 'sn', text: strat ? strat.v : ++stratN });
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
      return wrapStep(i, it, isOrch(it.fn) ? 'orch' : '', badge, h('div', { class: 'step-main' },
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
    h('optgroup', { label: 'Шаг стратегии' }, ['multisplit', 'multidisorder', 'fake', 'fakedsplit', 'fakeddisorder', 'hostfakesplit', 'circular', 'learn'].filter((f) => S.catalog.lua.functions[f]).map((f) => h('option', { value: f, text: f + ' — ' + (S.catalog.lua.functions[f].doc[0] || '').slice(0, 60) }))),
    h('optgroup', { label: 'Все функции' }, Object.keys(S.catalog.lua.functions).sort().map((f) => h('option', { value: f, text: f }))),
    h('optgroup', { label: 'Условия' }, h('option', { value: 'payload', text: 'Типы пакетов (--payload)' }), h('option', { value: 'range', text: 'Диапазон пакетов (--out-range)' })));
    return h('div', { class: 'group' }, h('div', { class: 'group-h' }, h('h3', { text: 'Что делает' }),
      orchFn === 'learn' ? h('span', { class: 'sm muted', text: 'learn запоминает для каждого сайта стратегию, которая его открывает' })
        : hasCircular ? h('span', { class: 'sm muted', text: 'circular перебирает стратегии 1, 2, … при сбоях' }) : h('span', { class: 'sm muted', text: 'шаги выполняются сверху вниз' })),
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
