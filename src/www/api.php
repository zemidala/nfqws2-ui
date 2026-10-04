<?php
// nfqws2-ui — веб-интерфейс для nfqws2 (пакет nfqws2-keenetic). https://github.com/zemidala/nfqws2-ui
// API: один файл, без зависимостей кроме php8-cgi, php8-mod-session и php8-mod-curl.
// Вход — логин и пароль root роутера; сессия общая с nfqws-keenetic-web, если он установлен.

ini_set('memory_limit', '64M');
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

const UI_VERSION = '1.5.0';

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

// Переменные конфига, которые можно править формой
const CONF_VARS = ['ISP_INTERFACE', 'NFQWS_BASE_ARGS', 'NFQWS_ARGS_CUSTOM', 'NFQWS_ARGS', 'NFQWS_ARGS_QUIC',
  'NFQWS_ARGS_UDP', 'NFQWS_ARGS_IPSET', 'NFQWS_EXTRA_ARGS', 'TCP_PORTS', 'UDP_PORTS', 'IPV6_ENABLED', 'LOG_LEVEL',
  'MODE_LIST', 'MODE_ALL'];
// Переменные с аргументами nfqws2 — их проверяет линтер
const ARG_VARS = ['NFQWS_BASE_ARGS', 'NFQWS_ARGS_CUSTOM', 'NFQWS_ARGS', 'NFQWS_ARGS_QUIC', 'NFQWS_ARGS_UDP', 'NFQWS_ARGS_IPSET'];
const CACHE_DIR = '/tmp/nfqws-ui-cache';
const CACHE_VER = 6;  // увеличить при изменении разбора справки/lua/проверок

// ================= общее =================

function fail(string $msg, int $code = 400): void
{
  respond(['error' => $msg], $code);
}

function respond(array $data, int $code = 200): void
{
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit();
}

function normalizeText(string $s): string
{
  $s = str_replace(["\r\n", "\r"], "\n", $s);
  $s = preg_replace("/\n{3,}/", "\n\n", $s);
  if ($s !== '' && substr($s, -1) !== "\n") {
    $s .= "\n";
  }
  return $s;
}

// Настройки веб-сервера интерфейса: порты, HTTPS, вход. Пишет nfqws-ui-setup.
function uiWeb(): array
{
  $w = json_decode((string)@file_get_contents(UI_WEB), true);
  return (is_array($w) ? $w : []) + ['port' => null, 'https_port' => null, 'legacy_port' => null, 'auth' => true];
}

// Вход по паролю root включён всегда, если его явно не выключили через nfqws-ui-setup
function authEnabled(): bool
{
  return uiWeb()['auth'] !== false;
}

// Вход только под root и только с паролем: без пароля у root интерфейс не пускает
function authenticate(string $username, string $password): bool
{
  if ($username !== 'root' || $password === '') {
    return false;
  }
  // На Keenetic это root из Entware (/opt/etc/shadow), а не учётная запись прошивки
  $users = @file(file_exists(ROOT . '/etc/shadow') ? ROOT . '/etc/shadow' : ROOT . '/etc/passwd') ?: [];
  $user = preg_grep('/^root:/', $users);
  if (!$user) {
    return false;
  }
  list(, $hash) = explode(':', array_pop($user));
  if (strlen($hash) < 13) {
    return false;
  }
  return hash_equals($hash, (string)crypt($password, $hash));
}

// Защита от перебора: не больше 5 неудачных попыток за 10 минут с одного адреса
const LOGIN_FAILS = '/tmp/nfqws-ui-login.json';
function loginBlocked(string $ip, bool $failed = false): bool
{
  $f = json_decode((string)@file_get_contents(LOGIN_FAILS), true) ?: [];
  $now = time();
  $f = array_filter(array_map(fn($l) => array_values(array_filter($l, fn($t) => $t > $now - 600)), $f));
  if ($failed) {
    $f[$ip][] = $now;
    @file_put_contents(LOGIN_FAILS, json_encode($f), LOCK_EX);
  }
  return count($f[$ip] ?? []) >= 5;
}

function isIp(string $s): bool
{
  // В сборке PHP для OpenWrt нет модуля filter
  return @inet_pton($s) !== false;
}

function cleanHost(string $h): string
{
  $h = strtolower(trim($h));
  $h = preg_replace('#^[a-z]+://#', '', $h);
  $h = explode('/', $h)[0];
  $h = preg_replace('/:\d+$/', '', $h);
  $h = preg_replace('/^\*\./', '', $h);
  return rtrim($h, '.');
}

function validHost(string $h): bool
{
  return $h !== '' && (isIp($h) || preg_match('/^(?=.{1,253}$)([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?\.)*[a-z0-9-]{1,63}$/', $h));
}


// ================= сохранность: снимки и история изменений =================
//
// Снимок — tar.gz с конфигом, списками, блобами и настройками интерфейса; делается перед каждым
// изменением через интерфейс (если с прошлого снимка что-то поменялось) и раз в сутки по cron.
// История — журнал изменений отдельных файлов с их версиями (сжатыми), включая правки вне интерфейса:
// их находит сканирование по cron и при каждом открытии интерфейса.

function uiSettings(): array
{
  $defaults = [
    'snapshots' => ['max_count' => 50, 'max_days' => 30],
    'monitor' => ['enabled' => true, 'interval' => 30, 'sites' => ['rutracker.org', 'youtube.com', 'discord.com', 'x.com']],
    'notify' => ['tg_token' => '', 'tg_chat' => ''],
    'auto' => ['enabled' => false, 'apply' => false, 'fails' => 2, 'pause' => 12],
    'provider' => '',
    'asn' => [],
    'subs' => [],
  ];
  $s = json_decode((string)@file_get_contents(UI_SETTINGS), true);
  $s = is_array($s) ? $s : [];
  $r = array_replace_recursive($defaults, $s);
  // списки берём целиком, иначе слияние по индексам вернёт «хвост» значений по умолчанию
  if (isset($s['monitor']['sites'])) {
    $r['monitor']['sites'] = array_values($s['monitor']['sites']);
  }
  if (isset($s['subs'])) {
    $r['subs'] = array_values($s['subs']);
  }
  if (isset($s['asn'])) {
    $r['asn'] = array_values($s['asn']);
  }
  return $r;
}

