'use strict';
// Interface: page: about, updates, the look settings.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ---------- О программе и обновления ----------

async function paneAbout(content) {
  const st = S.state;
  const u = st.ui?.update || {};
  const link = (href, text) => h('a', { href, target: '_blank', rel: 'noopener', text });
  const row = (label, ...v) => h('div', { class: 'frow kv' }, h('span', { class: 'lbl', text: label }), h('span', {}, ...v));   // read-only «label — value»
  const check = async () => {
    const r = await guarded(() => api('update_check', { force: true }));
    if (!r) return;
    S.state.ui.update = { ...u, ...r };
    updateChrome();
    toast(r.error ? 'Не удалось проверить: ' + r.error : r.available ? `Есть новая версия: ${r.latest}` : 'У вас последняя версия');
    route();
  };
  const cmd = (c, text) => h('div', { class: 'frow cmd-row' }, h('span', { class: 'lbl' }, copyCode(c)), h('span', { class: 'sm', text }));
  content.append(h('div', { class: 'vh' }, h('h1', { text: 'О программе' })),
    panel(null, null,
      h('div', { class: 'about-brand' }, logo(48), h('div', {}, h('h2', { text: 'nfqws2-ui' }), h('div', { class: 'sm muted', text: 'веб-интерфейс для nfqws2 · zemidala' }))),
      row('Версия', h('b', { text: st.ui?.version || '?' }), BUILD.includes('-') ? h('span', { class: 'muted mono', title: 'Хеш файлов интерфейса, загруженных в браузер', text: '  сборка ' + BUILD.split('-').pop() }) : null),
      row('nfqws2', st.version ? 'v' + st.version : 'не определена',
        ...(st.version ? [' · ', h('a', { href: '#', onclick: (e) => { e.preventDefault(); openNfqUpdate(); }, text: st.nfq_update?.available ? `вышла ${st.nfq_update.latest} — обновить` : 'проверить обновление' })] : [])),
      row('Исходный код', link(REPO, REPO.replace('https://', ''))),
      row('Описание и помощь', h('a', { href: '#/settings/readme', text: 'справка (README)' }), ' · ', link(REPO + '/wiki/nfqws2%E2%80%90ui', 'инструкция (Wiki)'), ' · ', h('a', { href: '#/settings/changelog', text: 'изменения по версиям' }), ' · ', link(REPO + '/discussions/categories/q-a', 'задать вопрос'), ' · ', link(REPO + '/issues/new/choose', 'сообщить о проблеме'), ' · ', link('https://t.me/nfqws2_ui', 'чат в Telegram')),
      row('Лицензия', 'MIT')),
    S.authEnabled ? panel('Вход', newTag('about:session'),
      row('Не выходить', h('select', { class: 'select', 'aria-label': 'Сколько не выходить без действий', onchange: async (e) => {
        if (await guarded(() => api('settings_set', { session: Number(e.target.value) }), 'Сохранено')) S.state.ui.session = Number(e.target.value);
      } }, [[3600, '1 час'], [86400, '1 день'], [604800, '7 дней'], [2592000, '30 дней']].map(([v, t]) => h('option', { value: v, text: t, selected: (st.ui?.session || 604800) === v })))),
      h('p', { class: 'sm muted', text: 'Сколько интерфейс помнит вход, если им не пользоваться. Пока вкладка открыта или вы заходите хотя бы раз за этот срок, входить заново не нужно. После перезагрузки роутера вход нужен всегда.' })) : null,
    panel('Обновления', null,
      u.available
        ? notice('info', `Доступна версия ${u.latest}`, 'Обновление ставит пакет с GitHub с проверкой контрольной суммы. nfqws2, конфиг и списки не затрагиваются.',
          h('div', { class: 'notice-actions' }, btn('Что нового', () => openUpdate(u), 'small'), u.can_update ? btn('Обновить', () => openUpdate(u, true), 'small primary', 'download', { disabled: u.running }) : null))
        : u.error ? notice('warn', 'Не удалось проверить обновления', `${u.error}. Попробуйте позже или посмотрите «все релизы».`)
        : u.latest ? notice('ok', 'У вас последняя версия', null) : null,
      row('Последняя проверка', u.checked ? fmtAgo(Date.now() / 1000 - u.checked) : 'ещё не было', u.error ? h('span', { class: 'status-bad', text: ' — ' + u.error }) : null),
      h('div', { class: 'row' }, btn('Проверить сейчас', check, 'small', 'refresh'), link(u.url || REPO + '/releases', 'все релизы')),
      h('p', { class: 'sm muted', text: 'Интерфейс сам проверяет обновления раз в 12 часов и раз в сутки по расписанию. Если вышла новая версия, сверху появится плашка.' })),
    panel('Из консоли роутера', null,
      cmd('nfqws-ui update', 'обновить до последней версии'),
      cmd('nfqws-ui update --check', 'только проверить'),
      cmd('nfqws-ui update v1.0.0 --force', 'поставить конкретную версию (откат)'),
      cmd('nfqws-ui status', 'адреса, порты, HTTPS'),
      cmd('nfqws-ui help', 'все команды; nfqws-ui — короткое имя для nfqws-ui-setup')));
}

