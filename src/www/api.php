<?php
// nfqws2-ui — веб-интерфейс для nfqws2 (пакет nfqws2-keenetic). https://github.com/zemidala/nfqws2-ui
// API: один файл, без зависимостей кроме php8-cgi, php8-mod-session и php8-mod-curl.
// Вход — логин и пароль root роутера; сессия общая с nfqws-keenetic-web, если он установлен.

ini_set('memory_limit', '64M');
// В php.ini Entware display_errors = On: любое предупреждение печаталось прямо в ответ, JSON ломался,
// и страница падала с «Cannot read properties of undefined». Ошибки — в свой журнал, он попадает в отчёт
const PHP_LOG = '/tmp/nfqws-ui-php.log';
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', PHP_LOG);
if (@filesize(PHP_LOG) > 262144) {
  @file_put_contents(PHP_LOG, substr((string)@file_get_contents(PHP_LOG), -65536));
}

// Текст фатальной ошибки PHP, которой закончился запрос или задание; null — фатальной не было
function fatalText(): ?string
{
  $e = error_get_last();
  if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
    return null;
  }
  if (!function_exists('curl_init') && strpos($e['message'], 'curl_') !== false) {
    return phpCurlHint(phpCurlError() ?? '');
  }
  return 'Ошибка PHP: ' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')';
}

// Запрос, упавший фатальной ошибкой, отвечает обычной ошибкой в JSON — страница покажет её текст
register_shutdown_function(function () {
  if (getenv('NFQWS_UI_CLI') === false && !headers_sent() && ($t = fatalText()) !== null) {
    respond(['error' => $t], 500);
  }
});
// Время как на роутере. date() с именованным поясом в PHP для OpenWrt падает (segfault),
// поэтому берём смещение из системы и форматируем через gmdate()
$m = [];
preg_match("/^([+-])(\d\d)(\d\d)$/", trim((string)@shell_exec("date +%z")), $m);
define("TZ_OFFSET", $m ? ($m[1] === "-" ? -1 : 1) * ((int)$m[2] * 3600 + (int)$m[3] * 60) : 0);

define('REQUEST_BATCH', bin2hex(random_bytes(4)));

function ldate(string $fmt, ?int $ts = null): string
{
  return gmdate($fmt, ($ts ?? time()) + TZ_OFFSET);
}

const UI_VERSION = '1.31.0';

// Пути пакета nfqws2-keenetic. На OpenWrt — корень «/», в Entware (Keenetic) — «/opt».
define('ROOT', !is_file('/usr/bin/nfqws2') && is_file('/opt/usr/bin/nfqws2') ? '/opt' : '');
define('CONF_FILE', ROOT . '/etc/nfqws2/nfqws2.conf');
define('CONF_DIR', ROOT . '/etc/nfqws2');
define('LISTS_DIR', ROOT . '/etc/nfqws2/lists');
define('LUA_DIR', ROOT . '/etc/nfqws2/lua');
define('SNAP_DIR', ROOT . '/etc/nfqws2/.snapshots');
define('HIST_DIR', ROOT . '/etc/nfqws2/.history');
define('UI_CONF_DIR', ROOT . '/etc/nfqws-ui');
define('UI_SETTINGS', UI_CONF_DIR . '/settings.json');
define('UI_WEB', UI_CONF_DIR . '/web.json');   // порты и HTTPS — пишет nfqws-ui-setup
define('WEB_CONF', ROOT . '/etc/nfqws_web.conf');   // настройка входа, общая с nfqws-keenetic-web
define('NFQWS_BIN', ROOT . '/usr/bin/nfqws2');
define('PID_FILE', ROOT . '/var/run/nfqws2.pid');
define('INIT_SCRIPT', ROOT ? '/opt/etc/init.d/S51nfqws2' : '/etc/init.d/nfqws2-keenetic');
define('LOG_DIR', ROOT . '/var/log');
// PATH для фоновых заданий: в Entware программы лежат в /opt
define('JOB_PATH', (ROOT ? '/opt/sbin:/opt/bin:/opt/usr/sbin:/opt/usr/bin:' : '') . '/usr/sbin:/usr/bin:/sbin:/bin');
const PROTECTED_LISTS = ['user.list', 'exclude.list', 'auto.list', 'ipset.list', 'ipset_exclude.list'];

const GLOBAL_OPTS = ['user', 'uid', 'qnum', 'fastpath-workaround', 'lua-init', 'lua-gc', 'blob', 'debug',
  'bind-fix4', 'bind-fix6', 'daemon', 'pidfile', 'ctrack-timeouts', 'ctrack-disable', 'ipcache-lifetime',
  'ipcache-hostname', 'reasm-disable', 'writeable', 'dry-run', 'intercept', 'wsize', 'wssize'];

// Блобы, которые nfqws2 объявляет сам (zapret2 nfq2/nfqws.c, ApplyDefaultBlobs)
const BUILTIN_BLOBS = ['fake_default_tls', 'fake_default_http', 'fake_default_quic'];

// Переменные конфига, которые можно править формой
const CONF_VARS = ['ISP_INTERFACE', 'NFQWS_BASE_ARGS', 'NFQWS_ARGS_CUSTOM', 'NFQWS_ARGS', 'NFQWS_ARGS_QUIC',
  'NFQWS_ARGS_UDP', 'NFQWS_ARGS_IPSET', 'NFQWS_EXTRA_ARGS', 'TCP_PORTS', 'UDP_PORTS', 'IPV6_ENABLED', 'LOG_LEVEL',
  'MODE_LIST', 'MODE_ALL'];
// Переменные с аргументами nfqws2 — их проверяет линтер
const ARG_VARS = ['NFQWS_BASE_ARGS', 'NFQWS_ARGS_CUSTOM', 'NFQWS_ARGS', 'NFQWS_ARGS_QUIC', 'NFQWS_ARGS_UDP', 'NFQWS_ARGS_IPSET'];
const CACHE_DIR = '/tmp/nfqws-ui-cache';
const CACHE_VER = 6;  // увеличить при изменении разбора справки/lua/проверок

// The server is split into sections in lib/ (one file each). They are loaded in this order: a later one may use
// constants of an earlier one. API_FILE — this file: background jobs start it again (php-cgi -f API_FILE)
define('API_FILE', __FILE__);
const LIB = ['core', 'snapshots', 'service', 'conf', 'lists', 'check', 'devices', 'exclude', 'lint', 'adapt', 'listcheck', 'fixes', 'monitor', 'subs', 'tests', 'parallel', 'multi', 'configs', 'freeze', 'picks', 'auto', 'refine', 'diag', 'dns', 'dnsx', 'conns', 'update', 'state', 'report', 'learn', 'share'];
// Sections to load, in LIB order; null — all of them. Loaded once each
function libLoad(?array $names = null): void
{
  static $done = [];
  foreach (LIB as $lib) {
    if (!isset($done[$lib]) && ($names === null || in_array($lib, $names, true))) {
      $done[$lib] = true;
      require __DIR__ . "/lib/$lib.php";
    }
  }
}

// A plain POST from the page loads only what its command needs (lib/map.php, made by scripts/libmap.php): php-cgi
// starts anew for every request, and on a weak router compiling all sections takes most of a light request's time.
// Everything else (cron, background jobs, download, upload) loads all sections
$LIB_MAP = getenv('NFQWS_UI_CLI') === false && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !isset($_GET['upload'])
  && is_file(__DIR__ . '/lib/map.php') ? require __DIR__ . '/lib/map.php' : null;
libLoad(is_array($LIB_MAP) ? $LIB_MAP['base'] : null);

// When the server code was changed last (any of its files): saved check results older than this are not trusted
function uiCodeTime(): int
{
  static $t = null;
  if ($t === null) {
    $t = (int)@filemtime(API_FILE);
    foreach (LIB as $lib) {
      $t = max($t, (int)@filemtime(__DIR__ . "/lib/$lib.php"));
    }
  }
  return $t;
}

// ================= запуск по расписанию =================
// cron (ставит nfqws-ui-setup): NFQWS_UI_CLI=scan|daily php-cgi -q -f /www/nfqws-ui/api.php

$cli = getenv('NFQWS_UI_CLI');
if ($cli !== false && !isset($_SERVER['REQUEST_METHOD'])) {
  if ($cli === 'test') {
    testJob();
    autoFinish();
    exit(0);
  }
  if ($cli === 'guard') {
    guardJob();
    exit(0);
  }
  if ($cli === 'guard_deadline') {
    pendingExpire(0);
    exit(0);
  }
  if ($cli === 'push') {
    remotePush();
    exit(0);
  }
  if ($cli === 'nfqupd') {
    nfqUpdateJob();
    exit(0);
  }
  if ($cli === 'dns') {
    dnsJob();
    if (is_file(DNSX_STOP)) {   // stopped during the quick part: the extended one does not start
      file_put_contents(DNSX_FILE, json_encode(['running' => false, 'stopped' => true, 'started' => time(), 'finished' => time(), 'servers' => []]));
      @unlink(DNSX_STOP);
    } else {
      dnsxJob();
    }
    exit(0);
  }
  if ($cli === 'dnsx') {
    dnsxJob();
    exit(0);
  }
  if ($cli === 'conns') {
    connJob();
    exit(0);
  }
  seenInfo();   // первым — как и в state()
  pendingExpire(60);   // задание проверки после перезапуска могло погибнуть — откат не должен потеряться
  devicesEnsure();     // nfqws2 restarted outside the interface or the router rebooted
  interceptCron();     // jumps into the nfqws2 chains lost after a firewall rebuild — put back even with the page closed
  learnKeep();         // what «learn» learned: the /tmp copy next to nfqws2.conf, so it survives a reboot
  historyScan('auto');
  nfqLogsTrim();
  monitorRun(false);
  autoTick();
  notifyFlush();   // то, что не ушло в прошлые проходы
  // новая версия nfqws2: в сети не чаще раза в 12 ч (после ошибки — 10 мин); в каждом проходе, а не раз в сутки,
  // иначе после обновления интерфейса метка «↑» появлялась только наутро
  nfqUpdateCheck(false);
  if ($cli === 'daily') {
    snapshotCreate('ежедневный', true);
    remotePush();   // раз в сутки — дослать то, что не ушло (NAS был выключен)
    if (uiSettings()['subs']) {
      subsRun();
    }
    updateCheck(false);
    asnRun();
    shareDaily();   // «help the base» (off by default): what learn learned well and «works» marks of base strategies
  }
  exit(0);
}

// ================= команды =================