function saveUiSettings(array $s): void
{
  @mkdir(UI_CONF_DIR, 0755, true);
  file_put_contents(UI_SETTINGS, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

// Файлы, версии которых ведёт история: конфиг и списки (кроме огромных)
function trackedFiles(): array
{
  $r = [CONF_FILE];
  foreach (glob(LISTS_DIR . '/*.list') as $f) {
    if (is_file($f) && filesize($f) <= 2 * 1048576) {
      $r[] = $f;
    }
  }
  return $r;
}

function snapIndex(): array
{
  $i = json_decode((string)@file_get_contents(SNAP_DIR . '/index.json'), true);
  return is_array($i) ? $i : [];
}

function snapSaveIndex(array $idx): void
{
  file_put_contents(SNAP_DIR . '/index.json', json_encode(array_values($idx), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function snapFileOf(string $id): string
{
  if (!preg_match('/^\d{8}-\d{6}(-\d+)?$/', $id)) {
    fail('Нет такого снимка', 404);
  }
  return SNAP_DIR . "/$id.tar.gz";
}

function snapHashes(): array
{
  $h = [];
  foreach (array_merge(trackedFiles(), glob(CONF_DIR . '/blobs/*') ?: []) as $f) {
    $h[substr($f, 1)] = md5_file($f);
  }
  ksort($h);
  return $h;
}

function snapPaths(): array
{
  $p = ['etc/nfqws2/nfqws2.conf', 'etc/nfqws2/lists', 'etc/nfqws2/blobs'];
  if (is_dir(UI_CONF_DIR)) {
    $p[] = substr(UI_CONF_DIR, 1);
  }
  return array_values(array_filter($p, fn($x) => file_exists('/' . $x)));
}

// Создаёт снимок. $ifChanged — не создавать, если с прошлого снимка ничего не поменялось
function snapshotCreate(string $reason, bool $ifChanged = true, string $note = ''): ?array
{
  @mkdir(SNAP_DIR, 0700, true);
  $idx = snapIndex();
  $hashes = snapHashes();
  if ($ifChanged && $idx && end($idx)['files'] === $hashes) {
    return null;
  }
  $id = ldate('Ymd-His');
  for ($n = 2; is_file(SNAP_DIR . "/$id.tar.gz"); $n++) {
    $id = ldate('Ymd-His') . "-$n";
  }
  $file = SNAP_DIR . "/$id.tar.gz";
  exec('tar -czf ' . escapeshellarg($file) . ' -C / ' . implode(' ', array_map('escapeshellarg', snapPaths())) . ' 2>&1', $out, $rc);
  if ($rc !== 0 || !is_file($file)) {
    @unlink($file);
    return null;
  }
  $entry = ['id' => $id, 'ts' => time(), 'reason' => $reason, 'note' => $note, 'size' => filesize($file), 'files' => $hashes];
  $idx[] = $entry;
  snapPrune($idx);
  snapSaveIndex($idx);
  return $entry;
}

function snapPrune(array &$idx): void
{
  $cfg = uiSettings()['snapshots'];
  $minTs = time() - 86400 * max(1, (int)$cfg['max_days']);
  $keep = [];
  $n = count($idx);
  foreach ($idx as $i => $e) {
    $newest5 = $i >= $n - 5;
    $inCount = $i >= $n - max(5, (int)$cfg['max_count']);
    if ($newest5 || ($inCount && $e['ts'] >= $minTs) || !empty($e['pinned'])) {
      $keep[] = $e;
    } else {
      @unlink(SNAP_DIR . "/{$e['id']}.tar.gz");
    }
  }
  $idx = $keep;
}

// Содержимое одного файла из снимка
function snapReadFile(string $id, string $rel): ?string
{
  $file = snapFileOf($id);
  if (!is_file($file) || !preg_match('#^etc/nfqws2/(nfqws2\.conf|lists/[A-Za-z0-9_.-]+|blobs/[A-Za-z0-9_.-]+)$#', $rel)) {
    return null;
  }
  $out = shell_exec('tar -xzOf ' . escapeshellarg($file) . ' ' . escapeshellarg($rel) . ' 2>/dev/null');
  return $out === null ? null : $out;
}

// Проверяет архив и раскладывает его поверх текущих файлов (файлы, которых нет в архиве, не трогает)
function restoreArchive(string $archive, string $reason): array
{
  exec('tar -tzf ' . escapeshellarg($archive) . ' 2>&1', $list, $rc);
  if ($rc !== 0 || !$list) {
    fail('Это не архив tar.gz или он повреждён');
  }
  $allowed = '#^(etc/?|etc/nfqws2/?|etc/nfqws2/(nfqws2\.conf|lists/?|blobs/?|lists/[A-Za-z0-9_.-]+|blobs/[A-Za-z0-9_.-]+)|etc/nfqws-ui/?|etc/nfqws-ui/[A-Za-z0-9_.-]+)$#';
  foreach ($list as $e) {
    if (!preg_match($allowed, $e)) {
      fail("В архиве лишний файл: $e — восстановление отменено");
    }
  }
  historyScan('auto');
  snapshotCreate('перед восстановлением', false, $reason);
  $tmp = '/tmp/nfqws-ui-restore-' . bin2hex(random_bytes(4));
  mkdir($tmp, 0700);
  exec('tar -xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($tmp) . ' 2>&1', $o, $rc);
  $changed = [];
  foreach ($list as $e) {
    $src = "$tmp/$e";
    if (!is_file($src)) {
      continue;
    }
    $dst = '/' . $e;
    if (is_file($dst) && md5_file($dst) === md5_file($src)) {
      continue;
    }
    @mkdir(dirname($dst), 0755, true);
    copy($src, $dst);
    $changed[] = $e;
  }
  exec('rm -rf ' . escapeshellarg($tmp));
  historyScan('восстановление', $reason);
  return $changed;
}

// ---------- история изменений ----------

function histState(): ?array
{
  $s = json_decode((string)@file_get_contents(HIST_DIR . '/state.json'), true);
  return is_array($s) ? $s : null;
}

function histObjPut(string $content): string
{
  $h = md5($content);
  $f = HIST_DIR . "/obj/$h.gz";
  if (!is_file($f)) {
    @mkdir(HIST_DIR . '/obj', 0700, true);
    file_put_contents($f, gzencode($content, 6));
  }
  return $h;
}

function histObjGet(?string $h): ?string
{
  if ($h === null || !preg_match('/^[0-9a-f]{32}$/', $h)) {
    return null;
  }
  $c = @file_get_contents(HIST_DIR . "/obj/$h.gz");
  return $c === false ? null : gzdecode($c);
}

function lineDelta(string $a, string $b): array
{
  $la = array_count_values(array_filter(array_map('trim', explode("\n", $a)), 'strlen'));
  $lb = array_count_values(array_filter(array_map('trim', explode("\n", $b)), 'strlen'));
  $added = [];
  $removed = [];
  foreach ($lb as $l => $n) {
    if (($la[$l] ?? 0) < $n) {
      $added[] = $l;
    }
  }
  foreach ($la as $l => $n) {
    if (($lb[$l] ?? 0) < $n) {
      $removed[] = $l;
    }
  }
  return [$added, $removed];
}

// Сверяет файлы с последним известным состоянием и записывает изменения в журнал.
// $source === 'auto' — изменение сделано вне интерфейса: различаем обновление пакета и всё остальное
function historyScan(string $source, string $note = ''): int
{
  @mkdir(HIST_DIR, 0700, true);
  $state = histState();
  $cur = [];
  foreach (trackedFiles() as $f) {
    $cur[$f] = md5_file($f);
  }
  if ($state === null) {
    // Первый запуск: запоминаем текущие версии как исходные
    foreach ($cur as $f => $h) {
      histObjPut(file_get_contents($f));
    }
    file_put_contents(HIST_DIR . '/state.json', json_encode($cur, JSON_UNESCAPED_SLASHES));
    histAppend(['file' => '*', 'source' => 'интерфейс', 'note' => 'История изменений начата', 'from' => null, 'to' => null]);
    return 0;
  }
  $n = 0;
  foreach (array_unique(array_merge(array_keys($state), array_keys($cur))) as $f) {
    $old = $state[$f] ?? null;
    $new = $cur[$f] ?? null;
    if ($old === $new) {
      continue;
    }
    $newText = $new ? file_get_contents($f) : '';
    if ($new) {
      histObjPut($newText);
    }
    [$added, $removed] = lineDelta((string)histObjGet($old), $newText);
    $src = $source;
    $ts = time();
    if ($source === 'auto') {
      $ts = $new ? filemtime($f) : time();
      $src = 'вне интерфейса';
      foreach (glob(dirname($f) . '/*-opkg') as $o) {
        if (abs(filemtime($o) - $ts) < 30) {
          $src = 'обновление пакета';
          break;
        }
      }
    }
    histAppend(['ts' => $ts, 'file' => basename($f), 'path' => $f, 'source' => $src, 'note' => $note,
      'from' => $old, 'to' => $new, 'added' => array_slice($added, 0, 20), 'removed' => array_slice($removed, 0, 20),
      'n_added' => count($added), 'n_removed' => count($removed)]);
    $n++;
  }
  file_put_contents(HIST_DIR . '/state.json', json_encode($cur, JSON_UNESCAPED_SLASHES));
  return $n;
}

function histAppend(array $e): void
{
  // batch — один запрос к API; undo_of — это событие отменяет другой batch
  $e = ['id' => bin2hex(random_bytes(6)), 'ts' => $e['ts'] ?? time(), 'batch' => REQUEST_BATCH] + $e;
  if (!empty($GLOBALS['UNDO_OF'])) {
    $e['undo_of'] = $GLOBALS['UNDO_OF'];
  }
  file_put_contents(HIST_DIR . '/log.jsonl', json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
}

function histLog(): array
{
  $r = [];
  foreach (@file(HIST_DIR . '/log.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
    $e = json_decode($l, true);
    if (is_array($e)) {
      $r[] = $e;
    }
  }
  return $r;
}

// Запись файла через интерфейс: сначала фиксируем чужие правки и делаем снимок, потом пишем
function writeWithBackup(string $path, string $content, string $note = ''): bool
{
  $content = normalizeText($content);
  if (is_file($path) && file_get_contents($path) === $content) {
    return true;
  }
  beforeChange($note);
  $tmp = $path . '.tmp-ui';
  if (file_put_contents($tmp, $content) === false) {
    return false;
  }
  if (is_file($path)) {
    // сохраняем владельца/права (auto.list, например, пишет nobody)
    @chmod($tmp, fileperms($path) & 0777);
    @chown($tmp, fileowner($path));
  }
  if (!rename($tmp, $path)) {
    return false;
  }
  historyScan('интерфейс', $note);
  return true;
}

function beforeChange(string $note): void
{
  historyScan('auto');
  // История хранит каждую версию файла, а снимок — точка отката всего набора:
  // при серии быстрых правок достаточно одного снимка перед первой
  $idx = snapIndex();
  if (!$idx || time() - end($idx)['ts'] > 900) {
    snapshotCreate('перед изменением', true, $note);
  }
}

// Версии файла для диалога «История»: из журнала, шаблоны пакета и ручные .bak
function historyOf(string $path): array
{
  $base = basename($path);
  $dir = dirname($path);
  $r = [];
  foreach (array_reverse(histLog()) as $e) {
    if (($e['path'] ?? null) === $path && $e['to']) {
      $r[] = ['id' => 'h:' . $e['to'], 'kind' => 'history', 'name' => $e['note'] ?: $e['source'], 'source' => $e['source'], 'mtime' => $e['ts'], 'size' => null];
    }
  }
  foreach (scandir($dir) as $f) {
    $p = "$dir/$f";
    if (!is_file($p) || $f === $base) {
      continue;
    }
    if (in_array($f, [$base . '-opkg', $base . '-old', $base . '.apk-new'], true)) {
      $kind = 'template';
    } elseif (preg_match('/^' . preg_quote($base, '/') . '\.(bak[\w.-]*|working|orig)$/', $f)) {
      $kind = 'bak';
    } else {
      continue;
    }
    $r[] = ['id' => 'file:' . $f, 'kind' => $kind, 'name' => $f, 'mtime' => filemtime($p), 'size' => filesize($p)];
  }
  return $r;
}

function historyContent(string $path, string $id): ?string
{
  if (str_starts_with($id, 'h:')) {
    return histObjGet(substr($id, 2));
  }
  foreach (historyOf($path) as $item) {
    if ($item['id'] === $id) {
      return file_get_contents(dirname($path) . '/' . $item['name']);
    }
  }
  return null;
}

// Путь к редактируемому файлу по имени: nfqws2.conf или список
function editablePath(string $name): string
{
  if ($name === 'nfqws2.conf') {
    return CONF_FILE;
  }
  if (!preg_match('/^[A-Za-z0-9_.-]+\.list$/', $name)) {
    fail('Недопустимое имя файла');
  }
  return LISTS_DIR . '/' . $name;
}

// ================= процесс =================

function findPid(): ?int
{
  $pid = (int)trim((string)@file_get_contents(PID_FILE));
  if ($pid > 0 && str_starts_with((string)@file_get_contents("/proc/$pid/cmdline"), NFQWS_BIN . "\0")) {
    return $pid;
  }
  foreach (glob('/proc/[0-9]*/cmdline') as $f) {
    if (str_starts_with((string)@file_get_contents($f), NFQWS_BIN . "\0")) {
      return (int)basename(dirname($f));
    }
  }
  return null;
}

function processInfo(int $pid): array
{
  $started = null;
  $stat = (string)@file_get_contents("/proc/$pid/stat");
  if ($stat && preg_match('/^btime (\d+)/m', (string)@file_get_contents('/proc/stat'), $b)) {
    // поле 22 (starttime) в тиках с загрузки, USER_HZ = 100
    $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
    $started = (int)$b[1] + intdiv((int)$fields[19], 100);
  }
  preg_match('/^VmRSS:\s+(\d+)/m', (string)@file_get_contents("/proc/$pid/status"), $m);
  return ['pid' => $pid, 'started' => $started, 'rss_kb' => isset($m[1]) ? (int)$m[1] : null];
}

function runningArgs(int $pid): array
{
  $argv = explode("\0", rtrim((string)@file_get_contents("/proc/$pid/cmdline"), "\0"));
  array_shift($argv);
  return $argv;
}

// Версия установленного пакета: opkg (OpenWrt до 24.10, Entware) или apk (OpenWrt 25+)
function packageVersion(string $pkg): ?string
{
  $out = [];
  if (!ROOT && (is_file('/usr/bin/apk') || is_file('/sbin/apk'))) {
    exec('apk list -I ' . escapeshellarg($pkg) . ' 2>/dev/null', $out);
    foreach ($out as $l) {
      if (preg_match('/^' . preg_quote($pkg, '/') . '-(\d[^\s]*)/', $l, $m)) {
        return $m[1];
      }
    }
    return null;
  }
  exec((ROOT ? '/opt/bin/' : '') . 'opkg status ' . escapeshellarg($pkg) . ' 2>/dev/null', $out);
  foreach ($out as $l) {
    if (str_starts_with($l, 'Version: ')) {
      return substr($l, 9);
    }
  }
  return null;
}

// ================= конфиг =================

function tokens(string $s): array
{
  $s = trim($s);
  return $s === '' ? [] : preg_split('/\s+/', $s);
}

// Значения переменных так, как их видит init-скрипт (через sh)
function confValues(string $file = CONF_FILE): array
{
  $names = array_merge(CONF_VARS, ['MODE_AUTO', 'NFQUEUE_NUM', 'USER']);
  $cmd = '. ' . escapeshellarg($file) . ' >/dev/null 2>&1; for v in '
    . implode(' ', array_map(fn($n) => "\"\$$n\"", $names)) . "; do printf '%s\\001' \"\$v\"; done";
  $vals = explode("\x01", shell_exec('sh -c ' . escapeshellarg($cmd)) ?? '');
  $r = [];
  foreach ($names as $i => $n) {
    $r[$n] = $vals[$i] ?? '';
  }
  return $r;
}

// Сырые (не раскрытые) значения из текста конфига: NAME="..." (многострочно) или NAME=value
function confRawVars(string $text): array
{
  $r = [];
  preg_match_all('/^([A-Z_][A-Z0-9_]*)=("([^"]*)"|[^\n]*)/m', $text, $m, PREG_SET_ORDER);
  foreach ($m as $x) {
    $r[$x[1]] = isset($x[3]) && $x[2] !== '' && $x[2][0] === '"' ? $x[3] : trim($x[2]);
  }
  return $r;
}

function confSetVar(string $text, string $name, string $value): string
{
  $quoted = preg_match('/^[A-Za-z0-9_.:,\/-]*$/', $value) && !str_contains($value, "\n") && $value !== ''
    && in_array($name, ['TCP_PORTS', 'UDP_PORTS', 'IPV6_ENABLED', 'LOG_LEVEL'], true)
    ? $value : '"' . $value . '"';
  $re = '/^' . $name . '=("[^"]*"|[^\n]*)/m';
  if (preg_match($re, $text)) {
    return preg_replace_callback($re, fn() => "$name=$quoted", $text, 1);
  }
  return rtrim($text, "\n") . "\n$name=$quoted\n";
}

function splitNew(array $args): array
{
  $parts = [[]];
  foreach ($args as $a) {
    if ($a === '--new') {
      $parts[] = [];
    } else {
      $parts[count($parts) - 1][] = $a;
    }
  }
  return $parts;
}

function optName(string $arg): string
{
  return preg_replace('/^--([^=]+).*$/', '$1', $arg);
}

// Повторяет _startup_args() init-скрипта
function expectedProfiles(array $raw): array
{
  $v = array_map('tokens', $raw);
  $exp = [];
  if ($v['NFQWS_ARGS_CUSTOM']) {
    foreach (splitNew($v['NFQWS_ARGS_CUSTOM']) as $k => $p) {
      $exp[] = ['source' => 'NFQWS_ARGS_CUSTOM', 'part' => $k + 1, 'tokens' => $p];
    }
  }
  if ($v['NFQWS_ARGS_UDP']) {
    $exp[] = ['source' => 'NFQWS_ARGS_UDP', 'tokens' => $v['NFQWS_ARGS_UDP']];
  }
  if ($v['NFQWS_ARGS_QUIC']) {
    if ($v['NFQWS_ARGS_IPSET']) {
      $exp[] = ['source' => 'NFQWS_ARGS_QUIC', 'with' => 'NFQWS_ARGS_IPSET',
        'tokens' => array_merge($v['NFQWS_ARGS_QUIC'], $v['NFQWS_ARGS_IPSET'], ['--ipset-ip=0.0.0.0'])];
    }
    $exp[] = ['source' => 'NFQWS_ARGS_QUIC', 'with' => 'NFQWS_EXTRA_ARGS', 'tokens' => array_merge($v['NFQWS_ARGS_QUIC'], $v['NFQWS_EXTRA_ARGS'])];
  }
  if ($v['NFQWS_ARGS_IPSET']) {
    $exp[] = ['source' => 'NFQWS_ARGS', 'with' => 'NFQWS_ARGS_IPSET',
      'tokens' => array_merge($v['NFQWS_ARGS'], $v['NFQWS_ARGS_IPSET'], ['--ipset-ip=0.0.0.0'])];
  }
  $exp[] = ['source' => 'NFQWS_ARGS', 'with' => 'NFQWS_EXTRA_ARGS', 'tokens' => array_merge($v['NFQWS_ARGS'], $v['NFQWS_EXTRA_ARGS'])];
  return $exp;
}

// ================= порты =================

function parsePorts(?string $s): ?array
{
  if ($s === null) {
    return null;
  }
  $r = [];
  foreach (explode(',', $s) as $p) {
    $p = trim($p);
    if ($p === '*') {
      $r[] = [0, 65535];
    } elseif (preg_match('/^(\d+)-(\d+)$/', $p, $m)) {
      $r[] = [(int)$m[1], (int)$m[2]];
    } elseif (preg_match('/^\d+$/', $p)) {
      $r[] = [(int)$p, (int)$p];
    }
  }
  return normRanges($r);
}

function normRanges(array $r): array
{
  usort($r, fn($a, $b) => $a[0] - $b[0]);
  $out = [];
  foreach ($r as $x) {
    $n = count($out);
    if ($n && $x[0] <= $out[$n - 1][1] + 1) {
      $out[$n - 1][1] = max($out[$n - 1][1], $x[1]);
    } else {
      $out[] = $x;
    }
  }
  return $out;
}

function intersectRanges(array $a, array $b): array
{
  $out = [];
  foreach ($a as $x) {
    foreach ($b as $y) {
      $lo = max($x[0], $y[0]);
      $hi = min($x[1], $y[1]);
      if ($lo <= $hi) {
        $out[] = [$lo, $hi];
      }
    }
  }
  return normRanges($out);
}

function subtractRanges(array $a, array $b): array
{
  foreach ($b as $y) {
    $next = [];
    foreach ($a as $x) {
      if ($y[1] < $x[0] || $y[0] > $x[1]) {
        $next[] = $x;
        continue;
      }
      if ($y[0] > $x[0]) {
        $next[] = [$x[0], $y[0] - 1];
      }
      if ($y[1] < $x[1]) {
        $next[] = [$y[1] + 1, $x[1]];
      }
    }
    $a = $next;
  }
  return $a;
}

function rangesStr(array $r): string
{
  return implode(',', array_map(fn($x) => $x[0] === $x[1] ? (string)$x[0] : "$x[0]-$x[1]", $r));
}

function inRanges(int $port, array $r): bool
{
  foreach ($r as $x) {
    if ($port >= $x[0] && $port <= $x[1]) {
      return true;
    }
  }
  return false;
}

// ================= профили =================

function buildProfile(int $index, array $args): array
{
  $p = ['index' => $index, 'args' => $args, 'tcp' => null, 'udp' => null, 'l7' => null,
    'hostlists' => [], 'hostlist_excludes' => [], 'hostlist_domains' => [], 'hostlist_exclude_domains' => [],
    'ipsets' => [], 'ipset_excludes' => [], 'ipset_ips' => [], 'ipset_exclude_ips' => [],
    'autolist' => null, 'payloads' => [], 'desync' => [], 'other' => []];
  foreach ($args as $a) {
    $name = optName($a);
    $val = str_contains($a, '=') ? substr($a, strpos($a, '=') + 1) : '';
    switch ($name) {
      case 'filter-tcp': $p['tcp'] = parsePorts($val); break;
      case 'filter-udp': $p['udp'] = parsePorts($val); break;
      case 'filter-l7': $p['l7'] = explode(',', $val); break;
      case 'hostlist': $p['hostlists'][] = $val; break;
      case 'hostlist-exclude': $p['hostlist_excludes'][] = $val; break;
      case 'hostlist-domains': array_push($p['hostlist_domains'], ...explode(',', $val)); break;
      case 'hostlist-exclude-domains': array_push($p['hostlist_exclude_domains'], ...explode(',', $val)); break;
      case 'hostlist-auto': $p['autolist'] = $val; break;
      case 'ipset': $p['ipsets'][] = $val; break;
      case 'ipset-exclude': $p['ipset_excludes'][] = $val; break;
      case 'ipset-ip': array_push($p['ipset_ips'], ...explode(',', $val)); break;
      case 'ipset-exclude-ip': array_push($p['ipset_exclude_ips'], ...explode(',', $val)); break;
      case 'payload': $p['payloads'][] = $val; break;
      case 'lua-desync':
        $fn = explode(':', $val, 2);
        $p['desync'][] = ['fn' => $fn[0], 'params' => $fn[1] ?? '', 'payload' => end($p['payloads']) ?: null];
        break;
      default: $p['other'][] = $a;
    }
  }
  $p['has_host_filter'] = $p['hostlists'] || $p['hostlist_domains'] || $p['autolist'];
  $p['has_ip_filter'] = $p['ipsets'] || $p['ipset_ips'];
  $p['has_excludes'] = $p['hostlist_excludes'] || $p['hostlist_exclude_domains'] || $p['ipset_excludes'] || $p['ipset_exclude_ips'];
  return $p;
}

function protoPorts(array $p): array
{
  if ($p['tcp'] === null && $p['udp'] === null) {
    return ['tcp' => [[0, 65535]], 'udp' => [[0, 65535]]];
  }
  return ['tcp' => $p['tcp'] ?? [], 'udp' => $p['udp'] ?? []];
}

// Какие порты профиля забирают более ранние профили без списков
function analyzeShadowing(array &$profiles): void
{
  foreach ($profiles as $j => &$pj) {
    $pp = protoPorts($pj);
    $remaining = $pp;
    $taken = [];
    for ($i = 0; $i < $j; $i++) {
      $pi = $profiles[$i];
      if ($pi['has_host_filter'] || $pi['has_ip_filter']) {
        continue;
      }
      if ($pi['l7'] !== null && ($pj['l7'] === null || array_diff($pj['l7'], $pi['l7']))) {
        continue;
      }
      $ppi = protoPorts($pi);
      foreach (['tcp', 'udp'] as $proto) {
        $x = intersectRanges($remaining[$proto], $ppi[$proto]);
        if ($x) {
          $taken[] = ['by' => $pi['index'], 'proto' => $proto, 'ports' => rangesStr($x), 'excludes' => $pi['has_excludes']];
          $remaining[$proto] = subtractRanges($remaining[$proto], $x);
        }
      }
    }
    $pj['taken'] = $taken;
    $pj['remaining'] = ['tcp' => rangesStr($remaining['tcp']), 'udp' => rangesStr($remaining['udp'])];
    $all = !$remaining['tcp'] && !$remaining['udp'];
    $pj['state'] = !$taken ? 'active' : ($all ? (array_filter($taken, fn($t) => $t['excludes']) ? 'excludes-only' : 'dead') : 'partial');
    $pj['ports'] = ['tcp' => rangesStr($pp['tcp']), 'udp' => rangesStr($pp['udp'])];
  }
  unset($pj);
}

function loadProfiles(?int $pid, array $expected, array &$globals): array
{
  if ($pid === null) {
    $argsList = array_column($expected, 'tokens');
  } else {
    $argsList = [];
    foreach (splitNew(runningArgs($pid)) as $part) {
      $prof = [];
      foreach ($part as $a) {
        if (in_array(optName($a), GLOBAL_OPTS, true)) {
          $globals[] = $a;
        } else {
          $prof[] = $a;
        }
      }
      $argsList[] = $prof;
    }
  }
  $profiles = [];
  $used = [];
  foreach ($argsList as $i => $args) {
    $p = buildProfile($i + 1, $args);
    // По документации: если все файлы хостлистов профиля пусты, фильтра по хостам нет
    $p['empty_hostlists'] = false;
    if ($p['hostlists'] && !$p['hostlist_domains'] && !$p['autolist']
      && !array_filter($p['hostlists'], fn($f) => readListLines($f))) {
      $p['has_host_filter'] = false;
      $p['empty_hostlists'] = true;
    }
    $p['source'] = null;
    foreach ($expected as $k => $e) {
      if (!isset($used[$k]) && $e['tokens'] === $args) {
        $p['source'] = array_diff_key($e, ['tokens' => 1]);
        $used[$k] = true;
        break;
      }
    }
    $profiles[] = $p;
  }
  analyzeShadowing($profiles);
  return $profiles;
}

// ================= списки =================

function readListLines(string $path): array
{
  $src = is_file($path) ? $path : (is_file("$path.gz") ? "compress.zlib://$path.gz" : null);
  if ($src === null) {
    return [];
  }
  $out = [];
  foreach (@file($src, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    $l = trim($l);
    if ($l !== '' && $l[0] !== '#' && $l[0] !== ';') {
      $out[] = $l;
    }
  }
  return $out;
}

function listKind(string $name): string
{
  return str_starts_with($name, 'ipset') ? 'ip' : 'host';
}

function listsInventory(array $profiles): array
{
  $usage = [];
  $roles = ['hostlists' => 'include', 'hostlist_excludes' => 'exclude', 'ipsets' => 'include', 'ipset_excludes' => 'exclude'];
  foreach ($profiles as $p) {
    foreach ($roles as $key => $role) {
      foreach ($p[$key] as $path) {
        $usage[$path][] = ['profile' => $p['index'], 'role' => $role];
      }
    }
    if ($p['autolist']) {
      $usage[$p['autolist']][] = ['profile' => $p['index'], 'role' => 'auto'];
    }
  }
  $paths = [];
  foreach (glob(LISTS_DIR . '/*') as $f) {
    if (is_file($f) && preg_match('/\.list(\.gz)?$/', $f)) {
      $paths[preg_replace('/\.gz$/', '', $f)] = true;
    }
  }
  foreach (array_keys($usage) as $path) {
    $paths[$path] = true;
  }
  $res = [];
  foreach (array_keys($paths) as $path) {
    $real = is_file($path) ? $path : (is_file("$path.gz") ? "$path.gz" : null);
    $name = basename($path);
    $res[] = [
      'name' => $name,
      'path' => $path,
      'kind' => listKind($name),
      'exists' => $real !== null,
      'editable' => $real === $path && dirname($path) === LISTS_DIR,
      'removable' => !in_array($name, PROTECTED_LISTS, true) && empty($usage[$path]),
      'entries' => $real ? count(readListLines($path)) : 0,
      'size' => $real ? filesize($real) : 0,
      'mtime' => $real ? filemtime($real) : null,
      'used' => $usage[$path] ?? [],
      'problems' => $real === $path ? listProblems($path) : ['error' => 0, 'warning' => 0, 'info' => 0],
    ];
  }
  usort($res, fn($a, $b) => [!$a['used'], $a['kind'] !== 'host', $a['name']] <=> [!$b['used'], $b['kind'] !== 'host', $b['name']]);
  return $res;
}

// ================= проверка хоста =================

function hostInEntries(string $host, array $entries): ?string
{
  $set = [];
  foreach ($entries as $e) {
    $set[strtolower(rtrim($e, '.'))] = true;
  }
  if (isset($set['^' . $host])) {
    return '^' . $host;
  }
  $parts = explode('.', $host);
  for ($k = 0; $k < count($parts); $k++) {
    $d = implode('.', array_slice($parts, $k));
    if (isset($set[$d])) {
      return $d;
    }
  }
  return null;
}

function ipInEntry(string $ipBin, string $entry): bool
{
  if (str_contains($entry, '-')) {
    [$a, $b] = array_map(fn($x) => @inet_pton(trim($x)), explode('-', $entry, 2));
    return $a !== false && $b !== false && strlen($a) === strlen($ipBin) && $ipBin >= $a && $ipBin <= $b;
  }
  [$net, $bits] = array_pad(explode('/', $entry, 2), 2, null);
  $netBin = @inet_pton($net);
  if ($netBin === false || strlen($netBin) !== strlen($ipBin)) {
    return false;
  }
  $bits = $bits === null ? strlen($ipBin) * 8 : (int)$bits;
  $bytes = intdiv($bits, 8);
  if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
    return false;
  }
  $rem = $bits % 8;
  if ($rem === 0) {
    return true;
  }
  $mask = (0xFF << (8 - $rem)) & 0xFF;
  return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
}

class ListCache
{
  private array $c = [];

  public function get(string $path): array
  {
    return $this->c[$path] ??= readListLines($path);
  }
}

function ipsIn(array $ips, array $paths, array $inline, ListCache $cache): ?array
{
  foreach ($ips as $ip) {
    $bin = inet_pton($ip);
    foreach ($inline as $e) {
      if (ipInEntry($bin, $e)) {
        return ['ip' => $ip, 'entry' => $e, 'list' => null];
      }
    }
    foreach ($paths as $path) {
      foreach ($cache->get($path) as $e) {
        if (ipInEntry($bin, $e)) {
          return ['ip' => $ip, 'entry' => $e, 'list' => basename($path)];
        }
      }
    }
  }
  return null;
}

function hostIn(string $host, array $paths, array $inline, ListCache $cache): ?array
{
  foreach ($paths as $path) {
    if ($d = hostInEntries($host, $cache->get($path))) {
      return ['entry' => $d, 'list' => basename($path)];
    }
  }
  if ($d = hostInEntries($host, $inline)) {
    return ['entry' => $d, 'list' => null];
  }
  return null;
}

// Повторяет выбор профиля nfqws2: порт → L7 → ipset → hostlist; первый подходящий побеждает
function matchRoute(array $profiles, string $host, array $ips, string $proto, int $port, string $l7, ListCache $cache): array
{
  $isIp = isIp($host);
  $steps = [];
  foreach ($profiles as $p) {
    $pp = protoPorts($p);
    $s = ['profile' => $p['index'], 'ok' => false, 'why' => ''];
    if (!inRanges($port, $pp[$proto])) {
      $s['why'] = 'другие порты';
      $s['skip'] = true;
    } elseif ($p['l7'] !== null && !in_array($l7, $p['l7'], true)) {
      $s['why'] = 'другой протокол (' . implode(', ', $p['l7']) . ')';
      $s['skip'] = true;
    } elseif ($p['has_ip_filter'] && !($hit = ipsIn($ips, $p['ipsets'], $p['ipset_ips'], $cache))) {
      $s['why'] = 'IP не входит в IP-списки профиля';
    } elseif (($p['ipset_excludes'] || $p['ipset_exclude_ips']) && ($ex = ipsIn($ips, $p['ipset_excludes'], $p['ipset_exclude_ips'], $cache))) {
      $s['why'] = "IP {$ex['ip']} в исключениях" . ($ex['list'] ? " ({$ex['list']})" : '');
    } elseif ($p['has_host_filter'] && !($p['autolist'] && !$isIp) && ($isIp || !($hh = hostIn($host, array_merge($p['hostlists'], $p['autolist'] ? [$p['autolist']] : []), $p['hostlist_domains'], $cache)))) {
      $s['why'] = $isIp ? 'профиль работает по именам сайтов' : 'сайта нет в списках профиля';
    } elseif (!$isIp && ($ex = hostIn($host, $p['hostlist_excludes'], $p['hostlist_exclude_domains'], $cache))) {
      $s['why'] = "сайт в исключениях ({$ex['entry']}" . ($ex['list'] ? " в {$ex['list']}" : '') . ')';
      $s['excluded_by'] = $ex['list'];
    } else {
      $s['ok'] = true;
      if (isset($hit)) {
        $s['why'] = "IP {$hit['ip']} в " . ($hit['list'] ?? 'профиле');
      } elseif (isset($hh)) {
        $s['why'] = "есть в {$hh['list']}" . ($hh['entry'] !== $host ? " (как {$hh['entry']})" : '');
      } elseif ($p['autolist']) {
        $s['why'] = 'профиль с автосписком: стратегия включится, когда nfqws2 заметит блокировку';
      } elseif ($p['empty_hostlists']) {
        $s['why'] = 'списки сайтов профиля пусты — nfqws2 считает, что фильтра нет';
      } else {
        $s['why'] = 'профиль без списков — обрабатывает всё на этих портах';
      }
      $steps[] = $s;
      return ['proto' => $proto, 'port' => $port, 'l7' => $l7, 'profile' => $p['index'], 'steps' => $steps];
    }
    unset($hit, $hh);
    $steps[] = $s;
  }
  return ['proto' => $proto, 'port' => $port, 'l7' => $l7, 'profile' => null, 'steps' => $steps];
}

// Ограничение «16-20 КБ» (ТСПУ): к зарубежным хостингам соединение замирает после ~16–20 КБ или ~25 пакетов —
// без RST, просто перестают приходить пакеты. Маленькая страница его не покажет, поэтому, как чекер
// hyperion-cs/dpi-checkers (tcp-16-20), отправляем POST на 64 КБ в новом соединении.
const VOLUME_BYTES = 65536;
const VOLUME_SMALL = 24576;   // страница меньше — объёмом её не проверить, нужен POST
const REASON_FREEZE = 'соединение зависает на объёме (после ~16–20 КБ)';

// true — соединение замерло на объёме; false — прошло или результат не показателен
function volumeFrozen(string $url): bool
{
  $ch = curl_init($url . (str_contains($url, '?') ? '&' : '?') . 't=' . mt_rand());
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => random_bytes(VOLUME_BYTES),   // случайные данные — чтобы не сжимались
    CURLOPT_HTTPHEADER => ['Expect:', 'Content-Type: application/octet-stream'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_NOSIGNAL => 1,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130 Safari/537.36',
  ]);
  curl_exec($ch);
  // рукопожатие прошло (APPCONNECT), а ответа на 64 КБ нет. Если TLS не установился, CONNECT_TIME тоже 0 —
  // по нему «TCP или TLS» не различить
  $frozen = curl_errno($ch) === 28 && curl_getinfo($ch, CURLINFO_APPCONNECT_TIME) > 0;
  curl_close($ch);
  return $frozen;
}

function probe(string $host): array
{
  $ch = curl_init('https://' . $host . '/');
  $code = null;
  $got = 0;
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_NOSIGNAL => 1,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130 Safari/537.36',
    CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$code) {
      if (preg_match('#^HTTP/[\d.]+\s+(\d+)#', $h, $m)) {
        $code = (int)$m[1];
      }
      return strlen($h);
    },
    CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$got) {
      $got += strlen($chunk);
      return $got > VOLUME_BYTES ? 0 : strlen($chunk);   // хватит: объём уже прошёл
    },
  ]);
  $t = microtime(true);
  curl_exec($ch);
  $errno = curl_errno($ch);
  $err = curl_error($ch);
  curl_close($ch);
  $ms = (int)round((microtime(true) - $t) * 1000);
  // ответ пришёл, но тело встало — это заморозка, а не рабочий сайт
  if ($code !== null && $errno === 28) {
    return ['ok' => false, 'code' => $code, 'ms' => $ms, 'reason' => REASON_FREEZE];
  }
  if ($code !== null) {
    $frozen = $got < VOLUME_SMALL && volumeFrozen('https://' . $host . '/');
    return ['ok' => !$frozen, 'code' => $code, 'ms' => $ms, 'reason' => $frozen ? REASON_FREEZE : null];
  }
  $reason = match ($errno) {
    6 => 'имя не разрешается (DNS)',
    7 => 'не удалось подключиться',
    28 => 'нет ответа (тайм-аут) — похоже на блокировку',
    35 => 'обрыв при установке TLS — похоже на блокировку DPI',
    52 => 'сервер ничего не ответил',
    56 => 'соединение сброшено — похоже на блокировку DPI',
    default => $err ?: "ошибка $errno",
  };
  return ['ok' => false, 'code' => null, 'ms' => $ms, 'reason' => $reason];
}

// ================= справочник и проверка синтаксиса =================

// Результат $fn кэшируется в /tmp, пока не изменятся файлы-зависимости
function cached(string $key, array $deps, callable $fn)
{
  $sig = CACHE_VER . md5(json_encode(array_map(fn($f) => file_exists($f) ? [$f, filemtime($f), is_file($f) ? filesize($f) : 0] : $f, $deps)));
  $file = CACHE_DIR . '/' . preg_replace('/[^a-z0-9_.-]/i', '_', $key) . '.json';
  if (is_file($file)) {
    $c = json_decode(file_get_contents($file), true);
    if (($c['sig'] ?? '') === $sig) {
      return $c['data'];
    }
  }
  $data = $fn();
  @mkdir(CACHE_DIR, 0700, true);
  file_put_contents($file, json_encode(['sig' => $sig, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  return $data;
}

// Запуск без shell-подстановок, с ограничением по времени (в busybox нет timeout)
function runTimeout(array $argv, int $sec = 10): array
{
  $p = proc_open(implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1', [1 => ['pipe', 'w']], $pipes);
  if (!is_resource($p)) {
    return [-1, 'не удалось запустить'];
  }
  stream_set_blocking($pipes[1], false);
  $out = '';
  $end = microtime(true) + $sec;
  while (true) {
    $out .= (string)fread($pipes[1], 65536);
    $st = proc_get_status($p);
    if (!$st['running']) {
      $out .= (string)stream_get_contents($pipes[1]);
      fclose($pipes[1]);
      proc_close($p);
      return [$st['exitcode'], $out];
    }
    if (microtime(true) > $end) {
      proc_terminate($p, 9);
      fclose($pipes[1]);
      proc_close($p);
      return [-1, $out . "\nпревышено время ожидания"];
    }
    usleep(20000);
  }
}

// Параметры nfqws2 из его собственной справки
function helpInfo(): array
{
  return cached('help', [NFQWS_BIN], function () {
    [, $out] = runTimeout([NFQWS_BIN, '--help'], 5);
    $opts = [];
    $l7 = [];
    $payloads = [];
    $section = 'GLOBAL';
    foreach (explode("\n", $out) as $line) {
      if (preg_match('/^([A-Z][A-Z -]+):\s*$/', trim($line), $m)) {
        $section = $m[1];
        continue;
      }
      if (!preg_match('/^\s*--([a-z0-9-]+)(\S*)\s*(?:;\s*(.*))?$/', $line, $m)) {
        continue;
      }
      $desc = trim($m[3] ?? '');
      $opts[$m[1]] = [
        'syntax' => '--' . $m[1] . $m[2],
        'desc' => $desc,
        'global' => in_array($section, ['GLOBAL', 'DESYNC ENGINE INIT'], true) || str_contains($desc, 'global parameter'),
        'value' => $m[2] !== '' && $m[2][0] !== '[',
      ];
      if (preg_match('/:\s*([a-z0-9_ ]+)$/', $desc, $x)) {
        if ($m[1] === 'filter-l7') {
          $l7 = preg_split('/\s+/', trim($x[1]));
        } elseif ($m[1] === 'payload') {
          $payloads = preg_split('/\s+/', trim($x[1]));
        }
      }
    }
    return ['options' => $opts, 'l7' => $l7, 'payloads' => $payloads];
  });
}

// Функции и их параметры — из документации в самих lua-скриптах
function luaCatalog(): array
{
  $files = luaFiles();
  return cached('lua', array_values($files), function () use ($files) {
    $std = [];
    $funcs = [];
    $globals = [];
    $fileKeys = [];
    foreach ($files as $base => $file) {
      $lines = file(str_ends_with($file, '.gz') ? "compress.zlib://$file" : $file, FILE_IGNORE_NEW_LINES) ?: [];
      $group = null;
      $inBlock = false;
      $doc = [];
      foreach ($lines as $i => $line) {
        if (str_starts_with($line, '--[[')) {
          $inBlock = true;
          continue;
        }
        if ($inBlock) {
          if (str_starts_with($line, ']]')) {
            $inBlock = false;
            $group = null;
          } elseif (preg_match('/^standard (.+?)\s*:\s*$/', $line, $m)) {
            $group = str_replace(' ', '_', $m[1]);
          } elseif ($group && preg_match('/^\*\s+(?:\w+\s*:\s*)?([a-z][a-z0-9_]*)(\S*)\s*(?:-\s*(.*))?$/', $line, $m)) {
            $std[$group][$m[1]] = trim($m[1] . $m[2] . (isset($m[3]) ? ' — ' . $m[3] : ''));
          }
          continue;
        }
        if (preg_match('/^--\s?(.*)$/', $line, $m)) {
          $doc[] = $m[1];
          if (preg_match('/^arg\s*:\s*([a-z][a-z0-9_]*)/', $m[1], $x)) {
            $fileKeys[$base][$x[1]] = true;
          }
          continue;
        }
        if (preg_match('/^function ([a-z_][a-z0-9_]*)\(ctx,\s*desync\)/', $line, $m)) {
          $f = ['file' => $base, 'doc' => [], 'std' => [], 'args' => [], 'required' => [], 'nfqws1' => null];
          foreach ($doc as $d) {
            if (preg_match('/^standard args\s*:\s*(.+)$/', $d, $x)) {
              // «direction, payload, reconstruct. FOOLING AND REPEATS ...» — берём только имена групп
              preg_match_all('/(?:^|,)\s*([a-z_]+)/', $x[1], $g);
              $f['std'] = $g[1];
              if (preg_match('/\.\s*(.+)$/', $x[1], $note)) {
                $f['doc'][] = $note[1];
              }
            } elseif (preg_match('/^arg\s*:\s*([a-z][a-z0-9_]*)(.*)$/', $d, $x)) {
              $f['args'][$x[1]] = trim($x[1] . $x[2]);
            } elseif (preg_match('/^nfqws1\s*:\s*(.+)$/', $d, $x)) {
              $f['nfqws1'] = trim($x[1], ' "');
            } elseif (trim($d) !== '') {
              $f['doc'][] = $d;
            }
          }
          for ($j = $i + 1; $j < count($lines) && $lines[$j] !== 'end'; $j++) {
            if (preg_match_all("/'([a-z0-9_]+)' arg required/", $lines[$j], $x)) {
              array_push($f['required'], ...$x[1]);
            }
          }
          $f['required'] = array_values(array_unique($f['required']));
          $funcs[$m[1]] = $f;
        } elseif (preg_match('/^([a-z_][a-z0-9_]*)\s*=/i', $line, $m)) {
          $globals[] = $m[1];
        }
        $doc = [];
      }
    }
    // Оркестраторы (zapret-auto.lua) принимают параметры детекторов и генераторов ключей того же файла
    foreach ($funcs as &$f) {
      $f['file_keys'] = array_keys($fileKeys[$f['file']] ?? []);
    }
    unset($f);
    return ['functions' => $funcs, 'std' => $std, 'globals' => array_values(array_unique($globals))];
  });
}

function levenshteinBest(string $word, array $candidates, int $max = 2): ?string
{
  $best = null;
  $bestD = $max + 1;
  foreach ($candidates as $c) {
    $d = levenshtein($word, $c);
    if ($d < $bestD) {
      $best = $c;
      $bestD = $d;
    }
  }
  return $best;
}

function validPortFilter(string $v): bool
{
  foreach (explode(',', $v) as $p) {
    $p = ltrim($p, '~');
    if ($p === '*') {
      continue;
    }
    if (!preg_match('/^(\d+)(?:-(\d+))?$/', $p, $m) || (int)$m[1] > 65535 || (isset($m[2]) && ((int)$m[2] > 65535 || (int)$m[2] < (int)$m[1]))) {
      return false;
    }
  }
  return true;
}

function listFileExists(string $path): bool
{
  return is_file($path) || is_file($path . '.gz');
}

// Lua-скрипты nfqws2-keenetic кладёт сжатыми (zapret-lib.lua.gz), а в --lua-init пишется имя без .gz:
// nfqws2 сам берёт сжатый файл, если обычного нет. Возвращает имя.lua => настоящий файл.
function luaFiles(): array
{
  $res = [];
  foreach (glob(LUA_DIR . '/*.lua.gz') ?: [] as $f) {
    $res[basename($f, '.gz')] = $f;
  }
  foreach (glob(LUA_DIR . '/*.lua') ?: [] as $f) {
    $res[basename($f)] = $f;
  }
  ksort($res);
  return $res;
}

// Проверка переменных с аргументами. $raw — значения как в форме (для привязки к номеру аргумента),
// $text — полный текст кандидата конфига. Возвращает список замечаний.
// ---------- перенос конфига с другой системы ----------
// Конфиг, скопированный с Keenetic (Entware, пути /opt/...) на OpenWrt или обратно, из zapret2 (/opt/zapret2/...)
// или от nfqws первой версии, часто не запускается: другие пути, интерфейс провайдера, нет части переменных.

function platformName(): string
{
  return ROOT ? 'Keenetic (Entware)' : 'OpenWrt';
}

// Интерфейс маршрута по умолчанию — обычно это провайдер
function wanIface(): string
{
  exec('ip -4 route show default 2>/dev/null', $out);
  foreach ($out as $l) {
    if (preg_match('/\bdev (\S+)/', $l, $m)) {
      return $m[1];
    }
  }
  return ROOT ? 'eth3' : 'wan';
}

// Сетевые интерфейсы роутера для выбора ISP_INTERFACE: адреса, маршрут по умолчанию, имя в OpenWrt (wan, lan…)
// и годится ли интерфейс в принципе (порты моста, Wi-Fi и мосты локальной сети — нет)
function netIfaces(): array
{
  $def = [];
  exec('ip -4 route show default 2>/dev/null', $rt4);
  exec('ip -6 route show default 2>/dev/null', $rt6);
  foreach ([4 => $rt4, 6 => $rt6] as $v => $rt) {
    foreach ($rt as $l) {
      if (preg_match('/\bdev (\S+)/', $l, $m)) {
        $def[$m[1]][] = "IPv$v";
      }
    }
  }
  $logical = [];
  exec('ubus call network.interface dump 2>/dev/null', $u);
  foreach (json_decode(implode("\n", $u), true)['interface'] ?? [] as $x) {
    $d = $x['l3_device'] ?? $x['device'] ?? null;
    if ($d && !in_array($x['interface'], $logical[$d] ?? [], true)) {
      $logical[$d][] = $x['interface'];
    }
  }
  $ips = [];
  exec('ip -o addr show 2>/dev/null', $ad);
  foreach ($ad as $l) {
    if (preg_match('/^\d+:\s+(\S+)\s+inet6?\s+(\S+)/', $l, $m) && !str_starts_with($m[2], 'fe80')) {
      $ips[$m[1]][] = $m[2];
    }
  }
  $res = [];
  foreach (glob('/sys/class/net/*') as $p) {
    $n = basename($p);
    if ($n === 'lo') {
      continue;
    }
    $type = (int)@file_get_contents("$p/type");
    $kind = is_dir("$p/bridge") ? 'bridge' : (is_dir("$p/phy80211") ? 'wifi' : ($type === 512 ? 'ppp' : ($type === 65534 ? 'tunnel' : 'ethernet')));
    $port = is_dir("$p/brport");
    $state = trim((string)@file_get_contents("$p/operstate"));
    $res[] = ['name' => $n, 'kind' => $kind, 'up' => in_array($state, ['up', 'unknown'], true), 'ips' => $ips[$n] ?? [],
      'default' => $def[$n] ?? [], 'logical' => $logical[$n] ?? [],
      // для nfqws2 подходит интерфейс с адресом, не мост локальной сети и не его порт
      'usable' => !$port && $kind !== 'bridge' && $kind !== 'wifi' && (!empty($ips[$n]) || isset($def[$n]))];
  }
  // сначала интерфейсы с маршрутом по умолчанию, потом подходящие, потом остальные
  usort($res, fn($a, $b) => [!$a['default'], !$a['usable'], $a['name']] <=> [!$b['default'], !$b['usable'], $b['name']]);
  return $res;
}

// Файлы, которые можно подключить в «Параметрах запуска»: lua-скрипты и блобы
function baseFiles(): array
{
  $lua = array_keys(luaFiles());
  $blobs = array_values(array_filter(array_map('basename', glob(CONF_DIR . '/blobs/*') ?: []),
    fn($f) => !preg_match('/\.(lua|gz)$/', $f) && is_file(CONF_DIR . "/blobs/$f")));
  return ['lua' => $lua, 'lua_dir' => LUA_DIR, 'blobs' => $blobs, 'blob_dir' => CONF_DIR . '/blobs'];
}

// Исправленный текст конфига, список правок и откуда, судя по путям, пришёл конфиг
function adaptConf(string $text): array
{
  $changes = [];
  $from = [];

  // 1. Пути. true — переносить всегда (та же раскладка nfqws2), false — только если такой файл здесь есть
  $rules = [
    ['~^(?:/opt)?/etc/nfqws2/~', CONF_DIR . '/', true, ROOT ? 'OpenWrt' : 'Keenetic (Entware)'],
    ['~^(?:/opt)?/etc/nfqws/(?=[\w.-]+\.list$)~', LISTS_DIR . '/', false, 'nfqws первой версии'],
    ['~^(?:/opt)?/etc/nfqws/~', CONF_DIR . '/', false, 'nfqws первой версии'],
    ['~^/opt/zapret2?/lua/~', LUA_DIR . '/', false, 'zapret2'],
    ['~^/opt/zapret2?/files/fake/~', CONF_DIR . '/blobs/', false, 'zapret2'],
    ['~^/opt/zapret2?/ipset/~', LISTS_DIR . '/', false, 'zapret2'],
  ];
  if (!ROOT) {
    $rules[] = ['~^/opt/var/log/~', '/var/log/', true, 'Keenetic (Entware)'];
  } else {
    $rules[] = ['~^/var/log/~', '/opt/var/log/', true, 'OpenWrt'];
  }
  $lines = explode("\n", $text);
  foreach ($lines as &$line) {
    if (preg_match('/^\s*#/', $line)) {
      continue;
    }
    $line = preg_replace_callback('~(?<![\w./-])/[\w.+-]+(?:/[\w.+-]+)*/?~', function ($m) use ($rules, &$changes, &$from) {
      $p = $m[0];
      foreach ($rules as [$re, $to, $always, $src]) {
        if (!preg_match($re, $p)) {
          continue;
        }
        $new = preg_replace($re, $to, $p, 1);
        if ($new === $p || (!$always && !file_exists($new))) {
          return $p;
        }
        $changes["$p → $new"] = true;
        $from[$src] = true;
        return $new;
      }
      return $p;
    }, $line);
  }
  unset($line);
  $text = implode("\n", $lines);
  $changes = array_keys($changes);

  $raw = confRawVars($text);

  // 2. Интерфейс провайдера, которого на этом роутере нет (eth3/ppp0 с Keenetic, wan/pppoe-wan с OpenWrt)
  if (isset($raw['ISP_INTERFACE']) && is_dir('/sys/class/net')) {
    $ifs = tokens($raw['ISP_INTERFACE']);
    if (!array_filter($ifs, fn($i) => is_dir("/sys/class/net/$i"))) {
      $wan = wanIface();
      $text = confSetVar($text, 'ISP_INTERFACE', $wan);
      $changes[] = ($ifs ? 'интерфейса «' . implode(' ', $ifs) . '» здесь нет' : 'интерфейс провайдера не задан') . " → $wan (маршрут по умолчанию)";
    }
  }

  // 3. lua-файлы, без которых --lua-desync не работает
  if (isset($raw['NFQWS_BASE_ARGS']) && preg_match('/--lua-desync=/', $text) && !preg_match('~--lua-init=@\S*zapret-lib\.lua~', $raw['NFQWS_BASE_ARGS'])) {
    foreach (['zapret-lib.lua', 'zapret-antidpi.lua', 'zapret-auto.lua'] as $f) {
      if (listFileExists(LUA_DIR . "/$f") && !str_contains($raw['NFQWS_BASE_ARGS'], "/$f")) {
        $text = appendTokenInText($text, 'NFQWS_BASE_ARGS', '--lua-init=@' . LUA_DIR . "/$f");
        $changes[] = "подключён $f";
      }
    }
  }

  // 4. Переменные, без которых init-скрипт не запустит nfqws2 или запустит не так (значения — как в стандартном конфиге)
  $base = [];
  foreach (['zapret-lib.lua', 'zapret-antidpi.lua', 'zapret-auto.lua'] as $f) {
    if (listFileExists(LUA_DIR . "/$f")) {
      $base[] = '--lua-init=@' . LUA_DIR . "/$f";
    }
  }
  foreach (['quic_initial', 'tls_clienthello'] as $b) {
    if (is_file(CONF_DIR . "/blobs/$b.bin")) {
      $base[] = "--blob=$b:@" . CONF_DIR . "/blobs/$b.bin";
    }
  }
  $needed = [
    'ISP_INTERFACE' => fn() => wanIface(),
    'NFQWS_BASE_ARGS' => fn() => implode("\n" . str_repeat(' ', 17), $base),
    'NFQWS_EXTRA_ARGS' => fn() => '$MODE_LIST',
    'TCP_PORTS' => fn() => '80,443',
    'UDP_PORTS' => fn() => '443',
    'IPV6_ENABLED' => fn() => '1',
    'LOG_LEVEL' => fn() => '0',
    'NFQUEUE_NUM' => fn() => '300',
    'USER' => fn() => 'nobody',
    'CONFIG_VERSION' => fn() => '1',
  ];
  if (ROOT) {
    $needed += ['POLICY_NAME' => fn() => 'nfqws', 'POLICY_EXCLUDE' => fn() => '0'];
  }
  $added = [];
  foreach ($needed as $name => $val) {
    if (!array_key_exists($name, $raw)) {
      $v = $val();
      $text = rtrim($text, "\n") . "\n" . ($added ? '' : "\n# Добавлено nfqws2-ui: этих переменных не было в конфиге\n")
        . $name . '=' . (preg_match('/^[\w.,:-]+$/', $v) && $name !== 'ISP_INTERFACE' ? $v : "\"$v\"") . "\n";
      $added[] = $name;
    }
  }
  // Режимы MODE_* нужны до строки, где на них ссылается NFQWS_EXTRA_ARGS
  $modes = [
    'MODE_LIST' => '--hostlist=' . LISTS_DIR . '/user.list',
    'MODE_ALL' => '--hostlist-exclude=' . LISTS_DIR . '/exclude.list',
    'MODE_AUTO' => '$MODE_LIST --hostlist-auto=' . LISTS_DIR . '/auto.list --hostlist-auto-debug=' . ROOT . '/var/log/nfqws2.log $MODE_ALL',
  ];
  $missingModes = array_diff_key($modes, $raw);
  if ($missingModes) {
    $block = '';
    foreach ($missingModes as $name => $v) {
      $block .= "$name=\"$v\"\n";
      $added[] = $name;
    }
    $text = preg_match('/^NFQWS_EXTRA_ARGS=/m', $text, $m, PREG_OFFSET_CAPTURE)
      ? substr($text, 0, $m[0][1]) . $block . substr($text, $m[0][1])
      : rtrim($text, "\n") . "\n$block";
  }
  if ($added) {
    $changes[] = 'добавлены переменные: ' . implode(', ', $added);
  }

  return ['text' => normalizeText($text), 'changes' => $changes, 'from' => array_keys($from), 'added' => $added];
}

function lintConf(array $raw, string $text): array
{
  $issues = [];
  $add = function (?string $var, ?int $tok, string $level, string $msg, ?array $fix = null) use (&$issues) {
    foreach ($issues as $x) {
      if ($x['var'] === $var && $x['tok'] === $tok && $x['msg'] === $msg) {
        return;
      }
    }
    $issues[] = ['var' => $var, 'tok' => $tok, 'level' => $level, 'msg' => $msg] + ($fix ? ['fix' => $fix] : []);
  };

  $tmp = tempnam('/tmp', 'nfqlint');
  file_put_contents($tmp, $text);
  exec('sh -n ' . escapeshellarg($tmp) . ' 2>&1', $shOut, $rc);
  if ($rc !== 0) {
    unlink($tmp);
    $add(null, null, 'error', 'Ошибка синтаксиса конфига: ' . preg_replace('#^/tmp/\S+: #', '', implode(' ', $shOut)));
    return ['issues' => $issues, 'dry_run' => null];
  }
  $exp = confValues($tmp);
  unlink($tmp);

  $ad = adaptConf($text);
  if ($ad['changes']) {
    $msg = $ad['from']
      ? 'Похоже, конфиг от ' . implode(' / ', $ad['from']) . ', а здесь ' . platformName() . ' — пути и настройки нужно поправить'
      : ($ad['added'] && count($ad['changes']) === 1 ? 'В конфиге не хватает переменных: ' . implode(', ', $ad['added']) : 'Конфиг не подходит для этого роутера');
    $add(null, null, $ad['from'] ? 'error' : 'warning', $msg, ['op' => 'adapt', 'label' => 'Исправить под ' . platformName(), 'changes' => $ad['changes']]);
  }

  $help = helpInfo();
  $opts = $help['options'];
  $lua = luaCatalog();
  $funcs = $lua['functions'];

  // Что объявлено в параметрах запуска: загруженные lua-файлы и блобы
  $loadedLua = [];
  $blobs = [];
  foreach (tokens($exp['NFQWS_BASE_ARGS']) as $t) {
    if (preg_match('/^--lua-init=@(.+)$/', $t, $m)) {
      $loadedLua[basename($m[1])] = true;
    } elseif (preg_match('/^--blob=([^:]+):/', $t, $m)) {
      $blobs[$m[1]] = true;
    }
  }

  if (trim($exp['ISP_INTERFACE']) === '') {
    $add('ISP_INTERFACE', null, 'error', 'Не задан интерфейс провайдера');
  }

  foreach (ARG_VARS as $var) {
    $toks = tokens($raw[$var] ?? '');
    // Комментарий внутри кавычек (так написан стандартный конфиг Keenetic): от «#» до конца строки.
    // nfqws2 получает эти слова как лишние аргументы и пропускает их
    $comment = [];
    $n = 0;
    foreach (explode("\n", $raw[$var] ?? '') as $line) {
      $lt = tokens($line);
      foreach ($lt as $k => $t) {
        if ($t[0] === '#') {
          $comment[$n + $k] = count($lt) - $k;
          for ($j = $k + 1; $j < count($lt); $j++) {
            $comment[$n + $j] = 0;
          }
          break;
        }
      }
      $n += count($lt);
    }
    foreach ($toks as $i => $t) {
      if (isset($comment[$i])) {
        if ($comment[$i]) {
          $add($var, $i, 'info', 'Комментарий внутри кавычек — nfqws2 получит его слова как лишние аргументы и пропустит; надёжнее вынести над переменной', ['op' => 'remove_comment', 'count' => $comment[$i], 'label' => 'Удалить комментарий']);
        }
        continue;
      }
      if (str_starts_with($t, '--dpi-desync')) {
        $add(null, null, 'error', 'Похоже, это стратегии nfqws первой версии (--dpi-desync…) — nfqws2 их не понимает, их нужно переписать на --lua-desync');
      }
      if (!str_starts_with($t, '--')) {
        $add($var, $i, 'error', 'Ожидается параметр вида --имя=значение');
        continue;
      }
      $name = substr(explode('=', $t, 2)[0], 2);
      $val = str_contains($t, '=') ? substr($t, strpos($t, '=') + 1) : null;
      if ($name === 'new') {
        if ($var !== 'NFQWS_ARGS_CUSTOM') {
          $add($var, $i, 'error', '--new можно использовать только в «Своих профилях» — init-скрипт не запустится', ['op' => 'remove', 'label' => 'Удалить --new']);
        } elseif ($i === 0 || $i === count($toks) - 1) {
          $add($var, $i, 'warning', '--new в начале или в конце создаёт пустой профиль', ['op' => 'remove', 'label' => 'Удалить лишний --new']);
        }
        continue;
      }
      if (!isset($opts[$name])) {
        $pref = array_values(array_filter(array_keys($opts), fn($o) => str_starts_with($o, $name)));
        if (count($pref) === 1) {
          $add($var, $i, 'warning', "Сокращённое имя — nfqws2 поймёт его как --{$pref[0]}, но лучше написать полностью", ['op' => 'replace', 'to' => '--' . $pref[0] . ($val !== null ? '=' . $val : ''), 'label' => "Написать --{$pref[0]}"]);
          $name = $pref[0];
        } else {
          $s = levenshteinBest($name, array_keys($opts));
          $add($var, $i, 'error', "Неизвестный параметр --$name" . ($s ? " — может быть, --$s?" : ''), $s ? ['op' => 'replace', 'to' => "--$s" . ($val !== null ? '=' . $val : ''), 'label' => "Заменить на --$s"] : ['op' => 'remove', 'label' => 'Удалить аргумент']);
          continue;
        }
      }
      $o = $opts[$name];
      if ($var === 'NFQWS_BASE_ARGS' && !$o['global']) {
        $add($var, $i, 'warning', 'Это параметр профиля — в параметрах запуска он попадёт в первый профиль');
      } elseif ($var !== 'NFQWS_BASE_ARGS' && $o['global']) {
        $add($var, $i, 'warning', 'Это общий параметр процесса — его место в «Параметрах запуска»', ['op' => 'move', 'to_var' => 'NFQWS_BASE_ARGS', 'label' => 'Перенести в параметры запуска']);
      }
      if ($o['value'] && ($val === null || $val === '')) {
        $add($var, $i, 'error', "Параметру нужно значение: {$o['syntax']}");
        continue;
      }
      switch ($name) {
        case 'filter-tcp':
        case 'filter-udp':
          if (!validPortFilter($val)) {
            $add($var, $i, 'error', 'Неверный список портов: нужны числа 1–65535, диапазоны через «-», разделитель «,»');
          }
          break;
        case 'filter-l7':
          foreach (explode(',', $val) as $p) {
            if (!in_array($p, $help['l7'], true)) {
              $s = levenshteinBest($p, $help['l7']);
              $add($var, $i, 'error', "Неизвестный протокол «{$p}»" . ($s ? " — может быть, {$s}?" : '') . ' Допустимы: ' . implode(', ', $help['l7']), $s ? ['op' => 'replace', 'to' => "--$name=" . implode(',', array_map(fn($x) => $x === $p ? $s : $x, explode(',', $val))), 'label' => "Заменить на $s"] : null);
            }
          }
          break;
        case 'payload':
        case 'payload-disable':
          foreach (explode(',', (string)$val) as $p) {
            if ($p !== '' && !in_array($p, $help['payloads'], true)) {
              $s = levenshteinBest($p, $help['payloads'], 3);
              $add($var, $i, 'error', "Неизвестный тип payload «{$p}»" . ($s ? " — может быть, {$s}?" : ''), $s ? ['op' => 'replace', 'to' => "--$name=" . implode(',', array_map(fn($x) => $x === $p ? $s : $x, explode(',', $val))), 'label' => "Заменить на $s"] : null);
            }
          }
          break;
        case 'hostlist':
        case 'hostlist-exclude':
        case 'ipset':
        case 'ipset-exclude':
          if (!listFileExists($val)) {
            $add($var, $i, 'error', "Файла нет: $val — nfqws2 не запустится", ['op' => 'remove', 'label' => 'Убрать ссылку на файл']);
          } elseif (in_array($name, ['hostlist', 'ipset'], true) && dirname($val) === LISTS_DIR && !readListLines($val)) {
            $add($var, $i, 'warning', 'Список пуст' . ($name === 'hostlist' ? ' — если все списки сайтов профиля пусты, nfqws2 считает, что фильтра нет, и профиль ловит весь трафик' : ''));
          }
          break;
        case 'lua-init':
          if (str_starts_with($val, '@') && !listFileExists(substr($val, 1))) {
            $add($var, $i, 'error', 'Файла нет: ' . substr($val, 1));
          }
          break;
        case 'blob':
          if (preg_match('/^[^:]+:\+?\d*@(.+)$/', $val, $m) && !is_file($m[1])) {
            $add($var, $i, 'error', 'Файла блоба нет: ' . $m[1]);
          } elseif (!preg_match('/^[^:]+:(\+?\d*@.+|0x[0-9a-fA-F]+)$/', $val)) {
            $add($var, $i, 'error', 'Формат: --blob=имя:@файл или --blob=имя:0xHEX');
          }
          break;
        case 'lua-desync':
          lintDesync($var, $i, $val, $funcs, $lua, $loadedLua, $blobs, $help, $add);
          break;
      }
    }
  }

  // Проверки на уровне профилей
  $expected = expectedProfiles($exp);
  $iptPorts = [
    'tcp' => parsePorts(str_replace(':', '-', $exp['TCP_PORTS'])) ?? [],
    'udp' => parsePorts(str_replace(':', '-', $exp['UDP_PORTS'])) ?? [],
  ];
  $usedPorts = [];
  foreach ($expected as $n => $e) {
    $var = $e['source'];
    $tok = null;
    if ($var === 'NFQWS_ARGS_CUSTOM') {
      // номер первого аргумента этой части
      $parts = 0;
      foreach (tokens($raw[$var] ?? '') as $i => $t) {
        if ($parts === $e['part'] - 1) {
          $tok = $i;
          break;
        }
        if ($t === '--new') {
          $parts++;
        }
      }
    }
    $label = "Профиль #" . ($n + 1);
    $hasDesync = false;
    $hasFilter = false;
    $inCircular = false;
    $strategies = [];
    foreach ($e['tokens'] as $t) {
      if (str_starts_with($t, '--lua-desync=')) {
        $hasDesync = true;
        $fn = explode(':', substr($t, 13))[0];
        if ($fn === 'circular') {
          $inCircular = true;
        }
        if (preg_match('/:strategy=(\d+)/', $t, $m)) {
          $strategies[] = (int)$m[1];
        }
      }
      if (preg_match('/^--filter-(tcp|udp|l7|l3|icmp|ipp)=/', $t)) {
        $hasFilter = true;
      }
    }
    if (!$hasDesync) {
      $add($var, $tok, 'warning', "$label: нет ни одного --lua-desync — профиль ничего не делает, но перехватывает свой трафик у профилей ниже");
    }
    if (!$hasFilter) {
      $add($var, $tok, 'warning', "$label: нет фильтров --filter-tcp/udp/l7 — профиль подходит для любого трафика");
    }
    if ($strategies) {
      $u = array_values(array_unique($strategies));
      sort($u);
      if (!$inCircular) {
        $add($var, $tok, 'warning', "$label: strategy=N используется без --lua-desync=circular — номера стратегий ни на что не влияют");
      } elseif ($u !== range(1, count($u))) {
        $add($var, $tok, 'error', "$label: номера strategy должны идти подряд с 1 (сейчас: " . implode(', ', $u) . ')');
      }
    }

    // Порты профиля должны попадать в правила iptables (TCP_PORTS / UDP_PORTS), иначе пакеты до nfqws2 не дойдут
    $hasPortFilter = false;
    foreach ($e['tokens'] as $t) {
      if (!preg_match('/^--filter-(tcp|udp)=(.+)$/', $t, $m)) {
        continue;
      }
      $hasPortFilter = true;
      $want = parsePorts(implode(',', array_filter(explode(',', $m[2]), fn($p) => $p !== '' && $p[0] !== '~'))) ?? [];
      $usedPorts[$m[1]] = normRanges(array_merge($usedPorts[$m[1]] ?? [], $want));
      $missing = subtractRanges($want, $iptPorts[$m[1]]);
      if ($missing) {
        $name = strtoupper($m[1]);
        $rawToks = tokens($raw[$var] ?? '');
        $ti = null;
        foreach ($rawToks as $i => $rt) {
          if ($rt === $t && ($tok === null || $i >= $tok)) {
            $ti = $i;
            break;
          }
        }
        $add($var, $ti ?? $tok, 'warning', "$label: $name " . rangesStr($missing) . " нет в «{$name}-портах» — такие пакеты не попадают в nfqws2 и профиль на них не сработает", ['op' => 'set_var', 'var' => $name . '_PORTS', 'value' => iptPortsStr(normRanges(array_merge($iptPorts[$m[1]], $missing))), 'label' => "Добавить в {$name}-порты"]);
      }
    }
    if (!$hasPortFilter) {
      $usedPorts['tcp'] = [[0, 65535]];
      $usedPorts['udp'] = [[0, 65535]];
    }
  }
  foreach (['tcp' => 'TCP_PORTS', 'udp' => 'UDP_PORTS'] as $proto => $v) {
    $extra = subtractRanges($iptPorts[$proto], $usedPorts[$proto] ?? []);
    if ($extra) {
      $add($v, null, 'info', strtoupper($proto) . ' ' . rangesStr($extra) . ' отправляются в nfqws2, но ни один профиль их не обрабатывает — лишняя нагрузка', ($left = subtractRanges($iptPorts[$proto], $extra)) ? ['op' => 'set_var', 'var' => $v, 'value' => iptPortsStr($left), 'label' => 'Убрать лишние порты'] : null);
    }
  }

  // Окончательная проверка — самим nfqws2
  $dry = dryRun($exp);
  if (!$dry['ok']) {
    $mapped = false;
    foreach ($dry['needles'] as $needle) {
      foreach (ARG_VARS as $var) {
        foreach (tokens($raw[$var] ?? '') as $i => $t) {
          if (str_contains($t, $needle)) {
            $add($var, $i, 'error', 'nfqws2: ' . $dry['message']);
            $mapped = true;
            break 3;
          }
        }
      }
    }
    if (!$mapped) {
      $add(null, null, 'error', 'nfqws2 отклонил параметры: ' . $dry['message']);
    }
  }
  return ['issues' => $issues, 'dry_run' => ['ok' => $dry['ok'], 'message' => $dry['message']]];
}

function lintDesync(string $var, int $i, string $val, array $funcs, array $lua, array $loadedLua, array $blobs, array $help, callable $add): void
{
  $parts = explode(':', $val);
  $fn = array_shift($parts);
  if (!isset($funcs[$fn])) {
    $s = levenshteinBest($fn, array_keys($funcs));
    $add($var, $i, 'error', "Нет такой lua-функции «{$fn}»" . ($s ? " — может быть, {$s}?" : ''), $s ? ['op' => 'replace', 'to' => '--lua-desync=' . implode(':', array_merge([$s], $parts)), 'label' => "Заменить на $s"] : null);
    return;
  }
  $f = $funcs[$fn];
  if (!isset($loadedLua[$f['file']])) {
    $add($var, $i, 'error', "Функция {$fn} из {$f['file']}, а этот файл не подключён через --lua-init",
      listFileExists(LUA_DIR . "/{$f['file']}") ? ['op' => 'append', 'var' => 'NFQWS_BASE_ARGS', 'tok' => '--lua-init=@' . LUA_DIR . "/{$f['file']}", 'label' => "Подключить {$f['file']}"] : null);
  }
  $allowed = array_merge(array_keys($f['args']), $f['file_keys'], ['strategy', 'final']);
  foreach ($f['std'] as $g) {
    $allowed = array_merge($allowed, array_keys($lua['std'][$g] ?? []));
  }
  $known = $allowed;
  foreach ($lua['std'] as $g) {
    $known = array_merge($known, array_keys($g));
  }
  foreach ($funcs as $ff) {
    $known = array_merge($known, array_keys($ff['args']), $ff['file_keys']);
  }
  $keys = [];
  foreach ($parts as $p) {
    [$k, $v] = array_pad(explode('=', $p, 2), 2, null);
    $keys[$k] = $v;
    if ($k === '') {
      $add($var, $i, 'error', 'Пустой параметр — лишнее двоеточие', ['op' => 'remove_param', 'key' => '', 'label' => 'Убрать лишнее двоеточие']);
    } elseif (!in_array($k, $allowed, true)) {
      if (in_array($k, $known, true)) {
        $add($var, $i, 'warning', "У функции {$fn} параметр «{$k}» не описан — возможно, он не действует", ['op' => 'remove_param', 'key' => $k, 'label' => "Убрать $k"]);
      } else {
        $s = levenshteinBest($k, array_unique($known));
        $add($var, $i, 'warning', "Неизвестный параметр «{$k}»" . ($s ? " — может быть, {$s}?" : ''), $s ? ['op' => 'rename_param', 'key' => $k, 'to' => $s, 'label' => "Заменить на $s"] : ['op' => 'remove_param', 'key' => $k, 'label' => "Убрать $k"]);
      }
    }
    if (in_array($k, ['blob', 'seqovl_pattern', 'pattern'], true) && $v !== null && !str_starts_with($v, '0x')
      && !isset($blobs[$v]) && !in_array($v, $lua['globals'], true)) {
      $add($var, $i, 'error', "Блоб «{$v}» не объявлен — добавьте --blob={$v}:@файл в параметры запуска", ($bf = blobFiles()) ? ['op' => 'declare_blob', 'name' => $v, 'choices' => $bf, 'label' => 'Объявить блоб из файла'] : null);
    }
    if ($k === 'payload' && $v !== null) {
      foreach (explode(',', $v) as $pl) {
        if (!in_array($pl, $help['payloads'], true)) {
          $add($var, $i, 'error', "Неизвестный тип payload «{$pl}»");
        }
      }
    }
    if ($k === 'strategy' && !preg_match('/^\d+$/', (string)$v)) {
      $add($var, $i, 'error', 'strategy должен быть числом');
    }
  }
  foreach ($f['required'] as $r) {
    if (!array_key_exists($r, $keys)) {
      $add($var, $i, 'error', "Функции {$fn} нужен параметр «{$r}» — без него она завершится ошибкой на каждом пакете", ['op' => 'add_param', 'key' => $r, 'choices' => $r === 'blob' ? array_values(array_unique(array_merge(array_keys($blobs), array_values(array_filter($lua['globals'], fn($g) => preg_match('/^fake_default_/', $g)))))) : [], 'label' => "Добавить $r"]);
    }
  }
}

// Запускает nfqws2 --dry-run с теми же аргументами, что собрал бы init-скрипт
function dryRun(array $exp): array
{
  $args = [NFQWS_BIN, '--dry-run', '--user=' . ($exp['USER'] ?: 'nobody'), '--qnum=' . ($exp['NFQUEUE_NUM'] ?: '300')];
  if (!str_contains($exp['NFQWS_BASE_ARGS'], '--fastpath-workaround=')) {
    $args[] = '--fastpath-workaround=auto';
  }
  array_push($args, ...tokens($exp['NFQWS_BASE_ARGS']));
  $profiles = array_column(expectedProfiles($exp), 'tokens');
  foreach ($profiles as $k => $p) {
    if ($k > 0) {
      $args[] = '--new';
    }
    array_push($args, ...$p);
  }
  [$rc, $out] = runTimeout($args, 10);
  if ($rc === 0) {
    return ['ok' => true, 'message' => null, 'needles' => []];
  }
  $lines = array_values(array_filter(array_map('trim', explode("\n", $out)),
    fn($l) => $l !== '' && !preg_match('/^(github version|we have|Running as|loading|Loaded|LUA v)/', $l)));
  $msg = $lines[0] ?? "код выхода $rc";
  $needles = [];
  if (preg_match_all("/'([^']+)'/", $msg, $m)) {
    $needles = $m[1];
  }
  if (preg_match('/:\s*(\S+)\s*$/', $msg, $m)) {
    $needles[] = $m[1];
  }
  return ['ok' => false, 'message' => $msg, 'needles' => $needles];
}

function lintCurrent(): array
{
  $deps = array_merge([CONF_FILE, LISTS_DIR, NFQWS_BIN], array_values(luaFiles()));
  return cached('lint-current', $deps, function () {
    $text = file_get_contents(CONF_FILE);
    return lintConf(confRawVars($text), $text);
  });
}

// ---------- проверка списков ----------

function validCidr(string $s): bool
{
  [$ip, $bits] = array_pad(explode('/', $s, 2), 2, null);
  $bin = @inet_pton($ip);
  if ($bin === false) {
    return false;
  }
  return $bits === null || (preg_match('/^\d+$/', $bits) && (int)$bits <= strlen($bin) * 8);
}

// Замечания по строкам списка: line — номер строки с 0; fix — чем заменить (строка) или null — удалить
function lintList(string $name, string $content): array
{
  $isIp = listKind($name) === 'ip';
  $lines = explode("\n", $content);
  $issues = [];
  $seen = [];
  $domains = [];
  foreach ($lines as $i => $rawLine) {
    $t = trim($rawLine);
    if ($t === '' || $t[0] === '#') {
      continue;
    }
    $key = strtolower($t);
    if ($isIp) {
      if (!validCidr($t)) {
        $issues[] = ['line' => $i, 'level' => 'error', 'msg' => 'Не IP-адрес и не подсеть'];
        continue;
      }
    } else {
      $d = ltrim($key, '^');
      if (str_contains($d, '*')) {
        $fix = preg_replace('/^\*\.?/', '', $d);
        $issues[] = ['line' => $i, 'level' => 'error', 'fix' => validHost($fix) ? $fix : null,
          'msg' => '«*» nfqws2 не поддерживает — запись не работает. Поддомены учитываются автоматически' . (validHost($fix) ? ", достаточно «{$fix}»" : '')];
        continue;
      }
      if (preg_match('#^[a-z]+://#', $d) || str_contains($d, '/')) {
        $issues[] = ['line' => $i, 'level' => 'error', 'fix' => cleanHost($d), 'msg' => 'Нужно только имя сайта, без http:// и пути'];
        continue;
      }
      if (!validHost($d)) {
        $issues[] = ['line' => $i, 'level' => 'error', 'msg' => 'Некорректное имя сайта'];
        continue;
      }
      if ($key[0] !== '^') {
        $domains[$d] = $domains[$d] ?? $i;
      }
    }
    if (isset($seen[$key])) {
      $issues[] = ['line' => $i, 'level' => 'warning', 'fix' => null, 'msg' => 'Повтор строки ' . ($seen[$key] + 1)];
      continue;
    }
    $seen[$key] = $i;
  }
  if (!$isIp) {
    foreach ($domains as $d => $i) {
      $parts = explode('.', $d);
      for ($k = 1; $k < count($parts) - 1; $k++) {
        $parent = implode('.', array_slice($parts, $k));
        if (isset($domains[$parent])) {
          $issues[] = ['line' => $i, 'level' => 'info', 'fix' => null, 'msg' => "Лишняя: уже покрыта записью «{$parent}» (строка " . ($domains[$parent] + 1) . ')'];
          break;
        }
      }
    }
  }
  usort($issues, fn($a, $b) => $a['line'] - $b['line']);
  return $issues;
}

function listProblems(string $path): array
{
  if (!is_file($path) || filesize($path) > 1048576) {
    return ['error' => 0, 'warning' => 0, 'info' => 0];
  }
  return cached('listlint-' . basename($path), [$path], function () use ($path) {
    $c = ['error' => 0, 'warning' => 0, 'info' => 0];
    foreach (lintList(basename($path), file_get_contents($path)) as $x) {
      $c[$x['level']]++;
    }
    return $c;
  });
}

// ================= исправления, отмена, перезапуск с проверкой, дубликаты =================

function iptPortsStr(array $ranges): string
{
  return implode(',', array_map(fn($x) => $x[0] === $x[1] ? (string)$x[0] : "$x[0]:$x[1]", $ranges));
}

function blobFiles(): array
{
  return array_values(array_map('basename', array_filter(glob(CONF_DIR . '/blobs/*') ?: [], 'is_file')));
}

// Точечная правка одного аргумента в тексте конфига: форматирование остального не трогаем.
// $new === null — удалить аргумент вместе с пробелами перед ним.
function editTokenInText(string $text, string $var, int $i, ?string $new): string
{
  if (!preg_match('/^' . preg_quote($var, '/') . '="([^"]*)"/m', $text, $m, PREG_OFFSET_CAPTURE)) {
    fail("Переменная $var не найдена");
  }
  $base = $m[1][1];
  preg_match_all('/\S+/', $m[1][0], $tm, PREG_OFFSET_CAPTURE);
  if (!isset($tm[0][$i])) {
    fail('Замечание устарело — обновите страницу');
  }
  [$tok, $off] = $tm[0][$i];
  $start = $base + $off;
  $end = $start + strlen($tok);
  if ($new !== null) {
    return substr($text, 0, $start) . $new . substr($text, $end);
  }
  // удаляем вместе с пробелами/переводом строки перед аргументом (или после, если он первый)
  $ws = $start;
  while ($ws > $base && ctype_space_($text[$ws - 1])) {
    $ws--;
  }
  if ($ws === $base) {
    while ($end < strlen($text) && ctype_space_($text[$end])) {
      $end++;
    }
    return substr($text, 0, $start) . substr($text, $end);
  }
  return substr($text, 0, $ws) . substr($text, $end);
}

function ctype_space_(string $c): bool
{
  return $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r";
}

// Дописывает аргумент в конец многострочной переменной, повторяя отступ её строк
function appendTokenInText(string $text, string $var, string $tok): string
{
  if (!preg_match('/^' . preg_quote($var, '/') . '="([^"]*)"/m', $text, $m, PREG_OFFSET_CAPTURE)) {
    return confSetVar($text, $var, $tok);
  }
  $val = $m[1][0];
  $indent = preg_match('/\n([ \t]*)\S[^\n]*$/', $val, $im) ? $im[1] : str_repeat(' ', strlen($var) + 2);
  $insert = trim($val) === '' ? $tok : "\n" . $indent . $tok;
  $pos = $m[1][1] + strlen(rtrim($val));
  return substr($text, 0, $pos) . $insert . substr($text, $pos);
}

// Применяет исправление замечания к тексту конфига и возвращает новый текст
function applyConfFix(array $x, ?string $choice): string
{
  $text = file_get_contents(CONF_FILE);
  $raw = confRawVars($text);
  $f = $x['fix'];
  if ($f['op'] === 'adapt') {
    return adaptConf($text)['text'];
  }
  if ($f['op'] === 'append') {
    return appendTokenInText($text, $f['var'], $f['tok']);
  }
  if ($f['op'] === 'set_var') {
    return confSetVar($text, $f['var'], $f['value']);
  }
  if ($f['op'] === 'declare_blob') {
    if (!in_array((string)$choice, $f['choices'], true)) {
      fail('Выберите файл блоба');
    }
    return appendTokenInText($text, 'NFQWS_BASE_ARGS', "--blob={$f['name']}:@" . CONF_DIR . "/blobs/$choice");
  }
  $var = $x['var'];
  $toks = tokens($raw[$var] ?? '');
  $i = $x['tok'];
  if ($i === null || !isset($toks[$i])) {
    fail('Замечание устарело — обновите страницу');
  }
  $t = $toks[$i];
  switch ($f['op']) {
    case 'remove':
      return editTokenInText($text, $var, $i, null);
    case 'remove_comment':
      for ($k = 0; $k < $f['count']; $k++) {
        $text = editTokenInText($text, $var, $i, null);
      }
      return $text;
    case 'replace':
      return editTokenInText($text, $var, $i, $f['to']);
    case 'move':
      return appendTokenInText(editTokenInText($text, $var, $i, null), $f['to_var'], $t);
    case 'remove_param':
    case 'rename_param':
    case 'add_param':
      [$name, $val] = array_pad(explode('=', $t, 2), 2, '');
      $parts = explode(':', $val);
      $fn = array_shift($parts);
      if ($f['op'] === 'remove_param') {
        $parts = array_values(array_filter($parts, fn($p) => explode('=', $p, 2)[0] !== $f['key']));
      } elseif ($f['op'] === 'rename_param') {
        $parts = array_map(fn($p) => explode('=', $p, 2)[0] === $f['key'] ? $f['to'] . substr($p, strlen($f['key'])) : $p, $parts);
      } else {
        $v = trim((string)$choice);
        if ($v === '' || ($f['choices'] && !in_array($v, $f['choices'], true)) || preg_match('/[\s:"`$\\\\]/', $v)) {
          fail('Выберите или введите значение');
        }
        array_unshift($parts, "{$f['key']}=$v");
      }
      return editTokenInText($text, $var, $i, $name . '=' . implode(':', array_merge([$fn], $parts)));
  }
  fail('Неизвестное исправление');
}

// ---------- отмена последнего действия ----------
// Все записи истории одного запроса имеют общий batch; отмена возвращает все затронутые файлы разом.

function lastUndoable(): ?array
{
  $log = histLog();
  $undone = [];
  foreach ($log as $e) {
    if (!empty($e['undo_of'])) {
      $undone[$e['undo_of']] = true;
    }
  }
  $batch = null;
  for ($k = count($log) - 1; $k >= 0; $k--) {
    $e = $log[$k];
    if (($e['source'] ?? '') === 'интерфейс' && !empty($e['batch']) && !empty($e['path']) && empty($e['undo_of']) && !isset($undone[$e['batch']])) {
      $batch = $e['batch'];
      break;
    }
  }
  if (!$batch) {
    return null;
  }
  $events = array_values(array_filter($log, fn($e) => ($e['batch'] ?? '') === $batch && !empty($e['path'])));
  return ['batch' => $batch, 'ts' => end($events)['ts'], 'events' => $events,
    'files' => array_values(array_unique(array_map(fn($e) => $e['file'], $events))),
    'note' => implode('; ', array_unique(array_filter(array_map(fn($e) => $e['note'], $events))))];
}

// Возвращает файлы к указанным версиям (hash; null — файла не было)
function restoreVersions(array $versions, string $note, ?string $undoOf = null): array
{
  $GLOBALS['UNDO_OF'] = $undoOf;
  beforeChange($note);
  $changed = [];
  foreach ($versions as $path => $h) {
    if (!in_array(dirname($path), [CONF_DIR, LISTS_DIR], true)) {
      continue;
    }
    if ($h === null) {
      if (is_file($path)) {
        @unlink($path);
        $changed[] = basename($path);
      }
      continue;
    }
    $c = histObjGet($h);
    if ($c === null || (is_file($path) && md5_file($path) === $h)) {
      continue;
    }
    file_put_contents($path . '.tmp-ui', $c);
    if (is_file($path)) {
      @chmod($path . '.tmp-ui', fileperms($path) & 0777);
      @chown($path . '.tmp-ui', fileowner($path));
    }
    rename($path . '.tmp-ui', $path);
    $changed[] = basename($path);
  }
  historyScan('интерфейс', $note);
  $GLOBALS['UNDO_OF'] = null;
  return $changed;
}

// ---------- перезапуск с проверкой и автооткатом ----------

const PENDING_FILE = '/tmp/nfqws-ui-pending.json';
define('CONFIRM_FILE', UI_CONF_DIR . '/confirmed.json');

// Момент последнего подтверждённого рабочего состояния: подтверждение пользователя или запуск nfqws2
function confirmPoint(): int
{
  $c = json_decode((string)@file_get_contents(CONFIRM_FILE), true);
  $pid = findPid();
  $start = $pid ? (processInfo($pid)['started'] ?? 0) : 0;
  return max((int)($c['ts'] ?? 0), (int)$start);
}

// Версии файлов на момент $T для всего, что менялось после него
function versionsAt(int $T): array
{
  $res = [];
  foreach (histLog() as $e) {
    if (empty($e['path']) || $e['ts'] <= $T || array_key_exists($e['path'], $res)) {
      continue;
    }
    $res[$e['path']] = $e['from'];
  }
  return $res;
}

function pendingGet(): ?array
{
  $p = json_decode((string)@file_get_contents(PENDING_FILE), true);
  return is_array($p) ? $p : null;
}

function pendingSave(array $p): void
{
  file_put_contents(PENDING_FILE . '.tmp', json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  rename(PENDING_FILE . '.tmp', PENDING_FILE);
}

function doRollback(array &$p, string $reason): void
{
  $changed = restoreVersions($p['versions'], 'откат к рабочему состоянию: ' . $reason);
  exec(INIT_SCRIPT . ' restart 2>&1');
  $p['state'] = 'rolled_back';
  $p['reason'] = $reason;
  $p['rolled_files'] = $changed;
  $p['finished'] = time();
  pendingSave($p);
}

// Фоновый сторож: проверяет сайты после перезапуска и откатывает, если не подтвердили вовремя
function guardJob(): void
{
  $p = pendingGet();
  if (!$p || $p['state'] !== 'checking') {
    return;
  }
  set_time_limit(1200);
  sleep(6);
  $p = pendingGet();
  if (!$p || $p['state'] !== 'checking') {
    return;
  }
  $running = findPid() !== null;
  $checks = [];
  foreach (uiSettings()['monitor']['sites'] as $host) {
    $r = probe($host);
    $before = $p['before'][$host] ?? null;
    $checks[] = ['host' => $host, 'ok' => $r['ok'], 'ms' => $r['ms'], 'reason' => $r['reason'], 'was_ok' => $before, 'regressed' => $before === 1 && !$r['ok']];
  }
  $p['checks'] = $checks;
  $p['running'] = $running;
  $p['state'] = 'waiting';
  pendingSave($p);
  if (!$running) {
    doRollback($p, 'nfqws2 не запустился');
    return;
  }
  while (time() < $p['deadline']) {
    sleep(2);
    $cur = pendingGet();
    if (!$cur || $cur['state'] !== 'waiting' || ($cur['id'] ?? '') !== $p['id']) {
      return;
    }
  }
  $p = pendingGet();
  if ($p && $p['state'] === 'waiting') {
    doRollback($p, 'изменения не подтверждены за отведённое время');
  }
}

// ---------- дубликаты и конфликты между списками ----------

function dupScan(): array
{
  $inv = listsInventory(currentProfiles());
  $map = [];
  $meta = [];
  foreach ($inv as $l) {
    if ($l['kind'] !== 'host' || !$l['exists'] || $l['size'] > 2 * 1048576 || str_ends_with($l['path'], '.gz')) {
      continue;
    }
    $roles = array_values(array_unique(array_column($l['used'], 'role')));
    $meta[$l['name']] = ['used' => (bool)$l['used'], 'roles' => $roles];
    foreach (readListLines($l['path']) as $e) {
      $d = preg_replace('/^\*\.?/', '', ltrim(strtolower($e), '^'));
      $map[$d][$l['name']] = $e;
    }
  }
  $items = [];
  foreach ($map as $d => $where) {
    if (count($where) > 1) {
      $inc = $exc = false;
      foreach (array_keys($where) as $ln) {
        $inc = $inc || in_array('include', $meta[$ln]['roles'], true) || in_array('auto', $meta[$ln]['roles'], true);
        $exc = $exc || in_array('exclude', $meta[$ln]['roles'], true);
      }
      $usedN = count(array_filter(array_keys($where), fn($ln) => $meta[$ln]['used']));
      $items[] = ['domain' => $d, 'kind' => $inc && $exc ? 'conflict' : 'dup', 'where' => $where, 'used' => $usedN];
    }
    // покрыт родительским доменом из ДРУГОГО списка
    $parts = explode('.', $d);
    for ($k = 1; $k < count($parts) - 1; $k++) {
      $parent = implode('.', array_slice($parts, $k));
      if (isset($map[$parent])) {
        $other = array_diff_key($map[$parent], $where);
        if ($other) {
          $items[] = ['domain' => $d, 'kind' => 'covered', 'parent' => $parent, 'where' => $where, 'parent_in' => array_keys($other),
            'used' => count(array_filter(array_merge(array_keys($where), array_keys($other)), fn($ln) => $meta[$ln]['used']))];
        }
        break;
      }
    }
  }
  $rank = ['conflict' => 0, 'dup' => 1, 'covered' => 2];
  usort($items, fn($a, $b) => [$rank[$a['kind']], $a['domain']] <=> [$rank[$b['kind']], $b['domain']]);
  return ['items' => array_slice($items, 0, 1000), 'total' => count($items), 'lists' => $meta];
}

// ================= мониторинг доступности =================
// Раз в interval минут (по cron-сканированию) открывает сайты с роутера и хранит последние 96 результатов.
// При смене состояния отправляет сообщение в Telegram, если задан бот.

define('MONITOR_FILE', UI_CONF_DIR . '/monitor.json');

function monitorData(): array
{
  $d = json_decode((string)@file_get_contents(MONITOR_FILE), true);
  return is_array($d) ? $d : ['last' => 0, 'sites' => []];
}

function monitorRun(bool $force, ?array $only = null): ?array
{
  $cfg = uiSettings()['monitor'];
  $data = monitorData();
  if (!$force && (!$cfg['enabled'] || time() - ($data['last'] ?? 0) < $cfg['interval'] * 60 - 30)) {
    return null;
  }
  $changes = [];
  foreach ($only !== null ? array_values(array_intersect($cfg['sites'], $only)) : $cfg['sites'] as $host) {
    $r = probe($host);
    $hist = $data['sites'][$host] ?? [];
    $prevOk = $hist ? end($hist)[1] : null;
    $hist[] = [time(), $r['ok'] ? 1 : 0, $r['ms'], $r['reason']];
    $data['sites'][$host] = array_slice($hist, -96);
    if ($prevOk !== null && $prevOk !== ($r['ok'] ? 1 : 0)) {
      $changes[] = ($r['ok'] ? '✅ снова открывается: ' : '❌ перестал открываться: ') . $host . ($r['ok'] ? '' : ' — ' . $r['reason']);
    }
  }
  // сайты, убранные из мониторинга, не храним
  $data['sites'] = array_intersect_key($data['sites'], array_flip($cfg['sites']));
  if ($only === null) {
    $data['last'] = time();   // ручная проверка отдельных сайтов не сдвигает расписание
  }
  @mkdir(UI_CONF_DIR, 0755, true);
  file_put_contents(MONITOR_FILE, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  if ($changes) {
    notifyTelegram("nfqws2 на роутере:\n" . implode("\n", $changes));
  }
  return $data;
}

function notifyTelegram(string $text): ?string
{
  $t = uiSettings()['notify'];
  if (empty($t['tg_token']) || empty($t['tg_chat'])) {
    return 'не настроено';
  }
  $ch = curl_init('https://api.telegram.org/bot' . $t['tg_token'] . '/sendMessage');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $t['tg_chat'], 'text' => $text]),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 6]);
  $res = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);
  $j = json_decode((string)$res, true);
  return !empty($j['ok']) ? null : ($j['description'] ?? $err ?: 'ошибка отправки');
}

// ================= подписки на списки =================
// Список обновляется по ссылке раз в сутки (cron daily) или по кнопке. Новая версия проходит проверку:
// если больше трети строк с ошибками — обновление отклоняется.

function subsRun(?string $only = null): array
{
  $s = uiSettings();
  $report = [];
  foreach ($s['subs'] as $i => $sub) {
    if ($only !== null && $sub['list'] !== $only) {
      continue;
    }
    $path = LISTS_DIR . '/' . $sub['list'];
    $tmp = tempnam('/tmp', 'nfqsub');
    exec('curl -fsSL -m 60 --max-filesize 10485760 -o ' . escapeshellarg($tmp) . ' ' . escapeshellarg($sub['url']) . ' 2>&1', $out, $rc);
    $sub['last'] = time();
    if ($rc !== 0) {
      $sub['status'] = 'error';
      $sub['error'] = 'не скачалось: ' . (trim(implode(' ', $out)) ?: "curl $rc");
    } else {
      $text = normalizeText((string)file_get_contents($tmp));
      $lines = count(readListLinesFromText($text));
      $errors = count(array_filter(lintList($sub['list'], $text), fn($x) => $x['level'] === 'error'));
      if ($lines === 0 || $errors > $lines / 3) {
        $sub['status'] = 'error';
        $sub['error'] = $lines === 0 ? 'пустой список' : "слишком много ошибок ($errors из $lines строк) — это не похоже на список";
      } else {
        $old = is_file($path) ? file_get_contents($path) : '';
        [$added, $removed] = lineDelta($old, $text);
        if ($old !== $text) {
          writeWithBackup($path, $text, 'обновлено по подписке: +' . count($added) . ' −' . count($removed));
        }
        $sub['status'] = 'ok';
        $sub['error'] = null;
        $sub['count'] = $lines;
        $sub['changed'] = $old !== $text ? ['added' => count($added), 'removed' => count($removed)] : null;
      }
    }
    @unlink($tmp);
    $s['subs'][$i] = $sub;
    $report[] = $sub;
  }
  saveUiSettings($s);
  return $report;
}

function readListLinesFromText(string $text): array
{
  return array_values(array_filter(array_map('trim', explode("\n", $text)), fn($l) => $l !== '' && $l[0] !== '#'));
}

// ================= podkop =================
// podkop (sing-box) отвечает на DNS-запросы адресами FakeIP из 198.18.0.0/15 для сайтов, которые
// пускает через прокси. Спрашиваем его DNS напрямую — так видно, куда пойдёт трафик клиентов сети.

function podkopRoute(string $host): ?array
{
  if (!is_file('/etc/init.d/podkop')) {
    return null;
  }
  exec('nslookup ' . escapeshellarg($host) . ' 127.0.0.42 2>/dev/null', $out, $rc);
  $ips = [];
  $afterName = false;
  foreach ($out as $l) {
    if (str_starts_with($l, 'Name:')) {
      $afterName = true;
    } elseif ($afterName && preg_match('/^Address:\s*([0-9.]+)$/', trim($l), $m)) {
      $ips[] = $m[1];
    }
  }
  if (!$ips) {
    return ['available' => false];
  }
  $fake = (bool)array_filter($ips, fn($ip) => ipInEntry(inet_pton($ip), '198.18.0.0/15'));
  return ['available' => true, 'proxy' => $fake, 'ips' => $ips];
}

// ================= тесты стратегий и трассировка =================
//
// Тест поднимает отдельный nfqws2 на очереди TEST_QNUM. Правила iptables отправляют в неё только
// проверочные соединения самого роутера — curl ходит с локальных портов TEST_PORTS. Основной nfqws2
// и трафик сети это не затрагивает (NFQUEUE-вердикт завершает обход mangle, до nfqws_post пакет не доходит).

const TEST_DIR = '/tmp/nfqws-ui-test';
const TEST_QNUM = 301;
const TEST_PORTS = '40000:40999';

// Стандартный набор стратегий для TLS и HTTP (стратегии без payload/filter — только шаги)
const STD_TLS = [
  ['multisplit pos=1,midsld', ['--lua-desync=multisplit:pos=1,midsld']],
  ['multisplit pos=2', ['--lua-desync=multisplit:pos=2']],
  ['multidisorder pos=1,midsld', ['--lua-desync=multidisorder:pos=1,midsld']],
  ['multisplit с seqovl', ['--lua-desync=multisplit:pos=1:seqovl=1:seqovl_pattern=tls_clienthello']],
  ['fake tcp_md5 + multisplit', ['--lua-desync=fake:blob=tls_clienthello:tcp_md5', '--lua-desync=multisplit:pos=1,midsld']],
  ['fake badsum + multisplit', ['--lua-desync=fake:blob=tls_clienthello:badsum', '--lua-desync=multisplit:pos=1,midsld']],
  ['fake tcp_seq + multidisorder', ['--lua-desync=fake:blob=tls_clienthello:tcp_seq=-10000', '--lua-desync=multidisorder:pos=1,midsld']],
  ['fake autottl + multisplit', ['--lua-desync=fake:blob=tls_clienthello:tls_mod=rnd,dupsid,sni=www.google.com:ip_autottl=-2,3-20', '--lua-desync=multisplit:pos=1,midsld']],
  ['fake x6 tcp_md5 + fakedsplit', ['--lua-desync=fake:blob=tls_clienthello:repeats=6:tcp_md5', '--lua-desync=fakedsplit:pos=1:tcp_md5']],
  ['fakedsplit tcp_md5', ['--lua-desync=fakedsplit:pos=midsld:tcp_md5']],
  ['fakeddisorder tcp_md5', ['--lua-desync=fakeddisorder:pos=midsld:tcp_md5']],
  ['hostfakesplit google.com', ['--lua-desync=hostfakesplit:host=google.com:tcp_md5:tcp_ts_up']],
];
const STD_HTTP = [
  ['http_methodeol', ['--lua-desync=http_methodeol']],
  ['http_hostcase', ['--lua-desync=http_hostcase']],
  ['multisplit pos=method+2', ['--lua-desync=multisplit:pos=method+2']],
  ['fake badsum + multisplit', ['--lua-desync=fake:blob=0x00000000:badsum', '--lua-desync=multisplit:pos=method+2']],
];

// Стратегии из конфига: шаги профилей, которые могут обработать это соединение; circular раскладывается на стратегии
function configCandidates(string $proto): array
{
  $wantPayload = $proto === 'http' ? 'http_req' : 'tls_client_hello';
  $port = $proto === 'http' ? 80 : 443;
  $out = [];
  foreach (expectedProfiles(confValues()) as $n => $e) {
    $p = buildProfile($n + 1, $e['tokens']);
    if (!inRanges($port, protoPorts($p)['tcp'])) {
      continue;
    }
    $payload = null;
    $steps = [];
    foreach ($e['tokens'] as $t) {
      if (str_starts_with($t, '--payload=')) {
        $payload = explode(',', substr($t, 10));
      } elseif (str_starts_with($t, '--lua-desync=') && ($payload === null || in_array($wantPayload, $payload, true) || in_array('all', $payload, true) || in_array('known', $payload, true))) {
        $steps[] = $t;
      }
    }
    if (!$steps) {
      continue;
    }
    $strategies = [];
    foreach ($steps as $t) {
      if (preg_match('/^--lua-desync=circular(:|$)/', $t)) {
        continue;
      }
      $n2 = preg_match('/:strategy=(\d+)/', $t, $m) ? (int)$m[1] : 0;
      $strategies[$n2][] = preg_replace('/:strategy=\d+/', '', $t);
    }
    ksort($strategies);
    foreach ($strategies as $sn => $st) {
      $key = implode(' ', $st);
      $from = '#' . ($n + 1) . ($sn ? " стратегия $sn" : '');
      if (isset($out[$key])) {
        // одна и та же стратегия в нескольких профилях (общая переменная) — тестируем один раз
        $out[$key]['from'] .= ', ' . $from;
        continue;
      }
      $fns = implode(' + ', array_map(fn($t) => explode(':', substr($t, 13))[0], $st));
      $out[$key] = ['name' => $fns, 'from' => 'профиль ' . $from, 'steps' => $st];
    }
  }
  return array_values($out);
}

function testRules(string $mode, string $iface): void
{
  $ipt = 'iptables -w -t mangle ';
  // снять старое
  foreach (['POSTROUTING' => 'nfqws_test_post', 'PREROUTING' => 'nfqws_test_pre'] as $hook => $chain) {
    while (true) {
      exec($ipt . "-D $hook -j $chain 2>/dev/null", $o, $rc);
      if ($rc !== 0) {
        break;
      }
    }
    exec($ipt . "-F $chain 2>/dev/null; " . $ipt . "-X $chain 2>/dev/null");
  }
  if ($mode === 'off') {
    return;
  }
  $if = escapeshellarg($iface);
  $cmds = [
    $ipt . '-N nfqws_test_post', $ipt . '-N nfqws_test_pre',
    $ipt . '-I POSTROUTING 1 -j nfqws_test_post', $ipt . '-I PREROUTING 1 -j nfqws_test_pre',
  ];
  if ($mode === 'queue') {
    $cmds[] = $ipt . "-A nfqws_test_post -o $if -p tcp -m multiport --sports " . TEST_PORTS . ' -m mark ! --mark 0x40000000/0x40000000 -j NFQUEUE --queue-num ' . TEST_QNUM . ' --queue-bypass';
    $cmds[] = $ipt . "-A nfqws_test_pre -i $if -p tcp -m multiport --dports " . TEST_PORTS . ' -m connbytes --connbytes-dir=reply --connbytes-mode=packets --connbytes 1:15 -j NFQUEUE --queue-num ' . TEST_QNUM . ' --queue-bypass';
  }
  // без обхода и после очереди — мимо основного nfqws2
  $cmds[] = $ipt . "-A nfqws_test_post -o $if -p tcp -m multiport --sports " . TEST_PORTS . ' -j ACCEPT';
  $cmds[] = $ipt . "-A nfqws_test_pre -i $if -p tcp -m multiport --dports " . TEST_PORTS . ' -j ACCEPT';
  foreach ($cmds as $c) {
    exec($c . ' 2>&1');
  }
}

function curlProbe(string $host, bool $http): array
{
  $url = ($http ? 'http://' : 'https://') . $host . '/';
  // Начальный порт — случайный: curl берёт первый свободный, и соединения подряд шли бы с одного и того же
  // порта к тому же серверу — на следующее может повлиять остаток прошлого (conntrack, состояние у провайдера).
  $curl = function () {
    [$lo, $hi] = array_map('intval', explode(':', TEST_PORTS));
    return 'curl -4 -sk -o /dev/null --local-port ' . mt_rand($lo, $hi - 50) . '-' . $hi
      . ' --connect-timeout 4 -A ' . escapeshellarg('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130 Safari/537.36');
  };
  $out = [];
  exec($curl() . ' -m 7 -r 0-' . (VOLUME_BYTES - 1) . ' -w "%{http_code} %{time_total} %{size_download}" ' . escapeshellarg($url) . ' 2>/dev/null', $out, $rc);
  [$code, $time, $size] = array_pad(explode(' ', trim($out[0] ?? '')), 3, '0');
  $ok = $rc === 0 && (int)$code > 0;
  $reason = $ok ? null : (match ($rc) {
    6 => 'имя не разрешается',
    7 => 'не удалось подключиться',
    28 => (int)$code > 0 ? REASON_FREEZE : 'тайм-аут',
    35 => 'обрыв TLS',
    52 => 'пустой ответ',
    56 => 'соединение сброшено',
    default => "ошибка curl $rc",
  });
  // маленькая страница — проверяем объём, как чекер tcp-16-20 (см. volumeFrozen)
  if ($ok && (int)$size < VOLUME_SMALL) {
    $body = TEST_DIR . '/volume.bin';
    if (!is_file($body) || filesize($body) !== VOLUME_BYTES) {
      file_put_contents($body, random_bytes(VOLUME_BYTES));
    }
    $vout = [];
    exec($curl() . ' -m 8 -H "Expect:" -H "Content-Type: application/octet-stream" --data-binary @' . escapeshellarg($body)
      . ' -w "%{time_appconnect}" ' . escapeshellarg($url . '?t=' . mt_rand()) . ' 2>/dev/null', $vout, $vrc);
    if ($vrc === 28 && (float)trim($vout[0] ?? '0') > 0) {
      [$ok, $reason] = [false, REASON_FREEZE];
    }
  }
  return ['ok' => $ok, 'code' => (int)$code, 'ms' => (int)round((float)$time * 1000), 'reason' => $reason];
}

// Запускает отдельный nfqws2 на тестовой очереди; возвращает процесс или null
function testNfqwsStart(array $args, ?string $debugFile = null): ?array
{
  $argv = array_merge([NFQWS_BIN, '--qnum=' . TEST_QNUM, '--user=nobody'], $debugFile ? ['--debug=@' . $debugFile] : [], $args);
  $p = proc_open(implode(' ', array_map('escapeshellarg', $argv)) . ' >' . TEST_DIR . '/nfqws.out 2>&1', [], $pipes);
  if (!is_resource($p)) {
    return null;
  }
  usleep(600000);
  $st = proc_get_status($p);
  if (!$st['running']) {
    proc_close($p);
    return null;
  }
  return ['proc' => $p, 'pid' => $st['pid']];
}

function testNfqwsStop(?array $h): void
{
  if (!$h) {
    return;
  }
  // proc_open запускает через sh: убиваем и оболочку, и сам nfqws2
  exec('pkill -f ' . escapeshellarg('nfqws2 --qnum=' . TEST_QNUM) . ' 2>/dev/null');
  proc_terminate($h['proc'], 15);
  proc_close($h['proc']);
}

function testStatus(): array
{
  $s = json_decode((string)@file_get_contents(TEST_DIR . '/status.json'), true);
  return is_array($s) ? $s : ['state' => 'idle'];
}

function testSaveStatus(array $s): void
{
  file_put_contents(TEST_DIR . '/status.json.tmp', json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  rename(TEST_DIR . '/status.json.tmp', TEST_DIR . '/status.json');
}

function testRunning(): bool
{
  $s = testStatus();
  return ($s['state'] ?? '') === 'running' && !empty($s['pid']) && file_exists('/proc/' . $s['pid']);
}

// Фоновое задание (запускается через cron-режим CLI): подбор стратегии или трассировка
function testJob(): void
{
  $job = json_decode((string)@file_get_contents(TEST_DIR . '/job.json'), true);
  if (!is_array($job)) {
    return;
  }
  $exp = confValues();
  $iface = explode(' ', trim($exp['ISP_INTERFACE']))[0] ?: 'eth1';
  $baseArgs = tokens($exp['NFQWS_BASE_ARGS']);
  $http = ($job['proto'] ?? 'https') === 'http';
  $status = ['state' => 'running', 'type' => $job['type'], 'pid' => getmypid(), 'host' => $job['host'], 'proto' => $job['proto'] ?? 'https',
    'started' => time(), 'results' => [], 'done' => 0, 'total' => 0, 'current' => null];
  $auto = !empty($job['auto']);
  if ($auto) {
    $status['auto'] = true;
  }
  $nfq = null;
  register_shutdown_function(function () use (&$nfq, $iface, &$status) {
    testNfqwsStop($nfq);
    testRules('off', $iface);
    if ($status['state'] === 'running') {
      $status['state'] = 'error';
      $status['error'] = $status['error'] ?? 'задание прервано';
      testSaveStatus($status);
    }
  });
  set_time_limit(900);

  if ($job['type'] === 'trace') {
    // Трассировка: один запрос через копию текущей конфигурации с подробным журналом
    $status['total'] = 1;
    testSaveStatus($status);
    $args = $baseArgs;
    foreach (array_column(expectedProfiles($exp), 'tokens') as $k => $pt) {
      if ($k > 0) {
        $args[] = '--new';
      }
      array_push($args, ...$pt);
    }
    $log = TEST_DIR . '/trace.log';
    @unlink($log);
    testRules('queue', $iface);
    $nfq = testNfqwsStart($args, $log);
    if (!$nfq) {
      $status['state'] = 'error';
      $status['error'] = 'не удалось запустить nfqws2: ' . trim((string)@file_get_contents(TEST_DIR . '/nfqws.out'));
      testSaveStatus($status);
      return;
    }
    $r = curlProbe($job['host'], $http);
    usleep(300000);
    testNfqwsStop($nfq);
    $nfq = null;
    testRules('off', $iface);
    $lines = @file($log, FILE_IGNORE_NEW_LINES) ?: [];
    $status['result'] = $r;
    $status['trace'] = array_slice($lines, 0, 1500);
    $status['trace_total'] = count($lines);
    $status['done'] = 1;
    $status['state'] = 'done';
    $status['finished'] = time();
    testSaveStatus($status);
    return;
  }

  // Подбор стратегии. Сначала — имеет ли он смысл: если имя не находится или адрес не отвечает вовсе,
  // перебор стратегий только зря займёт несколько минут.
  $dns = diagDns($job['host']);
  $why = null;
  if ($dns['status'] === 'nxdomain') {
    $why = 'Имя сайта не находится — ни у роутера, ни у защищённого DNS. Проверьте написание.';
  } elseif ($dns['ips'] && !array_filter(diagTcp($dns['ips'], $http ? 80 : 443), fn($x) => $x['ok'])) {
    $why = 'С адресом сайта нет соединения (' . implode(', ', $dns['ips']) . ') — похоже на блокировку по IP. Стратегии nfqws2 меняют пакеты, а не маршрут, и тут не помогут: нужен туннель (podkop, VPN).';
  }
  if ($why !== null) {
    $status['state'] = 'error';
    $status['title'] = 'Подбор не запущен';
    $status['error'] = $why;
    testSaveStatus($status);
    return;
  }
  $cands = [];
  if (in_array('config', $job['sets'], true)) {
    $cands = array_merge($cands, configCandidates($status['proto']));
  }
  if (in_array('std', $job['sets'], true)) {
    foreach ($http ? STD_HTTP : STD_TLS as [$name, $steps]) {
      $cands[] = ['name' => $name, 'from' => 'стандартный набор', 'steps' => $steps];
    }
  }
  if (!empty($job['steps'])) {
    array_unshift($cands, ['name' => strategyName($job['steps']), 'from' => 'вставленный профиль', 'steps' => $job['steps']]);
  }
  // То, что уже работало: совпавшие с набором стратегии помечаем, остальные добавляем
  $own = in_array('hist', $job['sets'], true);
  $byKey = [];
  foreach ($cands as $i => $c) {
    $byKey[implode(' ', $c['steps'])] = $i;
  }
  foreach (picksCandidates($job['host'], $status['proto'], $own, in_array('other', $job['sets'], true)) as $hc) {
    $key = implode(' ', $hc['steps']);
    if (isset($byKey[$key])) {
      if ($hc['rank'] === 0) {
        $cands[$byKey[$key]] += ['hist' => $hc['hist'], 'rank' => 0];
      }
      continue;
    }
    // стратегия из истории, которая стоит и в конфиге, — показываем, в каком она профиле
    foreach (configCandidates($status['proto']) as $cc) {
      if (implode(' ', $cc['steps']) === $key) {
        $hc['from'] = $cc['from'];
      }
    }
    $byKey[$key] = count($cands);
    $cands[] = $hc;
  }
  // Функции, которых нет в подключённых lua-скриптах, пропускаем
  $funcs = luaCatalog()['functions'];
  $cands = array_values(array_filter($cands, function ($c) use ($funcs) {
    foreach ($c['steps'] as $t) {
      if (!isset($funcs[explode(':', substr($t, 13))[0]])) {
        return false;
      }
    }
    return true;
  }));
  if (!$cands) {
    $status['state'] = 'error';
    $status['title'] = 'Подбор не запущен';
    $status['error'] = $own && count($job['sets']) === 1 ? 'В истории нет стратегий, которые работали для этого сайта. Запустите обычный подбор.' : 'Нет ни одной стратегии для проверки.';
    testSaveStatus($status);
    return;
  }
  $filter = $http ? ['--filter-tcp=80', '--filter-l7=http', '--payload=http_req'] : ['--filter-tcp=443', '--filter-l7=tls', '--payload=tls_client_hello'];
  $repeats = max(1, min(5, (int)($job['repeats'] ?? 3)));
  $status['total'] = count($cands) + 1;
  $status['phase'] = 'baseline';
  testSaveStatus($status);

  // Без обхода — чтобы понять, заблокирован ли сайт вообще
  $status['current'] = 'без обхода';
  testSaveStatus($status);
  testRules('bypass', $iface);
  $status['baseline'] = curlProbe($job['host'], $http);
  $status['done'] = 1;
  if ($auto && $status['baseline']['ok']) {
    $cands = [];   // блокировки нет — перебирать нечего
  }
  $cands = orderCandidates($cands, $status['baseline']['reason'] ?? null);
  // работавшее для этого сайта — первым, затем помогавшее другим сайтам
  usort($cands, fn($a, $b) => ($a['rank'] ?? 2) <=> ($b['rank'] ?? 2));
  $status['phase'] = 'pick';
  testSaveStatus($status);

  testRules('queue', $iface);
  // Одна стратегия: отдельный nfqws2 и до $repeats запросов
  $try = function (array $steps) use (&$nfq, $baseArgs, $filter, $repeats, $job, $http): array {
    $nfq = testNfqwsStart(array_merge($baseArgs, $filter, $steps));
    if (!$nfq) {
      return ['ok' => 0, 'tries' => 0, 'ms' => null, 'reason' => 'nfqws2 не принял параметры: ' . trim((string)@file_get_contents(TEST_DIR . '/nfqws.out'))];
    }
    $ok = 0;
    $times = [];
    $reason = null;
    for ($i = 0; $i < $repeats; $i++) {
      $r = curlProbe($job['host'], $http);
      if ($r['ok']) {
        $ok++;
        $times[] = $r['ms'];
      } else {
        $reason = $r['reason'];
        if ($i === 0) {
          break;  // первая попытка не прошла — дальше не тратим время
        }
      }
    }
    testNfqwsStop($nfq);
    $nfq = null;
    return ['ok' => $ok, 'tries' => $i < $repeats ? $i + 1 : $repeats, 'ms' => $times ? (int)round(array_sum($times) / count($times)) : null, 'reason' => $reason];
  };
  foreach ($cands as $c) {
    if (is_file(TEST_DIR . '/stop')) {
      $status['state'] = 'stopped';
      break;
    }
    $status['current'] = $c['name'];
    testSaveStatus($status);
    $status['results'][] = ['name' => $c['name'], 'from' => $c['from'], 'steps' => $c['steps'], 'profile' => array_merge($filter, $c['steps'])]
      + (isset($c['hist']) ? ['hist' => $c['hist']] : []) + $try($c['steps']);
    $status['done']++;
    testSaveStatus($status);
    if ($auto && end($status['results'])['ok'] === $repeats) {
      break;   // автоподбору хватает первой стратегии, открывшей сайт каждый раз
    }
  }
  if ($status['state'] === 'running' && !($auto && !$cands)) {
    testRefine($status, $try, $repeats, $http, !empty($job['refine']), $filter);
  }
  testRules('off', $iface);
  if ($status['state'] === 'running') {
    $status['state'] = 'done';
  }
  $status['repeats'] = $repeats;
  $status['finished'] = time();
  $status['current'] = null;
  testSaveStatus($status);
  picksAdd($status, $repeats);
}

// ----- история подборов: что и когда работало для каждого сайта -----

const PICKS_MAX = 100;       // запусков всего
const PICKS_PER_HOST = 10;   // и на один сайт
const PICKS_OTHER = 5;       // сколько чужих находок пробовать в подборе

function picksLoad(): array
{
  $f = UI_CONF_DIR . '/picks.json';
  if (is_file($f)) {
    return json_decode((string)@file_get_contents($f), true) ?: [];
  }
  // записи прежней короткой истории — без подробностей
  return json_decode((string)@file_get_contents(UI_CONF_DIR . '/tests.json'), true) ?: [];
}

function picksSave(array $items): void
{
  @mkdir(UI_CONF_DIR, 0755, true);
  $f = UI_CONF_DIR . '/picks.json';
  $json = json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    return;
  }
  file_put_contents("$f.tmp", $json);
  rename("$f.tmp", $f);
}

function picksAdd(array $status, int $repeats): void
{
  $full = array_values(array_filter($status['results'], fn($r) => $r['ok'] === $repeats));
  usort($full, fn($a, $b) => ($a['ms'] ?? PHP_INT_MAX) <=> ($b['ms'] ?? PHP_INT_MAX));
  $rec = ['ts' => time(), 'host' => $status['host'], 'proto' => $status['proto'], 'baseline' => $status['baseline'] ?? null,
    'ok' => count($full), 'total' => count($status['results']), 'best' => $full ? $full[0]['name'] : null,
    'dur' => time() - $status['started'], 'state' => $status['state'], 'repeats' => $repeats, 'auto' => !empty($status['auto']),
    // параметры храним только у того, что открыло сайт хоть раз: остальное заново не понадобится
    'results' => array_map(fn($r) => array_filter(['name' => $r['name'], 'from' => $r['from'], 'ok' => $r['ok'], 'tries' => $r['tries'], 'ms' => $r['ms'],
      'reason' => $r['ok'] === $r['tries'] ? null : ($r['reason'] ?? null), 'steps' => $r['ok'] > 0 ? $r['steps'] : null, 'refined' => $r['refined'] ?? null],
      fn($v) => $v !== null), $status['results'])];
  $out = [$rec];
  $n = 1;
  foreach (picksLoad() as $e) {
    if ($e['host'] === $rec['host'] && ++$n > PICKS_PER_HOST) {
      continue;
    }
    $out[] = $e;
  }
  picksSave(array_slice($out, 0, PICKS_MAX));
}

// Стратегии из истории для подбора: работавшие для этого сайта (rank 0) и помогавшие другим (rank 1)
function picksCandidates(string $host, string $proto, bool $own, bool $other): array
{
  $mine = [];
  $rest = [];
  foreach (picksLoad() as $e) {
    if (($e['proto'] ?? 'https') !== $proto || !empty($e['baseline']['ok'])) {
      continue;
    }
    foreach ($e['results'] ?? [] as $r) {
      if (empty($r['steps']) || $r['ok'] < ($e['repeats'] ?? 1)) {
        continue;
      }
      $key = implode(' ', $r['steps']);
      if ($e['host'] === $host) {
        $mine[$key] ??= ['name' => $r['name'], 'from' => 'история подборов', 'steps' => $r['steps'], 'hist' => $e['ts'], 'rank' => 0];
      } else {
        $rest[$key] ??= ['name' => $r['name'], 'from' => 'история: помогла ' . $e['host'], 'steps' => $r['steps'], 'rank' => 1];
      }
    }
  }
  $rest = array_slice(array_diff_key($rest, $mine), 0, PICKS_OTHER);
  return array_merge($own ? array_values($mine) : [], $other ? array_values($rest) : []);
}

// ----- автоподбор при поломке: мониторинг увидел, что сайт перестал открываться, — подбираем стратегию сами -----
// Работает из прохода cron (раз в 10 минут). За проход — один сайт; найденное либо предлагается кнопкой,
// либо (режим «применять самому») ставится отдельным профилем только для этого сайта с проверкой и откатом.

define('AUTO_FILE', UI_CONF_DIR . '/auto.json');

function autoData(): array
{
  $d = json_decode((string)@file_get_contents(AUTO_FILE), true);
  return (is_array($d) ? $d : []) + ['queue' => [], 'sites' => [], 'offers' => [], 'log' => []];
}

function autoSave(array $d): void
{
  @mkdir(UI_CONF_DIR, 0755, true);
  $d['log'] = array_slice($d['log'], 0, 60);
  $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json !== false) {
    file_put_contents(AUTO_FILE . '.tmp', $json);
    rename(AUTO_FILE . '.tmp', AUTO_FILE);
  }
}

function autoLog(array &$d, string $host, string $kind, string $text): void
{
  array_unshift($d['log'], ['ts' => time(), 'host' => $host, 'kind' => $kind, 'text' => $text]);
}

// Сколько проверок подряд сайт не открывается и открывался ли он до этого
function autoStreak(array $hist): array
{
  $n = 0;
  for ($i = count($hist) - 1; $i >= 0 && !$hist[$i][1]; $i--) {
    $n++;
  }
  return [$n, $i >= 0];
}

function testLaunch(array $job): void
{
  @mkdir(TEST_DIR, 0777, true);
  @chmod(TEST_DIR, 0777);
  @unlink(TEST_DIR . '/stop');
  file_put_contents(TEST_DIR . '/job.json', json_encode($job));
  touch(TEST_DIR . '/trace.log');
  chmod(TEST_DIR . '/trace.log', 0666);
  testSaveStatus(['state' => 'starting', 'host' => $job['host'], 'started' => time()]);
  // Чистое окружение: иначе php-cgi увидит CGI-переменные запроса и не перейдёт в режим задания
  exec('(env -i PATH=' . JOB_PATH . ' NFQWS_UI_CLI=test php-cgi -q -f ' . escapeshellarg(__FILE__) . ' >/dev/null 2>&1 &)');
}

function diagBusy(): bool
{
  $l = @fopen(TEST_DIR . '/diag.lock', 'c');
  if (!$l) {
    return false;
  }
  $busy = !flock($l, LOCK_EX | LOCK_NB);
  if (!$busy) {
    flock($l, LOCK_UN);
  }
  fclose($l);
  return $busy;
}

// Проход cron: перепроверить только что упавшие сайты, поставить сломавшиеся в очередь, запустить подбор для одного
function autoTick(): void
{
  $s = uiSettings();
  $a = $s['auto'];
  if (!$a['enabled'] || !$s['monitor']['enabled']) {
    return;
  }
  $mon = monitorData();
  // быстрая перепроверка: не ждём полного интервала, чтобы отличить сбой от поломки
  $again = [];
  foreach ($s['monitor']['sites'] as $h) {
    [$n, $was] = autoStreak($mon['sites'][$h] ?? []);
    $lastTs = $n ? end($mon['sites'][$h])[0] : 0;
    if ($n > 0 && $n < $a['fails'] && $was && time() - $lastTs >= 300) {
      $again[] = $h;
    }
  }
  if ($again) {
    $mon = monitorRun(true, $again) ?? $mon;
  }
  $d = autoData();
  $down = [];
  foreach ($s['monitor']['sites'] as $h) {
    $hist = $mon['sites'][$h] ?? [];
    if ($hist && !end($hist)[1]) {
      $down[] = $h;
    }
  }
  $d['queue'] = array_values(array_intersect($d['queue'], $down));   // пока ждал очереди — поднялся
  $massive = count($s['monitor']['sites']) >= 3 && count($down) * 2 > count($s['monitor']['sites']);
  $changed = false;
  foreach ($down as $h) {
    [$n, $was] = autoStreak($mon['sites'][$h]);
    if ($n < $a['fails'] || !$was || in_array($h, $d['queue'], true) || time() - ($d['sites'][$h]['last'] ?? 0) < $a['pause'] * 3600) {
      continue;
    }
    $skip = $massive ? 'не открывается больше половины сайтов мониторинга — похоже на обрыв связи, а не на блокировку'
      : (findPid() === null ? 'nfqws2 не запущен — подбирать стратегию бессмысленно' : null);
    if ($skip !== null) {
      // связь или сервис вернутся — попробуем на следующем проходе; в журнал пишем один раз
      if (($d['log'][0]['host'] ?? '') !== $h || ($d['log'][0]['kind'] ?? '') !== 'skip') {
        autoLog($d, $h, 'skip', 'Пропущен: ' . $skip . '.');
        $changed = true;
      }
      continue;
    }
    $pk = podkopRoute($h);
    if ($pk && !empty($pk['proxy'])) {
      $d['sites'][$h]['last'] = time();
      autoLog($d, $h, 'skip', 'Пропущен: сайт идёт через podkop, стратегии nfqws2 на него не действуют.');
      $changed = true;
      continue;
    }
    $d['queue'][] = $h;
    $changed = true;
  }
  $p = pendingGet();
  if ($d['queue'] && !testRunning() && !diagBusy() && !($p && in_array($p['state'], ['checking', 'waiting'], true))) {
    $h = array_shift($d['queue']);
    $d['sites'][$h]['last'] = time();
    autoLog($d, $h, 'start', 'Перестал открываться — запущен подбор стратегии.');
    autoSave($d);
    testLaunch(['type' => 'pick', 'host' => $h, 'proto' => 'https', 'sets' => ['config', 'std', 'hist', 'other'], 'repeats' => 3, 'refine' => false, 'auto' => true]);
    return;
  }
  if ($changed) {
    autoSave($d);
  }
}

// Конец автоподбора: записать итог, предложить или применить найденное, сообщить в Telegram
function autoFinish(): void
{
  $job = json_decode((string)@file_get_contents(TEST_DIR . '/job.json'), true);
  $st = testStatus();
  if (empty($job['auto']) || ($st['host'] ?? '') !== $job['host'] || in_array($st['state'] ?? '', ['running', 'starting'], true)) {
    return;
  }
  $h = $job['host'];
  $d = autoData();
  $msg = null;
  $full = array_values(array_filter($st['results'] ?? [], fn($r) => $r['ok'] > 0 && $r['ok'] === ($st['repeats'] ?? 3)));
  if ($st['state'] === 'error') {
    autoLog($d, $h, 'none', 'Подбор не запущен: ' . ($st['error'] ?? 'ошибка'));
    $msg = "🔎 $h: подбор не поможет. " . ($st['error'] ?? '');
  } elseif ($st['state'] === 'stopped') {
    autoLog($d, $h, 'none', 'Подбор остановлен вручную.');
  } elseif (!empty($st['baseline']['ok'])) {
    autoLog($d, $h, 'none', 'Без обхода сайт открывается — блокировки нет, мешает скорее нынешняя стратегия. Посмотрите диагноз сайта.');
    $msg = "🔎 $h: без обхода открывается — похоже, мешает нынешняя стратегия.";
  } elseif (!$full) {
    autoLog($d, $h, 'none', 'Ни одна стратегия не помогла (' . count($st['results'] ?? []) . ' проверено).');
    $msg = "🔎 $h: рабочая стратегия не нашлась.";
  } else {
    $best = $full[0];
    $d['offers'][$h] = ['ts' => time(), 'name' => $best['name'], 'steps' => $best['steps'], 'from' => $best['from']];
    autoLog($d, $h, 'found', 'Найдена рабочая стратегия: ' . $best['name'] . '.');
    autoSave($d);
    if (uiSettings()['auto']['apply']) {
      $r = autoApply($h, $best['steps'], 'автоподбор');
      $d = autoData();
      $msg = $r['ok'] ? "🔧 $h: найдена и применена стратегия «{$best['name']}» — отдельный профиль только для этого сайта."
        : "🔎 $h: найдена стратегия «{$best['name']}», но применить не удалось — {$r['text']}";
    } else {
      $msg = "🔎 $h: найдена рабочая стратегия «{$best['name']}». Применить можно в интерфейсе: «Автоподбор».";
    }
  }
  autoSave($d);
  if ($msg !== null) {
    notifyTelegram("nfqws2 на роутере:\n" . $msg);
  }
}

// Отдельный профиль «только для этого сайта» в начале своих профилей (если такой уже есть — меняем его стратегию),
// перезапуск и проверка: сайт должен открыться, остальные сайты мониторинга — не сломаться. Иначе — откат.
function autoApply(string $host, array $steps, string $who): array
{
  $d = autoData();
  $done = function (bool $ok, string $kind, string $text) use (&$d, $host): array {
    autoLog($d, $host, $kind, $text);
    if ($ok) {
      unset($d['offers'][$host]);
    }
    autoSave($d);
    return ['ok' => $ok, 'text' => $text];
  };
  $p = pendingGet();
  if ($p && in_array($p['state'], ['checking', 'waiting'], true)) {
    return $done(false, 'fail', 'Не применено: идёт перезапуск с проверкой — дождитесь его конца.');
  }
  $old = (string)file_get_contents(CONF_FILE);
  $custom = confRawVars($old)['NFQWS_ARGS_CUSTOM'] ?? '';
  if (preg_match('/["`$\\\\]/', $custom)) {
    return $done(false, 'fail', 'Не применено: в «своих профилях» есть подстановки оболочки — такой конфиг правится только вручную.');
  }
  $part = array_merge(['--filter-tcp=443', '--filter-l7=tls', "--hostlist-domains=$host", '--payload=tls_client_hello'], $steps);
  $parts = array_values(array_filter(splitNew(tokens($custom))));
  $found = false;
  foreach ($parts as $i => $pt) {
    if (in_array("--hostlist-domains=$host", $pt, true) && in_array('--filter-tcp=443', $pt, true)) {
      $parts[$i] = $part;
      $found = true;
      break;
    }
  }
  if (!$found) {
    array_unshift($parts, $part);
  }
  $new = confSetVar($old, 'NFQWS_ARGS_CUSTOM', implode("\n--new\n", array_map(fn($pt) => implode("\n", $pt), $parts)));
  $lint = lintConf(confRawVars($new), $new);
  if (!$lint['dry_run'] || !$lint['dry_run']['ok']) {
    return $done(false, 'fail', 'Не применено: nfqws2 не принимает такой конфиг — ' . ($lint['dry_run']['message'] ?? 'проверка не прошла'));
  }
  $mon = monitorData();
  $others = [];
  foreach (uiSettings()['monitor']['sites'] as $h) {
    $hist = $mon['sites'][$h] ?? [];
    if ($h !== $host && $hist && end($hist)[1]) {
      $others[] = $h;
    }
  }
  set_time_limit(300);
  if (!writeWithBackup(CONF_FILE, $new, "$who: профиль только для $host")) {
    return $done(false, 'fail', 'Не применено: не удалось записать конфиг.');
  }
  exec(INIT_SCRIPT . ' restart 2>&1');
  sleep(5);
  $why = null;
  if (findPid() === null) {
    $why = 'nfqws2 не запустился';
  } else {
    $twice = function (string $h): bool {
      return probe($h)['ok'] || (sleep(2) === 0 && probe($h)['ok']);
    };
    if (!$twice($host)) {
      $why = "$host так и не открылся";
    } else {
      foreach ($others as $h) {
        if (!$twice($h)) {
          $why = "перестал открываться $h";
          break;
        }
      }
    }
  }
  if ($why !== null) {
    writeWithBackup(CONF_FILE, $old, "откат ($who, $host): $why");
    // после отката сервис обязан работать: ждём запуска, при неудаче пробуем ещё раз
    $up = false;
    for ($try = 0; $try < 2 && !$up; $try++) {
      exec(INIT_SCRIPT . ' restart 2>&1');
      for ($i = 0; $i < 10 && !($up = findPid() !== null); $i++) {
        sleep(1);
      }
    }
    return $done(false, 'rolled', "Применено и откачено: $why. Конфиг возвращён как был"
      . ($up ? ', nfqws2 работает.' : ', но nfqws2 после отката не запустился — проверьте сервис.'));
  }
  // отметка в истории подборов и свежая запись в мониторинге
  $items = picksLoad();
  foreach ($items as &$e) {
    if ($e['host'] === $host) {
      $e['applied'] = ['ts' => time(), 'name' => strategyName($steps), 'steps' => $steps, 'target' => "отдельный профиль для $host ($who)"];
      picksSave($items);
      break;
    }
  }
  unset($e);
  monitorRun(true, [$host]);
  return $done(true, 'applied', ($found ? 'Стратегия профиля для этого сайта заменена' : 'Создан отдельный профиль только для этого сайта') . ': ' . strategyName($steps) . '. Сайт открывается, остальные не пострадали.');
}

// ----- уточнение чисел: вторая фаза подбора -----
// Берём стратегию с фейком, которая открыла сайт не каждый раз (или не открыла вовсе), и перебираем у её
// фейка по одной оси за раз: чем портить, сколько раз слать, какое имя подставлять.

const FAKE_FUNCS = ['fake', 'fakedsplit', 'fakeddisorder', 'hostfakesplit'];
const FOOL_KEYS = ['tcp_ts', 'tcp_md5', 'badsum', 'tcp_seq', 'tcp_ack', 'ip_ttl', 'ip_autottl'];
const REFINE_FOOL = [
  ['tcp_ts', ['tcp_ts' => '-600000']], ['tcp_md5', ['tcp_md5' => true]], ['badsum', ['badsum' => true]],
  ['tcp_seq', ['tcp_seq' => '-10000']], ['autottl', ['ip_autottl' => '-2,3-20']], ['tcp_ts + tcp_md5', ['tcp_ts' => '-600000', 'tcp_md5' => true]],
];
const REFINE_REPEATS = [1, 2, 4, 6, 8, 12, 16, 20];
const REFINE_NAMES = ['ya.ru', 'www.google.com', ''];   // пустое — случайное имя
const REFINE_LIMIT = 300;   // секунд на всю фазу

// «--lua-desync=fake:blob=x:repeats=6:tcp_md5» → функция и параметры по порядку
function stepParse(string $tok): array
{
  $parts = explode(':', substr($tok, 13));
  $s = ['fn' => array_shift($parts), 'p' => []];
  foreach ($parts as $part) {
    [$k, $v] = array_pad(explode('=', $part, 2), 2, true);
    $s['p'][$k] = $v;
  }
  return $s;
}

function stepBuild(array $s): string
{
  $out = '--lua-desync=' . $s['fn'];
  foreach ($s['p'] as $k => $v) {
    $out .= ':' . $k . ($v === true ? '' : '=' . $v);
  }
  return $out;
}

// Номер шага с фейком, у которого есть что уточнять
function stepFakeIndex(array $steps): ?int
{
  foreach ($steps as $i => $t) {
    if (in_array(stepParse($t)['fn'], FAKE_FUNCS, true)) {
      return $i;
    }
  }
  return null;
}

// Короткое имя стратегии: «fake ×18 tcp_ts + multisplit»
function strategyName(array $steps): string
{
  return implode(' + ', array_map(function ($t) {
    $s = stepParse($t);
    $name = $s['fn'];
    if (isset($s['p']['repeats']) && (int)$s['p']['repeats'] > 1) {
      $name .= ' ×' . (int)$s['p']['repeats'];
    }
    $fool = array_values(array_intersect(array_keys($s['p']), FOOL_KEYS));
    return $name . ($fool && in_array($s['fn'], FAKE_FUNCS, true) ? ' ' . implode(',', array_map(fn($k) => $k === 'ip_autottl' ? 'autottl' : $k, $fool)) : '');
  }, $steps));
}

// Порядок перебора — по тому, как соединение рвётся без обхода: при сбросе чаще помогают фейки,
// при молчании — разрезание. Так первая рабочая стратегия находится быстрее.
function orderCandidates(array $cands, ?string $reason): array
{
  $fakeFirst = in_array($reason, ['соединение сброшено', 'обрыв TLS'], true) ? 0 : ($reason === 'тайм-аут' ? 1 : null);
  if ($fakeFirst === null) {
    return $cands;
  }
  usort($cands, fn($a, $b) => (stepFakeIndex($a['steps']) === null ? 1 - $fakeFirst : $fakeFirst) <=> (stepFakeIndex($b['steps']) === null ? 1 - $fakeFirst : $fakeFirst));
  return $cands;
}

function testRefine(array &$status, callable $try, int $repeats, bool $http, bool $force, array $filter): void
{
  $full = fn($r) => $r['ok'] === $repeats;
  $fakes = array_values(array_filter($status['results'], fn($r) => $r['tries'] > 0 && stepFakeIndex($r['steps']) !== null));
  $working = array_filter($status['results'], $full);
  if (!$fakes || ($working && !$force)) {
    return;
  }
  // Основа для уточнения. Есть рабочая (галочка «полегче») — самая быстрая из рабочих. Иначе — та, что
  // продвинулась дальше всех: открывала сайт хоть раз, затем дошла до загрузки данных, затем до обрыва
  // шифрования; молчание в ответ — хуже всего. Если первая основа ничего не дала, пробуем вторую, с другой функцией.
  $stage = fn($r) => ($r['reason'] ?? '') === REASON_FREEZE ? 2 : (in_array($r['reason'] ?? '', ['обрыв TLS', 'соединение сброшено'], true) ? 1 : 0);
  usort($fakes, fn($a, $b) => [$b['ok'], $stage($b)] <=> [$a['ok'], $stage($a)]);
  $fast = array_values(array_filter($fakes, $full));
  usort($fast, fn($a, $b) => ($a['ms'] ?? PHP_INT_MAX) <=> ($b['ms'] ?? PHP_INT_MAX));
  $bases = [];
  if ($working && $fast) {
    $bases = [$fast[0]];
  } else {
    foreach ($fakes as $r) {
      $fn = stepParse($r['steps'][stepFakeIndex($r['steps'])])['fn'];
      if (!isset($bases[$fn]) && count($bases) < 2) {
        $bases[$fn] = $r;
      }
    }
    $bases = array_values($bases);
  }

  $withFool = function (array $s, array $set): array {
    $s['p'] = array_diff_key($s['p'], array_flip(FOOL_KEYS)) + $set;
    return $s;
  };
  $withRepeats = function (array $s, int $n): array {
    unset($s['p']['repeats']);
    if ($n > 1) {
      $s['p']['repeats'] = (string)$n;
    }
    return $s;
  };
  $withName = function (array $s, string $name): array {
    if ($s['fn'] === 'hostfakesplit') {
      unset($s['p']['host']);
      if ($name !== '') {
        $s['p']['host'] = $name;
      }
    } else {
      $s['p']['tls_mod'] = $name !== '' ? 'rnd,dupsid,sni=' . $name : 'rnd,rndsni,dupsid';
    }
    return $s;
  };

  $status['phase'] = 'refine';
  $deadline = time() + REFINE_LIMIT;
  set_time_limit(REFINE_LIMIT + 120);
  $tried = [];
  foreach ($bases as $base) {
    $steps = $base['steps'];
    $fi = stepFakeIndex($steps);
    $st = stepParse($steps[$fi]);
    $cur = ['ok' => $base['ok'], 'tries' => $base['tries'], 'ms' => $base['ms'], 'reason' => $base['reason'] ?? null];
    $axes = [
      ['fool', 'Чем портить фейк', array_map(fn($x) => [$x[0], fn($s) => $withFool($s, $x[1])], REFINE_FOOL)],
      ['repeats', 'Сколько фейков слать', array_map(fn($n) => [(string)$n, fn($s) => $withRepeats($s, $n)], REFINE_REPEATS)],
    ];
    // имя в фейке есть только у TLS: у hostfakesplit и у fake с заготовкой ClientHello
    if (!$http && ($st['fn'] === 'hostfakesplit' || ($st['fn'] === 'fake' && str_contains((string)($st['p']['blob'] ?? ''), 'tls')))) {
      $axes[] = ['name', 'Имя в фейке', array_map(fn($n) => [$n === '' ? 'случайное' : $n, fn($s) => $withName($s, $n)], REFINE_NAMES)];
    }
    $status['refine'] = ['base' => $base['name'], 'from' => $base['from'], 'axes' => [], 'state' => 'running', 'tried' => $tried];
    $status['total'] += array_sum(array_map(fn($a) => count($a[2]), $axes));
    $improved = false;
    foreach ($axes as $ai => [$id, $title, $variants]) {
      $items = [];
      $mut = [];
      foreach ($variants as [$label, $apply]) {
        $s2 = $apply($st);
        if (stepBuild($s2) === stepBuild($st)) {
          $res = $cur;   // этот набор уже проверен
          $status['done']++;
        } else {
          if (is_file(TEST_DIR . '/stop')) {
            $status['state'] = 'stopped';
            $status['refine']['state'] = 'stopped';
            break 3;
          }
          if (time() >= $deadline) {
            $status['refine']['state'] = 'time';
            break 3;
          }
          $status['current'] = "уточнение · $title: $label";
          testSaveStatus($status);
          $t = $steps;
          $t[$fi] = stepBuild($s2);
          $res = $try($t);
          $status['done']++;
        }
        $mut[] = $s2;
        $items[] = ['label' => $label, 'ok' => $res['ok'], 'tries' => $res['tries'], 'ms' => $res['ms'], 'reason' => $res['reason'] ?? null];
        $status['refine']['axes'][$ai] = ['id' => $id, 'title' => $title, 'items' => $items];
        testSaveStatus($status);
      }
      // лучший вариант оси — где больше удач; при равенстве берём первый по списку (меньше фейков, привычнее имя):
      // разница во времени в десятки миллисекунд — шум, по ней выбирать нельзя
      $best = null;
      foreach ($items as $i => $it) {
        if ($it['ok'] > 0 && ($best === null || $it['ok'] > $items[$best]['ok'])) {
          $best = $i;
        }
      }
      if ($best !== null && ($items[$best]['ok'] > $cur['ok'] || ($items[$best]['ok'] === $cur['ok'] && $full($items[$best])))) {
        $improved = $improved || stepBuild($mut[$best]) !== stepBuild($st);
        $st = $mut[$best];
        $cur = ['ok' => $items[$best]['ok'], 'tries' => $items[$best]['tries'], 'ms' => $items[$best]['ms'], 'reason' => $items[$best]['reason']];
        $status['refine']['axes'][$ai]['items'][$best]['picked'] = true;
      }
    }
    $status['refine']['state'] = 'done';
    if ($improved && $cur['ok'] >= $base['ok'] && $cur['ok'] > 0) {
      $steps[$fi] = stepBuild($st);
      $name = strategyName($steps);
      $status['results'][] = ['name' => $name, 'from' => 'уточнение: ' . $base['name'], 'steps' => $steps, 'profile' => array_merge($filter, $steps), 'refined' => true] + $cur;
      $status['refine']['final'] = $name;
      break;
    }
    $tried[] = $base['name'];
  }
  testSaveStatus($status);
}

// ================= диагноз блокировки =================
// Ступени: имя (DNS) → соединение (TCP) → запрос мимо nfqws2 → «по имени или по адресу» → открытый HTTP →
// запрос через nfqws2. Каждая ступень — отдельный запрос интерфейса: виден ход, нет долгих ответов.
// diagVerdict сводит результаты ступеней в один диагноз.

// Защищённый DNS для сверки: адреса серверов заданы явно, чтобы не зависеть от проверяемого резолвера
const DIAG_DOH = [['Яндекс', 'common.dot.dns.yandex.net', '77.88.8.8'], ['Google', 'dns.google', '8.8.8.8']];
// Заведомо разрешённое имя: с ним проверяем, режут соединение по имени сайта или по адресу
const DIAG_SNI = 'ya.ru';
const DIAG_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130 Safari/537.36';
// Признаки страницы-заглушки провайдера: в адресе перенаправления и в тексте короткой страницы
const DIAG_REDIRECT_MARKS = ['warning.rt.ru', 'lawfilter', 'zapret-info', 'rkn.gov.ru', 'eais.rkn', 'blocked', 'blackhole', 'access-denied'];
const DIAG_PAGE_MARKS = ['доступ к ресурсу ограничен', 'доступ ограничен', 'ресурс заблокирован', 'единый реестр', 'eais.rkn.gov.ru', 'rkn.gov.ru', 'warning.rt.ru', 'по решению суда', 'роскомнадзор'];

// Запрос A-записи в двоичном формате DNS (для DoH по RFC 8484)
function dnsWireQuery(string $host): string
{
  $q = "\x00\x00\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00";
  foreach (explode('.', $host) as $label) {
    $q .= chr(strlen($label)) . $label;
  }
  return $q . "\x00\x00\x01\x00\x01";
}

// Ответ DNS → код ответа и адреса IPv4
function dnsWireParse(string $r): ?array
{
  $n = strlen($r);
  if ($n < 12) {
    return null;
  }
  $h = unpack('nid/nflags/nqd/nan', $r);
  $pos = 12;
  $skipName = function () use ($r, $n, &$pos): void {
    while ($pos < $n) {
      $len = ord($r[$pos]);
      if ($len === 0) {
        $pos++;
        return;
      }
      if (($len & 0xC0) === 0xC0) {
        $pos += 2;
        return;
      }
      $pos += $len + 1;
    }
  };
  for ($i = 0; $i < $h['qd']; $i++) {
    $skipName();
    $pos += 4;
  }
  $ips = [];
  for ($i = 0; $i < $h['an'] && $pos + 10 <= $n; $i++) {
    $skipName();
    if ($pos + 10 > $n) {
      break;
    }
    $a = unpack('ntype/nclass/Nttl/nlen', substr($r, $pos, 10));
    $pos += 10;
    if ($a['type'] === 1 && $a['len'] === 4 && $pos + 4 <= $n) {
      $ips[] = inet_ntop(substr($r, $pos, 4));
    }
    $pos += $a['len'];
  }
  return ['rcode' => $h['flags'] & 15, 'ips' => $ips];
}

function dohResolve(string $server, string $ip, string $host): array
{
  $ch = curl_init("https://$server/dns-query?dns=" . rtrim(strtr(base64_encode(dnsWireQuery($host)), '+/', '-_'), '='));
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_NOSIGNAL => 1,
    CURLOPT_RESOLVE => ["$server:443:$ip"],
    CURLOPT_HTTPHEADER => ['accept: application/dns-message'],
  ]);
  $t = microtime(true);
  $body = curl_exec($ch);
  $err = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $ms = (int)round((microtime(true) - $t) * 1000);
  $ans = is_string($body) && $code === 200 ? dnsWireParse($body) : null;
  if (!$ans) {
    return ['ok' => false, 'ips' => [], 'ms' => $ms, 'error' => $err ?: "HTTP $code"];
  }
  return ['ok' => true, 'ips' => $ans['ips'], 'rcode' => $ans['rcode'], 'ms' => $ms];
}

function isIp4(string $s): bool
{
  $b = @inet_pton($s);
  return $b !== false && strlen($b) === 4;
}

// Адрес, по которому сайт в интернете жить не может: такой ответ DNS — заглушка
function ipReserved(string $ip): bool
{
  $b = inet_pton($ip);
  foreach (['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.168.0.0/16', '224.0.0.0/3'] as $net) {
    if (ipInEntry($b, $net)) {
      return true;
    }
  }
  return false;
}

// Ступень 1: ответ резолвера роутера против защищённого DNS
function diagDns(string $host): array
{
  $system = array_values(array_filter(gethostbynamel($host) ?: [], 'isIp4'));
  $doh = [];
  $ref = [];
  $answered = 0;
  foreach (DIAG_DOH as [$name, $server, $ip]) {
    $r = dohResolve($server, $ip, $host);
    $doh[] = ['name' => $name] + $r;
    if ($r['ok']) {
      $answered++;
      $ref = array_merge($ref, $r['ips']);
    }
  }
  $ref = array_values(array_unique($ref));
  $podkop = is_file('/etc/init.d/podkop') && (bool)array_filter($system, fn($ip) => ipInEntry(inet_pton($ip), '198.18.0.0/15'));
  if (!$ref) {
    // защищённый DNS адреса не дал: либо имени нет, либо сверить не с чем (нет связи с DoH, локальное имя)
    $status = $system ? 'unverified' : 'nxdomain';
  } elseif (!$system) {
    $status = 'no_system';
  } elseif ($podkop) {
    $status = 'podkop';
  } elseif (array_intersect($system, $ref)) {
    $status = 'ok';
  } elseif (!array_filter($system, fn($ip) => !ipReserved($ip))) {
    $status = 'fake';
  } else {
    $status = 'differs';   // у CDN ответы разным резолверам отличаются — решит запрос к сайту
  }
  $useRef = in_array($status, ['no_system', 'podkop', 'fake'], true);
  return ['status' => $status, 'system' => $system, 'ref' => $ref, 'doh' => $doh, 'doh_answered' => $answered, 'podkop' => $podkop,
    'ips' => array_slice($useRef ? $ref : $system, 0, 3), 'alt' => $status === 'differs' ? array_slice($ref, 0, 2) : []];
}

// Ступень 2: устанавливается ли соединение с адресами сайта
function diagTcp(array $ips, int $port = 443): array
{
  $out = [];
  foreach ($ips as $ip) {
    $t = microtime(true);
    $f = @fsockopen($ip, $port, $errno, $errstr, 4);
    $ms = (int)round((microtime(true) - $t) * 1000);
    if ($f) {
      fclose($f);
    }
    $out[] = ['ip' => $ip, 'ok' => (bool)$f, 'ms' => $ms, 'error' => $f ? null : ($errno === 110 || $ms >= 3900 ? 'нет ответа' : ($errno === 111 ? 'соединение отклонено' : ($errstr ?: "ошибка $errno")))];
  }
  return $out;
}

// Запросы «мимо nfqws2»: правила подбора пропускают проверочные соединения роутера мимо основной очереди.
// Правила общие с подбором стратегии, поэтому одновременно с ним диагноз не работает.
function diagBypass(callable $fn)
{
  if (testRunning()) {
    fail('Сейчас идёт подбор стратегии — диагноз использует те же проверочные правила. Дождитесь окончания подбора.');
  }
  @mkdir(TEST_DIR, 0777, true);
  $lock = fopen(TEST_DIR . '/diag.lock', 'c');
  flock($lock, LOCK_EX);
  $iface = explode(' ', trim(confValues()['ISP_INTERFACE']))[0] ?: 'eth1';
  testRules('bypass', $iface);
  try {
    return $fn();
  } finally {
    testRules('off', $iface);
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}

// Один запрос curl с проверочного порта. Возвращает код выхода, код HTTP, времена, текст ошибки и её вид.
function diagCurl(string $args, int $max): array
{
  [$lo, $hi] = array_map('intval', explode(':', TEST_PORTS));
  $w = '%{http_code}|%{time_connect}|%{time_appconnect}|%{time_total}|%{size_download}|%{redirect_url}|%{errormsg}';
  $out = [];
  exec('curl -4 -sk --local-port ' . mt_rand($lo, $hi - 50) . '-' . $hi . ' --connect-timeout 5 -m ' . $max . ' -A ' . escapeshellarg(DIAG_UA)
    . ' -w ' . escapeshellarg($w) . ' ' . $args . ' 2>/dev/null', $out, $rc);
  $f = array_pad(explode('|', (string)end($out), 7), 7, '');
  $code = (int)$f[0];
  $tls = (float)$f[2] > 0;
  $msg = strtolower($f[6]);
  if ($rc === 0 && $code > 0) {
    $kind = 'ok';
  } elseif ($rc === 6) {
    $kind = 'dns';
  } elseif ($rc === 7) {
    $kind = 'connect';
  } elseif ($rc === 28) {
    $kind = $code > 0 ? 'freeze' : ($tls ? 'stall' : 'timeout');
  } elseif ($rc === 35) {
    $kind = str_contains($msg, 'alert') ? 'alert' : (str_contains($msg, 'reset') ? 'reset' : 'tls');
  } elseif ($rc === 56) {
    $kind = 'reset';
  } elseif ($rc === 52) {
    $kind = 'empty';
  } else {
    $kind = 'other';
  }
  return ['ok' => $kind === 'ok', 'kind' => $kind, 'rc' => $rc, 'code' => $code, 'tls' => $tls, 'ms' => (int)round((float)$f[3] * 1000),
    'size' => (int)$f[4], 'redirect' => $f[5], 'error' => $f[6]];
}

// Похоже ли на заглушку провайдера: код 451, перенаправление на страницу блокировки или её текст
function diagIspPage(string $host, array $r, string $bodyFile): bool
{
  if ($r['code'] === 451) {
    return true;
  }
  $to = strtolower((string)parse_url($r['redirect'], PHP_URL_HOST));
  if ($to !== '' && $to !== $host && !str_ends_with($to, '.' . $host) && !str_ends_with($host, '.' . $to)) {
    foreach (DIAG_REDIRECT_MARKS as $m) {
      if (str_contains(strtolower($r['redirect']), $m)) {
        return true;
      }
    }
  }
  // настоящие сайты длиннее; на длинной странице такие слова встречаются и в обычном тексте
  if ($r['ok'] && $r['size'] > 0 && $r['size'] < 8192) {
    $body = (string)@file_get_contents($bodyFile, false, null, 0, 8192);
    // в сборке PHP для роутера нет ни iconv, ни mbstring
    if (function_exists('iconv') && preg_match('/charset=["\']?windows-1251/i', $body)) {
      $body = (string)@iconv('windows-1251', 'utf-8//IGNORE', $body);
    }
    static $lower = null;
    $lower ??= array_combine(preg_split('//u', 'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ', -1, PREG_SPLIT_NO_EMPTY), preg_split('//u', 'абвгдеёжзийклмнопрстуфхцчшщъыьэюя', -1, PREG_SPLIT_NO_EMPTY));
    $body = strtolower(strtr($body, $lower));
    foreach (DIAG_PAGE_MARKS as $m) {
      if (str_contains($body, $m)) {
        return true;
      }
    }
  }
  return false;
}

// Ступень 3: запрос сайта мимо nfqws2, с привязкой к адресу. Маленькая страница дополнительно проверяется
// на заморозку объёмом, как в проверке сайта.
function diagDirect(string $host, string $ip): array
{
  $body = TEST_DIR . '/diag.body';
  @unlink($body);
  $pin = '--resolve ' . escapeshellarg("$host:443:$ip");
  $r = diagCurl($pin . ' -r 0-' . (VOLUME_BYTES - 1) . ' -o ' . escapeshellarg($body) . ' ' . escapeshellarg("https://$host/"), 8);
  $r['ip'] = $ip;
  $r['isp_page'] = diagIspPage($host, $r, $body);
  @unlink($body);
  if ($r['ok'] && !$r['isp_page'] && $r['size'] < VOLUME_SMALL) {
    $blob = TEST_DIR . '/volume.bin';
    if (!is_file($blob) || filesize($blob) !== VOLUME_BYTES) {
      file_put_contents($blob, random_bytes(VOLUME_BYTES));
    }
    $v = diagCurl($pin . ' -H "Expect:" -H "Content-Type: application/octet-stream" --data-binary @' . escapeshellarg($blob)
      . ' -o /dev/null ' . escapeshellarg("https://$host/?t=" . mt_rand()), 8);
    if ($v['rc'] === 28 && $v['tls']) {
      $r['ok'] = false;
      $r['kind'] = 'freeze';
    }
  }
  return $r;
}

// Ступень 4: по имени режут или по адресу. Две пробы:
//  — адрес сайта с чужим (разрешённым) именем: если сервер ответил хоть чем-то, путь до адреса открыт;
//  — чужой адрес с именем сайта: если соединение рвётся и там, режут само имя.
function diagSni(string $host, string $ip): array
{
  $res = ['foreign_name' => null, 'foreign_addr' => null, 'by_name' => false];
  if ($host === DIAG_SNI) {
    return $res;
  }
  $a = diagCurl('--connect-to ' . escapeshellarg(DIAG_SNI . ":443:$ip:443") . ' -o /dev/null ' . escapeshellarg('https://' . DIAG_SNI . '/'), 6);
  // TLS-alert прислал сам сервер — значит, наш запрос до него дошёл
  $reached = $a['ok'] || $a['tls'] || $a['kind'] === 'alert';
  $res['foreign_name'] = ['reached' => $reached, 'kind' => $a['kind']];
  $other = array_values(array_filter(gethostbynamel(DIAG_SNI) ?: [], 'isIp4'))[0] ?? null;
  if ($other) {
    $b = diagCurl('--connect-to ' . escapeshellarg("$host:443:$other:443") . ' -o /dev/null ' . escapeshellarg("https://$host/"), 6);
    $blocked = in_array($b['kind'], ['reset', 'timeout', 'tls', 'empty'], true);
    $res['foreign_addr'] = ['blocked' => $blocked, 'kind' => $b['kind']];
  }
  $res['by_name'] = $reached || !empty($res['foreign_addr']['blocked']);
  return $res;
}

// Ступень 5: открытый HTTP на 80-м порту — заглушка провайдера и «закрыт весь адрес или только HTTPS»
function diagHttp(string $host, string $ip): array
{
  $body = TEST_DIR . '/diag.body';
  @unlink($body);
  $r = diagCurl('--resolve ' . escapeshellarg("$host:80:$ip") . ' -r 0-8191 -o ' . escapeshellarg($body) . ' ' . escapeshellarg("http://$host/"), 6);
  $r['isp_page'] = diagIspPage($host, $r, $body);
  @unlink($body);
  return $r;
}

// Ступень 6: как сайт открывается сейчас — через основной nfqws2 и с обычным DNS роутера
function diagVia(string $host): array
{
  $r = probe($host);
  $ips = gethostbynamel($host) ?: [];
  $route = matchRoute(currentProfiles(), $host, $ips, 'tcp', 443, 'tls', new ListCache());
  return $r + ['profile' => $route['profile'] ?? null, 'running' => findPid() !== null];
}

// Сводит результаты ступеней в диагноз. pick — имеет ли смысл подбирать стратегию.
function diagVerdict(array $r): array
{
  $dns = $r['dns'] ?? [];
  $direct = $r['direct'] ?? null;
  $via = $r['via'] ?? null;
  $tcpOk = (bool)array_filter($r['tcp'] ?? [], fn($x) => !empty($x['ok']));
  $v = fn(string $code, bool $pick = false) => ['code' => $code, 'pick' => $pick, 'profile' => $via['profile'] ?? null];
  $status = $dns['status'] ?? '';
  if ($status === 'nxdomain') {
    return $v('nxdomain');
  }
  if ($status === 'podkop') {
    return $v(!empty($via['ok']) ? 'podkop_ok' : 'podkop_fail');
  }
  // роутер получил не тот адрес: по своему не открывается, по адресу из защищённого DNS — открывается
  if (in_array($status, ['fake', 'no_system'], true) || ($status === 'differs' && !empty($r['alt_ok']))) {
    return $v($status === 'no_system' ? 'dns_noanswer' : 'dns_fake');
  }
  if ($direct && !empty($direct['isp_page']) || !empty($r['http']['isp_page'])) {
    return $v(!empty($via['ok']) ? 'fixed' : 'isp_page', empty($via['ok']));
  }
  if ($direct && !empty($direct['ok'])) {
    return $v(!$via || !empty($via['ok']) ? 'ok' : 'broken_by_nfqws');
  }
  if (!empty($via['ok'])) {
    return $v('fixed');
  }
  if (($direct['kind'] ?? '') === 'freeze') {
    return $v('freeze', true);   // подбор с уточнением иногда снимает и заморозку
  }
  if (!$tcpOk) {
    return $v(!empty($r['http']['ok']) ? 'port_block' : 'ip_block');
  }
  if (!empty($r['sni']['by_name'])) {
    return $v('sni_block', true);
  }
  if (($direct['kind'] ?? '') === 'alert') {
    return $v('site_error');
  }
  if (in_array($direct['kind'] ?? '', ['reset', 'timeout', 'tls', 'stall', 'empty'], true)) {
    return $v('tls_block', true);
  }
  return $v('unknown', true);
}

// ================= обновление интерфейса =================
// Проверка — по последнему релизу на GitHub, не чаще раза в 12 часов (кнопка «Проверить» — сразу).
// Само обновление делает nfqws-ui-setup update в отдельном сеансе: при установке пакета lighttpd
// перезапускается, и запрос, который его запустил, обрывается.

const REPO_URL = 'https://github.com/zemidala/nfqws2-ui';
define('UPDATE_FILE', UI_CONF_DIR . '/update.json');
const UPDATE_LOG = '/tmp/nfqws-ui-update.log';
define('SETUP_BIN', ROOT ? '/opt/sbin/nfqws-ui-setup' : '/usr/sbin/nfqws-ui-setup');

function updateInfo(): array
{
  $u = json_decode((string)@file_get_contents(UPDATE_FILE), true) ?: [];
  $latest = $u['latest'] ?? null;
  return ['current' => UI_VERSION, 'latest' => $latest, 'available' => $latest !== null && version_compare($latest, UI_VERSION, '>'),
    'notes' => $u['notes'] ?? '', 'url' => $u['url'] ?? REPO_URL . '/releases', 'checked' => $u['checked'] ?? 0, 'error' => $u['error'] ?? null,
    'can_update' => is_executable(SETUP_BIN)];
}

function updateCheck(bool $force): array
{
  $u = updateInfo();
  if (!$force && time() - $u['checked'] < 12 * 3600) {
    return $u;
  }
  $ch = curl_init('https://api.github.com/repos/zemidala/nfqws2-ui/releases/latest');
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'User-Agent: nfqws2-ui/' . UI_VERSION]]);
  $res = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  $j = json_decode((string)$res, true);
  $save = ['checked' => time()];
  if ($code === 200 && preg_match('/^v?(\d+\.\d+\.\d+)$/', (string)($j['tag_name'] ?? ''), $m)) {
    $save += ['latest' => $m[1], 'notes' => preg_match('/^.{0,4000}/su', (string)($j['body'] ?? ''), $nm) ? $nm[0] : '', 'url' => (string)($j['html_url'] ?? REPO_URL . '/releases')];
  } else {
    $old = json_decode((string)@file_get_contents(UPDATE_FILE), true) ?: [];
    $save += array_intersect_key($old, array_flip(['latest', 'notes', 'url']))
      + ['error' => $err ?: ($code === 404 ? 'релизов не найдено' : "GitHub ответил $code")];
  }
  @file_put_contents(UPDATE_FILE, json_encode($save, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  return updateInfo();
}

function updateRunning(): bool
{
  // [p] — чтобы не найти саму оболочку, в строке которой есть этот шаблон
  exec('pgrep -f "nfqws-ui-setu[p] update" 2>/dev/null', $out);
  return (bool)$out;
}

function updateStart(string $version): void
{
  if (!is_executable(SETUP_BIN)) {
    fail('Нет ' . SETUP_BIN . ' — обновите вручную, см. README');
  }
  if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    fail('Неверная версия');
  }
  if (updateRunning()) {
    fail('Обновление уже идёт');
  }
  file_put_contents(UPDATE_LOG, '');
  // procd (OpenWrt) держит lighttpd и всё, что он запустил, в cgroup /services/lighttpd и при перезапуске
  // может убить её целиком — посреди opkg install. Поэтому сначала уходим в корневую cgroup.
  $cmd = 'echo $$ > /sys/fs/cgroup/cgroup.procs 2>/dev/null; '
    . SETUP_BIN . ' update v' . $version . ' >>' . UPDATE_LOG . ' 2>&1; echo "[exit $?]" >>' . UPDATE_LOG;
  exec('(setsid env -i PATH=' . JOB_PATH . ' sh -c ' . escapeshellarg($cmd) . ' >/dev/null 2>&1 &)');
}

