'use strict';
// Interface: page: device connections.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ Device connections: what a program on a home device talks to, and whether answers come back ============

async function viewConns(main) {
  const out = h('div', { class: 'stack', style: 'gap:14px' });
  const devSel = h('select', { class: 'select', 'aria-label': 'Устройство' });
  const secSel = h('select', { class: 'select', 'aria-label': 'Сколько записывать' }, [[30, '30 секунд'], [60, '1 минута'], [120, '2 минуты']].map(([v, t]) => h('option', { value: v, text: t, selected: v === 60 })));
  const startBtn = btn('Записать', start, 'primary');
  let onlyBad = false;
  main.append(
    h('div', { class: 'vh' }, h('h1', { text: 'Соединения устройства' })),
    h('p', { class: 'muted dns-lead', text: 'Куда ходит программа на устройстве вашей сети и приходят ли ответы. Нужно, когда сайт открывается, а приложение работает плохо: голос в Discord обрывается, игра не входит в мир. Запись только читает таблицу соединений роутера и ничего не меняет.' }),
    panel(null, null,
      h('div', { class: 'conn-form' }, devSel, secSel, startBtn),
      h('p', { class: 'sm muted', text: 'Пока идёт запись, пользуйтесь программой на этом устройстве: позвоните в Discord, зайдите в игру — и дождитесь обрыва, если он есть.' })),
    out);

  async function loadDevices(keep) {
    const r = await api('conns_devices').catch(() => ({ items: [] }));
    if (r.noacct) out.before(notice('warn', 'Счётчики соединений выключены', 'В системе выключен учёт трафика соединений (nf_conntrack_acct = 0) — запись ничего не покажет. Включить: sysctl -w net.netfilter.nf_conntrack_acct=1 (до перезагрузки роутера).'));
    devSel.replaceChildren(...(r.items.length ? r.items.map((d) => h('option', { value: d.ip, selected: d.ip === keep,
      text: (d.name ? `${d.name} — ${d.ip}` : d.ip) + (d.flows ? ` · ${plural(d.flows, 'соединение', 'соединения', 'соединений')}` : '') }))
      : [h('option', { value: '', text: 'Устройств с соединениями нет' })]));
    devSel.dispatchEvent(new Event('change'));
  }
  async function start() {
    const s = await guarded(() => api('conns_start', { ip: devSel.value, secs: Number(secSel.value) }));
    if (s) { draw(s); poll(); }
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
    startBtn.disabled = !!s.running;
    if (!s.started) {
      morphKids(out, []);
      return;
    }
    const flows = s.flows || [];
    const nores = flows.filter((f) => f.noans);
    const stalled = flows.filter((f) => !f.noans && f.stalled);
    const unseen = flows.filter((f) => f.seen === false);
    const udpBad = flows.filter((f) => f.proto === 'udp' && f.noans === f.n);
    const sum = h('div', { class: 'conn-sum' },
      chip(`${plural(flows.length, 'направление', 'направления', 'направлений')}`, ''),
      nores.length ? chip(`без ответа: ${nores.length}`, 'bad') : null,
      stalled.length ? chip(`замерли: ${stalled.length}`, 'warn') : null,
      unseen.length && !s.running ? chip(`nfqws2 не видит: ${unseen.length}`, 'warn') : null,
      h('span', { class: 'grow' }),
      flows.some(bad) ? h('label', { class: 'chk' }, h('input', { type: 'checkbox', checked: onlyBad, onchange: (e) => { onlyBad = e.target.checked; draw(s); } }), 'Только с проблемами') : null);
    // hints say what the table shows, without guessing the fix
    const hints = s.running ? [] : [
      udpBad.length ? notice('bad', 'UDP без ответа', `Устройство шлёт UDP на ${udpBad.slice(0, 3).map((f) => `${f.dst}:${f.dport}`).join(', ')}${udpBad.length > 3 ? ' и ещё ' + (udpBad.length - 3) : ''}, а ответов нет. Так выглядит закрытый голос или игровой сервер. Сайт программы при этом может открываться — у него другие адреса.`) : null,
      unseen.some(bad) ? notice('warn', 'Порт мимо nfqws2', `Проблемные направления идут на порты, которых нет в TCP_PORTS/UDP_PORTS: ${[...new Set(unseen.filter(bad).map((f) => `${f.proto.toUpperCase()} ${f.dport}`))].join(', ')}. nfqws2 такой трафик не видит, и стратегии к нему не применяются. Порты задаются в «Основное».`,
        h('a', { class: 'btn small', href: '#/settings/basic' }, 'Основное')) : null,
      s.offload ? notice('info', 'Включено ускорение потоков', 'В межсетевом экране OpenWrt включён flow offloading: у ускоренных соединений счётчики перестают расти, и «нет ответа» может быть ложным.') : null,
      s.noacct ? notice('warn', 'Счётчики соединений выключены', 'В системе выключен учёт трафика соединений (nf_conntrack_acct = 0), поэтому записать нечего. Включить: sysctl -w net.netfilter.nf_conntrack_acct=1 — действует до перезагрузки роутера.')
        : !flows.length ? notice('info', 'Соединений не было', 'За время записи устройство никуда не ходило. Запустите программу и запишите ещё раз.') : null,
    ].map((x, i) => keyed(x, 'hint' + i)).filter(Boolean);
    const rows = flows.filter((f) => !onlyBad || bad(f)).sort((a, b) => bad(b) - bad(a));
    const tbl = h('div', { class: 'scroll' }, h('table', { class: 'tbl conn-tbl' },
      h('thead', {}, h('tr', {}, ...['Куда', 'Порт', 'Отправлено', 'Получено', 'Итог', 'nfqws2'].map((t, i) => h('th', { class: i === 2 || i === 3 ? 'num' : '', text: t })))),
      h('tbody', {}, rows.map((f) => {
        const [vt, vc] = verdict(f);
        const [nt, nc] = s.running ? ['…', ''] : nfqText(f);
        // data-h: on a phone each row turns into a card and the cells carry their own captions
        return h('tr', { 'data-key': `${f.proto}:${f.dst}:${f.dport}` },
          h('td', { class: 'c-dst' }, h('span', { class: 'mono', text: f.dst }), f.as_name ? h('div', { class: 'sm muted', text: f.as_name }) : null),
          h('td', { class: 'mono nowrap c-port', text: `${f.proto.toUpperCase()} ${f.dport}` }),
          h('td', { class: 'num', 'data-h': 'Отправлено' }, kb(f.ob), h('div', { class: 'sm muted', text: plural(f.op, 'пакет', 'пакета', 'пакетов') })),
          h('td', { class: 'num', 'data-h': 'Получено' }, kb(f.rb), h('div', { class: 'sm muted', text: plural(f.rp, 'пакет', 'пакета', 'пакетов') })),
          h('td', { class: 'c-res' }, chip(vt, vc)),
          h('td', { class: 'sm c-nfq' + (nc ? ' warnc' : ''), 'data-h': 'nfqws2', text: nt }));
      }))));
    // brought up to date in place (morph): the table grows while recording, rows keep their place, nothing blinks
    morphKids(out, [
      keyed(s.running ? (s.step === 'annotate' ? notice('info', 'Запись закончена, определяю сети и профили…', null, h('span', { class: 'spin' }))
        : notice('info', `Записываю… ${s.elapsed || 0} из ${s.secs} с`, 'Пользуйтесь программой на устройстве.', h('span', { class: 'spin' })))
        : s.died ? notice('warn', 'Запись прервалась', 'Запустите ещё раз.') : null, 'run'),
      ...hints,
      keyed(panel(s.running ? 'Соединения' : `Соединения ${s.ip}`, s.finished ? h('span', { class: 'sm muted', text: fmtDate(s.finished) }) : null, sum, keyed(flows.length ? tbl : null, 'tbl')), 'flows')]);
  }

  const s0 = await api('conns_get').catch(() => ({}));
  await loadDevices(s0.ip);
  draw(s0);
  if (s0.running) poll();
}
