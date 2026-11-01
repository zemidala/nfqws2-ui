'use strict';
// Interface start. Stays app.js and the last script: a page opened before an update looks for «app.js?v=»
// in index.html to tell a newer build is on the router.

// ============ старт ============

async function start() {
  try {
    const s = await api('session');
    S.auth = s.auth;
    S.authEnabled = s.auth_enabled;
  } catch (e) {
    document.getElementById('app').replaceChildren(h('div', { class: 'login' }, notice('bad', 'Роутер не отвечает', e.message)));
    return;
  }
  if (!S.auth) { renderLogin(); return; }
  S.check ||= createCheck();
  renderShell();
  SIDE.root = null;
  route(true);
  layoutNote();
  setTimeout(autoUpdateCheck, 3000);
  setTimeout(checkFresh, 1500);
}

setInterval(() => {
  // во время проверки после перезапуска состояние опрашивает плашка — лёгким pending_get
  if (S.auth && !pendingActive() && document.visibilityState === 'visible' && !document.querySelector('.modal-bg') && !document.activeElement?.matches('textarea, input, select')) {
    loadState().catch(() => {});
  }
}, 30000);

// Own tooltips instead of the browser's: one shared handler takes the title attribute of the element under the
// pointer (or focused from the keyboard) and shows it in the project's style. The title moves to data-tip so the
// browser does not show its own on top; code that sets title later is picked up on the next hover. On touch
// screens — a long press. Elements with a visible label keep it as is; a bare icon gets the text as aria-label.
const TIP = { el: null, target: null, timer: 0 };
function tipText(t) {
  if (t.hasAttribute('title')) {
    const s = t.getAttribute('title');
    t.removeAttribute('title');
    if (s) {
      t.dataset.tip = s;
      if (!t.getAttribute('aria-label') && !t.textContent.trim()) t.setAttribute('aria-label', s);
    } else delete t.dataset.tip;   // title = '' — no tooltip any more
  }
  return t.dataset.tip || '';
}
// code changes titles on the fly (a copy button says «Скопировано» while hovered): move them at once, so the
// browser does not show its own tooltip, and refresh the one on screen
new MutationObserver((muts) => {
  for (const m of muts) {
    // a native select is hidden behind its own dropdown button, which takes the title from it (ddSync)
    if (!m.target.hasAttribute('title') || m.target.tagName === 'SELECT') continue;
    tipText(m.target);
    if (m.target === TIP.target && TIP.el) {
      if (m.target.dataset.tip) TIP.el.textContent = m.target.dataset.tip; else tipHide();
    }
  }
}).observe(document.documentElement, { attributes: true, attributeFilter: ['title'], subtree: true });
function tipShow(t) {
  const text = tipText(t);
  if (!text || !t.isConnected) return;
  TIP.el ||= document.body.appendChild(h('div', { class: 'ui-tip', role: 'tooltip', id: 'ui-tip' }));
  const tip = TIP.el;
  tip.textContent = text;
  tip.hidden = false;
  tip.classList.remove('up');
  const r = t.getBoundingClientRect();
  const W = document.documentElement.clientWidth, H = innerHeight, w = tip.offsetWidth, hh = tip.offsetHeight;
  const cx = r.left + r.width / 2;
  const left = Math.max(8, Math.min(W - w - 8, cx - w / 2));
  let top = r.bottom + 9;
  if (top + hh > H - 8 && r.top - hh - 9 > 8) { top = r.top - hh - 9; tip.classList.add('up'); }
  tip.style.left = left + 'px';
  tip.style.top = top + 'px';
  tip.style.setProperty('--ax', Math.max(12, Math.min(w - 12, cx - left)) + 'px');
  TIP.target = t;
  t.setAttribute('aria-describedby', 'ui-tip');
}
function tipHide() {
  clearTimeout(TIP.timer);
  if (TIP.el) TIP.el.hidden = true;
  TIP.target?.removeAttribute('aria-describedby');
  TIP.target = null;
}
const tipFor = (e) => e.target.closest?.('[title]:not(select), [data-tip]');
document.addEventListener('pointerover', (e) => {
  if (e.pointerType === 'touch') return;
  const t = tipFor(e);
  if (t === TIP.target) return;
  tipHide();
  if (!t) return;
  tipText(t);   // at once, so the browser never shows its own
  TIP.timer = setTimeout(() => tipShow(t), 400);
});
document.addEventListener('pointerout', (e) => { if (TIP.target || TIP.timer) { const to = e.relatedTarget; if (!to || !TIP.target?.contains(to)) tipHide(); } });
document.addEventListener('focusin', (e) => { const t = tipFor(e); if (t && t.matches(':focus-visible')) tipShow(t); });
document.addEventListener('focusout', tipHide);
document.addEventListener('pointerdown', (e) => {
  tipHide();
  if (e.pointerType !== 'touch') return;
  const t = tipFor(e);
  if (t) TIP.timer = setTimeout(() => tipShow(t), 500);
});
document.addEventListener('pointerup', (e) => { if (e.pointerType === 'touch' && !TIP.target) clearTimeout(TIP.timer); });
addEventListener('scroll', tipHide, true);
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') tipHide(); });

start();

// Один общий обработчик: клик мимо выпадающего меню закрывает все открытые меню
document.addEventListener('click', (e) => {
  document.querySelectorAll('.menu-list:not([hidden])').forEach((list) => {
    if (!list.parentElement.contains(e.target)) list.hidden = true;
  });
});