// ================= состояние =================

function iptablesCounters(): array
{
  $rows = [];
  foreach (['nfqws_post' => 'out', 'nfqws_pre' => 'in'] as $chain => $dir) {
    $out = [];
    exec('iptables -w -t mangle -L ' . $chain . ' -v -n -x 2>/dev/null', $out);
    foreach (array_slice($out, 2) as $line) {
      $c = preg_split('/\s+/', trim($line));
      if (count($c) < 4 || $c[2] !== 'NFQUEUE') {
        continue;
      }
      $rows[] = ['dir' => $dir, 'proto' => ['6' => 'TCP', '17' => 'UDP', 'tcp' => 'TCP', 'udp' => 'UDP'][$c[3]] ?? $c[3],
        'what' => match (true) {
          str_contains($line, 'flags:0x01/0x01') => 'FIN',
          str_contains($line, 'flags:0x04/0x04') => 'RST',
          str_contains($line, 'flags:0x12/0x12') => 'SYN+ACK',
          default => 'data',
        },
        'pkts' => (int)$c[0], 'bytes' => (int)$c[1]];
    }
  }
  return $rows;
}

function restartNeeded(?array $proc): bool
{
  if (!$proc || !$proc['started']) {
    return false;
  }
  foreach (array_merge([CONF_FILE], array_values(luaFiles())) as $f) {
    // btime точен примерно до секунды
    if (@filemtime($f) > $proc['started'] + 2) {
      return true;
    }
  }
  return false;
}

