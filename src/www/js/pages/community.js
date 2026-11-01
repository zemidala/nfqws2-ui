'use strict';
// Interface: page: community strategies.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ Стратегии сообщества: что сработало у абонентов того же провайдера ============
// База — отдельный репозиторий на GitHub. Поделиться — заполненная форма issue (токен на роутере не нужен);
// присланное проверяет GitHub Action. Из базы ничего не применяется без проверки подбором на этом роутере.

// Открыла сайт каждый раз (у записи истории — tries нет, там только такие)
const pickFull = (x) => x.ok > 0 && x.ok === (x.tries ?? x.ok);

// «Поделиться» у стратегии, открывшей сайт каждый раз; у взятой из базы — «Подтвердить» или «Не сработала»
function shareBtn(x, s, labelled = false) {
  if (s.proto === 'quic') return null;   // база сообщества пока принимает только TLS и HTTP
  const mode = !x.community ? 'share' : pickFull(x) ? 'confirm' : 'fail';
  const [text, ic, title] = {
    share: ['Поделиться', 'share', 'Отправить стратегию в базу сообщества: её будут пробовать абоненты вашего провайдера'],
    confirm: ['Подтвердить', 'ok', 'Стратегия из базы сообщества сработала и у вас — подтвердите, это поднимет её для других'],
    fail: ['Не сработала', 'bad', 'Стратегия из базы сообщества у вас не открыла сайт — отметьте, роутеры будут пробовать её позже других'],
  }[mode];
  const shown = labelled || mode !== 'share';
  return btn(shown ? text : '', () => shareStrategy(x, s), 'small' + (shown && mode !== 'fail' ? '' : ' ghost'), ic, { title, 'aria-label': text });
}

// Что подбор взял из базы сообщества — одна строка под результатами; база пуста, а найдено рабочее — приглашение поделиться
function communityNote(s, best) {
  const c = s.community;
  if (!c || (s.state && ['running', 'starting'].includes(s.state))) return null;
  if (c.error) return h('p', { class: 'sm muted' }, 'Стратегии сообщества не проверялись: ' + c.error + '. ', h('a', { href: PAGES.comm[0], text: 'Подробнее' }));
  if (c.count) return null;
  if (best) {
    return h('div', { class: 'notice info' }, icon('share'), h('div', { class: 'grow' }, h('b', { text: `Для AS${c.asn} в базе сообщества стратегий пока нет` }),
      h('div', { class: 't', text: 'Поделитесь найденной — её будут пробовать первой абоненты вашего провайдера. Откроется заполненная форма на GitHub, отправляете вы сами.' })),
      h('div', { class: 'notice-actions' }, shareBtn(best, s, true)));
  }
  return h('p', { class: 'sm muted' }, `Для вашего провайдера (AS${c.asn}) в базе сообщества стратегий пока нет. `, h('a', { href: PAGES.comm[0], text: 'Как поделиться своей' }));
}

