'use strict';
// Interface: page: strategy pick and its results.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ Тесты ============

async function viewMonitor(main) {
  const m = await api('monitor_get');
  const cfg = m.settings;
  let sites = [...cfg.sites];
  const add = h('input', { class: 'input mono grow', id: 'mon-add', placeholder: 'добавить сайт, например rutracker.org', autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'Добавить сайт' });
  // the interval: presets or «свой…» — a number in minutes or hours. The cron pass runs every 10 minutes, so an own
  // interval is rounded to 10 minutes, from 10 minutes to 24 hours (the router clamps it the same way)
  const PRESETS = [10, 30, 60, 180, 720];
  const isPreset = PRESETS.includes(cfg.interval);
  const interval = h('select', { class: 'select', 'aria-label': 'Как часто' }, PRESETS.map((n) => h('option', { value: n, text: n < 60 ? `каждые ${n} мин` : `каждые ${n / 60} ч`, selected: n === cfg.interval })),
    h('option', { value: 'own', text: 'свой…', selected: !isPreset }));
  const ownHours = !isPreset && cfg.interval % 60 === 0;
  const ownNum = h('input', { class: 'input num mon-ivl', type: 'number', min: 1, max: 1440, step: 1, value: isPreset ? 45 : ownHours ? cfg.interval / 60 : cfg.interval, 'aria-label': 'Свой интервал' });
  const ownUnit = h('select', { class: 'select', 'aria-label': 'Единица интервала' }, h('option', { value: 'm', text: 'мин', selected: !ownHours }), h('option', { value: 'h', text: 'ч', selected: ownHours }));
  const ownBox = h('span', { class: 'row mon-own', hidden: isPreset }, ownNum, ownUnit, newTag('mon:ivl'));
  const ivl = () => {
    if (interval.value !== 'own') return Number(interval.value);
    const raw = Math.max(1, Number(ownNum.value) || 0) * (ownUnit.value === 'h' ? 60 : 1);
    return Math.min(1440, Math.max(10, Math.round(raw / 10) * 10));
  };
  let on = !!cfg.enabled;
  const enabled = h('button', { class: 'sw', type: 'button', id: 'mon-on', role: 'switch', 'aria-checked': String(on), 'aria-label': 'Проверять по расписанию',
    onclick: () => { on = !on; enabled.setAttribute('aria-checked', String(on)); save(); draw(); } });
  const listBox = h('div', { class: 'mon-box' });
  const checking = new Set();
  const save = async (extra = {}) => guarded(() => api('monitor_set', { sites, interval: ivl(), enabled: on, ...extra }));
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
  // a check result: [time, opened, ms, reason]; «slow» — opened, but longer than SLOW_MS (5 s)
  const kindOf = (x) => !x ? 'none' : !x[1] ? 'bad' : x[2] > SLOW_MS ? 'slow' : 'ok';
  const resText = (x) => !x ? 'ещё не проверялся' : !x[1] ? 'не открывается' : x[2] > SLOW_MS ? `медленно, ${(x[2] / 1000).toFixed(1).replace('.', ',')} с` : `${x[2]} мс`;
  const statusChip = (host, x) => checking.has(host) ? h('span', { class: 'mst none' }, h('span', { class: 'spin' }), 'проверяю…')
    : h('span', { class: 'mst ' + kindOf(x), title: x && !x[1] ? 'Не открывается: ' + x[3] : null, text: resText(x) });
  const histOf = (host) => m.data.sites[host] || [];
  const pctOf = (hist) => hist.length ? Math.round(hist.filter((x) => x[1]).length / hist.length * 100) : null;
  const cellTitle = (x) => fmtDate(x[0]) + ' · ' + (x[1] ? `открылся, ${x[2]} мс` : 'не открылся: ' + x[3]);
  const nowS = () => Math.floor(Date.now() / 1000);
  // view (table / timeline) and sorting — remembered in this browser
  let view = getLook().monView === 'tl' ? 'tl' : 'tbl';
  let sort = { key: 'host', dir: 1 };
  try { sort = { ...sort, ...JSON.parse(localStorage.getItem('nfqws-ui-mon-sort') || '{}') }; } catch { /* no storage */ }
  const RANK = { bad: 0, slow: 1, ok: 2, none: 3 };
  const CMP = {
    host: (a, b) => a.localeCompare(b),
    now: (a, b) => RANK[kindOf(histOf(a).at(-1))] - RANK[kindOf(histOf(b).at(-1))],
    pct: (a, b) => (pctOf(histOf(a)) ?? 101) - (pctOf(histOf(b)) ?? 101),
    when: (a, b) => (histOf(b).at(-1)?.[0] || 0) - (histOf(a).at(-1)?.[0] || 0),
  };
  // in the default order by name a site that does not open now comes first; an unknown saved key must not break the page
  const isDown = (host) => kindOf(histOf(host).at(-1)) === 'bad';
  const sorted = () => [...sites].sort((a, b) => (sort.key === 'host' ? isDown(b) - isDown(a) : 0) || sort.dir * (CMP[sort.key] || CMP.host)(a, b) || CMP.host(a, b));
  const remove = async (host) => { sites.splice(sites.indexOf(host), 1); await save(); draw(); };
  // the row's buttons: in their own column, and on a phone in a line under the site name (mon-under)
  const acts = (host, cls = '') => h('div', { class: 'mon-acts' + cls },
    btn('', () => checkNow([host]), 'small icon', 'refresh', { title: 'Проверить сейчас и записать результат в историю', 'aria-label': 'Проверить ' + host, disabled: checking.has(host) }),
    btn('', () => checkHost(host), 'small icon mon-more', 'search', { title: 'Подробно: какой профиль обрабатывает сайт', 'aria-label': 'Подробно о ' + host }),
    btn('', () => remove(host), 'small icon', 'x', { title: 'Убрать из мониторинга', 'aria-label': 'Убрать ' + host }));
  // how long the 96 checks of the history cover at the current interval: «48 часов», «4 дня»
  const spanText = () => { const hrs = Math.round(96 * ivl() / 60); return hrs <= 72 ? plural(hrs, 'час', 'часа', 'часов') : plural(Math.round(hrs / 24), 'день', 'дня', 'дней'); };
  const item = (x) => x && { ok: !!x[1], ms: x[2], tip: cellTitle(x), t: x[0], when: fmtDate(x[0]) };
  // the last 96 checks in the chosen look, the oldest on the left; fewer — empty on the left, so all rows end at «now»
  const histBar = (hist) => {
    const items = [...Array(Math.max(0, 96 - hist.length)).fill(null), ...hist.slice(-96).map(item)];
    const k = monChart();
    if (k === 'hours') return chHours(hist.map(item));
    if (k === 'words') return chWords(hist.slice(-96).map(item));
    // ticks and columns carry their time axis under them, as in the mockup
    return h('div', { class: 'ch-wrap' }, k === 'cols' ? chCols(chBuckets(items, 2)) : chTicks(chBuckets(items, 2)), axisRow());
  };
  const axisRow = () => { const hrs = Math.round(96 * ivl() / 60), half = Math.round(hrs / 2);
    const t = (n) => n <= 72 ? n + ' ч' : Math.round(n / 24) + ' дн';
    return h('div', { class: 'ch-axis' }, h('span', { text: t(hrs) + ' назад' }), h('span', { text: t(half) }), h('span', { text: 'сейчас' })); };
  const colHead = () => { const hourly = ivl() === 30;
    return { ticks: hourly ? 'По часам' : spanText(), hours: 'По часам, 00 → 23', cols: hourly ? 'Время ответа по часам' : 'Время ответа', words: 'За ' + spanText() }[monChart()]; };
  const tblLegend = () => monChart() === 'hours' ? [['cell', 'всё хорошо'], ['slow', 'медленно'], ['bad', 'не открывался']]
    : monChart() === 'cols' ? [['ln', 'время ответа'], ['slow', 'медленно'], ['bad', 'не открывался']] : null;
  // the chart look — a switch of buttons like «Таблица / Шкала времени», right after it
  const chartSeg = h('div', { class: 'seg mon-chartseg', role: 'group', 'aria-label': 'Вид графика' });
  const drawChartSeg = () => chartSeg.replaceChildren(...MON_CHARTS.map(([k, t]) => h('button', { type: 'button', class: monChart() === k ? 'on' : '', 'aria-pressed': String(monChart() === k), text: t,
    onclick: () => { setLook({ monChart: k }); drawChartSeg(); draw(); updateSide(); } })));
  drawChartSeg();
  const sumBox = h('div', { class: 'mon-tiles' });
  const tile = (big, lbl, cls = '') => h('div', { class: 'mon-tile' }, h('b', { class: cls, text: big }), h('span', { text: lbl }));
  const drawSum = () => {
    const lasts = sites.map((s) => histOf(s).at(-1));
    const n = { ok: 0, slow: 0, bad: 0, none: 0 };
    lasts.forEach((x) => n[kindOf(x)]++);
    const pcts = sites.map((s) => pctOf(histOf(s))).filter((x) => x !== null);
    const avg = pcts.length ? Math.round(pcts.reduce((a, b) => a + b, 0) / pcts.length) : null;
    const now = nowS(), mins = Math.max(0, Math.round(((m.data.last || 0) + ivl() * 60 - now) / 60));
    const t3 = !on ? tile('выкл.', 'автоматические проверки выключены — сайты проверяются только кнопками')
      : tile(mins <= 1 ? '≈ 10 мин' : mins + ' мин', 'до следующей проверки' + (m.data.last ? ' · последняя ' + fmtAgo(now - m.data.last) : ''));
    t3.dataset.tip = on ? 'Плюс-минус 10 минут: расписание проверяется раз в 10 минут.' : '';
    sumBox.replaceChildren(
      tile(`${n.ok + n.slow} из ${sites.length}`, 'открываются сейчас' + (n.slow ? `, ${n.slow} медленно` : ''), n.bad ? 'badc' : 'okc'),
      tile(avg === null ? '—' : avg + ' %', 'доступность за ' + spanText()),
      t3);
  };
  const viewSeg = h('div', { class: 'seg', role: 'group', 'aria-label': 'Вид' });
  const drawSeg = () => viewSeg.replaceChildren(...[['tbl', 'Таблица'], ['tl', 'Шкала времени']].map(([v, t]) =>
    h('button', { type: 'button', class: view === v ? 'on' : '', 'aria-pressed': String(view === v), text: t, onclick: () => { view = v; setLook({ monView: v }); drawSeg(); draw(); } })));
  const drawTable = () => {
    const heads = [['host', 'Сайт', ''], ['now', 'Сейчас', ''], [null, colHead(), 'mon-wide'], ['pct', 'Доступность', 'num'], ['when', 'Проверен', 'num mon-wide mon-when'], [null, '', 'mon-actcol']];
    const th = ([k, t, cls]) => h('th', { class: cls, 'aria-sort': k && sort.key === k ? (sort.dir > 0 ? 'ascending' : 'descending') : null },
      !k ? t : h('button', { class: 'th-sort', type: 'button', title: `Сортировать по столбцу «${t}»`, onclick: () => {
        sort = { key: k, dir: sort.key === k ? -sort.dir : 1 };
        try { localStorage.setItem('nfqws-ui-mon-sort', JSON.stringify(sort)); } catch { /* no storage */ }
        draw();
      } }, k === 'pct' ? [h('span', { class: 'mon-wide', text: t }), h('span', { class: 'mon-narrow', text: '%' })] : t, sort.key === k ? icon(sort.dir > 0 ? 'up' : 'down') : null));
    return h('div', { class: 'scroll' }, h('table', { class: 'tbl mon-tbl ch-fixed' }, h('thead', {}, h('tr', {}, heads.map(th))),
      h('tbody', {}, sorted().map((host) => {
        const hist = histOf(host), x = hist.at(-1), p = pctOf(hist);
        const down = x && !x[1] && !checking.has(host);
        return h('tr', { class: dupHosts.has(host) ? 'mon-dup' : down ? 'mon-down' : '' },
          // a long name may wrap only after a dot, never in the middle of a word; why it does not open — under the name
          h('td', { class: 'mono mon-host' }, h('button', { class: 'linkish', type: 'button', title: 'Подробно: какой профиль обрабатывает сайт', onclick: () => checkHost(host) },
            host.split('.').flatMap((p, j) => j ? [h('wbr'), '.' + p] : [p])), down ? h('div', { class: 'mon-why', text: x[3], title: x[3] }) : null, acts(host, ' mon-under')),
          h('td', {}, statusChip(host, x)),
          h('td', { class: 'mon-wide mon-hcell' }, histBar(hist)),
          h('td', { class: 'num', text: p === null ? '—' : p + '%' }),
          h('td', { class: 'num sm muted mon-wide mon-when', text: x ? fmtAgo(nowS() - x[0]) : '—' }),
          h('td', { class: 'mon-actcol' }, acts(host)));
      }))), tblLegend() ? h('div', { class: 'mtl-legend sm muted mon-wide' }, tblLegend().map(([k, t]) => h('span', { class: k, text: t }))) : null);
  };
  // Timeline: every site on one time axis — the window from the oldest check to now in 96 slots, each slot shows the
  // worst result in it. A slot where every checked site failed points at the provider or the router, not a block.
  const drawTimeline = () => {
    // one slot = one check interval (at most 96 slots), so every scheduled check lands in its own slot
    // a longer history than 96 intervals (interval changed, manual checks only) widens the slot instead of piling up in slot 0
    const now = nowS();
    const first = Math.min(...sites.map((s) => histOf(s)[0]?.[0] || now));
    const step = Math.max(ivl() * 60, Math.ceil((now - first) / 95));
    const N = Math.max(12, Math.min(96, Math.ceil((now - first) / step) + 1));
    const span = N * step;
    const slotOf = (t) => Math.min(N - 1, Math.max(0, Math.floor((t - (now - span)) / span * N)));
    const WORST = { bad: 3, slow: 2, ok: 1, none: 0 };
    const grid = sorted().map((host) => {
      const cells = Array.from({ length: N }, () => ({ k: 'none', xs: [] }));
      for (const x of histOf(host)) { const c = cells[slotOf(x[0])]; c.xs.push(x); if (WORST[kindOf(x)] > WORST[c.k]) c.k = kindOf(x); }
      // cron runs every 10 minutes, so a check drifts into the next slot now and then: a single empty slot between
      // two checked ones is that drift, not a missed check — draw it like the slot before
      for (let i = 1; i < N - 1; i++) if (cells[i].k === 'none' && cells[i - 1].xs.length && cells[i + 1].xs.length) cells[i].k = cells[i - 1].k;
      return [host, cells];
    });
    // runs of slots where at least two sites were checked and none opened
    const dead = Array.from({ length: N }, (_, i) => { const ks = grid.map(([, c]) => c[i].k).filter((k) => k !== 'none'); return ks.length >= 2 && ks.every((k) => k === 'bad'); });
    let run = null;
    for (let i = N - 1; i >= 0; i--) { if (dead[i] && !run) run = [i, i]; else if (dead[i] && run && run[0] === i + 1) run[0] = i; else if (run) break; }
    const slotT = (i) => now - span + span * i / N;
    const ago = (s) => s < 5400 ? Math.round(s / 60) + ' мин' : s < 172800 ? Math.round(s / 3600) + ' ч' : Math.round(s / 86400) + ' дн';
    // one point of the line per slot: failed if any check in it failed, otherwise the slowest answer when slow, else the mean
    const slotItem = (c) => {
      const okMs = c.xs.filter((x) => x[1]).map((x) => x[2]);
      return { ok: c.k !== 'bad', ms: c.k === 'slow' ? Math.max(...okMs) : Math.round(okMs.reduce((a, b) => a + b, 0) / (okMs.length || 1)), tip: c.xs.map(cellTitle).join('\n') };
    };
    const line = (cells) => { let prev = null; return cells.map((c) => (prev = c.xs.length ? slotItem(c) : c.k !== 'none' ? prev : null)); };   // a drifted slot repeats the one before
    return h('div', { class: 'mtl ch-fixed' },
      grid.map(([host, cells]) => [
        h('div', { class: 'mono mtl-host' }, h('span', { class: 'mtl-dot ' + kindOf(histOf(host).at(-1)) }), host),
        (() => { const its = line(cells), k = monChart(), b = its.length > 48 ? chBuckets(its, 2) : its;
          // each look keeps its own face on the shared time axis: hours — grey squares, words — 12 big blocks
          return k === 'cols' ? chCols(b) : k === 'hours' ? chTicks(b, 'hsq') : k === 'words' ? h('div', { class: 'ch-blocks tl' }, chBuckets(its, Math.ceil(its.length / 12)).map((x) => h('i', { class: chKind(x), title: x?.tip || null }))) : chTicks(b); })(),
        h('div', { class: 'num sm', text: pctOf(histOf(host)) === null ? '—' : pctOf(histOf(host)) + '%' })]).flat(),
      h('div', { class: 'mtl-axis' }, [1, 0.75, 0.5, 0.25, 0].map((f) => h('span', { text: f ? ago(span * f) + ' назад' : 'сейчас' }))),
      run ? h('div', { class: 'mtl-note', text: `${fmtDate(Math.round(slotT(run[0])))} — ${fmtDate(Math.round(slotT(run[1] + 1)))}: не открывался ни один сайт. Похоже на сбой провайдера или роутера, а не на блокировку.` }) : null,
      h('div', { class: 'mtl-legend sm muted' }, [monChart() === 'cols' ? ['ln', 'время ответа'] : monChart() === 'ticks' ? ['ok', 'открывался'] : ['cell', 'всё хорошо'],
        ['slow', 'медленно, больше 5 с'], ['bad', 'не открывался'], ['none', 'не проверялся']].map(([k, t]) => h('span', { class: k, text: t }))));
  };
  const draw = () => {
    drawSum();
    listBox.replaceChildren(!sites.length ? h('p', { class: 'muted', text: 'Сайтов нет — добавьте хотя бы один.' }) : view === 'tl' ? drawTimeline() : drawTable());
  };
  const addNote = h('div', { class: 'mon-note' });
  let dupHosts = new Set();
  // same normalization as cleanHost() in api.php: the server drops duplicates, IPs and bad names silently
  const cleanHost = (x) => x.trim().toLowerCase().replace(/^[a-z]+:\/\//, '').split('/')[0].replace(/:\d+$/, '').replace(/^\*\./, '').replace(/\.+$/, '');
  const addSite = async () => {
    const hs = add.value.split(/[\s,]+/).filter(Boolean);
    if (!hs.length) return;
    const before = new Set(sites);
    sites = [...new Set([...sites, ...hs])];
    if (!await save()) return;
    const mm = await api('monitor_get');
    sites = mm.settings.sites;
    const now = new Set(sites);
    const dup = [...new Set(hs.map(cleanHost).filter((x) => before.has(x)))];
    const lost = [...new Set(hs.filter((x) => !now.has(cleanHost(x)) && !before.has(cleanHost(x))))];
    add.value = lost.join(' ');
    dupHosts = new Set(dup);
    addNote.replaceChildren(...[
      dup.length && h('div', { class: 'notice warn' }, icon('alert'), h('span', { class: 't grow', text: `Уже в списке: ${dup.join(', ')}. Второй раз сайт не добавляется.` })),
      lost.length && h('div', { class: 'notice bad' }, icon('alert'), h('span', { class: 't grow', text: `Не добавлено: ${lost.join(', ')}. Нужно имя сайта, например rutracker.org: IP-адреса мониторинг не проверяет${sites.length >= 30 ? ', и в списке уже 30 сайтов — больше нельзя' : ''}.` })),
    ].filter(Boolean));
    // новые сайты проверяем сразу, не дожидаясь расписания
    const added = sites.filter((x) => !before.has(x));
    if (added.length) checkNow(added); else draw();
    listBox.querySelector('.mon-dup')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  };
  add.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); addSite(); } });
  add.addEventListener('input', () => { if (!addNote.childElementCount && !dupHosts.size) return; addNote.replaceChildren(); dupHosts = new Set(); draw(); });
  draw();
  main.append(
    policyNote(), policyProbeHint(),
    panel('Сайты', h('div', { class: 'row mon-ctrls' },
      h('label', { class: 'mon-sw', for: 'mon-on' }, enabled, h('span', { text: 'Проверять' })), interval, ownBox,
      btn('Проверить все', async () => { toast('Проверяю…'); const r = await guarded(() => api('monitor_run')); if (r) { m.data = r.data; draw(); await loadState(); } }, 'small', 'refresh')),
      h('p', { class: 'sm muted', text: 'Сайты открываются с самого роутера через основной nfqws2. История — последние 96 проверок: красное — сайт не открылся, жёлтое — ответ дольше 5 секунд. При смене «открывается ↔ нет» может прийти сообщение в Telegram — см. «Уведомления».' }),
      sumBox,
      h('div', { class: 'row' }, add, btn('Добавить', addSite, 'primary', 'plus')), addNote,
      h('div', { class: 'row mon-viewrow' }, viewSeg, newTag('mon:view'), chartSeg, newTag('mon:chart')), listBox));
  drawSeg();
  interval.addEventListener('change', () => { ownBox.hidden = interval.value !== 'own'; save(); draw(); });
  // own interval: saved once the number settles; the field shows what was actually set (rounded to 10 minutes)
  let ownSync = false;   // the page itself switches hours → minutes (1.5 h → 90 min): that change is not the reader's
  const ownSave = debounce(async () => {
    const v = ivl();
    if (ownUnit.value === 'h' && v % 60 === 0) ownNum.value = v / 60;
    else if (ownUnit.value === 'm') ownNum.value = v;
    else { ownSync = true; ownUnit.value = 'm'; ownUnit.dispatchEvent(new Event('change')); ownSync = false; ownNum.value = v; }
    await save();
    draw();
  }, 700);
  ownNum.addEventListener('input', ownSave);
  ownUnit.addEventListener('change', () => { if (!ownSync) ownSave(); });
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
  const found = h('div', { class: 'stack items', hidden: true });
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
        h('div', { class: 'stack items' },
          h('div', { class: 'row' }, tgChat, btn('Найти', findChats, '', 'search', { title: 'Показать чаты, из которых боту недавно писали' })),
          found)),
      who,
      h('div', { class: 'row' }, btn('Сохранить', async () => { if (await save()) { toast('Сохранено'); drawWho(); } }, 'primary'),
        btn('Отправить проверочное', async () => {
          if (!await save()) return;
          drawWho();
          const r = await guarded(() => api('notify_test'));
          if (r) toast('Сообщение отправлено ' + r.via);
          route();
        }))),
    sentPanel(m.sent));
  // не ждём Telegram, чтобы страница открылась сразу
  if (m.tg.token_set) drawWho();
}