function state(): array
{
  historyScan('auto');
  $pid = findPid();
  $proc = $pid ? processInfo($pid) : null;
  $expected = expectedProfiles(confValues());
  $globals = [];
  $profiles = loadProfiles($pid, $expected, $globals);
  return [
    'now' => time(),
    'running' => $pid !== null,
    'stop' => $pid === null ? stopReason() : null,
    'rivals' => rivals(),
    'process' => $proc,
    'version' => packageVersion('nfqws2-keenetic'),
    'globals' => $globals,
    'in_sync' => array_column($expected, 'tokens') === array_column($profiles, 'args'),
    'restart_needed' => restartNeeded($proc),
    'conf_mtime' => @filemtime(CONF_FILE),
    'profiles' => $profiles,
    'lists' => listsInventory($profiles),
    'iptables' => iptablesCounters(),
    'lint' => lintCurrent(),
    'conf_tokens' => array_map('tokens', array_intersect_key(confRawVars(file_get_contents(CONF_FILE)), array_flip(ARG_VARS))),
    // Профили по конфигу (для редактора) — могут отличаться от запущенных до перезапуска
    'conf_profiles' => $pid === null ? $profiles : loadProfiles(null, $expected, $globals),
    'subs' => uiSettings()['subs'],
    'ui' => (function () {
      $w = uiWeb();
      return ['version' => UI_VERSION, 'conf_file' => CONF_FILE, 'https_port' => $w['https_port'], 'legacy_port' => $w['legacy_port'], 'auth' => authEnabled(),
        'provider' => uiSettings()['provider'], 'platform' => ROOT ? 'Keenetic' : 'OpenWrt', 'repo' => REPO_URL, 'update' => updateInfo() + ['running' => updateRunning()]];
    })(),
    'undo' => (function () {
      $u = lastUndoable();
      return $u ? ['ts' => $u['ts'], 'files' => $u['files'], 'note' => $u['note']] : null;
    })(),
    'pending' => (function () {
      $p = pendingGet();
      if (!$p) {
        return null;
      }
      $p['files'] = array_map('basename', array_keys($p['versions'] ?? []));
      unset($p['versions'], $p['before']);
      return $p;
    })(),
    'rollback' => (function () {
      $T = confirmPoint();
      return ['since' => $T, 'files' => array_values(array_unique(array_map('basename', array_keys(versionsAt($T)))))];
    })(),
    'monitor' => (function () {
      $cfg = uiSettings()['monitor'];
      $d = monitorData();
      $sites = [];
      foreach ($cfg['sites'] as $h) {
        $hist = $d['sites'][$h] ?? [];
        $sites[] = ['host' => $h, 'last' => $hist ? end($hist) : null, 'recent' => array_map(fn($x) => $x[1], array_slice($hist, -24))];
      }
      return ['enabled' => $cfg['enabled'], 'interval' => $cfg['interval'], 'last' => $d['last'] ?? 0, 'sites' => $sites];
    })(),
    'auto' => (function () {
      $d = autoData();
      $offers = [];
      foreach ($d['offers'] as $h => $o) {
        $offers[] = ['host' => (string)$h, 'ts' => $o['ts'], 'name' => $o['name']];
      }
      return ['enabled' => uiSettings()['auto']['enabled'], 'offers' => $offers, 'queue' => $d['queue']];
    })(),
    'snap' => (function () {
      $idx = snapIndex();
      return ['count' => count($idx), 'last' => $idx ? end($idx)['ts'] : null,
        'size' => array_sum(array_map(fn($e) => $e['size'], $idx))];
    })(),
  ];
}

