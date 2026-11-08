'use strict';
// Interface: page: device connections.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ Device connections: what a program on a home device talks to, and whether answers come back ============

async function viewConns(main) {
  const out = h('div', { class: 'stack', style: 'gap:14px' });
  // the chosen length and device order are kept in this browser: a page switch or a reload does not reset them
  const KEY = 'nfqws-ui-conns';
  let prefs = {};
  try { prefs = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch { /* no storage */ }
  const keep = (k, v) => { prefs[k] = v; try { localStorage.setItem(KEY, JSON.stringify(prefs)); } catch { /* no storage */ } };
  const SORTS = [['flows-desc', 'Соединения ↓'], ['flows-asc', 'Соединения ↑'], ['ip-asc', 'IP ↑'], ['ip-desc', 'IP ↓'], ['name', 'Имя']];
  const sortSel = h('select', { class: 'select', 'aria-label': 'Порядок устройств', onchange: () => { keep('sort', sortSel.value); fillDevices(devSel.value); } },
    SORTS.map(([v, t]) => h('option', { value: v, text: '⇅ ' + t, selected: v === (prefs.sort || 'flows-desc') })));
  const devSel = h('select', { class: 'select', 'aria-label': 'Устройство' });
  const secSel = h('select', { class: 'select', 'aria-label': 'Сколько записывать', onchange: () => keep('secs', Number(secSel.value)) },
    [[30, '30 секунд'], [60, '1 минута'], [120, '2 минуты']].map(([v, t]) => h('option', { value: v, text: t, selected: v === (prefs.secs || 60) })));
  // «Записать» turns into «Остановить» while recording: what was recorded so far is then annotated as usual
  const startBtn = btn('Записать', () => (rec ? stop() : start()), 'primary');
  // site names come from the device's packets (tcpdump on the router); without tcpdump — an offer to install it
  const namesChk = h('input', { type: 'checkbox', checked: prefs.names !== false, onchange: () => keep('names', namesChk.checked) });
  const namesBox = h('div', {});
  let tab = 'all', rec = false, devices = [], tcpdump = false;
  main.append(
    h('div', { class: 'vh' }, h('h1', { text: 'Соединения устройства' })),
    h('p', { class: 'muted dns-lead', text: 'Куда ходит программа на устройстве вашей сети и приходят ли ответы. Нужно, когда сайт открывается, а приложение работает плохо: голос в Discord обрывается, игра не входит в мир. Запись только читает таблицу соединений роутера и ничего не меняет.' }),
    panel(null, null,
      h('div', { class: 'conn-form' }, sortSel, devSel, secSel, startBtn),
      namesBox,
      h('p', { class: 'sm muted' }, 'Пока идёт запись, пользуйтесь программой на этом устройстве: позвоните в Discord, зайдите в игру — и дождитесь обрыва, если он есть. Если нужное уже случилось, нажмите «Остановить запись» — записанное разберётся сразу.', newTag('conns:stop'))),
    out);

  function drawNames() {
    namesBox.replaceChildren(tcpdump
      ? h('label', { class: 'chk' }, namesChk, 'Определять имена сайтов', h('span', { class: 'muted', text: ' — по пакетам устройства во время записи (tcpdump)' }), newTag('conns:names'))
      : h('div', { class: 'notice info' }, icon('info'), h('div', { class: 'grow' }, h('b', { text: 'Имена сайтов не определяются: нет tcpdump' }),
        h('div', { class: 't', text: 'Без него в таблице только адреса и сети. tcpdump ставится из репозитория пакетов роутера и работает только во время записи.' })),
      btn('Установить tcpdump', installTcpdump, 'small', 'download'), newTag('conns:names')));
  }
  async function installTcpdump(e) {
    const b = e.currentTarget;
    b.disabled = true;
    b.querySelector('btn-l').textContent = 'Устанавливаю…';
    const r = await guarded(() => api('tcpdump_install'));
    if (r && !r.ok) toast('tcpdump не установился: ' + (r.error || ''), { err: true });
    tcpdump = !!r?.ok;
    drawNames();
  }
  async function loadDevices(sel) {
    const r = await api('conns_devices').catch(() => ({ items: [] }));
    if (r.noacct) out.before(notice('warn', 'Счётчики соединений выключены', 'В системе выключен учёт трафика соединений (nf_conntrack_acct = 0) — запись ничего не покажет. Включить: sysctl -w net.netfilter.nf_conntrack_acct=1 (до перезагрузки роутера).'));
    devices = r.items;
    tcpdump = !!r.tcpdump;
    drawNames();
    fillDevices(sel);
  }
  const ipNum = (ip) => ip.split('.').reduce((a, x) => a * 256 + Number(x), 0);
  function fillDevices(sel) {
    const by = {
      'flows-desc': (a, b) => (b.flows || 0) - (a.flows || 0), 'flows-asc': (a, b) => (a.flows || 0) - (b.flows || 0),
      'ip-asc': (a, b) => ipNum(a.ip) - ipNum(b.ip), 'ip-desc': (a, b) => ipNum(b.ip) - ipNum(a.ip),
      name: (a, b) => (a.name || '￿' + a.ip).localeCompare(b.name || '￿' + b.ip),
    }[sortSel.value] || (() => 0);
    devSel.replaceChildren(...(devices.length ? [...devices].sort((a, b) => by(a, b) || ipNum(a.ip) - ipNum(b.ip)).map((d) => h('option', { value: d.ip, selected: d.ip === sel,
      text: (d.name ? `${d.name} — ${d.ip}` : d.ip) + (d.flows ? ` · ${plural(d.flows, 'соединение', 'соединения', 'соединений')}` : '') }))
      : [h('option', { value: '', text: 'Устройств с соединениями нет' })]));
    devSel.dispatchEvent(new Event('change'));
  }
  async function start() {
    const s = await guarded(() => api('conns_start', { ip: devSel.value, secs: Number(secSel.value), names: tcpdump && namesChk.checked }));
    if (s) { draw(s); poll(); }
  }
  async function stop() {
    startBtn.disabled = true;   // until the job answers: annotating is not stoppable
    await guarded(() => api('conns_stop'));
  }
  function poll() {
    pollTest(async () => {
      if (!out.isConnected) return false;
      const s = await api('conns_get').catch(() => null);
      if (!s) return true;
      draw(s);
      return !!s.running;
    }, 2000);
  }

  const kb = (b) => fmtBytes(b);
  // verdict for a row, counted per connection: one answering connection must not hide the failed ones
  const of = (k, f) => f.n > 1 ? ` у ${k} из ${f.n}` : '';
  const verdict = (f) => f.noans === f.n ? ['нет ответа', 'bad'] : f.noans ? [`нет ответа${of(f.noans, f)}`, 'warn']
    : f.stalled ? [`замер на ${kb(f.stall)}${of(f.stalled, f)}`, 'warn'] : ['отвечает', 'ok'];
  const bad = (f) => verdict(f)[1] !== 'ok';
  function nfqText(f) {
    if (f.podkop) return ['идёт через podkop', ''];
    if (!f.seen) return [`порт ${f.dport} nfqws2 не видит`, 'warn'];
    if ([80, 443].includes(f.dport)) return ['видит; профиль — по имени сайта', ''];
    if (!f.profile) return ['видит, но ни один профиль не берёт', 'warn'];
    const p = prof(f.profile);
    return [`профиль #${f.profile}` + (p ? ` «${profName(p)}»` : '') + (f.l7 ? `, если это ${f.l7}` : ''), ''];
  }

  function draw(s) {
    // recording — the button stops it; annotating — it waits; done — it starts a new recording
    rec = !!s.running && s.step !== 'annotate';
    startBtn.disabled = !!s.running && !rec;
    startBtn.className = 'btn ' + (rec ? 'danger' : 'primary');
    // «Остановить запись», not just «Остановить»: the top bar has an «Остановить» that stops nfqws2
    startBtn.querySelector('btn-l').textContent = rec ? 'Остановить запись' : 'Записать';
    [sortSel, devSel, secSel, namesChk].forEach((x) => { x.disabled = !!s.running; });
    if (!s.started) {
      morphKids(out, []);
      return;
    }
    const flows = s.flows || [];
    const nores = flows.filter((f) => f.noans);
    const stalled = flows.filter((f) => !f.noans && f.stalled);
    const unseen = flows.filter((f) => f.seen === false);
    const udpBad = flows.filter((f) => f.proto === 'udp' && f.noans === f.n);
    // filter buttons with their counts (mockup local/design/bbb.html, Б); an empty one is not shown, «Мимо nfqws2» —
    // once the recording is annotated, before that nobody knows
    const tabs = [['all', 'Все', flows], ['noans', 'Без ответа', nores], ['stalled', 'Замерли', stalled], ['unseen', 'Мимо nfqws2', s.running ? [] : unseen]];
    if (!tabs.some(([k, , l]) => k === tab && (k === 'all' || l.length))) tab = 'all';
    const sum = h('div', { class: 'conn-tabs', role: 'group', 'aria-label': 'Показать' }, tabs.map(([k, t, l]) => k === 'all' || l.length
      ? h('button', { type: 'button', class: 'conn-tab' + (k === tab ? ' on' : ''), 'aria-pressed': String(k === tab), text: `${t} ${l.length}`, onclick: () => { tab = k; draw(s); } }) : null));
    // hints say what the table shows, without guessing the fix
    const hints = s.running ? [] : [
      udpBad.length ? notice('bad', 'UDP без ответа', `Устройство шлёт UDP на ${udpBad.slice(0, 3).map((f) => `${f.dst}:${f.dport}`).join(', ')}${udpBad.length > 3 ? ' и ещё ' + (udpBad.length - 3) : ''}, а ответов нет. Так выглядит закрытый голос или игровой сервер. Сайт программы при этом может открываться — у него другие адреса.`) : null,
      unseen.some(bad) ? notice('warn', 'Порт мимо nfqws2', `Проблемные направления идут на порты, которых нет в TCP_PORTS/UDP_PORTS: ${[...new Set(unseen.filter(bad).map((f) => `${f.proto.toUpperCase()} ${f.dport}`))].join(', ')}. nfqws2 такой трафик не видит, и стратегии к нему не применяются. Порты задаются в «Основное».`,
        h('a', { class: 'btn small', href: '#/settings/basic' }, 'Основное')) : null,
      s.offload ? notice('info', 'Включено ускорение потоков', 'В межсетевом экране OpenWrt включён flow offloading: у ускоренных соединений счётчики перестают расти, и «нет ответа» может быть ложным.') : null,
      s.noacct ? notice('warn', 'Счётчики соединений выключены', 'В системе выключен учёт трафика соединений (nf_conntrack_acct = 0), поэтому записать нечего. Включить: sysctl -w net.netfilter.nf_conntrack_acct=1 — действует до перезагрузки роутера.')
        : !flows.length ? notice('info', 'Соединений не было', 'За время записи устройство никуда не ходило. Запустите программу и запишите ещё раз.') : null,
    ].map((x, i) => keyed(x, 'hint' + i)).filter(Boolean);
    const rows = (tabs.find(([k]) => k === tab)[2]).slice().sort((a, b) => bad(b) - bad(a));
    // a pick needs a site name and a web port; for anything else (games, voice) the table already says what is wrong
    // a CDN address may carry several names: the first is from the device's own greeting when it had one
    const name = (f) => f.names?.[0];
    const pickable = (f) => !s.running && name(f) && f.proto === 'tcp' && [80, 443].includes(f.dport) && bad(f) && !f.podkop;
    const pickLink = (f) => { const u = pickHref(name(f)); return f.dport === 80 ? u + (u.includes('?') ? '&' : '?') + 'proto=http' : u; };
    // the site's name over its address; without a name — the network, without that — the address alone
    const tbl = h('div', { class: 'conn-box' }, h('table', { class: 'conn-b' },
      h('thead', {}, h('tr', {}, ...['Куда / IP', 'Порт', 'Объём', 'Статус', 'Действие'].map((t) => h('th', { text: t })))),
      h('tbody', {}, rows.map((f) => {
        const [vt, vc] = verdict(f);
        const top = name(f) || f.as_name;
        return h('tr', { 'data-key': `${f.proto}:${f.dst}:${f.dport}` },
          h('td', {}, h('div', { class: 'c-host', text: top || f.dst }), top ? h('div', { class: 'c-ip', text: f.dst }) : null),
          h('td', { text: `${f.proto.toUpperCase()} ${f.dport}` }),
          h('td', { title: `отправлено ${plural(f.op, 'пакет', 'пакета', 'пакетов')} / получено ${plural(f.rp, 'пакет', 'пакета', 'пакетов')}`, text: `${kb(f.ob)} / ${kb(f.rb)}` }),
          // what nfqws2 does with it had a column of its own; now it is the status's hint
          h('td', { title: s.running ? null : 'nfqws2: ' + nfqText(f)[0] }, h('span', { class: 'c-st ' + vc, text: vt })),
          h('td', {}, pickable(f) ? h('a', { class: 'c-btn', href: pickLink(f), title: `Подбор стратегии для ${name(f)}`, text: 'Подобрать' }) : '—'));
      }))));
    // the full capture: for the experienced, folded away
    const pcapBox = !s.running && s.pcap ? h('details', { class: 'conn-x' }, h('summary', {}, icon('chev'), 'Для опытных'),
      h('div', { class: 'conn-xb' }, `Все пакеты устройства за эту запись (${fmtBytes(s.pcap)}${s.pcap_cut ? ', запись пакетов остановлена на 4 МБ' : ''}). Файл открывается в Wireshark: видно рукопожатие, сбросы и что именно отвечал сервер. Пропадёт со следующей записью.`,
        h('div', {}, h('a', { class: 'c-btn', href: 'api.php?download=conns-pcap' }, icon('download'), 'Скачать .pcap')))) : null;
    // brought up to date in place (morph): the table grows while recording, rows keep their place, nothing blinks
    morphKids(out, [
      keyed(s.running ? (s.step === 'annotate' ? notice('info', 'Запись закончена, определяю сети и профили…', null, h('span', { class: 'spin' }))
        : notice('info', `Записываю… ${s.elapsed || 0} из ${s.secs} с`, 'Пользуйтесь программой на устройстве.', h('span', { class: 'spin' })))
        : s.died ? notice('warn', 'Запись прервалась', 'Запустите ещё раз.') : null, 'run'),
      ...hints,
      keyed(Object.assign(panel(s.running ? 'Соединения' : `Соединения ${s.ip}`, s.finished ? h('span', { class: 'sm muted', text: fmtDate(s.finished) + (s.stopped ? ` · остановлена на ${s.elapsed || 0} с` : '') }) : null,
        sum, keyed(flows.length ? tbl : null, 'tbl'), keyed(pcapBox, 'pcap')), { className: 'panel conn-res' }), 'flows')]);
  }

  const s0 = await api('conns_get').catch(() => ({}));
  await loadDevices(s0.ip);
  draw(s0);
  if (s0.running) poll();
}
