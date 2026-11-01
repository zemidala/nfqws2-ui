'use strict';
// Interface: the look: style, theme, layout settings.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ оформление ============

// Оформление по умолчанию: стиль K («как в Keenetic»). В браузере хранится только то, что выбрано явно, —
// поэтому смена умолчания доходит до тех, кто стиль сам не выбирал
const LOOK_DEFAULT = { variant: 'k', mode: 'auto', layout: 'menu' };
// New installs (started on 1.26.0 or later) default to Z · zemidala; older ones keep K unless they pick a style.
// The decision is cached in the browser so the page does not flash K before the state arrives.
const ZD_SINCE = '1.26.0';
function lookStored() {
  try { return JSON.parse(localStorage.getItem('nfqws-ui-look') || '{}') || {}; } catch { return {}; }
}
function lookDefaultVariant() {
  try { return localStorage.getItem('nfqws-ui-look-default') === 'z' ? 'z' : 'k'; } catch { return 'k'; }
}
function getLook() {
  return { ...LOOK_DEFAULT, variant: lookDefaultVariant(), ...lookStored() };
}
// Z is the K layout with the brand palette: data-variant="k" keeps every K rule, data-palette="zd" swaps colours
const isKLike = (variant) => variant === 'k' || variant === 'z';
// Colour schemes: hand-picked palettes (local/design/palitry.html) laid over any style but Z, whose point is the
// brand colours. A style keeps its shape and layout, only the colours change; by default every style keeps its own.
// Each scheme has a dark and a light set: page, cards, menu, lines, text, accent. Status colours stay the style's.
// The CSS is cached in the browser and applied by index.html before the first paint (no flash of the stock colour)
const SCHEMES = [
  ['night', 'Полночь',
    { bg: '#0b1120', surface: '#121a2e', side: '#0e1526', line: '#24304a', soft: '#18223a', fg: '#e6ebf5', muted: '#a3afc6', accent: '#6aa7ff', ink: '#8bbaff', afg: '#06122a', asoft: '#1a2c4f', ctl: '#53607a' },
    { bg: '#edf1f8', surface: '#ffffff', side: '#f6f8fc', line: '#d4dcea', soft: '#eef2f9', fg: '#101a2c', muted: '#4b5873', accent: '#1d55c9', ink: '#1d55c9', afg: '#ffffff', asoft: '#dfe8fb', ctl: '#8995ab' }],
  ['ocean', 'Океан',
    { bg: '#06151a', surface: '#0c2027', side: '#091a20', line: '#1c3a43', soft: '#11282f', fg: '#def0f1', muted: '#9cbdc1', accent: '#35c6d4', ink: '#5ed3de', afg: '#03232a', asoft: '#103a42', ctl: '#4c6d73' },
    { bg: '#e9f4f5', surface: '#ffffff', side: '#f3f9fa', line: '#cfe2e4', soft: '#e9f3f4', fg: '#0b2227', muted: '#476468', accent: '#0a6c79', ink: '#0a6c79', afg: '#ffffff', asoft: '#d4ecee', ctl: '#83a0a4' }],
  ['lilac', 'Сирень',
    { bg: '#110e1b', surface: '#1a1628', side: '#15111f', line: '#2f2944', soft: '#211c33', fg: '#ece8f7', muted: '#b3abca', accent: '#ad91ff', ink: '#bfa8ff', afg: '#170c33', asoft: '#2c2350', ctl: '#5f5878' },
    { bg: '#f3f0fa', surface: '#ffffff', side: '#f8f6fc', line: '#ded7ee', soft: '#f1edf9', fg: '#1a1330', muted: '#5a5175', accent: '#6236c4', ink: '#6236c4', afg: '#ffffff', asoft: '#e9e1fb', ctl: '#988fb0' }],
  ['pine', 'Хвоя',
    { bg: '#0b130e', surface: '#121d16', side: '#0e1711', line: '#243529', soft: '#17241b', fg: '#e2ede4', muted: '#a5baa9', accent: '#6fd08c', ink: '#86dba0', afg: '#05200f', asoft: '#1a3624', ctl: '#53685a' },
    { bg: '#edf3ee', surface: '#ffffff', side: '#f5f9f6', line: '#d3e2d6', soft: '#edf4ef', fg: '#0f2216', muted: '#4a6352', accent: '#1d7440', ink: '#1d7440', afg: '#ffffff', asoft: '#d9eedf', ctl: '#86a08e' }],
  ['amber', 'Янтарь',
    { bg: '#14100b', surface: '#1e1811', side: '#18130d', line: '#382d1f', soft: '#251e15', fg: '#f2eadf', muted: '#c4b5a0', accent: '#f2a53c', ink: '#f6b961', afg: '#2a1702', asoft: '#3a2a12', ctl: '#6e6150' },
    { bg: '#f7f1e8', surface: '#ffffff', side: '#fbf7f1', line: '#e8dcca', soft: '#f6efe4', fg: '#2a1d0e', muted: '#6b5a44', accent: '#955409', ink: '#955409', afg: '#ffffff', asoft: '#f6e5cc', ctl: '#ad9c84' }],
  ['cherry', 'Вишня',
    { bg: '#150b0f', surface: '#201218', side: '#1a0e13', line: '#3c232b', soft: '#28171e', fg: '#f4e6ea', muted: '#c8a9b1', accent: '#ff7d95', ink: '#ff99ac', afg: '#2d0711', asoft: '#45202b', ctl: '#735a62' },
    { bg: '#f8eef0', surface: '#ffffff', side: '#fbf5f6', line: '#ecd5da', soft: '#f7ecee', fg: '#2a0f17', muted: '#6c4b54', accent: '#ad2348', ink: '#ad2348', afg: '#ffffff', asoft: '#f8dde4', ctl: '#ae939a' }],
  ['graphite', 'Графит',
    { bg: '#111213', surface: '#1a1b1d', side: '#151617', line: '#2d2f32', soft: '#202124', fg: '#e8e8e9', muted: '#afb1b5', accent: '#d2d5da', ink: '#e0e2e6', afg: '#141516', asoft: '#2c2e31', ctl: '#5d6065' },
    { bg: '#f1f1f2', surface: '#ffffff', side: '#f7f7f8', line: '#dcdddf', soft: '#f0f0f1', fg: '#16171a', muted: '#55585e', accent: '#2f3236', ink: '#2f3236', afg: '#ffffff', asoft: '#e5e6e8', ctl: '#97999e' }],
];
const SCHEME_VARIANTS = ['a', 'b', 'c', 'k'];
const darkNow = () => { const m = document.documentElement.dataset.mode; return m === 'dark' || (m !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches); };
// a scheme set → the app's tokens; K also has its menu (side) and active item colours
const schemeVars = (p) => `--bg: ${p.bg}; --surface: ${p.surface}; --line: ${p.line}; --soft: ${p.soft}; --fg: ${p.fg}; --muted: ${p.muted}; --faint: ${p.muted};`
  + ` --accent: ${p.accent}; --accent-ink: ${p.ink}; --accent-fg: ${p.afg}; --accent-soft: ${p.asoft}; --tk-opt: ${p.ink}; --tk-p: ${p.ctl};`
  + ` --k-side: ${p.side}; --k-active: ${p.asoft}; --k-field: ${p.ctl};`;
