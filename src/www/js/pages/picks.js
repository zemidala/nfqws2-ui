'use strict';
// Interface: page: pick history.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ История подборов: что и когда работало для каждого сайта ============

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
      h('td', {}, h('div', { class: 'row acts-row' },
        g.works ? btn('Проверить снова', () => pickAgain(g.host, g.last.proto), 'small', 'play', { title: 'Быстрый подбор только по тому, что для этого сайта уже работало' }) : null,
        btn('', async () => { if (await ask(`Удалить историю подборов для ${g.host}?`, 'Удалить', 'danger', 'trash') && await guarded(() => api('picks_delete', { host: g.host }), 'Удалено')) route(true); }, 'small ghost', 'trash', { 'aria-label': 'Удалить историю сайта', title: 'Удалить историю сайта' }))))));
    if (!rows.length) body.append(h('tr', {}, h('td', { colspan: 6, class: 'muted', text: 'Ничего не найдено.' })));
  }
  draw();
  main.append(
    bare ? null : h('div', { class: 'vh' }, h('h1', { text: 'История подборов' })),
    !sites.size ? panel(null, null, h('p', { class: 'muted', text: 'Подбор стратегии ещё не запускался. После каждого подбора здесь останется, что и когда сработало, — при следующей поломке это проверяется первым.' }),
      h('div', { class: 'row' }, h('a', { class: 'btn primary', href: PAGES.pick[0] }, 'Подобрать стратегию')))
    : panel(null, null,
      h('div', { class: 'row' }, search, h('span', { class: 'grow' }), h('span', { class: 'sm muted num', text: `${plural(sites.size, 'сайт', 'сайта', 'сайтов')} · ${plural(all.length, 'подбор', 'подбора', 'подборов')}` }),
        btn('Очистить всё', async () => { if (await ask('Удалить всю историю подборов? На профили и конфиг это не влияет.', 'Удалить всё', 'danger', 'trash') && await guarded(() => api('picks_delete', {}), 'История очищена')) route(true); }, 'small danger', 'trash')),
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
      if (x.ok >= (e.repeats || 1)) { g.full++; if (!g.last) { g.last = e.ts; g.ms = x.ms; g.rep = x.ok; } }
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
        btn('Удалить историю', async () => { if (await ask(`Удалить историю подборов для ${host}?`, 'Удалить', 'danger', 'trash') && await guarded(() => api('picks_delete', { host }), 'Удалено')) { if (bare) route(true); else go(PAGES.phist[0]); } }, 'small danger', 'trash')),
        h('p', { class: 'sm muted', text: '«Проверить снова» пробует только то, что для этого сайта уже работало, — это секунды, а не минуты. Применить стратегию можно из результатов проверки.' }),
        applied ? h('p', { class: 'sm' }, 'Применена ', h('b', { text: fmtDate(applied.ts) }), ': ', h('span', { class: 'mono', text: applied.name }), applied.target ? ` — ${applied.target}` : '') : null),
      panel('Что работало', h('span', { class: 'row', style: 'gap:8px' }, newTag('phist:share'), h('span', { class: 'sm muted num', text: plural(list.length, 'стратегия', 'стратегии', 'стратегий') })),
        list.length ? h('div', { class: 'scroll' }, h('table', { class: 'tbl' },
          h('thead', {}, h('tr', {}, h('th'), h('th', { text: 'Стратегия' }), h('th', { text: 'Работала' }), h('th', { class: 'nowrap', text: 'Последний раз' }), h('th', { class: 'num', text: 'Время' }), h('th'))),
          h('tbody', {}, list.map((g) => h('tr', {},
            h('td', {}, levelIcon(g.full === g.seen ? 'ok' : g.full ? 'warning' : 'error')),
            h('td', { style: 'min-width:220px' }, h('div', {}, g.name, g.refined ? ' ' : null, g.refined ? chip('уточнение') : null), h('code', { class: 'sm muted', style: 'word-break:break-all', text: g.steps.map((t) => t.replace('--lua-desync=', '')).join('  ') })),
            h('td', { class: 'sm nowrap', text: g.full ? `в ${g.full} из ${plural(g.seen, 'запуска', 'запусков', 'запусков')}` : 'открывала не каждый раз' }),
            h('td', { class: 'date', text: g.last ? fmtDate(g.last) : '—' }),
            h('td', { class: 'num nowrap', text: g.ms ? g.ms + ' мс' : '—' }),
            h('td', {}, h('div', { class: 'row acts-row' }, g.full ? shareBtn({ steps: g.steps, ok: g.rep, name: g.name }, { host, proto }) : null, btn('Скопировать', () => copyText(g.steps.join('\n')), 'small', 'copy')))))))) : h('p', { class: 'muted', text: 'Ни одна стратегия этот сайт пока не открыла.' })),
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
