'use strict';
// Interface: page: blocking diagnosis, the site card.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ Диагноз блокировки ============
// Семь ступеней на роутере (api: diag): имя → соединение → запрос мимо nfqws2 → по имени или по адресу →
// открытый HTTP → запрос через nfqws2 → QUIC. Итог — один диагноз и что с ним делать; QUIC — отдельной строкой:
// сайт с закрытым QUIC открывается (браузер переходит на обычное соединение), но с задержкой.

const DIAG_STEPS = [['dns', 'Имя сайта (DNS)'], ['tcp', 'Соединение (TCP 443)'], ['direct', 'Запрос мимо nfqws2'],
  ['sni', 'По имени или по адресу'], ['http', 'Открытый HTTP (порт 80)'], ['via', 'Как открывается сейчас'], ['quic', 'QUIC (HTTP/3, UDP 443)']];
const DIAG_KIND = { timeout: 'нет ответа при установке шифрования', reset: 'соединение сброшено', tls: 'обрыв при установке шифрования', alert: 'сервер отказал в соединении',
  stall: 'шифрование установлено, но ответа нет', freeze: 'загрузка замирает на объёме (16–20 КБ)', connect: 'не удалось подключиться', empty: 'пустой ответ', dns: 'имя не разрешается', other: 'ошибка соединения' };

// Диагноз: уровень плашки, заголовок, пояснение
// Строка ступени QUIC: уровень и текст
function quicStepText(q, qp) {
  if (q.direct.ok) return ['ok', `Отвечает без обхода за ${q.direct.ms} мс — QUIC не заблокирован.`];
  if (q.direct.reason !== 'нет ответа') return ['warn', `Проверить не удалось: ${q.direct.reason}.`];
  if (q.via?.ok) return ['ok', `Без обхода ответа нет — QUIC этого сайта режут. Через ${qp || 'nfqws2'} отвечает за ${q.via.ms} мс: nfqws2 справляется.`];
  if (q.h3 === false) return ['info', 'По QUIC не отвечает, и HTTP/3 сайт не объявляет — похоже, он работает только по обычному соединению. Это не блокировка.'];
  if (q.policy) return ['warn', 'Без обхода ответа нет. Через nfqws2 с роутера не проверить: включена политика доступа Keenetic, запросы самого роутера идут мимо nfqws2.'];
  if (!q.running) return ['warn', 'Без обхода ответа нет, а nfqws2 сейчас остановлен — через него не проверить.'];
  if (q.h3 === null) return ['warn', 'По QUIC не отвечает ни без обхода, ни через nfqws2; объявляет ли сайт HTTP/3, узнать не удалось.'];
  return ['bad', `Сайт объявляет HTTP/3, но по QUIC не отвечает ни без обхода, ни через nfqws2 (${qp ? qp + ' не помогает' : 'QUIC-профиля для него нет'}). Браузер ждёт ответа и только потом переходит на обычное соединение — страницы и видео стартуют с задержкой.`];
}

