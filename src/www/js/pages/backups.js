'use strict';
// Interface: page: snapshots and change history.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ---------- Резервные копии ----------

async function paneBackup(content) {
  const data = await api('snapshots');
  const reasonText = { 'вручную': 'вручную', 'перед изменением': 'перед изменением', 'ежедневный': 'ежедневный', 'перед восстановлением': 'перед восстановлением', 'исходный': 'исходный' };
  const fileInput = h('input', { type: 'file', accept: '.tar.gz,.tgz,application/gzip', hidden: true, onchange: async (e) => {
    const f = e.target.files[0];
    if (!f) return;
    if (!confirm(`Восстановить конфиг и списки из «${f.name}»? Текущее состояние сохранится снимком. Списки и блобы, которых нет в архиве, удалятся.`)) return;
    const fd = new FormData();
    fd.append('archive', f);
    const r = await fetch('api.php?upload=restore', { method: 'POST', body: fd, credentials: 'same-origin' });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) { toast(j.error || 'Не удалось восстановить', { err: true }); return; }
    toast(restoredText(j, 'Архив совпадает с текущим состоянием'));
    route(true);
  } });
  const maxCount = h('input', { class: 'input num', type: 'number', min: 5, max: 500, value: data.settings.max_count, style: 'width:90px', id: 'snap-count', 'aria-label': 'Не больше снимков' });
  const maxDays = h('input', { class: 'input num', type: 'number', min: 1, max: 3650, value: data.settings.max_days, style: 'width:90px', id: 'snap-days', 'aria-label': 'Хранить дней' });
  const R = data.remote;
  // где лежит снимок; у снимка только на NAS неизвестно, чем он отличается от текущего, — сравнение и восстановление доступны
  const where = (e) => e.local && e.remote ? 'роутер и NAS' : e.local ? (R.on ? 'роутер, ждёт отправки' : 'роутер') : 'NAS';
  // Selection for deleting: only snapshots that are on the router. The bar sits in the panel header line,
  // so selecting does not shift the table.
  const sel = new Set();
  const local = data.items.filter((e) => e.local);
  const boxes = new Map();
  const all = h('input', { type: 'checkbox', 'aria-label': 'Выбрать все снимки на роутере', disabled: !local.length,
    onchange: () => { local.forEach((e) => all.checked ? sel.add(e.id) : sel.delete(e.id)); sync(); } });
  const cnt = h('b', { class: 'cnt' });
  const freed = h('span', { class: 'sm muted sz' });
  const selBar = h('div', { class: 'snapsel off', 'aria-hidden': 'true' }, cnt, freed, h('span', { class: 'grow' }),
    btn('Снять выбор', () => { sel.clear(); sync(); }, 'small ghost clr'),
    btn(h('span', {}, 'Удалить', h('span', { class: 'lbl-long', text: ' с роутера' })), () => deleteSnapshots(data.items.filter((e) => sel.has(e.id)), R), 'small danger', 'trash'));
  const sync = () => {
    for (const [id, [box, tr]] of boxes) { box.checked = sel.has(id); tr.classList.toggle('sel', sel.has(id)); }
    all.checked = sel.size > 0 && sel.size === local.length;
    all.indeterminate = sel.size > 0 && !all.checked;
    selBar.classList.toggle('off', !sel.size);
    selBar.toggleAttribute('aria-hidden', !sel.size);
    cnt.textContent = 'Выбрано: ' + sel.size;
    freed.textContent = '· освободится ' + fmtBytes(local.filter((e) => sel.has(e.id)).reduce((s, e) => s + e.size, 0));
  };
  const pick = (e) => {
    const box = h('input', { type: 'checkbox', 'aria-label': 'Выбрать снимок от ' + fmtDate(e.ts), disabled: !e.local,
      title: e.local ? null : 'Только на NAS — на роутере удалять нечего', onchange: () => { box.checked ? sel.add(e.id) : sel.delete(e.id); sync(); } });
    return box;
  };
  const rows = data.items.map((e) => { const box = pick(e); const tr = h('tr', {},
    // a snapshot kept only on the NAS has nothing to delete on the router — no box at all rather than a faded one
    h('td', { class: 'chk' }, e.local ? box : null),
    h('td', { class: 'num nowrap', text: fmtDate(e.ts) }),
    // the note wraps (two lines at most): a one-line ellipsis kept the column 40 characters wide and the table scrolled sideways
    h('td', {}, reasonText[e.reason] || e.reason, e.note ? h('div', { class: 'sm muted clamp2', style: 'max-width:40ch', text: e.note }) : null),
    h('td', { class: 'sm' }, e.partial ? h('span', { class: 'status-bad', text: 'без конфига и списков (сделан до 1.15.4) — восстановить нечего' }) : e.differs === null ? h('span', { class: 'faint', text: 'неизвестно — снимок на NAS' }) : e.differs.length ? h('span', { class: 'muted', text: 'отличается: ' + e.differs.map(base).slice(0, 4).join(', ') + (e.differs.length > 4 ? ` и ещё ${e.differs.length - 4}` : '') }) : h('span', { class: 'status-ok', text: 'как сейчас' })),
    h('td', { class: 'sm where ' + (e.local ? '' : 'muted'), text: where(e) }),
    h('td', { class: 'num', text: fmtBytes(e.size) }),
    // кнопки — во вложенном блоке: ячейка с display:flex перестаёт быть ячейкой таблицы (строки разъезжаются)
    // the buttons wrap to a second line when the table is narrow, instead of pushing it wider than the window
    h('td', {}, h('div', { class: 'row acts-row' },
      btn('Сравнить', () => compareSnapshot(e), 'small ghost', null, { disabled: e.partial || e.differs !== null && !e.differs.length }),
      btn('Восстановить', async () => {
        const gone = e.extra?.length ? `\n\nУдалятся (их не было в снимке): ${e.extra.map(base).join(', ')}.` : e.extra ? '' : '\n\nСписки и блобы, которых не было в снимке, удалятся.';
        if (!confirm(`Вернуть конфиг и списки к снимку от ${fmtDate(e.ts)}? Текущее состояние сохранится отдельным снимком.${gone}`)) return;
        const r = await guarded(() => api('snapshot_restore', { id: e.id }));
        if (r) { toast(restoredText(r, 'Снимок совпадает с текущим состоянием')); route(true); }
      }, 'small ghost', null, { disabled: e.partial || e.differs !== null && !e.differs.length }),
      h('a', { class: 'btn small ghost icon', href: 'api.php?download=' + e.id, title: 'Скачать', 'aria-label': 'Скачать снимок' }, icon('download')),
      btn('', async () => { await guarded(() => api('snapshot_pin', { id: e.id, pinned: !e.pinned })); route(); }, 'small ghost icon' + (e.pinned ? ' primary' : ''), 'pin', { title: e.pinned ? 'Закреплён — не удаляется с роутера' : 'Закрепить: оставить на роутере', 'aria-label': 'Закрепить', disabled: !e.local }))));
    boxes.set(e.id, [box, tr]);
    return tr; });
  sync();   // fill the hidden bar now, so it already has its final width
  content.append(
    h('div', { class: 'vh' }, h('h1', { text: 'Резервные копии' }), h('span', { class: 'grow' }),
      btn('Создать снимок сейчас', async () => { const note = prompt('Подпись к снимку (необязательно):', ''); if (note === null) return; if (await guarded(() => api('snapshot_create', { note }), 'Снимок создан')) route(true); }, 'primary', 'plus')),
    h('div', { class: 'cards2' },
      panel('На роутере', chip(`${plural(data.local, 'снимок', 'снимка', 'снимков')} · ${fmtBytes(data.total)}`, 'num'),
        h('p', { class: 'sm muted', text: 'Снимок — конфиг, все списки, блобы и настройки интерфейса. Создаётся перед изменениями через интерфейс (не чаще раза в 15 минут), раз в сутки в 04:05 и вручную.' }),
        R.on ? null : h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Хранить' }), h('div', { class: 'row' }, maxDays, h('span', { class: 'sm', text: 'дней, не больше' }), maxCount, h('span', { class: 'sm', text: 'снимков' }),
          btn('Применить', async () => { if (await guarded(() => api('settings_set', { snapshots: { max_count: maxCount.value, max_days: maxDays.value } }), 'Сохранено')) route(); }, 'small'))),
        h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Где' }), h('span', { class: 'mono sm', text: data.dir })),
        h('p', { class: 'sm faint', text: R.on ? `Включена отправка на NAS: на роутере остаются ${data.settings.remote.keep_local} последних, закреплённые и ещё не отправленные, остальные — только на NAS.` : 'Пять последних снимков и закреплённые сами не удаляются — только вручную.' })),
      panel('Вне роутера', null,
        h('div', { class: 'row' }, h('a', { class: 'btn', href: 'api.php?download=current' }, icon('download'), 'Скачать архив'),
          h('label', { class: 'btn' }, icon('upload'), 'Восстановить из файла', fileInput)),
        h('p', { class: 'sm muted', text: 'Архив .tar.gz сохраняется на устройство, с которого открыт интерфейс — ПК или телефон. Из него же можно восстановить: перед этим делается снимок текущего состояния.' }))),
    remotePanel(data),
    panel(R.stored ? 'Снимки' : 'Снимки на роутере', data.items.length ? [newTag('backup:delete'), selBar] : null, data.items.length ? h('div', { class: 'scroll' }, h('table', { class: 'tbl snaps' },
      h('thead', {}, h('tr', {}, h('th', { class: 'chk' }, all), h('th', { text: 'Когда' }), h('th', { text: 'Почему' }), h('th', { class: 'wide', text: 'По сравнению с текущим' }), h('th', { text: 'Где' }), h('th', { class: 'num', text: 'Размер' }), h('th'))),
      h('tbody', {}, rows))) : h('p', { class: 'muted', text: 'Снимков пока нет.' })));
}