// Сессии — в своей папке: уборка сессий любого другого PHP в общей /tmp (на Keenetic — nfqws-keenetic-web)
// удаляет файлы старше СВОЕГО срока и выкидывала бы из интерфейса раньше нашего
$life = sessionLife();
@mkdir(SESS_DIR, 0700, true);
// До 1.11 сессии лежали в общей /tmp: переносим вход оттуда, иначе обновление выкидывает из интерфейса
$sid = (string)($_COOKIE[session_name()] ?? '');
if (preg_match('/^[a-zA-Z0-9,-]{20,128}$/', $sid) && !is_file(SESS_DIR . "/sess_$sid") && is_file("/tmp/sess_$sid")
  && strpos((string)@file_get_contents("/tmp/sess_$sid"), 'auth|b:1;') !== false && @copy("/tmp/sess_$sid", SESS_DIR . "/sess_$sid")) {
  @chmod(SESS_DIR . "/sess_$sid", 0600);
}
ini_set('session.save_path', SESS_DIR);
ini_set('session.gc_maxlifetime', (string)$life);
session_set_cookie_params(['lifetime' => $life, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
session_start();
$authed = !authEnabled() || !empty($_SESSION['auth']);
// cookie продлеваем, пока интерфейсом пользуются: иначе он истёк бы через срок от входа, а не от последнего действия
if ($authed && authEnabled() && time() - ($_SESSION['ck'] ?? 0) > min(3600, intdiv($life, 10))) {
  $_SESSION['ck'] = time();
  setcookie(session_name(), session_id(), ['expires' => time() + $life, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
}

// Скачивание архива: GET api.php?download=current|<id снимка>
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['download'])) {
  session_write_close();
  if (!$authed) {
    fail('auth', 401);
  }
  $id = (string)$_GET['download'];
  if ($id === 'current') {
    historyScan('auto');
    $file = tempnam('/tmp', 'nfqarch');
    exec('tar -czf ' . escapeshellarg($file) . ' -C / ' . implode(' ', array_map('escapeshellarg', snapPaths())));
    $name = 'nfqws2-' . ldate('Y-m-d-His') . '.tar.gz';
  } else {
    $file = snapArchive($id);
    $name = "nfqws2-snapshot-$id.tar.gz";
  }
  header('Content-Type: application/gzip');
  header('Content-Disposition: attachment; filename="' . $name . '"');
  header('Content-Length: ' . filesize($file));
  header('Cache-Control: no-store');
  readfile($file);
  if ($id === 'current') {
    unlink($file);
  }
  exit();
}

// Восстановление из загруженного архива: POST multipart на api.php?upload=restore
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['upload'] ?? '') === 'restore') {
  session_write_close();
  if (!$authed) {
    fail('auth', 401);
  }
  $f = $_FILES['archive'] ?? null;
  if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 20 * 1048576) {
    fail('Файл не загрузился (не больше 20 МБ)');
  }
  respond(restoreArchive($f['tmp_name'], 'из файла ' . basename($f['name']), false, [], true) + ['lint' => lintCurrent()]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  fail('POST only', 405);
}
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in) || !is_string($in['cmd'] ?? null)) {
  fail('bad request');
}
$cmd = $in['cmd'];
if (is_array($LIB_MAP)) {
  libLoad($LIB_MAP['cmd'][$cmd] ?? null);   // a command not in the map loads all sections
}
$str = function (string $k) use ($in): string {
  if (!is_string($in[$k] ?? null)) {
    fail("нет параметра $k");
  }
  return $in[$k];
};

if ($cmd === 'login') {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  if (loginBlocked($ip)) {
    fail('Слишком много неудачных попыток. Подождите 10 минут.', 429);
  }
  if (!authenticate($str('user'), $str('password'))) {
    loginBlocked($ip, true);
    sleep(2);
    fail('Неверный логин или пароль. Вход — под root с его паролем (на Asuswrt-Merlin — под логином администратора роутера); если у root нет пароля, задайте его командой passwd.', 401);
  }
  session_regenerate_id(true);
  $_SESSION['auth'] = true;
  $_SESSION['ck'] = time();
  respond(['ok' => true]);
}
if ($cmd === 'logout') {
  $_SESSION['auth'] = false;
  respond(['ok' => true]);
}
session_write_close();
if ($cmd === 'session') {
  respond(['auth' => $authed, 'auth_enabled' => authEnabled()]);
}
if (!$authed) {
  fail('auth', 401);
}

