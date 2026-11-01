'use strict';
// Interface: page: the help report.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ Отчёт для помощи ============

async function paneReport(content) {
  const sites = h('input', { class: 'input mono grow', placeholder: 'до трёх сайтов через пробел — необязательно', autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'Сайты для проверки' });
  const hide = h('input', { type: 'checkbox', id: 'rep-hide' });
  const ta = h('textarea', { class: 'input mono', rows: 22, readonly: true, style: 'width:100%;resize:vertical;font-size:var(--fs-xs)', hidden: true });
  // the finished report is a block of its own: heading with two equal small buttons, a note, the text
  const acts = h('section', { class: 'panel', hidden: true },
    h('div', { class: 'panel-h' }, h('h2', { text: 'Отчёт' }), h('span', { class: 'grow' }),
      copyBtn(() => ta.value, 'Копировать', 'small'),
      btn('Скачать .txt', () => { const a = h('a', { href: URL.createObjectURL(new Blob([ta.value], { type: 'text/plain' })), download: 'nfqws2-report.txt' }); document.body.append(a); a.click(); a.remove(); }, 'small', 'download')),
    h('p', { class: 'sm muted', text: 'Перечитайте перед отправкой: отчёт никуда не уходит сам, его отправляете вы.' }));
  const make = btn('Собрать отчёт', async () => {
    make.disabled = true;
    const r = await guarded(() => api('report', { sites: sites.value.split(/[\s,]+/).filter(Boolean).slice(0, 3), hide: hide.checked, build: BUILD.split('-').pop() }));
    make.disabled = false;
    if (!r) return;
    ta.value = r.text; ta.hidden = false; acts.hidden = false;
    acts.scrollIntoView({ block: 'start', behavior: 'smooth' });
  }, 'primary', 'play');
  content.append(
    h('div', { class: 'vh' }, h('h1', { text: 'Отчёт для помощи' })),
    panel(null, null,
      h('p', { class: 'sm muted', text: 'Текст, который можно приложить к вопросу в чате или в issue: версии, состояние сервиса, замечания к конфигу, профили, названия списков, мониторинг, итоги подборов. Паролей, токена Telegram, внешнего адреса и содержимого списков в нём нет.' }),
      h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Проверить сайты' }), sites),
      h('div', { class: 'frow' }, h('span', { class: 'lbl', text: 'Имена сайтов' }), h('label', { class: 'chk' }, hide, 'заменить на «сайт-1», «сайт-2»…')),
      h('div', { class: 'row' }, make)),
    acts);
  acts.append(ta);
}