// Toast after a restore: how many files came back from the snapshot and how many extra ones were deleted
function restoredText(r, same) {
  const n = r.changed.length, m = (r.removed || []).length;
  if (!n && !m) return same;
  return [n ? `Восстановлено файлов: ${n}` : '', m ? `удалено: ${m} (${r.removed.map(base).join(', ')})` : ''].filter(Boolean).join(', ') + '. Перезапустите nfqws2.';
}

// Confirms and deletes snapshots from the router. Consequences are listed only for the snapshots they apply to.
function deleteSnapshots(list, R) {
  if (!list.length) return;
  const warn = list.flatMap((e) => {
    const when = fmtDate(e.ts);
    if (e.rollback && !(e.remote && R.on)) return [`${when} — снимок перед обновлением nfqws2. «Вернуть» nfqws2 вернёт только программу, конфиг и списки останутся как есть.`];
    if (e.pinned) return [`${when} — закреплён` + (e.remote ? '. Копия на NAS останется.' : '.')];
    if (!e.remote && R.on) return [`${when} — ещё не отправлен на NAS: после удаления его не будет нигде.`];
    return [];
  });
  const onNas = list.filter((e) => e.remote).length;
  const tail = !onNas ? (list.length === 1 ? 'Копии на NAS у него нет — удалённое не вернуть.' : 'Копий на NAS у них нет — удалённое не вернуть.')
    : onNas === list.length ? (list.length === 1 ? 'На NAS копия останется — восстановить из неё можно и потом.' : 'На NAS копии останутся — восстановить из них можно и потом.')
    : `На NAS останутся копии ${onNas} из ${list.length} — из них можно восстановить и потом. Остальные удаляются насовсем.`;
  const go = btn('Удалить', async () => {
    const r = await guarded(() => api('snapshot_delete', { ids: list.map((e) => e.id) }));
    if (!r) return;
    bg.close();
    toast(`Удалено с роутера: ${r.deleted}, освободилось ${fmtBytes(r.freed)}` + (r.on_nas ? `. На NAS остались копии: ${r.on_nas}` : ''));
    route(true);
  }, 'danger', 'trash');
  const bg = modal(list.length === 1 ? `Удалить снимок от ${fmtDate(list[0].ts)}?` : `Удалить снимки: ${list.length}?`, h('div', { class: 'modal-b stack' },
    warn.length ? h('ul', { class: 'snapwarn' }, warn.map((t) => h('li', { text: t }))) : null,
    h('p', { class: 'sm muted', text: tail })), go);
}

