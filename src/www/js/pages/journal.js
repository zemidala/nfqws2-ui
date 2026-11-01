'use strict';
// Interface: page: logs.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ Журнал ============

async function viewLog(main) {
  const data = await api('log');
  const st = S.state;
  let q = '';
  const pre = h('pre', { class: 'box', style: 'max-height:65vh' });
  const draw = () => {
    const lines = (data.syslog || []).slice().reverse().filter((l) => !q || l.toLowerCase().includes(q));
    drawLog(pre, lines, q ? 'Ничего не найдено' : 'Журнал пуст');
  };
  const search = h('input', { class: 'input', id: 'log-filter', placeholder: 'Фильтр', 'aria-label': 'Фильтр журнала', oninput: (e) => { q = e.target.value.toLowerCase(); draw(); } });
  draw();
  main.append(h('div', { class: 'vh' }, h('h1', { text: 'Журнал' }), h('span', { class: 'grow' }), btn('Обновить', () => route(), 'small', 'refresh')),
    data.syslog
      ? panel('Системный журнал nfqws2', copyBtn(() => preText(pre)), h('p', { class: 'sm muted', text: 'Последние 300 строк logread, новые сверху.' }), search, pre)
      : panel('Системный журнал nfqws2', null, h('p', { class: 'sm muted', text: 'На Keenetic системный журнал ведёт прошивка: откройте его в веб-интерфейсе роутера (раздел «Диагностика») и найдите строки nfqws2. Файлы журналов nfqws2 показаны ниже.' })),
    // note and buttons straight in the heading row: when it wraps, the note goes under the heading, the buttons stay together
    (data.files || []).map((f) => panel(f.name, [
      h('span', { class: 'sm muted', text: fmtBytes((data.logs?.files || []).find((x) => x.name === f.name)?.used ?? f.size) + ' в памяти' }),
      h('span', { class: 'grow' }),
      copyBtn(() => f.tail || ''),
      btn('Очистить', async () => { if (await clearLog(f.name)) route(); }, 'small ghost', 'trash')], (() => { const p = h('pre', { class: 'box' }); drawLog(p, (f.tail || '').split('\n').filter(Boolean), 'пусто'); return p; })())),
    panel('Трафик в очередь nfqws2', null, h('div', { class: 'scroll' }, h('table', { class: 'tbl' },
      h('thead', {}, h('tr', {}, h('th', { text: 'Направление' }), h('th', { text: 'Что' }), h('th', { class: 'num', text: 'Пакетов' }), h('th', { class: 'num', text: 'Объём' }))),
      h('tbody', {}, st.iptables.length ? st.iptables.map((x) => h('tr', {}, h('td', { text: x.dir === 'out' ? 'исходящие' : 'входящие' }), h('td', { text: x.proto + (x.what === 'data' ? ', первые пакеты' : ', ' + x.what) }),
        h('td', { class: 'num', text: x.pkts.toLocaleString('ru-RU') }), h('td', { class: 'num', text: fmtBytes(x.bytes) })))
        : h('tr', {}, h('td', { colspan: 4, class: 'muted', text: 'Правил нет — сервис остановлен?' })))))));
}