switch ($cmd) {
  case 'state':
    respond(state());

  case 'check':
    $host = cleanHost($str('host'));
    if (!validHost($host)) {
      fail('Введите домен (например, youtube.com) или IP');
    }
    $isIp = isIp($host);
    $ips = $isIp ? [$host] : (gethostbynamel($host) ?: []);
    $profiles = currentProfiles();
    $cache = new ListCache();
    $routes = checkRoutes($profiles, $host, $ips, $cache);
    // В каких списках (используемых и нет) уже есть сайт
    $in_lists = [];
    foreach (listsInventory($profiles) as $l) {
      if (!$l['exists']) {
        continue;
      }
      $hit = $l['kind'] === 'ip' ? ($ips ? ipsIn($ips, [$l['path']], [], $cache) : null)
        : (!$isIp ? hostIn($host, [$l['path']], [], $cache) : null);
      if ($hit) {
        $in_lists[] = ['list' => $l['name'], 'entry' => $hit['entry'], 'used' => (bool)$l['used']];
      }
    }
    respond(['host' => $host, 'ips' => $ips, 'routes' => $routes, 'in_lists' => $in_lists, 'podkop' => $isIp ? null : podkopRoute($host)]);

  case 'probe':
    $host = cleanHost($str('host'));
    if (!validHost($host)) {
      fail('bad host');
    }
    $r = probe($host);
    // не открылся — не подменён ли адрес; тогда и маршрут через профили — по настоящему адресу
    if (!$r['ok'] && ($sp = dnsSpoof($host))) {
      $r['dns'] = $sp;
      $r['routes'] = checkRoutes(currentProfiles(), $host, $sp['real'], new ListCache());
    } elseif (!$r['ok'] && $r['reason'] !== REASON_FREEZE && !str_contains((string)$r['reason'], 'DNS')) {
      // не открылся через профиль — а мимо nfqws2? Тогда сайт не заблокирован, а профиль его ломает
      $ips = isIp($host) ? [$host] : (gethostbynamel($host) ?: []);
      $rt = matchRoute(currentProfiles(), $host, $ips, 'tcp', 443, 'tls', new ListCache());
      if ($rt['profile'] && ($d = probeDirect($host, $ips))) {
        $r['direct'] = $d + ['profile' => $rt['profile']];
      }
    }
    respond($r);

  case 'exclude_preview':
    session_write_close();
    respond(excludeOptions(cleanHost($str('host')), (int)($in['profile'] ?? 0)));

  case 'exclude_apply':
    respond(excludeApply(cleanHost($str('host')), (int)($in['profile'] ?? 0), $str('mode')));

  case 'diag':
    $host = cleanHost($str('host'));
    curlRequire();
    if (!validHost($host) || isIp($host)) {
      fail('Введите имя сайта, например rutracker.org');
    }
    $step = $str('step');
    $GLOBALS['PROBE_TLS12'] = !empty($in['tls12']);   // «as old devices»: every request of the diagnosis over TLS 1.2
    $ip = is_string($in['ip'] ?? null) ? $in['ip'] : '';
    if (in_array($step, ['direct', 'sni', 'http', 'quic'], true) && !isIp4($ip)) {
      fail('нет адреса сайта');
    }
    switch ($step) {
      case 'dns':
        respond(['host' => $host] + diagDns($host));
      case 'tcp':
        $ips = array_slice(array_values(array_filter(is_array($in['ips'] ?? null) ? $in['ips'] : [], fn($x) => is_string($x) && isIp4($x))), 0, 3);
        respond(['results' => diagTcp($ips)]);
      case 'direct':
        respond(diagBypass(fn() => diagDirect($host, $ip)));
      case 'sni':
        respond(diagBypass(fn() => diagSni($host, $ip)));
      case 'http':
        respond(diagBypass(fn() => diagHttp($host, $ip)));
      case 'via':
        respond(diagVia($host));
      case 'quic':
        respond(diagQuic($host, $ip));
      case 'other':
        respond(diagOther($host, array_values(array_filter(is_array($in['tried'] ?? null) ? $in['tried'] : [], fn($x) => is_string($x) && isIp4($x)))));
      case 'verdict':
        respond(diagVerdict(is_array($in['r'] ?? null) ? $in['r'] : []));
    }
    fail('неизвестная ступень');

  case 'list_get':
    $path = editablePath($str('name'));
    if (!is_file($path)) {
      fail('Файл не найден', 404);
    }
    $content = file_get_contents($path);
    respond(['name' => basename($path), 'content' => $content, 'mtime' => filemtime($path), 'issues' => listUsed($path) ? lintList(basename($path), $content) : []]);

  case 'list_add':
  case 'list_remove':
    $path = editablePath($str('name'));
    if (!is_file($path)) {
      fail('Файл не найден', 404);
    }
    $items = is_array($in['items'] ?? null) ? $in['items'] : [];
    // пустой неподключённый список получает вид по тому, что в него добавляют
    $isIpList = listKind(basename($path), readListLines($path) ?: array_map('trim', array_filter($items, 'is_string'))) === 'ip';
    $clean = [];
    foreach ($items as $it) {
      if (!is_string($it)) {
        continue;
      }
      $it = $isIpList ? trim($it) : cleanHost($it);
      if ($it === '') {
        continue;
      }
      if ($isIpList ? !preg_match('#^[0-9a-fA-F:.]+(/\d{1,3})?$#', $it) : !validHost(ltrim($it, '^'))) {
        fail("Некорректная запись: $it");
      }
      $clean[$it] = true;
    }
    $text = rtrim(file_get_contents($path), "\n");
    $lines = $text === '' ? [] : explode("\n", $text);
    $existing = [];
    foreach ($lines as $l) {
      $existing[strtolower(trim($l))] = true;
    }
    $changed = 0;
    if ($cmd === 'list_add') {
      foreach (array_keys($clean) as $it) {
        if (!isset($existing[strtolower($it)])) {
          $lines[] = $it;
          $changed++;
        }
      }
    } else {
      $lines = array_values(array_filter($lines, function ($l) use ($clean, &$changed) {
        if (isset($clean[strtolower(trim($l))])) {
          $changed++;
          return false;
        }
        return true;
      }));
    }
    $note = ($cmd === 'list_add' ? 'добавлено: ' : 'удалено: ') . implode(', ', array_slice(array_keys($clean), 0, 5)) . (count($clean) > 5 ? ' и ещё ' . (count($clean) - 5) : '');
    if ($changed && !writeWithBackup($path, implode("\n", $lines), $note)) {
      fail('Не удалось записать файл', 500);
    }
    respond(['changed' => $changed]);

  case 'list_remove_lines':
    // Удаление строк по номерам: из повторов уходит только отмеченный.
    // Текст строки сверяется — если файл успели изменить, ничего не удаляем.
    $path = editablePath($str('name'));
    if (!is_file($path)) {
      fail('Файл не найден', 404);
    }
    $text = rtrim(file_get_contents($path), "\n");
    $lines = $text === '' ? [] : explode("\n", $text);
    $drop = [];
    foreach (is_array($in['lines'] ?? null) ? $in['lines'] : [] as $x) {
      $n = $x['n'] ?? null;
      if (!is_int($n) || !isset($lines[$n]) || !is_string($x['t'] ?? null) || trim($lines[$n]) !== trim($x['t'])) {
        fail('Список изменился — обновите страницу и повторите', 409);
      }
      $drop[$n] = trim($lines[$n]);
    }
    if (!$drop) {
      respond(['changed' => 0]);
    }
    $what = array_values($drop);
    $note = 'удалено: ' . implode(', ', array_slice($what, 0, 5)) . (count($what) > 5 ? ' и ещё ' . (count($what) - 5) : '');
    if (!writeWithBackup($path, implode("\n", array_diff_key($lines, $drop)), $note)) {
      fail('Не удалось записать файл', 500);
    }
    respond(['changed' => count($drop)]);

  case 'list_save':
    $path = editablePath($str('name'));
    if (!is_file($path)) {
      fail('Файл не найден', 404);
    }
    if (!writeWithBackup($path, $str('content'), (string)($in['note'] ?? 'правка текстом'))) {
      fail('Не удалось записать файл', 500);
    }
    respond(['ok' => true]);

  case 'list_create':
  case 'list_copy':
    $name = $str($cmd === 'list_copy' ? 'to' : 'name');
    if (!str_ends_with($name, '.list')) {
      $name .= '.list';
    }
    $path = editablePath($name);
    if (file_exists($path)) {
      fail('Список с таким именем уже есть');
    }
    $content = '';
    if ($cmd === 'list_copy') {
      $src = editablePath($str('name'));
      $content = (string)@file_get_contents($src);
    }
    beforeChange($cmd === 'list_copy' ? "копия {$str('name')} → $name" : "создан список $name");
    if (file_put_contents($path, $content) === false) {
      fail('Не удалось создать файл', 500);
    }
    historyScan('интерфейс', $cmd === 'list_copy' ? "копия {$str('name')}" : 'создан список');
    respond(['name' => $name]);

  case 'list_rename':
    $from = editablePath($str('name'));
    $to = $str('to');
    if (!str_ends_with($to, '.list')) {
      $to .= '.list';
    }
    $toPath = editablePath($to);
    if (!is_file($from)) {
      fail('Файл не найден', 404);
    }
    if (in_array(basename($from), PROTECTED_LISTS, true)) {
      fail('Стандартный список nfqws2-keenetic переименовать нельзя — на него ссылается стандартный конфиг. Сделайте копию под новым именем');
    }
    if (file_exists($toPath)) {
      fail('Список с таким именем уже есть');
    }
    // Ссылки на список в конфиге переписываем вместе с переименованием
    $text = file_get_contents(CONF_FILE);
    $newText = preg_replace('#' . preg_quote($from, '#') . '(?=[\s"]|$)#m', $toPath, $text);
    beforeChange("переименован {$str('name')} → $to");
    if (!rename($from, $toPath)) {
      fail('Не удалось переименовать', 500);
    }
    if ($newText !== $text && !writeWithBackup(CONF_FILE, $newText, "ссылки на {$str('name')} → $to")) {
      rename($toPath, $from);
      fail('Не удалось обновить ссылки в конфиге', 500);
    }
    historyScan('интерфейс', "переименован в $to");
    respond(['name' => $to, 'conf_changed' => $newText !== $text]);

  case 'list_move':
    // Перенос или копирование записей в другой список
    $from = editablePath($str('name'));
    $toPath = editablePath($str('to'));
    if (!is_file($from) || !is_file($toPath)) {
      fail('Файл не найден', 404);
    }
    $items = array_values(array_filter(is_array($in['items'] ?? null) ? $in['items'] : [], 'is_string'));
    $want = array_flip(array_map(fn($x) => strtolower(trim($x)), $items));
    $toText = rtrim(file_get_contents($toPath), "\n");
    $toLines = $toText === '' ? [] : explode("\n", $toText);
    $have = array_flip(array_map(fn($l) => strtolower(trim($l)), $toLines));
    foreach ($items as $it) {
      if (!isset($have[strtolower(trim($it))])) {
        $toLines[] = trim($it);
      }
    }
    $copy = !empty($in['copy']);
    $what = implode(', ', array_slice($items, 0, 5)) . (count($items) > 5 ? ' и ещё ' . (count($items) - 5) : '');
    writeWithBackup($toPath, implode("\n", $toLines), ($copy ? 'скопировано из ' : 'перенесено из ') . basename($from) . ": $what");
    if (!$copy) {
      $fromLines = array_filter(explode("\n", rtrim(file_get_contents($from), "\n")), fn($l) => !isset($want[strtolower(trim($l))]));
      writeWithBackup($from, implode("\n", $fromLines), 'перенесено в ' . basename($toPath) . ": $what");
    }
    respond(['moved' => count($items)]);

  case 'list_delete':
    $name = $str('name');
    $path = editablePath($name);
    foreach (listsInventory(currentProfiles()) as $l) {
      if ($l['name'] === $name && !$l['removable']) {
        fail(in_array($name, PROTECTED_LISTS, true) ? 'Стандартный список nfqws2-keenetic удалить нельзя — его можно очистить'
          : 'Этот список читают профили — сначала отключите его от них');
      }
    }
    beforeChange("удалён список $name");
    if (!@unlink($path)) {
      fail('Не удалось удалить', 500);
    }
    historyScan('интерфейс', 'список удалён');
    respond(['ok' => true]);

  case 'conf_get':
    $text = file_get_contents(CONF_FILE);
    $raw = confRawVars($text);
    $vars = [];
    foreach (CONF_VARS as $n) {
      $vars[$n] = $raw[$n] ?? '';
    }
    respond(['content' => $text, 'vars' => $vars, 'mtime' => filemtime(CONF_FILE), 'blobs_used' => blobsUsed()]);

  case 'conf_save_vars':
    [$text, $raw] = candidateFromVars(is_array($in['vars'] ?? null) ? $in['vars'] : []);
    saveConf($text, $raw, !empty($in['force']), (string)($in['note'] ?? 'правка настроек'));

  case 'conf_save_raw':
    $text = normalizeText($str('content'));
    saveConf($text, confRawVars($text), !empty($in['force']), (string)($in['note'] ?? 'правка конфига текстом'));

  case 'conf_format':
    $src = normalizeText($str('content'));
    if (substr_count($src, '"') % 2) {
      fail('В конфиге незакрытая кавычка — оформлять нельзя, сначала исправьте её (подсветка в редакторе покажет, где текст «съехал»)');
    }
    $f = confFormat($src);
    if ($f === null) {
      fail('Оформить не получилось: после оформления nfqws2 получил бы другие аргументы. Текст не изменён — это ошибка интерфейса, пришлите нам конфиг');
    }
    respond(['content' => $f, 'same' => $f === $src, 'lines' => count(array_diff_assoc(explode("\n", $f), explode("\n", $src)))]);

  case 'catalog':
    respond(['help' => helpInfo(), 'lua' => luaCatalog()]);

  case 'lint':
    [$text, $raw] = candidateFromVars(is_array($in['vars'] ?? null) ? $in['vars'] : []);
    respond(lintMark(lintConf($raw, $text)));

  case 'lint_raw':
    $text = normalizeText($str('content'));
    respond(lintMark(lintConf(confRawVars($text), $text)));

  case 'lint_hide':
    // «don't show» on a config note, or show it again (on = false); key comes from lintMark()
    $key = $str('key');
    if (!preg_match('/^[0-9a-f]{12}$/', $key)) {
      fail('Неверный ключ замечания');
    }
    $s = uiSettings();
    if (!empty($in['on'])) {
      $msg = $str('msg');
      $s['lint_hide'][$key] = preg_match('/^.{301}/us', $msg) && preg_match('/^.{0,300}/us', $msg, $cut) ? $cut[0] . '…' : $msg;   // by characters: no mbstring on the router
      $s['lint_hide'] = array_slice($s['lint_hide'], -200, null, true);
    } else {
      unset($s['lint_hide'][$key]);
    }
    saveUiSettings($s);
    respond(['lint' => lintCurrent()]);

  case 'load':
    session_write_close();
    respond(routerLoad());

  case 'conf_adapt':
    // Переделка текста из редактора под этот роутер — без сохранения
    respond(adaptConf(normalizeText($str('content'))));

  case 'list_lint':
    $path = editablePath($str('name'));
    respond(['issues' => listUsed($path) ? lintList(basename($path), is_string($in['content'] ?? null) ? $in['content'] : (string)@file_get_contents($path)) : []]);

  case 'list_fix':
    $path = editablePath($str('name'));
    if (!is_file($path)) {
      fail('Файл не найден', 404);
    }
    $lines = explode("\n", rtrim(file_get_contents($path), "\n"));
    $present = array_flip(array_map(fn($l) => strtolower(trim($l)), $lines));
    $fixed = 0;
    foreach (lintList(basename($path), implode("\n", $lines)) as $x) {
      if (!array_key_exists('fix', $x)) {
        continue;
      }
      if ($x['fix'] === null || isset($present[$x['fix']])) {
        $lines[$x['line']] = null;
      } else {
        $lines[$x['line']] = $x['fix'];
        $present[$x['fix']] = true;
      }
      $fixed++;
    }
    if ($fixed && !writeWithBackup($path, implode("\n", array_filter($lines, fn($l) => $l !== null)), "автоисправление: $fixed")) {
      fail('Не удалось записать файл', 500);
    }
    respond(['fixed' => $fixed]);

  case 'history':
    respond(['items' => historyOf(editablePath($str('name')))]);

  case 'history_get':
    $c = historyContent(editablePath($str('name')), $str('id'));
    if ($c === null) {
      fail('Версия не найдена', 404);
    }
    respond(['content' => $c]);

  case 'leftovers':
    respond(['items' => pkgLeftovers(true)]);

  case 'leftovers_delete':
    respond(['deleted' => pkgLeftoversDelete(is_array($in['names'] ?? null) ? $in['names'] : [])]);

  case 'fix_apply':
    // Находим то же замечание в свежей проверке текущего конфига и применяем его исправление
    $text = file_get_contents(CONF_FILE);
    $lint = lintConf(confRawVars($text), $text);
    $var = $in['var'] ?? null;
    $tok = isset($in['tok']) ? (int)$in['tok'] : null;
    foreach ($lint['issues'] as $x) {
      if ($x['var'] === $var && $x['tok'] === $tok && $x['msg'] === ($in['msg'] ?? '') && !empty($x['fix'])) {
        $new = applyConfFix($x, isset($in['choice']) ? (string)$in['choice'] : null);
        saveConf($new, confRawVars($new), true, 'исправление: ' . $x['fix']['label']);
      }
    }
    fail('Замечание уже исправлено или изменилось — обновите страницу', 409);

  case 'undo_last':
    $u = lastUndoable();
    if (!$u) {
      fail('Нечего отменять');
    }
    $versions = [];
    foreach ($u['events'] as $e) {
      if (!array_key_exists($e['path'], $versions)) {
        $versions[$e['path']] = $e['from'];
      }
    }
    $changed = restoreVersions($versions, 'отмена: ' . ($u['note'] ?: implode(', ', $u['files'])), $u['batch']);
    respond(['files' => $changed, 'note' => $u['note'], 'conf' => in_array('nfqws2.conf', $changed, true)]);

  case 'safe_restart':
    if (($cur = pendingGet()) && in_array($cur['state'], ['checking', 'waiting'], true)) {
      fail('Проверка уже идёт');
    }
    historyScan('auto');
    $T = confirmPoint();
    $mon = monitorData();
    $before = [];
    foreach (uiSettings()['monitor']['sites'] as $h) {
      $hist = $mon['sites'][$h] ?? [];
      $before[$h] = $hist ? end($hist)[1] : null;
    }
    $minutes = max(1, min(15, (int)($in['minutes'] ?? 3)));
    $p = ['id' => bin2hex(random_bytes(4)), 'state' => 'checking', 'T' => $T, 'versions' => rollbackVersions($T), 'started' => time(),
      'deadline' => time() + $minutes * 60, 'before' => $before, 'checks' => null, 'mem' => memFreeMb()];
    $p['mem_low'] = $p['mem'] !== null && $p['mem'] < LOWMEM_RESTART_MB;
    pendingSave($p);
    [$rc, $out, $fixed] = serviceRun('restart');
    exec('(env -i PATH=' . JOB_PATH . ' NFQWS_UI_CLI=guard php-cgi -q -f ' . escapeshellarg(API_FILE) . ' >/dev/null 2>&1 &)');
    respond(['ok' => $rc === 0, 'output' => $out, 'fixed' => $fixed, 'files' => array_map('basename', array_keys($p['versions']))]);

  case 'pending_get':
    // Опрос плашки «проверяю сайты»: раньше раз в 3 с шёл полный state — самый тяжёлый запрос
    pendingExpire(60);
    respond(['pending' => pendingView(), 'now' => time()]);

  case 'safe_confirm':
    $p = pendingGet();
    if ($p && in_array($p['state'], ['checking', 'waiting'], true)) {
      $p['state'] = 'confirmed';
      $p['finished'] = time();
      pendingSave($p);
    }
    @mkdir(UI_CONF_DIR, 0755, true);
    file_put_contents(CONFIRM_FILE, json_encode(['ts' => time()]));
    respond(['ok' => true]);

  case 'safe_rollback':
    // Откат сейчас: к состоянию до изменений (во время проверки) или к последнему подтверждённому
    $p = pendingGet();
    if (!$p || !in_array($p['state'], ['checking', 'waiting'], true)) {
      $T = confirmPoint();
      $p = ['id' => bin2hex(random_bytes(4)), 'state' => 'manual', 'T' => $T, 'versions' => rollbackVersions($T), 'started' => time()];
      if (!$p['versions']) {
        fail('С момента последнего рабочего состояния ничего не менялось');
      }
    }
    doRollback($p, 'по кнопке');
    respond(['files' => $p['rolled_files']]);

  case 'dup_scan':
    respond(dupScan());

  case 'test_start':
  case 'trace_start':
    $host = cleanHost($str('host'));
    if (!validHost($host) || isIp($host)) {
      fail('Введите имя сайта, например rutracker.org');
    }
    if (testRunning()) {
      fail('Тест уже идёт — дождитесь окончания или остановите его');
    }
    @mkdir(TEST_DIR, 0777, true);
    $diagLock = fopen(TEST_DIR . '/diag.lock', 'c');
    if ($diagLock && !flock($diagLock, LOCK_EX | LOCK_NB)) {
      fail('Сейчас идёт диагноз сайта — он использует те же проверочные правила. Повторите через несколько секунд.');
    }
    if ($diagLock) {
      flock($diagLock, LOCK_UN);
      fclose($diagLock);
    }
    $sets = array_values(array_intersect(is_array($in['sets'] ?? null) ? $in['sets'] : ['config', 'std'], ['config', 'ports', 'std', 'hist', 'other', 'community', 'ext', 'full']));
    $own = array_values(array_filter(is_array($in['steps'] ?? null) ? $in['steps'] : [], fn($t) => is_string($t) && preg_match('/^--lua-desync=[^\s"`$\\\\]+$/', $t)));
    // свои стратегии из формы подбора: до OWN_MAX штук, у каждой — только шаги --lua-desync
    $ownList = $cmd === 'test_start' ? ownFromRequest($in['own'] ?? null) : [];
    curlRequire();
    testLaunch(['type' => $cmd === 'trace_start' ? 'trace' : 'pick', 'host' => $host,
      'proto' => ($in['proto'] ?? '') === 'http' ? 'http' : (($in['proto'] ?? '') === 'quic' ? 'quic' : 'https'),
      'sets' => $own || $ownList ? $sets : ($sets ?: ['config', 'std']), 'steps' => $own, 'own' => $ownList,
      'repeats' => (int)($in['repeats'] ?? 3), 'refine' => !empty($in['refine']), 'tls12' => !empty($in['tls12'])]);
    respond(['ok' => true]);

  case 'multi_start':
    // подбор одной стратегии для нескольких сайтов (2–10), например из списка профиля
    if (testRunning()) {
      fail('Тест уже идёт — дождитесь окончания или остановите его');
    }
    if (diagBusy()) {
      fail('Сейчас идёт диагноз сайта — он использует те же проверочные правила. Повторите через несколько секунд.');
    }
    $hosts = [];
    foreach (is_array($in['hosts'] ?? null) ? $in['hosts'] : [] as $h) {
      $h = cleanHost((string)$h);
      if (validHost($h) && !isIp($h)) {
        $hosts[$h] = true;
      }
    }
    $hosts = array_slice(array_keys($hosts), 0, 10);
    if (count($hosts) < 2) {
      fail('Укажите хотя бы два сайта — для одного есть обычный подбор');
    }
    $list = null;
    if (is_string($in['list'] ?? null) && $in['list'] !== '') {
      $path = editablePath($in['list']);
      if (!is_file($path)) {
        fail('Список не найден', 404);
      }
      $list = ['name' => basename($path), 'path' => $path];
    }
    $sets = array_values(array_intersect(is_array($in['sets'] ?? null) ? $in['sets'] : [], ['config', 'ports', 'std', 'hist', 'community']));
    $ownList = ownFromRequest($in['own'] ?? null);   // own strategies alone are a valid run
    curlRequire();
    testLaunch(['type' => 'multi', 'host' => $list ? $list['name'] : 'сайтов: ' . count($hosts), 'hosts' => $hosts, 'list' => $list,
      'proto' => ($in['proto'] ?? '') === 'http' ? 'http' : 'https', 'sets' => $ownList ? $sets : ($sets ?: ['config', 'std']), 'own' => $ownList,
      'repeats' => (int)($in['repeats'] ?? 3), 'tls12' => !empty($in['tls12'])]);
    respond(['ok' => true]);

  case 'wide_start':
    // проверить найденную стратегию на других сайтах (до 10)
    if (testRunning()) {
      fail('Сейчас идёт подбор или другая проверка — дождитесь окончания');
    }
    if (diagBusy()) {
      fail('Сейчас идёт диагноз сайта — он использует те же проверочные правила. Повторите через несколько секунд.');
    }
    $steps = array_values(array_filter(is_array($in['steps'] ?? null) ? $in['steps'] : [], fn($t) => is_string($t) && preg_match('/^--lua-desync=[^\s"`$\\\\]+$/', $t)));
    if (!$steps) {
      fail('Нет стратегии для проверки');
    }
    $hosts = [];
    foreach (is_array($in['hosts'] ?? null) ? $in['hosts'] : [] as $h) {
      $h = cleanHost((string)$h);
      if (validHost($h) && !isIp($h)) {
        $hosts[$h] = true;
      }
    }
    $hosts = array_slice(array_keys($hosts), 0, 10);
    if (!$hosts) {
      fail('Укажите хотя бы один сайт, например rutracker.org');
    }
    // итог подбора, из которого запущена проверка, сохраняем — страница покажет его под новой проверкой
    $prev = testStatus();
    $pick = ($prev['type'] ?? '') === 'pick' ? $prev : (($prev['type'] ?? '') === 'wide' ? ($prev['pick'] ?? null) : null);
    curlRequire();
    testLaunch(['type' => 'wide', 'host' => $pick['host'] ?? $hosts[0], 'proto' => ($in['proto'] ?? '') === 'http' ? 'http' : 'https',
      'hosts' => $hosts, 'steps' => $steps, 'repeats' => (int)($in['repeats'] ?? ($pick['repeats'] ?? 3)), 'pick' => $pick]);
    respond(['ok' => true]);

  case 'cfg_fetch':
    // конфиг по ссылке на один файл (raw-файл на GitHub и т. п.); только текст, не больше CFG_TEXT_MAX
    session_write_close();
    $url = trim($str('url'));
    if (!preg_match('~^https?://[^\s/"\'`$]+/\S*$~', $url)) {
      fail('Нужна ссылка на файл, например https://raw.githubusercontent.com/…/nfqws2.conf');
    }
    $url = preg_replace('~^https://github\.com/([^/]+/[^/]+)/blob/~', 'https://raw.githubusercontent.com/$1/', $url);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_NOSIGNAL => 1,
      CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
      CURLOPT_HTTPHEADER => ['User-Agent: nfqws2-ui/' . UI_VERSION],
      CURLOPT_NOPROGRESS => false, CURLOPT_PROGRESSFUNCTION => fn($c, $dt, $d) => $d > CFG_TEXT_MAX ? 1 : 0]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code !== 200) {
      fail('Не удалось скачать: ' . ($code ? "HTTP $code" : ($err ?: 'нет ответа')));
    }
    if (strlen($body) > CFG_TEXT_MAX || str_contains($body, "\0") || !preg_match('/^[ \t]*NFQWS_\w+=/m', $body)) {
      fail('По ссылке не текст конфига nfqws2 (нет переменных NFQWS_…). Нужна ссылка на сам файл, а не на страницу с ним.');
    }
    respond(['text' => $body, 'name' => preg_replace('/\.conf$/', '', basename(parse_url($url, PHP_URL_PATH) ?: 'конфиг'))]);

  case 'cfg_start':
    if (testRunning()) {
      fail('Сейчас идёт подбор или другая проверка — дождитесь окончания');
    }
    if (diagBusy()) {
      fail('Сейчас идёт диагноз сайта — он использует те же проверочные правила. Повторите через несколько секунд.');
    }
    // the current config for comparison — unless the form unticked it (saves one config's time, no «better/worse»)
    $withMine = !array_key_exists('mine', $in) || !empty($in['mine']);
    $cfgs = $withMine ? [['name' => 'мой (сейчас)', 'text' => (string)file_get_contents(CONF_FILE), 'mine' => true]] : [];
    foreach (array_slice(is_array($in['configs'] ?? null) ? $in['configs'] : [], 0, CFG_MAX) as $n => $c) {
      $t = is_array($c) && is_string($c['text'] ?? null) ? $c['text'] : '';
      if (trim($t) === '') {
        continue;
      }
      if (strlen($t) > CFG_TEXT_MAX) {
        fail('Конфиг больше ' . (CFG_TEXT_MAX / 1024) . ' КБ — это не nfqws2.conf');
      }
      $name = trim(preg_replace('/[\x00-\x1f"`$\\\\]/', '', (string)($c['name'] ?? '')));
      $cfgs[] = ['name' => cutText($name !== '' ? $name : 'Конфиг ' . ($n + 1), 40), 'text' => $t];
    }
    if (count($cfgs) < ($withMine ? 2 : 1)) {
      fail('Добавьте хотя бы один конфиг для проверки');
    }
    $hosts = [];
    foreach (is_array($in['hosts'] ?? null) ? $in['hosts'] : [] as $h) {
      $h = cleanHost((string)$h);
      if (validHost($h) && !isIp($h)) {
        $hosts[$h] = true;
      }
    }
    $hosts = array_slice(array_keys($hosts), 0, CFG_HOSTS_MAX);
    if (!$hosts) {
      fail('Укажите хотя бы один сайт для проверки');
    }
    curlRequire();
    testLaunch(['type' => 'cfg', 'host' => 'конфигов: ' . (count($cfgs) - ($withMine ? 1 : 0)),'configs' => $cfgs, 'hosts' => $hosts, 'repeats' => (int)($in['repeats'] ?? 3)]);
    respond(['ok' => true]);

  case 'cfg_get':
    // идёт проверка конфигов — её состояние, иначе итог прошлой
    $s = testStatus();
    if (($s['type'] ?? '') === 'cfg' && in_array($s['state'] ?? '', ['running', 'starting'], true)) {
      respond($s + ['busy' => null]);
    }
    $f = json_decode((string)@file_get_contents(CFG_FILE), true);
    // другое задание идёт — запускать проверку сейчас нельзя
    $busy = in_array($s['state'] ?? '', ['running', 'starting'], true) && testRunning() ? ($s['type'] ?? 'pick') : null;
    respond((is_array($f) ? $f : ['state' => 'idle', 'type' => 'cfg']) + ['busy' => $busy]);

  case 'freeze_start':
    if (testRunning()) {
      fail('Сейчас идёт подбор или другая проверка — дождитесь окончания');
    }
    if (diagBusy()) {
      fail('Сейчас идёт диагноз сайта — он использует те же проверочные правила. Повторите через несколько секунд.');
    }
    // свои сайты и адреса (IPv4, можно с портом — например, свой VPS): до трёх
    $extra = [];
    $ips = [];
    foreach (array_slice(is_array($in['hosts'] ?? null) ? $in['hosts'] : [], 0, 3) as $h) {
      if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})(?::(\d{1,5}))?$/', trim((string)$h), $m)) {
        $port = (int)($m[2] ?? 443);
        if (!isIp4($m[1]) || ipReserved($m[1]) || $port < 1 || $port > 65535) {
          fail("Адрес {$m[0]}: нужен внешний IPv4-адрес, порт — от 1 до 65535");
        }
        $ips[] = ['ip' => $m[1], 'port' => $port];
        continue;
      }
      $h = cleanHost((string)$h);
      if (validHost($h) && !isIp($h)) {
        $extra[] = $h;
      }
    }
    $only = !empty($in['only']);
    if ($only && !$extra && !$ips) {
      fail('«Только свои»: впишите хотя бы один свой сайт или адрес');
    }
    curlRequire();
    testLaunch(['type' => 'freeze', 'host' => $only ? 'свои адреса' : 'опорные сети', 'sets' => [], 'extra' => $extra, 'ips' => $ips, 'only' => $only]);
    respond(['ok' => true]);

  case 'freeze_get':
    // идёт проба — её состояние, иначе итог прошлой
    $s = testStatus();
    if (($s['type'] ?? '') === 'freeze' && in_array($s['state'] ?? '', ['running', 'starting'], true)) {
      respond($s);
    }
    $f = json_decode((string)@file_get_contents(FREEZE_FILE), true);
    if (is_array($f) && !empty($f['nets'])) {
      $f['nets'] = freezeInConf($f['nets']);
    }
    respond(is_array($f) ? $f + ['type' => 'freeze'] : ['state' => 'idle', 'type' => 'freeze']);

  case 'freeze_join':
    session_write_close();
    respond(freezeJoin((int)($in['asn'] ?? 0), (int)($in['profile'] ?? 0)));

  case 'test_status':
    $s = testStatus();
    if (($s['state'] ?? '') === 'running' && !testRunning()) {
      $s['state'] = 'error';
      $s['error'] = 'задание прервалось';
    }
    respond($s);

  case 'curl_install':
    respond(curlInstall());

  case 'php_curl_install':
    respond(phpCurlInstall());

  case 'test_stop':
    @touch(TEST_DIR . '/stop');
    respond(['ok' => true]);

  case 'test_candidates':
    $proto = ($in['proto'] ?? '') === 'http' ? 'http' : 'https';
    respond(['config' => configCandidates($proto, !empty($in['ports'])), 'std' => array_map(fn($x) => ['name' => $x[0], 'steps' => $x[1]], $proto === 'http' ? STD_HTTP : STD_TLS),
      'catalog' => ['ext' => count(catalogCandidates('ext', $proto === 'http')), 'full' => count(catalogCandidates('full', $proto === 'http'))]]);

  case 'tests_history':
    // без host — все запуски без подробностей; с host — запуски одного сайта целиком
    $items = picksLoad();
    $host = cleanHost((string)($in['host'] ?? ''));
    if ($host !== '') {
      respond(['items' => array_values(array_filter($items, fn($e) => $e['host'] === $host))]);
    }
    respond(['items' => array_map(function ($e) {
      $e['works'] = count(array_filter($e['results'] ?? [], fn($r) => !empty($r['steps']) && $r['ok'] >= ($e['repeats'] ?? 1)));
      unset($e['results']);
      return $e;
    }, $items)]);

  case 'picks_delete':
    $host = cleanHost((string)($in['host'] ?? ''));
    picksSave($host === '' ? [] : array_filter(picksLoad(), fn($e) => $e['host'] !== $host));
    respond(['ok' => true]);

  case 'pick_applied':
    // отметка в последнем подборе сайта: что применено и куда
    $host = cleanHost($str('host'));
    $steps = array_values(array_filter(is_array($in['steps'] ?? null) ? $in['steps'] : [], fn($t) => is_string($t) && str_starts_with($t, '--lua-desync=')));
    $items = picksLoad();
    foreach ($items as &$e) {
      if ($e['host'] === $host && $steps) {
        $e['applied'] = ['ts' => time(), 'name' => strategyName($steps), 'steps' => $steps, 'target' => preg_match('/^.{0,80}/us', $str('target'), $tm) ? $tm[0] : ''];
        picksSave($items);
        break;
      }
    }
    unset($e);
    respond(['ok' => true]);

  case 'snapshots':
    historyScan('auto');
    $idx = array_reverse(snapIndex());
    $cur = snapHashes();
    $rbSnap = nfqUpdData()['result']['snap'] ?? null;
    foreach ($idx as &$e) {
      // что отличается от текущего состояния; у снимков только на NAS хешей нет — неизвестно
      $e['local'] = $e['local'] ?? true;
      $e['remote'] = !empty($e['remote']);
      $e['partial'] = ROOT && empty($e['full']);   // Keenetic, до 1.15.4: без конфига и списков
      $e['rollback'] = $e['id'] === $rbSnap;   // nfqws2 rollback restores config and lists from it
      unset($e['full']);
      if (!isset($e['files'])) {
        $e['differs'] = null;
        continue;
      }
      $diff = [];
      foreach (array_unique(array_merge(array_keys($e['files']), array_keys($cur))) as $f) {
        if (($e['files'][$f] ?? null) !== ($cur[$f] ?? null)) {
          $diff[] = $f;
        }
      }
      $e['differs'] = $diff;
      $e['extra'] = snapExtra($e['files'], $cur);   // restoring deletes these
      unset($e['files']);
    }
    unset($e);
    $loc = array_filter($idx, fn($e) => $e['local']);
    $pub = remotePubKey();
    $rc = remoteCfg();
    respond(['items' => $idx, 'settings' => uiSettings()['snapshots'], 'dir' => SNAP_DIR,
      'total' => array_sum(array_map(fn($e) => $e['size'], $loc)), 'local' => count($loc),
      'remote' => ['tool' => remoteTool(), 'pub' => $pub, 'on' => remoteOn(), 'state' => remoteState(),
        'pending' => count(array_filter($loc, fn($e) => !$e['remote'])), 'stored' => count(array_filter($idx, fn($e) => $e['remote'])),
        'setup' => $pub && $rc['dir'] !== '' ? remoteSetupCmd($rc['dir'], $pub) : '']]);

  // Копии на NAS: настройки, ключ, проверка связи, отправка сейчас
  case 'remote_set':
    $s = uiSettings();
    $r = $s['snapshots']['remote'];
    $host = trim($str('host'));
    $user = trim($str('user'));
    $dir = rtrim(trim($str('dir')), '/');
    ($host === '' || preg_match('/^[A-Za-z0-9.:-]{1,253}$/', $host)) || fail('Адрес — имя или IP без пробелов');
    ($user === '' || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $user)) || fail('Имя пользователя — латиница, цифры, точка, дефис');
    ($dir === '' || (preg_match('#^/[A-Za-z0-9/_.-]+$#', $dir) && strpos($dir, '..') === false)) || fail('Папка — полный путь (/volume1/…), латиница, цифры, «/ _ . -»');
    $r = array_merge($r, ['host' => $host, 'user' => $user, 'dir' => $dir, 'port' => max(1, min(65535, (int)($in['port'] ?? 22))),
      'enabled' => !empty($in['enabled']), 'keep_local' => max(1, min(50, (int)($in['keep_local'] ?? $r['keep_local'])))]);
    ($r['enabled'] && ($host === '' || $user === '')) && fail('Чтобы включить отправку, укажите адрес и пользователя');
    $s['snapshots']['remote'] = $r;
    saveUiSettings($s);
    remoteKick();
    respond(['ok' => true]);

  case 'remote_key':
    respond(['pub' => remoteKeyCreate()]);

  case 'remote_test':
    $c = remoteCfg();
    ($c['host'] !== '' && $c['user'] !== '') || fail('Сначала укажите адрес и пользователя');
    is_file(REMOTE_KEY) || fail('Сначала создайте ключ роутера');
    [$ok, $msg] = remoteCall('ping', '', null, null, 20);
    if (!$ok || trim($msg) !== 'ok') {
      $hint = preg_match('/denied|authenticat/i', $msg) ? ' — ключ роутера не добавлен на NAS или добавлен не тому пользователю'
        : (preg_match('/refused|timed out|нет ответа|No route|unreachable/i', $msg) ? ' — NAS не отвечает по ssh: адрес, порт, включён ли SSH' : '');
      fail('Нет связи: ' . trim($msg) . $hint);
    }
    [$ok, $list] = remoteCall('list', '', null, null, 20);
    respond(['ok' => true, 'count' => $ok ? count(array_filter(explode("
", trim($list)))) : null]);

  case 'remote_push':
    respond(remotePush());

  case 'snapshot_create':
    historyScan('auto');
    $e = snapshotCreate('вручную', false, (string)($in['note'] ?? ''));
    if (!$e) {
      fail('Не удалось создать снимок', 500);
    }
    respond(['id' => $e['id']]);

  case 'snapshot_file':
    $c = snapReadFile($str('id'), $str('file'));
    if ($c === null) {
      fail('Файла нет в снимке', 404);
    }
    $cur = @file_get_contents('/' . $str('file'));
    respond(['content' => $c, 'current' => $cur === false ? null : $cur]);

  case 'snapshot_restore':
    $file = snapArchive($str('id'));
    respond(restoreArchive($file, 'из снимка ' . $str('id'), false, [], true) + ['lint' => lintCurrent()]);

  // Deletes snapshots from the router only. A snapshot with a NAS copy stays listed as NAS-only (the router key
  // cannot delete on the NAS, by design); a router-only snapshot is gone entirely.
  case 'snapshot_delete':
    $ids = array_values(array_unique(array_map('strval', (array)($in['ids'] ?? []))));
    $ids || fail('Не выбрано ни одного снимка');
    array_map('snapFileOf', $ids);   // validates ids
    $lock = snapLock();
    $idx = snapIndex();
    $keep = [];
    $deleted = $onNas = $freed = 0;
    foreach ($idx as $e) {
      if (!in_array($e['id'], $ids, true) || !($e['local'] ?? true)) {
        $keep[] = $e;
        continue;
      }
      $f = SNAP_DIR . "/{$e['id']}.tar.gz";
      $freed += is_file($f) ? filesize($f) : 0;
      @unlink($f);
      $deleted++;
      if (!empty($e['remote'])) {
        $e['local'] = false;
        unset($e['files'], $e['pinned']);
        $keep[] = $e;
        $onNas++;
      }
    }
    snapSaveIndex($keep);
    fclose($lock);
    respond(['deleted' => $deleted, 'on_nas' => $onNas, 'freed' => $freed]);

  case 'snapshot_pin':
    $idx = snapIndex();
    foreach ($idx as &$e) {
      if ($e['id'] === $str('id')) {
        $e['pinned'] = !empty($in['pinned']);
      }
    }
    unset($e);
    snapSaveIndex($idx);
    respond(['ok' => true]);

  case 'settings_get':
    $s = uiSettings();
    $s['notify']['tg_token'] = $s['notify']['tg_token'] ? '••••' : '';
    $s['notify']['proxy'] = maskProxy($s['notify']['proxy']);
    respond($s);

  case 'monitor_get':
    $s = uiSettings();
    respond(['settings' => $s['monitor'], 'data' => monitorData(), 'tg' => ['token_set' => $s['notify']['tg_token'] !== '', 'chat' => $s['notify']['tg_chat'],
      'via' => $s['notify']['via'], 'iface' => $s['notify']['iface'], 'proxy' => maskProxy($s['notify']['proxy'])],
      'sent' => (function () { $d = notifyData(); return ['queue' => $d['queue'], 'log' => array_reverse($d['log'])]; })()]
      // список интерфейсов нужен только странице «Уведомления»
      + (empty($in['ifaces']) ? [] : ['ifaces' => array_values(array_filter(netIfaces(), fn($x) => $x['ips'] && $x['kind'] !== 'bridge' && $x['kind'] !== 'wifi'))]));

  case 'monitor_set':
    $s = uiSettings();
    if (is_array($in['sites'] ?? null)) {
      $sites = [];
      foreach ($in['sites'] as $h) {
        $h = cleanHost((string)$h);
        if (validHost($h) && !isIp($h)) {
          $sites[$h] = true;
        }
      }
      $s['monitor']['sites'] = array_slice(array_keys($sites), 0, 30);
    }
    if (isset($in['interval'])) {
      $s['monitor']['interval'] = max(10, min(1440, (int)$in['interval']));
    }
    if (isset($in['enabled'])) {
      $s['monitor']['enabled'] = (bool)$in['enabled'];
    }
    if (is_string($in['tg_chat'] ?? null)) {
      $s['notify']['tg_chat'] = preg_replace('/[^0-9@A-Za-z_-]/', '', $in['tg_chat']);
    }
    if (is_string($in['tg_token'] ?? null) && $in['tg_token'] !== '••••') {
      $s['notify']['tg_token'] = preg_replace('/[^0-9A-Za-z:_-]/', '', $in['tg_token']);
    }
    if (is_string($in['tg_iface'] ?? null)) {
      $s['notify']['iface'] = preg_match('/^[A-Za-z0-9_.-]{1,15}$/', $in['tg_iface']) ? $in['tg_iface'] : '';
    }
    if (is_string($in['tg_proxy'] ?? null) && !str_contains($in['tg_proxy'], '••••')) {
      $px = trim($in['tg_proxy']);
      if ($px !== '') {
        // без схемы считаем SOCKS5; имя api.telegram.org пусть находит сам прокси — местный DNS может отдавать закрытый адрес
        $px = preg_match('#^[a-z0-9]+://#i', $px) ? $px : "socks5h://$px";
        $px = preg_replace('#^socks5://#i', 'socks5h://', $px);
        if (!preg_match('#^(socks5h|socks4a?|https?)://([^\s/@]+@)?(\[[0-9a-fA-F:]+\]|[A-Za-z0-9.-]+):\d{1,5}/?$#i', $px)) {
          fail('Прокси: нужен адрес вида socks5://адрес:порт или http://адрес:порт (логин и пароль — socks5://логин:пароль@адрес:порт)');
        }
      }
      $s['notify']['proxy'] = $px;
    }
    if (is_string($in['tg_via'] ?? null)) {
      $s['notify']['via'] = in_array($in['tg_via'], ['iface', 'proxy'], true) ? $in['tg_via'] : '';
      if ($s['notify']['via'] === 'iface' && $s['notify']['iface'] === '') {
        fail('Выберите интерфейс, через который отправлять уведомления');
      }
      if ($s['notify']['via'] === 'proxy' && $s['notify']['proxy'] === '') {
        fail('Укажите адрес прокси');
      }
    }
    saveUiSettings($s);
    respond(['ok' => true]);

  case 'monitor_run':
    $only = is_array($in['hosts'] ?? null) ? array_values(array_filter($in['hosts'], 'is_string')) : null;
    respond(['data' => monitorRun(true, $only)]);

  case 'auto_get':
    $s = uiSettings();
    $d = autoData();
    $offers = [];
    foreach ($d['offers'] as $h => $o) {
      $offers[] = ['host' => (string)$h] + $o;
    }
    $st = testStatus();
    respond(['settings' => $s['auto'], 'monitor' => ['enabled' => $s['monitor']['enabled'], 'sites' => count($s['monitor']['sites']), 'interval' => $s['monitor']['interval']],
      'tg' => $s['notify']['tg_token'] !== '' && $s['notify']['tg_chat'] !== '', 'offers' => $offers, 'queue' => $d['queue'], 'log' => $d['log'],
      'running' => testRunning() && !empty($st['auto']) ? $st['host'] : null]);

  case 'auto_set':
    $s = uiSettings();
    foreach (['enabled', 'apply'] as $k) {
      if (isset($in[$k])) {
        $s['auto'][$k] = (bool)$in[$k];
      }
    }
    if (isset($in['fails'])) {
      $s['auto']['fails'] = max(2, min(4, (int)$in['fails']));
    }
    if (isset($in['pause'])) {
      $s['auto']['pause'] = max(1, min(168, (int)$in['pause'] ?: 12));   // hours: 6, 12, 24 or own, up to a week
    }
    saveUiSettings($s);
    respond(['ok' => true]);

  case 'auto_apply':
    $host = cleanHost($str('host'));
    $o = autoData()['offers'][$host] ?? null;
    if (!$o) {
      fail('Для этого сайта нет найденной стратегии');
    }
    session_write_close();
    $r = !empty($o['list']) ? autoApplyList($host, $o['steps'], $o['list'], 'по кнопке') : autoApply($host, $o['steps'], 'по кнопке');
    if (!$r['ok']) {
      fail($r['text']);
    }
    respond($r);

  case 'auto_dismiss':
    $host = cleanHost($str('host'));
    $d = autoData();
    unset($d['offers'][$host]);
    autoSave($d);
    respond(['ok' => true]);

  case 'auto_clear':
    $d = autoData();
    $d['log'] = [];
    autoSave($d);
    respond(['ok' => true]);

  case 'provider_detect':
    $p = providerDetect();
    if (!$p || $p['provider'] === '') {
      fail('Не удалось определить провайдера — впишите название вручную');
    }
    respond($p);   // внешний адрес наружу интерфейса не отдаём

  case 'provider_set':
    $s = uiSettings();
    $s['provider'] = preg_match('/^[^\x00-\x1f"`$\\\\]{0,80}/u', trim((string)($in['provider'] ?? '')), $pm) ? $pm[0] : '';
    if (isset($in['asn'])) {
      $s['provider_asn'] = preg_match('/^(?:AS)?(\d{1,10})$/i', trim((string)$in['asn']), $am) && (int)$am[1] < 4294967296 ? (int)$am[1] : 0;
    }
    saveUiSettings($s);
    respond(['provider' => $s['provider'], 'asn' => $s['provider_asn']]);

  case 'seen_mark':
    // открытые новые функции: метка «новое» у них больше не показывается
    $s = uiSettings();
    $s['seen'] = seenInfo();
    foreach (array_slice(is_array($in['ids'] ?? null) ? $in['ids'] : [], 0, 50) as $id) {
      if (is_string($id) && preg_match('/^[a-z]{1,20}(:[a-z0-9_-]{1,40})?$/', $id) && !in_array($id, $s['seen']['open'], true)) {
        $s['seen']['open'][] = $id;
      }
    }
    $s['seen']['open'] = array_slice($s['seen']['open'], -300);
    if (preg_match('/^\d+\.\d+\.\d+$/', (string)($in['told'] ?? ''))) {
      $s['seen']['told'] = $in['told'];
    }
    saveUiSettings($s);
    respond(['seen' => $s['seen']]);

  case 'community_get':
    session_write_close();
    $asn = myAsn(!empty($in['detect']));
    $d = $asn ? communityLoad($asn, !empty($in['force'])) : ['items' => [], 'fetched' => 0, 'error' => null];
    $idx = communityIndex(!empty($in['force']));
    $items = $d['items'];
    foreach ($items as &$x) {
      $x['name'] = strategyName($x['steps']);
      $x['fresh'] = communityFresh($x);
      $x['skipped'] = $x['fails'] >= 2 && $x['fails'] > $x['reports'];   // подбор её не пробует
    }
    unset($x);
    usort($items, fn($a, $b) => [$a['skipped'], $b['fresh'], $b['reports'] - $b['fails'], $b['last']] <=> [$b['skipped'], $a['fresh'], $a['reports'] - $a['fails'], $a['last']]);
    respond(['asn' => $asn, 'provider' => uiSettings()['provider'], 'fetched' => $d['fetched'], 'error' => $d['error'],
      'total' => $idx['total'] ?? null, 'providers' => $idx['providers'] ?? null, 'items' => $items, 'repo' => COMMUNITY_REPO,
      'page' => COMMUNITY_REPO . '/blob/main/STRATEGIES.md', 'mine' => $items ? COMMUNITY_REPO . "/blob/main/providers/AS$asn.md" : null]);

  case 'share_info':
    // данные для формы «Поделиться»: AS провайдера, сеть сайта, версии
    session_write_close();
    $host = cleanHost($str('host'));
    $asn = myAsn(true);
    respond(['asn' => $asn, 'provider' => uiSettings()['provider'], 'target_asn' => validHost($host) && !isIp($host) ? hostAsn($host) : null,
      'nfqws2' => (string)packageVersion('nfqws2-keenetic'), 'ui' => UI_VERSION, 'repo' => COMMUNITY_REPO,
      // through the relay: whether it is set up, whether the site's name may go, what this router has set
      'relay' => shareRelay(), 'host_known' => validHost($host) && shareRelay() ? shareHostKnown($host) : false,
      'name' => uiSettings()['share']['name'], 'auto' => uiSettings()['share']['auto']]);

  case 'share_send':
    // «Поделиться» / «Подтвердить» / «Не сработала» through the relay, no GitHub account
    session_write_close();
    $steps = is_array($in['steps'] ?? null) ? array_values($in['steps']) : [];
    if (!$steps || count($steps) > 8 || array_filter($steps, fn($t) => !is_string($t) || !preg_match(COMMUNITY_STEP, $t))) {
      fail('Стратегия не подходит для базы: только шаги --lua-desync без путей к файлам, не больше восьми');
    }
    $kind = in_array($in['kind'] ?? '', ['share', 'works', 'fails'], true) ? $in['kind'] : 'share';
    $it = shareItem($kind, $steps, ($in['proto'] ?? '') === 'http' ? 'http' : 'tls', cleanHost($str('host')), is_int($in['net'] ?? null) ? $in['net'] : null);
    if (!$it) {
      fail('Провайдер не определён — откройте «Стратегии сообщества» и нажмите «Определить»');
    }
    respond(shareSend([$it]) + ['ok' => true, 'host' => $it['host']]);

  case 'share_get':
    session_write_close();
    $st = shareState();
    $ready = [];
    if (shareRelay()) {
      // what the next daily send would take from learn — shown, so it is clear before ticking «help the base».
      // The first-seen days are kept: the «two different days» count starts with the first look
      foreach (shareLearned($st) as $x) {
        $ready[] = ['host' => $x['host'], 'known' => shareHostKnown($x['host']), 'proto' => $x['proto'], 'name' => strategyName($x['steps'])];
      }
      shareStateSave($st);
    }
    respond(['relay' => shareRelay(), 'auto' => uiSettings()['share']['auto'], 'name' => uiSettings()['share']['name'],
      'stats' => shareRelay() ? shareStats(!empty($in['force'])) : null, 'last' => $st['last'], 'queued' => count($st['queue']), 'ready' => $ready]);

  case 'share_set':
    $s = uiSettings();
    if (array_key_exists('auto', $in)) {
      $s['share']['auto'] = (bool)$in['auto'];
    }
    if (array_key_exists('name', $in)) {
      $name = trim((string)$in['name']);
      // the base takes the same: letters, digits, space, «_» and «-», up to 24 — no links, no @
      if ($name !== '' && !preg_match('/^[0-9A-Za-zА-Яа-яЁё _-]{1,24}$/u', $name)) {
        fail('Подпись — до 24 знаков: буквы, цифры, пробел, «_» и «-»');
      }
      $s['share']['name'] = $name;
    }
    saveUiSettings($s);
    respond(['ok' => true, 'auto' => $s['share']['auto'], 'name' => $s['share']['name']]);

  case 'profile_check':
    respond(profileCheck(array_slice(is_array($in['tokens'] ?? null) ? $in['tokens'] : [], 0, 200)));

  case 'strategy_check':
    respond(strategyCheck(is_array($in['items'] ?? null) ? $in['items'] : [], ($in['proto'] ?? '') === 'http' ? 'http' : (($in['proto'] ?? '') === 'quic' ? 'quic' : 'https')));

  case 'report':
    session_write_close();
    set_time_limit(120);
    $sites = [];
    foreach (array_slice(is_array($in['sites'] ?? null) ? $in['sites'] : [], 0, 3) as $h) {
      $h = cleanHost((string)$h);
      if (validHost($h) && !isIp($h)) {
        $sites[] = $h;
      }
    }
    respond(['text' => reportText($sites, !empty($in['hide']), preg_replace('/[^0-9a-f]/', '', (string)($in['build'] ?? '')))]);

  case 'asn_get':
    // Список удалили (на странице «Списки» или вручную) — запись о нём больше не нужна.
    $s = uiSettings();
    $keep = array_values(array_filter($s['asn'], fn($x) => is_file(LISTS_DIR . '/' . $x['list'])));
    if (count($keep) !== count($s['asn'])) {
      $s['asn'] = $keep;
      saveUiSettings($s);
    }
    respond(['items' => $keep]);

  case 'asn_lookup':
    session_write_close();
    $r = asnResolve($str('q'));
    $a = asnFetch($r['asn']);
    respond(['asn' => $a['asn'], 'holder' => $a['holder'], 'count' => count($a['prefixes']), 'sample' => array_slice($a['prefixes'], 0, 12), 'v6' => $a['v6'],
      'via' => $r['via'], 'name' => "ipset_as{$a['asn']}.list", 'exists' => is_file(LISTS_DIR . "/ipset_as{$a['asn']}.list")]);

  case 'asn_create':
    session_write_close();
    $a = asnFetch((int)($in['asn'] ?? 0));
    if (!$a['prefixes']) {
      fail("У AS{$a['asn']} нет анонсированных префиксов — проверьте номер");
    }
    respond(asnWrite($a, !isset($in['auto']) || !empty($in['auto'])));

  case 'asn_set':
    $s = uiSettings();
    foreach ($s['asn'] as $k => $x) {
      if ($x['asn'] === (int)($in['asn'] ?? 0)) {
        if (!empty($in['forget'])) {
          unset($s['asn'][$k]);   // сам список остаётся — удалить его можно на странице «Списки»
        } else {
          $s['asn'][$k]['auto'] = !empty($in['auto']);
        }
      }
    }
    $s['asn'] = array_values($s['asn']);
    saveUiSettings($s);
    respond(['items' => $s['asn']]);

  case 'conns_devices':
    respond(['items' => connDevices(), 'noacct' => connNoAcct()]);

  case 'conns_start':
    respond(connStart((string)($in['ip'] ?? ''), (int)($in['secs'] ?? 60)));

  case 'conns_get':
    respond(connData());

  case 'conns_stop':
    respond(connStop());

  case 'learn_get':
    respond(learnInfo());

  case 'learn_forget':
    // one host of one key, or everything when no key
    learnForget(is_string($in['key'] ?? null) ? $in['key'] : null, is_string($in['host'] ?? null) ? $in['host'] : null);
    respond(learnInfo());

  case 'learn_convert':
    // the n-th circular of a variable → learn; saved with the usual check and snapshot, the page restarts with a check
    $text = learnConvert($str('var'), (int)($in['n'] ?? 0));
    saveConf($text, confRawVars($text), false, 'профиль: перебор стратегий → обучение');

  case 'dns_start':
    // own servers come with the start and are kept for the next checks (an empty list clears them)
    if (is_array($in['own'] ?? null)) {
      $own = dnsOwnParse($in['own']);
      if (is_string($own)) {
        fail($own);
      }
      $s = uiSettings();
      $s['dns_own'] = array_values(array_filter(array_map(fn($v) => trim((string)$v), array_slice($in['own'], 0, DNS_OWN_MAX)), 'strlen'));
      if (isset($in['only'])) {
        if ($in['only'] && !$s['dns_own']) {
          fail('«Только свои»: впишите хотя бы один свой сервер');
        }
        $s['dns_only'] = (bool)$in['only'];
      }
      saveUiSettings($s);
    }
    dnsStart();
    respond(dnsData());

  case 'dns_get':
    respond(dnsData() + ['own_servers' => uiSettings()['dns_own'], 'own_only' => !empty(uiSettings()['dns_only'])]);

  case 'dnsx_start':
    dnsxStart();
    respond(dnsxData());

  case 'dnsx_get':
    respond(dnsxData());

  case 'dnsx_stop':
    @touch(DNSX_STOP);
    respond(dnsxData());

  case 'devices_get':
    $d = devicesConf();
    respond(['keenetic' => ROOT !== '', 'new_on' => $d['new_on'], 'items' => ROOT !== '' ? [] : devicesList(),
      'active' => devicesActive($d), 'ok' => devicesEnsure(), 'running' => (bool)findPid()]);

  case 'devices_set':
    ROOT === '' || fail('На Keenetic устройства выбираются политикой доступа прошивки');
    $list = [];
    foreach ((array)($in['list'] ?? []) as $mac => $x) {
      $mac = strtolower(trim((string)$mac));
      if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) {
        fail("Не похоже на MAC-адрес: $mac");
      }
      $name = preg_match('/^.{0,40}/us', (string)($x['name'] ?? ''), $nm) ? preg_replace('/[^\p{L}\p{N} ._()-]/u', '', $nm[0]) : '';
      $list[$mac] = ['on' => !empty($x['on']), 'name' => $name] + (!empty($x['manual']) ? ['manual' => true] : []);
    }
    count($list) <= DEV_MAX || fail('Слишком много устройств: не больше ' . DEV_MAX);
    $s = uiSettings();
    $s['devices'] = ['new_on' => !empty($in['new_on']), 'list' => $list];
    saveUiSettings($s);
    $ok = devicesApply();
    $d = devicesConf();
    respond(['keenetic' => false, 'new_on' => $d['new_on'], 'items' => devicesList(), 'active' => devicesActive($d),
      'ok' => devicesActive($d) ? $ok : null, 'running' => (bool)findPid()]);

  case 'tg_chats':
    $r = tgChats();
    if (isset($r['error'])) {
      fail($r['error']);
    }
    respond($r);

  case 'tg_info':
    respond(tgInfo());

  case 'notify_test':
    $err = notifyTelegram('Проверка уведомлений nfqws2: всё работает.', false);
    if (!$err) {
      notifyFlush();   // связь есть — заодно дослать то, что ждёт
    }
    if ($err) {
      fail('Telegram: ' . $err);
    }
    respond(['ok' => true, 'via' => notifyVia(uiSettings()['notify'])]);

  case 'sub_set':
    $s = uiSettings();
    $list = basename(editablePath($str('list')));
    $url = trim((string)($in['url'] ?? ''));
    $s['subs'] = array_values(array_filter($s['subs'], fn($x) => $x['list'] !== $list));
    if ($url !== '') {
      if (!preg_match('#^https?://[^\s"\'<>]+$#', $url)) {
        fail('Ссылка должна начинаться с http:// или https://');
      }
      $s['subs'][] = ['list' => $list, 'url' => $url, 'last' => null, 'status' => null, 'error' => null, 'count' => null];
    }
    saveUiSettings($s);
    respond(['ok' => true]);

  case 'sub_run':
    $r = subsRun(basename(editablePath($str('list'))));
    respond(['result' => $r[0] ?? null]);

  case 'settings_set':
    $s = uiSettings();
    if (is_array($in['snapshots'] ?? null)) {
      $s['snapshots']['max_count'] = max(5, min(500, (int)($in['snapshots']['max_count'] ?? 50)));
      $s['snapshots']['max_days'] = max(1, min(3650, (int)($in['snapshots']['max_days'] ?? 30)));
    }
    if (isset($in['session'])) {
      if (!in_array((int)$in['session'], SESSION_LIVES, true)) {
        fail('Недопустимый срок');
      }
      $s['session'] = (int)$in['session'];
    }
    if (isset($in['restart_check'])) {
      $s['restart_check'] = (bool)$in['restart_check'];
    }
    saveUiSettings($s);
    respond($s);

  case 'history_log':
    historyScan('auto');
    $log = array_reverse(histLog());
    $file = $in['file'] ?? null;
    if (is_string($file) && $file !== '') {
      $log = array_values(array_filter($log, fn($e) => $e['file'] === $file));
    }
    respond(['items' => array_slice($log, 0, max(1, min(1000, (int)($in['limit'] ?? 200))))]);

  case 'history_diff':
    foreach (histLog() as $e) {
      if ($e['id'] === $str('id')) {
        respond(['from' => histObjGet($e['from']), 'to' => histObjGet($e['to']),
          'current' => isset($e['path']) && is_file($e['path']) ? file_get_contents($e['path']) : null]);
      }
    }
    fail('Нет такой записи', 404);

  case 'initial_get':
    // что изменится при «Вернуть как при установке»
    $iv = initialVersions();
    $files = [];
    foreach ($iv['versions'] ?? [] as $path => $h) {
      [$added, $removed] = lineDelta(is_file($path) ? (string)file_get_contents($path) : '', (string)histObjGet($h));
      $files[] = ['file' => basename($path), 'exists' => is_file($path), 'n_added' => count($added), 'n_removed' => count($removed)];
    }
    respond(['at' => $iv['at'] ?? null, 'files' => $files]);

  case 'initial_reset':
    if (($cur = pendingGet()) && in_array($cur['state'], ['checking', 'waiting'], true)) {
      fail('Идёт перезапуск с проверкой — дождитесь его конца');
    }
    historyScan('auto');
    $iv = initialVersions();
    if (!$iv || !$iv['versions']) {
      fail('С начала истории конфиг и списки не менялись — возвращать нечего');
    }
    snapshotCreate('перед изменением', true, 'перед «Вернуть как при установке»');   // крупное изменение — снимок всегда, а не раз в 15 минут
    $changed = restoreVersions($iv['versions'], 'как при установке (' . ldate('d.m.Y H:i', $iv['at']) . ')');
    respond(['files' => $changed]);

  case 'history_revert':
    // Вернуть файл к состоянию ДО выбранного изменения
    foreach (histLog() as $e) {
      if ($e['id'] !== $str('id') || empty($e['path'])) {
        continue;
      }
      $path = $e['path'];
      $prev = histObjGet($e['from']);
      if ($path === CONF_FILE) {
        if ($prev === null) {
          fail('Нет предыдущей версии конфига');
        }
        saveConf($prev, confRawVars($prev), true, 'откат изменения от ' . ldate('d.m H:i', $e['ts']));
      }
      if ($prev === null) {
        beforeChange('откат: удаление созданного файла');
        @unlink($path);
        historyScan('интерфейс', 'откат: файл удалён');
      } else {
        writeWithBackup($path, $prev, 'откат изменения от ' . ldate('d.m H:i', $e['ts']));
      }
      respond(['ok' => true]);
    }
    fail('Нет такой записи', 404);

  case 'service':
    $action = $str('action');
    if (!in_array($action, ['start', 'stop', 'restart', 'enable'], true)) {
      fail('bad action');
    }
    if ($action === 'enable') {
      exec(INIT_SCRIPT . ' enable 2>&1', $o, $rc);
      [$out, $fixed] = [implode("\n", $o), null];
    } else {
      [$rc, $out, $fixed] = serviceRun($action);
    }
    usleep(500000);
    respond(['ok' => $rc === 0, 'output' => $out, 'fixed' => $fixed]);

  case 'update_check':
    respond(updateCheck(!empty($in['force'])));

  case 'update_run':
    updateStart($str('version'));
    respond(['ok' => true]);

  case 'nfq_update_check':
    session_write_close();
    respond(nfqUpdateCheck(!empty($in['force'])));

  case 'nfq_update_run':
    nfqUpdateStart($str('version'), !empty($in['rollback']));
    respond(['ok' => true]);

  case 'nfq_update_status':
    $log = (string)@file_get_contents(NFQ_UPDATE_LOG);
    respond(nfqUpdateInfo() + ['log' => $log, 'exit' => preg_match('/\[exit (\d+)\]\s*$/', $log, $m) ? (int)$m[1] : null,
      'job' => nfqUpdData()['job'] ?? null]);

  case 'update_status':
    $log = (string)@file_get_contents(UPDATE_LOG);
    respond(['running' => updateRunning(), 'log' => $log,
      'exit' => preg_match('/\[exit (\d+)\]\s*$/', $log, $m) ? (int)$m[1] : null, 'version' => UI_VERSION]);

  case 'sysinfo':
    respond(['ifaces' => netIfaces()] + baseFiles());

  case 'doc':
    // README и история версий лежат в пакете рядом с интерфейсом (docs/), при разработке — в корне репозитория
    $name = ['readme' => 'README.md', 'changelog' => 'CHANGELOG.md'][$str('name')] ?? fail('Нет такого документа');
    $file = is_file(__DIR__ . "/docs/$name") ? __DIR__ . "/docs/$name" : dirname(__DIR__, 2) . "/$name";
    is_file($file) || fail('Документ не найден: ' . $name, 404);
    respond(['text' => file_get_contents($file)]);

  case 'log':
    // На Keenetic logread нет: системный журнал ведёт прошивка, его показывает веб-интерфейс роутера
    $out = null;
    if (!ROOT) {
      $out = [];
      exec('logread -e nfqws2 2>/dev/null | tail -n 300', $out);
    }
    $files = [];
    // только хвост: отладочный журнал nfqws2 растёт до сотен мегабайт, целиком он не влезает в память PHP
    foreach (glob(LOG_DIR . '/nfqws2*.log') ?: [] as $f) {
      $size = filesize($f);
      $tail = '';
      if ($fh = @fopen($f, 'rb')) {
        if ($size > 65536) fseek($fh, -65536, SEEK_END);
        $tail = (string)stream_get_contents($fh);
        fclose($fh);
        if ($size > 65536) $tail = substr($tail, strpos($tail, "\n") + 1);   // первая строка обрезана посередине
      }
      $lines = explode("\n", rtrim($tail, "\n"));
      $files[] = ['name' => basename($f), 'size' => $size, 'tail' => implode("\n", array_slice($lines, -200)) . "\n"];
    }
    respond(['syslog' => $out, 'files' => $files, 'logs' => nfqLogs()]);

  case 'log_clear':
    nfqLogCut($str('name'));
    respond(['logs' => nfqLogs()]);

  default:
    fail('unknown command', 404);
}
