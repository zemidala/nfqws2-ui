'use strict';
// Interface: page: what the «learn» orchestrator has learned (approved mockup local/design/learn.html, variant A).
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ Что выучил роутер ============
// A row per site: the strategy the router takes now, how sure it is, successes / failures; a row opens to show every
// strategy of the profile, «Закрепить для сайта» and «Забыть». The module writes the file once a minute.

// hosts whose row is unfolded — kept outside the view: the page may be drawn again (state reload, the side rail)
const LEARN_OPEN = new Set();

async function viewLearn(main) {
  const out = h('div', { class: 'stack', style: 'gap:14px' }, skel(1, 4));
  main.append(
    h('div', { class: 'vh' }, h('h1', { text: 'Что выучил роутер' })),
    h('p', { class: 'muted dns-lead', text: 'Профили с обучением сами выбирают стратегию для каждого сайта: запоминают, какая открывала его, и после неудач переходят на следующую по успеху. Старые результаты со временем весят меньше. Обновляется раз в минуту.' }),
    out);
  const open = LEARN_OPEN;
  let data = null;

  const pct = (x) => Math.round(x * 100);
  const barCls = (x) => x >= 0.7 ? '' : x >= 0.45 ? 'mid' : 'low';
  const bar = (score) => h('span', { class: 'lbar' }, h('i', { class: barCls(score), style: `width:${pct(score)}%` }));
  const curOf = (it) => it.strategies.find((s) => s.n === it.cur) || it.strategies[0];
  const tried = (s) => s.ok + s.fail > 0;
  const lastFail = (it) => Math.max(0, ...it.strategies.filter((s) => s.fail > 0 && s.last).map((s) => s.last));
  const stratName = (s) => s.name || `стратегия ${s.n}`;

  async function forget(it) {
    const all = !it;
    if (!await ask(all ? 'Забыть всё, что выучил роутер?\n\nПрофили с обучением начнут выбирать стратегии заново.' : `Забыть ${it.host}?\n\nДля этого сайта роутер начнёт выбирать стратегию заново.`, all ? 'Забыть всё' : 'Забыть', 'danger')) return;
    const r = await guarded(() => api('learn_forget', all ? {} : { key: it.key, host: it.host }), all ? 'Забыто' : `${it.host}: забыто`);
    if (r) { data = r; draw(); }
  }

  // a profile only for this site with the strategy the router has settled on — as «Применить» in the pick
  async function pin(it) {
    const s = curOf(it);
    if (!s?.steps.length) { toast('Стратегии этого профиля не нашлись — профиль изменили?', { err: true }); return; }
    await loadConf();
    const cur = S.conf.vars.NFQWS_ARGS_CUSTOM.trim() ? splitParts(tokensOf(S.conf.vars.NFQWS_ARGS_CUSTOM)) : [];
    const part = ['--filter-tcp=443', '--filter-l7=tls', `--hostlist-domains=${it.host}`, '--payload=tls_client_hello', ...s.steps];
    if (!await ask(`Закрепить для ${it.host}: ${stratName(s)}?\n\nПоявится отдельный профиль только для этого сайта, первым (#1); остальные профили сдвинутся на один номер.\n\nПосле сохранения nfqws2 перезапустится с проверкой: если сайты перестанут открываться или вы не подтвердите за 3 минуты, всё вернётся как было.`, 'Закрепить')) return;
    if (!await saveVars({ NFQWS_ARGS_CUSTOM: joinParts([part, ...cur]) }, `профиль для ${it.host}: выбор обучения`)) return;
    await safeRestart();
    go('#/settings/p1');
  }

  function row(it) {
    const s = curOf(it);
    const isOpen = open.has(it.host);
    const lf = lastFail(it);
    const toggle = () => { if (open.has(it.host)) open.delete(it.host); else open.add(it.host); draw(); };
    const main = h('tr', { 'data-key': it.key + ':' + it.host },
      h('td', { class: 'nm' }, h('button', { type: 'button', class: 'lex', 'aria-expanded': String(isOpen), 'aria-label': (isOpen ? 'Свернуть ' : 'Все стратегии ') + it.host, onclick: toggle }, icon(isOpen ? 'down' : 'chev')), h('span', { class: 'mono', text: it.host })),
      // no profile has these strategies any more (changed or removed): the record waits, the module will not use it
      h('td', { class: 'mono sm c-cur', 'data-h': 'Сейчас', text: stratName(s) + (it.profile ? '' : ' · профиль изменён') }),
      h('td', { class: 'c-sure', 'data-h': 'Уверенность' }, bar(s.score), ' ', h('span', { class: 'sm', text: pct(s.score) + ' %' })),
      h('td', { class: 'num hide-narrow', text: `${s.ok} / ${s.fail}` }),   // of the strategy taken now, as in the mockup
      h('td', { class: 'sm muted hide-narrow', text: lf ? fmtDate(lf) : '—' }),
      h('td', { class: 'num hide-narrow' }, btn('Забыть', () => forget(it), 'small ghost')));
    if (!isOpen) return [main];
    const more = h('tr', { class: 'lmore', 'data-key': 'more:' + it.key + ':' + it.host }, h('td', { colspan: 6 },
      h('div', { class: 'lstrat' }, it.strategies.map((x) => {
        const cur = x.n === it.cur;
        return [h('span', { class: cur ? 'cur' : '', text: String(x.n) }),
          h('span', { class: 'mono' + (cur ? ' cur' : ''), text: stratName(x) + (cur ? ' · сейчас' : '') }),
          bar(x.score),
          h('span', { class: 'sm' + (tried(x) ? '' : ' muted'), text: tried(x) ? `${x.ok} / ${x.fail}` : 'не пробовалась' })];
      })),
      h('div', { class: 'row', style: 'margin-top:10px' },
        btn('Закрепить для сайта', () => pin(it), 'small', null, { disabled: !s.steps.length }),
        btn('Забыть', () => forget(it), 'small ghost'),
        h('span', { class: 'sm muted grow', text: `«Закрепить» — отдельный профиль только для ${it.host} с этой стратегией, как «Применить» в подборе.` }))));
    return [main, more];
  }

  function draw() {
    if (!data) return;
    const items = data.items || [];
    if (!items.length) {
      // profiles that could switch: those with circular
      const circ = (S.state.conf_profiles || []).filter((p) => (p.desync || []).some((d) => d.fn === 'circular'));
      morphKids(out, [keyed(panel(null, null,
        h('p', { text: 'Пока ничего не выучено.' }),
        h('p', { class: 'sm muted', text: 'Обучение работает в профилях, где вместо круговой смены стратегий (circular) стоит learn. Профиль с circular переводится одной кнопкой «Перевести на обучение» на его странице.' }),
        circ.length ? h('div', { class: 'row' }, circ.map((p) => btn(`#${p.index} «${profName(p)}»`, () => go('#/settings/p' + p.index), 'small', 'chev'))) : null,
        !data.module ? notice('warn', 'Модуля обучения на роутере нет', 'Файл nfqws-ui.lua не найден — переустановите nfqws2-ui.') : null), 'empty')]);
      return;
    }
    const profiles = [...new Set(items.map((x) => x.profile).filter(Boolean))].map((n) => { const p = prof(n); return `#${n}` + (p ? ` «${profName(p)}»` : ''); });
    morphKids(out, [keyed(h('section', { class: 'panel' },
      h('div', { class: 'row', style: 'margin-bottom:6px' },
        h('span', { class: 'sm muted grow' }, plural(items.length, 'сайт', 'сайта', 'сайтов'), profiles.length ? ' · ' + (profiles.length > 1 ? 'профили ' : 'профиль ') + profiles.join(', ') : '',
          data.updated ? ' · обновлено ' + fmtAgo(Math.floor(Date.now() / 1000) - data.updated) : ''),
        btn('Забыть всё', () => forget(null), 'small danger')),
      h('div', { class: 'scroll', 'data-whole': '' }, h('table', { class: 'tbl learn-tbl' },
        h('thead', {}, h('tr', {}, h('th', { text: 'Сайт' }), h('th', { text: 'Сейчас' }), h('th', { text: 'Уверенность' }),
          h('th', { class: 'num hide-narrow', text: 'Удачи / неудачи' }), h('th', { class: 'hide-narrow', text: 'Последняя неудача' }), h('th', { class: 'hide-narrow' }))),
        h('tbody', {}, items.flatMap(row))))), 'tbl')]);
  }

  async function load() {
    const r = await api('learn_get').catch((e) => { morphKids(out, [keyed(notice('bad', 'Не удалось получить', e.message), 'err')]); return null; });
    if (r) { data = r; draw(); }
  }
  await load();
  // the module writes once a minute: refresh while the page is open
  const t = setInterval(() => { if (!out.isConnected) { clearInterval(t); return; } load(); }, 60000);
}