// Что ушло в Telegram и что ждёт: сообщение, которое не удалось отправить, повторяется в каждом проходе cron (до суток)
function sentPanel(sent) {
  if (!sent || (!sent.queue.length && !sent.log.length)) return null;
  // первая строка у всех одна («nfqws2 на роутере:») — показываем суть
  const body = (t) => t.replace(/^nfqws2 на роутере:\n/, '');
  const rows = [
    ...sent.queue.map((m) => h('tr', {}, h('td', { class: 'nowrap', text: fmtDate(m.ts) }),
      h('td', {}, h('span', { class: 'chip warn', text: 'ждёт' })),
      h('td', { style: 'white-space:pre-line' }, body(m.text), h('div', { class: 'sm muted', text: `не ушло${m.tries ? ` (попыток: ${m.tries})` : ''}: ${m.error || 'ещё не пробовали'} — повтор в следующем проходе, не дольше суток` })))),
    ...sent.log.slice(0, 20).map((m) => h('tr', {}, h('td', { class: 'nowrap', text: fmtDate(m.at) }),
      h('td', {}, h('span', { class: 'chip ' + (m.ok ? 'ok' : 'bad'), text: m.ok ? 'ушло' : 'не ушло' })),
      h('td', { style: 'white-space:pre-line' }, body(m.text),
        m.ok && m.ts - m.at > 120 ? h('div', { class: 'sm muted', text: `отправлено в ${fmtDate(m.ts)}, с опозданием` }) : null,
        m.ok ? null : h('div', { class: 'sm', style: 'color:var(--bad)', text: m.error }))))];
  return panel('Последние сообщения', newTag('tg:log'),
    h('div', { class: 'scroll' }, h('table', { class: 'tbl' }, h('thead', {}, h('tr', {}, h('th', { text: 'Когда' }), h('th'), h('th', { class: 'wide', text: 'Сообщение' }))), h('tbody', {}, rows))),
    h('p', { class: 'sm muted', text: 'Если Telegram недоступен, сообщение не теряется: оно ждёт и уходит, как только связь появится, с пометкой о времени события.' }));
}

