'use strict';
// Interface: page: bypass per device.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ---------- Devices ----------
// Who in the home network goes through nfqws2. A device is known by MAC; every listed device keeps its own
// switch, and «new devices» decides only for the ones the router has not seen yet. OpenWrt only: on Keenetic
// the firmware access policy does the same.
// Redraw the same page with fresh data and stay where the user was (route() scrolls to the top for page changes)
async function rerouteHere() {
  const y = window.scrollY;
  await route(true);
  window.scrollTo(0, y);
}

async function viewDevices(main) {
  const data = await api('devices_get');
  main.append(h('div', { class: 'vh' }, h('h1', { text: 'Устройства' })));
  if (data.keenetic) {
    main.append(panel(null, null,
      h('p', { text: 'На Keenetic устройства выбираются в самой прошивке — политикой доступа. Создайте политику в веб-интерфейсе Keenetic, добавьте в неё нужные устройства и впишите её имя в конфиг nfqws2 (POLICY_NAME). Можно и наоборот: обходить всех, кроме устройств политики (POLICY_EXCLUDE=1).' }),
      h('p', { class: 'sm muted', text: 'Проверки самого роутера (мониторинг, «Проверить сайт») интерфейс и тогда пропускает через nfqws2.' }),
      h('div', { class: 'row' }, btn('Конфиг целиком', () => go('#/settings/raw'), 'small'))));
    return;
  }
  let newOn = data.new_on;
  const items = data.items.map((x) => ({ ...x }));
  const orig = JSON.stringify([newOn, items.map((x) => [x.mac, x.on, x.name])]);
  const bar = h('div', { class: 'savebar', hidden: true });
  const tbody = h('tbody');
  const cnt = h('span', { class: 'dev-cnt' });
  const sync = () => {
    const now = JSON.stringify([newOn, items.map((x) => [x.mac, x.on, x.name])]);
    setDirty(now !== orig);
    bar.hidden = now === orig;
    const on = items.filter((x) => x.on).length;
    cnt.replaceChildren('С обходом ', h('b', { text: String(on) }), ' из ', h('b', { text: String(items.length) }));
  };
  const toggle = (on, label, onchange) => {
    const b = h('button', { class: 'sw', type: 'button', role: 'switch', 'aria-checked': String(on), 'aria-label': label,
      onclick: () => { const v = b.getAttribute('aria-checked') !== 'true'; b.setAttribute('aria-checked', String(v)); onchange(v); } });
    return b;
  };
  // sorting by a column header; the choice is a per-browser convenience
  const SORT_KEY = 'nfqws-ui-dev-sort';
  let sort = { key: 'name', dir: 1 };
  try { sort = { ...sort, ...JSON.parse(localStorage.getItem(SORT_KEY) || '{}') }; } catch { /* no storage */ }
  const label = (x) => (x.name || x.mac).toLowerCase();
  const ipNum = (ip) => ip ? ip.split('.').reduce((a, n) => a * 256 + Number(n), 0) : Infinity;
  const CMP = {
    name: (a, b) => label(a).localeCompare(label(b), 'ru'),
    mac: (a, b) => a.mac.localeCompare(b.mac),
    ip: (a, b) => ipNum(a.ip) - ipNum(b.ip),
    online: (a, b) => b.online - a.online,
    on: (a, b) => b.on - a.on,
  };
  const sorted = () => [...items].sort((a, b) => sort.dir * (CMP[sort.key] || CMP.name)(a, b) || CMP.name(a, b));
  const heads = [['name', 'Устройство', ''], ['mac', 'MAC', 'hide-narrow'], ['ip', 'Адрес', 'hide-narrow'], ['online', 'Сейчас', 'hide-narrow'], ['on', 'Обход', '']];
  const thead = h('thead');
  const drawHead = () => thead.replaceChildren(h('tr', {}, heads.map(([k, t, cls]) => h('th', { class: cls, 'aria-sort': sort.key === k ? (sort.dir > 0 ? 'ascending' : 'descending') : 'none' },
    h('button', { class: 'th-sort', type: 'button', title: 'Сортировать по столбцу «' + t + '»', onclick: () => {
      sort = { key: k, dir: sort.key === k ? -sort.dir : 1 };
      try { localStorage.setItem(SORT_KEY, JSON.stringify(sort)); } catch { /* no storage */ }
      drawHead(); draw();
    } }, t, sort.key === k ? icon(sort.dir > 0 ? 'up' : 'down') : null)))));
  const draw = () => {
    tbody.replaceChildren(...sorted().map((x) => {
      const tr = h('tr', { class: x.on ? '' : 'off' },
        h('td', {}, h('div', { class: 'dev-name' }, h('span', { class: 'dev-dot' + (x.online ? '' : ' gone'), title: x.online ? 'в сети' : 'сейчас не в сети' }),
          h('span', { class: 'dev-n', text: x.name || x.mac, title: x.name || x.mac }), x.manual ? h('span', { class: 'dev-tag', text: 'вписан вручную' }) : null)),
        h('td', { class: 'mono sm hide-narrow', text: x.mac }),
        h('td', { class: 'mono sm hide-narrow ' + (x.ip ? '' : 'muted'), text: x.ip || '—' }),
        h('td', { class: 'sm muted hide-narrow', text: x.online ? 'в сети' : 'нет' }),
        // the remove button keeps its place empty in other rows, so the switches stay in one column
        h('td', {}, h('div', { class: 'dev-act' }, toggle(x.on, 'Обход для ' + (x.name || x.mac), (v) => { x.on = v; tr.classList.toggle('off', !v); sync(); }),
          // any device not online now can be forgotten (phones change MACs; the list must not only grow);
          // it comes back on its own when it shows up again
          x.manual || !x.online ? btn('', () => { items.splice(items.indexOf(x), 1); draw(); sync(); }, 'small ghost icon slot', 'x', { title: x.manual ? 'Убрать из списка' : 'Забыть: устройство снова появится в списке, когда будет в сети', 'aria-label': 'Убрать ' + x.mac }) : h('span', { class: 'slot' }))));
      return tr;
    }));
  };
  const mac = h('input', { class: 'input mono grow', placeholder: 'MAC, например 3c:22:fb:00:10:9e', autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'MAC-адрес' });
  const add = () => {
    const v = mac.value.trim().toLowerCase().replace(/-/g, ':');
    if (!/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/.test(v)) { toast('Не похоже на MAC-адрес: шесть пар цифр 0–9 и букв a–f через двоеточие', { err: true }); return; }
    if (items.some((x) => x.mac === v)) { toast('Это устройство уже в списке'); return; }
    items.push({ mac: v, name: '', ip: '', online: false, on: newOn, manual: true });
    mac.value = '';
    draw(); sync();
  };
  const save = async () => {
    const list = Object.fromEntries(items.map((x) => [x.mac, { on: x.on, name: x.name, manual: x.manual }]));
    const r = await guarded(() => api('devices_set', { new_on: newOn, list }));
    if (!r) return;
    setDirty(false);
    toast(r.ok === false ? 'Сохранено, но правила поставить не удалось — подробности в «Отчёте для помощи»' : 'Сохранено. Новые соединения пойдут по-новому, уже открытые — как шли');
    rerouteHere();
  };
  bar.append(h('span', { text: 'Изменён выбор устройств' }), btn('Отменить', () => { setDirty(false); rerouteHere(); }, 'ghost'), btn('Сохранить', save, 'primary'));
  drawHead(); draw(); sync();
  main.append(
    h('p', { class: 'muted dev-lead', text: 'Кто в домашней сети идёт через nfqws2. Устройство узнаётся по MAC-адресу, поэтому смена его IP ничего не ломает. Проверки самого роутера всегда идут через nfqws2.' }),
    data.active && data.ok === false && data.running ? notice('warn', 'Выбор устройств сейчас не действует', 'Правила не стоят — все устройства идут через nfqws2. Обычно так бывает, если nfqws2 перезапустили не из интерфейса; правила вернутся сами в течение 10 минут.',
      btn('Вернуть сейчас', async () => { if (await guarded(() => api('devices_get'))) rerouteHere(); }, 'small')) : null,
    !data.active ? notice('info', 'Сейчас обход действует для всей сети', 'Выключите обход у устройства, чтобы оно ходило напрямую, мимо nfqws2.') : null,
    panel(null, null, h('div', { class: 'row' },
      h('label', { class: 'swl' }, toggle(newOn, 'Новые устройства — с обходом', (v) => { newOn = v; sync(); }), 'Новые устройства — с обходом'),
      h('span', { class: 'grow' }), cnt),
      h('p', { class: 'sm muted', text: 'Касается только устройств, которых ещё нет в списке ниже: у каждого из списка свой переключатель.' })),
    panel(null, null,
      items.length ? h('div', { class: 'scroll' }, h('table', { class: 'tbl devs' }, thead, tbody)) : h('p', { class: 'muted', text: 'Роутер пока не видит устройств: нет аренд DHCP и соседей в сети. Устройство можно вписать по MAC.' }),
      h('form', { class: 'row', onsubmit: (e) => { e.preventDefault(); add(); } }, mac, h('button', { class: 'btn small', type: 'submit' }, 'Добавить вручную'))),
    bar);
}
