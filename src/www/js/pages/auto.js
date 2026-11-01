'use strict';
// Interface: page: automatic pick when a site breaks.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ Автоподбор при поломке ============

const AUTO_KIND = { start: 'info', found: 'ok', applied: 'ok', none: 'error', rolled: 'warning', fail: 'warning', skip: 'info' };

async function viewAuto(main) {
  const a = await api('auto_get');
  const cfg = a.settings;
  const enabled = h('input', { type: 'checkbox', id: 'au-on', checked: cfg.enabled });
  const apply = h('input', { type: 'checkbox', id: 'au-apply', checked: cfg.apply });
  const fails = h('select', { class: 'select', 'aria-label': 'Сколько неудач подряд' }, [2, 3, 4].map((n) => h('option', { value: n, text: `${n} проверки подряд`, selected: n === cfg.fails })));
  const pause = h('select', { class: 'select', 'aria-label': 'Пауза между попытками' }, [6, 12, 24].map((n) => h('option', { value: n, text: `${n} часов`, selected: n === cfg.pause })));
  const save = async () => {
    if (apply.checked && !cfg.apply && !confirm('Разрешить роутеру самому менять конфиг?\n\nКогда сайт из мониторинга сломается и стратегия найдётся, роутер создаст отдельный профиль только для этого сайта и перезапустит nfqws2 — без вашего участия, в любое время суток. Если сайт не откроется или сломается другой сайт мониторинга, конфиг вернётся как был. Чужие профили и списки не меняются.')) { apply.checked = false; return; }
    if (await guarded(() => api('auto_set', { enabled: enabled.checked, apply: apply.checked, fails: Number(fails.value), pause: Number(pause.value) }), 'Сохранено')) { await loadState().catch(() => {}); route(true); }
  };
  [enabled, apply, fails, pause].forEach((el) => el.addEventListener('change', save));
  main.append(
    h('div', { class: 'vh' }, h('h1', { text: 'Автоподбор' })),
    policyOnly() ? notice('warn', 'Автоподбор не запускается', 'nfqws2 обрабатывает только устройства политики доступа, а мониторинг ходит с самого роутера — мимо nfqws2. Он не видит, работает ли обход, и поломку от нормы не отличит.', h('a', { class: 'btn small', href: PAGES.mon[0] }, 'Мониторинг')) : null,
    !a.monitor.enabled || !a.monitor.sites ? notice('warn', 'Мониторинг выключен или пуст', 'Автоподбор узнаёт о поломке от мониторинга: включите его и добавьте сайты, за которыми нужно следить.', h('a', { class: 'btn small', href: PAGES.mon[0] }, 'Мониторинг')) : null,
    a.running ? notice('info', `Сейчас идёт автоподбор для ${a.running}`, 'Ход виден на странице подбора.', h('a', { class: 'btn small', href: pickHref(a.running) }, 'Открыть')) : null,
    a.offers.length ? panel('Найдено — ждёт вашего решения', null, a.offers.map((o) => h('div', { class: 'stack', style: 'gap:8px' },
      h('div', { class: 'item-main' }, h('span', { class: 'row', style: 'gap:8px' }, levelIcon('ok'), h('b', { class: 'mono', text: o.host })),
        h('span', { class: 'sm muted', text: `${fmtDate(o.ts)} · ${o.name} · ${o.from}` }),
        h('code', { class: 'sm muted', style: 'word-break:break-all', text: o.steps.map((t) => t.replace('--lua-desync=', '')).join('  ') }),
        o.list ? h('span', { class: 'sm', text: `Эта стратегия уже стоит в профиле #${o.list.profile} — достаточно добавить сайт в ${o.list.name}: без нового профиля и без перезапуска.` }) : null),
      h('div', { class: 'row' }, btn(o.list ? `Добавить в ${o.list.name}` : 'Применить', () => autoApply(o), 'small primary', o.list ? 'plus' : 'ok'),
        h('a', { class: 'btn small', href: pickHistHref(o.host) }, 'История'),
        btn('Отклонить', async () => { if (await guarded(() => api('auto_dismiss', { host: o.host }))) { await loadState().catch(() => {}); route(true); } }, 'small ghost'))))) : null,
    panel(null, null,
      h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Автоподбор' }), h('div', { class: 'stack', style: 'gap:2px' },
        h('label', { class: 'chk' }, enabled, 'подбирать стратегию, когда сайт из мониторинга перестаёт открываться'),
        h('span', { class: 'sm muted', text: `Следит за сайтами мониторинга (сейчас ${a.monitor.sites}). Упавший сайт перепроверяется через 10 минут; если он не открывается и снова — запускается подбор. Первым пробуется то, что для этого сайта уже работало.` }))),
      h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Что делать с найденным' }), h('div', { class: 'stack', style: 'gap:2px' },
        h('label', { class: 'chk' }, apply, 'применять самому — записью в список профиля, если его стратегия подошла, иначе отдельным профилем только для этого сайта'),
        h('span', { class: 'sm muted', text: 'Без галочки найденное только предлагается: здесь, в «Проблемах» и в Telegram. С галочкой роутер сам создаёт профиль для сайта, перезапускает nfqws2 и проверяет: если сайт не открылся или сломался другой сайт мониторинга — возвращает конфиг как был.' }))),
      h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Считать поломкой' }), h('div', { class: 'row' }, fails, h('span', { class: 'sm muted', text: 'неудачных, после того как сайт открывался' }))),
      h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Повторять не чаще' }), h('div', { class: 'row' }, h('span', { class: 'sm muted', text: 'раз в' }), pause, h('span', { class: 'sm muted', text: 'для одного сайта' }))),
      h('p', { class: 'sm faint', text: 'Автоподбор не запускается, если не открывается больше половины сайтов мониторинга (похоже на обрыв связи), если nfqws2 остановлен или сайт идёт через podkop. За один проход — один сайт.' + (a.tg ? '' : ' Чтобы узнавать о находках сразу, настройте Telegram в «Уведомлениях».') })),
    panel('Журнал', a.log.length ? btn('Очистить', async () => { if (await guarded(() => api('auto_clear'))) route(true); }, 'small ghost') : null,
      a.queue.length ? h('p', { class: 'sm', text: 'В очереди: ' + a.queue.join(', ') }) : null,
      a.log.length ? h('div', { class: 'stack', style: 'gap:0' }, a.log.map((e) => h('div', { class: 'act' }, levelIcon(AUTO_KIND[e.kind] || 'info'),
        h('div', { class: 'item-main' }, h('span', {}, h('a', { class: 'mono', href: pickHistHref(e.host), text: e.host }), h('span', { class: 'sm faint', text: ' · ' + fmtDate(e.ts) })),
          h('span', { class: 'sm muted', text: e.text })))))
        : h('p', { class: 'muted', text: cfg.enabled ? 'Пока ничего не происходило — все сайты мониторинга открываются.' : 'Автоподбор выключен.' })));
}