// ---------- Оформление ----------

// Схемы компоновок для выбора
const LAYOUT_THUMB = {
  menu: '<rect width="120" height="68" rx="4" fill="var(--bg)" stroke="var(--line)"/><rect width="120" height="10" rx="4" fill="var(--surface)" stroke="var(--line)"/><rect y="10" width="30" height="58" fill="var(--surface)" stroke="var(--line)"/><path d="M5 17h18M5 23h14M5 35h18M5 41h12M5 47h16M5 59h14" stroke="var(--faint)" stroke-width="2" stroke-linecap="round"/><rect x="3" y="26" width="24" height="6" rx="2" fill="var(--accent-soft)"/><rect x="36" y="16" width="78" height="20" rx="2" fill="var(--surface)" stroke="var(--line)"/><rect x="36" y="40" width="78" height="22" rx="2" fill="var(--surface)" stroke="var(--line)"/>',
  tabs: '<rect width="120" height="68" rx="4" fill="var(--bg)" stroke="var(--line)"/><rect width="120" height="10" rx="4" fill="var(--surface)" stroke="var(--line)"/><rect x="30" y="3" width="50" height="4" rx="2" fill="var(--accent)"/><rect y="10" width="28" height="58" fill="var(--surface)" stroke="var(--line)"/><rect x="34" y="16" width="44" height="3" rx="1.5" fill="var(--faint)"/><rect x="34" y="24" width="80" height="16" rx="2" fill="var(--surface)" stroke="var(--line)"/><rect x="34" y="44" width="80" height="18" rx="2" fill="var(--surface)" stroke="var(--line)"/>',
  site: '<rect width="120" height="68" rx="4" fill="var(--bg)" stroke="var(--line)"/><rect width="120" height="10" rx="4" fill="var(--surface)" stroke="var(--line)"/><rect x="30" y="3" width="40" height="4" rx="2" fill="var(--accent)"/><rect x="88" y="3" width="26" height="4" rx="2" fill="var(--line)"/><rect x="18" y="16" width="84" height="6" rx="2" fill="var(--accent-soft)"/><rect x="18" y="26" width="84" height="36" rx="2" fill="var(--surface)" stroke="var(--line)"/><path d="M22 34h76M22 41h76M22 48h76M22 55h76" stroke="var(--line)"/>',
};

