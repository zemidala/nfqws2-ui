'use strict';

const YT_PROFILE = 'YouTube-узлы';
const YT_LIST = 'youtube-nodes.list';
const YT_FIXED = ['www.youtube.com', 'redirector.googlevideo.com', 'i.ytimg.com'];

async function viewYoutube(main) {
  const box = h('div', { class: 'stack' });
  main.append(
    h('div', { class: 'vh' }, h('h1', { text: 'YouTube' })),
    h('p', { class: 'muted dns-lead', text: 'Для случая, когда YouTube не открывается, ролики не начинаются или долго грузятся в самом начале. Роутер проверит адреса YouTube сам: отвечают ли они без обхода и через ваш конфиг. Если нет, подберёт настройку nfqws2 и включит её профилем только на эти адреса. От равномерного замедления, когда видео идёт, но медленно, эта страница не поможет.' }),
    h('section', { class: 'panel' }, h('div', { class: 'panel-h' }, h('h2', { text: 'Проверка YouTube' })), box));

  const sec = (ms) => String(Math.round(ms / (ms < 1000 ? 10 : 100)) / (ms < 1000 ? 100 : 10)).replace('.', ',');
  const mb = (kb) => String(Math.round(kb / 1024 * 100) / 100).replace('.', ',');
  const ownProf = () => (S.state?.profiles || []).find((p) => p.name === YT_PROFILE && !p.skip);
  const fail = (list, key) => list.filter((t) => !t[key]?.ok);
  const reasonOf = (list, key) => { const r = [...new Set(list.map((t) => t[key]?.reason).filter(Boolean))]; return r.length === 1 ? ': ' + r[0] : ''; };

  const listOnly = (s) => !!s.best?.current && !!ownProf();

  function kind(s) {
    if (['running', 'starting'].includes(s.state)) return 'run';
    if (['idle', 'stopped', 'error'].includes(s.state)) return s.state;
    if (s.verdict === 'tunnel') return 'tunnel';
    if (s.foreign && !['open', 'unresolved'].includes(s.verdict)) return 'foreign';
    if (s.verdict === 'found' && s.best) return s.best.slow ? 'slow' : s.harm ? 'harm' : 'found';
    if (['blocked', 'harm', 'found'].includes(s.verdict)) return s.harm || s.verdict === 'harm' ? 'harmb' : 'blocked';
    return s.verdict || 'idle';
  }

  function rows(s, k) {
    const t = s.targets || [], n = t.length;
    const row = (lv, title, text) => h('li', {}, lv === 'run' ? h('span', { class: 'spin' }) : levelIcon(lv), h('b', { text: title }), h('span', { class: 'r sm', text }));
    const fb = fail(t, 'base'), fc = fail(t, 'conf').filter((x) => x.tcp !== false);
    const unreach = t.filter((x) => x.tcp === false).length;
    const unreachTail = unreach ? `; ещё ${plural(unreach, 'адрес недоступен', 'адреса недоступны', 'адресов недоступны')} даже по TCP — их стратегия не откроет` : '';
    const prof = ownProf();
    const r1 = k === 'tunnel' ? row('info', 'Без обхода', 'не проверялось: YouTube идёт через туннель')
      : !n ? row(k === 'run' ? 'run' : 'info', 'Без обхода', k === 'run' ? 'проверяю…' : 'не проверялось')
      : !fb.length ? row('ok', 'Без обхода', `отвечают все ${plural(n, 'адрес', 'адреса', 'адресов')}`)
      : row('error', 'Без обхода', `не отвечают ${plural(fb.length, 'адрес', 'адреса', 'адресов')} из ${n}${reasonOf(fb, 'base')}`);
    const r2 = k === 'tunnel' ? row('info', 'Через ваш конфиг', 'не проверялось')
      : !n || !t[0].conf ? row(k === 'run' && n ? 'run' : 'info', 'Через ваш конфиг', k === 'run' && n ? 'проверяю…' : 'не проверялось')
      : !fc.length && !unreach ? row('ok', 'Через ваш конфиг', `отвечают все ${n}` + (prof ? `, профиль #${prof.index} «${YT_PROFILE}»` : ''))
      : !fc.length && unreach < n ? row('ok', 'Через ваш конфиг', `отвечают все доступные: ${n - unreach} из ${n}` + (prof ? `, профиль #${prof.index} «${YT_PROFILE}»` : '') + unreachTail)
      : fc.length + unreach < n ? row('warning', 'Через ваш конфиг', `отвечают ${n - fc.length - unreach} из ${n}` + unreachTail)
      : row('error', 'Через ваш конфиг', `не отвечают ${fc.length + unreach} из ${n}` + unreachTail);
    const tried = (s.results || []).length;
    const b = s.best;
    const r3 = k === 'run' && ['pick', 'confirm'].includes(s.phase) ? row('run', 'Настройка для YouTube', `пробую ${s.current || '…'} — ${s.done} из ${s.total}`)
      : k === 'found' && b.current ? row('ok', 'Настройка для YouTube', `${b.name} открывает и новые адреса — менять её не нужно`)
      : k === 'found' ? row('ok', 'Настройка для YouTube', `${b.name} — отвечают все ${plural((s.blocked || []).length, 'адрес', 'адреса', 'адресов')}, повторная проверка тоже`)
      : k === 'unreach' ? row('error', 'Настройка для YouTube', 'не поможет: адреса закрыты по IP')
      : k === 'slow' ? row('warning', 'Настройка для YouTube', `${b.name} — адреса отвечают, быстрых настроек не нашлось`)
      : k === 'harm' ? row('ok', 'Настройка для YouTube', `${b.name} — отвечают все ${plural((s.blocked || []).length, 'адрес', 'адреса', 'адресов')}`)
      : k === 'open' || k === 'tunnel' ? row('ok', 'Настройка для YouTube', 'не нужна')
      : k === 'works' ? row('ok', 'Настройка для YouTube', 'не нужна — конфиг уже справляется')
      : k === 'freeze' ? row('info', 'Настройка для YouTube', 'здесь не подбирается')
      : k === 'foreign' ? row('info', 'Настройка для YouTube', 'не подбирается: на роутере уже есть схема с yt_cdn_observe')
      : k === 'none' ? row('error', 'Настройка для YouTube', `ни одна из ${tried} не помогла`)
      : k === 'stopped' ? row('info', 'Настройка для YouTube', tried ? `проверка остановлена после ${plural(tried, 'настройки', 'настроек', 'настроек')}` : 'не подбиралась')
      : k === 'error' ? row('error', 'Настройка для YouTube', 'проверка прервалась')
      : k === 'blocked' || k === 'harmb' ? row('info', 'Настройка для YouTube', 'не подбиралась')
      : row('info', 'Настройка для YouTube', 'подбирается, если адреса не отвечают');
    const sp = (b && (b.confirm_speed || b.speed)) || s.speed?.conf;
    const via = b ? 'с этой настройкой' : 'через ваш конфиг';
    const r4 = sp?.freeze ? row('error', 'Страница YouTube', 'замирает после 16 КБ')
      : sp?.ok && sp.ms ? row(b?.slow ? 'warning' : 'ok', 'Страница YouTube', `${via} ${mb(sp.kb)} МБ за ${sec(sp.ms)} с${b?.slow ? ' — медленно' : ''}`)
      : sp && !sp.ok ? row('error', 'Страница YouTube', `${via}: ${sp.reason}`)
      : sp?.ok && sp.reason ? row('info', 'Страница YouTube', `${via}: ${sp.reason}`)
      : row('info', 'Страница YouTube', k === 'run' ? 'проверится с найденной настройкой' : 'не проверялась');
    return h('ul', { class: 'vc-checks' }, r1, r2, r3, r4);
  }

  function head(s, k) {
    const prof = ownProf();
    const un = (s.unreach || []).length;
    const unNote = un ? ` Ещё ${plural(un, 'адрес', 'адреса', 'адресов')} из записи недоступны даже по TCP — это узлы, которые закрыты по IP или не отдают видео вашей сети; видео пойдёт с остальных.` : '';
    const someConf = (s.targets || []).some((x) => x.conf?.ok);
    const t = {
      idle: ['YouTube ещё не проверялся', 'Проверка займёт около 30 секунд. Если адреса не отвечают, роутер сам подберёт настройку — это ещё одна-две минуты.'],
      found: listOnly(s) ? ['Новые адреса YouTube не входят в список профиля', `Настройка профиля «${YT_PROFILE}» их открывает, менять её не нужно: адреса только добавятся в список.${unNote}`]
        : [someConf ? 'Часть адресов YouTube не открывается' : 'YouTube сейчас не открывается', `Провайдер не пропускает приветствие TLS к ${someConf ? 'части адресов' : 'адресам'} YouTube, и ваш конфиг с этим не справляется. Нашлась настройка, с которой они отвечают и страница грузится быстро.${unNote}`],
      unreach: ['Адреса YouTube недоступны', 'До адресов YouTube не устанавливается даже соединение TCP — их закрывают по IP. nfqws2 меняет пакеты, а не маршрут, и тут не поможет: нужен туннель (podkop, VPN).'],
      slow: ['YouTube откроется, но медленно', 'Быстрые настройки не помогли. Сработала только wssize: она уменьшает окно TCP на всё соединение, и страница грузится в 15–20 раз дольше. Видео пойдёт, но может буферизоваться.' + unNote],
      works: ['YouTube открывается', `Провайдер не пропускает адреса YouTube напрямую, но ваш конфиг справляется${prof ? ` (профиль #${prof.index} «${YT_PROFILE}», в списке ${plural((s.listed || []).length, 'адрес', 'адреса', 'адресов')})` : ''}.${unNote}`],
      open: ['YouTube не блокируют', (un ? 'Доступные адреса YouTube отвечают и без обхода' : 'Адреса YouTube отвечают и без обхода') + ', nfqws2 для них не нужен. Если ролики всё равно тормозят, это замедление или сеть устройства — настройкой nfqws2 его обычно не снять.' + unNote],
      tunnel: ['YouTube идёт через туннель', 'DNS отдаёт для YouTube адреса podkop (198.18.x.x): трафик уходит в туннель, nfqws2 в нём не участвует. Если видео тормозит, дело в туннеле или его сервере.'],
      freeze: ['YouTube обрывается на 16 КБ', 'Адреса отвечают, но страница YouTube замирает после первых 16 КБ. Это обрыв на объёме: его снимает подбор имени на странице «Обрыв» для сети Google.'],
      harm: ['YouTube ломает ваш конфиг', `Провайдер адреса YouTube не трогает — их ломает ваш конфиг. Профиль «${YT_PROFILE}» заберёт эти адреса себе и пропустит их без обхода.${unNote}`],
      none: [someConf ? 'Часть адресов YouTube не открывается, и подобрать не вышло' : 'YouTube не открывается, и подобрать не вышло',
        `Ни одна из ${(s.results || []).length} настроек не открыла ${plural((s.blocked || []).length, 'адрес', 'адреса', 'адресов')}${s.harm ? ': без обхода они отвечают, а через ваш конфиг нет — мешает его стратегия. Уберите эти адреса из профиля, который их ловит. ' : ': соединение с ними устанавливается, а приветствие TLS режется. '}${someConf ? 'Остальные адреса отвечают через ваш конфиг, видео может идти с них. ' : ''}Напишите в группу — набор настроек будет пополняться.${unNote}`],
      blocked: ['YouTube сейчас не открывается', 'Адреса YouTube не отвечают, и ваш конфиг с этим не справляется. Подбор найдёт настройку, это одна-две минуты.'],
      harmb: ['YouTube ломает ваш конфиг', 'Провайдер адреса YouTube не трогает, а через ваш конфиг они не отвечают. Подбор найдёт настройку, которая не мешает, это одна-две минуты.'],
      foreign: ['На роутере уже стоит другая схема для YouTube', 'В конфиге есть yt_cdn_observe и профиль на youtube-cdn-ip.list. Свой профиль эта страница поверх не ставит: два профиля на одни адреса мешают друг другу. Проверка показывает, как YouTube работает сейчас, вместе с этой схемой.'],
      unresolved: ['Адреса YouTube не находятся', 'Ни одно имя YouTube не нашлось в DNS. Проверьте DNS роутера и повторите.'],
      stopped: ['Проверка остановлена', ''],
      error: ['Проверка прервалась', s.error || 'Повторите; если не выходит — пришлите «Отчёт для помощи».'],
    }[k];
    if (!t || k === 'run') return null;
    return h('p', { class: 'sm' }, h('b', { text: t[0] + '.' }), t[1] ? ' ' + t[1] : '');
  }

  function addrList(s) {
    const t = s.targets?.length ? s.targets
      : [...YT_FIXED.map((h0) => ({ host: h0, ip: '', from: 'fixed' })), ...(s.recorded?.nodes || [])];
    const from = (x) => ({ fixed: 'постоянный', record: 'из записи' + (x.stalled ? ', замер' : ''), list: 'из списка' }[x.from] || '') + (x.tcp === false ? ', недоступен по TCP' : '');
    const cell = (label, r) => {
      const [lv, text] = s.verdict === 'tunnel' ? ['info', 'через туннель'] : !r ? ['info', '—'] : r.ok ? ['ok', `отвечает, ${sec(r.ms)} с`] : ['error', 'нет ответа'];
      return h('span', { class: 'st sm', 'data-l': label + ': ' }, levelIcon(lv), h('span', { text }));
    };
    const rec = (s.recorded?.nodes || []).length;
    return h('details', { class: 'own-st' }, h('summary', {}, `Адреса — ${t.length}${rec ? `, из них ${rec} из записи соединений` : ''}`),
      h('ul', { class: 'yt-addr' }, h('li', { class: 'h' }, ...['Адрес', 'IP', 'Без обхода', 'Через конфиг'].map((x) => h('span', { text: x }))),
        t.map((x) => h('li', { 'data-key': x.ip || x.host }, h('span', { class: 'host' }, h('code', { text: x.host }), h('span', { class: 'sm muted', text: from(x) })),
          h('code', { class: 'ip sm muted', text: x.ip || '—' }), cell('Без обхода', x.base), cell('Через конфиг', x.conf)))));
  }

  function triedList(s) {
    const res = s.results || [];
    if (!res.length) return null;
    const item = (x) => {
      const lv = !x.ok ? 'error' : x.slow ? 'warning' : 'ok';
      const text = !x.ok ? x.reason || 'не помогла' : x.speed?.ms ? `все адреса отвечают, ${x.slow ? 'но ' : ''}страница ${mb(x.speed.kb)} МБ за ${sec(x.speed.ms)} с` : 'все адреса отвечают' + (x.speed?.reason ? `, ${x.speed.reason}` : '');
      return h('li', { 'data-key': x.steps.join(' ') }, levelIcon(lv),
        h('span', { class: 'r' }, h('b', { text: x.name }), h('code', { class: 'sm muted', style: 'overflow-wrap:anywhere', text: x.steps.filter((t) => t.startsWith('--lua-desync=')).map((t) => t.replace('--lua-desync=', '')).join('  ') })),
        h('span', { class: 'r sm', text }));
    };
    return h('details', { class: 'own-st' }, h('summary', {}, `Что пробовали — ${plural(res.length, 'настройка', 'настройки', 'настроек')}`), h('ul', { class: 'vc-checks' }, res.map(item)));
  }

  function draw(s) {
    const k = kind(s);
    const running = k === 'run';
    const runBlock = running ? [h('div', { class: 'row' }, h('span', { class: 'spin' }), h('b', { text: 'Проверяю YouTube' }), h('span', { class: 'grow' }),
      h('span', { class: 'sm muted num', text: s.total ? `${s.done} из ${s.total}` : 'запуск…' }), btn('Остановить', () => api('test_stop'), 'small danger', 'stop')),
      s.total ? h('div', { class: 'progress', style: 'height:6px;border-radius:99px;background:var(--soft);overflow:hidden' }, h('i', { style: `display:block;height:100%;width:${Math.round(s.done / s.total * 100)}%;background:var(--accent)` })) : null,
      h('p', { class: 'sm muted', text: `Сейчас: ${s.current || 'запуск'}. ${s.pick && ['pick', 'confirm'].includes(s.phase) ? 'Всего около двух минут.' : 'Около 30 секунд.'}` })] : [];
    const canEnable = ['found', 'slow', 'harm'].includes(k) && s.profile;
    const again = btn('Проверить ещё раз', () => start(true), '', 'refresh');
    const acts = running ? null : h('div', { class: 'vc-act' },
      k === 'idle' ? btn('Проверить YouTube', () => start(true), 'primary', 'play')
        : canEnable ? [k === 'slow' ? btn('Всё равно включить', () => enable(s), '', 'ok') : btn(listOnly(s) ? 'Добавить адреса в список' : 'Включить для YouTube', () => enable(s), 'primary', 'ok'), again]
        : k === 'freeze' ? [btn('Подобрать на странице «Обрыв»', () => { location.hash = PAGES.frz[0]; }, 'primary', 'play'), again]
        : k === 'blocked' || k === 'harmb' ? [btn('Подобрать настройку', () => start(true), 'primary', 'play'), btn('Проверить ещё раз', () => start(false), '', 'refresh')]
        : k === 'works' && ownProf() ? [again, btn('Убрать профиль', () => remove(), 'danger', 'undo')]
        : again);
    const profNote = canEnable && listOnly(s) ? h('p', { class: 'sm muted', text: `Настройка профиля «${YT_PROFILE}» не меняется, в списке ${YT_LIST} станет ${plural(s.profile.ips.length, 'адрес', 'адреса', 'адресов')}. nfqws2 перезапустится с проверкой, и адреса проверятся ещё раз.` })
      : canEnable ? h('p', { class: 'sm muted', text: `Добавится профиль «${YT_PROFILE}» первым: TCP 443, только адреса из списка ${YT_LIST} (сейчас ${s.profile.ips.length}). Перед изменением делается снимок, nfqws2 перезапустится с проверкой, и адреса проверятся ещё раз. QUIC профиль не касается: если браузер ходит к YouTube по QUIC и ваш конфиг его не обрабатывает, ролики пойдут мимо профиля.` }) : null;
    const hint = !running && k !== 'tunnel' && !(s.recorded?.nodes || []).length
      ? [notice('info', 'Узлов видео из записи нет', 'Проверены только постоянные адреса YouTube. Чтобы проверить серверы, с которых идёт видео: «Соединения устройства» → устройство, с которого смотрите YouTube → «Записать» и смотрите ролик, пока идёт запись. Потом вернитесь сюда и нажмите «Проверить ещё раз»: узлы из записи проверятся вместе с остальными.'),
        h('div', { class: 'vc-act' }, btn('Открыть «Соединения устройства»', () => { location.hash = PAGES.conns[0]; }, '', 'pulse'))] : [];
    const checked = new Set((s.targets || []).map((t) => t.ip));
    const fresh = (s.recorded?.nodes || []).filter((n) => !checked.has(n.ip));
    const freshNote = !running && s.targets?.length && fresh.length
      ? [notice('info', `В записи ${plural(fresh.length, 'узел', 'узла', 'узлов')} видео, ещё не проверены`, 'Проверка возьмёт их вместе с постоянными адресами YouTube.'),
        h('div', { class: 'vc-act' }, btn('Проверить узлы', () => start(true), '', 'refresh'))] : [];
    morphKids(box, [...runBlock.map((el, i) => keyed(el, 'run' + i)), keyed(rows(s, k), 'rows'), keyed(running ? null : head(s, k), 'head'),
      ...freshNote.map((el, i) => keyed(el, 'fresh' + i)), keyed(acts, 'acts'), keyed(profNote, 'prof'), ...hint.map((el, i) => keyed(el, 'hint' + i)), keyed(addrList(s), 'addr'), keyed(running ? null : triedList(s), 'tried')].filter(Boolean));
  }

  async function start(pick) {
    if (!await guarded(() => api('yt_start', { pick }))) return;
    poll();
  }

  async function enable(s) {
    await loadConf();
    const cur = S.conf.vars.NFQWS_ARGS_CUSTOM.trim() ? splitParts(tokensOf(S.conf.vars.NFQWS_ARGS_CUSTOM)) : [];
    const rest = cur.filter((p) => !p.includes('--name=' + YT_PROFILE));
    const replaced = rest.length !== cur.length;
    const only = listOnly(s) && replaced;
    if (!await ask(only ? `Обновить список адресов профиля «${YT_PROFILE}»?\n\nНастройка профиля не меняется. В списке ${YT_LIST} станет ${plural(s.profile.ips.length, 'адрес', 'адреса', 'адресов')}: новые из проверки и прежние, которые отвечают.\n\nПосле сохранения nfqws2 перезапустится с проверкой: если сайты перестанут открываться или вы не подтвердите за 3 минуты, всё вернётся как было.` : `${replaced ? 'Заменить' : 'Добавить'} профиль для YouTube?\n\n«${YT_PROFILE}» (${s.best.name}): TCP 443, только ${plural(s.profile.ips.length, 'адрес', 'адреса', 'адресов')} из списка ${YT_LIST}. Он встанет первым в списке профилей.\n\nПосле сохранения nfqws2 перезапустится с проверкой: если сайты перестанут открываться или вы не подтвердите за 3 минуты, всё вернётся как было. Потом адреса проверятся ещё раз.`, only ? 'Обновить список' : replaced ? 'Заменить' : 'Включить')) return;
    await api('list_create', { name: YT_LIST }).catch(() => null);
    if (!await guarded(() => api('list_save', { name: YT_LIST, content: s.profile.ips.map((ip) => ip + '/32').join('\n') + '\n', note: 'адреса YouTube' }))) return;
    if (!only && !await saveVars({ NFQWS_ARGS_CUSTOM: joinParts([s.profile.tokens, ...rest]) }, `YouTube: ${s.best.name}`)) return;
    if (!await safeRestart()) return;
    if (await guarded(() => api('yt_start', { pick: false })) && box.isConnected) poll();
  }

  async function remove() {
    await loadConf();
    const cur = splitParts(tokensOf(S.conf.vars.NFQWS_ARGS_CUSTOM));
    const rest = cur.filter((p) => !p.includes('--name=' + YT_PROFILE));
    if (rest.length === cur.length) return;
    if (!await ask(`Убрать профиль «${YT_PROFILE}»?\n\nАдреса YouTube снова пойдут по вашим обычным профилям. nfqws2 перезапустится с проверкой.`, 'Убрать', 'danger')) return;
    if (!await saveVars({ NFQWS_ARGS_CUSTOM: joinParts(rest) }, 'YouTube: профиль убран')) return;
    if (!await safeRestart()) return;
    if (await guarded(() => api('yt_start', { pick: false })) && box.isConnected) poll();
  }

  function poll() {
    pollTest(async () => {
      if (!box.isConnected) return false;
      const s = await api('yt_get').catch(() => null);
      if (!s) return true;
      draw(s);
      return ['running', 'starting'].includes(s.state);
    }, 1500);
  }

  const s0 = await api('yt_get').catch(() => ({ state: 'idle' }));
  draw(s0);
  if (['running', 'starting'].includes(s0.state)) poll();
}