// Кандидат конфига: текущий файл с подставленными значениями из формы
function candidateFromVars(array $vars): array
{
  $text = file_get_contents(CONF_FILE);
  foreach ($vars as $n => $v) {
    if (!in_array($n, CONF_VARS, true) || !is_string($v)) {
      fail("Неизвестная переменная $n");
    }
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    if ($n === 'NFQWS_EXTRA_ARGS') {
      if (!in_array($v, ['$MODE_LIST', '$MODE_ALL', '$MODE_AUTO'], true)) {
        fail('Недопустимый режим');
      }
    } elseif (preg_match('/["`$\\\\]/', $v)) {
      fail(($n) . ': нельзя использовать символы " ` $ \\');
    }
    $text = confSetVar($text, $n, $v);
  }
  return [$text, array_merge(confRawVars($text), $vars)];
}

function saveConf(string $text, array $raw, bool $force, string $note = 'правка конфига'): void
{
  $lint = lintConf($raw, $text);
  $errors = array_filter($lint['issues'], fn($x) => $x['level'] === 'error');
  // Отказ nfqws2 или ошибка sh — сервис не запустится, такое не сохраняем даже с force
  $fatal = !$lint['dry_run'] || !$lint['dry_run']['ok'];
  if ($errors && (!$force || $fatal)) {
    respond(['error' => 'В конфиге есть ошибки', 'issues' => $lint['issues'], 'fatal' => $fatal, 'dry_run' => $lint['dry_run']], 422);
  }
  if (!writeWithBackup(CONF_FILE, $text, $note)) {
    fail('Не удалось записать конфиг', 500);
  }
  respond(['ok' => true, 'issues' => $lint['issues']]);
}

