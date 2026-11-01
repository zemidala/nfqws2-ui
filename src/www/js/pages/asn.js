'use strict';
// Interface: page: a list by ASN.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ Список по ASN ============

const ASN_PRESETS = [[24940, 'Hetzner'], [16276, 'OVH'], [14061, 'DigitalOcean'], [51167, 'Contabo'], [63949, 'Akamai (Linode)'], [13335, 'Cloudflare'], [16509, 'Amazon AWS'], [20473, 'Vultr']];

async function viewAsn(main) {
  const saved = (await api('asn_get').catch(() => ({ items: [] }))).items;
  const q = h('input', { class: 'input mono grow', placeholder: 'AS24940 или имя сайта', autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'Номер AS или сайт' });
  const out = h('div', { class: 'stack' });
  const look = async (v) => {
    if (v) q.value = v;
    if (!q.value.trim()) return;
    out.replaceChildren(spinner('Спрашиваю RIPEstat…'));
    const r = await guarded(() => api('asn_lookup', { q: q.value.trim() }));
    if (!r) { out.replaceChildren(); return; }
    const auto = h('input', { type: 'checkbox', id: 'asn-auto', checked: true });
    out.replaceChildren(panel(`AS${r.asn} · ${r.holder || 'владелец не указан'}`, h('span', { class: 'sm muted num', text: plural(r.count, 'префикс', 'префикса', 'префиксов') }),
      r.via ? h('p', { class: 'sm muted', text: r.via }) : null,
      r.count ? h('pre', { class: 'box sm', text: r.sample.join('\n') + (r.count > r.sample.length ? `\n… и ещё ${r.count - r.sample.length}` : '') }) : notice('warn', 'У этой сети нет анонсированных префиксов', 'Проверьте номер AS.'),
      r.count > 3000 ? notice('warn', 'Очень большая сеть', 'Такой список затронет множество посторонних сайтов и сервисов — подумайте, нужен ли он целиком.') : null,
      h('p', { class: 'sm faint', text: (r.v6 ? 'IPv4 и IPv6.' : 'Только IPv4: IPv6 в конфиге выключен.') + ' Вложенные префиксы убраны.' }),
      r.count ? h('div', { class: 'row' }, h('label', { class: 'chk' }, auto, 'обновлять раз в сутки'), h('span', { class: 'grow' }),
        btn(r.exists ? `Обновить ${r.name}` : `Создать список ${r.name}`, async () => {
          const c = await guarded(() => api('asn_create', { asn: r.asn, auto: auto.checked }), 'Готово');
          if (!c) return;
          await loadState().catch(() => {});
          go('#/sites/' + encodeURIComponent(c.name));
        }, 'primary', 'ok')) : null));
  };
  main.append(
    h('div', { class: 'vh' }, h('h1', { text: 'Список по ASN' })),
    panel(null, null,
      h('p', { class: 'sm muted', text: 'Собирает список IP-адресов целой сети (хостинга, облака) по номеру её автономной системы. Нужен, когда блокировка идёт по адресам хостинга, а не по имени сайта. Данные — RIPEstat.' }),
      h('form', { class: 'row', onsubmit: (e) => { e.preventDefault(); look(); } }, q, h('button', { class: 'btn primary', type: 'submit' }, 'Найти', icon('arrow'))),
      h('div', { class: 'recent' }, ASN_PRESETS.map(([n, t]) => h('button', { class: 'chip', type: 'button', text: t, title: 'AS' + n, onclick: () => look('AS' + n) }))),
      h('p', { class: 'sm faint', text: 'Можно ввести имя сайта — роутер найдёт, в чьей сети он живёт. Готовый список подключается к профилю на странице «Профили» (поле «Списки IP»).' })),
    out,
    saved.length ? panel('Собранные списки', null, saved.map((x) => h('div', { class: 'act' }, levelIcon(x.error ? 'warning' : 'ok'),
      h('div', { class: 'item-main' }, h('span', {}, h('a', { class: 'mono', href: '#/sites/' + encodeURIComponent(x.list), text: x.list }), h('span', { class: 'sm faint', text: ` · AS${x.asn} ${x.holder}` })),
        h('span', { class: 'sm muted', text: `${plural(x.count, 'префикс', 'префикса', 'префиксов')} · ${x.auto ? 'обновляется раз в сутки' : 'не обновляется'} · ${fmtDate(x.last)}` + (x.error ? ` · ${x.error}` : '') })),
      btn('Обновить', () => look('AS' + x.asn), 'small ghost', 'refresh'),
      btn(x.auto ? 'Не обновлять' : 'Обновлять', async () => { if (await guarded(() => api('asn_set', { asn: x.asn, auto: !x.auto }))) route(true); }, 'small ghost')))) : null);
}
