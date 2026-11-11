'use strict';
// Interface: Markdown for README, changes, release notes.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ Markdown: README, история версий, описание релиза ============
// Небольшой разбор без внешних библиотек: заголовки, абзацы, вложенные списки, таблицы, код, цитаты, ссылки.
// HTML и картинки пропускаются. Всё строится через DOM, поэтому текст из релиза не может выполнить код.

const mdSlug = (s) => s.toLowerCase().replace(/[^\p{L}\p{N}\s-]/gu, '').trim().replace(/\s/g, '-');

function mdInline(s, onAnchor) {
  const out = [];
  const re = /`([^`]+)`|\*\*(.+?)\*\*|\[([^\]]*)\]\(([^)\s]+)\)/g;
  let last = 0;
  for (const m of s.matchAll(re)) {
    if (m.index > last) out.push(s.slice(last, m.index));
    if (m[1] !== undefined) out.push(h('code', { text: m[1] }));
    else if (m[2] !== undefined) out.push(h('b', {}, mdInline(m[2], onAnchor)));
    else {
      const href = m[4];
      const kids = mdInline(m[3], onAnchor);
      if (href.startsWith('#')) out.push(h('a', { href: '#', onclick: (e) => { e.preventDefault(); let a = href.slice(1); try { a = decodeURIComponent(a); } catch { /* a stray «%» */ } onAnchor?.(a); } }, kids));
      else if (/^https?:\/\//.test(href)) out.push(h('a', { href, target: '_blank', rel: 'noopener' }, kids));
      else if (/^CHANGELOG\.md$/i.test(href)) out.push(h('a', { href: '#/settings/changelog' }, kids));
      else if (/^README\.md$/i.test(href)) out.push(h('a', { href: '#/settings/readme' }, kids));
      else out.push(h('a', { href: REPO + '/blob/main/' + href.replace(/^\.?\//, ''), target: '_blank', rel: 'noopener' }, kids));
    }
    last = m.index + m[0].length;
  }
  if (last < s.length) out.push(s.slice(last));
  return out;
}

function md(text, onAnchor) {
  const lines = text.replace(/\r/g, '').split('\n');
  const root = h('div', { class: 'md' });
  const inl = (s) => mdInline(s, onAnchor);
  let para = [];
  const flush = () => { if (para.length) root.append(h('p', {}, inl(para.join(' ')))); para = []; };
  const isList = (l) => /^(\s*)([-*]|\d+\.)\s+/.exec(l);
  for (let i = 0; i < lines.length; i++) {
    const l = lines[i];
    // картинки, значки и HTML-вставки (скриншоты) — пропускаем
    if (/^\s*(\[?!\[|<\/?(p|img|br|div|details|summary)\b)/i.test(l)) { flush(); continue; }
    if (/^```/.test(l)) {
      flush();
      const code = [];
      while (++i < lines.length && !/^```/.test(lines[i])) code.push(lines[i]);
      root.append(copyBox(h('pre', { class: 'box', text: code.join('\n') })));
      continue;
    }
    const hm = /^(#{1,4})\s+(.*)$/.exec(l);
    if (hm) {
      flush();
      root.append(h('h' + Math.min(hm[1].length + 1, 5), { id: 'md-' + mdSlug(hm[2]) }, inl(hm[2])));
      continue;
    }
    if (/^\|/.test(l)) {
      flush();
      const rows = [];
      for (; i < lines.length && /^\|/.test(lines[i]); i++) rows.push(lines[i].replace(/^\||\|\s*$/g, '').split('|').map((c) => c.trim()));
      i--;
      const body = rows.filter((r) => !r.every((c) => /^:?-+:?$/.test(c)));
      const head = rows.length > 1 && rows[1].every((c) => /^:?-+:?$/.test(c)) ? body.shift() : null;
      root.append(h('div', { class: 'scroll md-tbl' }, h('table', { class: 'tbl' },
        head && head.some(Boolean) ? h('thead', {}, h('tr', {}, head.map((c) => h('th', {}, inl(c))))) : null,
        h('tbody', {}, body.map((r) => h('tr', {}, r.map((c) => h('td', {}, inl(c)))))))));
      continue;
    }
    if (/^>\s?/.test(l)) {
      flush();
      const q = [];
      for (; i < lines.length && /^>\s?/.test(lines[i]); i++) q.push(lines[i].replace(/^>\s?/, ''));
      i--;
      // GitHub's alerts («> [!WARNING]») are for the repository page only (the notice about use and risk): not here
      if (/^\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\s*$/.test(q[0] || '')) continue;
      root.append(h('blockquote', {}, inl(q.join(' '))));
      continue;
    }
    if (isList(l)) {
      flush();
      // Список со вложенностью по отступу; строки с отступом без маркера — продолжение пункта
      const top = { el: null, indent: -1, items: [] };
      const stack = [top];
      let cur = null;
      for (; i < lines.length; i++) {
        const x = lines[i];
        const lm = isList(x);
        if (lm) {
          const ind = lm[1].length;
          const ordered = /\d/.test(lm[2]);
          while (stack.length > 1 && ind < stack[stack.length - 1].indent) stack.pop();
          let lvl = stack[stack.length - 1];
          if (ind > lvl.indent) {
            const el = h(ordered ? 'ol' : 'ul');
            (cur || root).append(el);
            lvl = { el, indent: ind };
            stack.push(lvl);
          }
          cur = h('li', {}, inl(x.slice(lm[0].length)));
          lvl.el.append(cur);
          lvl.li = cur;
        } else if (/^\s+\S/.test(x) && cur) {
          // продолжение относится к пункту, у которого маркер левее отступа строки
          const ind = x.length - x.trimStart().length;
          const owner = [...stack].reverse().find((v) => v.li && v.indent < ind) || stack[stack.length - 1];
          owner.li.append(h('p', {}, inl(x.trim())));
        } else if (x.trim() === '' && (isList(lines[i + 1] || '') || /^\s+\S/.test(lines[i + 1] || ''))) {
          continue;
        } else {
          i--;
          break;
        }
      }
      continue;
    }
    if (l.trim() === '') { flush(); continue; }
    if (/^---+$/.test(l.trim())) { flush(); root.append(h('hr')); continue; }
    para.push(l.trim());
  }
  flush();
  return root;
}

// Страница с документом из пакета: README или история версий
async function paneDoc(content, name) {
  const r = await api('doc', { name });
  let body;
  const toAnchor = (id) => body.querySelector('#md-' + CSS.escape(id))?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  body = md(r.text, toAnchor);
  const heads = [...body.querySelectorAll('h3')];
  content.append(h('div', { class: 'vh' }, h('h1', { text: name === 'readme' ? 'Справка' : 'Изменения по версиям' }), h('span', { class: 'grow' }),
    h('a', { class: 'sm', href: REPO + (name === 'readme' ? '#readme' : '/blob/main/CHANGELOG.md'), target: '_blank', rel: 'noopener', text: 'на GitHub' })),
    heads.length > 3 ? panel(null, null, h('div', { class: 'md-toc' }, heads.map((x) =>
      h('a', { href: '#', onclick: (e) => { e.preventDefault(); x.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, text: x.textContent })))) : null,
    panel(null, null, body));
}