// Копии снимков на NAS (или другом устройстве с ssh): куда, ключ роутера, команда для той стороны, проверка
function remotePanel(data) {
  const R = data.remote;
  const c = data.settings.remote;
  const st = R.state || {};
  const now = Math.floor(Date.now() / 1000);
  const inp = (id, label, value, attrs = {}, hint = null) => h('div', { class: 'frow' }, h('label', { class: 'lbl', for: id, text: label }),
    h('input', { class: 'input', id, value: value ?? '', spellcheck: 'false', autocapitalize: 'off', ...attrs }), hint ? h('span', { class: 'hint', text: hint }) : null);
  const on = h('input', { type: 'checkbox', id: 'rm-on', checked: !!c.enabled });
  const val = (id) => document.getElementById(id).value.trim();
  const save = async (quiet) => {
    const r = await guarded(() => api('remote_set', { enabled: on.checked, host: val('rm-host'), port: val('rm-port'), user: val('rm-user'), dir: val('rm-dir'), keep_local: val('rm-keep') }), quiet ? null : 'Сохранено');
    if (r && !quiet) route();
    return r;
  };
  const status = !R.tool ? notice('bad', 'На роутере нет ssh-клиента', 'Нужен dbclient (dropbear) или ssh (OpenSSH).')
    : !c.enabled ? h('p', { class: 'sm muted', text: 'Отправка выключена — снимки хранятся только на роутере.' })
    : st.error ? notice('warn', 'Последняя отправка не удалась' + (st.error_ts ? ' · ' + fmtAgo(now - st.error_ts) : ''), st.error + (R.pending ? ` Ждут отправки: ${R.pending}.` : ''))
    : h('p', { class: 'sm' }, h('span', { class: 'status-ok', text: '✓ Отправка включена' }), h('span', { class: 'muted', text: ` · на NAS ${plural(R.stored, 'снимок', 'снимка', 'снимков')}` + (st.ok ? ' · последняя отправка ' + fmtAgo(now - st.ok) : '') + (R.pending ? ` · ждут отправки: ${R.pending}` : '') }));
  return panel('Копии на NAS', c.enabled && !st.error && R.on ? chip('включено', 'ok') : null,
    h('p', { class: 'sm muted', text: 'Роутер сам отправляет каждый новый снимок по ssh на NAS (или другое устройство с ssh). Там хранятся все снимки, на роутере — только последние. Ключ роутера на NAS ограничен одной папкой: им можно положить новый снимок и прочитать старые, но нельзя ни удалить, ни перезаписать их, ни войти в систему.' }),
    status,
    h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Отправлять' }), h('label', { class: 'chk' }, on, h('span', { text: 'каждый новый снимок на NAS' }))),
    inp('rm-host', 'Адрес', c.host, { class: 'input mono', placeholder: '192.168.1.207' }),
    inp('rm-port', 'Порт ssh', c.port, { type: 'number', min: 1, max: 65535, style: 'width:110px' }),
    inp('rm-user', 'Пользователь', c.user, { class: 'input mono', placeholder: 'имя пользователя на NAS' }),
    inp('rm-dir', 'Папка на NAS', c.dir, { class: 'input mono', placeholder: '/volume1/homes/имя/Backups/nfqws2-ui' }, 'Полный путь. Другую папку роутер выбрать не сможет — она записывается в разрешение ключа на NAS.'),
    inp('rm-keep', 'Оставлять на роутере', c.keep_local, { type: 'number', min: 1, max: 50, style: 'width:110px' }, 'последних снимков, плюс закреплённые'),
    h('div', { class: 'row' },
      btn('Сохранить', () => save(false), 'primary'),
      btn('Проверить связь', async () => {
        if (!(await save(true))) return;
        const r = await guarded(() => api('remote_test'));
        if (r) toast('Связь есть' + (r.count != null ? ` · на NAS ${plural(r.count, 'снимок', 'снимка', 'снимков')}` : ''));
      }, '', null, { disabled: !R.pub }),
      btn('Отправить сейчас', async () => {
        const r = await guarded(() => api('remote_push'));
        if (r) { r.error ? toast('Не отправлено: ' + r.error, { err: true }) : toast(r.busy ? 'Отправка уже идёт' : `Отправлено снимков: ${r.sent}`); route(); }
      }, '', null, { disabled: !R.on })),
    h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Ключ роутера' }),
      R.pub ? h('div', { class: 'stack', style: 'gap:6px' },
        h('code', { class: 'sm mono', style: 'overflow-wrap:anywhere', text: R.pub }),
        // когда всё работает, команда для NAS больше не нужна — свёрнута
        R.setup ? h('details', { open: !(R.on && st.ok && !st.error) }, h('summary', { class: 'sm', text: 'Команда для NAS — выполнить один раз под этим пользователем (по ssh): кладёт скрипт приёма снимков и разрешает ключ роутера только для него' }),
          h('textarea', { class: 'input mono', rows: 6, readonly: true, style: 'width:100%;resize:vertical;font-size:var(--fs-xs);margin-top:6px', text: R.setup }),
          h('div', { class: 'row' }, btn('Копировать команду', () => copyText(R.setup), 'small', 'copy')))
          : h('p', { class: 'sm muted', text: 'Укажите папку на NAS и сохраните — здесь появится команда для NAS.' }))
        : btn('Создать ключ роутера', async () => { if (await guarded(() => api('remote_key'), 'Ключ создан')) route(); }, '', 'plus', { disabled: !R.tool })));
}

