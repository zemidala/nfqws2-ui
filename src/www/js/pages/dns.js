'use strict';
// Interface: page: DNS servers.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ DNS servers: which answer, which are intercepted, does the router's DNS fake addresses ============

const DNS_SITE_TEXT = { ok: ['верно', 'ok'], fake: ['заглушка', 'bad'], none: ['нет ответа', 'bad'], other: ['другой адрес', 'warn'], podkop: ['podkop', ''], unknown: ['не сверить', ''] };

// DNS page (approved mockup local/design/dns-page.html): one «Проверить» runs the quick check (own DNS, five servers,
// blocked sites) and then the extended one (about fifty public servers) in one background job. Four blocks: three
// verdict tiles; one piece of advice (nothing to change, or what to set with the three best servers); one table of
// all servers with the router's and the provider's own DNS first; sites — only those with a problem, the rest folded
async function viewDns(main) {
  const startBtn = btn('Проверить', start, 'primary');
  const when = h('span');
  const out = h('div', { class: 'stack', style: 'gap:14px' }, skel(1, 4));
  // own servers: checked with the public ones and kept on the router for the next checks
  const ownIn = h('input', { class: 'input mono grow', placeholder: '1.2.3.4, dns.example.com, https://dns.example.com/dns-query', autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'Свои DNS-серверы' });
  const onlyIn = h('input', { type: 'checkbox' });
  main.append(
    h('div', { class: 'vh' }, h('h1', { text: 'DNS-серверы' })),
    h('p', { class: 'muted dns-lead' }, 'Какие DNS-серверы отвечают с этого роутера, не перехватывает ли провайдер обычный DNS и не подменяет ли адреса закрытых сайтов. ', when),
    // the fields and the start button in one block, top to bottom — as on «Обрыв на 16 КБ»
    panel(null, null,
      h('div', { class: 'frow dns-own' }, h('span', { class: 'lbl', text: 'Свои серверы' }),
        h('div', { class: 'stack', style: 'gap:6px' }, h('div', { class: 'row' }, ownIn, newTag('dns:own')),
          h('span', { class: 'sm muted', text: 'Необязательно, через запятую, до пяти. Адрес — проверяется обычный DNS (порт 53), имя — DoT, ссылка https://… — DoH. Проверяются вместе с публичными и стоят в таблице первыми; список запоминается.' }),
          h('label', { class: 'chk' }, onlyIn, 'Только свои — без публичных серверов', newTag('dns:only')))),
      h('div', { class: 'row' }, startBtn, newTag('dns:btn'), h('span', { class: 'sm muted grow note-wide', text: 'Настройки DNS роутера страница не меняет — только показывает и советует.' }))),
    out);
  let q = {}, x = {}, sort = { key: 'best', dir: 1 }, all = false;
  const busy = () => !!(q.running || x.running);
  async function start() {
    const own = ownIn.value.split(/[\s,;]+/).map((v) => v.trim()).filter(Boolean);
    const s = await guarded(() => api('dns_start', { own, only: onlyIn.checked }));
    if (!s) return;
    q = s; x = await api('dnsx_get').catch(() => ({})); all = false; sort = { key: 'best', dir: 1 };
    draw(); poll();
  }
  function poll() {
    pollTest(async () => {
      if (!out.isConnected) return false;
      const a = await api('dns_get').catch(() => null);
      const b = await api('dnsx_get').catch(() => null);
      if (a) q = a;
      if (b) x = b;
      draw();
      return busy();
    }, 2000);
  }

  // ---- the extended table: times, failures, who answered, stubs ----
  const okMs = dnsxOkMs;
  const udpMs = (r) => r.udp?.ok && !r.udp.intercept ? r.udp.ms : null;   // an intercepted answer is not this server's
  // DoT counts when it was a real query (r.dot.q — the router has openssl); otherwise only the connection was timed
  const bestMs = (r) => { const v = [okMs(r.doh), udpMs(r), r.dot?.q ? okMs(r.dot) : null].filter((m) => m != null); return v.length ? Math.min(...v) : okMs(r.dot); };
  const bestHow = (r) => { const m = bestMs(r); return m == null ? '' : m === okMs(r.doh) ? 'DoH' : m === udpMs(r) ? 'UDP 53' : 'DoT'; };
  const clean = (r) => !r.udp?.intercept && r.sub && r.sub.bad === 0 && bestMs(r) != null;   // answers itself, no stubs found
  const FAIL = { syn: ['SYN DROP', 'TCP не соединился — адрес закрыт'], tls: ['TLS DROP', 'TCP соединился, TLS оборван — режут по имени'],
    timeout: ['TIMEOUT', 'нет ответа'], bad: ['ошибка', 'сервер ответил не DNS-ответом или с ошибкой'], name: ['нет адреса', 'имя сервера не разрешилось'],
    cert: ['сертификат', 'сертификат не подходит к имени сервера'], slow: ['медленно', 'соединение есть, но ответа не было 5 секунд'] };
  // a measurement still to come: a small spinner in its own cell, the value lands in the same place
  const waiting = () => h('span', { class: 'spin xs', role: 'img', 'aria-label': 'проверяется' });
  const tcell = (r, wait) => wait ? waiting() : !r ? h('span', { class: 'muted', text: '—' }) : r.ok ? h('span', { class: 'num ok-t', text: r.ms + ' мс' })
    : h('span', { class: 'bad-t sm', text: (FAIL[r.why] || ['нет'])[0], title: (FAIL[r.why] || ['', ''])[1] });
  const whoCell = (r) => {
    if (r.wait?.udp) return waiting();
    const u = r.udp;
    if (!u) return h('span', { class: 'muted', text: '' });
    if (!u.ok) return h('span', { class: 'muted sm', text: 'нет ответа' });
    const nm = (u.as_name || '').split(/\s+/)[0];
    const why = { isp: 'ответил сервер провайдера', ripn: 'ответил национальный резолвер (RIPN), а не этот сервер', local: 'ответил DNS вашей сети' }[u.intercept];
    const t = `${u.who || '?'}${nm ? ' — ' + nm : ''}`;
    return h('span', { class: 'mono sm ' + (u.intercept ? 'bad-t' : 'muted'), title: why ? `${t}: ${why}` : t, text: t });
  };
  // stubs are measured over UDP 53. When someone else answered there (interception), the stubs are that one's, not
  // this server's: say «перехват» in grey instead of blaming the server
  const subCell = (r) => r.wait?.udp || r.wait?.sub ? waiting()
    : r.udp?.intercept ? h('span', { class: 'chip', text: 'перехват', title: `На порт 53 ответил не ${r.name}, а ${(r.udp.as_name || '').split(/\s+/)[0] || 'другой сервер'} — подменяет ли адреса сам ${r.name}, так не проверить. По DoH и DoT перехвата нет.` })
    : !r.sub ? chip('—', '') : r.sub.bad === 0 ? chip('нет', 'ok') : chip(`${r.sub.bad} из ${r.sub.of}`, r.sub.bad >= 2 ? 'bad' : 'warn');
  const KEYS = { name: (r) => r.name, doh: (r) => okMs(r.doh) ?? 1e9, dot: (r) => okMs(r.dot) ?? 1e9,
    udp: (r) => udpMs(r) ?? (r.udp?.ok ? 5e5 + r.udp.ms : 1e9),
    sub: (r) => r.udp?.intercept ? 50 : r.sub ? r.sub.bad : 99,   // intercepted: after the measured ones
    best: (r) => (clean(r) ? 0 : 1e6) + (bestMs(r) ?? 9e5) };   // default: clean servers first, each group by its fastest way
  const SHOW = 9;   // public servers shown before «Ещё N»
  // the router's and the provider's own DNS as table rows (from the quick check): stubs over the checked sites
  function ownRows() {
    const sites = q.sites || [];
    const subOf = (k) => { const s = sites.filter((v) => v[k] && v.ref.length); return s.length ? { bad: s.filter((v) => ['fake', 'none'].includes(v[k].st)).length, of: s.length } : null; };
    const sitesWait = !!q.running;   // stubs over the sites come at the end of the quick check
    const rows = (q.servers || []).filter((v) => v.kind !== 'public').map((v) => ({ name: v.name, ip: v.ip, own: true, doh: null, dot: null,
      udp: v.udp?.st === 'none' ? { ok: false, why: 'timeout' } : { ok: true, ms: v.udp?.ms, who: v.udp?.who, as_name: v.udp?.as_name },
      sub: subOf(v.kind), wait: sitesWait ? { sub: true } : null }));
    // the router's own DNS is asked first: until it answers, its row waits
    return rows.length || !q.running ? rows : [{ name: 'DNS роутера', ip: '', own: true, doh: null, dot: null, udp: null, sub: null, wait: { udp: true } }];
  }
  function table() {
    // while the check runs the rows keep the list order (a row does not jump as its numbers come), unless the reader
    // sorted by a column; when it ends — the best first
    const ordered = !busy() || sort.user;
    // own servers (r.mine) stay on top of the public ones whatever the sort
    const pub = ordered ? [...(x.servers || [])].sort((a, b) => { const k = KEYS[sort.key]; const va = k(a), vb = k(b); return !!b.mine - !!a.mine || (typeof va === 'string' ? va.localeCompare(vb) : va - vb) * sort.dir || a.name.localeCompare(b.name); }) : [...(x.servers || [])];
    const top = busy() ? null : pub.find(clean);
    const shown = all ? pub : pub.slice(0, SHOW);
    const th = (key, text, cls = '') => h('th', { class: cls, 'aria-sort': ordered && sort.key === key ? (sort.dir > 0 ? 'ascending' : 'descending') : null },
      h('button', { type: 'button', class: 'th-sort', onclick: () => { sort = { key, dir: sort.key === key ? -sort.dir : 1, user: true }; draw(); } }, text, ordered && sort.key === key ? icon(sort.dir > 0 ? 'up' : 'down') : null));
    const row = (r) => h('tr', { class: r.own ? 'own' : r === top ? 'best' : null, 'data-key': (r.own ? 'own:' : '') + r.name },
      h('td', { class: 'nm', text: r.name, title: r.ip || null }), h('td', { class: 'mono sm hide-narrow', text: r.ip || '—' }),
      h('td', { class: 'num t-doh', 'data-h': 'DoH' }, tcell(r.doh, r.wait?.doh)),
      h('td', { class: 'num t-dot', 'data-h': 'DoT', title: r.dot?.ok && !r.dot.q ? 'время соединения: запрос по DoT без программы openssl на роутере не отправить' : null }, tcell(r.dot, r.wait?.dot)),
      h('td', { class: 'num t-udp', 'data-h': 'UDP' }, tcell(r.udp, r.wait?.udp)),
      h('td', { class: 'who' }, whoCell(r)), h('td', { class: 'sub' }, subCell(r)));
    return [h('div', { class: 'scroll', 'data-key': 'tbl' }, h('table', { class: 'tbl dnsx-tbl' },
      h('thead', {}, h('tr', {}, th('name', 'Сервер'), h('th', { class: 'hide-narrow', text: 'Адрес' }), th('doh', 'DoH', 'num'), th('dot', 'DoT', 'num'), th('udp', 'UDP', 'num'),
        h('th', { text: 'Кто ответил на UDP 53' }), th('sub', 'Подмена (UDP 53)'))),
      h('tbody', {}, ownRows().map(row),
        pub.length ? h('tr', { class: 'sep', 'data-key': 'sep' }, h('td', { colspan: 7, text: !ordered ? 'Публичные — в порядке проверки; когда она закончится, сверху встанут лучшие'
          : sort.key === 'best' ? 'Публичные — сверху те, что без перехвата и подмены, по скорости' : 'Публичные' })) : null,
        shown.map(row)))),
      pub.length > SHOW ? h('div', { class: 'row', 'data-key': 'more' }, btn(all ? 'Свернуть' : `Ещё ${plural(pub.length - SHOW, 'сервер', 'сервера', 'серверов')}`, () => { all = !all; draw(); }, 'small', all ? 'up' : 'down')) : null];
  }

  const who = (u) => u.asn ? `${u.as_name || 'сеть'} (AS${u.asn})` : '';
  // the fastest working DoT/DoH of the quick check's five servers: the advice when the extended check did not run
  const bestOf = (s) => s.servers.filter((v) => v.kind === 'public').flatMap((v) => [['DoT', v, v.dot], ['DoH', v, v.doh]])
    .filter(([, , r]) => r?.ok).map(([p, v, r]) => ({ p, x: v, ms: r.ms })).sort((a, b) => a.ms - b.ms)[0];

  function tiles(s) {
    s = { servers: [], sites: [], ...s };
    const pub = s.servers.filter((x) => x.kind === 'public');
    const router = s.servers.find((x) => x.kind === 'router');
    const sites = s.sites || [];
    const fake = sites.filter((x) => x.router.st === 'fake');
    const silent = sites.filter((x) => x.router.st === 'none');
    const t1 = !sites.length ? null : fake.length ? ['warn', `Подменяет ${plural(fake.length, 'сайт', 'сайта', 'сайтов')} из ${sites.length}`, fake.map((x) => x.host).join(', ') + ' — вместо адреса сайта заглушка.']
      : silent.length ? ['warn', `Не отвечает на ${plural(silent.length, 'сайт', 'сайта', 'сайтов')}`, silent.map((x) => x.host).join(', ')]
        : ['ok', 'Отдаёт настоящие адреса', router?.udp?.asn ? 'Дальше спрашивает ' + who(router.udp) + '.' : ''];
    const ic = pub.filter((x) => x.udp?.st === 'intercepted');
    const none = pub.filter((x) => x.udp?.st === 'none');
    const answered = [...new Set(ic.map((x) => x.udp.why === 'isp' ? 'сервер провайдера' : x.udp.why === 'local' ? 'DNS вашей сети' : who(x.udp)))];
    const byIsp = ic.length && ic.every((x) => x.udp.why === 'isp');
    const t2 = !pub.length ? null : ic.length ? ['bad', byIsp ? 'Перехвачен провайдером' : 'Перехвачен', `Спрашивали ${ic.map((x) => x.name).join(', ')} — ответил ${answered.join(', ')}.`]
      : none.length === pub.length ? ['bad', 'Не проходит', 'Ни один сервер не ответил на порт 53.']
        : none.length ? ['warn', 'Отвечают не все', `Нет ответа от ${none.map((x) => x.name).join(', ')}.`] : ['ok', 'Проходит', 'Отвечают те серверы, которых спрашивали.'];
    const sec = pub.flatMap((x) => [['DoT', x, x.dot], ['DoH', x, x.doh]]).filter(([, , r]) => r);
    const okSec = sec.filter(([, , r]) => r.ok);
    const closed = sec.filter(([, , r]) => !r.ok).map(([p, x]) => `${p} к ${x.name}`);
    // with the extended check done, the secure-DNS tile counts all its servers
    const xs = (x.servers || []).filter((r) => r.dot || r.doh);
    const xOk = xs.filter((r) => r.dot?.ok || r.doh?.ok);
    const xClosed = xs.filter((r) => !(r.dot?.ok || r.doh?.ok)).map((r) => r.name);
    const t3 = xs.length && !x.running ? (!xOk.length ? ['bad', 'Не проходит', 'Ни DoT, ни DoH не соединились ни с одним сервером.']
      : ['ok', `Работает у ${xOk.length} из ${xs.length}`, !xClosed.length ? 'DoT и DoH проходят ко всем.'
        : 'Закрыты: ' + xClosed.slice(0, 2).join(', ') + (xClosed.length > 2 ? ` и ещё ${xClosed.length - 2}` : '') + '.'])
      : !sec.length ? null : !okSec.length ? ['bad', 'Не проходит', 'Ни DoT, ни DoH не соединились.']
      : ['ok', `Работает: ${okSec.length} из ${sec.length}`, !closed.length ? 'DoT и DoH проходят.'
        : closed.length === 1 ? `DoT и DoH проходят; закрыт только ${closed[0]}.` : 'Закрыто: ' + closed.join(', ') + '.'];
    // a tile whose answer is still being checked keeps its place: the label and a spinner instead of the verdict
    const tile = (k, t, wait) => wait ? h('div', { class: 'dns-tile wait', 'data-key': k }, h('span', { class: 'k sm muted', text: k }), h('b', { class: 'v' }, waiting(), h('span', { class: 'muted', text: 'проверяю…' })))
      : t && h('div', { class: 'dns-tile ' + t[0], 'data-key': k }, h('span', { class: 'k sm muted', text: k }), h('b', { class: 'v', text: t[1] }), t[2] ? h('span', { class: 'd sm muted', text: t[2] }) : null);
    return h('div', { class: 'dns-tiles', 'data-key': 'tiles' }, tile('DNS роутера', t1, !!q.running), tile('Обычный DNS, порт 53', t2, !!q.running), tile('Защищённый DNS', t3, !!x.running));
  }

  // One piece of advice. The router gets real addresses — nothing to change (plain DNS intercepted does not matter, the
  // router already asks over a secure channel), with the fastest servers named in one line. It gets stubs — what to set,
  // the three best clean servers from the extended check (or the fastest secure one from the quick check)
  function advice() {
    const sites = q.sites || [];
    const fake = sites.filter((v) => v.router.st === 'fake');
    const silent = sites.filter((v) => v.router.st === 'none');
    const best = (x.servers || []).filter(clean).sort((a, b) => bestMs(a) - bestMs(b)).slice(0, 3);
    const quick = bestOf({ servers: q.servers || [] });
    const names = best.map((r) => h('b', { text: r.name })).flatMap((b, i) => [i ? ', ' : '', b, ` (${bestMs(best[i])} мс)`]);
    if (!sites.length) return null;
    if (!fake.length && silent.length) {
      return notice('warn', 'DNS роутера отвечает не на все сайты', `На ${silent.map((v) => v.host).join(', ')} он не вернул адреса. Если эти сайты нужны — проверьте их в «Диагнозе блокировки»: бывает, что DNS провайдера молчит вместо заглушки.`);
    }
    if (!fake.length) {
      return h('section', { class: 'panel dns-adv ok' }, h('div', { class: 'panel-h' }, h('h2', { text: 'Менять ничего не нужно' })),
        h('p', {}, 'Роутер получает настоящие адреса.',
          (q.servers || []).some((v) => v.udp?.st === 'intercepted') ? ' Обычный DNS к чужим серверам перехвачен, но роутер уже спрашивает по защищённому каналу.' : '',
          best.length ? [' Если когда-нибудь понадобится сменить DNS, быстрее всех у вас: ', ...names, '.'] : ''));
    }
    const how = h('p', { class: 'sm muted' }, 'Как поставить: OpenWrt — Сеть → DHCP и DNS; Keenetic — Интернет → DNS. Подробно — ',
      h('a', { href: wikiDns(), target: '_blank', rel: 'noopener', text: 'в справке, раздел «DNS»' }), '. Интерфейс DNS роутера не меняет.');
    if (!best.length && !quick) {
      return notice('warn', 'Защищённый DNS не проходит', 'Провайдер закрыл и DoT, и DoH к проверенным серверам — сменить DNS на роутере не получится.');
    }
    return h('section', { class: 'panel dns-adv bad' }, h('div', { class: 'panel-h' }, h('h2', { text: 'Что делать' })),
      h('p', { text: 'DNS роутера на часть сайтов отдаёт заглушку — никакая стратегия nfqws2 это не исправит. Поставьте в роутере защищённый DNS: быстрее всех у вас отвечают эти, без перехвата и подмены.' }),
      best.length ? h('div', { class: 'dnsx-best' }, best.map((r, i) => h('div', { class: 'dnsx-tile' + (i ? '' : ' top') },
        h('b', { text: r.name }), h('span', { class: 'big num', text: bestMs(r) + ' мс' }), h('span', { class: 'sm muted', text: `${bestHow(r)} · ${r.ip || 'по имени'}` }))))
        : h('p', {}, 'Быстрее всех ответил ', h('b', { text: `${quick.x.name} по ${quick.p}` }), ` (${quick.ms} мс).`),
      how);
  }

  // Sites: only those with a problem in view; the rest folded
  function sitesBlock() {
    const sites = q.sites || [];
    if (!sites.length) return null;
    const hasIsp = sites.some((v) => v.isp);
    const word = (r) => r ? (DNS_SITE_TEXT[r.st] || ['?'])[0] : null;
    // a problem is a stub or no answer; «другой адрес» is normal for big sites and podkop (each DNS gets its own address)
    const bad = sites.filter((v) => ['fake', 'none'].includes(v.router.st));
    const line = (v) => {
      const st = v.router.st;
      const [t, cls] = st === 'fake' ? ['подменён', 'bad'] : DNS_SITE_TEXT[st] || ['?', ''];
      const ips = (v.router.ips || []).join(', ');
      return h('div', { class: 'dns-site' }, h('span', { class: 'mono', text: v.host }), chip(t, cls),
        ['fake', 'none'].includes(st) ? h('div', { class: 'sm muted', text: [`DNS роутера: ${ips || word(v.router)}`, hasIsp && v.isp ? `провайдер: ${word(v.isp)}` : null,
          v.ref.length ? `настоящий: ${v.ref.join(', ')}` : 'защищённый DNS не ответил'].filter(Boolean).join(' · ') }) : null);
    };
    const ok = sites.filter((v) => !bad.includes(v));
    const podkopNote = sites.some((v) => v.router.st === 'podkop') ? h('p', { class: 'sm muted', text: 'podkop — адреса 198.18.x выдаёт podkop: такие сайты идут через него, так и задумано.' }) : null;
    if (!bad.length) {
      return h('details', { class: 'panel dns-more-panel' }, h('summary', { text: `Сайты через DNS роутера — ${sites.length} проверено, подмены нет` }),
        h('div', { class: 'dns-sites' }, sites.map(line)), podkopNote);
    }
    return panel(bad.some((v) => v.router.st === 'fake') ? 'Подменённые сайты' : 'Сайты с проблемами', h('span', { class: 'sm muted', text: `${bad.length} из ${sites.length} проверенных` }),
      h('div', { class: 'dns-sites' }, bad.map(line)),
      ok.length ? h('details', { class: 'dns-more' }, h('summary', { text: `Остальные ${plural(ok.length, 'сайт', 'сайта', 'сайтов')} — без подмены` }), h('div', { class: 'dns-sites' }, ok.map(line))) : null,
      podkopNote);
  }

  // while the check runs: where it is, in the head of the «Серверы» block, with its own «Остановить»
  function progress() {
    const text = q.running ? (q.step === 'sites' ? 'Свои DNS проверены, сверяю адреса сайтов…' : 'Спрашиваю свои DNS…')
      : x.queued ? 'Начинаю проверку публичных серверов…'
      : x.step === 'tls' ? 'Обычный DNS проверен у всех, проверяю DoT и DoH…'
      : `Обычный DNS: ${x.done || 0} из ${x.total || 55}`;
    const pct = q.running ? 10 + 15 * ((q.servers || []).length / 7) : x.step === 'tls' ? 85 : 25 + 55 * ((x.done || 0) / (x.total || 55));
    return { head: [h('span', { class: 'sm muted dnsx-step' }, h('span', { class: 'spin xs' }), text), h('span', { class: 'grow' }), btn('Остановить', () => guarded(() => api('dnsx_stop')), 'small')],
      bar: h('div', { class: 'dnsx-prog', 'data-key': 'bar' }, h('i', { style: `width:${Math.round(pct)}%` })) };
  }
  // Plain DNS to other servers intercepted: the red in the table is about port 53, not about the servers — said once
  // above the table, so «Cloudflare — 2 из 5» does not read as Cloudflare faking addresses
  function interceptNote() {
    const ic = (x.servers || []).filter((r) => r.udp?.intercept);
    if (!ic.length) return null;
    const secure = ic.filter((r) => r.doh?.ok || r.dot?.ok).length;
    return h('p', { class: 'sm dns-ic', 'data-key': 'ic' }, h('b', { text: `Порт 53 перехвачен у ${plural(ic.length, 'сервера', 'серверов', 'серверов')}. ` }),
      'Красное в «Кто ответил» и «перехват» в «Подмене» — про обычный DNS: на вопрос к этим серверам отвечает другой, его заглушки и видны. Сами серверы тут ни при чём',
      secure ? `: по DoH и DoT ${secure === ic.length ? 'они' : secure + ' из них'} отвечают сами, без перехвата. Если ставить такой сервер в роутер — то по DoH или DoT, не обычным DNS.` : '.');
  }

  // the advice needs the whole result: until then its place is held
  const adviceWait = () => h('section', { class: 'panel dns-adv wait' }, h('div', { class: 'panel-h' }, h('h2', { class: 'muted', text: 'Совет' })),
    h('p', { class: 'muted', text: 'Появится, когда проверка закончится: что делать, если DNS роутера подменяет адреса, и какие серверы у вас самые быстрые.' }));

  function draw() {
    startBtn.disabled = busy();
    startBtn.querySelector('btn-l').textContent = busy() ? 'Проверяю…' : q.started ? 'Проверить снова' : 'Проверить';
    const fin = Math.max(q.finished || 0, x.finished || 0);
    when.textContent = !busy() && fin ? `Проверено ${fmtDate(fin)}, заняло ${Math.max(1, fin - (q.started || fin))} с. ` : '';
    // blocks are brought up to date in place (morph): numbers land in their cells, nothing blinks or jumps
    if (!q.started) {
      morphKids(out, [panel(null, null, h('p', { text: 'Проверка спрашивает DNS роутера, DNS провайдера и около 50 публичных серверов (Cloudflare, Google, Quad9, AdGuard, Яндекс, Comss.one, XboxDNS и другие) тремя способами: обычный DNS на порт 53, DoT и DoH. Смотрит, кто на самом деле отвечает на порт 53, и сверяет, не подменяет ли DNS роутера адреса закрытых сайтов.' }),
        h('p', { class: 'sm muted' }, 'Занимает 1–2 минуты, идёт в фоне. Нажмите «Проверить». ', newTag('dns:full')))]);
      return;
    }
    const died = q.died || x.died;
    const run = busy() ? progress() : null;
    const count = (x.servers || []).filter((r) => !r.mine).length;
    // every block has a key: one that comes or goes (a notice, the sites) does not shift the others onto each other
    const key = keyed;
    morphKids(out, [
      key(died ? notice('warn', 'Проверка прервалась', 'Запустите ещё раз.') : null, 'died'),
      key(!run && x.stopped ? notice('info', 'Проверка остановлена', 'Публичные серверы проверены не все.') : null, 'stopped'),
      tiles(q), key(run ? adviceWait() : advice(), 'advice'),
      key(panel('Серверы', run ? run.head : h('span', { class: 'sm muted', text: count ? `свои и ${count} публичных` : 'свои' }), run?.bar,
        interceptNote(), ...table(),
        h('div', { class: 'dnsx-legend sm muted', 'data-key': 'legend' }, h('span', {}, h('b', { class: 'bad-t', text: 'TLS DROP' }), ' — соединился, TLS оборван (режут по имени)'),
          h('span', {}, h('b', { class: 'bad-t', text: 'SYN DROP' }), ' — адрес закрыт'), h('span', {}, h('b', { class: 'bad-t', text: 'TIMEOUT' }), ' — нет ответа'),
          h('span', { text: 'красный в «Кто ответил» — на порт 53 ответил не тот, кого спрашивали (перехват)' }),
          h('span', { text: 'подмена — на сколько из пяти закрытых сайтов сервер по порту 53 отдаёт заглушку вместо адреса' }),
          h('span', { text: (x.servers || []).some((r) => r.dot?.q) ? 'время — с роутера, запрос по уже открытому соединению' : 'время — с роутера; у DoT — время соединения (нет openssl)' }),
          h('span', { text: 'подмена «—» — не проверялась' }))), 'servers'),
      key(q.running ? null : sitesBlock(), 'sites')]);
  }

  const [a0, b0] = [await api('dns_get').catch(() => ({})), await api('dnsx_get').catch(() => ({}))];
  q = a0; x = b0;
  ownIn.value = (a0.own_servers || []).join(', ');
  onlyIn.checked = !!a0.own_only;
  draw();
  if (busy()) poll();
}

// a time from a probe result, or null when the probe failed
const dnsxOkMs = (r) => r && r.ok ? r.ms : null;
