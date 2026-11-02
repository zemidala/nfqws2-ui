'use strict';
// Interface: page: checking someone else's config.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ Чужой конфиг: проверить целиком, не ставя ============
// Конфиги из чатов проверяются на проверочной очереди рядом с рабочим nfqws2 и сравниваются с текущим.

const CFG_DRAFT = [];   // добавленные, но ещё не проверенные конфиги: живут, пока открыта вкладка

async function viewCfg(main) {
  const mon = (S.state.monitor?.sites || []).map((x) => x.host);
  const sites = h('input', { class: 'input mono grow', value: mon.join(', '), placeholder: 'сайты через запятую, например rutracker.org, youtube.com', autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'Сайты для проверки' });
  const chips = h('div', { class: 'row' });
  const out = h('div', { class: 'stack', style: 'gap:14px' }, skel(1, 4));
  const startBtn = btn('Проверить', start, 'primary', 'play');
  const withMine = h('input', { type: 'checkbox', id: 'cfg-mine', checked: true });
  let last = null;
  let detail = false;

  const drawChips = () => {
    chips.replaceChildren(...CFG_DRAFT.map((c, i) => h('span', { class: 'chip accent' }, c.name,
      h('button', { class: 'x', type: 'button', 'aria-label': 'Убрать ' + c.name, onclick: () => { CFG_DRAFT.splice(i, 1); drawChips(); } }, '×'))),
    CFG_DRAFT.length ? null : h('span', { class: 'sm muted', text: 'Пока ничего не добавлено.' }));
  };
  const add = (name, text) => {
    if (CFG_DRAFT.length >= 10) { toast('Не больше 10 конфигов за раз', { err: true }); return false; }
    CFG_DRAFT.push({ name: name.trim() || 'Конфиг ' + (CFG_DRAFT.length + 1), text });
    drawChips();
    return true;
  };
  const addText = () => {
    const ta = h('textarea', { class: 'input mono', rows: 10, style: 'width:100%;resize:vertical', placeholder: 'Вставьте содержимое nfqws2.conf целиком', spellcheck: 'false' });
    const nm = h('input', { class: 'input', placeholder: 'Имя, например «из чата» (необязательно)', style: 'max-width:320px' });
    const bg = modal('Конфиг текстом', h('div', { class: 'stack modal-b' }, ta, nm,
      h('p', { class: 'sm faint', text: 'Пути под этот роутер поправятся сами. Конфиг, в котором кроме переменных есть команды, проверяться не будет.' })),
    btn('Добавить', () => { if (!ta.value.trim()) { toast('Вставьте текст конфига', { err: true }); return; } if (add(nm.value, ta.value)) bg.close(); }, 'primary'));
    ta.focus();
  };
  const addUrl = () => {
    const u = h('input', { class: 'input mono', placeholder: 'https://raw.githubusercontent.com/…/nfqws2.conf', style: 'width:100%', autocapitalize: 'off', spellcheck: 'false' });
    const bg = modal('Конфиг по ссылке', h('div', { class: 'stack modal-b' }, u,
      h('p', { class: 'sm faint', text: 'Ссылка на сам файл. Для GitHub подойдёт и ссылка на страницу файла (…/blob/…) — роутер возьмёт его текст.' })),
    btn('Скачать', async () => {
      const r = await guarded(() => api('cfg_fetch', { url: u.value.trim() }));
      if (r && add('', r.text)) bg.close();
    }, 'primary'));
    u.focus();
  };
  // Files are read in the browser and go through the same checks as a config fetched by link
  const CFG_FILE_MAX = 65536;   // = CFG_TEXT_MAX in api.php
  const fileInput = h('input', { type: 'file', accept: '.conf,.txt,text/plain', multiple: true, hidden: true, onchange: async () => {
    for (const f of fileInput.files) {
      const bad = (why) => toast(`«${f.name}»: ${why}`, { err: true });
      if (f.size > CFG_FILE_MAX) { bad(`больше ${CFG_FILE_MAX / 1024} КБ — это не nfqws2.conf`); continue; }
      const text = (await f.text()).replace(/^﻿/, '').replace(/\r\n?/g, '\n');
      if (text.includes('\0') || !/^[ \t]*NFQWS_\w+=/m.test(text)) { bad('не текст конфига nfqws2 (нет переменных NFQWS_…)'); continue; }
      if (!add(f.name.replace(/\.(conf|txt)$/i, ''), text)) break;
    }
    fileInput.value = '';
  } });

  main.append(
    h('div', { class: 'vh' }, h('h1', { text: 'Чужой конфиг' })),
    panel(null, null,
      h('p', { text: 'Проверьте конфиг из чата раньше, чем ставить его вместо своего. Роутер запустит каждый отдельным nfqws2 на проверочных соединениях, откроет одни и те же сайты и сравнит с вашим текущим конфигом (если галочка «Сравнение» стоит). Ваш конфиг и трафик при этом не меняются.' }),
      h('div', { class: 'row cfg-add' }, h('b', { class: 'grow', text: 'Конфиги для проверки' }), newTag('cfg:file'),
        h('span', { class: 'cfg-btns' }, btn('Текстом', addText, 'small', 'plus'), btn('По ссылке', addUrl, 'small', 'plus'),
          btn('Из файла', () => fileInput.click(), 'small', 'upload', { title: 'Файл .conf или .txt с компьютера или телефона, можно несколько' })), fileInput),
      chips,
      h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Сайты' }), sites),
      h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Сравнение' }), h('div', { class: 'stack', style: 'gap:2px' },
        h('label', { class: 'chk' }, withMine, 'проверить и текущий конфиг', newTag('cfg:mine')),
        h('span', { class: 'sm muted', text: 'Чтобы видеть, лучше или хуже ваш. Без него проверка короче на один конфиг, но сравнения не будет.' }))),
      h('div', { class: 'row' }, startBtn, h('span', { class: 'sm muted grow note-wide', text: 'Каждый сайт — до трёх раз. Обычно 1–3 минуты на конфиг.' }))),
    out);
  drawChips();

  async function start() {
    if (!CFG_DRAFT.length) { toast('Добавьте хотя бы один конфиг', { err: true }); return; }
    const hosts = sites.value.split(/[\s,;]+/).map((x) => x.trim().replace(/^[a-z]+:\/\//i, '').replace(/[/?#].*$/, '')).filter(Boolean);
    if (!hosts.length) { toast('Укажите сайты для проверки', { err: true }); return; }
    if (!await guarded(() => api('cfg_start', { configs: CFG_DRAFT, hosts, mine: withMine.checked }))) return;
    poll();
  }
  function poll() {
    pollTest(async () => {
      if (!out.isConnected) return false;
      const s = await api('cfg_get').catch(() => null);
      if (!s) return true;
      draw(s);
      return ['running', 'starting'].includes(s.state);
    }, 2000);
  }

  const score = (c) => c.skip ? -1 : c.opened;
  function draw(s) {
    last = s;
    const running = ['running', 'starting'].includes(s.state);
    startBtn.disabled = running || !!s.busy;
    // brought up to date in place (morph): cards and the table fill in while the check runs, nothing blinks
    if (s.busy && !running) { morphKids(out, [keyed(notice('info', 'Сейчас идёт подбор или другая проверка', 'Проверку конфигов можно запустить, когда она закончится.'), 'busy')]); return; }
    if (s.state === 'idle') { morphKids(out, []); return; }
    if (s.state === 'error') { morphKids(out, [keyed(notice('bad', 'Проверка не удалась', s.error || ''), 'error')]); return; }
    const all = s.configs || [];
    const mine = all.find((c) => c.mine);
    const theirs = all.filter((c) => !c.mine).sort((a, b) => score(b) - score(a) || (a.ms ?? 1e9) - (b.ms ?? 1e9));
    const hosts = (s.sites || []).map((x) => x.host);
    const total = hosts.length;
    const failed = (c) => hosts.filter((x) => c.per?.[x] && !(c.per[x].ok > 0 && c.per[x].ok === c.per[x].tries));
    const card = (c) => {
      const bad = failed(c);
      const why = (x) => `${x} (${c.per[x].reason || c.per[x].ok + ' из ' + c.per[x].tries})`;
      const better = mine && !c.mine && !c.skip && !mine.skip ? hosts.filter((x) => failed(mine).includes(x) && !bad.includes(x)) : [];
      const worse = mine && !c.mine && !c.skip && !mine.skip ? bad.filter((x) => !failed(mine).includes(x)) : [];
      const tag = c.mine || c.skip || !mine || mine.skip ? null
        : c.opened > mine.opened ? h('span', { class: 'chip ok', text: 'лучше вашего' }) : c.opened === mine.opened ? h('span', { class: 'chip', text: 'как ваш' }) : h('span', { class: 'chip bad', text: 'хуже вашего' });
      return h('div', { class: 'cfg-card' + (tag && c.opened > mine.opened ? ' best' : ''), 'data-key': (c.mine ? 'mine:' : 'cfg:') + c.name },
        h('div', { class: 'stack', style: 'gap:2px;min-width:0' },
          h('div', { class: 'row', style: 'gap:8px' }, h('b', { text: c.name }), tag),
          c.skip ? h('div', { class: 'sm', style: 'color:var(--warn)', text: c.skip }) : [
            // что не открылось: то, что хуже вашего, — отдельной красной строкой; общее с вашим — серым
            !bad.length ? h('div', { class: 'sm muted', text: 'Открылись все сайты' }) : null,
            worse.length ? h('div', { class: 'sm', style: 'color:var(--bad)', text: 'У вас открываются, а с ним нет: ' + worse.map(why).join(', ') }) : null,
            bad.length > worse.length ? h('div', { class: 'sm muted', text: (c.mine || !mine || mine.skip ? 'Не открылись: ' : 'Не открылись, как и у вас: ') + bad.filter((x) => !worse.includes(x)).map(why).join(', ') }) : null,
            better.length ? h('div', { class: 'sm', style: 'color:var(--ok)', text: 'Открылись, а у вас нет: ' + better.join(', ') }) : null],
          c.changes?.length ? h('div', { class: 'sm faint', text: 'Поправлено под этот роутер: ' + plural(c.changes.length, 'изменение', 'изменения', 'изменений'), title: c.changes.join('\n') }) : null,
          !c.mine && !c.skip && c.warnings ? h('div', { class: 'sm faint', text: `Проверка конфига: ${plural(c.warnings, 'замечание', 'замечания', 'замечаний')} — видны после применения на странице «Конфиг целиком»` }) : null),
        h('div', { class: 'row cfg-act' },
          c.skip ? null : h('div', { class: 'cfg-score' }, h('b', { text: `${c.opened}/${total}` }), h('span', { class: 'cfg-bar' }, h('i', { style: `width:${total ? Math.round(c.opened / total * 100) : 0}%` })),
            c.ms ? h('span', { class: 'sm faint', text: c.ms + ' мс' }) : null),
          !c.mine && !c.skip && c.text ? btn('Применить', () => apply(c), 'small' + (tag && c.opened > mine.opened ? ' primary' : ''), 'ok') : null,
          !c.mine && c.profiles?.length ? btn('Профили…', () => profiles(c), 'small') : null));
    };
    const table = () => h('div', { class: 'scroll', 'data-key': 'tbl' }, h('table', { class: 'tbl cfg-tbl' },
      h('thead', {}, h('tr', {}, h('th', { text: 'Сайт' }), h('th', { text: 'без обхода' }), [mine, ...theirs].filter(Boolean).map((c) => h('th', { text: c.name })))),
      h('tbody', {}, (s.sites || []).map((x) => h('tr', {}, h('td', { class: 'mono', text: x.host }),
        h('td', { class: 'sm ' + (x.baseline?.ok ? 'ok' : 'muted'), text: x.baseline?.ok ? 'открывается' : (x.baseline?.reason || '—') }),
        [mine, ...theirs].filter(Boolean).map((c) => {
          const p = c.per?.[x.host];
          if (!p) return h('td', { class: 'sm faint', text: c.skip ? 'не проверен' : '—' });
          const ok = p.ok > 0 && p.ok === p.tries;
          return h('td', { class: 'sm ' + (ok ? 'ok' : 'bad'), text: ok ? `${p.ok}/${p.tries}` + (p.ms ? ` · ${p.ms} мс` : '') : (p.reason || `${p.ok}/${p.tries}`) });
        }))))));
    morphKids(out, [
      keyed(podkopNote(s), 'podkop'),
      keyed(running ? h('section', { class: 'panel' }, h('div', { class: 'row' }, h('span', { class: 'spin' }), h('b', { text: 'Проверяю конфиги' }), h('span', { class: 'grow' }),
        h('span', { class: 'sm muted num', text: s.total ? `${s.done} из ${s.total}` : 'запуск…' }), btn('Остановить', () => api('test_stop'), 'small danger', 'stop')),
        s.current ? h('p', { class: 'sm muted', text: 'Сейчас: ' + s.current }) : null) : null, 'run'),
      keyed(all.length ? panel('Итог', h('span', { class: 'row' }, h('span', { class: 'sm muted', text: (s.finished ? fmtDate(s.finished) + ' · ' : '') + plural(total, 'сайт', 'сайта', 'сайтов') }),
        btn(detail ? 'Скрыть таблицу' : 'Подробно по сайтам', () => { detail = !detail; draw(last); }, 'small ghost')),
      h('div', { class: 'stack', style: 'gap:10px' }, ...theirs.map(card), mine ? card(mine) : null),
      detail ? table() : null,
      h('p', { class: 'sm faint', text: 'Сайт считается открытым, если открылся каждый раз. «Применить» ставит конфиг целиком: снимок, перезапуск с проверкой мониторинга и откат, если сайты перестанут открываться или вы не подтвердите за 3 минуты. «Профили…» — взять из конфига отдельный профиль, остальное у вас не меняется.', 'data-key': 'note' })) : null, 'result')]);
  }

  async function apply(c) {
    if (!await ask(`Поставить «${c.name}» вместо вашего конфига?\n\nПеред этим сохранится снимок. nfqws2 перезапустится с проверкой: если сайты мониторинга перестанут открываться или вы не подтвердите за 3 минуты, вернётся ваш конфиг. Вернуть вручную — «Отменить» в шапке или «История изменений».`, 'Поставить')) return;
    if (!await guarded(() => api('conf_save_raw', { content: c.text, force: true, note: 'чужой конфиг: ' + c.name }))) return;
    S.conf = null;
    await safeRestart();
    go(PAGES.raw[0]);
  }

  function profiles(c) {
    const label = (pt) => {
      const nm = pt.find((t) => t.startsWith('--name='));
      const f = pt.filter((t) => /^--filter-(tcp|udp|l7)=/.test(t)).map((t) => t.replace(/^--filter-/, '')).join(' ');
      const d = pt.filter((t) => t.startsWith('--lua-desync=')).map((t) => t.slice(13).split(':')[0]);
      // для кого: списки сайтов и адресов (у одинаковых по фильтру профилей отличаются именно они)
      const base = (t) => t.split('=')[1].split(/[,/]/).pop();
      const who = [...pt.filter((t) => /^--(hostlist|ipset|hostlist-auto)=/.test(t)).map(base), ...pt.filter((t) => /^--(hostlist|ipset)-exclude=/.test(t)).map((t) => 'кроме ' + base(t))];
      return (nm ? nm.slice(7) + ' · ' : '') + (f || 'без фильтра') + (who.length ? ' · ' + who.join(', ') : '') + (d.length ? ' → ' + [...new Set(d)].join(', ') : '');
    };
    const bg = modal(`Профили «${c.name}»`, h('div', { class: 'stack modal-b' },
      h('p', { class: 'sm muted', text: 'Возьмите профиль, который открыл нужный сайт: он добавится в ваши «свои профили» первым, остальное у вас не изменится.' }),
      ...c.profiles.map((pt, i) => h('div', { class: 'row', style: 'flex-wrap:nowrap' }, h('span', { class: 'mono sm grow', style: 'min-width:0;overflow-wrap:anywhere', text: `#${i + 1} ${label(pt)}` }),
        btn('Взять', () => { bg.close(); pasteProfile(pt.join('\n')); }, 'small')))));
  }

  poll();
}