async function compareSnapshot(e) {
  const files = e.differs;
  const body = h('div', { class: 'modal-b' });
  const bg = modal(`Снимок от ${fmtDate(e.ts)}: отличия`, body, []);
  const pick = h('select', { class: 'select', 'aria-label': 'Файл', onchange: () => show(pick.value) }, files.map((f) => h('option', { value: f, text: base(f) })));
  const box = h('div', { class: 'stack' });
  body.replaceChildren(h('div', { class: 'stack' }, h('div', { class: 'row' }, h('span', { class: 'sm muted', text: 'Файл:' }), pick), box));
  async function show(f) {
    box.replaceChildren(skel(1, 4));
    const r = await api('snapshot_file', { id: e.id, file: f }).catch((x) => ({ error: x.message }));
    if (r.error) { box.replaceChildren(notice('warn', 'Файла нет в снимке', 'Он появился позже, чем сделан снимок.')); return; }
    box.replaceChildren(h('p', { class: 'sm muted', text: r.current === null ? 'Сейчас этого файла нет.' : 'Красным — как было в снимке, зелёным — как сейчас.' }), renderDiff(diffLines(r.content, r.current ?? '')));
  }
  show(files[0]);
  return bg;
}

// ---------- История изменений ----------

async function paneHistory(content, r) {
  const fileFilter = r.q.get('file') || '';
  const data = await api('history_log', { file: fileFilter, limit: 300 });
  const files = [...new Set((await api('history_log', { limit: 1000 })).items.map((e) => e.file).filter((f) => f !== '*'))].sort();
  const srcIcon = { 'интерфейс': ['edit', 'info'], 'вне интерфейса': ['alert', 'warning'], 'обновление пакета': ['download', 'info'], 'восстановление': ['history', 'info'] };
  const box = h('div', {});
  let day = '';
  for (const e of data.items) {
    const d = fmtDay(e.ts);
    if (d !== day) { box.append(h('div', { class: 'tday', text: d })); day = d; }
    const [ic, lvl] = srcIcon[e.source] || ['info', 'info'];
    const detail = h('details', { ontoggle: async (ev) => {
      if (!ev.target.open || ev.target.dataset.loaded) return;
      ev.target.dataset.loaded = '1';
      const df = await api('history_diff', { id: e.id }).catch(() => null);
      if (df) ev.target.append(renderDiff(diffLines(df.from ?? '', df.to ?? '')));
    } }, h('summary', { text: 'что изменилось' }));
    const summary = e.file === '*' ? e.note : [e.file === 'nfqws2.conf' ? 'Конфиг' : listName(e.file), e.to === null ? 'удалён' : e.from === null ? 'создан' : null, e.note].filter(Boolean).join(': ');
    box.append(h('div', { class: 'tev' },
      h('span', { class: 'tm', text: fmtTime(e.ts) }),
      h('span', { class: 'lvl ' + lvl }, icon(ic)),
      h('div', { class: 'item-main' }, h('div', { text: summary }),
        h('div', { class: 'who' }, e.source, e.n_added || e.n_removed ? ` · +${e.n_added || 0} −${e.n_removed || 0} строк` : ''),
        e.file !== '*' ? detail : null),
      e.file !== '*' && e.path ? btn('Откатить', async () => {
        if (!confirm(`Вернуть ${e.file} к состоянию до этого изменения (${fmtDate(e.ts)})? Текущая версия останется в истории.`)) return;
        if (await guarded(() => api('history_revert', { id: e.id }), 'Изменение откачено')) route(true);
      }, 'small') : h('span')));
  }
  content.append(h('div', { class: 'vh' }, h('h1', { text: 'История изменений' }), h('span', { class: 'grow' }),
    S.state.undo ? btn('Отменить последнее', () => undoLast(true), 'small', 'undo', { title: S.state.undo.note }) : null,
    h('span', { class: 'row', style: 'gap:4px;flex-wrap:nowrap' }, btn('Вернуть как при установке', resetInitial, 'small', 'history', { title: 'Конфиг и списки — к тому, какими они были, когда интерфейс начал вести историю (обычно это установка)' }), newTag('hist:initial')),
    S.state.rollback?.files.length ? btn('Вернуть рабочее состояние', rollbackToWorking, 'small', 'history', { title: 'На ' + fmtDate(S.state.rollback.since) + ': ' + S.state.rollback.files.join(', ') }) : null,
    h('select', { class: 'select', 'aria-label': 'Файл', onchange: (ev) => go('#/settings/hist' + (ev.target.value ? '?file=' + encodeURIComponent(ev.target.value) : '')) },
      h('option', { value: '', text: 'Все файлы' }), files.map((f) => h('option', { value: f, text: f, selected: f === fileFilter })))),
  panel(null, null, h('p', { class: 'sm muted', text: 'Все правки конфига и списков: через интерфейс, по SSH и при обновлении пакета (правки вне интерфейса находятся каждые 10 минут и при каждом открытии). Откат возвращает файл к состоянию до выбранного изменения.' }),
    data.items.length ? box : h('p', { class: 'muted', text: 'Изменений пока нет.' })));
}