// Under a strategy's time: the wait for the first byte, and the download speed for the fastest few. A small page comes
// in one piece, the first byte is then the whole time — shown only when it says something of its own
const fmtSpeed = (k) => k >= 1024 ? (k / 1024).toFixed(1).replace('.', ',') + ' МБ/с' : k + ' КБ/с';
function timeNote(x) {
  if (!x.ms) return null;
  const first = x.ttfb && x.ms - x.ttfb >= 50;
  const parts = [first ? `ответ ${x.ttfb} мс` : null, x.kbps ? fmtSpeed(x.kbps) : null].filter(Boolean);
  if (!parts.length) return null;
  return h('div', { class: 'sm muted nowrap', title: [first ? `Первый байт ответа — через ${x.ttfb} мс` : null, x.kbps ? `Скорость — по скачиванию ${x.kb} КБ с того же сайта` : null].filter(Boolean).join('. '),
    text: parts.join(' · ') });
}

// Several sites: the ones whose address the router's DNS replaced, probed at the real address
function pinnedNote(sites) {
  const pinned = (sites || []).filter((r) => r.dns_pin);
  if (!pinned.length) return null;
  return h('div', { class: 'sm', style: 'color:var(--warn)' }, 'DNS роутера подменяет адрес: ', pinned.map((r, i) => [i ? ', ' : '', h('span', { class: 'mono', text: r.host })]),
    '. Они проверялись по настоящему адресу от защищённого DNS; у устройств откроются только с другим DNS — ', h('a', { href: '#/tests/dns', text: 'DNS-серверы' }), '.');
}

// The router's DNS gave a stub, so the pick probed the real address: a strategy alone will not open the site for devices.
// If the real address opens without bypass, the stub is the whole block
function dnsPinNote(pin, baseline) {
  if (!pin) return null;
  const got = `DNS роутера отдаёт ${(pin.system || []).join(', ') || 'пустой ответ'}, а защищённый DNS — ${pin.ip}.`;
  return h('div', { class: 'notice warn' }, icon('alert'), h('div', { class: 'grow' },
    h('b', { text: baseline?.ok ? 'Сайт закрыт только подменой DNS' : 'DNS роутера подменяет адрес сайта' }),
    h('div', { class: 't' }, baseline?.ok
      ? `${got} По настоящему адресу сайт открывается без обхода${baseline.ms ? `, ${baseline.ms} мс` : ''}: стратегия не нужна, устройствам нужен другой DNS: `
      : `${got} Подбор шёл по настоящему адресу. Найденная стратегия снимет блокировку по DPI, но сайт откроется только у устройств, которые получают настоящий адрес. Выбрать другой DNS: `,
      // the accent link on the warning's tinted background falls short of contrast in the dark theme
      h('a', { href: '#/tests/dns', text: 'DNS-серверы', style: 'color:inherit;text-decoration:underline' }), '.')));
}