function diagVerdictText(R) {
  const v = R.verdict;
  const p = v.profile ? `профиль #${v.profile}` : null;
  const how = R.direct && !R.direct.ok ? DIAG_KIND[R.direct.kind] || 'не открывается' : 'не открывается';
  return {
    // checked from the router and for this one name: a device can still fail (other addresses of the service, QUIC,
    // TLS 1.2, its own DNS) — the verdict says what was checked, the block under it — what to check next
    ok: ['ok', 'С роутера сайт открывается', (p ? `Страница ${R.host || 'сайта'} открывается и мимо nfqws2, и через него (${p}).` : `Страница ${R.host || 'сайта'} открывается без обхода — nfqws2 её не обрабатывает.`)],
    fixed: ['ok', 'Заблокирован, но nfqws2 справляется', `Мимо nfqws2: ${how}. Через ${p || 'nfqws2'} страница сайта открывается с роутера.`],
    broken_by_nfqws: ['warn', 'Сайт не заблокирован — его ломает nfqws2', `Мимо nfqws2 сайт открывается, а через ${p || 'nfqws2'} — нет. Исключите сайт из этого профиля или подберите для него другую стратегию.`],
    nxdomain: ['warn', 'Имя сайта не находится', 'Такого имени нет ни у роутера, ни у защищённого DNS. Проверьте написание — подбор стратегии тут не поможет.'],
    dns_noanswer: ['bad', 'Роутер не получает адрес сайта', 'Защищённый DNS адрес знает, а резолвер роутера — нет. Дело в DNS, а не в nfqws2: стратегии и списки тут не помогут.'],
    dns_fake: ['bad', 'Роутер получает не тот адрес (подмена DNS)', 'По адресу от резолвера роутера сайт не открывается, а по адресу от защищённого DNS — открывается. Дело в DNS, а не в nfqws2.'],
    podkop_ok: ['info', 'Сайт идёт через podkop и открывается', 'Соединение уходит в туннель, nfqws2 его не видит.' + (R.direct ? (R.direct.ok ? ' Напрямую, мимо туннеля, сайт тоже открывается.' : ' Напрямую, мимо туннеля, сайт не открывается — туннель ему нужен.') : '')],
    podkop_fail: ['bad', 'Сайт идёт через podkop и не открывается', 'Соединение уходит в туннель, nfqws2 его не видит. Проблема на стороне туннеля или самого сайта.'],
    sni_block: ['bad', 'Блокировка по имени сайта (DPI)', 'Это тот случай, с которым справляется nfqws2. ' + (p ? `Нынешний ${p} не помогает — нужна другая стратегия.` : 'Сейчас nfqws2 этот сайт не обрабатывает.')],
    tls_block: ['bad', 'Соединение рвётся при установке шифрования', 'Похоже на блокировку DPI, но подтвердить, что режут именно по имени, не удалось. Подбор стратегии стоит попробовать.'],
    dns_other: ['warn', 'Закрыт адрес, который выдаёт ваш DNS', `Закрыт по IP только адрес, который получает роутер; адрес ${R.other?.ip} от DNS ${R.other?.from} отвечает` + (R.other?.open ? ', и сайт по нему открывается даже без обхода' : '') + `. У больших сетей разные DNS выдают разные адреса — дело в DNS, туннель не нужен. Смените DNS роутера (например, на ${R.other?.from}) или настройте для этого сайта отдельный DNS` + (R.other?.open ? '.' : ', а если сайт и тогда не откроется — подберите стратегию: блокировку по имени nfqws2 снимает.')],
    ip_block: ['bad', 'Заблокирован адрес (по IP)', 'Пакеты до сервера не доходят вовсе. nfqws2 меняет пакеты, а не маршрут, поэтому подбор стратегии не поможет — нужен туннель (podkop, VPN).'],
    port_block: ['bad', 'Не отвечает порт 443', 'Открытый HTTP на этом адресе отвечает, а HTTPS — нет. Стратегии nfqws2 это не исправят — нужен туннель (podkop, VPN).'],
    freeze: ['bad', 'Ограничение «16–20 КБ»', FREEZE_HINT],
    isp_page: ['bad', 'Вместо сайта — страница провайдера о блокировке', 'Провайдер подменяет ответ сайта. Подбор стратегии может помочь.'],
    site_error: ['warn', 'Сервер сайта отказал в соединении', 'Отказ пришёл от самого сервера, а не от провайдера — похоже на неполадку сайта.'],
    unknown: ['warn', 'Причину определить не удалось', 'Сайт не открывается, но ни под один известный вид блокировки это не подходит. Можно попробовать подбор стратегии.'],
  }[v.code] || ['warn', 'Причину определить не удалось', ''];
}

