'use strict';
// Interface: page: settings frame and profile list.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ Настройки ============

async function viewSettings(main, r) {
  await Promise.all([loadCatalog(), loadConf()]);
  const pane = r.arg || 'p' + (S.state.conf_profiles.find((p) => profIssues(p).some((x) => x.level === 'error'))?.index || 1);
  const content = h('div', { class: 'stack', style: 'gap:14px' });
  const m = pane.match(/^p(\d+)$/);
  if (m) {
    // «Профили»: слева список профилей, справа выбранный
    const nav = h('nav', { class: 'nav lnav scrollable', 'aria-label': 'Профили' });
    main.append(h('div', { class: 'vh' }, h('h1', { text: 'Профили' })), h('div', { class: 'split settings' }, nav, content));
    drawSettingsNav(nav, pane);
    return viewProfile(content, Number(m[1]), r);
  }
  // остальные страницы настроек открываются из меню или вкладок «Система»
  main.append(content);
  const panes = { basic: paneBasic, base: paneBase, raw: paneRaw, backup: paneBackup, hist: paneHistory, look: paneLook, about: paneAbout, report: paneReport, readme: (c) => paneDoc(c, 'readme'), changelog: (c) => paneDoc(c, 'changelog') };
  await (panes[pane] || paneBasic)(content, r);
}

function drawSettingsNav(nav, current) {
  const st = S.state;
  const customs = st.conf_profiles.filter((p) => p.source?.source === 'NFQWS_ARGS_CUSTOM');
  const item = (id, label, tail) => h('a', { href: '#/settings/' + id, class: id === current ? 'on' : null }, label, tail ? h('span', { class: 'tail' }, tail) : null);
  let dragFrom = null;
  nav.replaceChildren(
    h('div', { class: 'nav-h', text: 'Профили — порядок проверки' }),
    st.conf_profiles.map((p) => {
      const iss = profIssues(p);
      const lvl = worst(iss);
      const isCustom = p.source?.source === 'NFQWS_ARGS_CUSTOM';
      const a = item('p' + p.index, [h('span', { class: 'pn ' + (lvl === 'error' ? 'err' : p.state) , text: p.index }), h('span', { class: 'nm', text: profName(p) })],
        [lvl === 'error' ? h('span', { class: 'errdot', title: 'Есть ошибки' }) : lvl === 'warning' ? h('span', { class: 'warndot', title: 'Есть предупреждения' }) : null, isCustom ? h('span', { class: 'grip', title: 'Перетащите, чтобы изменить порядок', text: '⋮⋮' }) : h('span', { title: 'Порядок задаёт скрипт запуска', text: '🔒︎' })]);
      if (isCustom && customs.length > 1) {
        a.draggable = true;
        a.addEventListener('dragstart', () => { dragFrom = p.source.part; });
        a.addEventListener('dragover', (e) => { e.preventDefault(); a.classList.add('drag-over'); });
        a.addEventListener('dragleave', () => a.classList.remove('drag-over'));
        a.addEventListener('drop', (e) => { e.preventDefault(); a.classList.remove('drag-over'); moveCustom(dragFrom, p.source.part); });
      }
      return a;
    }),
    h('button', { class: 'ni', type: 'button', onclick: addCustomProfile }, h('span', { class: 'pn pi' }, icon('plus')), h('span', { class: 'nm muted', text: 'Свой профиль' })),
    h('button', { class: 'ni', type: 'button', onclick: pasteProfile }, h('span', { class: 'pn pi' }, icon('download')), h('span', { class: 'nm muted', text: 'Вставить чужой' })));
}

// Профиль одним куском для чата: подпись + параметры
function shareProfile(index, args) {
  const st = S.state;
  const prov = h('input', { class: 'input grow', value: st.ui.provider || '', placeholder: 'название провайдера', 'aria-label': 'Провайдер' });
  const ta = h('textarea', { class: 'input mono', rows: 7, readonly: true, style: 'width:100%;resize:vertical' });
  const draw = () => { ta.value = `# nfqws2 ${st.version ? 'v' + st.version : ''} · ${prov.value.trim() || 'провайдер не указан'} · ${st.ui.platform}\n${args.join(' ')}`; };
  prov.addEventListener('input', draw);
  const keep = () => { if (prov.value.trim() !== (st.ui.provider || '')) api('provider_set', { provider: prov.value.trim() }).then((r) => { st.ui.provider = r.provider; }).catch(() => {}); };
  draw();
  modal(`Профиль #${index} — для чата`, h('div', { class: 'stack modal-b' },
    h('div', { class: 'row' }, h('span', { class: 'sm muted', text: 'Провайдер' }), prov,
      btn('Определить', async () => { const r = await guarded(() => api('provider_detect')); if (r) { prov.value = r.provider; draw(); keep(); } }, 'small', 'search', { title: 'Роутер спросит у RIPEstat, чьей сети принадлежит ваш внешний адрес. Сам адрес в текст не попадает.' })),
    ta, h('p', { class: 'sm faint', text: 'В тексте только параметры профиля и подпись. Пути к спискам остаются как у вас — получателю их поправит «Вставить чужой».' })),
    btn('Скопировать', () => { keep(); copyText(ta.value); }, 'primary', 'copy'));
}

