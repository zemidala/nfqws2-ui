'use strict';

const VOICE_PROFILE = 'Discord-голос';
const VOICE_PROFILE_TCP = 'Discord-голос-TCP';
const VOICE_UDP = [[19294, 19344], [50000, 65535]];

async function viewVoice(main, r) {
  const serverIn = h('input', { class: 'input mono grow', value: r.q.get('server') || '', placeholder: '104.29.136.182:19296', autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'Сервер голоса' });
  const ownTa = h('textarea', { class: 'input mono', rows: 3, style: 'width:100%;resize:vertical', spellcheck: 'false', autocapitalize: 'off', 'aria-label': 'Свои стратегии',
    placeholder: '--lua-desync=fake:blob=quic_initial:repeats=6' });
  const ownOut = h('div', { class: 'stack items' });
  const ownDet = h('details', { class: 'own-st' }, h('summary', {}, 'Своя стратегия из чата'), ownTa,
    h('span', { class: 'sm muted', text: 'Вставляйте как есть, каждая строка — отдельная стратегия (до 10). Они проверяются первыми, если голос режут и конфиг не помогает.' }), ownOut);
  const serverDet = h('details', { class: 'own-st', open: !!r.q.get('server') }, h('summary', {}, 'Сервер для проверки'), h('div', { class: 'row' }, serverIn),
    h('span', { class: 'sm muted', text: 'Голосовой сервер Discord, который отвечает без звонка. Можно вписать адрес из «Соединений устройства», записанных во время звонка.' }));
  const box = h('div', { class: 'stack' });
  main.append(
    h('div', { class: 'vh' }, h('h1', { text: 'Голос Discord' })),
    h('p', { class: 'muted dns-lead', text: 'Для случая, когда в голосовом канале через 10–20 секунд пинг 5000 мс и отключение или висит «Подключение к RTC». Обычно это провайдер режет голосовой поток. Роутер проверит голос сам, без звонка, и, если нужно, подберёт настройку nfqws2. Это эксперимент: проверяется начало голосового соединения, в звонке всё может быть иначе.' }),
    h('section', { class: 'panel' }, h('div', { class: 'panel-h' }, h('h2', { text: 'Проверка голоса' })), box, ownDet, serverDet));

  const fmtS = (ms) => String(Math.round(ms / 100) / 10).replace('.', ',');
  const passText = (x) => `проходит: ${x.secs} с, ${plural(x.got, 'ответ', 'ответа', 'ответов')}`;
  const cutText = (x) => x.got === 0 ? 'нет ответа' : `замер через ${fmtS(x.froze_ms ?? 0)} с, после ${plural(x.got, 'ответа', 'ответов', 'ответов')}`;
  const covers = (ranges, port) => (ranges || []).some(([a, b]) => port >= a && port <= b);
  function voiceProf(s) {
    const port = Number(String(s.server || '').split(':')[1]) || 19296;
    return (S.state?.profiles || []).find((p) => !p.skip && covers(p.udp, port) && (!p.l7 || p.l7.some((x) => ['discord', 'stun'].includes(x)))
      && !p.hostlists.length && !p.ipsets.length && !p.hostlist_domains.length && !p.ipset_ips.length);
  }
  const best = (s) => (s.results || []).find((x) => x.ok && x.long?.ok);

  function rows(s, running) {
    const b = s.baseline, c = s.config;
    const row = (lv, t, text) => h('li', {}, lv === 'run' ? h('span', { class: 'spin' }) : levelIcon(lv), h('b', { text: t }), h('span', { class: 'r sm', text }));
    const p = voiceProf(s);
    const r1 = !b ? row(running ? 'run' : 'info', 'Без обхода', running ? 'проверяю…' : 'не проверялось')
      : b.ok ? row('ok', 'Без обхода', passText(b)) : row('error', 'Без обхода', b.got ? 'режет провайдер: ' + cutText(b) : b.reason || 'нет ответа');
    const r2 = !c ? row(running && b ? 'run' : 'info', 'Через ваш конфиг', running && b ? 'проверяю…' : 'не проверялось')
      : c.ok ? row('ok', 'Через ваш конфиг', passText(c) + (p ? `, профиль #${p.index} «${profName(p)}»` : '')) : row('error', 'Через ваш конфиг', (b?.ok ? 'ломает голос: ' : 'не проходит: ') + (c.reason && !c.got ? c.reason : cutText(c)));
    const x = best(s);
    const tried = (s.results || []).length;
    const done = s.state === 'done';
    const r3 = running && ['pick', 'confirm'].includes(s.phase) ? row('run', 'Настройка для голоса', `пробую ${s.current || '…'} — ${s.done} из ${s.total}`)
      : s.state === 'stopped' ? row('info', 'Настройка для голоса', tried ? `проверка остановлена после ${plural(tried, 'настройки', 'настроек', 'настроек')}` : 'не подбиралась')
      : s.state === 'error' ? row('error', 'Настройка для голоса', 'проверка прервалась')
      : done && s.udp_need === false || ['open', 'works'].includes(s.verdict) ? row('ok', 'Настройка для голоса', b?.ok ? 'не нужна' : 'не нужна — конфиг уже справляется')
      : x ? row('ok', 'Настройка для голоса', `${x.name} — поток шёл ${x.long.secs} с без обрыва`)
      : s.verdict === 'none' && tried ? row('error', 'Настройка для голоса', `ни одна из ${tried} не помогла`)
      : ['blocked', 'harm'].includes(s.verdict) ? row('info', 'Настройка для голоса', 'не подбиралась')
      : row('info', 'Настройка для голоса', 'подбирается, если голос режут');
    return h('ul', { class: 'vc-checks' }, r1, r2, r3, tcpRow(s, running, row));
  }

  function tcpRow(s, running, row) {
    const t = s.tcp, T = 'Подключение (TCP)';
    if (running && s.phase === 'tcp') return row('run', T, `пробую ${String(s.current || '…').replace(/^подключение \(TCP \d+\): /, '')} — ${s.done} из ${s.total}`);
    if (!t) return row(running ? 'run' : 'info', T, running ? 'проверяю…' : 'не проверялось');
    if (!t.ip) return row('info', T, 'не проверялось: discord.media идёт через podkop или не находится');
    const ports = Object.keys(t.ports || {}).map(Number);
    const baseClosed = ports.filter((p) => !t.ports[p].base);
    const list = (a) => a.join(', ');
    if (!t.closed?.length) {
      return row('ok', T, baseClosed.length ? `discord.media: ${list(baseClosed)} без обхода закрыт, через ваш конфиг открывается` : `discord.media: порты ${list(ports)} открыты и без обхода`);
    }
    const byConf = t.closed.filter((p) => t.ports[p].base), byIsp = t.closed.filter((p) => !t.ports[p].base);
    const head = 'discord.media: ' + [byIsp.length ? `${list(byIsp)} — закрыт без обхода и через конфиг` : null, byConf.length ? `${list(byConf)} — закрывает ваш конфиг` : null].filter(Boolean).join('; ');
    if (t.best) return row('ok', T, `${head}. Подобрано: ${t.best.name}`);
    if (t.results?.length) return row('error', T, `${head}. Ни одна из ${t.results.length} настроек не помогла`);
    return row('error', T, head);
  }

  function note(s) {
    const p = voiceProf(s);
    const t = {
      idle: 'Проверка займёт около 30 секунд. Если провайдер режет голос или подключение к голосовому серверу, роутер сам подберёт настройку — это ещё пара минут.',
      works: `Провайдер режет голосовой поток, но ваш конфиг его пропускает${p ? ` (профиль #${p.index} «${profName(p)}»)` : ''}. Если в звонке всё равно 5000 мс, напишите в группу: проверка видит только начало соединения.`,
      open: (p ? `Голос проходит и без обхода, и через ваш конфиг (профиль #${p.index} «${profName(p)}»).` : 'Без обхода голосовой поток идёт без обрыва, nfqws2 для него не нужен.') + ' Если в звонке проблемы, причина в другом: регион сервера, сеть устройства.',
      none: `Ни одна из ${(s.results || []).length} настроек не удержала голосовой поток. Провайдер, скорее всего, режет голос по адресу, а это настройкой nfqws2 не снять. Поможет только туннель: голос Discord через VPN (podkop на OpenWrt, WireGuard на Keenetic).`,
      blocked: 'Провайдер режет голосовой поток, и ваш конфиг его не пропускает. Подбор найдёт настройку, это около двух минут.',
      harm: 'Провайдер голос не режет, а через ваш конфиг поток замирает: стратегия UDP-профиля ему мешает. Подбор найдёт настройку, которая не мешает, это около двух минут.',
      found: s.harm ? 'Провайдер голос не режет — его ломает ваш конфиг. Профиль «Discord-голос» заберёт пакеты голоса себе с настройкой, которая проходит.'
        : s.udp_need === false ? 'Голосовой поток проходит, но закрыто подключение к голосовому серверу по TCP: из-за этого Discord висит на «Подключении к RTC».' : null,
      tcp_blocked: 'Голосовой поток проходит, но закрыто подключение к голосовому серверу по TCP: из-за этого Discord висит на «Подключении к RTC». Подбор найдёт настройку.',
      tcp_none: `Голосовой поток проходит, а подключение к голосовому серверу по TCP не открыла ни одна из ${(s.tcp?.results || []).length} настроек. Настройкой nfqws2 это не снять, поможет только туннель: Discord через VPN (podkop на OpenWrt, WireGuard на Keenetic).`,
      unreachable: 'Сервер не ответил ни разу — ни без обхода, ни через конфиг. Проверьте адрес в «Сервер для проверки» или повторите позже.',
      stopped: 'Проверка остановлена.',
      error: s.error || 'Проверка прервалась. Повторите; если не выходит — пришлите «Отчёт для помощи».',
    }[['stopped', 'idle', 'error'].includes(s.state) ? s.state : s.verdict];
    return t ? h('p', { class: 'sm', text: t }) : null;
  }

  function triedList(s) {
    const res = s.results || [];
    if (!res.length) return null;
    const item = (x) => {
      const ok = x.ok && (!x.long || x.long.ok);
      const text = x.long?.ok ? `проходит ${x.long.secs} с, ${x.long.got} ответов из ${x.long.sent}` : x.long ? `${x.secs} с проходит, в долгой проверке ${cutText(x.long)}`
        : x.ok ? `проходит ${x.secs} с, ${x.got} из ${x.sent}` : x.reason && !x.got ? x.reason : cutText(x);
      return h('li', { 'data-key': x.steps.join(' ') }, levelIcon(ok ? 'ok' : 'error'),
        h('span', { class: 'r' }, h('b', { text: x.name }), h('code', { class: 'sm muted', style: 'overflow-wrap:anywhere', text: x.steps.map((t) => t.replace('--lua-desync=', '')).join('  ') })),
        h('span', { class: 'r sm', text }));
    };
    return h('details', { class: 'own-st' }, h('summary', {}, `Что пробовали — ${plural(res.length, 'настройка', 'настройки', 'настроек')}`), h('ul', { class: 'vc-checks' }, res.map(item)));
  }

  function draw(s) {
    const running = ['running', 'starting'].includes(s.state);
    const x = best(s);
    const runBlock = running ? [h('div', { class: 'row' }, h('span', { class: 'spin' }), h('b', { text: 'Проверяю голос Discord' }), h('span', { class: 'grow' }),
      h('span', { class: 'sm muted num', text: s.total ? `${s.done} из ${s.total}` : 'запуск…' }), btn('Остановить', () => api('test_stop'), 'small danger', 'stop')),
      s.total ? h('div', { class: 'progress', style: 'height:6px;border-radius:99px;background:var(--soft);overflow:hidden' }, h('i', { style: `display:block;height:100%;width:${Math.round(s.done / s.total * 100)}%;background:var(--accent)` })) : null,
      h('p', { class: 'sm muted', text: `Сейчас: ${s.current || 'запуск'}. ${s.pick && ['pick', 'confirm', 'tcp'].includes(s.phase) ? 'Подбор занимает пару минут.' : 'Около 30 секунд.'}` })] : [];
    const canEnable = s.state === 'done' && ['found', 'none'].includes(s.verdict) && !!(x || s.tcp?.best);
    const acts = running ? null : h('div', { class: 'vc-act' },
      s.state === 'idle' ? btn('Проверить голос', () => start(true), 'primary', 'play')
        : canEnable ? [btn('Включить для голоса Discord', () => enable(x, s), 'primary', 'ok'), btn('Проверить ещё раз', () => start(true), '', 'refresh')]
        : ['blocked', 'harm', 'tcp_blocked'].includes(s.verdict) && s.state === 'done' ? [btn('Подобрать настройку', () => start(true), 'primary', 'play'), btn('Проверить ещё раз', () => start(false), '', 'refresh')]
        : btn('Проверить ещё раз', () => start(true), '', 'refresh'));
    const what = [x ? 'профиль «Discord-голос» первым: UDP 19294–19344 и 50000–65535, только пакеты голоса Discord' : null,
      s.tcp?.best ? `профиль «${VOICE_PROFILE_TCP}» для discord.media на портах ${s.tcp.closed.join(', ')}` : null].filter(Boolean);
    const profNote = !running && canEnable ? h('p', { class: 'sm muted', text: `Добавится ${what.join(', и ')}. Перед изменением делается снимок, nfqws2 перезапустится с проверкой и после этого голос проверится ещё раз.` }) : null;
    morphKids(box, [...runBlock.map((el, i) => keyed(el, 'run' + i)), keyed(rows(s, running), 'rows'), keyed(running ? null : note(s), 'note'),
      keyed(acts, 'acts'), keyed(profNote, 'prof'), keyed(running ? null : triedList(s), 'tried')].filter(Boolean));
  }

  async function ownStrategies() {
    if (!ownTa.value.trim()) { ownOut.replaceChildren(); return []; }
    ownDet.open = true;
    const items = parseStrategies(ownTa.value);
    if (!items.length) { ownOut.replaceChildren(notice('bad', 'В тексте не нашлось стратегий', 'Нужны шаги вида --lua-desync=функция:параметры. Очистите поле, если своя стратегия не нужна.')); return null; }
    const chk = await guarded(() => api('strategy_check', { items: items.slice(0, 10), proto: 'quic' }));
    if (!chk) return null;
    ownOut.replaceChildren(...chk.items.map((x, i) => x.errors.length ? notice('bad', `Стратегия ${i + 1} не годится`, x.errors.join('; ')) : null).filter(Boolean));
    if (chk.items.some((x) => x.errors.length)) { toast('Поправьте свои стратегии или уберите строки с ошибками', { err: true }); return null; }
    return chk.items.map((x) => x.steps);
  }

  async function start(pick) {
    const own = pick ? await ownStrategies() : [];
    if (!own) return;
    if (!await guarded(() => api('voice_start', { server: serverIn.value.trim(), pick, own }))) return;
    poll();
  }

  const portsAdd = (cur, want) => {
    const list = (cur || '').split(',').map((t) => t.trim()).filter(Boolean);
    const have = list.map((t) => t.split(/[:-]/).map(Number)).map(([a, b]) => [a, b || a]);
    const add = want.filter(([a, b]) => !have.some(([x1, x2]) => a >= x1 && b <= x2)).map(([a, b]) => a === b ? `${a}` : `${a}:${b}`);
    return add.length ? [...list, ...add].join(',') : null;
  };

  async function enable(x, s) {
    await loadConf();
    const v = S.conf.vars;
    const vars = {};
    const tcp = s.tcp?.best ? s.tcp : null;
    const parts = [x ? ['--name=' + VOICE_PROFILE, '--filter-udp=19294-19344,50000-65535', '--filter-l7=discord,stun', '--payload=discord_ip_discovery,stun', ...x.steps] : null,
      tcp ? ['--name=' + VOICE_PROFILE_TCP, '--filter-tcp=' + tcp.closed.join(','), '--filter-l7=tls', '--hostlist-domains=discord.media', '--payload=tls_client_hello', ...tcp.best.steps] : null].filter(Boolean);
    const names = parts.map((p) => p[0]);
    const cur = v.NFQWS_ARGS_CUSTOM.trim() ? splitParts(tokensOf(v.NFQWS_ARGS_CUSTOM)) : [];
    const rest = cur.filter((p) => !names.some((n) => p.includes(n)));
    vars.NFQWS_ARGS_CUSTOM = joinParts([...parts, ...rest]);
    const udpAdd = x ? portsAdd(v.UDP_PORTS, VOICE_UDP) : null;
    const tcpAdd = tcp ? portsAdd(v.TCP_PORTS, tcp.closed.map((p) => [p, p])) : null;
    if (udpAdd) vars.UDP_PORTS = udpAdd;
    if (tcpAdd) vars.TCP_PORTS = tcpAdd;
    const replaced = rest.length !== cur.length;
    const lines = [x ? `«${VOICE_PROFILE}» (${x.name}): только пакеты голоса Discord на UDP 19294–19344 и 50000–65535.` : null,
      tcp ? `«${VOICE_PROFILE_TCP}» (${tcp.best.name}): только discord.media на TCP ${tcp.closed.join(', ')}.` : null].filter(Boolean);
    if (!await ask(`${replaced ? 'Заменить' : 'Добавить'} ${parts.length > 1 ? 'профили' : 'профиль'} для голоса Discord?\n\n${lines.join('\n')} ${parts.length > 1 ? 'Они встанут первыми' : 'Он встанет первым'} в списке профилей.${udpAdd || tcpAdd ? ' Недостающие порты добавятся в UDP_PORTS и TCP_PORTS.' : ''}\n\nПосле сохранения nfqws2 перезапустится с проверкой: если сайты перестанут открываться или вы не подтвердите за 3 минуты, всё вернётся как было. Потом голос проверится ещё раз.`, replaced ? 'Заменить' : 'Включить')) return;
    if (x?.blob_host) {
      const b = await guarded(() => api('voice_blob', { host: x.blob_host }));
      if (!b) return;
      if (!tokensOf(v.NFQWS_BASE_ARGS).some((t) => t.startsWith(`--blob=${b.name}:`))) vars.NFQWS_BASE_ARGS = [...tokensOf(v.NFQWS_BASE_ARGS), b.decl].join('\n');
    }
    if (!await saveVars(vars, `голос Discord: ${[x?.name, tcp?.best.name].filter(Boolean).join(', ')}`)) return;
    if (!await safeRestart()) return;
    if (await guarded(() => api('voice_start', { server: serverIn.value.trim(), pick: false })) && box.isConnected) poll();
  }

  function poll() {
    pollTest(async () => {
      if (!box.isConnected) return false;
      const s = await api('voice_get').catch(() => null);
      if (!s) return true;
      draw(s);
      return ['running', 'starting'].includes(s.state);
    }, 1500);
  }

  const s0 = await api('voice_get').catch(() => ({ state: 'idle' }));
  if (!serverIn.value && s0.server && s0.server !== s0.default_server) serverIn.value = s0.server;
  draw(s0);
  if (['running', 'starting'].includes(s0.state)) poll();
}
