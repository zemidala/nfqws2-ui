'use strict';
// Interface: page: the 16 KB cut-off probe.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ Обрыв на 16 КБ: проба по сетям ============
// Где провайдер замораживает соединение после ~16–20 КБ и какое имя в фейке это снимает — для сети целиком.

async function viewFreeze(main) {
  const sites = h('input', { class: 'input mono grow', placeholder: 'king.hr, 203.0.113.5:8443 — через запятую, до трёх (необязательно)', autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'Свои сайты и адреса' });
  const out = h('div', { class: 'stack', style: 'gap:14px' }, skel(1, 4));
  const startBtn = btn('Запустить пробу', start, 'primary', 'play');
  main.append(
    h('div', { class: 'vh' }, h('h1', { text: 'Обрыв на 16 КБ' })),
    panel(null, null,
      h('p', { text: 'Многие провайдеры пропускают начало соединения с зарубежными хостингами (Hetzner, OVH, Linode и др.) и замораживают его после 16–20 КБ: сайт начинает открываться и зависает. Обычные стратегии тут не помогают. Помогает фейк с именем сайта, который провайдер не трогает, — и подходящее имя обычно одно для всей сети хостинга, а не для отдельного сайта.' }),
      h('p', { class: 'sm muted', text: 'Проба по очереди открывает опорные адреса в крупных сетях без обхода, а где есть обрыв — подбирает имя для фейка. Найденное ставится одним профилем на все адреса сети (список по ASN обновляется сам раз в сутки).' }),
      h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Свои сайты и адреса' }),
        h('div', { class: 'stack', style: 'gap:6px' }, h('div', { class: 'row' }, sites, newTag('frz:ip')),
          h('span', { class: 'sm muted', text: 'Адрес — IPv4, можно с портом (по умолчанию 443): например, свой VPS. Проба покажет, соединяется ли он вообще и замирает ли на объёме.' }))),
      h('div', { class: 'row' }, startBtn, h('span', { class: 'sm muted grow note-wide', text: 'Занимает 2–10 минут. Работает отдельный nfqws2 на проверочных соединениях роутера — ваш трафик не затрагивается.' }))),
    out);

  async function start() {
    const hosts = sites.value.split(/[\s,;]+/).map((x) => x.trim().replace(/^[a-z]+:\/\//i, '').replace(/[/?#].*$/, '')).filter(Boolean);
    if (!await guarded(() => api('freeze_start', { hosts }))) return;
    poll();
  }
  function poll() {
    pollTest(async () => {
      if (!out.isConnected) return false;
      const s = await api('freeze_get').catch(() => null);
      if (!s) return true;
      draw(s);
      return ['running', 'starting'].includes(s.state);
    }, 2000);
  }

  function verdict(n) {
    if (n.baseline?.ok) return [levelIcon('ok'), h('span', { text: 'обрыва нет' })];
    if (n.no_tcp) return [levelIcon('error'), h('span', {}, 'нет соединения с адресом', h('div', { class: 'sm muted', text: 'Закрыт по IP или не отвечает на этом порту — стратегия nfqws2 тут не поможет, нужен туннель.' }))];
    if (!n.freeze) return [levelIcon('info'), h('span', { class: 'muted', text: 'не проверить: ' + (n.baseline?.reason || 'нет ответа') })];
    const what = n.stall ? 'замирает ещё до ответа' : 'обрыв есть';
    // the probe goes WITHOUT bypass; «Диагноз» checks through nfqws2 — both can be true at once, say it
    const stallNote = n.stall ? h('div', { class: 'sm muted', text: 'Без обхода соединение с этим адресом открывается, но сервер не отвечает вовсе — провайдер глушит ещё раньше 16 КБ. Проба идёт мимо nfqws2, а «Диагноз» проверяет через ваш конфиг: если там сайт открывается, ваш профиль с этим уже справляется.' }) : null;
    if (n.found) return [levelIcon(inConfWorks(n) ? 'ok' : 'warning'), h('span', {}, what + ' · снимает имя ', h('b', { class: 'mono', text: n.found.name }), h('div', { class: 'sm muted', text: n.found.via ? `найдено на ${n.found.via} — та же сеть` : n.found.strategy + (n.found.ms ? `, ${n.found.ms} мс` : '') }), inConfNote(n), stallNote)];
    return [levelIcon('error'), h('span', {}, n.tried?.length ? `${what} · не снялся (проверено ${plural(n.tried.length, 'вариант', 'варианта', 'вариантов')})` : what, stallNote)];
  }

  // Найденная стратегия уже в конфиге: сервер сверяет при каждом чтении итога (конфиг мог поменяться после пробы)
  const profLabel = (k) => { const p = prof(k); return `#${k}` + (p ? ` «${profName(p)}»` : ''); };
  const inConfWorks = (n) => !!n.in_conf?.route && n.in_conf.profiles.includes(n.in_conf.route);
  function inConfNote(n) {
    const c = n.in_conf;
    if (!c?.profiles?.length) return null;
    if (inConfWorks(n)) return h('div', { class: 'sm' }, `Уже работает: адреса сети идут через профиль ${profLabel(c.route)} с этой стратегией`, newTag('frz:inconf'));
    if (c.join) return h('div', { class: 'sm' }, `Эта стратегия уже есть в профиле ${profLabel(c.join)} — сеть можно добавить в него, без нового профиля: в конфиг допишется одна строка`);
    const list = c.profiles.map(profLabel).join(', ');
    return h('div', { class: 'sm' }, `Эта стратегия уже есть в ${c.profiles.length > 1 ? 'профилях' : 'профиле'} ${list}, но адреса сети `
      + (c.route ? `перехватывает профиль ${profLabel(c.route)}` : 'туда не попадают') + (c.why ? ` (${c.why})` : ''), newTag('frz:inconf'));
  }

  function draw(s) {
    const running = ['running', 'starting'].includes(s.state);
    startBtn.disabled = running;
    // brought up to date in place (morph): rows fill in while the probe runs, nothing blinks
    if (s.state === 'idle') { morphKids(out, [keyed(panel(null, null, h('p', { class: 'muted', text: 'Проба ещё не запускалась.' })), 'idle')]); return; }
    if (s.state === 'error') { morphKids(out, [keyed(notice('bad', 'Проба не удалась', s.error || ''), 'error')]); return; }
    const nets = s.nets || [];
    // итог — по сетям (AS), а не по адресам: у одной сети бывает несколько опорных адресов
    const byAs = (list) => [...new Map(list.map((n) => [n.asn || n.host, n])).values()];
    const frozen = byAs(nets.filter((n) => n.freeze));
    const found = frozen.filter((n) => byAs(nets).length && nets.some((x) => (x.asn || x.host) === (n.asn || n.host) && x.found));
    const total = byAs(nets).length;
    const working = found.filter((n) => nets.some((x) => (x.asn || x.host) === (n.asn || n.host) && inConfWorks(x))).length;
    const foundText = !found.length ? 'Ни одно имя обрыв не сняло.'
      : `Для ${found.length} из них имя нашлось` + (working === found.length ? ', и эта стратегия уже работает в вашем конфиге — применять ничего не нужно.'
        : ' — его можно применить для всей сети.' + (working ? ` В ${plural(working, 'сети', 'сетях', 'сетях')} эта стратегия уже работает в вашем конфиге.` : ''));
    morphKids(out, [
      keyed(running ? h('section', { class: 'panel' }, h('div', { class: 'row' }, h('span', { class: 'spin' }), h('b', { text: s.phase === 'names' ? 'Подбираю имена для сетей с обрывом' : 'Проверяю сети без обхода' }), h('span', { class: 'grow' }),
        h('span', { class: 'sm muted num', text: s.total ? `${s.done} из ${s.total}` : 'запуск…' }), btn('Остановить', () => api('test_stop'), 'small danger', 'stop')),
        s.current ? h('p', { class: 'sm muted', text: 'Сейчас: ' + s.current }) : null) : null, 'run'),
      keyed(!running && nets.length ? (frozen.length
        ? notice(found.length === frozen.length ? 'ok' : 'warn', `Обрыв есть в ${plural(frozen.length, 'сети', 'сетях', 'сетях')} из ${total}`,
          foundText
          + (found.length < frozen.length ? ' Где не снялось: опорные адреса самой компании-хостинга провайдер иногда режет строже, чем сайты её клиентов, — впишите в «Свои сайты» сайт, который живёт в этой сети и не открывается у вас, или запустите на нём подбор с уточнением.' : ''))
        : notice('ok', 'Обрыва на 16 КБ не видно', 'Ни одна из проверенных сетей не замораживает соединение — значит, у вашего провайдера сейчас этого ограничения нет (или оно касается других сетей).')) : null, 'verdict'),
      keyed(!running && s.state === 'time' ? notice('warn', 'Проба остановлена по времени', 'На подбор имён отведено 10 минут; ниже — что успели проверить.') : null, 'time'),
      keyed(nets.length ? panel('Сети', h('span', { class: 'sm muted', text: s.finished ? fmtDate(s.finished) : '' }), h('div', { class: 'frz' },
        nets.map((n) => h('div', { class: 'frz-row', 'data-key': n.host + ':' + (n.pad?.port ?? 443) },
          h('div', {}, h('div', { text: n.label }), h('div', { class: 'sm muted mono', text: n.host })),
          h('div', { class: 'mono sm', text: n.asn ? 'AS' + n.asn : '—' }),
          h('div', { class: 'row', style: 'gap:6px;flex-wrap:nowrap;align-items:flex-start' }, ...verdict(n)),
          // кнопки — один раз на сеть: у адреса, где имя нашлось
          // own address on another port: «apply for the network» makes a TLS-on-443 profile — it would not cover it
          h('div', { class: 'row frz-act' }, n.found && n.asn && !n.found.via && (n.pad?.port ?? 443) !== 443 ? h('span', { class: 'sm muted', text: `Найдено для порта ${n.pad.port}: профилем на сеть ставится только 443 — добавьте порт в профиль вручную` }) : n.found && n.asn && !n.found.via ? [
            inConfWorks(n) || !n.in_conf?.join ? null : [btn(`В профиль #${n.in_conf.join}`, () => joinNet(n), 'small primary', 'ok', { title: `Список адресов AS${n.asn} — строкой --ipset в профиль ${profLabel(n.in_conf.join)} с той же стратегией, без нового профиля` }), newTag('frz:join')],
            inConfWorks(n) ? null : btn(n.in_conf?.join ? 'Отдельным профилем' : 'Применить для сети', () => applyNet(n), n.in_conf?.join ? 'small' : 'small primary', n.in_conf?.join ? null : 'ok', { title: `Список адресов AS${n.asn} и профиль с найденным именем — для всех сайтов этой сети` }),
            shareBtn({ steps: n.found.steps, ok: 3, tries: 3, name: n.found.strategy }, { host: '', proto: 'https', asn: n.asn })] : null))))) : null, 'nets')]);
  }

  // Профиль на всю сеть: список адресов по ASN (обновляется сам) + найденная стратегия, первым, с перезапуском и проверкой
  async function applyNet(n) {
    if (!await ask(`Применить для всей сети AS${n.asn} (${n.label})?\n\nБудет создан список адресов этой сети (по данным RIPEstat, обновляется раз в сутки) и профиль первым в «своих профилях»: TLS на 443 к адресам сети — стратегия ${n.found.strategy} с именем ${n.found.name}.\n\nnfqws2 перезапустится с проверкой: если сайты мониторинга перестанут открываться или вы не подтвердите за 3 минуты, всё вернётся как было.`, 'Применить')) return;
    toast('Получаю адреса сети…');
    const r = await guarded(() => api('asn_create', { asn: n.asn, auto: true }));
    if (!r) return;
    await loadConf();
    const cur = S.conf.vars.NFQWS_ARGS_CUSTOM.trim() ? splitParts(tokensOf(S.conf.vars.NFQWS_ARGS_CUSTOM)) : [];
    const part = ['--filter-tcp=443', '--filter-l7=tls', `--ipset=${r.path}`, '--payload=tls_client_hello', ...n.found.steps];
    if (!await saveVars({ NFQWS_ARGS_CUSTOM: joinParts([part, ...cur]) }, `профиль для сети AS${n.asn}: обрыв на 16 КБ`)) return;
    await safeRestart();
    go('#/settings/p1');
  }

  // Та же стратегия уже есть в профиле, который отбирает по IP-спискам, — сеть добавляется в него одной строкой --ipset
  async function joinNet(n) {
    const k = n.in_conf.join;
    if (!await ask(`Добавить сеть AS${n.asn} (${n.label}) в профиль ${profLabel(k)}?\n\nБудет создан список адресов этой сети (по данным RIPEstat, обновляется раз в сутки), а в профиль добавится строка --ipset с этим списком. Стратегия профиля та же, что нашла проба, — нового профиля не будет.\n\nnfqws2 перезапустится с проверкой: если сайты мониторинга перестанут открываться или вы не подтвердите за 3 минуты, всё вернётся как было.`, 'Добавить')) return;
    toast('Получаю адреса сети…');
    const r = await guarded(() => api('freeze_join', { asn: n.asn, profile: k }));
    if (!r) return;
    await loadConf();
    await safeRestart();
    go('#/settings/p' + k);
  }

  poll();
}