// Окно «Поделиться»: что уйдёт в публичный issue — видно целиком; отправляет сам человек на GitHub
async function shareStrategy(x, s) {
  const proto = s.proto === 'http' ? 'http' : 'tls';
  const fail = !!x.community && !pickFull(x);
  const body = h('div', { class: 'stack modal-b' }, spinner('Определяю провайдера и сеть сайта…'));
  const send = btn('Открыть форму на GitHub', null, 'primary', 'share', { disabled: true });
  const bg = modal(fail ? 'Стратегия не сработала' : x.community ? 'Подтвердить стратегию' : 'Поделиться стратегией', body, send);
  const info = await api('share_info', { host: s.host || '' }).catch((e) => ({ error: e.message }));
  if (s.asn) info.target_asn = s.asn;   // находка для целой сети, без сайта
  const asn = h('input', { class: 'input mono', value: info.asn || '', placeholder: '12389', style: 'max-width:120px', 'aria-label': 'AS провайдера' });
  const prov = h('input', { class: 'input grow', value: info.provider || '', placeholder: 'название провайдера', 'aria-label': 'Провайдер' });
  const withHost = h('input', { type: 'checkbox', checked: true });
  const pre = h('pre', { class: 'box', style: 'white-space:pre-wrap;word-break:break-all' });
  const tries = fail ? Math.max(x.tries || 1, x.ok + 1) : x.ok;
  const result = `${x.ok}/${tries}`;
  const fields = () => ({
    outcome: fail ? 'fails' : 'works', asn: asn.value.trim().replace(/^AS/i, ''), provider: prov.value.trim(), proto, target_host: withHost.checked ? s.host : '',
    target_asn: info.target_asn ? String(info.target_asn) : '', strategy: x.steps.join('\n'), result, nfqws2: info.nfqws2 || '', ui: info.ui || '',
  });
  const draw = () => {
    const f = fields();
    pre.textContent = [`AS провайдера: ${f.asn || '—'}${f.provider ? ' · ' + f.provider : ''}`, `Протокол: ${proto}`,
      `Сайт: ${f.target_host || 'не указан'}${f.target_asn ? ' · сеть AS' + f.target_asn : ''}`, `Результат: открылся ${x.ok} из ${tries}${fail ? ' — не сработала' : ''}`, '', f.strategy].join('\n');
    send.disabled = !/^\d{1,10}$/.test(f.asn);
  };
  [asn, prov].forEach((e) => e.addEventListener('input', draw));
  withHost.addEventListener('change', draw);
  send.onclick = () => {
    const f = fields();
    // AS и название провайдера запоминаем: в следующий раз и в подборе они уже будут
    if (f.asn !== String(info.asn || '') || f.provider !== (info.provider || '')) api('provider_set', { provider: f.provider, asn: f.asn }).then((r) => { if (S.state?.ui) S.state.ui.provider = r.provider; }).catch(() => {});
    const what = [`AS${f.asn}`, proto, f.target_host || strategyLabel(x)].join(' · ');
    const q = new URLSearchParams({ template: 'strategy.yml', title: (fail ? 'Не сработала · ' : x.community ? 'Подтверждаю · ' : '') + what, ...f });
    window.open(`${info.repo}/issues/new?${q}`, '_blank', 'noopener');
    bg.close();
  };
  body.replaceChildren(
    info.error ? notice('warn', 'Не всё удалось определить', info.error) : null,
    h('div', { class: 'row' }, h('span', { class: 'sm muted', text: 'Провайдер' }), asn, prov),
    !info.asn ? h('p', { class: 'sm muted', text: 'Номер AS провайдера определить не удалось — впишите его (узнать можно на bgp.he.net по своему адресу).' }) : null,
    s.host ? h('label', { class: 'chk' }, withHost, `указать сайт ${s.host}`) : null,
    pre,
    h('p', { class: 'sm faint' }, 'Откроется форма в ', h('a', { href: info.repo || '#', target: '_blank', rel: 'noopener', text: 'базе стратегий' }),
      ' на GitHub, уже заполненная, — проверьте и нажмите Create (нужен аккаунт GitHub). Issue публичный: в нём только то, что выше. Ваш IP-адрес не отправляется. ',
      fail ? 'Если стратегия не сработала у многих, роутеры будут пробовать её последней, а потом и вовсе перестанут. Если позже она у вас заработает — «Подтвердить» отменит эту отметку.'
        : x.community ? 'Подтверждение поднимает стратегию для других абонентов вашего провайдера.' : 'Стратегию проверит GitHub Action и добавит в базу; nfqws2-ui у абонентов вашего провайдера будет пробовать её при подборе.'));
  draw();
}

const strategyLabel = (x) => x.name || x.steps.map((t) => t.replace('--lua-desync=', '').split(':')[0]).join(' + ');