async function viewTests(main, r, bare = false) {
  await loadCatalog();
  stopTestPoll();
  if (r.arg === 'history') return viewPickHist(main, r);
  if (r.arg === 'auto') return viewAuto(main);
  if (r.arg === 'community') return viewCommunity(main);
  if (r.arg === 'freeze') return viewFreeze(main);
  if (r.arg === 'dns') return viewDns(main);
  if (r.arg === 'conns') return viewConns(main);
  if (r.arg === 'learn') return viewLearn(main);
  if (r.arg === 'configs') return viewCfg(main);
  const tab = ['trace', 'monitor', 'notify'].includes(r.arg) ? r.arg : 'pick';
  if (tab === 'monitor') {
    main.append(h('div', { class: 'vh' }, h('h1', { text: layout() === 'site' ? 'Сайты' : 'Мониторинг' })));
    return viewMonitor(main);
  }
  if (tab === 'notify') return viewNotify(main);
  const host = h('input', { class: 'input mono grow', id: 't-host', value: r.q.get('host') || S.check?.input.value || '', placeholder: 'rutracker.org', autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'Сайт для теста' });
  host.addEventListener('input', () => { host.dataset.edited = '1'; });
  if (r.q.get('host')) host.dataset.edited = '1';
  const proto = h('select', { class: 'select', 'aria-label': 'Протокол' }, h('option', { value: 'https', text: 'HTTPS (TLS, TCP 443)' }), h('option', { value: 'http', text: 'HTTP (TCP 80)', selected: r.q.get('proto') === 'http' }),
    h('option', { value: 'quic', text: 'QUIC (HTTP/3, UDP 443)', selected: r.q.get('proto') === 'quic' }));
  const isQuic = () => proto.value === 'quic';
  // через какой профиль сайт идёт по этому протоколу и какие порты профиля смотреть
  const routeKey = (s) => s.proto === 'http' ? 'http' : s.proto === 'quic' ? 'quic' : 'https';
  const quicNote = h('div', { class: 'frow', hidden: true }, h('span', { class: 'lbl', text: 'QUIC' }), h('div', { class: 'row' },
    h('span', { class: 'sm muted', text: 'Проверка — настоящим первым пакетом HTTP/3 на имя сайта: ответил сервер — QUIC проходит. Каталог zapret2, уточнение и подбор для нескольких сайтов рассчитаны на TLS и здесь не участвуют. Обычно до минуты.' })));
  const setCfg = h('input', { type: 'checkbox', id: 't-cfg', checked: true });
  // NFQ-L231: also the strategies of profiles for other ports (2053, 8443…) — tried on this site's 443/80
  const setPorts = h('input', { type: 'checkbox', id: 't-ports' });
  const portsLbl = h('label', { class: 'chk', title: 'Профили, у которых в --filter-tcp нет 443 (для HTTP — 80): их стратегии тоже пробуются на этом сайте. Конфиг не меняется' },
    setPorts, 'и из профилей для других портов', newTag('pick:ports'));
  setCfg.addEventListener('change', () => { setPorts.disabled = !setCfg.checked; });
  const setStd = h('input', { type: 'checkbox', id: 't-std', checked: true });
  const setHist = h('input', { type: 'checkbox', id: 't-hist', checked: true });
  const setComm = h('input', { type: 'checkbox', id: 't-comm', checked: true });
  // каталог по правилам blockcheck2: большой набор проверяется параллельно
  // пункты длинные («полный — 3111 стратегий, 15–30 минут»): без min-width:0 список не сужается и на телефоне вылезает за край
  const catSel = h('select', { class: 'select', style: 'min-width:0;max-width:100%', 'aria-label': 'Каталог zapret2' },
    h('option', { value: '', text: 'не брать' }), h('option', { value: 'ext', text: 'расширенный' }), h('option', { value: 'full', text: 'полный — долго' }));
  const catNote = h('span', { class: 'sm muted' });
  const catCounts = () => api('test_candidates', { proto: proto.value }).then((r) => {
    catSel.options[1].text = `расширенный — ${r.catalog.ext} стратегий, 2–5 минут`;
    catSel.options[2].text = `полный — ${r.catalog.full} стратегий, 15–30 минут`;
  }).catch(() => {});
  catCounts();
  proto.addEventListener('change', () => { if (isQuic()) drawMode(); else { catCounts(); drawMode(); } });
  const repeats = h('select', { class: 'select', 'aria-label': 'Повторов' }, [1, 2, 3, 5].map((n) => h('option', { value: n, text: plural(n, 'повтор', 'повтора', 'повторов'), selected: n === 3 })));
  const refine = h('input', { type: 'checkbox', id: 't-refine' });
  // «as old devices»: smart TVs and set-top boxes often speak only TLS 1.2, and a strategy found over TLS 1.3 may not open
  // the site for them — every probe of the pick then goes over TLS 1.2 (HTTPS only)
  const tls12 = h('input', { type: 'checkbox', id: 't-tls12', checked: r.q.get('tls12') === '1' });   // from the diagnosis: ?tls12=1
  const tlsRow = h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Устройства' }), h('div', { class: 'stack lines' },
    h('label', { class: 'chk' }, tls12, 'проверять как старые устройства (TLS 1.2)', newTag('pick:tls12')),
    h('span', { class: 'sm muted', text: 'Для смарт-ТВ и приставок, у которых сайт не открывается, хотя на телефоне и компьютере всё работает: многие из них умеют только TLS 1.2, а провайдер разбирает такое приветствие иначе. Тогда и подбор проверяет сайт по TLS 1.2.' })));
  // Подбор одной стратегии для нескольких сайтов (списка): поле «Сайт» меняется на список и сайты из него
  const hostLists = S.state.lists.filter((l) => l.kind === 'host' && l.exists && l.entries > 0);
  let modeVal = 'one';
  const modeSeg = h('div', { class: 'seg', role: 'radiogroup', 'aria-label': 'Что подбираем' }, [['one', 'Один сайт'], ['multi', 'Несколько сайтов или список']].map(([v, t]) =>
    h('button', { type: 'button', role: 'radio', class: v === modeVal ? 'on' : '', 'aria-checked': String(v === modeVal), 'data-v': v, text: t, onclick: () => {
      modeVal = v;
      modeSeg.querySelectorAll('button').forEach((b) => { b.classList.toggle('on', b.dataset.v === v); b.setAttribute('aria-checked', String(b.dataset.v === v)); });
      drawMode();
    } })));
  const mode = { get value() { return modeVal; } };
  const mList = h('select', { class: 'select', 'aria-label': 'Список', onchange: () => fillMulti() },
    hostLists.map((l) => h('option', { value: l.name, text: `${l.name} — ${l.entries}`, selected: l.name === 'user.list' })), h('option', { value: '', text: 'свои сайты' }));
  const mHosts = h('textarea', { class: 'input mono', rows: 6, placeholder: 'rutracker.org\nyoutube.com\ndiscord.com', 'aria-label': 'Сайты, по одному в строке', autocapitalize: 'off', spellcheck: 'false' });
  const mNote = h('span', { class: 'sm muted' });
  async function fillMulti() {
    if (!mList.value) { mNote.textContent = 'Впишите от 2 до 10 сайтов. Найденную стратегию можно будет поставить профилем для этих сайтов.'; return; }
    const r = await api('list_get', { name: mList.value }).catch(() => null);
    const all = (r?.content || '').split('\n').map((l) => l.replace(/#.*/, '').trim().toLowerCase()).filter((l) => l && !/[\^*/: ]/.test(l) && !/^[\d.]+$/.test(l) && l.includes('.'));
    for (let i = all.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [all[i], all[j]] = [all[j], all[i]]; }
    mHosts.value = all.slice(0, 10).join('\n');
    mNote.textContent = `Случайные 10 из ${mList.value} — можно поменять. Найденную стратегию можно будет поставить профилем на весь ${mList.value}.`;
  }
  const oneRows = [];   // поля, которые есть только у подбора одного сайта (каталог, уточнение)
  // Своя стратегия: вставленная из чата или написанная — проверяется первой, конфиг не трогается
  const ownTa = h('textarea', { class: 'input mono', rows: 3, style: 'width:100%;resize:vertical', spellcheck: 'false', autocapitalize: 'off', 'aria-label': 'Свои стратегии',
    placeholder: '--lua-desync=fake:blob=fake_default_tls:tcp_md5:repeats=6 --lua-desync=multisplit:pos=1,midsld' });
  const ownOut = h('div', { class: 'stack items' });
  const ownDet = h('details', { class: 'own-st' }, h('summary', {}, 'вставить стратегию из чата или свою ', newTag('pick:own')), ownTa,
    h('span', { class: 'sm muted', text: 'Вставляйте как есть — кавычки, подписи и переносы уберутся. Каждая строка — отдельная стратегия (до 10); профили через --new тоже разделяются. Проверяются шаги --lua-desync, фильтры и списки чужого профиля не нужны: подбор сам ставит фильтр под сайт. Свои стратегии идут первыми; ваш конфиг и трафик не затрагиваются. Чтобы проверить только их, снимите галочки выше.' }),
    ownOut);
  const ownRow = h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Своя стратегия' }), ownDet);
  // до запуска: что в тексте не годится — названо, а не выглядит потом как «не открылось»
  async function ownStrategies() {
    if (!ownTa.value.trim()) { ownOut.replaceChildren(); return []; }
    ownDet.open = true;
    const items = parseStrategies(ownTa.value);
    if (!items.length) { ownOut.replaceChildren(notice('bad', 'В тексте не нашлось стратегий', 'Нужны шаги вида --lua-desync=функция:параметры. Очистите поле, если своя стратегия не нужна.')); return null; }
    const chk = await guarded(() => api('strategy_check', { items: items.slice(0, 10), proto: proto.value }));
    if (!chk) return null;
    const skipped = [...new Set(chk.items.flatMap((x) => x.skipped))];
    const notes = [...new Set(chk.items.flatMap((x) => x.notes || []))];
    ownOut.replaceChildren(
      notes.length ? notice('info', 'Блоб взят из папки blobs', notes.join('; ') + '. В конфиг он не добавляется; если стратегия подойдёт, проверка конфига предложит объявить его.') : null,
      items.length > chk.max ? notice('info', `Взяты первые ${chk.max} из ${items.length} стратегий`, 'Остальные проверьте следующим запуском.') : null,
      chk.items.map((x, i) => x.errors.length ? notice('bad', `Стратегия ${i + 1} не годится`, x.errors.join('; ')) : null),
      skipped.length ? notice('info', 'В проверке участвуют только шаги --lua-desync', 'Не взято: ' + skipped.join(' ')) : null);
    if (chk.items.some((x) => x.errors.length)) { toast('Поправьте свои стратегии или уберите строки с ошибками', { err: true }); return null; }
    return chk.items.map((x) => x.steps);
  }
  const multiRow = h('div', { class: 'frow', hidden: true }, h('span', { class: 'lbl', text: 'Сайты' }),
    h('div', { class: 'stack items' }, h('div', { class: 'row' }, mList, btn('Другие случайные', () => fillMulti(), 'small', 'refresh')), mHosts, mNote,
      h('span', { class: 'sm muted', text: 'Сначала каждый сайт открывается без обхода: что открывается и так или закрыто по IP, в подборе не участвует. Потом каждая стратегия проверяется на всех оставшихся сайтах; пять лучших и все свои — перепроверяются по одной. Каталог zapret2 и уточнение здесь не участвуют — на десяти сайтах это заняло бы часы. Обычно 3–8 минут.' })));
  const hostLbl = h('label', { class: 'lbl', for: 't-host', text: 'Сайт' });
  function drawMode() {
    const multi = mode.value === 'multi';
    hostLbl.textContent = multi ? 'Протокол' : 'Сайт';
    host.hidden = multi;
    multiRow.hidden = !multi;
    ownRow.hidden = multi && isQuic();
    tlsRow.hidden = proto.value !== 'https';
    portsLbl.hidden = isQuic();
    oneRows.forEach((el) => { el.hidden = multi || isQuic(); });
    quicNote.hidden = !isQuic() || multi;
    if (multi && !mHosts.value) fillMulti();
  }
  const out = h('div', { class: 'stack' });
  const startBtn = btn(tab === 'trace' ? 'Запустить трассировку' : 'Запустить тест', start, 'primary', 'play');
  let routeInfo = null;
  let routeLoading = null;
  let lastStatus = null;

  async function start() {
    const cmd = tab === 'trace' ? 'trace_start' : 'test_start';
    const sets = [setCfg.checked && 'config', setCfg.checked && setPorts.checked && !isQuic() && 'ports', setStd.checked && 'std', setHist.checked && 'hist', setHist.checked && 'other', setComm.checked && 'community', catSel.value].filter(Boolean);
    const single = tab === 'pick' && mode.value !== 'multi';
    if (tab !== 'trace' && !sets.length && !(tab === 'pick' && ownTa.value.trim())) { toast('Выберите, что пробовать', { err: true }); return; }
    if (tab === 'pick' && mode.value === 'multi') {
      if (isQuic()) { toast('Подбор для QUIC — пока только для одного сайта', { err: true }); return; }
      const hosts = mHosts.value.split(/[\s,]+/).filter(Boolean);
      const mOwn = await ownStrategies();
      if (!mOwn) return;
      const mSets = sets.filter((x) => ['config', 'ports', 'std', 'hist', 'community'].includes(x));
      if (!mSets.length && !mOwn.length) { toast('Выберите, что пробовать: каталог zapret2 в подборе для нескольких сайтов не участвует', { err: true }); return; }
      if (!await guarded(() => api('multi_start', { hosts, list: mList.value, proto: proto.value, sets: mSets, own: mOwn, repeats: Number(repeats.value), tls12: proto.value === 'https' && tls12.checked }))) return;
      poll();
      return;
    }
    const own = single ? await ownStrategies() : [];
    if (!own) return;
    if (!await guarded(() => api(cmd, { host: host.value, proto: proto.value, sets: isQuic() ? sets.filter((x) => !['ext', 'full'].includes(x)) : sets, own, repeats: Number(repeats.value), refine: !isQuic() && refine.checked, tls12: tab === 'pick' && proto.value === 'https' && tls12.checked }))) return;
    poll();
  }
  function poll() {
    pollTest(async () => {
      if (!out.isConnected) return false;
      const s = await api('test_status').catch(() => null);
      if (!s) return true;
      drawStatus(s);
      return ['running', 'starting'].includes(s.state);
    }, 1500);
  }

  function drawStatus(s) {
    const running = ['running', 'starting'].includes(s.state);
    startBtn.disabled = running;
    lastStatus = s;
    // поле «Сайт» показывает сайт, для которого показаны результаты, пока его не начали править
    if (s.host && s.type !== 'multi' && !host.dataset.edited && host.value !== s.host) host.value = s.host;
    // для рекомендации нужно знать, через какой профиль сайт идёт сейчас
    if (!running && s.host && s.type !== 'trace' && s.type !== 'multi' && (!routeInfo || routeInfo.host !== s.host) && routeLoading !== s.host) {
      routeLoading = s.host;
      api('check', { host: s.host }).then((r) => { routeInfo = r; routeLoading = null; if (out.isConnected) drawStatus(lastStatus); }).catch(() => { routeLoading = null; });
    }
    // brought up to date in place (morph): the progress moves, result rows appear and keep their nodes, nothing blinks.
    // Each block has a key, so one that comes or goes does not shift the others
    const put = (...blocks) => morphKids(out, blocks);
    if (s.state === 'idle') { put(); return; }
    if (s.type === 'freeze') { put(keyed(running ? notice('info', 'Идёт проба «Обрыв на 16 КБ»', 'Подбор можно запустить, когда она закончится.', h('a', { class: 'btn small', href: PAGES.frz[0] }, 'Открыть')) : null, 'other')); return; }
    if (s.type === 'cfg') { put(keyed(running ? notice('info', 'Идёт проверка чужих конфигов', 'Подбор можно запустить, когда она закончится.', h('a', { class: 'btn small', href: PAGES.cfg[0] }, 'Открыть')) : null, 'other')); return; }
    if ((s.type === 'trace') !== (tab === 'trace') && !running) { put(); return; }
    const head = running ? h('section', { class: 'panel', 'data-key': 'run' },
      h('div', { class: 'row' }, h('span', { class: 'spin' }), h('b', { text: `${s.type === 'trace' ? 'Трассировка' : s.type === 'multi' ? 'Подбор для' : 'Тест'} ${s.host}` }), h('span', { class: 'grow' }),
        h('span', { class: 'sm muted num', text: s.total ? `${s.done} из ${s.total}` : 'запуск…' }), btn('Остановить', () => api('test_stop'), 'small danger', 'stop')),
      s.total ? h('div', { class: 'progress', style: 'height:6px;border-radius:99px;background:var(--soft);overflow:hidden' }, h('i', { style: `display:block;height:100%;width:${Math.round(s.done / s.total * 100)}%;background:var(--accent)` })) : null,
      s.type === 'multi' && MULTI_STEP[s.phase] ? h('div', { class: 'sm' }, h('b', { text: MULTI_STEP[s.phase][0] }), ' — ' + MULTI_STEP[s.phase][1]) : null,
      s.current ? h('p', { class: 'sm muted', text: 'Сейчас: ' + s.current }) : null) : null;
    if (s.type === 'wide') {
      const p = s.pick;
      put(keyed(podkopNote(s), 'podkop'), keyed(wideResult(s, running), 'wide'), keyed(p && (p.results?.length || p.baseline) ? pickResult(p, false) : null, 'result'));
      return;
    }
    if (s.state === 'error') { put(keyed(notice('bad', s.title || 'Тест не удался', s.error || '', s.host && s.title ? btn('Подробный диагноз', () => go(diagHref(s.host)), 'small', 'search') : null), 'error')); return; }
    if (s.type === 'trace') { put(head, keyed(s.state === 'done' ? traceResult(s) : null, 'trace')); return; }
    if (s.type === 'multi') { put(head, keyed(podkopNote(s), 'podkop'), keyed(multiResult(s, running), 'multi')); return; }
    put(head, keyed(podkopNote(s), 'podkop'), keyed(s.results?.length || s.baseline ? pickResult(s, running) : null, 'result'));
  }

  function pickResult(s, running) {
    const rep = s.repeats || Number(repeats.value);
    const res = [...(s.results || [])];
    // рабочими считаются только проверенные по одной: то, что после параллельного прогона перепроверку не прошло
    // или не дошло до неё, сервер помечает unsure с ok = 0. Поэтому «перепроверенные первыми» не нужно — иначе
    // работавшая раньше стратегия (её проверяют по одной сразу, без отметки) уходила вниз, и «быстрее всех»
    // показывало не самую быструю
    const good = res.filter((x) => x.ok > 0 && x.ok === x.tries).sort((a, b) => b.ok - a.ok || a.ms - b.ms);
    const best = good[0];
    const baseline = s.baseline;
    const rows = res.sort((a, b) => (b.ok / Math.max(1, b.tries)) - (a.ok / Math.max(1, a.tries)) || (b.unsure ? 1 : 0) - (a.unsure ? 1 : 0) || (a.ms ?? 1e9) - (b.ms ?? 1e9)).map((x) => {
      const full = x.ok && x.ok === x.tries;
      return h('tr', { 'data-key': x.steps.join(' ') },
        h('td', {}, levelIcon(full ? 'ok' : x.ok || x.unsure ? 'warning' : 'error')),
        h('td', {}, h('div', {}, x.name, needsTs(x.steps) ? ' ' : null, needsTs(x.steps) ? tsChip() : null), h('code', { class: 'sm muted', style: 'word-break:break-all', text: x.steps.map((t) => t.replace('--lua-desync=', '')).join('  ') })),
        h('td', { class: 'sm muted src' }, x.from, x.hist ? h('div', { class: 'nowrap' }, chip('работала ' + fmtDate(x.hist), 'ok')) : null, x.confirmed ? h('div', { class: 'nowrap' }, full ? chip('перепроверена', 'ok') : chip('в пачке открывала, по одной — нет')) : null),
        h('td', { class: 'sm', text: full ? `открылся ${x.ok} из ${x.ok}` : x.ok ? `${x.ok} из ${x.tries}` : x.reason || 'не открылся' }),
        h('td', { class: 'num' }, x.ms ? x.ms + ' мс' : '—', timeNote(x)),
        h('td', {}, x.ok || x.community ? h('div', { class: 'row acts-row' }, x.ok ? applyMenu(x, s) : null, full ? wideBtn(x, s) : null, full || x.community ? shareBtn(x, s) : null) : null));
    });
    // blocks by name: a key each for morph, so a notice that appears at the end does not shift the table
    const NAMES = ['flow', 'dnspin', 'baseline', 'advice', 'best', 'community', 'slow', 'none', 'refine', 'failed', 'parallel', 'table'];
    return h('div', { class: 'stack' }, [
      flow(s, running, res, best),
      dnsPinNote(s.dns_pin, baseline),
      baseline && !(baseline.ok && s.dns_pin) ? (baseline.ok ? (s.proto === 'quic' ? notice('info', 'Без обхода по QUIC сайт отвечает', `Ответ за ${baseline.ms} мс — QUIC этого сайта не режут, стратегия не нужна.`)
        : notice('info', 'Без обхода сайт открывается', `HTTP ${baseline.code}, ${baseline.ms} мс — блокировки нет, стратегия не нужна.`))
        : s.proto === 'quic' ? notice('warn', 'Без обхода по QUIC сайт не отвечает', 'QUIC (HTTP/3) этого сайта режут. Сам сайт при этом может открываться: браузер ждёт ответа по QUIC и только потом переходит на обычное соединение — страницы и видео стартуют с задержкой.')
        : notice('warn', 'Без обхода сайт не открывается: ' + baseline.reason, baseline.reason === 'соединение сброшено' || baseline.reason === 'обрыв TLS' ? 'Похоже на блокировку по SNI (DPI) — обход поможет.' : isFreeze(baseline.reason) ? (best ? 'Это ограничение ТСПУ «16–20 КБ» для зарубежных хостингов. Ниже — стратегия, которая его снимает.' : FREEZE_HINT) : 'Похоже на блокировку по DPI или IP.')) : null,
      !running ? recommendation(s, good, best) : null,
      !running && best ? h('div', { class: 'notice ok' }, icon('ok'), h('div', { class: 'grow' },
        h('b', { text: `Работают ${good.length} из ${res.length}. Быстрее всех — ${best.name}, ${best.ms} мс.` }),
        h('div', { class: 't', text: (best.kbps ? `Скачивание до 1 МБ: ${fmtSpeed(best.kbps)}. ` : '') + best.from }), h('code', { class: 'sm', style: 'word-break:break-all', text: best.steps.join(' ') }),
        needsTs(best.steps) ? h('div', { class: 't', text: `На компьютерах с Windows эта стратегия сработает, только если включены временные метки TCP: ${WIN_TS_HINT}.` }) : null),
        h('div', { class: 'notice-actions' }, applyMenu(best, s, 'primary'), btn('Скопировать', () => copyText(best.steps.join('\n')), 'small', 'copy'), shareBtn(best, s, true), newTag('pick:share'))) : null,
      communityNote(s, best),
      !running && best ? slowNote(s) : null,
      !running && !best && res.length ? notice('bad', 'Ни одна стратегия не помогла', isFreeze(baseline?.reason) ? FREEZE_HINT : 'Попробуйте больше повторов, протокол HTTP или другой сайт. Если без обхода соединение не устанавливается вовсе — возможно, заблокирован IP, тогда nfqws2 не поможет.') : null,
      refinePanel(s, rep),
      s.failed && Object.keys(s.failed).length ? h('p', { class: 'sm muted' }, `Из каталога не помогли ${plural(Object.values(s.failed).reduce((a, b) => a + b, 0), 'стратегия', 'стратегии', 'стратегий')}: `
        + Object.entries(s.failed).sort((a, b) => b[1] - a[1]).map(([w, n]) => `${w} — ${n}`).join(', ') + '. В таблице — только то, что открыло сайт хоть раз.') : null,
      s.parallel && !running ? h('p', { class: 'sm muted', text: `Большой набор проверялся параллельно, по ${plural(s.parallel, 'стратегии', 'стратегии', 'стратегий')} сразу`
        + (s.parallel_max && s.parallel < s.parallel_max ? ` (а не по ${s.parallel_max}: пачка подстраивается под память роутера, чтобы он не начал тормозить)` : '') + '. Когда к одному сайту идёт много разных соединений одновременно, оборудование провайдера иногда путается, и такой итог может разойтись с проверкой по одной. Поэтому находки перепроверяются по одной, начиная с быстрых, пока не наберётся 8 подтверждённых (отметка «перепроверена»). Рабочими считаются только они; открывшие сайт лишь в пачке показаны в таблице, но применить их отсюда нельзя.' }) : null,
      panel(`Результаты для ${s.host}` + (s.proto === 'quic' ? ' · QUIC' : '') + (s.tls12 ? ' · TLS 1.2' : ''),h('span', { class: 'sm muted num', text: `${res.length} стратегий · ${rep} повт.` + (s.finished ? ` · ${Math.round((s.finished - s.started))} с` : '') }),
        h('div', { class: 'scroll' }, h('table', { class: 'tbl' }, h('thead', {}, h('tr', {}, h('th'), h('th', { text: 'Стратегия' }), h('th', { text: 'Откуда' }), h('th', { text: 'Результат' }), h('th', { class: 'num' }, 'Время', newTag('pick:speed')), h('th'))), h('tbody', {}, rows))))].map((el, i) => keyed(el, NAMES[i])));
  }

  // The steps of a pick for several sites: the counter alone («3 из 10», then «12 из 57») looked like nothing was
  // happening on the long first step (NFQ-L256)
  const MULTI_STEP = {
    baseline: ['Шаг 1 из 3', 'каждый сайт без обхода: что открывается и так, в подборе не участвует. Обычно до минуты'],
    scan: ['Шаг 2 из 3', 'все стратегии на всех сайтах, пачками'],
    confirm: ['Шаг 3 из 3', 'лучшие стратегии перепроверяются по одной'],
  };

  // The pick sees that a server answers, not how fast it goes: video is slowed down rather than closed, and QUIC is
  // judged by the reply to its first packet (NFQ-L260: «all green, YouTube does not play»)
  const VIDEO_HOST = /(^|\.)(googlevideo\.com|youtube\.com|ytimg\.com|youtu\.be)$/;
  function slowNote(s) {
    const video = VIDEO_HOST.test(s.host || ''), quic = s.proto === 'quic';
    if (!video && !quic) return null;
    if (!video) return notice('info', 'По QUIC видно только ответ сервера', 'Засчитывается ответ на первый пакет: замедление дальше по соединению так не видно. После применения проверьте сайт на устройстве.');
    return notice('info', 'Скорость видео так не проверить', (quic ? 'Подбор видит только ответ сервера на первый пакет QUIC' : 'Подбор видит только, что сервер отвечает и отдаёт первые 64 КБ')
      + ', а видео обычно замедляют, а не закрывают. После применения проверьте видео на устройстве.'
      + (quic ? ' Если QUIC не пройдёт, приложение YouTube уйдёт на обычный HTTPS: подберите стратегию и для него.' : ''));
  }

  // Ход подбора: без обхода → перебор → уточнение → итог
  function flow(s, running, res, best) {
    // the speed of the fastest is measured after the pick: the result is known, the last step is still running
    const speed = running && s.phase === 'speed';
    const now = running && !speed ? (s.phase || 'baseline') : 'done';
    const order = ['baseline', 'pick', 'refine', 'done'];
    const cell = (id, title, text) => h('div', { class: speed && id === 'done' ? 'now' : order.indexOf(id) < order.indexOf(now) || now === 'done' ? 'done' : id === now ? 'now' : '' }, h('b', { text: title }), h('span', { text }));
    const tried = res.filter((x) => !x.refined);
    const full = tried.filter((x) => x.ok && x.ok === x.tries).length;
    const part = tried.filter((x) => x.ok && x.ok < x.tries).length;
    return h('div', { class: 'flow' },
      cell('baseline', '1 · Без обхода', s.baseline ? (s.baseline.ok ? 'сайт открывается' : s.baseline.reason) : 'проверяю…'),
      cell('pick', '2 · Перебор', s.parallel && s.done > 1 ? `${plural(s.done - 1, 'стратегия', 'стратегии', 'стратегий')} из ${s.total - 1}: работают ${full}` + (part ? `, почти — ${part}` : '') + (s.phase === 'confirm' ? ' · перепроверка' : '')
        : res.length ? `${plural(tried.length, 'стратегия', 'стратегии', 'стратегий')}: работают ${full}` + (part ? `, почти — ${part}` : '') : 'ожидает'),
      cell('refine', '3 · Уточнение', s.refine ? `основа — ${s.refine.base}` : now === 'done' ? 'не понадобилось' : 'если рабочих не найдётся'),
      cell('done', '4 · Итог', speed ? 'замеряю скорость…' : now !== 'done' ? '' : best ? best.name : 'рабочей стратегии нет'));
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
    return h('div', { class: 'stack' },
      s.proto === 'quic'
        ? (s.result.ok ? notice('ok', `Ответил по QUIC через текущую конфигурацию: ${s.result.ms} мс`) : notice('bad', 'Не ответил по QUIC через текущую конфигурацию: ' + s.result.reason, 'Сам сайт при этом может открываться: браузер ждёт ответа по QUIC и потом переходит на обычное соединение.'))
        : s.result.ok ? notice('ok', `Открылся через текущую конфигурацию: HTTP ${s.result.code}, ${s.result.ms} мс`) : notice('bad', 'Не открылся через текущую конфигурацию: ' + s.result.reason),
      panel('Что произошло', null,
        h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Имя сайта' }), h('span', { class: 'mono', text: hostname || 'не определено (нет SNI/Host)' })),
        h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Профиль' }), profile ? h('a', { href: `#/settings/p${profile}`, text: `#${profile} ${p ? profName(p) : ''}` }) : h('span', { text: 'ни один — соединение прошло без обработки' })),
        actions.length ? h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Действия' }), h('ol', { class: 'sm mono', style: 'margin:0;padding-left:18px;display:grid;gap:var(--sp-1)' }, actions.map((a) => h('li', { text: a })))) : null,
        warnings.length ? h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Предупреждения' }), h('div', { class: 'stack items' }, warnings.map((w) => h('div', { class: 'help-iss warning' }, levelIcon('warning'), h('span', { class: 'mono sm', text: w }))))) : null),
      panel('Журнал обработки', h('span', { class: 'row', style: 'gap:8px' }, h('span', { class: 'sm muted num', text: plural(work.length, 'строка', 'строки', 'строк') }), copyBtn(() => preText(pre))),
        h('input', { class: 'input', placeholder: 'Фильтр', 'aria-label': 'Фильтр журнала', oninput: (e) => { q = e.target.value.toLowerCase(); draw(); } }), pre,
        h('details', {}, h('summary', { text: `запуск nfqws2 (${setup.length} строк)` }), (() => { const p = h('pre', { class: 'box', style: 'max-height:40vh' }); drawLog(p, setup); return copyBox(p); })())));
  }

  // Куда можно применить стратегию и что это затронет. Для неопытного главный вариант — «только для этого сайта».
  function applyTargets(x, s) {
    const steps = x.steps;
    const port = s.proto === 'http' ? 80 : 443;
    const rp = routeProfile(s);
    const covers = (p) => (p.ports[s.proto === 'quic' ? 'udp' : 'tcp'] || '').split(',').some((x) => { const [a, b] = x.split('-').map(Number); return port >= a && port <= (b || a); });
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

  function oneRow(el) { oneRows.push(el); return el; }

  // Итог подбора для нескольких сайтов
  function multiResult(s, running) {
    const hosts = s.hosts || [];
    const M = hosts.length;
    const skipped = (s.sites || []).filter((r) => r.skip);
    const sitesBox = (s.sites || []).length ? h('div', { class: 'stack lines' },
      h('div', { class: 'sm' }, h('b', { text: `Подбор для ${plural(M, 'сайта', 'сайтов', 'сайтов')}: ` }), h('span', { class: 'mono', style: 'white-space:normal;overflow-wrap:anywhere', text: hosts.join(', ') || '—' }), s.list ? h('span', { class: 'muted', text: ` (из ${s.list.name})` }) : null, s.tls12 ? h('span', { class: 'muted', text: ' · по TLS 1.2' }) : null),
      skipped.length ? h('div', { class: 'sm muted' }, 'Не участвуют: ', skipped.map((r, i) => [i ? '; ' : '', h('span', { class: 'mono', text: r.host }), ' — ' + r.skip])) : null,
      pinnedNote(s.sites)) : null;
    if (running || s.state === 'error') return panel(null, null, sitesBox);
    const res = s.results || [];
    const conf = res.filter((x) => x.confirmed && x.opened > 0);
    const best = conf[0];
    const missed = (x) => hosts.filter((hh) => !(x.per[hh]?.ok > 0 && x.per[hh].ok === x.per[hh].tries));
    const target = s.list ? `весь ${s.list.name}` : 'эти сайты';
    const actions = (x, primary) => h('div', primary ? { class: 'notice-actions' } : { class: 'row acts-row' },
      btn(primary ? `Профилем на ${target}` : 'Применить…', () => addListProfile(x.steps, s), 'small' + (primary ? ' primary' : ''), primary ? 'ok' : null,
        { title: `Новый профиль первым: эта стратегия для ${s.list ? 'всех сайтов из ' + s.list.name : 'этих сайтов'}; с проверкой и откатом` }),
      btn(primary ? 'Скопировать' : '', () => copyText(x.steps.join('\n')), 'small' + (primary ? '' : ' ghost'), 'copy', { title: 'Скопировать', 'aria-label': 'Скопировать' }));
    const verdict = !best
      ? notice('bad', 'Ни одна стратегия не открыла эти сайты', 'Попробуйте обычный подбор для каждого сайта отдельно — с каталогом zapret2 и уточнением: возможно, сайтам нужны разные стратегии.')
      : best.opened === M
        ? notice('ok', `Открывает все ${plural(M, 'сайт', 'сайта', 'сайтов')}: ${best.name}${best.ms ? `, ${best.ms} мс` : ''}`, best.steps.map((t) => t.replace('--lua-desync=', '')).join('  '), actions(best, true))
        : notice('warn', `Все сайты не открыла ни одна. Лучшая — ${best.opened} из ${M}: ${best.name}`,
          `Не открыла: ${missed(best).join(', ')}. Если поставить её на ${target}, эти сайты не откроются — им нужна другая стратегия (обычный подбор для каждого).`, actions(best, true));
    const shown = res.filter((x) => x.confirmed || x.scan > 0);
    const none = res.length - shown.length;
    const rows = shown.map((x) => {
      const full = x.confirmed && x.opened === M;
      return h('tr', {},
        h('td', {}, levelIcon(full ? 'ok' : x.confirmed && x.opened ? 'warning' : x.confirmed ? 'error' : 'info')),
        h('td', {}, h('div', {}, x.name, needsTs(x.steps) ? ' ' : null, needsTs(x.steps) ? tsChip() : null), h('code', { class: 'sm muted', style: 'word-break:break-all', text: x.steps.map((t) => t.replace('--lua-desync=', '')).join('  ') })),
        h('td', { class: 'sm muted src' }, x.from, x.hist ? h('div', { class: 'nowrap' }, chip('работала ' + fmtDate(x.hist), 'ok')) : null),
        h('td', { class: 'sm' }, x.confirmed
          ? [h('b', { text: `${x.opened} из ${M}` }), x.opened < M ? h('div', { class: 'muted' }, 'не открыла: ', h('span', { class: 'mono', text: missed(x).join(', ') })) : null]
          : h('span', { class: 'muted nowrap', title: 'Открыла столько сайтов в параллельной пачке; по одной не перепроверялась — применить нельзя', text: `пачка: ${x.scan} из ${M}` })),
        h('td', { class: 'num', text: x.ms ? x.ms + ' мс' : '—' }),
        h('td', {}, x.confirmed && x.opened ? actions(x, false) : null));
    });
    return h('div', { class: 'stack' }, panel(null, null, sitesBox), verdict,
      panel(`Результаты: ${s.candidates || res.length} стратегий на ${plural(M, 'сайте', 'сайтах', 'сайтах')}`, h('span', { class: 'sm muted', text: `${s.repeats} повт. · ${s.finished - s.started >= 90 ? Math.round((s.finished - s.started) / 60) + ' мин' : (s.finished - s.started) + ' с'}` }),
        rows.length ? h('div', { class: 'scroll' }, h('table', { class: 'tbl' },
          h('thead', {}, h('tr', {}, h('th'), h('th', { class: 'wide', text: 'Стратегия' }), h('th', { text: 'Откуда' }), h('th', { text: 'Открыла' }), h('th', { class: 'num', text: 'Время' }), h('th'))),
          h('tbody', {}, rows))) : null,
        h('p', { class: 'sm muted', text: (none ? `Не открыли ни одного сайта: ${plural(none, 'стратегия', 'стратегии', 'стратегий')}. ` : '') + 'Сначала все стратегии проверяются пачками, по одному запросу на сайт; пять лучших перепроверяются по одной — на каждом сайте до заданного числа повторов. Применить можно только перепроверенные; у остальных — сколько сайтов они открыли в пачке.' })));
  }

  // Профиль для списка (или этих сайтов) первым в своих профилях, затем перезапуск с проверкой
  async function addListProfile(steps, s) {
    const http = s.proto === 'http';
    await loadConf();
    const cur = S.conf.vars.NFQWS_ARGS_CUSTOM.trim() ? splitParts(tokensOf(S.conf.vars.NFQWS_ARGS_CUSTOM)) : [];
    const who = s.list ? `--hostlist=${s.list.path}` : `--hostlist-domains=${s.hosts.join(',')}`;
    const part = [`--filter-tcp=${http ? 80 : 443}`, `--filter-l7=${http ? 'http' : 'tls'}`, who, `--payload=${http ? 'http_req' : 'tls_client_hello'}`, ...steps];
    const what = s.list ? `всех сайтов из ${s.list.name}` : s.hosts.join(', ');
    if (!await ask(`Создать профиль для ${what}?\n\nОн встанет первым (#1), остальные профили сдвинутся на один номер и не изменятся. Сайты из ${s.list ? 'списка' : 'этого перечня'} пойдут через новую стратегию.\n\nПосле сохранения nfqws2 перезапустится с проверкой: если сайты перестанут открываться или вы не подтвердите за 3 минуты, всё вернётся как было.`, 'Создать профиль')) return;
    if (!await saveVars({ NFQWS_ARGS_CUSTOM: joinParts([part, ...cur]) }, `профиль для ${s.list ? s.list.name : 'нескольких сайтов'} из подбора`)) return;
    await safeRestart();
    go('#/settings/p1');
  }

  // Проверить найденную стратегию на других сайтах: открывает ли она не только свой
  function wideBtn(x, s) {
    if (s.proto === 'quic') return null;   // проверка на других сайтах идёт через curl, по TCP
    return btn('', () => wideDialog(x, s), 'small ghost icon', 'layers',
      { title: 'Проверить эту стратегию на других сайтах — например, из списка профиля', 'aria-label': 'Проверить на других сайтах' });
  }

  // Сайты для проверки: из списка профиля, откуда стратегия (или из user.list), без самого сайта, до 8 случайных
  async function wideSuggest(x, s) {
    const nums = [...(x.from || '').matchAll(/#(\d+)/g)].map((m) => +m[1]);
    const hostLists = S.state.lists.filter((l) => l.kind === 'host' && l.exists && l.used.some((u) => u.role === 'include'));
    const list = hostLists.find((l) => l.used.some((u) => nums.includes(u.profile) && u.role === 'include'))
      || hostLists.find((l) => l.name === 'user.list') || hostLists[0];
    if (!list) return { list: null, hosts: [] };
    const r = await api('list_get', { name: list.name }).catch(() => null);
    const all = (r?.content || '').split('\n').map((l) => l.replace(/#.*/, '').trim().toLowerCase())
      .filter((l) => l && !/[\^*/: ]/.test(l) && !/^[\d.]+$/.test(l) && l.includes('.') && l !== s.host && !s.host.endsWith('.' + l));
    for (let i = all.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [all[i], all[j]] = [all[j], all[i]]; }
    return { list, hosts: all.slice(0, 8) };
  }

  async function wideDialog(x, s) {
    const ta = h('textarea', { class: 'input mono', rows: 8, placeholder: 'rutracker.org\nyoutube.com', 'aria-label': 'Сайты, по одному в строке', autocapitalize: 'off', spellcheck: 'false' });
    const from = h('p', { class: 'sm muted', text: 'Подбираю сайты из списка…' });
    const run = btn('Проверить', async () => {
      const hosts = ta.value.split(/[\s,]+/).filter(Boolean);
      if (!hosts.length) { toast('Укажите хотя бы один сайт', { err: true }); return; }
      if (!await guarded(() => api('wide_start', { steps: x.steps, hosts, proto: s.proto, repeats: s.repeats }))) return;
      bg.close();
      window.scrollTo({ top: out.getBoundingClientRect().top + window.scrollY - 80, behavior: 'smooth' });
      poll();
    }, 'primary', 'play');
    const bg = modal('Проверить на других сайтах', h('div', { class: 'stack modal-b' },
      h('div', {}, h('b', { text: x.name }), h('code', { class: 'sm muted', style: 'display:block;word-break:break-all', text: x.steps.map((t) => t.replace('--lua-desync=', '')).join('  ') })),
      h('p', { class: 'sm', text: `Стратегия открыла ${s.host}. Откроет ли она другие сайты? Это стоит проверить, прежде чем ставить её на профиль со списком: если она работает только для одного сайта, остальные из списка перестанут открываться.` }),
      h('label', { class: 'lbl', text: 'Сайты — до 10, по одному в строке' }), ta, from,
      h('p', { class: 'sm muted', text: 'Сначала каждый сайт открывается без обхода: что открывается и так, в итог не входит. Потом — через отдельный nfqws2 с этой стратегией, как в подборе. Ваш трафик и основной nfqws2 не затрагиваются. Около 10–20 секунд на сайт.' })), run);
    const sug = await wideSuggest(x, s);
    ta.value = sug.hosts.join('\n');
    from.textContent = sug.list ? (sug.hosts.length ? `Взяты случайные сайты из ${sug.list.name} — можно поменять.` : `В ${sug.list.name} нет других сайтов — впишите свои.`) : 'Списков сайтов нет — впишите сайты сами.';
  }

  function wideResult(s, running) {
    const rows = s.sites || [];
    const counted = rows.filter((r) => !r.skip && r.baseline && !r.baseline.ok && r.ok !== undefined);
    const okN = counted.filter((r) => r.ok > 0 && r.ok === r.tries).length;
    const n = counted.length;
    const verdict = running || s.state === 'error' ? null : !n
      ? notice('info', 'Проверять было не на чем', 'Все сайты открываются и без обхода (или их имена не находятся) — итог ничего не говорит о стратегии. Впишите сайты, которые у вас не открываются без обхода.')
      : okN === n ? notice('ok', `Открыла все: ${okN} из ${n}`, 'Похоже, стратегия работает не только для одного сайта — её можно ставить на профиль со списком.')
        : okN ? notice('warn', `Открыла ${okN} из ${n}`, `Для профиля со всем списком она не подойдёт: часть сайтов не откроется. ${s.pick ? `Лучше применить её только для ${s.pick.host} или подобрать стратегию для остальных.` : 'Для остальных сайтов нужна другая стратегия.'}`)
          : notice('bad', `Не открыла ни один из ${n}`, s.pick ? `Похоже, стратегия работает только для ${s.pick.host} — ставьте её профилем только для него.` : 'Эти сайты она не открывает.');
    return h('section', { class: 'panel' },
      h('div', { class: 'row' }, running ? h('span', { class: 'spin' }) : null, h('h2', { text: 'На других сайтах: ' + s.name }), newTag('pick:wide'), h('span', { class: 'grow' }),
        running ? h('span', { class: 'sm muted num', text: s.total ? `${s.done} из ${s.total}` : 'запуск…' }) : null,
        running ? btn('Остановить', () => api('test_stop'), 'small danger', 'stop') : null),
      h('code', { class: 'sm muted', style: 'word-break:break-all', text: (s.steps || []).map((t) => t.replace('--lua-desync=', '')).join('  ') }),
      running && s.current ? h('p', { class: 'sm muted', text: 'Сейчас: ' + s.current }) : null,
      s.state === 'error' ? notice('bad', 'Проверка не удалась', s.error || '') : verdict,
      rows.length ? h('div', { class: 'scroll' }, h('table', { class: 'tbl' },
        h('thead', {}, h('tr', {}, h('th'), h('th', { class: 'wide', text: 'Сайт' }), h('th', { text: 'Без обхода' }), h('th', { text: 'Со стратегией' }), h('th', { class: 'num', text: 'Время' }))),
        h('tbody', {}, rows.map((r) => {
          const full = r.ok > 0 && r.ok === r.tries;
          const free = r.baseline?.ok;
          return h('tr', {},
            h('td', {}, r.ok === undefined && !r.skip ? null : levelIcon(r.skip || free ? 'info' : full ? 'ok' : r.ok ? 'warning' : 'error')),
            h('td', { class: 'mono' }, r.host, r.dns_pin ? [' ', chip('DNS подменяет адрес', 'warn')] : null),
            h('td', { class: 'sm', text: r.skip ? r.skip : free ? (r.dns_pin ? 'по настоящему адресу открывается — не в счёт' : 'открывается — не в счёт') : r.baseline?.reason || '—' }),
            h('td', { class: 'sm', text: r.skip ? '—' : r.ok === undefined ? (running ? 'ждёт' : 'не проверен') : full ? `открылся ${r.ok} из ${r.ok}` : r.ok ? `${r.ok} из ${r.tries}` : r.reason || 'не открылся' }),
            h('td', { class: 'num', text: r.ms ? r.ms + ' мс' : '—' }));
        })))) : null,
      running ? null : h('div', { class: 'row' },
        btn('Проверить на других сайтах', () => wideDialog({ name: s.name, steps: s.steps, from: '' }, { host: s.host, proto: s.proto, repeats: s.repeats }), 'small', 'refresh')));
  }

  function applyMenu(x, st, cls = '', label = 'Применить…') {
    const wrap = h('span', { class: 'menu', 'data-whole': '1' });   // the button opens its own list: morph keeps it whole
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
    const route = routeInfo.routes[routeKey(s)].steps;
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
    if (!await ask(`Добавить ${s.host} в ${lt.list.name}?\n\nПрофиль #${lt.profile} уже использует стратегию, которая сработала в тесте. Новый профиль не создаётся, nfqws2 перечитает список сам — перезапуск не нужен.`, 'Добавить')) return;
    if (!await guarded(() => api('list_add', { name: lt.list.name, items: [s.host] }))) return;
    api('pick_applied', { host: s.host, steps: x.steps, target: `список ${lt.list.name} профиля #${lt.profile}` }).catch(() => {});
    await loadState().catch(() => {});
    routeInfo = await api('check', { host: s.host }).catch(() => routeInfo);
    const now = routeProfile(s);
    const undo = { undo: () => undoLast(false) };
    if (now === lt.profile) toast(`${s.host} добавлен в ${lt.list.name} и теперь идёт через профиль #${lt.profile}`, undo);
    else {
      const st = routeInfo.routes[routeKey(s)].steps.find((r) => r.profile === lt.profile);
      toast(`${s.host} добавлен в ${lt.list.name}, но через профиль #${lt.profile} не пошёл: ` + (now ? `его перехватывает профиль #${now}` : st?.why || 'причина не определена'), { err: true, ...undo });
    }
    if (out.isConnected) drawStatus(lastStatus);
  }

  // Отдельный профиль «только для этого сайта» в начале своих профилей, затем перезапуск с проверкой
  async function addSiteProfile(steps, s) {
    const http = s.proto === 'http';
    await loadConf();
    const cur = S.conf.vars.NFQWS_ARGS_CUSTOM.trim() ? splitParts(tokensOf(S.conf.vars.NFQWS_ARGS_CUSTOM)) : [];
    const quic = s.proto === 'quic';
    const part = quic ? ['--filter-udp=443', '--filter-l7=quic', `--hostlist-domains=${s.host}`, '--payload=quic_initial', ...steps]
      : [`--filter-tcp=${http ? 80 : 443}`, `--filter-l7=${http ? 'http' : 'tls'}`, `--hostlist-domains=${s.host}`, `--payload=${http ? 'http_req' : 'tls_client_hello'}`, ...steps];
    // UDP 443 должен доходить до nfqws2: иначе профиль есть, а пакеты в него не попадают
    const udpPorts = (S.conf.vars.UDP_PORTS || '').split(',').map((x) => x.trim()).filter(Boolean);
    const udpHas = udpPorts.some((x) => { const [a, b] = x.split(/[:-]/).map(Number); return 443 >= a && 443 <= (b || a); });
    const extra = quic && !udpHas ? { UDP_PORTS: [...udpPorts, '443'].join(',') } : {};
    if (!await ask(`Создать профиль только для ${s.host}${quic ? ' (QUIC, UDP 443)' : ''}?\n\nОн встанет первым (#1), остальные профили сдвинутся на один номер. Другие сайты не затронет.\n\nПосле сохранения nfqws2 перезапустится с проверкой: если сайты перестанут открываться или вы не подтвердите за 3 минуты, всё вернётся как было.`, 'Создать профиль')) return;
    if (!await saveVars({ NFQWS_ARGS_CUSTOM: joinParts([part, ...cur]), ...extra }, `профиль только для ${s.host}${quic ? ' (QUIC)' : ''}`)) return;
    api('pick_applied', { host: s.host, steps, target: `отдельный профиль для ${s.host}` }).catch(() => {});
    await safeRestart();
    go('#/settings/p1');
  }

  // Через какой профиль тестовый сайт идёт сейчас (по проверке сайта)
  function routeProfile(s) {
    if (!routeInfo || routeInfo.host !== s.host) return null;
    return routeInfo.routes[routeKey(s)].profile;
  }

  // Рекомендация по итогам теста — простыми словами
  function recommendation(s, good, best) {
    const b = s.baseline;
    if (b?.ok) {
      // без обхода открывается, а стратегия нынешнего профиля — нет: профиль сайт ломает
      const rp = routeKey(s) === 'https' ? routeProfile(s) : null;
      const mine = rp && (s.results || []).find((r) => new RegExp(`#${rp}(?!\\d)`).test(r.from || ''));
      if (mine && !mine.ok && prof(rp)) return brokenNotice(s.host, prof(rp), `${s.host} открывается и без обхода, а со стратегией профиля #${rp} — нет${mine.reason ? ` (${mine.reason})` : ''}. Обход этому сайту не нужен — исключите его из профиля.`);
      return notice('info', 'Менять ничего не нужно', `${s.host} открывается и без обхода — блокировки сейчас нет.`);
    }
    if (!routeInfo || routeInfo.host !== s.host) return h('div', { class: 'skeleton sm' }, h('span', { class: 'spin' }), 'Смотрю, через какой профиль сайт идёт сейчас…');
    const rp = routeProfile(s);
    const p = rp ? confProf(rp) : null;
    const currentWorks = rp && good.some((x) => x.from.includes(`#${rp}`));
    if (currentWorks && s.proto === 'quic') {
      return notice('ok', 'Менять ничего не нужно', `Сейчас QUIC для ${s.host} идёт через профиль #${rp} ${p ? profName(p) : ''}, и его стратегия в тесте сработала.`);
    }
    if (currentWorks) {
      return notice('ok', 'Менять ничего не нужно', `Сейчас ${s.host} идёт через профиль #${rp} ${p ? profName(p) : ''}, и его стратегия в тесте сработала. Если у вас сайт всё равно не открывается, причина скорее в браузере (HTTP/3 — QUIC), DNS или в том, что сайт идёт через podkop. Если не открывается только на компьютере с Windows — ${WIN_TS_HINT}; подбор идёт с роутера, а у него метки есть.`);
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
      h('div', { class: 'notice-actions' }, btn('Применить только для этого сайта', () => addSiteProfile(best.steps, s), 'small primary', 'ok', { title: 'Только для ' + s.host }), applyMenu(best, s, '', 'Другие варианты…')));
  }

  const hist = await api('tests_history').catch(() => ({ items: [] }));
  main.append(
    bare ? null : h('div', { class: 'vh' }, h('h1', { text: tab === 'trace' ? 'Трассировка' : 'Подбор стратегии' })),
    panel(null, null,
      tab === 'pick' ? h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Подбирать' }), h('div', { class: 'row' }, modeSeg, newTag('pick:multi'))) : null,
      h('div', { class: 'frow' }, hostLbl, h('div', { class: 'row' }, host, proto, tab === 'pick' ? newTag('pick:quic') : newTag('trace:quic'))),
      tab === 'pick' ? [multiRow, quicNote,
        h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Что пробовать' }), h('div', { class: 'pick-sets' },
          h('label', { class: 'chk' }, setCfg, 'стратегии из вашего конфига'), h('label', { class: 'chk' }, setStd, 'стандартный набор'),
          h('label', { class: 'chk', title: 'До восьми стратегий, которые сработали у абонентов вашего провайдера (база на GitHub, раз в сутки). Сначала — открывавшие этот сайт или его сеть.' }, setComm, 'стратегии сообщества', newTag('pick:community')),
          portsLbl,
          h('label', { class: 'chk', title: 'Стратегии из истории подборов: сначала работавшие для этого сайта, затем до пяти помогавших другим сайтам' }, setHist, 'что работало раньше'))),
        ownRow,
        oneRow(h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Каталог zapret2' }), h('div', { class: 'stack lines' },
          h('div', { class: 'row', style: 'min-width:0' }, catSel, newTag('pick:catalog')),
          h('span', { class: 'sm muted', text: 'Те же функции, позиции разреза и способы испортить фейк, что перебирает blockcheck2 из zapret2. Большой набор проверяется параллельно, до 15 стратегий сразу (на роутере со 128 МБ — по 5, чтобы он не тормозил); лучшие находки потом перепроверяются по одной.' })))),
        h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Повторов' }), h('div', { class: 'row' }, repeats, h('span', { class: 'sm muted', text: 'Стратегия засчитывается, если сайт открылся каждый раз.' }))),
        tlsRow,
        oneRow(h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Уточнение' }), h('div', { class: 'stack lines' },
          h('label', { class: 'chk' }, refine, 'искать вариант полегче, даже если рабочая стратегия найдена'),
          h('span', { class: 'sm muted', text: 'Если рабочих стратегий нет, подбор сам пробует довести лучшую: меняет число фейков (до 20), способ их порчи и имя в фейке. Это ещё 2–5 минут.' }))))] :
        h('p', { class: 'sm muted', text: 'Трассировка открывает сайт через копию текущей конфигурации с подробным журналом nfqws2 — видно, какой профиль сработал и что сделали стратегии.' }),
      h('div', { class: 'row' }, startBtn, h('span', { class: 'sm muted grow note-wide', text: 'Работает отдельный процесс nfqws2 на очереди 301 только для проверочных соединений роутера. Ваш трафик и основной nfqws2 не затрагиваются.' }))),
    out,
    tab === 'pick' && !bare && hist.items.length ? panel('Последние подборы', linkBtn(PAGES.phist[0], 'Вся история'), h('div', { class: 'scroll' }, h('table', { class: 'tbl' },
      h('thead', {}, h('tr', {}, h('th', { text: 'Когда' }), h('th', { text: 'Сайт' }), h('th', { text: 'Без обхода' }), h('th', { class: 'num', text: 'Сработало' }), h('th', { text: 'Лучшая' }))),
      h('tbody', {}, hist.items.slice(0, 5).map((x) => h('tr', {}, h('td', { class: 'date', text: fmtDate(x.ts) }), h('td', { class: 'mono' }, h('a', { href: pickHistHref(x.host), text: x.host })),
        h('td', { text: x.baseline?.ok ? 'открывается' : x.baseline?.reason || '—' }), h('td', { class: 'num', text: `${x.ok} из ${x.total}` }), h('td', { class: 'mono sm', text: x.best || '—' }))))))) : null);
  if (isQuic()) drawMode();   // пришли из диагноза с ?proto=quic
  poll();
}
