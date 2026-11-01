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

function modal(title, body, footer) {
  const close = () => { bg.remove(); document.removeEventListener('keydown', esc); };
  const esc = (e) => { if (e.key === 'Escape') close(); };
  const bg = h('div', { class: 'modal-bg', onclick: (e) => { if (e.target === bg) close(); } },
    h('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
      h('div', { class: 'modal-h' }, h('h2', { text: title }), h('button', { class: 'btn ghost icon', type: 'button', onclick: close, 'aria-label': 'Закрыть' }, icon('x'))),
      body, h('div', { class: 'modal-f' }, btn('Закрыть', close, 'ghost'), footer)));
  document.addEventListener('keydown', esc);
  document.body.append(bg);
  bg.close = close;
  return bg;
}