function currentProfiles(): array
{
  $pid = findPid();
  $globals = [];
  return loadProfiles($pid, expectedProfiles(confValues()), $globals);
}

// ================= вторая очередь: причина остановки, другие обходчики, обмен профилями, отчёт, списки по ASN =================

// Почему nfqws2 не работает — от самой вероятной причины к общей
function stopReason(): array
{
  $log = [];
  if (!ROOT) {
    exec('logread -e nfqws2 2>/dev/null | tail -n 6', $log);
  }
  $auto = null;
  if (!ROOT && is_file(INIT_SCRIPT)) {
    exec(INIT_SCRIPT . ' enabled 2>/dev/null', $o, $rc);
    $auto = $rc === 0;
  }
  $r = ['log' => array_values(array_filter(array_map('trim', $log))), 'autostart' => $auto];
  if (!is_file(NFQWS_BIN)) {
    return $r + ['kind' => 'nobin', 'text' => 'Нет программы nfqws2 (' . NFQWS_BIN . ') — пакет nfqws2-keenetic не установлен или удалён.'];
  }
  if (!is_file(INIT_SCRIPT)) {
    return $r + ['kind' => 'nobin', 'text' => 'Нет скрипта запуска ' . INIT_SCRIPT . ' — пакет nfqws2-keenetic установлен не полностью.'];
  }
  $dry = lintCurrent()['dry_run'] ?? null;
  if ($dry && !$dry['ok']) {
    return $r + ['kind' => 'conf', 'text' => 'nfqws2 не принимает конфиг: ' . $dry['message']];
  }
  if ($auto === false) {
    return $r + ['kind' => 'disabled', 'text' => 'Сервис остановлен, и автозапуск выключен — после перезагрузки роутера он сам не поднимется.'];
  }
  return $r + ['kind' => 'manual', 'text' => 'Конфиг в порядке — сервис остановлен вручную или завершился сам. Попробуйте запустить; если не получится, ниже — последние строки журнала.'];
}