// The site opens from the router, but not on a device — what is worth checking next. The diagnosis checks one name over
// TLS 1.3 from the router itself: a service's video and pictures come from other names, a browser may go over QUIC,
// a TV may speak only TLS 1.2, a device may use its own DNS
function deviceNext(host, R) {
  const q = encodeURIComponent(host);
  const quicBad = R.quic?.blocked;
  return h('div', { class: 'notice info diag-next' }, icon('info'), h('div', { class: 'grow' },
    h('b', { text: 'Если на устройстве всё равно не открывается' }),
    h('ul', { class: 't sm' },
      h('li', {}, 'Сервис может брать видео и картинки с других адресов (у YouTube — googlevideo.com, ytimg.com). ', h('a', { href: '#/tests/conns', text: 'Соединения устройства' }), ' покажут, куда оно ходит и где нет ответа.'),
      h('li', {}, quicBad ? 'По QUIC (HTTP/3) сайт не отвечает — браузер ждёт и только потом уходит на обычное соединение. ' : 'Браузер мог пойти по QUIC (HTTP/3). ',
        h('a', { href: `#/tests?host=${q}&proto=quic`, text: 'Подбор для QUIC' }), '.'),
      h('li', {}, 'Смарт-ТВ и приставки часто умеют только TLS 1.2. ', h('a', { href: `#/tests?host=${q}&tls12=1`, text: 'Подбор как для старых устройств (TLS 1.2)' }), '.'),
      h('li', { text: 'У устройства может быть свой DNS (защищённый DNS в браузере) или IPv6 — тогда оно ходит другими адресами, чем роутер.' }))));
}