async function paneLook(content) {
  const look = getLook();
  // colour scheme for every style but Z; the swatch shows the scheme's page and button colours in the current theme.
  // Same shape as «Компоновка» and «Стиль интерфейса» above: a grey caption on top, the choice under it
  const tintRow = h('div', { class: 'look-sub' });
  const drawTint = () => {
    tintRow.hidden = !SCHEME_VARIANTS.includes(getLook().variant);
    if (tintRow.hidden) return;
    const cur = SCHEMES.some(([id]) => id === getLook().scheme) ? getLook().scheme : null;
    const nt = newTag('look:scheme');
    const pick = (id) => { setLook({ scheme: id || undefined }); drawTint(); };
    tintRow.replaceChildren(h('span', { class: 'sm muted' }, 'Цветовая схема', nt ? [' ', nt] : null),
      h('div', { class: 'tints', role: 'radiogroup', 'aria-label': 'Цветовая схема' },
        h('button', { type: 'button', role: 'radio', 'aria-checked': String(!cur), class: 'tint' + (!cur ? ' on' : ''), onclick: () => pick(null) }, 'Как в стиле'),
        SCHEMES.map(([id, name, dark, light]) => {
          const p = darkNow() ? dark : light;
          return h('button', { type: 'button', role: 'radio', 'aria-checked': String(cur === id), class: 'tint' + (cur === id ? ' on' : ''), onclick: () => pick(id) },
            h('i', { style: `background:linear-gradient(135deg, ${p.bg} 0 50%, ${p.accent} 50% 100%)` }), name);
        })));
  };
  drawTint();
  const card = (v, title, text, thumb) => h('label', { class: 'look' }, h('input', { type: 'radio', name: 'look', value: v, checked: look.variant === v, onchange: () => { setLook({ variant: v }); drawTint(); } }), thumb, h('b', { text: title }), h('span', { class: 'sm muted', text }));
  const i = (cls, style) => h('i', { class: cls, style });
  // Компоновка: где показаны переходы между страницами. Страницы и их адреса одни и те же.
  const setLayout = (v) => { setLook({ layout: v }); renderShell(); route(); };
  const lay = (v, title, text) => h('label', { class: 'look' }, h('input', { type: 'radio', name: 'layout', value: v, checked: look.layout === v, onchange: () => setLayout(v) }),
    svgEl('0 0 120 68', LAYOUT_THUMB[v], 'lthumb'), h('b', { text: title }), h('span', { class: 'sm muted', text }));
  content.append(h('div', { class: 'vh' }, h('h1', { text: 'Оформление' })),
    panel(null, null,
      h('label', { class: 'chk' }, h('input', { type: 'checkbox', checked: !!look.simple, onchange: (e) => { setLook({ simple: e.target.checked }); renderShell(); route(); } }),
        h('span', {}, h('b', { text: 'Упрощённый вид' }), h('br'), h('span', { class: 'sm muted', text: 'Боковое меню — шесть разделов, страницы раздела — вкладками над страницей; «Обзор» по умолчанию «Кратко»; обновить, HTTPS, GitHub и выход — под кнопкой «⋯». Для тех, кому полный вид кажется перегруженным. Функции те же.' }))),
      h('label', { class: 'chk' }, h('input', { type: 'checkbox', checked: !look.hideWinTs, onchange: (e) => { setLook({ hideWinTs: !e.target.checked }); updateSide(); } }),
        h('span', {}, h('b', { text: 'Напоминать про метки TCP в Windows' }), ' ', newTag('look:wints'), h('br'), h('span', { class: 'sm muted', text: `Пункт в «Проблемах» у профиля, стратегия которого маскируется только через tcp_ts: в Windows такие стратегии работают, только если включены временные метки TCP (${WIN_TS_CMD}). Если на ваших компьютерах они уже включены, напоминание можно убрать.` }))),
      h('label', { class: 'chk' }, h('input', { type: 'checkbox', checked: !!look.showLoad, onchange: (e) => { setLook({ showLoad: e.target.checked }); drawLoad(); } }),
        h('span', {}, h('b', { text: 'Показывать нагрузку роутера' }), ' ', newTag('look:load'), h('br'), h('span', { class: 'sm muted', text: 'В шапке, рядом с nfqws2: загрузка процессора и занятая память в процентах, на широком экране — ещё средняя нагрузка. Цифра желтеет, если процессор занят больше чем на 80 % или свободной памяти меньше 15 %. Подробности — при наведении. Обновляется раз в 10 секунд, на роутере с малой памятью — раз в 30.' }))),
      h('label', { class: 'chk' }, h('input', { type: 'checkbox', checked: !look.noanim, onchange: (e) => { setLook({ noanim: !e.target.checked }); } }),
        h('span', {}, h('b', { text: 'Анимации' }), ' ', newTag('look:anim'), h('br'), h('span', { class: 'sm muted', text: 'Страница плавно появляется при переходе, меню и окна — при открытии. Если в системе включено «уменьшить движение», анимаций нет независимо от этой галочки.' })))),
    panel(null, null,
      h('fieldset', { class: 'looks' }, h('legend', { class: 'sm muted', text: 'Компоновка — как разложены разделы' }),
        lay('menu', 'Боковое меню', 'Все разделы списком слева, любой — в один клик.'),
        lay('tabs', 'Вкладки по задачам', 'Вкладки сверху, слева постоянная колонка обзора.'),
        lay('site', 'Вокруг сайта', 'Таблица сайтов и карточка сайта: проверка, подбор, трассировка рядом.'))),
    panel(null, null,
      h('fieldset', { class: 'looks' }, h('legend', { class: 'sm muted', text: 'Стиль интерфейса' }),
        card('a', 'A · Консоль', 'Плотно, панель с разделителями. Больше всего данных на экране.', h('span', { class: 'thumb th-a' }, h('span', { class: 'tl' }, i('acc'), i(), i(), i()), h('span', { class: 'tr' }, i(), i('', 'width:70%'), i('acc', 'width:40%')))),
        card('b', 'B · Карточки', 'Мягкие карточки и воздух. Удобнее на телефоне.', h('span', { class: 'thumb th-b' }, h('span', { class: 'tl' }, i(), i(), i('acc')), h('span', { class: 'tr' }, i(), i()))),
        card('c', 'C · Схема', 'Путь соединения через профили — главный объект.', h('span', { class: 'thumb th-c' }, h('span', { class: 'tl' }, i(), i(), i()), h('span', { class: 'tr' }, [0, 1, 2].map((k) => h('span', { class: 'pl' }, h('span', { class: 'nd' }), i(k === 0 ? 'acc' : '')))))),
        card('k', 'K · Keenetic', 'Как веб-интерфейс Keenetic: светлое меню с разделами, белые карточки, синие кнопки.', h('span', { class: 'thumb th-k' }, h('span', { class: 'tl' }, i('acc'), i(), i(), i('acc'), i()), h('span', { class: 'tr' }, i('card'), i('card')))),
        h('div', { class: 'look-new' }, card('z', 'Z · zemidala', 'Фирменные цвета: чёрное меню, графит, оранжевые кнопки и отметки. Раскладка как у K.', h('span', { class: 'thumb th-z' }, h('span', { class: 'tl' }, i('acc'), i(), i(), i('acc'), i()), h('span', { class: 'tr' }, i('card'), i('card')))), newTag('look:zd'))),
      tintRow,
      h('div', { class: 'look-sub' }, h('span', { class: 'sm muted', text: 'Тема' }),
        h('div', { class: 'seg', role: 'radiogroup', 'aria-label': 'Тема' }, [['auto', 'Как в системе'], ['light', 'Светлая'], ['dark', 'Тёмная']].map(([m, t]) =>
          h('button', { type: 'button', role: 'radio', 'aria-checked': String(look.mode === m), class: look.mode === m ? 'on' : '', text: t, onclick: () => { setLook({ mode: m }); route(); } })))),
      h('p', { class: 'sm muted', text: 'Выбор хранится в этом браузере: на телефоне и компьютере можно выбрать разное.' })));
}