async function viewCommunity(main) {
  const site = h('input', { class: 'input mono grow', placeholder: 'сайт для проверки, например rutracker.org', autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'Сайт для проверки', style: 'max-width:320px' });
  const body = h('div', { class: 'stack', style: 'gap:14px' }, spinner('Загружаю базу стратегий…'));
  main.append(h('div', { class: 'vh' }, h('h1', { text: 'Стратегии сообщества' })), body);
  const host = () => site.value.trim().replace(/^[a-z]+:\/\//i, '').replace(/[/?#].*$/, '');
  // проверка — обычным подбором, только по выбранному: отдельный nfqws2, трафик не затрагивается
  const test = async (steps, h0) => {
    const hh = host() || h0;
    if (!hh) { toast('Укажите сайт для проверки', { err: true }); site.focus(); return; }
    if (!await guarded(() => api('test_start', steps ? { host: hh, sets: [], steps, repeats: 3 } : { host: hh, sets: ['community'], repeats: 3 }))) return;
    go(pickHref(hh));
  };
  const load = async (opts = {}) => {
    const d = await api('community_get', opts).catch((e) => ({ error: e.message, items: [] }));
    const http = d.items.filter((x) => x.proto === 'http');
    const tls = d.items.filter((x) => x.proto === 'tls');
    const table = (items) => h('div', { class: 'scroll' }, h('table', { class: 'tbl' },
      h('thead', {}, h('tr', {}, h('th', { text: 'Стратегия' }), h('th', { class: 'wide', text: 'Где работала' }), h('th', { class: 'num', text: 'Подтвердили' }), h('th', { class: 'num', text: 'Не сработала' }), h('th', { text: 'Последний раз' }), h('th'))),
      h('tbody', {}, items.map((x) => h('tr', { class: x.skipped ? 'dim' : null },
        h('td', { style: 'min-width:150px' }, h('div', {}, x.name, x.skipped ? [' ', chip('подбор не пробует', 'bad')] : !x.fresh ? [' ', chip('давно не подтверждалась', 'warn')] : null), h('code', { class: 'sm muted', style: 'word-break:break-all', text: x.steps.map((t) => t.replace('--lua-desync=', '')).join('  ') })),
        h('td', { class: 'sm' }, x.targets.length ? x.targets.slice(0, 6).map((t) => h('div', { class: 'mono', text: [t.host, t.asn ? 'AS' + t.asn : null].filter(Boolean).join(' · ') })) : h('span', { class: 'muted', text: 'сайт не указан' }),
          x.targets.length > 6 ? h('div', { class: 'muted', text: `и ещё ${x.targets.length - 6}` }) : null),
        h('td', { class: 'num', text: x.reports }),
        h('td', { class: 'num', text: x.fails || '—', title: x.fails ? 'последний раз ' + x.last_fail : null }),
        h('td', { class: 'date', text: x.last || '—' }),
        h('td', {}, h('div', { class: 'row acts-row' },
          btn('Проверить', () => test(x.steps, x.targets.find((t) => t.host)?.host), 'small', 'play', { title: 'Прогнать эту стратегию на сайте из поля выше (или на сайте, где она работала)' }),
          btn('', () => copyText(x.steps.join('\n')), 'small ghost', 'copy', { title: 'Скопировать', 'aria-label': 'Скопировать' }))))))));
    const head = !d.asn
      ? notice('warn', 'Провайдер не определён', 'Стратегии в базе разложены по провайдерам (номер AS). Роутер может узнать его у RIPEstat по вашему внешнему адресу — сам адрес никуда не сохраняется.',
        btn('Определить', () => load({ detect: true }), 'small', 'search'))
      : h('div', { class: 'row' }, h('b', { text: `AS${d.asn}` }), d.provider ? h('span', { class: 'muted', text: d.provider }) : null, h('span', { class: 'grow' }),
        h('span', { class: 'sm muted', text: d.fetched ? 'база загружена ' + fmtDate(d.fetched) : '' }),
        btn('Обновить', () => load({ force: true }), 'small', 'refresh'));
    const link = (href, text) => h('a', { href, target: '_blank', rel: 'noopener', text });
    const whole = d.page ? h('p', { class: 'sm muted' }, d.total ? `Во всей базе ${plural(d.total, 'стратегия', 'стратегии', 'стратегий')} для ${plural(d.providers || 0, 'провайдера', 'провайдеров', 'провайдеров')}. ` : '',
      d.mine ? [link(d.mine, `AS${d.asn} на GitHub`), ' · '] : null, link(d.page, 'все провайдеры')) : null;
    body.replaceChildren(
      panel(null, null, head,
        d.error ? notice('warn', 'База не загрузилась', d.error + ' — попробуйте позже.') : null,
        // проверять нечего — поле и кнопку не показываем: серая кнопка без объяснения выглядит как поломка
        d.asn && !d.error && d.items.length ? h('div', { class: 'row' }, site, btn('Проверить все на сайте', () => test(null), 'primary', 'play'),
          h('span', { class: 'sm muted grow note-wide', text: 'Подбор только по стратегиям из базы — до восьми, сначала открывавшие этот сайт или его сеть. Ваш трафик не затрагивается.' })) : null,
        whole),
      d.asn && !d.error && !d.items.length ? panel(null, null, h('p', { class: 'muted', text: `Для AS${d.asn} в базе пока ничего нет${d.total ? ` (всего в базе ${plural(d.total, 'стратегия', 'стратегии', 'стратегий')})` : ''}. Станьте первым: после удачного подбора нажмите «Поделиться».` })) : null,
      tls.length ? panel('HTTPS (TLS)', h('span', { class: 'sm muted num', text: plural(tls.length, 'стратегия', 'стратегии', 'стратегий') }), table(tls)) : null,
      http.length ? panel('HTTP', h('span', { class: 'sm muted num', text: plural(http.length, 'стратегия', 'стратегии', 'стратегий') }), table(http)) : null,
      panel('Как это устроено', null, h('ul', { class: 'sm', style: 'margin:0;padding-left:18px;display:grid;gap:4px' },
        h('li', { text: 'DPI у провайдеров разный, поэтому стратегии собраны по номеру AS провайдера: здесь только те, что сработали у абонентов вашего.' }),
        h('li', { text: 'Подбор пробует их вместе с остальными (галочка «стратегии сообщества») — и автоподбор при поломке тоже. Применяется только то, что открыло сайт на вашем роутере.' }),
        h('li', { text: 'Поделиться: после подбора у стратегии, открывшей сайт каждый раз, — кнопка «Поделиться» (в результатах и в «Истории подборов»). У стратегии из базы, которая сработала и у вас, — «Подтвердить», которая не сработала, — «Не сработала».' }),
        h('li', { text: 'Порядок: сначала подтверждённые за последние 60 дней; среди них — открывавшие тот же сайт, затем ту же сеть, затем по числу «подтвердили» за вычетом «не сработала». Стратегию, которая не сработала у большинства (и хотя бы у двоих), подбор не пробует.' }),
        h('li', {}, 'База — открытый репозиторий ', h('a', { href: d.repo, target: '_blank', rel: 'noopener', text: 'zemidala/nfqws2-strategies' }), '. Присланное проверяет GitHub Action: принимаются только шаги --lua-desync без путей к файлам.'))));
  };
  await load();
}
