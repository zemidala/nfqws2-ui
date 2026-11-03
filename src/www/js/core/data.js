'use strict';
// Interface: loading state, config and catalog from the router.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ данные ============

async function loadState() {
  S.state = await api('state');
  const since = S.state?.ui?.seen?.since;
  if (since) {
    const def = vcmp(since, ZD_SINCE) >= 0 ? 'z' : 'k';
    if (def !== lookDefaultVariant()) {
      try { localStorage.setItem('nfqws-ui-look-default', def); } catch { /* no storage */ }
      if (!lookStored().variant) applyLook();
    }
  }
  updateChrome();
  return S.state;
}
async function loadCatalog() {
  if (!S.catalog) S.catalog = await api('catalog');
  return S.catalog;
}
async function loadConf() {
  S.conf = await api('conf_get');
  return S.conf;
}
const prof = (n) => S.state?.profiles.find((p) => p.index === n);
const confProf = (n) => S.state?.conf_profiles.find((p) => p.index === n);

function profName(p) {
  const s = p.source;
  if (p.name) return p.name;   // --name=… или --new=… в конфиге
  if (!s) return 'Профиль';
  if (s.source === 'NFQWS_ARGS_CUSTOM') return 'Свой профиль ' + s.part;
  if (s.source === 'NFQWS_ARGS_UDP') return 'UDP: игры, звонки';
  if (s.source === 'NFQWS_ARGS_QUIC') return s.with === 'NFQWS_ARGS_IPSET' ? 'QUIC по IP' : 'QUIC по сайтам';
  return s.with === 'NFQWS_ARGS_IPSET' ? 'HTTP/HTTPS по IP' : 'HTTP/HTTPS по сайтам';
}
function profScope(p) {
  if (p.empty_hostlists) return 'списки пусты — весь трафик';
  if (p.has_host_filter && p.has_ip_filter) return 'сайты и IP из списков';
  if (p.has_host_filter) return 'сайты из списков';
  if (p.has_ip_filter) return 'IP из списков';
  return p.has_excludes ? 'все, кроме исключений' : 'весь трафик';
}
const portsText = (ports) => [ports.tcp && 'TCP ' + ports.tcp, ports.udp && 'UDP ' + ports.udp].filter(Boolean).join(' · ');
// Короткое описание стратегии: для circular — «перебор: N стратегий», иначе функции через «+»
const strategyText = (p) => {
  const fns = p.desync.map((d) => d.fn);
  const orch = fns.includes('circular') ? 'перебор' : fns.includes('learn') ? 'обучение' : null;
  if (orch) {
    const n = new Set(p.desync.map((d) => (d.params.match(/(?:^|:)strategy=(\d+)/) || [])[1]).filter(Boolean)).size;
    return n ? `${orch}: ${plural(n, 'стратегия', 'стратегии', 'стратегий')}` : orch + ' стратегий';
  }
  return [...new Set(fns)].join(' + ') || 'без стратегии';
};

// Замечания проверки, относящиеся к профилю конфига
function profIssues(p) {
  const st = S.state;
  if (!p.source) return [];
  const all = (st.lint?.issues || []).filter((x) => x.var === p.source.source);
  const mine = (x) => !x.msg.startsWith('Профиль #') || x.msg.startsWith(`Профиль #${p.index}:`);
  if (p.source.source !== 'NFQWS_ARGS_CUSTOM') return all.filter(mine);
  const toks = st.conf_tokens?.NFQWS_ARGS_CUSTOM || [];
  let part = 1;
  const partOf = toks.map((t) => (isNewArg(t) ? part++ : part));
  return all.filter((x) => mine(x) && (x.tok == null || partOf[x.tok] === p.source.part));
}

function listEffect(l, profiles = S.state.profiles) {
  if (!l.used.length) return 'не используется ни одним профилем';
  return l.used.map((u) => {
    const p = profiles.find((x) => x.index === u.profile);
    if (!p) return '#' + u.profile;
    if (u.role === 'exclude') return `исключает из #${p.index}`;
    return `#${p.index} ${profName(p)}: ` + (portsText(p.remaining) || 'перекрыт профилями выше');
  }).join('; ');
}

// --new или --new=имя — начало следующего профиля; имя из --new=имя переходит в профиль как --name=имя
// (для nfqws2 одно и то же; при сохранении «Своих профилей» пишется --new + --name=имя). На сервере — splitNew
const isNewArg = (t) => t === '--new' || t.startsWith('--new=');

const splitParts = (toks) => toks.reduce((acc, t) => { if (isNewArg(t)) acc.push(t.length > 6 ? ['--name=' + t.slice(6)] : []); else acc[acc.length - 1].push(t); return acc; }, [[]]);

const joinParts = (parts) => parts.map((p) => p.join('\n')).join('\n--new\n');

// Сохранение переменных конфига с проверкой; при ошибках предлагает сохранить всё равно (кроме фатальных)
async function saveVars(vars, note, force = false) {
  try {
    const res = await api('conf_save_vars', { vars, note, force });
    await loadState();
    await loadConf();
    return res;
  } catch (e) {
    if (e.data?.issues) {
      const errs = e.data.issues.filter((x) => x.level === 'error').map((x) => '• ' + x.msg).slice(0, 6).join('\n');
      if (e.data.fatal) { toast('Не сохранено: nfqws2 с таким конфигом не запустится. ' + (e.data.dry_run?.message || ''), { err: true }); return null; }
      if (await ask('Сохранить конфиг с ошибками?\n\n' + errs + '\n\nnfqws2 запустится, но эти места будут работать неправильно.', 'Сохранить всё равно', 'danger')) return saveVars(vars, note, true);
      return null;
    }
    toast(e.message, { err: true });
    return null;
  }
}

// Текст со стратегиями (из чата или свой) → список стратегий, каждая — список параметров. Стратегии разделяет «--new»
// или новая строка; строка с «\» в конце продолжается на следующей. Подписи, кавычки и «ИМЯ=» убираются; шаг без
// «--lua-desync=» (fake:blob=…) дополняется сам. Что из этого годится, решает сервер (strategy_check).
function parseStrategies(text) {
  const out = [];
  for (const line of text.replace(/\\[ \t]*\r?\n/g, ' ').split('\n')) {
    if (/^\s*#/.test(line)) continue;
    const toks = line.replace(/["'`\\]/g, ' ').replace(/^\s*[A-Z_]+=/, '').split(/\s+/).filter(Boolean)
      .map((t) => /^[a-z_][a-z0-9_]*:\S/.test(t) ? '--lua-desync=' + t : t).filter((t) => t.startsWith('--'));
    for (const part of splitParts(toks)) if (part.length) out.push(part);
  }
  return out;
}