// Другие обходчики, работающие одновременно: они правят те же пакеты
function rivals(): array
{
  $names = ['nfqws' => 'zapret (nfqws)', 'tpws' => 'zapret (tpws)', 'youtubeUnblock' => 'youtubeUnblock', 'b4' => 'b4',
    'ciadpi' => 'byedpi', 'byedpi' => 'byedpi', 'dpitunnel' => 'DPITunnel', 'spoofdpi' => 'SpoofDPI', 'goodbyedpi' => 'GoodbyeDPI'];
  $main = findPid();
  $found = [];
  foreach (glob('/proc/[0-9]*/comm') ?: [] as $f) {
    $c = trim((string)@file_get_contents($f));
    $pid = (int)basename(dirname($f));
    if (isset($names[$c])) {
      $found[$names[$c]] = true;
    } elseif ($c === 'nfqws2' && $pid !== $main && !str_contains((string)@file_get_contents("/proc/$pid/cmdline"), '--qnum=' . TEST_QNUM)) {
      $found['ещё один nfqws2 (zapret2)'] = true;
    }
  }
  return array_keys($found);
}

function ripe(string $call, string $resource = ''): ?array
{
  $ch = curl_init("https://stat.ripe.net/data/$call/data.json?sourceapp=nfqws2-ui" . ($resource !== '' ? '&resource=' . rawurlencode($resource) : ''));
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_NOSIGNAL => 1]);
  $j = json_decode((string)curl_exec($ch), true);
  curl_close($ch);
  return is_array($j['data'] ?? null) ? $j['data'] : null;
}

