'use strict';
// Interface: log highlighting.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// ============ подсветка журналов ============
// Время, уровень syslog, процесс, пути, файлы, параметры, адреса, ошибки и предупреждения. Строка с ошибкой
// или предупреждением выделяется целиком.

const LOG_RE = new RegExp([
  String.raw`(?<time>^\w{3} \w{3} +\d+ \d\d:\d\d:\d\d \d{4}|\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d(?:[.,]\d+)?|\b\d\d:\d\d:\d\d(?:\.\d+)?\b)`,
  String.raw`(?<lvl>\b(?:kern|user|daemon|auth|authpriv|syslog|cron|local\d)\.(?:emerg|alert|crit|err|warn|warning|notice|info|debug)\b)`,
  String.raw`(?<proc>\b[\w.-]+\[\d+\]:)`,
  String.raw`(?<url>https?://[^\s"'<>]+)`,
  String.raw`(?<path>(?<![\w/.-])/(?:[\w.+-]+/?)+)`,
  String.raw`(?<opt>(?<![\w-])--[a-z][\w-]*)`,
  String.raw`(?<ip>\b\d{1,3}(?:\.\d{1,3}){3}(?::\d{1,5})?(?:/\d{1,2})?\b)`,
  String.raw`(?<file>\b[\w.-]+\.(?:list|lua|bin|conf|log|json|ipk|sh|txt|gz|pcap)\b)`,
  String.raw`(?<err>\b(?:errors?|fail(?:ed|ure|s)?|fatal|cannot|can't|could not|denied|refused|invalid|not found|no such|timed? ?out|abort(?:ed)?)\b|[Оо]шибк[а-я]*|не удалось|не найден[а-я]*|нет доступа|НЕ РАБОТАЕТ)`,
  String.raw`(?<warn>\b(?:warn(?:ing)?s?|deprecated|skipp(?:ed|ing)|retry(?:ing)?)\b|[Вв]нимание|[Пп]редупрежд[а-я]*)`,
  String.raw`(?<ok>\b(?:OK|ok|success(?:ful(?:ly)?)?|started|ready|done|loaded|matches)\b|[Гг]отово|установлен|запущен)`,
].join('|'), 'giu');

function logLine(line) {
  const out = [];
  let last = 0;
  let mark = '';
  for (const m of line.matchAll(LOG_RE)) {
    const kind = Object.keys(m.groups).find((k) => m.groups[k] !== undefined);
    let cls = kind;
    if (kind === 'lvl') {
      const l = m[0].split('.')[1];
      // busybox crond пишет каждый запуск задания как cron.err — это не ошибка
      cls = /emerg|alert|crit|err/.test(l) && !(m[0] === 'cron.err' && / cmd /.test(line)) ? 'err' : /warn/.test(l) ? 'warn' : 'lvl';
    }
    if (cls === 'err') mark = 'err';
    else if (cls === 'warn' && !mark) mark = 'warn';
    if (m.index > last) out.push(line.slice(last, m.index));
    out.push(h('span', { class: 'lg-' + cls, text: m[0] }));
    last = m.index + m[0].length;
  }
  if (last < line.length) out.push(line.slice(last));
  return h('div', { class: 'lg' + (mark ? ' lg-' + mark + '-line' : '') }, out.length ? out : '\u00a0');
}

// Журнал в элемент pre: строки с подсветкой или текст-заглушка
function drawLog(pre, lines, empty = 'Пусто') {
  pre.classList.add('logv');
  pre.replaceChildren(...(lines.length ? lines.map(logLine) : [h('div', { class: 'lg muted', text: empty })]));
}
