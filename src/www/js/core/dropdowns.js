'use strict';
// Interface: own dropdowns over a hidden native select.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ dropdowns: own button and list over a hidden native select ============
// Every <select> that lands in the page gets a button in its place (same classes, so layout rules keep working)
// and stays in the DOM hidden: it still holds the value, fires change, and code reading .value, .selectedIndex
// or .options does not change. One shared list is shown over the page; on a phone it is a sheet at the bottom.

const DD_SEARCH_FROM = 12;   // options; longer lists get a search field
const ddPhone = window.matchMedia('(max-width: 759px)');
let ddOpen = null;   // { sel, btn, pop, back }

function ddSync(sel) {
  const b = sel._dd;
  if (!b) return;
  const o = sel.options[sel.selectedIndex];
  b.querySelector('.dd-t').textContent = o ? o.text : '';
  // sizer: every option text in one grid cell, so the button is as wide as its longest option and does not jump
  b.querySelector('.dd-sz').replaceChildren(...[...sel.options].map((x) => h('span', { text: x.text })));
  b.disabled = sel.disabled;
  b.hidden = sel.hidden;
  b.title = sel.title || (o && b.scrollWidth > b.clientWidth ? o.text : '');
}

function ddEnhance(sel) {
  if (sel._dd || !sel.isConnected) return;
  const b = h('button', { type: 'button', class: (sel.className + ' dd-btn').trim(), 'aria-haspopup': 'listbox', 'aria-expanded': 'false',
    'aria-label': sel.getAttribute('aria-label') || sel.labels?.[0]?.textContent || null, style: sel.getAttribute('style') },
  h('span', { class: 'dd-in' }, h('span', { class: 'dd-t' }), h('span', { class: 'dd-sz', 'aria-hidden': 'true' })), h('span', { class: 'dd-arr', 'aria-hidden': 'true' }));
  if (sel.dataset.fk) { b.dataset.fk = sel.dataset.fk; delete sel.dataset.fk; }   // keepFocus() finds the button
  sel._dd = b;
  sel.classList.add('dd-native');
  sel.before(b);
  b.addEventListener('click', () => (ddOpen?.sel === sel ? ddClose() : ddShow(sel)));
  b.addEventListener('keydown', (e) => {
    if (ddOpen?.sel === sel) return ddKey(e);
    if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) { e.preventDefault(); ddShow(sel); }
  });
  b.addEventListener('focus', () => sel.dispatchEvent(new FocusEvent('focus')));
  b.addEventListener('blur', () => sel.dispatchEvent(new FocusEvent('blur')));
  new MutationObserver(() => ddSync(sel)).observe(sel, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['disabled', 'hidden', 'title'] });
  sel.addEventListener('change', () => ddSync(sel));
  ddSync(sel);
}

// Value set from code does not fire events: keep the button text in step
for (const p of ['value', 'selectedIndex']) {
  const d = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, p);
  Object.defineProperty(HTMLSelectElement.prototype, p, { ...d, set(v) { d.set.call(this, v); ddSync(this); } });
}

const ddScan = (root = document) => root.querySelectorAll('select:not(.dd-native)').forEach(ddEnhance);
new MutationObserver(() => ddScan()).observe(document.documentElement, { childList: true, subtree: true });

