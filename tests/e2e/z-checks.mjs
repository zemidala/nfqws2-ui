// Checks of style Z that layout and contrast audits cannot make. Each runs in the page and returns a list of findings.

// a coloured stripe down the side, capitals, spaced letters; the menu alone keeps its bar
export const signs = () => {
  const out = new Set();
  for (const e of document.querySelectorAll('body *')) {
    const b = e.getBoundingClientRect();
    if (!b.width || !b.height || e.closest('.mnav')) continue;
    const s = getComputedStyle(e);
    const name = `${e.tagName.toLowerCase()}.${String(e.className.baseVal ?? e.className).split(' ').slice(0, 2).join('.')}`;
    if (/inset/.test(s.boxShadow) && /\d+px 0px 0px 0px inset/.test(s.boxShadow)) out.add(`полоса сбоку: ${name}`);
    // a line on one side only, on any side; the underline of a tab is what a tab is, so tabs are left out
    const w = ['Top', 'Right', 'Bottom', 'Left'].map((k) => (s[`border${k}Style`] === 'none' || s[`border${k}Color`] === 'rgba(0, 0, 0, 0)' ? 0 : parseFloat(s[`border${k}Width`])));
    // a hairline between rows is a divider, a thick line on one side is the stripe
    if (Math.max(...w) >= 2 && Math.min(...w) < Math.max(...w) && b.height > 16 && b.width > 16 && !e.closest('.tabs, .subtabs, nav')) out.add(`полоса с одной стороны: ${name}`);
    if ([...e.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim().length > 2)) {
      if (s.textTransform === 'uppercase') out.add(`капс: ${name} «${e.textContent.trim().slice(0, 20)}»`);
      if (parseFloat(s.letterSpacing) > 0.5) out.add(`разрядка: ${name} «${e.textContent.trim().slice(0, 20)}»`);
    }
  }
  return [...out];
};

// a button whose content does not leave it room: text or icon closer than 6 px to the edge, or cut
export const tightButtons = () => {
  const out = new Set();
  for (const e of document.querySelectorAll('button.btn, a.btn')) {
    const b = e.getBoundingClientRect();
    if (!b.width || !b.height || e.matches('.icon') || b.width < 44) continue;
    const r = document.createRange();
    r.selectNodeContents(e);
    const c = r.getBoundingClientRect();
    if (!c.width) continue;
    if (c.left - b.left < 6 || b.right - c.right < 6 || e.scrollWidth > e.clientWidth + 1) out.add(`кнопка тесная: «${e.textContent.trim().slice(0, 20)}» ${Math.round(c.left - b.left)}/${Math.round(b.right - c.right)}`);
  }
  return [...out];
};

// nothing in the top bar lies over anything else
export const topBar = () => {
  const out = [];
  const seen = [...document.querySelectorAll('.top-in *')].filter((e) => e.matches('.input, .btn, .lchip, .pill, .brand') && e.getBoundingClientRect().width);
  for (let i = 0; i < seen.length; i++) {
    for (let j = i + 1; j < seen.length; j++) {
      const a = seen[i].getBoundingClientRect(), b = seen[j].getBoundingClientRect();
      if (seen[i].contains(seen[j]) || seen[j].contains(seen[i])) continue;
      if (a.left < b.right - 1 && b.left < a.right - 1 && a.top < b.bottom - 1 && b.top < a.bottom - 1) out.push(`шапка, наложение: ${seen[i].className} / ${seen[j].className}`);
    }
  }
  const q = document.querySelector('.top-in .quick .input');
  if (q?.offsetParent && q.clientWidth < 115) out.push(`шапка, поле сжато до ${q.clientWidth}`);
  return out;
};

// controls side by side of one height
export const rows = () => {
  const out = new Set();
  for (const p of document.querySelectorAll('main *, #side *, .top-in')) {
    const kids = [...p.children].filter((c) => c.matches('input.input, .btn, .dd-btn') && c.getBoundingClientRect().height);
    if (kids.length < 2) continue;
    const by = new Map();
    for (const c of kids) { const b = c.getBoundingClientRect(); const k = Math.round((b.top + b.bottom) / 2 / 24); (by.get(k) || by.set(k, []).get(k)).push(b); }
    for (const row of by.values()) {
      const hs = [...new Set(row.map((b) => Math.round(b.height)))];
      if (row.length > 1 && Math.max(...hs) - Math.min(...hs) > 2) out.add(`разная высота ${hs.join('/')}: ${p.tagName.toLowerCase()}.${String(p.className).split(' ')[0]}`);
    }
  }
  return [...out];
};
