'use strict';
// Interface: diff, file history, dialogs.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ сравнение, история файла, диалоги ============

function diffLines(a, b) {
  const A = a.replace(/\n$/, '').split('\n');
  const B = b.replace(/\n$/, '').split('\n');
  const n = A.length, m = B.length;
  if (n * m > 4e6) {
    const sa = new Set(A), sb = new Set(B);
    return [...A.filter((x) => !sb.has(x)).map((t) => ['-', t]), ...B.filter((x) => !sa.has(x)).map((t) => ['+', t])];
  }
  const W = m + 1;
  const dp = new Uint32Array((n + 1) * W);
  for (let i = n - 1; i >= 0; i--) for (let j = m - 1; j >= 0; j--) dp[i * W + j] = A[i] === B[j] ? dp[(i + 1) * W + j + 1] + 1 : Math.max(dp[(i + 1) * W + j], dp[i * W + j + 1]);
  const out = [];
  let i = 0, j = 0;
  while (i < n && j < m) {
    if (A[i] === B[j]) { out.push([' ', A[i]]); i++; j++; }
    else if (dp[(i + 1) * W + j] >= dp[i * W + j + 1]) out.push(['-', A[i++]]);
    else out.push(['+', B[j++]]);
  }
  while (i < n) out.push(['-', A[i++]]);
  while (j < m) out.push(['+', B[j++]]);
  return out;
}

function renderDiff(ops) {
  const box = h('div', { class: 'diff' });
  if (!ops.some((o) => o[0] !== ' ')) { box.append(h('div', { class: 'gap', text: 'Без изменений' })); return box; }
  const CTX = 3;
  const keep = ops.map(() => false);
  ops.forEach((op, k) => { if (op[0] !== ' ') for (let d = -CTX; d <= CTX; d++) if (ops[k + d]) keep[k + d] = true; });
  let skipped = 0;
  const flush = () => { if (skipped) { box.append(h('div', { class: 'gap', text: `… ${plural(skipped, 'строка без изменений', 'строки без изменений', 'строк без изменений')}` })); skipped = 0; } };
  ops.forEach((op, k) => {
    if (!keep[k]) { skipped++; return; }
    flush();
    box.append(h('div', { class: op[0] === '+' ? 'add' : op[0] === '-' ? 'del' : '', text: (op[0] === ' ' ? '  ' : op[0] + ' ') + op[1] }));
  });
  flush();
  return box;
}

// opts: cls — extra class of the window (.ask — narrow), cancel — label of the closing button, onClose — after any close
function modal(title, body, footer, opts = {}) {
  const close = () => { bg.remove(); document.removeEventListener('keydown', esc); opts.onClose?.(); };
  // only the top window: a question asked over another window closes alone
  const esc = (e) => { if (e.key === 'Escape' && bg === [...document.querySelectorAll('body > .modal-bg')].pop()) close(); };
  const bg = h('div', { class: 'modal-bg', onclick: (e) => { if (e.target === bg) close(); } },
    h('div', { class: 'modal' + (opts.cls ? ' ' + opts.cls : ''), role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
      h('div', { class: 'modal-h' }, h('h2', { text: title }), h('button', { class: 'btn ghost icon', type: 'button', onclick: close, 'aria-label': 'Закрыть' }, icon('x'))),
      body, h('div', { class: 'modal-f' }, btn(opts.cancel || 'Закрыть', close, 'ghost'), footer)));
  document.addEventListener('keydown', esc);
  document.body.append(bg);
  bg.close = close;
  return bg;
}

// Own confirm(): resolves true / false. The question (text up to the first «?») becomes the title, the rest —
// paragraphs under it («\n\n» between them). Esc, the cross, «Отмена» and a click outside — false
function ask(text, ok = 'Да', cls = 'primary', ic = null) {
  const q = text.indexOf('?');
  const first = text.split('\n\n')[0];
  const title = q > 0 && q < first.length ? text.slice(0, q + 1) : first;
  const rest = text.slice(title.length).trim();
  return new Promise((resolve) => {
    let done = false;
    const finish = (v) => { if (done) return; done = true; resolve(v); if (v) bg.close(); };
    const yes = btn(ok, () => finish(true), cls, ic);
    const bg = modal(title, rest ? h('div', { class: 'modal-b stack ask-b' }, rest.split(/\n\n+/).map((p) => h('p', { class: 'sm muted', text: p }))) : null,
      yes, { cls: 'ask', cancel: 'Отмена', onClose: () => finish(false) });
    yes.focus();
  });
}

// Own prompt(): resolves the trimmed text or null. Enter — the same as the button.
// o: value, ok (button label), hint (a line above the field), empty (an empty answer is allowed)
function askText(title, o = {}) {
  return new Promise((resolve) => {
    let done = false;
    const finish = (v) => { if (done) return; done = true; resolve(v); if (v !== null) bg.close(); };
    const input = h('input', { class: 'input', type: 'text', value: o.value || '', spellcheck: 'false', autocomplete: 'off', 'aria-label': title,
      onkeydown: (e) => { if (e.key === 'Enter') { e.preventDefault(); submit(); } } });
    const submit = () => { const v = input.value.trim(); if (v || o.empty) finish(v); else input.focus(); };
    const hint = o.hint;
    const ok = o.ok || 'Готово';
    const bg = modal(title, h('div', { class: 'modal-b stack ask-b' }, hint ? h('p', { class: 'sm muted', text: hint }) : null, input),
      btn(ok, submit, 'primary'), { cls: 'ask', cancel: 'Отмена', onClose: () => finish(null) });
    input.focus();
    input.select();
  });
}

// The last resort of copyText(): the text in a field, selected — Ctrl+C by hand
function copyByHand(text) {
  const ta = h('textarea', { class: 'input mono', readonly: '', rows: Math.min(8, text.split('\n').length + 1), 'aria-label': 'Текст для копирования' });
  ta.value = text;
  modal('Скопируйте вручную', h('div', { class: 'modal-b stack ask-b' }, h('p', { class: 'sm muted', text: 'Браузер не дал доступа к буферу обмена. Текст выделен — нажмите Ctrl+C.' }), ta), null, { cls: 'ask' });
  ta.focus();
  ta.select();
}
