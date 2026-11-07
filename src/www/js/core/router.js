'use strict';
// Interface: routing: which page to draw for the address.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ маршрутизация ============

let routeSeq = 0;
async function route(refresh = false) {
  if (!S.auth) return;
  const seq = ++routeSeq;
  let r = parseRoute();
  if (!r.tab && overviewCol()) {
    // обзор уже виден в колонке слева — открываем первую вкладку
    const q = r.q.get('q');
    history.replaceState(null, '', PAGES.pick[0]);
    if (q) S.check.run(q);
    r = parseRoute();
  }
  openDrawer(false);
  updateChrome();
  // a full-screen config editor or list sits in <body>: it goes away with the page; leaving the page ends full screen
  document.querySelectorAll('body > .cfg, body > .lpanel').forEach((e) => e.remove());
  document.documentElement.classList.remove('cfg-fs');
  if (r.tab !== 'settings' || r.arg !== 'raw') S.cfgFull = false;
  if (r.tab !== 'sites' || r.arg !== S.listFull) S.listFull = null;
  let main = document.getElementById('main');
  // Every page is built into a new, empty #main: a page left before its data came holds the old, detached element,
  // so what it draws late lands nowhere instead of under the next page
  const freshMain = () => {
    const m = main.cloneNode(false);
    main.replaceWith(m);
    main = m;
    return m;
  };
  if (!S.state || refresh) {
    if (!S.state) main.replaceChildren(skel(2, 5));
    try { await loadState(); } catch (e) { main.replaceChildren(notice('bad', 'Не удалось получить состояние', e.message)); return; }
  }
  if (seq !== routeSeq) return;
  if (r.tab !== 'settings' || r.arg !== 'raw') setFocus(false);
  // a view in quotes lives in a page file (js/pages): loaded before the switch, so the transition does not wait on it
  const views = { '': viewOverview, sites: 'viewSites', tests: 'viewTests', settings: 'viewSettings', log: 'viewLog', site: 'viewSite', diag: 'viewDiag', asn: 'viewAsn', devices: 'viewDevices' };
  let view = views[r.tab] || viewOverview;
  if (typeof view === 'string') {
    try { view = await pageView(view); } catch (e) { main.replaceChildren(notice('bad', 'Страница не загрузилась', e.message)); return; }
    if (seq !== routeSeq) return;
  }
  // новое на этой странице: метки внутри видны весь этот заход, после отрисовки страница считается открытой
  S.newHere = new Set(pageFeatures(pageOf(r)));
  const page = pageOf(r);
  const other = S.shownPageId !== page;
  // the same page again (a save, a refresh): long tables scroll inside their own box — keep where each one was
  const boxTops = other ? [] : [...main.querySelectorAll('.scroll')].map((b) => b.scrollTop);
  // only a new address starts from the top: a redraw of this one (a saved form, another theme) stays where it was
  const pageTop = S.shownHash === location.hash ? window.scrollY : 0;
  // While the next page loads, the browser keeps a still picture of what was on screen (View Transitions) and the
  // new page is built under it — no empty frame, no skeleton, nothing jumps, and no second copy in the page (a cloned
  // copy made every search of the page find things twice for a moment). Then: another page fades in over the old one
  // (Оформление → «Анимации»); the same page with other data (profile → profile, a refresh) just replaces it.
  // A skeleton only when there is nothing on screen yet or the browser cannot do view transitions.
  const build = async () => {
    const target = railTarget(freshMain(), page);
    target.replaceChildren(subTabs(page));
    placeSide();
    updatedNote();
    try {
      await view(target, r);
    } catch (e) {
      console.error(e);
      target.replaceChildren(notice('bad', 'Ошибка', e.message));
    }
  };
  const fade = other && !getLook().noanim && !matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (document.startViewTransition && main.firstElementChild && !main.querySelector(':scope > .skel, :scope .mcol > .skel')) {
    S.vt?.skipTransition();   // a click before the last page finished
    document.documentElement.classList.toggle('vt-cut', !fade);
    S.vt = document.startViewTransition(build);
    // a newer switch skips this one: its promises reject with «Transition was skipped» — expected, not an error
    S.vt.ready.catch(() => {});
    S.vt.finished.catch(() => {});
    await S.vt.updateCallbackDone.catch(() => {});
  } else {
    const target = railTarget(freshMain(), page);
    const wait = skel(2, 5);
    target.replaceChildren(subTabs(page), wait);
    placeSide();
    updatedNote();
    try {
      await view(target, r);
      wait.remove();
    } catch (e) {
      console.error(e);
      target.replaceChildren(notice('bad', 'Ошибка', e.message));
    }
    if (seq === routeSeq && fade) {
      target.classList.remove('pg-in');
      void target.offsetWidth;
      target.classList.add('pg-in');
    }
  }
  if (seq === routeSeq) { seenPage(page); S.shownPageId = page; S.shownHash = location.hash; }
  if (seq === routeSeq && boxTops.some(Boolean)) main.querySelectorAll('.scroll').forEach((b, i) => { if (boxTops[i]) b.scrollTop = boxTops[i]; });
  if (!r.q.get('var') && !r.q.get('hl')) window.scrollTo(0, pageTop);
}

// unsaved edits: the address goes back at once, and on «Уйти» the page moves on (hashchange again, now clean)
window.addEventListener('hashchange', async () => {
  if (S.dirty) {
    const to = location.hash;
    history.replaceState(null, '', S.dirtyHash);
    if (to === S.dirtyHash || !await ask('Уйти без сохранения?\n\nНа странице есть несохранённые изменения.', 'Уйти', 'danger')) return;
    S.dirty = false;
    location.hash = to;
    return;
  }
  route();
});
window.addEventListener('beforeunload', (e) => { if (S.dirty) { e.preventDefault(); e.returnValue = ''; } });
wideMq.addEventListener('change', () => route());
const setDirty = (v) => { S.dirty = v; S.dirtyHash = location.hash; };

// Page files come on demand: a view → its files (PAGE_NEEDS, made by scripts/pagemap.php). Each file loads once,
// with the build number of the page so the browser never mixes builds. A failed load is tried again next time
const pageLoads = {};
function loadPageFile(name) {
  return pageLoads[name] ??= new Promise((ok, bad) => {
    const s = document.createElement('script');
    s.src = `js/pages/${name}.js?v=${encodeURIComponent(BUILD)}`;
    s.async = false;   // several files run in the order asked, while they download together
    s.onload = ok;
    s.onerror = () => { delete pageLoads[name]; s.remove(); bad(new Error(`Файл js/pages/${name}.js не загрузился — проверьте связь с роутером и обновите страницу.`)); };
    document.head.append(s);
  });
}
async function pageView(name) {
  await Promise.all((PAGE_NEEDS[name] || []).map(loadPageFile));
  if (typeof window[name] !== 'function') throw new Error(`Нет функции ${name} — обновите страницу.`);
  return window[name];
}

const pickHref = (host) => layout() === 'site' ? `#/site/${encodeURIComponent(host)}/pick` : '#/tests?host=' + encodeURIComponent(host);

const diagHref = (host) => layout() === 'site' ? `#/site/${encodeURIComponent(host)}/diag` : '#/diag?host=' + encodeURIComponent(host);

const pickHistHref = (host) => layout() === 'site' ? `#/site/${encodeURIComponent(host)}/hist` : PAGES.phist[0] + '?host=' + encodeURIComponent(host);