async function viewDiag(main, r, bare = false) {
  const KEY = 'nfqws-ui-recent';
  const recentGet = () => { try { return JSON.parse(localStorage.getItem(KEY)) || []; } catch { return []; } };
  const input = h('input', { class: 'input mono grow', id: 'd-host', value: r.q.get('host') || '', placeholder: 'rutracker.org', autocapitalize: 'off', autocorrect: 'off', spellcheck: 'false', inputmode: 'url', enterkeyhint: 'go', 'aria-label': 'Сайт для диагноза' });
  // «as old devices»: every request of the diagnosis over TLS 1.2, and «Подобрать стратегию» opens the pick the same way
  const tls12 = h('input', { type: 'checkbox', id: 'd-tls12', checked: r.q.get('tls12') === '1' });
  const tlsHref = (href, on) => on ? href + (href.includes('?') ? '&' : '?') + 'tls12=1' : href;
  const verdictBox = h('div', { class: 'stack' });
  const rows = {};
  const stepsEl = h('div', { class: 'dsteps' }, DIAG_STEPS.map(([id, title]) => (rows[id] = h('div', { class: 'dstep' }, h('span', { class: 'lvl' }), h('b', { text: title }), h('span', { class: 'sm muted' })))));
  const panelEl = panel('Что проверено', null, stepsEl);
  panelEl.hidden = true;
  const ICON = { ok: () => levelIcon('ok'), bad: () => levelIcon('error'), warn: () => levelIcon('warning'), info: () => levelIcon('info'), run: () => h('span', { class: 'spin' }), skip: () => h('span', { class: 'lvl faint', text: '–' }) };
  const set = (id, state, text) => { rows[id].firstChild.replaceWith(ICON[state]()); rows[id].lastChild.textContent = text; rows[id].classList.toggle('skip', state === 'skip'); };
  let seq = 0;

  async function run(raw) {
    const my = ++seq;
    let host = raw.trim().replace(/^[a-z]+:\/\//i, '').replace(/[/?#].*$/, '');
    if (!host) return;
    input.value = host;
    panelEl.hidden = false;
    verdictBox.replaceChildren();
    DIAG_STEPS.forEach(([id]) => set(id, 'skip', 'ожидает'));
    const R = { host };   // the name — for the verdict text («Страница … открывается»)
    const old = tls12.checked;
    panelEl.querySelector('.panel-h h2').textContent = old ? 'Что проверено — по TLS 1.2' : 'Что проверено';
    const step = async (id, data) => {
      set(id, 'run', 'проверяю…');
      const res = await api('diag', { step: id, host, tls12: old, ...data });
      if (my !== seq || !stepsEl.isConnected) throw new Error('cancelled');
      return res;
    };
    try {
      // 1. имя
      const d = R.dns = await step('dns');
      host = d.host;
      const names = d.doh.filter((x) => x.ok).map((x) => x.name).join(' и ') || 'защищённый DNS';
      const sys = d.system.slice(0, 3).join(', ');
      const ref = d.ref.slice(0, 3).join(', ');
      set('dns', { ok: 'ok', podkop: 'info', differs: 'warn', unverified: 'warn' }[d.status] || 'bad', {
        ok: `Адрес ${sys} — тот же, что отвечает защищённый DNS (${names}). Подмены нет.`,
        nxdomain: d.doh_answered ? 'Такого имени нет — ни у роутера, ни у защищённого DNS.' : 'Роутер адрес не нашёл, а защищённый DNS недоступен — сверить не с чем.',
        no_system: `Роутер адрес не получил, а защищённый DNS отвечает: ${ref}.`,
        podkop: `Роутер отдаёт служебный адрес podkop (${sys}): сайт уходит в туннель. Настоящий адрес — ${ref}.`,
        fake: `Роутер получил ${sys} — это не адрес сайта (часто так выглядит заглушка провайдера). Защищённый DNS отвечает: ${ref}. Включите в роутере DNS-over-HTTPS или DNS-over-TLS — иначе устройства сети получают тот же неверный адрес.`,
        differs: `Роутер получил ${sys}, защищённый DNS — ${ref}. У больших сетей ответы различаются; решит запрос к сайту.`,
        unverified: `Адрес ${sys}. Сверить не с чем: защищённый DNS ${d.doh_answered ? 'такого имени не знает' : 'недоступен'}.`,
      }[d.status]);
      if (d.status !== 'nxdomain') {
        // 2. соединение
        const tcp = R.tcp = (await step('tcp', { ips: d.ips })).results;
        const up = tcp.filter((x) => x.ok);
        const ip = up[0]?.ip;
        set('tcp', !up.length ? 'bad' : up.length < tcp.length ? 'warn' : 'ok', !up.length ? `Нет ответа ни от одного из ${plural(tcp.length, 'адреса', 'адресов', 'адресов')} — пакеты не доходят.`
          : `Установлено за ${up[0].ms} мс` + (up.length < tcp.length ? `; не отвечают: ${tcp.filter((x) => !x.ok).map((x) => x.ip).join(', ')}.` : ' — адрес не заблокирован.'));
        // закрыты все адреса от своего DNS — может быть, другой DNS выдаёт рабочий (у Meta, Google, CDN так бывает)
        if (!up.length && !d.podkop) {
          set('tcp', 'run', 'Нет ответа. Спрашиваю адреса у других DNS…');
          const o = R.other = await api('diag', { step: 'other', host, tls12: old, tried: [...new Set([...d.system, ...d.ref, ...d.ips])] });
          if (my !== seq || !stepsEl.isConnected) throw new Error('cancelled');
          set('tcp', o.ok ? 'warn' : 'bad', `Нет ответа ни от одного из ${plural(tcp.length, 'адреса', 'адресов', 'адресов')}. `
            + (o.ok ? `Но адрес ${o.ip} (его выдаёт DNS ${o.from}) отвечает` + (o.open ? ' — и сайт по нему открывается даже без обхода.' : '.')
              : o.checked.length ? `Другие DNS выдают ${o.checked.map((c) => c.ip).join(', ')} — не отвечают и они.` : 'Другие DNS выдают те же адреса.'));
        }
        // 3. запрос мимо nfqws2
        if (ip) {
          const dr = R.direct = await step('direct', { ip });
          if (!dr.ok && d.alt.length) {
            const alt = await step('direct', { ip: d.alt[0] });
            if (alt.ok) R.alt_ok = true;
          }
          set('direct', dr.isp_page ? 'bad' : dr.ok ? 'ok' : 'bad', dr.isp_page ? 'Вместо сайта пришла страница провайдера о блокировке.'
            : dr.ok ? `Сайт отвечает: HTTP ${dr.code}, ${dr.ms} мс.` : `Не открывается: ${DIAG_KIND[dr.kind] || dr.error}.` + (R.alt_ok ? ' По адресу от защищённого DNS — открывается.' : ''));
          // 4. по имени или по адресу
          if (!dr.ok && !dr.isp_page && ['timeout', 'reset', 'tls', 'alert', 'stall', 'empty'].includes(dr.kind) && !R.alt_ok) {
            const s = R.sni = await step('sni', { ip });
            set('sni', s.by_name ? 'ok' : 'warn', s.foreign_addr?.blocked ? 'Имя сайта рвётся даже на постороннем адресе — режут именно по имени.'
              : s.foreign_name?.reached ? 'С именем ya.ru этот же адрес отвечает — значит, режут именно по имени сайта.'
                : 'Не показательно: с чужим именем этот адрес тоже не отвечает.');
          } else set('sni', 'skip', dr.ok ? 'Не нужно: сайт отвечает.' : 'Не проверялось.');
        } else {
          set('direct', 'skip', 'Не проверялось: соединения нет.');
          set('sni', 'skip', 'Не проверялось: соединения нет.');
        }
        // 5. открытый HTTP — когда HTTPS не отвечает
        if (!ip || (R.direct && !R.direct.ok && R.direct.kind !== 'freeze')) {
          const ht = R.http = await step('http', { ip: ip || d.ips[0] });
          set('http', ht.isp_page ? 'bad' : ht.ok ? 'ok' : 'warn', ht.isp_page ? 'Отвечает страница провайдера о блокировке.'
            : ht.ok ? `Отвечает: HTTP ${ht.code}` + (ht.redirect ? ` → ${ht.redirect.slice(0, 60)}` : '') + '.' : `Тоже не отвечает (${ht.kind === 'timeout' || ht.kind === 'stall' ? 'нет ответа' : DIAG_KIND[ht.kind] || 'ошибка'}).`);
        } else set('http', 'skip', 'Не нужно.');
        // 6. как сейчас
        const via = R.via = await step('via');
        const where = d.podkop ? 'через podkop' : via.profile ? `через профиль #${via.profile}` : 'nfqws2 его не обрабатывает';
        set('via', via.ok ? 'ok' : 'bad', (via.ok ? `Открывается: HTTP ${via.code}, ${via.ms} мс — ${where}.` : `Не открывается: ${via.reason} — ${where}.`) + (via.running ? '' : ' nfqws2 сейчас остановлен.'));
        // 7. QUIC: настоящий первый пакет HTTP/3 на имя сайта — мимо nfqws2 и через него
        const qip = ip || d.ips[0];
        if (d.podkop || !qip) set('quic', 'skip', d.podkop ? 'Не проверялось: сайт идёт через podkop.' : 'Не проверялось: адреса нет.');
        else {
          const q = R.quic = await step('quic', { ip: qip });
          const qp = q.profile ? `профиль #${q.profile}` : null;
          const [lvl, text] = quicStepText(q, qp);
          q.blocked = lvl === 'bad';
          set('quic', lvl, text);
        }
      } else {
        DIAG_STEPS.slice(1).forEach(([id]) => set(id, 'skip', 'Не проверялось.'));
      }
      R.verdict = await api('diag', { step: 'verdict', host, tls12: old, r: R });
      if (my !== seq) return;
      const [lvl, title, text] = diagVerdictText(R);
      const prof = R.verdict.profile;
      verdictBox.replaceChildren(notice(lvl, title, text, h('div', { class: 'notice-actions' },
        prof && R.verdict.code === 'broken_by_nfqws' ? btn(`Исключить из профиля #${prof}`, () => excludeDialog(host, prof), 'small primary') : null,
        prof && R.verdict.code === 'broken_by_nfqws' ? newTag('diag:exclude') : null,
        R.verdict.pick ? btn('Подобрать стратегию', () => go(tlsHref(pickHref(host), old)), 'small primary', 'tests', old ? { title: 'Подбор тоже по TLS 1.2 — как для старых устройств' } : {}) : null,
        R.quic?.blocked ? btn('Подобрать для QUIC', () => go('#/tests?host=' + encodeURIComponent(host) + '&proto=quic'), 'small', 'tests', { title: 'По QUIC (HTTP/3) сайт не отвечает ни без обхода, ни через nfqws2 — подобрать стратегию для UDP 443' }) : null,
        prof && ['broken_by_nfqws', 'sni_block', 'tls_block', 'fixed'].includes(R.verdict.code) ? h('a', { class: 'btn small', href: `#/settings/p${prof}`, text: `Профиль #${prof}` }) : null,
        btn('Ещё раз', () => run(host), 'small ghost', 'refresh'))),
        ['ok', 'fixed', 'podkop_ok'].includes(R.verdict.code) ? deviceNext(host, R) : null);
      try { localStorage.setItem(KEY, JSON.stringify([host, ...recentGet().filter((x) => x !== host)].slice(0, 8))); } catch { /* нет хранилища */ }
    } catch (e) {
      if (e.message === 'cancelled' || my !== seq) return;
      DIAG_STEPS.forEach(([id]) => { if (rows[id].querySelector('.spin')) set(id, 'bad', 'не удалось проверить'); });
      verdictBox.replaceChildren(notice('bad', 'Диагноз не завершён', e.message));
    }
  }

  const start = () => {
    const v = input.value.trim();
    if (!v) return;
    if (bare || (r.q.get('host') === v && (r.q.get('tls12') === '1') === tls12.checked)) run(v);
    else go(tlsHref(diagHref(v.replace(/^[a-z]+:\/\//i, '').replace(/[/?#].*$/, '')), tls12.checked));
  };
  main.append(
    bare ? null : h('div', { class: 'vh' }, h('h1', { text: 'Диагноз блокировки' })),
    panel(null, null,
      h('form', { class: 'row', onsubmit: (e) => { e.preventDefault(); start(); } }, input, h('button', { class: 'btn primary', type: 'submit' }, 'Проверить', icon('arrow'))),
      h('label', { class: 'chk' }, tls12, 'проверять как старые устройства (TLS 1.2)', newTag('diag:tls12'),
        h('span', { class: 'sm muted', text: ' — для смарт-ТВ и приставок' })),
      bare ? null : h('div', { class: 'recent' }, recentGet().map((x) => h('button', { class: 'chip', type: 'button', text: x, onclick: () => go(tlsHref(diagHref(x), tls12.checked)) }))),
      h('p', { class: 'sm muted', text: 'Роутер по шагам выясняет, почему сайт не открывается: DNS, блокировка по имени или по адресу, заморозка — и поможет ли подбор стратегии. Занимает 10–30 секунд. Ваш трафик и основной nfqws2 не затрагиваются.' })),
    verdictBox, panelEl);
  if (r.q.get('host')) run(r.q.get('host'));
}

// ============ Карточка сайта: проверка, подбор и трассировка одного сайта (компоновка «Вокруг сайта») ============

async function viewSite(main, r) {
  const host = r.arg;
  if (!host) { go(PAGES.mon[0]); return; }
  const sub = ['diag', 'pick', 'trace', 'hist'].includes(r.sub) ? r.sub : 'check';
  const href = (s) => '#/site/' + encodeURIComponent(host) + (s === 'check' ? '' : '/' + s);
  main.append(
    h('div', { class: 'vh' }, h('a', { class: 'btn ghost small', href: PAGES.mon[0] }, icon('back'), 'Сайты'), h('h1', { class: 'site-name', text: host })),
    h('nav', { class: 'subtabs', 'aria-label': 'Сайт' },
      [['check', 'Проверка'], ['diag', 'Диагноз'], ['pick', 'Подбор стратегии'], ['trace', 'Трассировка'], ['hist', 'История подборов']].map(([s, t]) => h('a', { href: href(s), class: s === sub ? 'on' : null, text: t }))));
  if (sub === 'hist') return viewPickHist(main, { q: new URLSearchParams({ host }) }, true);
  const q = new URLSearchParams({ host, ...(r.q?.get('tls12') === '1' ? { tls12: '1' } : {}) });   // «as old devices» from the diagnosis
  if (sub === 'diag') return viewDiag(main, { q }, true);
  if (sub !== 'check') return viewTests(main, { arg: sub, q }, true);
  if (overviewCol()) main.append(h('p', { class: 'muted', text: 'Результат проверки — в колонке слева.' }));
  else main.append(S.check.el);
  if (S.check.shown() !== host) S.check.run(host);
}
