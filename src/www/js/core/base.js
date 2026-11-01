'use strict';
// Interface: icons, names, the shared state S, the element builder h.
// A plain script, loaded by index.html in a fixed order (see the list there); its names are shared by all.

// Интерфейс nfqws2. Три компоновки на выбор (Оформление): боковое меню, вкладки по задачам с колонкой обзора,
// «вокруг сайта». Страницы и их адреса общие. Без сборки и внешних зависимостей, данные — через api.php.

const ICONS = {
  home: '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
  sites: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
  tests: '<path d="M9 3h6M10 3v6l-5 9a2 2 0 0 0 1.7 3h10.6a2 2 0 0 0 1.7-3l-5-9V3"/><path d="M7 15h10"/>',
  settings: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1 7 17M17 7l2.1-2.1"/>',
  log: '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h6"/>',
  refresh: '<path d="M21 12a9 9 0 1 1-2.6-6.4L21 8"/><path d="M21 3v5h-5"/>',
  logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
  ok: '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
  bad: '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
  alert: '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17.5v.5"/>',
  info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5v.5"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  x: '<path d="M6 6l12 12M18 6 6 18"/>',
  trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
  history: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
  back: '<path d="M15 18 9 12l6-6"/>',
  chev: '<path d="m9 18 6-6-6-6"/>',
  up: '<path d="m6 15 6-6 6 6"/>',
  down: '<path d="m6 9 6 6 6-6"/>',
  play: '<path d="M7 4v16l13-8z"/>',
  stop: '<rect x="6" y="6" width="12" height="12" rx="1"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
  edit: '<path d="M4 20h4L19 9l-4-4L4 16z"/>',
  wand: '<path d="m15 4 5 5L9 20l-5-5z"/><path d="M13 6l5 5"/>',
  menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
  dash: '<g fill="currentColor" stroke="none"><rect x="3" y="3" width="8.25" height="10.5"/><rect x="3" y="15" width="8.25" height="6"/><rect x="12.75" y="3" width="8.25" height="6"/><rect x="12.75" y="10.5" width="8.25" height="10.5"/></g>',
  gear: '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
  shield: '<path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.2 7.5 9.5 4.3-1.3 7.5-4.9 7.5-9.5V6z"/>',
  more: '<circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/>',
  pulse: '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
  layers: '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
  download: '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
  upload: '<path d="M12 20V9M7 14l5-5 5 5M5 4h14"/>',
  copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h8"/>',
  expand: '<path d="M4 9V4h5M15 4h5v5M20 15v5h-5M9 20H4v-5"/>',
  shrink: '<path d="M9 4v5H4M20 9h-5V4M15 20v-5h5M4 15h5v5"/>',
  lock: '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
  share: '<path d="M12 15V3M7 8l5-5 5 5M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/>',
  pin: '<path d="M9 4h6l-1 6 4 4H6l4-4z"/><path d="M12 14v7"/>',
  undo: '<path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/>',
  power: '<path d="M12 3v8"/><path d="M7 6.3a8 8 0 1 0 10 0"/>',
  dup: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M4 16V6a2 2 0 0 1 2-2h10"/><path d="M12 14h4"/>',
  git: '<circle cx="6" cy="5" r="2"/><circle cx="6" cy="19" r="2"/><circle cx="18" cy="7" r="2"/><path d="M6 7v10M18 9c0 5-7 4-11 8"/>',
  arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
  gift: '<rect x="3" y="8" width="18" height="5" rx="1"/><path d="M5 13v8h14v-8M12 8v13M12 8S10 3 7.5 4 9 8 12 8zM12 8s2-5 4.5-4S15 8 12 8z"/>',
};

