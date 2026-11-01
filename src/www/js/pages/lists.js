'use strict';
// Interface: page: lists of sites and addresses.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

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
  const look = getLook();
  const grouping = ['kind', 'profile', 'none'].includes(look.listGroup) ? look.listGroup : 'kind';
  const order = look.listSort === 'n' ? 'n' : 'name';
  const sorted = (arr) => [...arr].sort(order === 'n' ? (a, b) => (b.entries || 0) - (a.entries || 0) || a.name.localeCompare(b.name) : (a, b) => a.name.localeCompare(b.name));
  // extra replaces the profile chips on the right (in profile groups the profile is already in the header)
  const row = (l, extra) => {
    const pr = l.problems || {};
    const nice = LIST_NAMES[l.name];
    const file = l.name.replace(/\.list$/, '');
    const role = !l.used.length ? '' : l.used.every((u) => u.role === 'exclude') ? ' exc' : ' inc';
    const tip = l.name + (l.used.length ? '\n' + l.used.map((u) => `${u.role === 'exclude' ? 'исключает из' : 'читает'} профиль #${u.profile}`).join('\n') : '\nне подключён ни к одному профилю');
    return h('a', { href: '#/sites/' + encodeURIComponent(l.name), class: 'lrow' + (l.used.length ? '' : ' off') + (l.name === current ? ' on' : ''), title: tip },
      h('span', { class: 'bar' + role }),
      h('span', { class: 'nm' }, nice ? [h('span', { class: 't', text: nice }), h('i', { class: 'mono', text: file })] : h('span', { class: 't mono', text: file })),
      h('span', { class: 'tail num' },
        extra !== undefined ? extra : l.used.map((u) => h('span', { class: 'pchip' + (u.role === 'exclude' ? ' exc' : ''), text: '#' + u.profile })),
        pr.error ? h('span', { class: 'errdot' }) : pr.warning ? h('span', { class: 'warndot' }) : null,
        // стандартный список nfqws2-keenetic: не удаляется и не переименовывается — замок у числа записей
        l.stock ? h('span', { class: 'lk', title: 'Стандартный список nfqws2-keenetic: удалить и переименовать нельзя, очистить можно', 'aria-label': 'стандартный список' }, icon('lock')) : null,
        fmtNum(l.entries)));
  };
  let query = '';
  const shown = () => S.state.lists.filter((l) => !query || (l.name + ' ' + (LIST_NAMES[l.name] || '')).toLowerCase().includes(query));
  // a foldable group (profile groups, "not connected"); while searching everything stays open
  const group = (key, head, items, count) => {
    const closed = fold[key] === true && !query && !items.some((l) => l.name === current);
    const box = h('div', { class: 'lsec' + (closed ? ' closed' : '') });
    box.append(
      h('button', { type: 'button', class: 'lsec-h', 'aria-expanded': String(!closed), onclick: (e) => {
        const c = box.classList.toggle('closed');
        e.currentTarget.setAttribute('aria-expanded', String(!c));
        setFold(key, c);
      } }, h('span', { class: 'ar', text: '▾' }), head, h('span', { class: 'cnt num', text: count })),
      h('div', { class: 'lsec-b' }, items.map((x) => x.el)));
    return box;
  };
  // profiles that read the same lists in the same roles become one group (#12 and #14 in the stock config)
  const byProfile = () => {
    const lists = shown();
    const sets = new Map();
    for (const p of S.state.profiles || []) {
      const items = lists.filter((l) => l.used.some((u) => u.profile === p.index));
      if (!items.length) continue;
      const roleOf = (l) => l.used.find((u) => u.profile === p.index).role;
      const key = items.map((l) => l.name + ':' + roleOf(l)).sort().join(',');
      if (sets.has(key)) sets.get(key).ps.push(p); else sets.set(key, { ps: [p], items, roleOf });
    }
    const out = [...sets.values()].map(({ ps, items, roleOf }) => {
      const inc = sorted(items.filter((l) => roleOf(l) !== 'exclude')).map((l) => ({ name: l.name, el: row(l, null) }));
      const exc = sorted(items.filter((l) => roleOf(l) === 'exclude')).map((l) => ({ name: l.name, el: row(l, h('span', { class: 'lrole', text: 'исключает' })) }));
      const names = [...new Set(ps.map(profName))].join(' · ');
      return group('p' + ps.map((p) => p.index).join('-'), [h('span', { class: 'pnum', text: ps.map((p) => '#' + p.index).join(', ') }), h('b', { text: names })], [...inc, ...exc], String(items.length));
    });
    const off = sorted(lists.filter((l) => !l.used.length)).map((l) => ({ name: l.name, el: row(l, null) }));
    if (off.length) out.push(group('p-off', h('b', { class: 'muted', text: 'Не подключены' }), off, String(off.length)));
    return out;
  };
  const section = (kind, title) => {
    const all = sorted(shown().filter((l) => l.kind === kind));
    if (!all.length) return null;
    const used = all.filter((l) => l.used.length);
    const off = all.filter((l) => !l.used.length);
    const total = all.reduce((a, l) => a + (l.entries || 0), 0);
    // not connected lists are open when one of them is current, the section has no connected ones, or a search is on
    const offOpen = !used.length || !!query || off.some((l) => l.name === current) || fold['off-' + kind] === true;
    const closed = fold[kind] === true && !query && !all.some((l) => l.name === current);
    const box = h('div', { class: 'lsec' + (closed ? ' closed' : '') });
    const offBox = h('div', { class: 'lsec-off', hidden: !offOpen }, off.map((l) => row(l)));
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
      h('div', { class: 'lsec-b' }, used.map((l) => row(l)), offBtn, offBox));
    return box;
  };
  const act = (ic, label, onclick) => h('button', { class: 'ni', type: 'button', onclick }, h('span', { class: 'sq', text: ic }), h('span', { class: 'nm muted', text: label }));
  const file = h('input', { type: 'file', accept: '.list,.txt,text/plain', hidden: true, onchange: (e) => importListFile(e.target.files[0]) });
  const body = h('div', { class: 'lnav-body' });
  const drawBody = () => {
    const parts = grouping === 'profile' ? byProfile()
      : grouping === 'none' ? sorted(shown()).map((l) => row(l))
        : [section('host', 'Списки сайтов'), section('ip', 'IP-списки')].filter(Boolean);
    body.replaceChildren(...(parts.length ? parts : [h('div', { class: 'lnav-empty sm muted', text: query ? 'Ничего не найдено' : 'Списков нет' })]));
  };
  const selOf = (label, key, cur, opts) => h('select', { class: 'select', 'aria-label': label, title: label, onchange: (e) => { setLook({ [key]: e.target.value }); drawListNav(nav, current); } },
    opts.map(([v, t]) => h('option', { value: v, text: t, selected: v === cur })));
  const find = h('input', { class: 'input', type: 'search', placeholder: 'Найти список', 'aria-label': 'Найти список', oninput: (e) => { query = e.target.value.trim().toLowerCase(); drawBody(); } });
  drawBody();
  nav.replaceChildren(
    h('div', { class: 'lnav-ctl' }, newTag('lists:order'), find,
      h('div', { class: 'lnav-opt' },
        selOf('Группы', 'listGroup', grouping, [['kind', 'Сайты и IP'], ['profile', 'По профилям'], ['none', 'Без групп']]),
        selOf('Порядок', 'listSort', order, [['name', 'По имени'], ['n', 'По записям']]))),
    body,
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
  let raw = !!getLook().listText;   // «Списком» / «Текстом» — как выбирали в прошлый раз в этом браузере
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
  const more = h('span', { class: 'menu' }, btn('', () => { moreList.hidden = !moreList.hidden; }, 'small icon', 'down', { 'aria-haspopup': 'menu', title: 'Ещё: переименовать, копия, очистить, удалить', 'aria-label': 'Ещё' }), moreList);
  const moreItem = (label, fn, attrs = {}) => h('button', { type: 'button', role: 'menuitem', text: label, onclick: () => { moreList.hidden = true; fn(); }, ...attrs });
  // недоступный пункт — серый, причина прямо в нём: подсказку при наведении на телефоне не увидеть
  const offItem = (label, why) => h('button', { type: 'button', role: 'menuitem', disabled: true },
    h('span', { class: 'stack', style: 'gap:2px' }, label, h('span', { class: 'sm faint', style: 'white-space:normal', text: why })));
  const STOCK_WHY = 'Стандартный список nfqws2-keenetic: на него ссылается стандартный конфиг, без файла nfqws2 не запустится. Очистить можно';
  moreList.append(meta.stock ? offItem('Переименовать', 'Стандартный список — сделайте копию под новым именем') : moreItem('Переименовать', () => renameList()),
    moreItem('Сделать копию', () => copyList()),
    moreItem('Очистить список', () => clearList()),
    meta.removable ? moreItem('Удалить список', () => deleteList())
      : offItem('Удалить список', meta.stock ? STOCK_WHY : 'Список читают профили — сначала отключите его от них'));
  const head = h('div', { class: 'lhead' },
    h('div', { class: 'row' }, h('h2', { class: 'mono ltitle', text: name }), LIST_NAMES[name] ? h('span', { class: 'muted', text: LIST_NAMES[name] }) : null, readers,
      h('span', { class: 'grow' }), viewSlot, btn('История', () => openHistory(name, reload), 'small', 'history'), more),
    h('div', { class: 'row sm' }, meta.used.length ? null : h('span', { class: 'muted', text: 'Ни один профиль не читает этот список — правки в нём ничего не меняют. Записи проверяются, когда список подключён к профилю.' }), attachMenu(meta)),
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
  // очистить: файл остаётся (на стандартные ссылается конфиг nfqws2-keenetic), записи уходят
  async function clearList() {
    if (!data.content.trim()) { toast('Список и так пуст'); return; }
    if (!confirm(`Очистить ${name}? Файл останется пустым.` + (meta.used.length ? ' Профили, которые его читают, перестанут учитывать его записи сразу, без перезапуска.' : '')
      + ' Перед этим делается снимок, вернуть записи можно из «Истории».')) return;
    if (await guarded(() => api('list_save', { name, content: '', note: 'список очищен' }), 'Список очищен')) { await loadState(); reload(); }
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
        h('button', { type: 'button', class: raw ? '' : 'on', text: 'Списком', onclick: () => { raw = false; setLook({ listText: false }); draw(); } }),
        h('button', { type: 'button', class: raw ? 'on' : '', text: 'Текстом', onclick: () => { raw = true; setLook({ listText: true }); draw(); } })), newTag('lists:text'));
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
      // большой список роутер проверяет секундами — тогда только при сохранении
      const relint = debounce(async (v) => { if (v.length > ED_PLAIN_CHARS) return; const r = await api('list_lint', { name, content: v }).catch(() => null); if (r) showIss(r.issues); }, 500);
      showIss(data.issues);
      body.replaceChildren(tools, ed.el, issBox, h('div', { class: 'row' },
        h('span', { class: 'sm muted grow', text: 'Одна запись на строку, # в начале — комментарий (Ctrl+/ ставит и снимает). Поддомены учитываются автоматически, ^домен — без поддоменов, «*» не поддерживается.' }),
        copyBtn(() => ed.ta.value, 'Копировать', 'small'),
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