// Чужой профиль из чата: разбираем текст, отбрасываем лишнее, проверяем у nfqws2, даём испытать на сайте
function pasteProfile(text) {
  const ta = h('textarea', { class: 'input mono', rows: 6, style: 'width:100%;resize:vertical', placeholder: '--filter-tcp=443 --filter-l7=tls --lua-desync=fake:blob=tls_clienthello:tcp_md5 …', spellcheck: 'false' });
  if (typeof text === 'string') ta.value = text;   // профиль из «Чужого конфига»; из меню приходит событие клика
  const out = h('div', { class: 'stack' });
  const site = h('input', { class: 'input mono', placeholder: 'сайт для проверки, например rutracker.org', style: 'max-width:300px', autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'Сайт для проверки' });
  let res = null;
  // строки-подписи и комментарии убираем; кавычки, обратные косые и переносы — тоже
  const parse = () => ta.value.split('\n').filter((l) => !/^\s*#/.test(l)).join(' ').replace(/["'`\\]/g, ' ').replace(/^[A-Z_]+=/, '').split(/\s+/).filter(Boolean);
  const check = async () => {
    const parts = splitParts(parse()).filter((x) => x.length);
    if (!parts.length) { toast('Вставьте текст профиля', { err: true }); return; }
    res = await guarded(() => api('profile_check', { tokens: parts[0] }));
    if (!res) return;
    const bad = res.missing.length || !res.steps.length || (res.dry_run && !res.dry_run.ok);
    out.replaceChildren(
      parts.length > 1 ? notice('info', `В тексте ${plural(parts.length, 'профиль', 'профиля', 'профилей')} — взят первый`, 'Остальные вставьте по одному.') : null,
      res.tokens.length ? copyBox(h('pre', { class: 'box', style: 'white-space:pre-wrap;word-break:break-all', text: res.tokens.join('\n') })) : null,
      res.rejected.length ? notice('warn', 'Отброшено', res.rejected.map((x) => `${x.tok} — ${x.why}`).join('; ')) : null,
      res.fixed.length ? notice('info', 'Пути к спискам поправлены под этот роутер', res.fixed.map((x) => `${x.from} → ${x.to}`).join('; ')) : null,
      res.missing.length ? notice('bad', 'Нет файлов, на которые ссылается профиль', res.missing.join(', ') + ' — создайте такие списки или уберите эти параметры из текста.') : null,
      res.dry_run && !res.dry_run.ok ? notice('bad', 'nfqws2 не принимает такой профиль', res.dry_run.message) : null,
      !res.steps.length ? notice('bad', res.tokens.length ? 'В профиле не осталось стратегии' : 'Параметров профиля в тексте не нашлось', res.tokens.length ? 'Без шагов --lua-desync профиль ничего не делает.' : '') : null,
      !bad ? notice('ok', 'nfqws2 принимает этот профиль', 'Можно испытать его стратегию на сайте или сразу добавить.') : null,
      !bad ? h('div', { class: 'row' },
        res.steps.length ? [site, btn('Проверить на сайте', test, 'small', 'play', { title: 'Стратегия профиля прогоняется отдельным nfqws2 на проверочных соединениях роутера — ваш трафик не затрагивается' })] : null,
        h('span', { class: 'grow' }), btn('Добавить профилем', add, 'small primary', 'ok')) : null);
  };
  const test = async () => {
    if (!site.value.trim()) { toast('Укажите сайт', { err: true }); return; }
    if (!await guarded(() => api('test_start', { host: site.value.trim(), sets: [], steps: res.steps, repeats: 3 }))) return;
    const host = site.value.trim().replace(/^[a-z]+:\/\//i, '').replace(/[/?#].*$/, '');
    bg.close();
    go(pickHref(host));
  };
  const add = async () => {
    await loadConf();
    const cur = S.conf.vars.NFQWS_ARGS_CUSTOM.trim() ? splitParts(tokensOf(S.conf.vars.NFQWS_ARGS_CUSTOM)) : [];
    if (!await ask('Добавить профиль первым в «свои профили»?\n\nОн будет проверяться раньше остальных; порядок потом можно поменять перетаскиванием. После сохранения nfqws2 перезапустится с проверкой и откатит изменения, если вы их не подтвердите.', 'Добавить профиль')) return;
    if (!await saveVars({ NFQWS_ARGS_CUSTOM: joinParts([res.tokens, ...cur]) }, 'вставлен чужой профиль')) return;
    bg.close();
    await safeRestart();
    go('#/settings/p1');
  };
  const bg = modal('Вставить чужой профиль', h('div', { class: 'stack modal-b' },
    h('p', { class: 'sm muted', text: 'Вставьте текст профиля из чата как есть — подписи, кавычки и переносы уберутся сами. Останутся только параметры, которые знает nfqws2 на этом роутере.' }),
    ta, h('div', { class: 'row' }, btn('Разобрать и проверить', check, 'primary small')), out));
  if (ta.value) check();
}

async function moveCustom(from, to) {
  if (!from || from === to) return;
  const parts = splitParts(tokensOf(S.conf.vars.NFQWS_ARGS_CUSTOM));
  const [moved] = parts.splice(from - 1, 1);
  parts.splice(to - 1, 0, moved);
  if (await saveVars({ NFQWS_ARGS_CUSTOM: joinParts(parts) }, `порядок своих профилей: ${from} → ${to}`)) {
    toast('Порядок изменён. Перезапустите nfqws2, чтобы применить.');
    go('#/settings/p' + to);
  }
}

async function addCustomProfile() {
  const parts = S.conf.vars.NFQWS_ARGS_CUSTOM.trim() ? splitParts(tokensOf(S.conf.vars.NFQWS_ARGS_CUSTOM)) : [];
  parts.push(['--filter-tcp=443', '--filter-l7=tls', '--payload=tls_client_hello', '--lua-desync=multisplit:pos=1,midsld']);
  if (await saveVars({ NFQWS_ARGS_CUSTOM: joinParts(parts) }, 'добавлен свой профиль ' + parts.length)) {
    toast('Профиль добавлен в конец своих профилей');
    go('#/settings/p' + parts.length);
  }
}