const LIST_NAMES = {
  'user.list': 'Мой список сайтов', 'exclude.list': 'Исключения — не трогать', 'auto.list': 'Автосписок',
  'cloudflare.list': 'Сайты на Cloudflare', 'ipset.list': 'Мои IP-адреса', 'ipset_exclude.list': 'IP-исключения',
  'ipset_cf.list': 'IP Cloudflare', 'ipset_aws.list': 'IP Amazon AWS', 'ipset_do.list': 'IP DigitalOcean',
  'ipset_htz.list': 'IP Hetzner', 'ipset_ovh.list': 'IP OVH', 'ipset-games.list': 'IP игровых серверов',
  'ipset_gamefilter.list': 'IP игровых серверов (gamefilter)', 'youtube.list': 'YouTube', 'google.list': 'Google',
  'discord.list': 'Discord', 'telegram.list': 'Telegram', 'instagram.list': 'Instagram', 'twitch.list': 'Twitch',
  'whatsapp.list': 'WhatsApp', 'blizzard.list': 'Blizzard', 'ea.list': 'EA', 'aws_game.list': 'Игры на AWS', 'ipset_meta.list': 'IP Meta',
  'exclude_special.list': 'Особые исключения',
};

const VAR_INFO = {
  ISP_INTERFACE: ['Интерфейс провайдера', 'Через какой интерфейс идёт трафик в интернет. Несколько — через пробел.'],
  NFQWS_EXTRA_ARGS: ['Режим основного профиля', 'Для каких сайтов работают профили «HTTP/HTTPS» и «QUIC» по спискам сайтов.'],
  TCP_PORTS: ['TCP-порты в nfqws2', 'Какие TCP-порты вообще отправлять в nfqws2 (правила iptables). Через запятую, диапазон — 5000:5010.'],
  UDP_PORTS: ['UDP-порты в nfqws2', 'То же для UDP.'],
  IPV6_ENABLED: ['Обрабатывать IPv6', ''],
  LOG_LEVEL: ['Отладочный журнал', 'Подробный вывод nfqws2 — только для поиска проблем, сильно нагружает журнал.'],
  NFQWS_ARGS_CUSTOM: ['Свои профили', 'Запускаются первыми, профили разделяет --new.'],
  NFQWS_ARGS: ['HTTP/HTTPS', 'Стратегия профилей HTTP/HTTPS (по спискам сайтов и по IP).'],
  NFQWS_ARGS_QUIC: ['QUIC', 'Стратегия профилей QUIC (UDP 443).'],
  NFQWS_ARGS_UDP: ['UDP: игры, звонки', 'Стратегия для UDP.'],
  NFQWS_ARGS_IPSET: ['IP-списки', 'Общие для профилей «по IP-спискам».'],
  NFQWS_BASE_ARGS: ['Параметры запуска', 'Общие для всего процесса: lua-скрипты, блобы.'],
  MODE_LIST: ['Списки сайтов', 'Общие для профилей «по спискам сайтов».'],
  MODE_ALL: ['Исключения режима «все сайты»', ''],
};
const ARG_VARS = ['NFQWS_ARGS_CUSTOM', 'NFQWS_ARGS', 'NFQWS_ARGS_QUIC', 'NFQWS_ARGS_UDP', 'NFQWS_ARGS_IPSET', 'NFQWS_BASE_ARGS'];
const MODES = [['$MODE_LIST', 'Только сайты из списков'], ['$MODE_ALL', 'Все сайты, кроме исключений'], ['$MODE_AUTO', 'Списки + найденные автоматически']];
const LEVEL_RANK = { error: 3, warning: 2, info: 1 };
const LIST_OPTS = ['hostlist', 'hostlist-exclude', 'ipset', 'ipset-exclude', 'hostlist-domains', 'hostlist-exclude-domains', 'ipset-ip', 'ipset-exclude-ip', 'hostlist-auto'];
const LIST_ROLE = { hostlist: 'сайты', 'hostlist-exclude': 'кроме сайтов', ipset: 'IP', 'ipset-exclude': 'кроме IP', 'hostlist-domains': 'сайты', 'hostlist-exclude-domains': 'кроме сайтов', 'ipset-ip': 'IP', 'ipset-exclude-ip': 'кроме IP', 'hostlist-auto': 'автосписок' };

const S = { auth: null, state: null, catalog: null, conf: null, dirty: false, check: null };
const wideMq = window.matchMedia('(min-width: 1100px)');
const isWide = () => wideMq.matches;

// append/replaceChildren превращают null в текст "null" — пропускаем пустые значения
for (const m of ['append', 'replaceChildren']) {
  const orig = Element.prototype[m];
  Element.prototype[m] = function (...kids) {
    return orig.apply(this, kids.flat(Infinity).filter((k) => k != null && k !== false));
  };
}