// On a phone a table of four or more columns shows each row as a card: the column names become labels (data-th,
// drawn by CSS), so nothing scrolls sideways. Tables with a phone layout of their own are left alone.
const CARD_SKIP = '.mon-tbl, .conn-tbl, .devs, .cfg-tbl, .dnsx-tbl, .learn-tbl';
function cardTable(t) {
  if (t.matches(CARD_SKIP)) return;
  const names = [...t.querySelectorAll(':scope > thead th')].map((th) => th.textContent.trim());
  if (names.length < 4) return;
  t.classList.add('cardy');
  for (const tr of t.querySelectorAll(':scope > tbody > tr')) {
    [...tr.children].forEach((td, i) => { if (td.colSpan === 1 && names[i]) td.dataset.th = names[i]; });
  }
}
// A heading row (heading + date, count or buttons) that does not fit in one line gets .wrapped: the heading takes the
// whole first line and the rest starts from the left edge under it, not pushed to the right on a line of its own.
// Measured without the class each time, so a wider window brings the row back to one line.
const WRAP_ROWS = '.panel-h, .blk-h, .vh, main .row, .conn-sum';
function markWraps() {
  for (const row of document.querySelectorAll(WRAP_ROWS)) {
    if (!row.firstElementChild || row.children.length < 2 || !row.offsetParent) continue;
    row.classList.remove('wrapped');
    const first = row.firstElementChild;
    const fr = first.getBoundingClientRect();
    // something starts below the first item (not just sits lower in the same line), or the heading broke in two
    const lh = parseFloat(getComputedStyle(first).lineHeight) || 0;
    const wrapped = [...row.children].some((k) => k.offsetParent && k.getBoundingClientRect().top >= fr.bottom - 2)
      || (lh > 0 && /^H[1-3]$/.test(first.tagName) && first.getBoundingClientRect().height > lh * 1.5);
    row.classList.toggle('wrapped', wrapped);
  }
}
let cardQueued = false;
new MutationObserver(() => {
  if (cardQueued) return;
  cardQueued = true;
  requestAnimationFrame(() => { cardQueued = false; document.querySelectorAll('table.tbl').forEach(cardTable); markWraps(); });
}).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden', 'open'] });   // a block shown later is measured too
addEventListener('resize', () => requestAnimationFrame(markWraps));

function ddShow(sel) {
  ddClose();
  const b = sel._dd;
  const phone = ddPhone.matches;
  const opts = [...sel.options];
  const pop = h('div', { class: 'dd-pop' + (phone ? ' sheet' : ''), role: 'listbox', 'aria-label': b.getAttribute('aria-label') });
  const back = h('div', { class: 'dd-back' + (phone ? ' on' : ''), onclick: () => ddClose() });
  ddOpen = { sel, btn: b, pop, back, act: Math.max(0, sel.selectedIndex), q: '', at: performance.now() };
  if (phone) pop.append(h('div', { class: 'dd-sh' }, h('b', { text: b.getAttribute('aria-label') || '' }), btn('Готово', () => ddClose(true), 'small ghost')));
  if (opts.length > DD_SEARCH_FROM) {
    const q = h('input', { class: 'input dd-q', placeholder: 'Найти…', 'aria-label': 'Найти', autocapitalize: 'off', spellcheck: 'false',
      oninput: () => { ddOpen.q = q.value.trim().toLowerCase(); ddOpen.act = ddVisible()[0] ?? -1; ddDraw(); }, onkeydown: ddKey });
    pop.append(h('div', { class: 'dd-search' }, q));
  }
  pop.append(h('div', { class: 'dd-opts' }));
  document.body.append(back, pop);
  b.setAttribute('aria-expanded', 'true');
  b.classList.add('dd-on');
  ddDraw();
  ddPlace();
  (pop.querySelector('.dd-q') || (phone ? null : b))?.focus({ preventScroll: true });
}

// indices of options matching the search, without the empty placeholder of «add…» menus
function ddVisible() {
  const { sel, q } = ddOpen;
  return [...sel.options].map((o, i) => i).filter((i) => {
    const o = sel.options[i];
    if (!q) return true;
    return o.value !== '' && o.text.toLowerCase().includes(q);
  });
}

function ddDraw() {
  const { sel, pop, act } = ddOpen;
  const box = pop.querySelector('.dd-opts');
  const items = [];
  let group = null;
  for (const i of ddVisible()) {
    const o = sel.options[i];
    const g = o.parentElement.tagName === 'OPTGROUP' ? o.parentElement.label : null;
    if (g && g !== group) items.push(h('div', { class: 'dd-g', text: g }));
    group = g;
    items.push(h('div', { class: 'dd-o' + (i === act ? ' act' : ''), role: 'option', 'aria-selected': String(i === sel.selectedIndex), 'aria-disabled': o.disabled ? 'true' : null,
      'data-i': i, text: o.text, onmousemove: () => { if (ddOpen.act !== i) { ddOpen.act = i; box.querySelectorAll('.dd-o').forEach((x) => x.classList.toggle('act', +x.dataset.i === i)); } },
      onclick: () => ddPick(i) }));
  }
  box.replaceChildren(...(items.length ? items : [h('div', { class: 'dd-none', text: 'Ничего не найдено' })]));
  box.querySelector('.dd-o.act')?.scrollIntoView({ block: 'nearest' });
}