function schemeCss(look) {
  const s = SCHEMES.find(([id]) => id === look.scheme);
  if (!s) return '';
  const [, , dark, light] = s;
  const sel = ':root:root:root:is([data-variant="a"], [data-variant="b"], [data-variant="c"], [data-variant="k"]:not([data-palette]))';
  return `${sel} { ${schemeVars(light)} }\n${sel}[data-mode="dark"] { ${schemeVars(dark)} }\n`
    + `@media (prefers-color-scheme: dark) { ${sel}:not([data-mode="light"]) { ${schemeVars(dark)} } }\n`
    // the previews of the styles in «Оформление» (always drawn light) take the scheme too, Z's excepted
    + `.thumb:not(.th-z) { background: ${light.bg}; } .thumb:not(.th-z) .acc { background: ${light.accent}; } .thumb:not(.th-z) .nd { border-color: ${light.accent}; }\n`;
}

function applyLook(look = getLook()) {
  const css = schemeCss(look);
  let st = document.getElementById('scheme-style');
  if (css && !st) st = document.head.appendChild(h('style', { id: 'scheme-style' }));
  if (st) st.textContent = css;
  try { if (css) localStorage.setItem('nfqws-ui-scheme-css', css); else localStorage.removeItem('nfqws-ui-scheme-css'); } catch { /* no storage */ }
  document.documentElement.dataset.variant = look.variant === 'z' ? 'k' : look.variant;
  if (look.variant === 'z') document.documentElement.dataset.palette = 'zd'; else delete document.documentElement.dataset.palette;
  // a colour scheme gives K's panels a background of their own — CSS then gives them inner padding too
  if (look.variant !== 'z' && SCHEMES.some(([id]) => id === look.scheme)) document.documentElement.dataset.scheme = look.scheme; else delete document.documentElement.dataset.scheme;
  document.documentElement.dataset.mode = look.mode;
  document.documentElement.dataset.layout = ['menu', 'tabs', 'site'].includes(look.layout) ? look.layout : 'menu';
  document.documentElement.toggleAttribute('data-simple', !!look.simple);
  document.documentElement.toggleAttribute('data-navmini', !!look.navmini);
  document.documentElement.toggleAttribute('data-nooutline', !!look.nooutline);
  document.documentElement.toggleAttribute('data-noanim', !!look.noanim);
}
function setLook(patch) {
  try { localStorage.setItem('nfqws-ui-look', JSON.stringify({ ...lookStored(), ...patch })); } catch { /* нет хранилища */ }
  applyLook();
}
applyLook();