// Вставленный из чата профиль: оставляем только известные параметры профиля, поправляем пути, проверяем у nfqws2
function profileCheck(array $tokens): array
{
  $opts = helpInfo()['options'];
  $clean = [];
  $rejected = [];
  $fixed = [];
  $missing = [];
  foreach ($tokens as $t) {
    if (!is_string($t) || !preg_match('/^--([a-z0-9-]+)(=[^"`$\\\\\s]*)?$/', $t, $m)) {
      $rejected[] = ['tok' => is_string($t) ? $t : '?', 'why' => 'не параметр nfqws2'];
      continue;
    }
    if (!isset($opts[$m[1]])) {
      $rejected[] = ['tok' => $t, 'why' => 'nfqws2 на этом роутере такого параметра не знает'];
      continue;
    }
    if ($opts[$m[1]]['global'] || $m[1] === 'new') {
      $rejected[] = ['tok' => $t, 'why' => 'общий параметр процесса, в профиль не входит'];
      continue;
    }
    // nfqws2 при проверке не смотрит, есть ли такая lua-функция, — смотрим сами
    if ($m[1] === 'lua-desync' && !isset(luaCatalog()['functions'][explode(':', substr($t, 13))[0]])) {
      $rejected[] = ['tok' => $t, 'why' => 'такой функции нет в lua-скриптах этого роутера (другая версия nfqws2?)'];
      continue;
    }
    if (preg_match('/^--(hostlist|hostlist-exclude|hostlist-auto|ipset|ipset-exclude)=(.+)$/', $t, $f) && !is_file($f[2])) {
      $alt = LISTS_DIR . '/' . basename($f[2]);
      if (is_file($alt)) {
        $fixed[] = ['from' => $f[2], 'to' => $alt];
        $t = "--{$f[1]}=$alt";
      } elseif ($f[1] === 'hostlist-auto') {
        $t = "--{$f[1]}=$alt";   // этот файл nfqws2 создаст сам
      } else {
        $missing[] = $f[2];
      }
    }
    $clean[] = $t;
  }
  $dry = null;
  if ($clean && !$missing) {
    $exp = confValues();
    $exp['NFQWS_ARGS_CUSTOM'] = implode(' ', $clean) . (trim($exp['NFQWS_ARGS_CUSTOM']) !== '' ? ' --new ' . $exp['NFQWS_ARGS_CUSTOM'] : '');
    $d = dryRun($exp);
    $dry = ['ok' => $d['ok'], 'message' => $d['message']];
  }
  return ['tokens' => $clean, 'rejected' => $rejected, 'fixed' => $fixed, 'missing' => $missing, 'dry_run' => $dry,
    'steps' => array_values(array_filter($clean, fn($t) => str_starts_with($t, '--lua-desync=')))];
}

// Отчёт для помощи: текст без секретов — версии, состояние, профили, списки, мониторинг, подборы
function reportText(array $sites, bool $hide, string $build): string
{
  $st = state();
  $s = uiSettings();
  $os = ROOT ? 'Keenetic (Entware)' : 'OpenWrt';
  if (!ROOT && preg_match("/DISTRIB_DESCRIPTION='([^']*)'/", (string)@file_get_contents('/etc/openwrt_release'), $m)) {
    $os = $m[1];
  }
  $L = ['Отчёт nfqws2-ui · ' . ldate('Y-m-d H:i'), '', '== Версии ==',
    'nfqws2: ' . ($st['version'] ?: 'не определена'), 'nfqws2-ui: ' . UI_VERSION . ($build !== '' ? " (сборка $build)" : ''),
    'система: ' . $os . ' · ' . php_uname('m') . ' · ядро ' . php_uname('r'), 'провайдер: ' . ($s['provider'] !== '' ? $s['provider'] : 'не указан'), '', '== Сервис =='];
  if ($st['running']) {
    $L[] = 'работает' . (!empty($st['process']['started']) ? ', запущен ' . ldate('Y-m-d H:i', (int)$st['process']['started']) : '')
      . ($st['restart_needed'] || !$st['in_sync'] ? ' · конфиг изменён после запуска — нужен перезапуск' : '');
  } else {
    $L[] = 'ОСТАНОВЛЕН. ' . $st['stop']['text'];
    foreach ($st['stop']['log'] as $l) {
      $L[] = '  ' . $l;
    }
  }
  $L[] = 'другие обходчики: ' . ($st['rivals'] ? implode(', ', $st['rivals']) : 'не найдены') . (is_file('/etc/init.d/podkop') ? ' · podkop установлен' : '');
  $L[] = '';
  $L[] = '== Замечания к конфигу ==';
  $issues = array_filter($st['lint']['issues'], fn($x) => $x['level'] !== 'info');
  foreach ($issues as $x) {
    $L[] = ($x['level'] === 'error' ? 'ОШИБКА: ' : 'предупреждение: ') . $x['msg'];
  }
  if (!$issues) {
    $L[] = 'нет';
  }
  $exp = confValues();
  $L[] = '';
  $L[] = '== Основное ==';
  foreach (['ISP_INTERFACE', 'TCP_PORTS', 'UDP_PORTS', 'IPV6_ENABLED', 'NFQWS_EXTRA_ARGS'] as $v) {
    $L[] = "$v=" . ($v === 'NFQWS_EXTRA_ARGS' ? (confRawVars((string)file_get_contents(CONF_FILE))[$v] ?? '') : $exp[$v]);
  }
  $L[] = 'параметры запуска: ' . implode(' ', tokens($exp['NFQWS_BASE_ARGS']));
  $L[] = '';
  $L[] = '== Профили (в порядке проверки) ==';
  foreach ($st['conf_profiles'] as $p) {
    $L[] = '#' . $p['index'] . ' [' . ($p['source']['source'] ?? '?') . ']' . (in_array($p['state'] ?? '', ['dead', 'excludes-only'], true) ? ' (не срабатывает)' : '');
    $L[] = '  ' . implode(' ', $p['args'] ?? []);
  }
  $L[] = '';
  $L[] = '== Списки ==';
  foreach ($st['lists'] as $l) {
    $L[] = $l['name'] . ': ' . $l['entries'] . ' записей' . ($l['used'] ? ', профили ' . implode(', ', array_unique(array_map(fn($u) => '#' . $u['profile'], $l['used']))) : ', не используется');
  }
  $L[] = '';
  $L[] = '== Мониторинг ==';
  foreach ($st['monitor']['sites'] as $m) {
    $L[] = $m['host'] . ': ' . (!$m['last'] ? 'не проверялся' : ($m['last'][1] ? 'открывается, ' . $m['last'][2] . ' мс' : 'НЕ открывается — ' . $m['last'][3]));
  }
  $L[] = '';
  $L[] = '== Последние подборы ==';
  foreach (array_slice(picksLoad(), 0, 8) as $e) {
    $L[] = ldate('m-d H:i', $e['ts']) . ' ' . $e['host'] . ': без обхода — ' . (!empty($e['baseline']['ok']) ? 'открывается' : ($e['baseline']['reason'] ?? '?'))
      . '; сработало ' . ($e['ok'] ?? 0) . ' из ' . ($e['total'] ?? 0) . ($e['best'] ?? null ? '; лучшая: ' . $e['best'] : '');
  }
  if ($sites) {
    $L[] = '';
    $L[] = '== Проверка сайтов ==';
    foreach ($sites as $h) {
      $dns = diagDns($h);
      $pr = probe($h);
      $pk = podkopRoute($h);
      $L[] = $h . ': DNS — ' . $dns['status'] . ' (' . count($dns['ips'] ?? []) . ' адр.)'
        . '; через текущий конфиг — ' . ($pr['ok'] ? 'открывается, HTTP ' . $pr['code'] . ', ' . $pr['ms'] . ' мс' : 'НЕ открывается: ' . $pr['reason'])
        . ($pk && !empty($pk['proxy']) ? '; идёт через podkop' : '');
    }
  }
  $text = implode("\n", $L) . "\n";
  if ($hide) {
    // имена сайтов заменяем на «сайт-N»: из мониторинга, подборов, профилей «только для сайта» и запрошенных проверок
    $hosts = array_merge(array_column($st['monitor']['sites'], 'host'), array_column(picksLoad(), 'host'), $sites);
    preg_match_all('/--hostlist-domains=(\S+)/', $text, $m);
    foreach ($m[1] as $v) {
      $hosts = array_merge($hosts, explode(',', $v));
    }
    $hosts = array_values(array_unique(array_filter($hosts)));
    usort($hosts, fn($a, $b) => strlen($b) <=> strlen($a));
    foreach ($hosts as $i => $h) {
      $text = str_replace($h, 'сайт-' . ($i + 1), $text);
    }
  }
  return $text;
}

// ----- список IP по номеру автономной системы (RIPEstat) -----

function asnResolve(string $q): array
{
  $q = trim($q);
  if (preg_match('/^(?:AS)?(\d{1,10})$/i', $q, $m)) {
    return ['asn' => (int)$m[1], 'via' => null];
  }
  $host = cleanHost($q);
  if (!validHost($host)) {
    fail('Введите номер AS (например AS24940) или имя сайта');
  }
  $ip = isIp($host) ? $host : (diagDns($host)['ips'][0] ?? null);
  if (!$ip) {
    fail("Не удалось узнать адрес $host");
  }
  if (isIp4($ip) && ipReserved($ip)) {
    fail("Адрес $host — служебный ($ip): сайт идёт через podkop или DNS отдаёт заглушку. Укажите номер AS.");
  }
  $ni = ripe('network-info', $ip);
  if (empty($ni['asns'][0])) {
    fail("RIPEstat не знает, чьей сети принадлежит $ip");
  }
  return ['asn' => (int)$ni['asns'][0], 'via' => "$host → $ip → {$ni['prefix']}"];
}

function asnFetch(int $asn): array
{
  $ov = ripe('as-overview', "AS$asn");
  $pf = ripe('announced-prefixes', "AS$asn");
  if ($pf === null) {
    fail('RIPEstat не ответил — попробуйте позже');
  }
  $v6 = strtolower(trim(confValues()['IPV6_ENABLED'] ?? '')) === 'true' || (confValues()['IPV6_ENABLED'] ?? '') === '1';
  $v4 = [];
  $six = [];
  foreach ($pf['prefixes'] ?? [] as $p) {
    $x = (string)($p['prefix'] ?? '');
    if (preg_match('#^(\d+\.\d+\.\d+\.\d+)/(\d+)$#', $x, $m) && isIp4($m[1]) && (int)$m[2] <= 32) {
      $start = ip2long($m[1]);
      $v4[] = [$start, $start + (1 << (32 - (int)$m[2])) - 1, $x];
    } elseif ($v6 && preg_match('#^[0-9a-f:]+/\d+$#i', $x)) {
      $six[$x] = true;
    }
  }
  // вложенные префиксы убираем: остаются только самые широкие
  usort($v4, fn($a, $b) => [$a[0], $b[1]] <=> [$b[0], $a[1]]);
  $out = [];
  $end = -1;
  foreach ($v4 as [$s, $e, $x]) {
    if ($e > $end) {
      $out[] = $x;
      $end = $e;
    }
  }
  return ['asn' => $asn, 'holder' => (string)($ov['holder'] ?? ''), 'prefixes' => array_merge($out, array_keys($six)), 'v6' => $v6];
}

function asnWrite(array $a, bool $auto): array
{
  $name = "ipset_as{$a['asn']}.list";
  $path = LISTS_DIR . '/' . $name;
  $text = "# AS{$a['asn']} {$a['holder']} — префиксы по данным RIPEstat, " . ldate('Y-m-d') . "\n" . implode("\n", $a['prefixes']) . "\n";
  if (!writeWithBackup($path, $text, "список по AS{$a['asn']}: " . count($a['prefixes']) . ' префиксов')) {
    fail('Не удалось записать список', 500);
  }
  $s = uiSettings();
  $s['asn'] = array_values(array_filter($s['asn'], fn($x) => $x['asn'] !== $a['asn']));
  $s['asn'][] = ['asn' => $a['asn'], 'holder' => $a['holder'], 'list' => $name, 'auto' => $auto, 'last' => time(), 'count' => count($a['prefixes']), 'error' => null];
  saveUiSettings($s);
  return ['name' => $name, 'count' => count($a['prefixes'])];
}

// Раз в сутки: обновить списки по ASN. Пустой или резко похудевший ответ не принимаем.
function asnRun(): void
{
  $s = uiSettings();
  foreach ($s['asn'] as $i => $x) {
    if (empty($x['auto']) || !is_file(LISTS_DIR . '/' . $x['list'])) {
      continue;
    }
    $pf = ripe('announced-prefixes', "AS{$x['asn']}");
    $err = null;
    if ($pf === null) {
      $err = 'RIPEstat не ответил';
    } else {
      $a = asnFetch((int)$x['asn']);
      if (!$a['prefixes'] || count($a['prefixes']) * 3 < (int)$x['count']) {
        $err = 'ответ подозрительно мал (' . count($a['prefixes']) . ' вместо ' . $x['count'] . ') — список не тронут';
      } else {
        asnWrite($a, true);
        continue;
      }
    }
    $s = uiSettings();
    foreach ($s['asn'] as $k => $y) {
      if ($y['asn'] === $x['asn']) {
        $s['asn'][$k]['error'] = $err;
        $s['asn'][$k]['last'] = time();
      }
    }
    saveUiSettings($s);
  }
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
  historyScan('auto');
  monitorRun(false);
  autoTick();
  if ($cli === 'daily') {
    snapshotCreate('ежедневный', true);
    if (uiSettings()['subs']) {
      subsRun();
    }
    updateCheck(false);
    asnRun();
  }
  exit(0);
}

// ================= команды =================

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
$authed = !authEnabled() || !empty($_SESSION['auth']);

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
    $file = snapFileOf($id);
    if (!is_file($file)) {
      fail('Нет такого снимка', 404);
    }
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
  $changed = restoreArchive($f['tmp_name'], 'из файла ' . basename($f['name']));
  respond(['changed' => $changed, 'lint' => lintCurrent()]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  fail('POST only', 405);
}
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in) || !is_string($in['cmd'] ?? null)) {
  fail('bad request');
}
$cmd = $in['cmd'];
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
    fail('Неверный логин или пароль. Вход — под root с его паролем; если у root нет пароля, задайте его командой passwd.', 401);
  }
  session_regenerate_id(true);
  $_SESSION['auth'] = true;
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
    $routes = [
      'https' => matchRoute($profiles, $host, $ips, 'tcp', 443, 'tls', $cache),
      'quic' => matchRoute($profiles, $host, $ips, 'udp', 443, 'quic', $cache),
      'http' => matchRoute($profiles, $host, $ips, 'tcp', 80, 'http', $cache),
    ];
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
    respond(probe($host));

  case 'diag':
    $host = cleanHost($str('host'));
    if (!validHost($host) || isIp($host)) {
      fail('Введите имя сайта, например rutracker.org');
    }
    $step = $str('step');
    $ip = is_string($in['ip'] ?? null) ? $in['ip'] : '';
    if (in_array($step, ['direct', 'sni', 'http'], true) && !isIp4($ip)) {
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
    respond(['name' => basename($path), 'content' => $content, 'mtime' => filemtime($path), 'issues' => lintList(basename($path), $content)]);

  case 'list_add':
  case 'list_remove':
    $path = editablePath($str('name'));
    if (!is_file($path)) {
      fail('Файл не найден', 404);
    }
    $items = is_array($in['items'] ?? null) ? $in['items'] : [];
    $isIpList = listKind(basename($path)) === 'ip';
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
        fail('Этот список используется или защищён — удалить нельзя');
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
    respond(['content' => $text, 'vars' => $vars, 'mtime' => filemtime(CONF_FILE)]);

  case 'conf_save_vars':
    [$text, $raw] = candidateFromVars(is_array($in['vars'] ?? null) ? $in['vars'] : []);
    saveConf($text, $raw, !empty($in['force']), (string)($in['note'] ?? 'правка настроек'));

  case 'conf_save_raw':
    $text = normalizeText($str('content'));
    saveConf($text, confRawVars($text), !empty($in['force']), (string)($in['note'] ?? 'правка конфига текстом'));

  case 'catalog':
    respond(['help' => helpInfo(), 'lua' => luaCatalog()]);

  case 'lint':
    [$text, $raw] = candidateFromVars(is_array($in['vars'] ?? null) ? $in['vars'] : []);
    respond(lintConf($raw, $text));

  case 'lint_raw':
    $text = normalizeText($str('content'));
    respond(lintConf(confRawVars($text), $text));

  case 'conf_adapt':
    // Переделка текста из редактора под этот роутер — без сохранения
    respond(adaptConf(normalizeText($str('content'))));

  case 'list_lint':
    $path = editablePath($str('name'));
    respond(['issues' => lintList(basename($path), is_string($in['content'] ?? null) ? $in['content'] : (string)@file_get_contents($path))]);

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
    $p = ['id' => bin2hex(random_bytes(4)), 'state' => 'checking', 'T' => $T, 'versions' => versionsAt($T), 'started' => time(),
      'deadline' => time() + $minutes * 60, 'before' => $before, 'checks' => null];
    pendingSave($p);
    exec(INIT_SCRIPT . ' restart 2>&1', $out, $rc);
    exec('(env -i PATH=' . JOB_PATH . ' NFQWS_UI_CLI=guard php-cgi -q -f ' . escapeshellarg(__FILE__) . ' >/dev/null 2>&1 &)');
    respond(['ok' => $rc === 0, 'output' => implode("\n", $out), 'files' => array_map('basename', array_keys($p['versions']))]);

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
      $p = ['id' => bin2hex(random_bytes(4)), 'state' => 'manual', 'T' => $T, 'versions' => versionsAt($T), 'started' => time()];
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
    $sets = array_values(array_intersect(is_array($in['sets'] ?? null) ? $in['sets'] : ['config', 'std'], ['config', 'std', 'hist', 'other']));
    $own = array_values(array_filter(is_array($in['steps'] ?? null) ? $in['steps'] : [], fn($t) => is_string($t) && preg_match('/^--lua-desync=[^\s"`$\\\\]+$/', $t)));
    testLaunch(['type' => $cmd === 'trace_start' ? 'trace' : 'pick', 'host' => $host,
      'proto' => ($in['proto'] ?? '') === 'http' ? 'http' : 'https', 'sets' => $own ? $sets : ($sets ?: ['config', 'std']), 'steps' => $own, 'repeats' => (int)($in['repeats'] ?? 3), 'refine' => !empty($in['refine'])]);
    respond(['ok' => true]);

  case 'test_status':
    $s = testStatus();
    if (($s['state'] ?? '') === 'running' && !testRunning()) {
      $s['state'] = 'error';
      $s['error'] = 'задание прервалось';
    }
    respond($s);

  case 'test_stop':
    @touch(TEST_DIR . '/stop');
    respond(['ok' => true]);

  case 'test_candidates':
    $proto = ($in['proto'] ?? '') === 'http' ? 'http' : 'https';
    respond(['config' => configCandidates($proto), 'std' => array_map(fn($x) => ['name' => $x[0], 'steps' => $x[1]], $proto === 'http' ? STD_HTTP : STD_TLS)]);

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
    foreach ($idx as &$e) {
      // что отличается от текущего состояния
      $diff = [];
      foreach (array_unique(array_merge(array_keys($e['files']), array_keys($cur))) as $f) {
        if (($e['files'][$f] ?? null) !== ($cur[$f] ?? null)) {
          $diff[] = $f;
        }
      }
      $e['differs'] = $diff;
      unset($e['files']);
    }
    unset($e);
    respond(['items' => $idx, 'settings' => uiSettings()['snapshots'], 'dir' => SNAP_DIR,
      'total' => array_sum(array_map(fn($e) => $e['size'], $idx))]);

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
    $file = snapFileOf($str('id'));
    if (!is_file($file)) {
      fail('Нет такого снимка', 404);
    }
    $changed = restoreArchive($file, 'из снимка ' . $str('id'));
    respond(['changed' => $changed, 'lint' => lintCurrent()]);

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
    respond($s);

  case 'monitor_get':
    $s = uiSettings();
    respond(['settings' => $s['monitor'], 'data' => monitorData(), 'tg' => ['token_set' => $s['notify']['tg_token'] !== '', 'chat' => $s['notify']['tg_chat']]]);

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
      $s['auto']['pause'] = in_array((int)$in['pause'], [6, 12, 24], true) ? (int)$in['pause'] : 12;
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
    $r = autoApply($host, $o['steps'], 'по кнопке');
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
    $ip = ripe('whats-my-ip')['ip'] ?? null;
    $asn = $ip ? (ripe('network-info', $ip)['asns'][0] ?? null) : null;
    $holder = $asn ? (ripe('as-overview', "AS$asn")['holder'] ?? null) : null;
    if (!$holder) {
      fail('Не удалось определить провайдера — впишите название вручную');
    }
    respond(['provider' => $holder]);   // внешний адрес наружу интерфейса не отдаём

  case 'provider_set':
    $s = uiSettings();
    $s['provider'] = preg_match('/^[^\x00-\x1f"`$\\\\]{0,80}/u', trim((string)($in['provider'] ?? '')), $pm) ? $pm[0] : '';
    saveUiSettings($s);
    respond(['provider' => $s['provider']]);

  case 'profile_check':
    respond(profileCheck(array_slice(is_array($in['tokens'] ?? null) ? $in['tokens'] : [], 0, 200)));

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
    respond(['items' => uiSettings()['asn']]);

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

  case 'notify_test':
    $err = notifyTelegram('Проверка уведомлений nfqws2: всё работает.');
    if ($err) {
      fail('Telegram: ' . $err);
    }
    respond(['ok' => true]);

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
    exec(INIT_SCRIPT . ' ' . $action . ' 2>&1', $out, $rc);
    usleep(500000);
    respond(['ok' => $rc === 0, 'output' => implode("\n", $out)]);

  case 'update_check':
    respond(updateCheck(!empty($in['force'])));

  case 'update_run':
    updateStart($str('version'));
    respond(['ok' => true]);

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
    foreach (glob(LOG_DIR . '/nfqws2*.log') ?: [] as $f) {
      $files[] = ['name' => basename($f), 'size' => filesize($f), 'tail' => implode('', array_slice(@file($f) ?: [], -200))];
    }
    respond(['syslog' => $out, 'files' => $files]);

  default:
    fail('unknown command', 404);
}
