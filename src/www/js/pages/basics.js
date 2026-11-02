'use strict';
// Interface: page: main settings and start parameters.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

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
  const toggle = (k) => [h('span', { class: 'lbl', text: VAR_INFO[k][0] }), h('label', { class: 'chk' }, h('input', { type: 'checkbox', id: 'f-' + k, checked: vals[k] === '1', onchange: (e) => { vals[k] = e.target.checked ? '1' : '0'; refresh(); } }), h('span', { text: 'включено' })), hints(k)];
  content.append(h('div', { class: 'vh' }, h('h1', { text: 'Основное' })),
    h('section', { class: 'panel' },
      h('div', { class: 'frow' }, input('ISP_INTERFACE'), sys ? ifacePicker(sys.ifaces, () => vals.ISP_INTERFACE, (val) => { vals.ISP_INTERFACE = val; document.getElementById('f-ISP_INTERFACE').value = val; refresh(); }, 'f-ISP_INTERFACE') : null),
      h('div', { class: 'frow' }, h('label', { class: 'lbl', for: 'f-mode', text: VAR_INFO.NFQWS_EXTRA_ARGS[0] }),
        h('select', { class: 'select', id: 'f-mode', onchange: (e) => { vals.NFQWS_EXTRA_ARGS = e.target.value; refresh(); } }, MODES.map(([val, t]) => h('option', { value: val, text: t, selected: vals.NFQWS_EXTRA_ARGS === val }))), hints('NFQWS_EXTRA_ARGS')),
      h('div', { class: 'frow' }, input('TCP_PORTS')), h('div', { class: 'frow' }, input('UDP_PORTS')),
      h('div', { class: 'frow' }, toggle('IPV6_ENABLED')), h('div', { class: 'frow' }, toggle('LOG_LEVEL'), logSizeHint())),
    bar);
}

// Сколько сейчас занимают журналы nfqws2 — подсказка у галочки «Отладочный журнал»
function logSizeHint() {
  const f = (S.state.logs?.files || []).filter((x) => x.used > 0);
  if (!f.length) return null;
  const big = f.some((x) => x.used >= S.state.logs.warn);
  return h('span', { class: 'hint', style: big ? 'color:var(--warn)' : null },
    'Сейчас: ' + f.map((x) => `${x.name} — ${fmtBytes(x.used)}`).join(', ') + ` (${LOG_DIR_HINT}). Больше 50 МБ — подрезается до 1 МБ автоматически. `,
    h('a', { href: PAGES.log[0], text: 'Журнал' }));
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
  const list = () => tokensOf(get());
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
  const orig = tokensOf(S.conf.vars.NFQWS_BASE_ARGS).join('\n');
  let issues = (S.state.lint?.issues || []).filter((x) => x.var === 'NFQWS_BASE_ARGS');
  const help = h('div', { class: 'help' });
  const sug = createSuggest();
  const issBox = h('div', {});
  const bar = h('div', { class: 'savebar', hidden: true });
  const chipsLua = h('div', { class: 'chips' });
  const chipsBlob = h('div', { class: 'chips blobs' });
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
    // a blob is used if a profile passes its name as a value (blob=, seqovl_pattern=, …): the router counts it from the
    // config as the init script reads it (commented lines do not count, several assignments of a variable do)
    const usedSet = new Set(S.conf.blobs_used || []);
    const usedBlob = (n) => usedSet.has(n);
    chipsBlob.replaceChildren(...(blobToks.length ? blobToks.map((t, i) => h('div', { class: 'blob-row' }, chip(t.slice(7).replace(/:@.*\//, ' → '), 'mono'),
      usedBlob(blobNames[i]) ? null : h('span', { class: 'sm muted', text: 'не используется в стратегиях' }))) : [h('span', { class: 'sm muted', text: 'нет' })]));
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
      h('div', { class: 'row' }, h('span', { class: 'grow' }), copyBtn(() => ed.ta.value, 'Копировать', 'small')),
      ed.el, sug.el, issBox, help),
    bar);
}