function ddPlace() {
  const { btn: b, pop } = ddOpen;
  if (pop.classList.contains('sheet')) return;
  const r = b.getBoundingClientRect();
  const below = innerHeight - r.bottom - 8, above = r.top - 8;
  const up = below < Math.min(pop.scrollHeight, 300) && above > below;
  pop.style.minWidth = r.width + 'px';
  pop.style.maxHeight = Math.max(120, Math.min(340, up ? above : below)) + 'px';
  const w = pop.offsetWidth;
  pop.style.left = Math.max(8, Math.min(r.left, innerWidth - w - 8)) + 'px';
  pop.style.top = up ? '' : r.bottom + 4 + 'px';
  pop.style.bottom = up ? innerHeight - r.top + 4 + 'px' : '';
}

function ddPick(i) {
  const { sel } = ddOpen;
  const o = sel.options[i];
  if (!o || o.disabled) return;
  ddClose(true);
  if (sel.selectedIndex === i) return;
  sel.selectedIndex = i;
  sel.dispatchEvent(new Event('input', { bubbles: true }));
  sel.dispatchEvent(new Event('change', { bubbles: true }));
}

function ddClose(refocus = false) {
  if (!ddOpen) return;
  const { btn: b, pop, back } = ddOpen;
  ddOpen = null;
  pop.remove();
  back.remove();
  b.setAttribute('aria-expanded', 'false');
  b.classList.remove('dd-on');
  if (refocus && b.isConnected) b.focus({ preventScroll: true });
}

function ddKey(e) {
  if (!ddOpen) return;
  const vis = ddVisible().filter((i) => !ddOpen.sel.options[i].disabled);
  const k = vis.indexOf(ddOpen.act);
  const move = (to) => { e.preventDefault(); ddOpen.act = vis[Math.max(0, Math.min(vis.length - 1, to))]; ddDraw(); };
  if (e.key === 'Escape') { e.preventDefault(); ddClose(true); }
  else if (e.key === 'ArrowDown') move(k + 1);
  else if (e.key === 'ArrowUp') move(k - 1);
  else if (e.key === 'Home') move(0);
  else if (e.key === 'End') move(vis.length - 1);
  else if (e.key === 'PageDown') move(k + 8);
  else if (e.key === 'PageUp') move(k - 8);
  else if (e.key === 'Enter' || (e.key === ' ' && e.target.tagName !== 'INPUT')) { e.preventDefault(); if (ddOpen.act >= 0) ddPick(ddOpen.act); }
  else if (e.key === 'Tab') ddClose();
  else if (e.key.length === 1 && e.target.tagName !== 'INPUT') {
    // jump to the next option starting with the typed letter
    const ch = e.key.toLowerCase();
    const opts = ddOpen.sel.options;
    const next = [...vis.slice(k + 1), ...vis.slice(0, k + 1)].find((i) => opts[i].text.trim().toLowerCase().startsWith(ch));
    if (next !== undefined) move(vis.indexOf(next));
  }
}

document.addEventListener('pointerdown', (e) => { if (ddOpen && !ddOpen.pop.contains(e.target) && !ddOpen.btn.contains(e.target) && e.target !== ddOpen.back) ddClose(); }, true);
// the list is fixed to the window: when the page scrolls under it, close it rather than leave it hanging.
// A sheet covers the page, and a scroll right after opening is the one that brought the button into view.
document.addEventListener('scroll', (e) => {
  if (ddOpen && !ddOpen.pop.contains(e.target) && !ddOpen.pop.classList.contains('sheet') && performance.now() - ddOpen.at > 200) ddClose();
}, true);
window.addEventListener('resize', () => { if (ddOpen && !ddOpen.pop.classList.contains('sheet')) ddClose(); });
window.addEventListener('hashchange', () => ddClose());

// Перерисовка с сохранением фокуса и курсора в поле с data-fk
function keepFocus(fn) {
  const a = document.activeElement;
  const key = a?.dataset?.fk;
  const sel = key && 'selectionStart' in a ? [a.selectionStart, a.selectionEnd] : null;
  fn();
  if (!key) return;
  ddScan();   // new selects get their buttons now, not on the next observer tick
  const b = document.querySelector(`[data-fk="${key}"]`);
  if (!b) return;
  b.focus({ preventScroll: true });
  if (sel && b.setSelectionRange) try { b.setSelectionRange(...sel); } catch { /* не поле ввода */ }
}
