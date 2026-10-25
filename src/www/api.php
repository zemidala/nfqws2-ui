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

const UI_VERSION = '1.23.0';

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
  // недопустимый UTF-8 (имя из DNS, сообщение системы) раньше давал пустой ответ — теперь заменяется на «�»
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false) {
    error_log('respond: ' . json_last_error_msg());
    http_response_code(500);
    $json = json_encode(['error' => 'Ответ не удалось собрать: ' . json_last_error_msg()], JSON_UNESCAPED_UNICODE);
  }
  echo $json;
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

// Сколько держать вход без действий (выбор в «О программе»)
define('SESSION_LIVES', [3600, 86400, 604800, 2592000]);
define('SESS_DIR', '/tmp/nfqws-ui-sess');

function sessionLife(): int
{
  $v = (int)uiSettings()['session'];
  return in_array($v, SESSION_LIVES, true) ? $v : 604800;
}

// Вход только под учётной записью с uid 0 и только с паролем: без пароля интерфейс не пускает.
// Обычно это root; на Asuswrt-Merlin у неё имя администратора роутера (admin или своё)
function authenticate(string $username, string $password): bool
{
  if ($password === '' || !preg_match('/^[A-Za-z0-9._-]{1,32}$/', $username)) {
    return false;
  }
  // На Keenetic это root из Entware (/opt/etc/passwd, /opt/etc/shadow), а не учётная запись прошивки
  $passwd = ROOT . '/etc/passwd';
  $pw = preg_grep('/^' . preg_quote($username, '/') . ':[^:]*:0:/', @file($passwd) ?: []);
  if (!$pw) {
    return false;
  }
  $hash = explode(':', array_pop($pw))[1] ?? '';
  if (strlen($hash) < 13) {
    $shadows = [ROOT . '/etc/shadow'];
    // Merlin: Entware ссылается на системные passwd/shadow, а /opt/etc/shadow может не быть.
    // На Keenetic /opt/etc/passwd — свой файл Entware, туда системный shadow не попадает
    if (ROOT !== '' && is_link($passwd) && @readlink($passwd) === '/etc/passwd') {
      $shadows[] = '/etc/shadow';
    }
    $hash = '';
    foreach ($shadows as $f) {
      $sh = preg_grep('/^' . preg_quote($username, '/') . ':/', @file($f) ?: []);
      if ($sh) {
        $hash = explode(':', array_pop($sh))[1] ?? '';
        break;
      }
    }
  }
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
    // remote — копии снимков на другом устройстве (NAS) по ssh; keep_local — сколько последних оставлять на роутере
    'snapshots' => ['max_count' => 50, 'max_days' => 30, 'remote' => ['enabled' => false, 'host' => '', 'port' => 22, 'user' => '', 'dir' => '', 'keep_local' => 5]],
    'monitor' => ['enabled' => true, 'interval' => 30, 'sites' => ['rutracker.org', 'youtube.com', 'discord.com', 'x.com']],
    // via — каким путём слать в Telegram: '' — как обычно, iface — через интерфейс (туннель), proxy — через прокси
    'notify' => ['tg_token' => '', 'tg_chat' => '', 'via' => '', 'iface' => '', 'proxy' => ''],
    'auto' => ['enabled' => false, 'apply' => false, 'fails' => 2, 'pause' => 12],
    'session' => 604800,   // сколько не выходить из интерфейса без действий, секунды: см. SESSION_LIVES
    'provider' => '',
    'provider_asn' => 0,   // номер AS провайдера: по нему берутся стратегии сообщества
    'seen' => null,        // метки «новое»: см. seenInfo()
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

// Метки «новое» у новых функций интерфейса (список функций — FEATURES в app.js):
// since — версия, с которой человек начал пользоваться интерфейсом (метки — только у того, что новее),
// open — что из нового уже открыто, told — о какой версии уже показано сообщение «Обновлено до…».
// Хранится на роутере: открыл новое с телефона — метка пропала и на компьютере.
function seenInfo(): array
{
  $s = uiSettings();
  if (!is_array($s['seen'])) {
    // меток ещё не было. Если интерфейсом уже пользовались (эти файлы появляются за первые минуты работы) —
    // это обновление с версии без меток, 1.8.0 или раньше; иначе — новая установка, и новым ничего не считается
    $used = array_filter(['settings.json', 'update.json', 'monitor.json', 'picks.json'], fn($f) => is_file(UI_CONF_DIR . "/$f"));
    $s['seen'] = ['since' => $used ? '1.8.0' : UI_VERSION, 'open' => [], 'told' => $used ? '' : UI_VERSION];
    saveUiSettings($s);
  }
  return $s['seen'] + ['since' => UI_VERSION, 'open' => [], 'told' => ''];
}

// Запись, которая переживает отключение питания: данные сбрасываются на диск до того, как новый файл встанет на место
// старого. Без этого после обрыва на месте файла оказывается пустой или обрезанный (так потерялась история подборов).
function putSynced(string $file, string $data): bool
{
  $h = @fopen($file, 'w');
  if (!$h) {
    return false;
  }
  $ok = fwrite($h, $data) === strlen($data) && fflush($h);
  if ($ok && function_exists('fsync')) {
    @fsync($h);
  }
  fclose($h);
  return $ok;
}

function putSafe(string $path, string $data): bool
{
  return putSynced("$path.tmp", $data) && rename("$path.tmp", $path);
}

function saveUiSettings(array $s): void
{
  @mkdir(UI_CONF_DIR, 0755, true);
  putSafe(UI_SETTINGS, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
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
  putSafe(SNAP_DIR . '/index.json', json_encode(array_values($idx), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
  // пути от корня, как на диске: в Entware (Keenetic) — opt/etc/…
  $p = [substr(CONF_FILE, 1), substr(LISTS_DIR, 1), substr(CONF_DIR, 1) . '/blobs'];
  // настройки интерфейса — кроме ключа роутера для NAS: закрытый ключ не должен уходить ни на NAS, ни в скачанный архив
  foreach (glob(UI_CONF_DIR . '/*') ?: [] as $f) {
    if (is_file($f) && !preg_match('/^(remote_key(\.pub)?|known_hosts)$|\.(tmp|bad)$/', basename($f))) {
      $p[] = substr($f, 1);
    }
  }
  return array_values(array_filter($p, fn($x) => file_exists('/' . $x)));
}

// Создаёт снимок. $ifChanged — не создавать, если с прошлого снимка ничего не поменялось
function snapshotCreate(string $reason, bool $ifChanged = true, string $note = ''): ?array
{
  @mkdir(SNAP_DIR, 0700, true);
  $idx = snapIndex();
  $hashes = snapHashes();
  // full — снимок с конфигом и списками; на Keenetic до 1.15.4 в снимки попадали только настройки интерфейса
  if ($ifChanged && $idx && end($idx)['files'] === $hashes && (!ROOT || !empty(end($idx)['full']))) {
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
  $entry = ['id' => $id, 'ts' => time(), 'reason' => $reason, 'note' => $note, 'size' => filesize($file), 'files' => $hashes, 'full' => true];
  $lock = snapLock();
  $idx = snapIndex();   // перечитываем под замком: отправка на NAS могла поменять отметки
  $idx[] = $entry;
  snapPrune($idx);
  snapSaveIndex($idx);
  fclose($lock);
  remoteKick();
  return $entry;
}

// Замок на index.json: снимок создаёт запрос интерфейса или cron, а отметки «на NAS» ставит фоновая отправка
function snapLock()
{
  @mkdir(SNAP_DIR, 0700, true);
  $l = fopen(SNAP_DIR . '/.lock', 'c');
  flock($l, LOCK_EX);
  return $l;
}

// Без копий на NAS: хранить по сроку и числу, пять последних и закреплённые — всегда.
// С копиями на NAS: на роутере — keep_local последних, закреплённые и ещё не отправленные; остальные
// остаются в списке как «только на NAS» (там хранится всё — удалить или перезаписать их с роутера нельзя).
function snapPrune(array &$idx): void
{
  $cfg = uiSettings()['snapshots'];
  $remote = remoteOn();
  $minTs = time() - 86400 * max(1, (int)$cfg['max_days']);
  $local = array_values(array_filter($idx, fn($e) => ($e['local'] ?? true)));
  $n = count($local);
  $pos = array_flip(array_column($local, 'id'));
  $keepN = $remote ? max(1, (int)$cfg['remote']['keep_local']) : 5;
  $keep = [];
  foreach ($idx as $e) {
    if (!($e['local'] ?? true)) {
      $keep[] = $e;
      continue;
    }
    $i = $pos[$e['id']];
    $newest = $i >= $n - $keepN;
    if ($newest || !empty($e['pinned'])) {
      $keep[] = $e;
    } elseif ($remote) {
      if (empty($e['remote'])) {
        $keep[] = $e;   // ещё не дошёл до NAS
        continue;
      }
      @unlink(SNAP_DIR . "/{$e['id']}.tar.gz");
      $e['local'] = false;
      unset($e['files']);   // хеши файлов нужны только для «отличается от текущего», а список на NAS растёт без конца
      $keep[] = $e;
    } elseif ($i >= $n - max(5, (int)$cfg['max_count']) && $e['ts'] >= $minTs) {
      $keep[] = $e;
    } else {
      @unlink(SNAP_DIR . "/{$e['id']}.tar.gz");
      if (!empty($e['remote'])) {
        $e['local'] = false;
        unset($e['files']);
        $keep[] = $e;
      }
    }
  }
  $idx = $keep;
}

// ---------- копии снимков на NAS (или любом устройстве с ssh) ----------
// Роутер сам отправляет каждый новый снимок по ssh своим ключом. На той стороне ключ ограничен строкой
// в authorized_keys (command=…,restrict): им можно только положить новый снимок, прочитать и перечислить
// снимки в одной папке — ни оболочки, ни удаления, ни перезаписи. Скрипт той стороны — remoteScript().

define('REMOTE_KEY', UI_CONF_DIR . '/remote_key');
define('REMOTE_STATE', UI_CONF_DIR . '/remote.json');

function remoteCfg(): array
{
  return uiSettings()['snapshots']['remote'];
}

// dropbear (OpenWrt) или OpenSSH (Entware): у них разные ключи и параметры
function remoteTool(): ?string
{
  foreach (['dbclient' => 'dropbear', 'ssh' => 'openssh'] as $bin => $kind) {
    exec('command -v ' . $bin . ' 2>/dev/null', $o, $rc);
    if ($rc === 0) {
      // на OpenWrt ssh — это сам dropbear
      return $kind === 'openssh' && is_link(trim($o[0] ?? '')) && strpos((string)readlink(trim($o[0])), 'dropbear') !== false ? 'dropbear' : $kind;
    }
    $o = [];
  }
  return null;
}

function remoteOn(): bool
{
  $c = remoteCfg();
  return !empty($c['enabled']) && $c['host'] !== '' && $c['user'] !== '' && is_file(REMOTE_KEY);
}

function remotePubKey(): string
{
  if (!is_file(REMOTE_KEY)) {
    return '';
  }
  $cmd = remoteTool() === 'openssh' ? 'cat ' . escapeshellarg(REMOTE_KEY . '.pub') : 'dropbearkey -y -f ' . escapeshellarg(REMOTE_KEY);
  exec($cmd . ' 2>/dev/null', $o);
  foreach ($o as $l) {
    if (preg_match('/^ssh-\S+ \S+/', $l)) {
      return preg_replace('/ \S+$/', '', trim($l)) . ' nfqws2-ui';
    }
  }
  return '';
}

function remoteKeyCreate(): string
{
  @mkdir(UI_CONF_DIR, 0700, true);
  if (!is_file(REMOTE_KEY)) {
    $tool = remoteTool();
    $tool || fail('На роутере нет ssh-клиента (dbclient или ssh)');
    $cmd = $tool === 'openssh' ? 'ssh-keygen -q -t ed25519 -N "" -C nfqws2-ui -f ' . escapeshellarg(REMOTE_KEY) : 'dropbearkey -t ed25519 -f ' . escapeshellarg(REMOTE_KEY);
    exec($cmd . ' 2>&1', $o, $rc);
    ($rc === 0 && is_file(REMOTE_KEY)) || fail('Не удалось создать ключ: ' . implode(' ', $o));
    @chmod(REMOTE_KEY, 0600);
  }
  return remotePubKey();
}

// Одна команда на той стороне: ping | list | put <имя> | get <имя>. $in — файл на вход, $out — куда писать вывод
function remoteCall(string $op, string $name = '', ?string $in = null, ?string $out = null, int $timeout = 30): array
{
  $c = remoteCfg();
  $tool = remoteTool();
  if (!$tool) {
    return [false, 'на роутере нет ssh-клиента'];
  }
  $target = escapeshellarg($c['user'] . '@' . $c['host']);
  $key = escapeshellarg(REMOTE_KEY);
  $port = (int)$c['port'];
  $remote = escapeshellarg(trim("$op $name"));
  // ключ хоста запоминается при первом подключении (в UI_CONF_DIR/.ssh) и дальше сверяется
  $cmd = $tool === 'openssh'
    ? "ssh -i $key -p $port -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=" . escapeshellarg(UI_CONF_DIR . '/known_hosts') . " -o ConnectTimeout=10 $target $remote"
    : "dbclient -y -i $key -p $port $target $remote";
  $p = proc_open('exec ' . $cmd, [0 => $in ? ['file', $in, 'r'] : ['file', '/dev/null', 'r'], 1 => $out ? ['file', $out, 'w'] : ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['HOME' => UI_CONF_DIR, 'PATH' => JOB_PATH]);
  if (!is_resource($p)) {
    return [false, 'не удалось запустить ssh'];
  }
  $text = '';
  $err = '';
  foreach ($pipes as $pp) {
    stream_set_blocking($pp, false);
  }
  $deadline = time() + $timeout;
  do {
    $st = proc_get_status($p);
    if (isset($pipes[1])) {
      $text .= (string)stream_get_contents($pipes[1]);
    }
    $err .= (string)stream_get_contents($pipes[2]);
    if (!$st['running']) {
      break;
    }
    usleep(100000);
  } while (time() < $deadline);
  if ($st['running']) {
    proc_terminate($p, 9);
    proc_close($p);
    return [false, 'нет ответа за ' . $timeout . ' с'];
  }
  if (isset($pipes[1])) {
    $text .= (string)stream_get_contents($pipes[1]);
  }
  $err .= (string)stream_get_contents($pipes[2]);
  foreach ($pipes as $pp) {
    fclose($pp);
  }
  proc_close($p);
  $rc = $st['exitcode'];
  $err = trim(preg_replace('/^.*(Warning|Host .* is not in the trusted hosts file|Fingerprint|Do you want to continue|Permanently added).*$/mi', '', $err));
  return [$rc === 0, $rc === 0 ? $text : ($err !== '' ? $err : "код $rc")];
}

function remoteState(array $patch = []): array
{
  $s = json_decode((string)@file_get_contents(REMOTE_STATE), true);
  $s = is_array($s) ? $s : [];
  if ($patch) {
    $s = array_merge($s, $patch);
    @file_put_contents(REMOTE_STATE, json_encode($s, JSON_UNESCAPED_UNICODE));
  }
  return $s;
}

// Отправить всё, что ещё не на NAS, и список снимков; после — убрать с роутера лишнее
function remotePush(): array
{
  if (!remoteOn()) {
    return ['sent' => 0, 'error' => 'отправка на NAS выключена'];
  }
  $l = fopen(SNAP_DIR . '/.push.lock', 'c');
  if (!flock($l, LOCK_EX | LOCK_NB)) {
    return ['sent' => 0, 'error' => null, 'busy' => true];   // уже идёт фоновая отправка
  }
  remoteState(['try' => time()]);
  $sent = 0;
  $error = null;
  foreach (snapIndex() as $e) {
    $f = SNAP_DIR . "/{$e['id']}.tar.gz";
    if (!empty($e['remote']) || !($e['local'] ?? true) || !is_file($f)) {
      continue;
    }
    [$ok, $msg] = remoteCall('put', "{$e['id']}.tar.gz", $f, null, 120);
    if (!$ok && strpos($msg, 'уже есть') === false) {
      $error = trim($msg);
      break;
    }
    $sent++;
    $lock = snapLock();
    $idx = snapIndex();
    foreach ($idx as &$x) {
      if ($x['id'] === $e['id']) {
        $x['remote'] = true;
      }
    }
    unset($x);
    snapSaveIndex($idx);
    fclose($lock);
  }
  if (!$error) {
    $lock = snapLock();
    $idx = snapIndex();
    snapPrune($idx);
    snapSaveIndex($idx);
    fclose($lock);
    [$ok, $msg] = remoteCall('put', 'index.json', SNAP_DIR . '/index.json');
    $error = $ok ? null : 'список снимков: ' . trim($msg);
  }
  remoteState($error ? ['error' => $error, 'error_ts' => time()] : ['ok' => time(), 'error' => null]);
  flock($l, LOCK_UN);
  fclose($l);
  return ['sent' => $sent, 'error' => $error];
}

// Отправка в фоне — запрос интерфейса не ждёт ssh
function remoteKick(): void
{
  if (remoteOn() && getenv('NFQWS_UI_CLI') !== 'push') {
    exec('(env -i PATH=' . JOB_PATH . ' NFQWS_UI_CLI=push php-cgi -q -f ' . escapeshellarg(__FILE__) . ' >/dev/null 2>&1 &)');
  }
}

// Архив снимка на роутере; если он только на NAS — скачать во временную папку
function snapArchive(string $id): string
{
  $file = snapFileOf($id);
  if (is_file($file)) {
    return $file;
  }
  $known = array_filter(snapIndex(), fn($e) => $e['id'] === $id);
  ($known && remoteOn()) || fail('Нет такого снимка', 404);
  $tmp = "/tmp/nfqws-ui-snap-$id.tar.gz";
  if (!is_file($tmp) || !filesize($tmp)) {
    [$ok, $msg] = remoteCall('get', "$id.tar.gz", null, $tmp, 120);
    exec('tar -tzf ' . escapeshellarg($tmp) . ' >/dev/null 2>&1', $o, $rc);
    if (!$ok || $rc !== 0) {
      @unlink($tmp);
      fail('Не удалось взять снимок с NAS: ' . ($ok ? 'архив повреждён' : $msg), 502);
    }
  }
  return $tmp;
}

// Скрипт той стороны: кладётся в домашнюю папку пользователя, вызывается только из authorized_keys
function remoteScript(): string
{
  return <<<'SH'
#!/bin/sh
# nfqws2-ui: приём снимков с роутера. Вызывается только из ~/.ssh/authorized_keys (command=...),
# поэтому ключом роутера нельзя ничего, кроме: положить НОВЫЙ снимок, прочитать и перечислить снимки в одной папке.
dir=$1
set -f
set -- $SSH_ORIGINAL_COMMAND
set +f
op=$1 name=$2
case "$op" in ping|list) ;; put|get)
  case "$name" in
    index.json|[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9].tar.gz) ;;
    [0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9]-[0-9].tar.gz) ;;
    [0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9].tar.gz) ;;
    *) echo "имя не подходит: $name" >&2; exit 2 ;;
  esac ;;
  *) echo "неизвестная команда: $op" >&2; exit 2 ;;
esac
mkdir -p "$dir" || exit 1
umask 077
case "$op" in
  ping) echo ok ;;
  list) cd "$dir" && for f in [0-9]*.tar.gz; do [ -f "$f" ] && echo "$f $(wc -c < "$f")"; done; exit 0 ;;
  get) cat "$dir/$name" ;;
  put)
    if [ "$name" != index.json ] && [ -e "$dir/$name" ]; then cat > /dev/null; echo "уже есть: $name" >&2; exit 3; fi
    cat > "$dir/.$name.part" && mv -f "$dir/.$name.part" "$dir/$name" ;;
esac
SH;
}

// Одна команда для той стороны: положить скрипт и разрешить ключ роутера только для него
function remoteSetupCmd(string $dir, string $pub): string
{
  $line = 'command="sh .nfqws-ui-store.sh ' . $dir . '",restrict ' . $pub;
  return "umask 077 && mkdir -p ~/.ssh && cat > ~/.nfqws-ui-store.sh <<'NFQWSUI'\n" . remoteScript() . "\nNFQWSUI\n"
    . "grep -qF " . escapeshellarg($pub) . " ~/.ssh/authorized_keys 2>/dev/null || echo " . escapeshellarg($line) . " >> ~/.ssh/authorized_keys";
}

// Содержимое одного файла из снимка
function snapReadFile(string $id, string $rel): ?string
{
  $file = snapArchive($id);
  if (!is_file($file) || !preg_match('#^(opt/)?etc/nfqws2/(nfqws2\.conf|lists/[A-Za-z0-9_.-]+|blobs/[A-Za-z0-9_.-]+)$#', $rel)) {
    return null;
  }
  $out = shell_exec('tar -xzOf ' . escapeshellarg($file) . ' ' . escapeshellarg($rel) . ' 2>/dev/null');
  return $out === null ? null : $out;
}

// Проверяет архив и раскладывает его поверх текущих файлов (файлы, которых нет в архиве, не трогает).
// Пути в архиве — etc/… (OpenWrt) или opt/etc/… (Keenetic); файлы ложатся туда, где они у этой системы,
// так что копию с одного роутера можно восстановить и на другом.
// $nfqOnly — только конфиг, списки и блобы nfqws2, без настроек интерфейса (откат пакета nfqws2);
// $keep — пути файлов (как на роутере), которые не трогать
function restoreArchive(string $archive, string $reason, bool $nfqOnly = false, array $keep = []): array
{
  exec('tar -tzf ' . escapeshellarg($archive) . ' 2>&1', $list, $rc);
  if ($rc !== 0 || !$list) {
    fail('Это не архив tar.gz или он повреждён');
  }
  $allowed = '#^(opt/?|(opt/)?(etc/?|etc/nfqws2/?|etc/nfqws2/(nfqws2\.conf|lists/?|blobs/?|lists/[A-Za-z0-9_.-]+|blobs/[A-Za-z0-9_.-]+)|etc/nfqws-ui/?|etc/nfqws-ui/[A-Za-z0-9_.-]+))$#';
  $conf = false;
  foreach ($list as $e) {
    if (!preg_match($allowed, $e)) {
      fail("В архиве лишний файл: $e — восстановление отменено");
    }
    $conf = $conf || preg_match('#^(opt/)?etc/nfqws2/nfqws2\.conf$#', $e);
  }
  if (!$conf) {
    fail('В архиве нет конфига nfqws2 — только настройки интерфейса. Так собирались копии на Keenetic до версии 1.15.4: конфиг, списки и блобы в них не попадали. Восстановить из такой копии нечего — сделайте новый снимок.');
  }
  historyScan('auto');
  snapshotCreate('перед восстановлением', false, $reason);
  $tmp = '/tmp/nfqws-ui-restore-' . bin2hex(random_bytes(4));
  mkdir($tmp, 0700);
  exec('tar -xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($tmp) . ' 2>&1', $o, $rc);
  $changed = [];
  foreach ($list as $e) {
    $src = "$tmp/$e";
    if (!is_file($src) || ($nfqOnly && !preg_match('#^(opt/)?etc/nfqws2/#', $e))) {
      continue;
    }
    $dst = ROOT . '/' . preg_replace('#^opt/#', '', $e);
    if (in_array($dst, $keep, true) || (is_file($dst) && md5_file($dst) === md5_file($src))) {
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
  if (!putSynced($tmp, $content)) {
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

// Новые стандартные версии файлов, которые менеджер пакетов положил рядом с изменёнными вручную
// при обновлении nfqws2: opkg — «имя-opkg», apk — «имя.apk-new». nfqws2 их не читает.
function pkgLeftovers(bool $full = false): array
{
  $r = [];
  foreach ([CONF_DIR => '', LISTS_DIR => 'lists/'] as $dir => $pre) {
    foreach (@scandir($dir) ?: [] as $f) {
      if (!preg_match('/^(.+?)(-opkg|\.apk-new)$/', $f, $m) || !is_file("$dir/$f")) {
        continue;
      }
      $e = ['name' => $pre . $f, 'base' => $m[1], 'mtime' => filemtime("$dir/$f")];
      if ($full) {
        $own = "$dir/{$m[1]}";
        $e['size'] = filesize("$dir/$f");
        $e['own'] = is_file($own);
        $e['same'] = $e['own'] && md5_file($own) === md5_file("$dir/$f");
      }
      $r[] = $e;
    }
  }
  return $r;
}

function pkgLeftoversDelete(array $names): int
{
  $known = array_column(pkgLeftovers(), 'name');
  $n = 0;
  foreach ($names as $name) {
    if (is_string($name) && in_array($name, $known, true) && @unlink(CONF_DIR . '/' . $name)) {
      $n++;
    }
  }
  return $n;
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
    $cmd = (string)@file_get_contents($f);
    if (str_starts_with($cmd, NFQWS_BIN . "\0") && !ownNfqws($cmd)) {
      return (int)basename(dirname($f));
    }
  }
  return null;
}

// Наши собственные короткие запуски nfqws2: проверочный (подбор), --dry-run (проверка конфига), сборка пакета QUIC
function ownNfqws(string $cmd): bool
{
  return (bool)preg_match('/--qnum=' . TEST_QNUM . '\b|--dry-run|--intercept=0/', str_replace("\0", ' ', $cmd));
}

// Файл PID скрипта запуска не указывает на работающий nfqws2. Скрипт nfqws2-keenetic считает сервис запущенным,
// только если самый новый процесс nfqws2 (pgrep -nf) — тот, что в файле. Если в момент перезапуска работал ещё
// один nfqws2 (наш проверочный, --dry-run), restart старый процесс не останавливает, новый не может занять
// очередь и выходит, файл PID пропадает — и дальше restart не помогает никогда. На OpenWrt сервисом управляет procd
function pidLost(?int $pid): bool
{
  return ROOT !== '' && $pid !== null && (int)trim((string)@file_get_contents(PID_FILE)) !== $pid;
}

// Скрипт запуска (start | stop | restart) с проверкой итога. На Keenetic чиним «потерянный» процесс (см. pidLost):
// останавливаем его сами и запускаем сервис заново. Возвращает [код, вывод, что сделали сами или null]
function serviceRun(string $action): array
{
  $before = findPid();
  $started = $before ? processInfo($before)['started'] : null;
  exec(INIT_SCRIPT . " $action 2>&1", $out, $rc);
  if (ROOT === '' || $action === 'start' && !pidLost($before)) {
    return [$rc, implode("\n", $out), null];
  }
  // restart возвращается раньше, чем nfqws2 поднимется: ждём новый процесс до 3 с
  $now = null;
  for ($i = 0; $i < 6; $i++) {
    usleep(500000);
    $now = findPid();
    if ($action === 'stop' ? $now === null : $now !== null && $now !== $before && !pidLost($now)) {
      return [$rc, implode("\n", $out), null];
    }
  }
  $stuck = $now !== null && ($action === 'stop' || ($now === $before && processInfo($now)['started'] === $started) || pidLost($now));
  if (!$stuck) {
    return [$rc, implode("\n", $out), null];
  }
  exec('kill ' . (int)$now . ' 2>/dev/null');
  for ($i = 0; $i < 10 && is_dir("/proc/$now"); $i++) {
    usleep(300000);
  }
  if (is_dir("/proc/$now")) {
    exec('kill -9 ' . (int)$now . ' 2>/dev/null');
    usleep(300000);
  }
  @unlink(PID_FILE);
  $note = "скрипт запуска не видел работающий nfqws2 (PID $now, файл PID не совпадал) — процесс остановлен"
    . ($action === 'stop' ? '' : ' и сервис запущен заново');
  if ($action !== 'stop') {
    exec(INIT_SCRIPT . ' start 2>&1', $out2, $rc);
    $out = array_merge($out, $out2);
  } else {
    $rc = is_dir("/proc/$now") ? 1 : 0;   // скрипт ответил «не запущен», но остановили мы
  }
  return [$rc, implode("\n", $out), $note];
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

// Архитектура пакетов: uname на mipsel отвечает «mips», поэтому спрашиваем opkg (строка с наибольшим приоритетом)
function sysArch(): string
{
  exec((ROOT ? '/opt/bin/' : '') . 'opkg print-architecture 2>/dev/null', $out);
  $best = '';
  $prio = -1;
  foreach ($out as $l) {
    if (preg_match('/^arch\s+(\S+)\s+(\d+)/', $l, $m) && !in_array($m[1], ['all', 'noarch'], true) && (int)$m[2] >= $prio) {
      [$best, $prio] = [$m[1], (int)$m[2]];
    }
  }
  return $best !== '' ? $best : php_uname('m');
}

// Версия установленного пакета: opkg (OpenWrt до 24.10, Entware) или apk (OpenWrt 25+)
function packageVersion(string $pkg, bool $fresh = false): ?string
{
  // в одном запросе версию спрашивают несколько раз (состояние, обновление nfqws2) — opkg на mipsel небыстрый
  static $memo = [];
  if (!$fresh && array_key_exists($pkg, $memo)) {
    return $memo[$pkg];
  }
  return $memo[$pkg] = packageVersionRead($pkg);
}

function packageVersionRead(string $pkg): ?string
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
  // «\» в конце строки — перенос значения по строкам, оболочка его убирает; параметром он не является.
  // «\» с пробелами после него оболочка отдаёт nfqws2 отдельным аргументом «\» — он ничего не значит, и для
  // интерфейса это тоже перенос (иначе «\» попадал в пересобранные профили и сохранение отказывало)
  $s = trim(preg_replace('/\\\\[ \t]*\r?\n/', ' ', $s));
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

// «Оформить» в «Конфиге целиком»: меняются только пробелы и переносы. У многострочных значений в кавычках —
// параметр на строку, выравнивание под кавычку, пустая строка перед --new; строка с комментарием «#» внутри
// кавычек остаётся целой (комментарий — до конца строки). Вне кавычек — без пробелов в конце строк и без
// лишних пустых строк. Если аргументы nfqws2 после этого хоть в чём-то другие — отказ (null).
function confFormat(string $text): ?string
{
  $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $text);
  $out = preg_replace_callback('/^([A-Z_][A-Z0-9_]*)="([^"]*)"/m', function ($m) {
    $val = $m[2];
    if (!str_contains($val, "\n") || trim($val) === '') {
      return $m[0];
    }
    $items = [];
    foreach (explode("\n", preg_replace('/\\\\[ \t]*\n/', "\n", $val)) as $line) {
      $line = trim($line);
      $code = $line;
      $comment = null;
      if (preg_match('/(^|\s)(#.*)$/', $line, $c, PREG_OFFSET_CAPTURE)) {
        $code = trim(substr($line, 0, $c[2][1]));
        $comment = $c[2][0];
      }
      foreach ($code === '' ? [] : preg_split('/\s+/', $code) as $t) {
        $items[] = $t;
      }
      if ($comment !== null) {
        $items[] = $comment;
      }
    }
    $pad = str_repeat(' ', strlen($m[1]) + 2);
    $lines = [];
    foreach ($items as $t) {
      if ($lines && isNewArg($t)) {
        $lines[] = '';
      }
      $lines[] = $t;
    }
    $body = '';
    foreach ($lines as $i => $l) {
      $body .= $i === 0 ? $l : "\n" . ($l === '' ? '' : $pad . $l);
    }
    return $m[1] . '="' . $body . '"';
  }, $text);
  $out = preg_replace('/[ ]+$/m', '', $out);
  $out = preg_replace("/\n{3,}/", "\n\n", trim($out, "\n")) . "\n";
  // проверка: те же присваивания в том же порядке (переменная может быть присвоена и дважды),
  // у каждого — те же аргументы; вне присваиваний — те же строки, кроме пустых
  $sig = function (string $s): array {
    preg_match_all('/^([A-Z_][A-Z0-9_]*)=("([^"]*)"|[^\n]*)/m', $s, $m, PREG_SET_ORDER);
    $r = array_map(fn($x) => [$x[1], tokens(isset($x[3]) && ($x[2][0] ?? '') === '"' ? $x[3] : $x[2])], $m);
    $rest = preg_replace('/^[A-Z_][A-Z0-9_]*=("[^"]*"|[^\n]*)/m', '', $s);
    $r[] = array_values(array_filter(array_map('rtrim', explode("\n", $rest)), fn($l) => $l !== ''));
    return $r;
  };
  return $sig($text) === $sig($out) ? $out : null;
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

// --new или --new=имя — начало следующего профиля. Имя из --new=имя переносится в профиль как --name=имя:
// для nfqws2 это одно и то же, а профиль несёт своё имя с собой (в app.js — splitParts, так же)
function isNewArg(string $a): bool
{
  return $a === '--new' || str_starts_with($a, '--new=');
}

function splitNew(array $args): array
{
  $parts = [[]];
  foreach ($args as $a) {
    if (isNewArg($a)) {
      $parts[] = strlen($a) > 6 ? ['--name=' . substr($a, 6)] : [];
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

const WIN_TS_HINT = 'Включить в Windows — командная строка от имени администратора: netsh interface tcp set global timestamps=enabled';

// Шаг --lua-desync, у которого из маскировок (fooling) только tcp_ts=N
function tsOnlyFooling(string $step): bool
{
  $parts = explode(':', preg_replace('/^--lua-desync=/', '', $step));
  $ts = false;
  foreach ($parts as $p) {
    if (preg_match('/^(tcp_md5|badsum|tcp_seq=|tcp_ack=|ip_ttl=|ip6_ttl=|ip_autottl=|ip6_autottl=|tcp_flags_(set|unset)=|ip6_(hopbyhop|destopt|routing|ah)|fool=)/', $p)) {
      return false;
    }
    $ts = $ts || str_starts_with($p, 'tcp_ts=');
  }
  return $ts;
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
    'autolist' => null, 'payloads' => [], 'desync' => [], 'other' => [], 'name' => null];
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
      case 'name': $p['name'] = $val !== '' ? $val : null; break;
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

// Вид списка по подключению: --ipset → ip, --hostlist → host. Профили передаёт listsInventory, иначе читаем сами
function listUse(?array $profiles = null): array
{
  static $use = null;
  if ($profiles !== null || $use === null) {
    $use = [];
    foreach ($profiles ?? currentProfiles() as $p) {
      foreach (['ipsets' => 'ip', 'ipset_excludes' => 'ip', 'hostlists' => 'host', 'hostlist_excludes' => 'host'] as $key => $kind) {
        foreach ($p[$key] as $path) {
          $use[$path] ??= $kind;
        }
      }
      if ($p['autolist']) {
        $use[$p['autolist']] ??= 'host';
      }
    }
  }
  return $use;
}

// Подключён ли список к профилю. Неподключённые не проверяются: в той же папке лежат и чужие списки
// (для XKeen и т. п.) со своим форматом — проверять их незачем, а большие ещё и читать долго
function listUsed(string $path): bool
{
  foreach (array_keys(listUse()) as $p) {
    if (basename($p) === basename($path)) {
      return true;
    }
  }
  return false;
}

// Вид списка: как его подключают профили; неподключённый — по содержимому (больше половины строк —
// адреса и подсети); пустой — по имени. $lines — записи, если они уже прочитаны или ещё не сохранены
function listKind(string $name, ?array $lines = null): string
{
  $use = listUse();
  if (isset($use[LISTS_DIR . "/$name"])) {
    return $use[LISTS_DIR . "/$name"];
  }
  if ($lines === null) {
    return listMeta(LISTS_DIR . "/$name")['kind'] ?? (str_starts_with($name, 'ipset') ? 'ip' : 'host');
  }
  return listContentKind($lines) ?? (str_starts_with($name, 'ipset') ? 'ip' : 'host');
}

function listContentKind(array $lines): ?string
{
  $lines = array_slice($lines, 0, 200);
  return $lines ? (count(array_filter($lines, 'validCidr')) * 2 > count($lines) ? 'ip' : 'host') : null;
}

// Число записей и вид по содержимому — из кеша, пока у файла те же время и размер: опись списков входит в каждое
// состояние, а читать все списки целиком (бывают и на сотню тысяч строк) на слабом роутере — сотни миллисекунд
function listMeta(string $path): array
{
  $real = is_file($path) ? $path : (is_file("$path.gz") ? "$path.gz" : null);
  if ($real === null) {
    return ['entries' => 0, 'kind' => null];
  }
  return cached('listmeta-' . md5($path), [$real], function () use ($path) {
    $lines = readListLines($path);
    return ['entries' => count($lines), 'kind' => listContentKind($lines)];
  });
}

function listsInventory(array $profiles): array
{
  listUse($profiles);
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
      'kind' => listUse()[$path] ?? listKind($name),
      'exists' => $real !== null,
      'editable' => $real === $path && dirname($path) === LISTS_DIR,
      // стандартные списки nfqws2-keenetic не удаляются и не переименовываются: на них ссылается стандартный конфиг
      // (MODE_LIST, MODE_AUTO, NFQWS_ARGS_IPSET) — без файла nfqws2 после возврата к нему не запустится. Очистить можно
      'removable' => !in_array($name, PROTECTED_LISTS, true) && empty($usage[$path]), 'stock' => in_array($name, PROTECTED_LISTS, true),
      'entries' => $real ? listMeta($path)['entries'] : 0,
      'size' => $real ? filesize($real) : 0,
      'mtime' => $real ? filemtime($real) : null,
      'used' => $usage[$path] ?? [],
      'problems' => $real === $path && !empty($usage[$path]) ? listProblems($path) : ['error' => 0, 'warning' => 0, 'info' => 0],
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
      // единственное, чего не хватило профилю, — записи в его списке сайтов (подбор предлагает её добавить)
      $s['miss'] = $isIp ? null : 'hostlist';
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
// Keenetic, «только политика»: запросы самого роутера nfqws2 не обрабатывает — мониторинг и «Проверить сайт» видели бы
// сайт без обхода. Поэтому проверочные соединения роутера получают метку политики (своя цепочка в mangle OUTPUT, только
// TCP с портов PROBE_PORTS) и идут тем же путём, что устройства из политики: через её подключение и через nfqws2.
// Прошивка время от времени перестраивает таблицы — правило проверяется перед каждой проверкой.
const PROBE_PORTS = '41000:41099';
const PROBE_CHAIN = 'nfqws_ui_out';

function policyProbeEnsure(?array $policy = null, bool $fresh = false): bool
{
  static $done = null;
  if ($done !== null && !$fresh) {
    return $done;
  }
  $policy ??= interceptInfo()['policy'];
  $ipt = 'iptables -w -t mangle ';
  if (($policy['mode'] ?? '') !== 'only' || !preg_match('/^0x[0-9a-f]+$/i', $policy['mark'])) {
    policyProbeRemove();
    return $done = false;
  }
  $rule = '-p tcp --sport ' . PROBE_PORTS . ' -j MARK --set-xmark ' . $policy['mark'] . '/0x0fffffff';
  // быстрый путь: правило уже видно в прочитанной таблице — без четырёх запусков iptables -C (на mipsel это заметно)
  $all = mangleRules();
  $mine = preg_grep('/^-A ' . PROBE_CHAIN . ' /', $all);
  if (count($mine) === 1 && preg_grep('/^-A OUTPUT .*-j ' . PROBE_CHAIN . '\b/', $all)
    && preg_match('/--sport ' . preg_quote(PROBE_PORTS, '/') . ' .*--set-xmark (0x[0-9a-f]+)\/0x0?fffffff\b/i', reset($mine), $x)
    && hexdec($x[1]) === hexdec($policy['mark'])) {
    return $done = true;
  }
  $ok = fn(string $c): bool => exec($ipt . $c . ' 2>/dev/null', $o, $rc) !== null && $rc === 0;
  if (!$ok('-C ' . PROBE_CHAIN . ' ' . $rule)) {
    exec($ipt . '-N ' . PROBE_CHAIN . ' 2>/dev/null');
    exec($ipt . '-F ' . PROBE_CHAIN . ' 2>/dev/null');
    exec($ipt . '-A ' . PROBE_CHAIN . ' ' . $rule . ' 2>/dev/null');
  }
  if (!$ok('-C OUTPUT -j ' . PROBE_CHAIN)) {
    exec($ipt . '-I OUTPUT 1 -j ' . PROBE_CHAIN . ' 2>/dev/null');
  }
  return $done = $ok('-C ' . PROBE_CHAIN . ' ' . $rule) && $ok('-C OUTPUT -j ' . PROBE_CHAIN);
}

function policyProbeRemove(): void
{
  $ipt = 'iptables -w -t mangle ';
  exec($ipt . '-S ' . PROBE_CHAIN . ' 2>/dev/null', $o, $rc);
  if ($rc !== 0) {
    return;   // цепочки нет
  }
  while (true) {
    exec($ipt . '-D OUTPUT -j ' . PROBE_CHAIN . ' 2>/dev/null', $o, $rc);
    if ($rc !== 0) {
      break;
    }
  }
  exec($ipt . '-F ' . PROBE_CHAIN . ' 2>/dev/null; ' . $ipt . '-X ' . PROBE_CHAIN . ' 2>/dev/null');
}

// Параметры curl для запроса «как от устройства политики»: порт источника из PROBE_PORTS
function policyProbeOpts(): array
{
  if (!policyProbeEnsure()) {
    return [];
  }
  [$lo, $hi] = array_map('intval', explode(':', PROBE_PORTS));
  $from = mt_rand($lo, $hi - 20);
  return [CURLOPT_LOCALPORT => $from, CURLOPT_LOCALPORTRANGE => $hi - $from + 1];
}

function volumeFrozen(string $url, array $resolve = []): bool
{
  $ch = curl_init($url . (str_contains($url, '?') ? '&' : '?') . 't=' . mt_rand());
  curl_setopt_array($ch, [
    CURLOPT_RESOLVE => $resolve,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => random_bytes(VOLUME_BYTES),   // случайные данные — чтобы не сжимались
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    CURLOPT_HTTPHEADER => ['Expect:', 'Content-Type: application/octet-stream'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_NOSIGNAL => 1,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130 Safari/537.36',
  ] + policyProbeOpts());
  curl_exec($ch);
  // рукопожатие прошло (APPCONNECT), а ответа на 64 КБ нет. Если TLS не установился, CONNECT_TIME тоже 0 —
  // по нему «TCP или TLS» не различить
  $frozen = curl_errno($ch) === 28 && curl_getinfo($ch, CURLINFO_APPCONNECT_TIME) > 0;
  curl_close($ch);
  return $frozen;
}

// Каким профилем пойдёт сайт по HTTPS, QUIC и HTTP при этих адресах
function checkRoutes(array $profiles, string $host, array $ips, ListCache $cache): array
{
  return [
    'https' => matchRoute($profiles, $host, $ips, 'tcp', 443, 'tls', $cache),
    'quic' => matchRoute($profiles, $host, $ips, 'udp', 443, 'quic', $cache),
    'http' => matchRoute($profiles, $host, $ips, 'tcp', 80, 'http', $cache),
  ];
}

// ---------- исключение из профиля: сайт не заблокирован, а профиль его ломает ----------
// Общий профиль (например, «весь HTTPS») обрабатывает и сайты, которые провайдер не трогает; у части из них фейки
// ломают соединение. «Проверить сайт» после неудачи открывает сайт мимо nfqws2 и, если он открылся, предлагает
// исключить его из профиля: по имени, домену, адресу или всей сети (ASN — для игр, которые ходят по адресу).

const EXCLUDE_IP_LIST = 'ipset_exclude_ui.list';

// Мимо nfqws2, по первому IPv4-адресу. null — проверить нельзя (идёт подбор, нет адреса)
function probeDirect(string $host, array $ips): ?array
{
  $ip = array_values(array_filter($ips, fn($x) => isIp4($x) && !ipReserved($x)))[0] ?? null;
  if (!$ip || testRunning()) {
    return null;
  }
  $r = diagBypass(fn() => diagDirect($host, $ip));
  return ['ok' => $r['ok'] && !$r['isp_page'], 'code' => $r['code'], 'ms' => $r['ms'], 'kind' => $r['kind'], 'ip' => $ip];
}

function profileByIndex(int $index): array
{
  foreach (currentProfiles() as $p) {
    if ($p['index'] === $index) {
      return $p;
    }
  }
  fail("Профиля #$index нет — обновите страницу");
}

// Список, который можно править из интерфейса (лежит в папке списков)
function ownList(string $path): bool
{
  return dirname($path) === LISTS_DIR && preg_match('/^[A-Za-z0-9_.-]+\.list$/', basename($path));
}

function regDomain(string $host): ?string
{
  $l = explode('.', $host);
  return count($l) > 2 ? implode('.', array_slice($l, -2)) : null;
}

// Что можно исключить и что для этого изменится. Каждый вариант: mode, title, hint, files [{name, add|remove}], token
function excludeOptions(string $host, int $index): array
{
  if (!validHost($host)) {
    fail('Некорректное имя сайта');
  }
  $p = profileByIndex($index);
  if (!$p['source']) {
    fail('Запущенный nfqws2 не совпадает с конфигом — перезапустите его и проверьте сайт ещё раз');
  }
  $isIp = isIp($host);
  $ips = $isIp ? [$host] : array_values(array_filter(gethostbynamel($host) ?: [], 'isIp4'));
  $cache = new ListCache();
  $opts = [];
  // Имена: в первый свой список исключений профиля; если его нет — exclude.list и строка в профиле
  if (!$isIp) {
    $exPath = array_values(array_filter($p['hostlist_excludes'], 'ownList'))[0] ?? null;
    $token = $exPath ? null : '--hostlist-exclude=' . LISTS_DIR . '/exclude.list';
    $exName = basename($exPath ?? 'exclude.list');
    // сайт попал в профиль из его списка сайтов — проще убрать запись
    $hh = hostIn($host, $p['hostlists'], [], $cache);
    if ($hh && $hh['entry'] === $host && ownList(LISTS_DIR . '/' . $hh['list']) && in_array(LISTS_DIR . '/' . $hh['list'], $p['hostlists'], true)) {
      $also = array_map(fn($x) => '#' . $x['index'], array_filter(currentProfiles(), fn($x) => $x['index'] !== $index && in_array(LISTS_DIR . '/' . $hh['list'], $x['hostlists'], true)));
      $opts[] = ['mode' => 'unlist', 'title' => "Убрать $host из {$hh['list']}", 'hint' => 'Сайт попадает в профиль из этого списка — запись не нужна.'
        . ($also ? ' Список подключён и к ' . (count($also) > 1 ? 'профилям ' : 'профилю ') . implode(', ', $also) . ' — там сайта тоже не станет.' : ''),
        'files' => [['name' => $hh['list'], 'remove' => [$host]]], 'token' => null];
    }
    $opts[] = ['mode' => 'name', 'title' => "Только $host", 'hint' => "Имя в $exName. Другие сайты" . (regDomain($host) ? ' ' . regDomain($host) : '') . ' остаются в профиле.',
      'files' => [['name' => $exName, 'add' => [$host]]], 'token' => $token];
    if ($d = regDomain($host)) {
      $opts[] = ['mode' => 'domain', 'title' => "Весь $d", 'hint' => 'Вместе со всеми поддоменами.', 'files' => [['name' => $exName, 'add' => [$d]]], 'token' => $token];
    }
  }
  // Адреса: общий список исключений интерфейса; профиль подключает его через --ipset-exclude
  $ipPath = LISTS_DIR . '/' . EXCLUDE_IP_LIST;
  $ipToken = in_array($ipPath, $p['ipset_excludes'], true) ? null : "--ipset-exclude=$ipPath";
  if ($ips) {
    $shown = array_slice($ips, 0, 8);
    $opts[] = ['mode' => 'ip', 'title' => 'По адресу ' . implode(', ', array_slice($shown, 0, 3)) . (count($shown) > 3 ? ' и ещё ' . (count($shown) - 3) : ''),
      'hint' => 'Для программ и игр, которые подключаются по адресу, без имени.', 'files' => [['name' => EXCLUDE_IP_LIST, 'add' => $shown]], 'token' => $ipToken];
    $net = ripe('network-info', $ips[0], 8);
    $asn = (int)($net['asns'][0] ?? 0);
    if ($asn) {
      $holder = (string)(ripe('as-overview', "AS$asn", 8)['holder'] ?? '');
      $asPath = LISTS_DIR . "/ipset_as$asn.list";
      $opts[] = ['mode' => 'asn', 'asn' => $asn, 'title' => "Вся сеть AS$asn" . ($holder !== '' ? " ($holder)" : ''),
        'hint' => 'Если у сервера много адресов. Широко: мимо профиля пойдёт всё в этой сети. Список префиксов обновляется раз в сутки.',
        'files' => [['name' => "ipset_as$asn.list", 'add' => ['префиксы AS' . $asn . ' из RIPEstat']]],
        'token' => in_array($asPath, $p['ipset_excludes'], true) ? null : "--ipset-exclude=$asPath"];
    }
  }
  return ['host' => $host, 'profile' => $index, 'name' => $p['name'], 'options' => $opts];
}

// Вставляет аргумент в профиль: в свою часть NFQWS_ARGS_CUSTOM — перед первым --payload/--lua-desync (рядом с фильтрами),
// в составной профиль — в конец переменной, которая есть только у него (NFQWS_ARGS_IPSET, NFQWS_EXTRA_ARGS)
function profileAddToken(string $text, array $p, string $tok): string
{
  $src = $p['source'];
  $var = $src['source'];
  if ($var !== 'NFQWS_ARGS_CUSTOM') {
    return appendTokenInText($text, $src['with'] ?? $var, $tok);
  }
  $toks = tokens(confRawVars($text)[$var] ?? '');
  $part = 1;
  $at = null;
  $last = null;
  foreach ($toks as $i => $t) {
    if (isNewArg($t)) {
      $part++;
      continue;
    }
    if ($part !== $src['part']) {
      continue;
    }
    $last = $i;
    if ($at === null && in_array(optName($t), ['payload', 'lua-desync'], true)) {
      $at = $i;
    }
  }
  if ($last === null) {
    fail('Профиль в конфиге не найден — обновите страницу');
  }
  // отступ строки, где стоит аргумент: новая строка выглядит так же, как соседние
  preg_match('/^' . $var . '="([^"]*)"/m', $text, $m, PREG_OFFSET_CAPTURE);
  preg_match_all('/\S+/', preg_replace('/\\\\(?=[ \t]*\r?\n)/', ' ', $m[1][0]), $tm, PREG_OFFSET_CAPTURE);
  $i = $at ?? $last;
  $before = substr($m[1][0], 0, $tm[0][$i][1]);
  $nl = strrpos($before, "\n");
  $indent = $nl === false ? '' : substr($before, $nl + 1);
  $sep = $nl === false || trim($indent) !== '' ? ' ' : "\n" . $indent;
  $orig = $toks[$i];
  return editTokenInText($text, $var, $i, $at !== null ? $tok . $sep . $orig : $orig . $sep . $tok);
}

function excludeApply(string $host, int $index, string $mode): array
{
  $all = excludeOptions($host, $index);
  $o = array_values(array_filter($all['options'], fn($x) => $x['mode'] === $mode))[0] ?? null;
  if (!$o) {
    fail('Такого варианта нет — обновите страницу');
  }
  $note = "исключение из профиля #$index: " . $o['title'];
  $files = [];
  if ($mode === 'asn') {
    $a = asnFetch($o['asn']);
    if (!$a['prefixes']) {
      fail("У AS{$o['asn']} нет анонсированных префиксов");
    }
    $files[] = asnWrite($a, true)['name'];
  } else {
    foreach ($o['files'] as $f) {
      $path = LISTS_DIR . '/' . $f['name'];
      $text = is_file($path) ? rtrim((string)file_get_contents($path), "\n") : ($f['name'] === EXCLUDE_IP_LIST ? '# Исключены из профилей кнопкой «Исключить» (nfqws2-ui): сайт не заблокирован, а профиль его ломает' : '');
      $lines = $text === '' ? [] : explode("\n", $text);
      $has = array_map(fn($l) => strtolower(trim($l)), $lines);
      if (!empty($f['remove'])) {
        $lines = array_values(array_filter($lines, fn($l) => !in_array(strtolower(trim($l)), $f['remove'], true)));
      }
      foreach ($f['add'] ?? [] as $x) {
        if (!in_array(strtolower($x), $has, true)) {
          $lines[] = $x;
        }
      }
      if (!writeWithBackup($path, implode("\n", $lines) . "\n", $note)) {
        fail("Не удалось записать {$f['name']}", 500);
      }
      $files[] = $f['name'];
    }
  }
  if ($o['token']) {
    $new = profileAddToken((string)file_get_contents(CONF_FILE), profileByIndex($index), $o['token']);
    $lint = lintConf(confRawVars($new), $new);
    if (!$lint['dry_run'] || !$lint['dry_run']['ok']) {
      fail('nfqws2 не принимает конфиг с исключением: ' . ($lint['dry_run']['message'] ?? 'ошибка'), 422);
    }
    if (!writeWithBackup(CONF_FILE, $new, $note)) {
      fail('Не удалось записать конфиг', 500);
    }
    $files[] = 'nfqws2.conf';
  }
  return ['files' => $files, 'title' => $o['title'], 'token' => $o['token']];
}

// $ip — открыть по этому адресу, а не по ответу DNS роутера (проверка «по настоящему адресу» при подмене DNS)
function probe(string $host, ?string $ip = null): array
{
  $ch = curl_init('https://' . $host . '/');
  $code = null;
  $got = 0;
  $resolve = $ip !== null ? ["$host:443:$ip"] : [];
  curl_setopt_array($ch, [
    CURLOPT_RESOLVE => $resolve,
    // по IPv4, как подбор и диагноз: маршрут через профили считается для IPv4, а при IPv6 от провайдера запрос ушёл бы
    // по нему — мимо стратегии, если nfqws2 не настроен на IPv6 (в подборе сайт открывается, здесь — «тайм-аут»)
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
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
  ] + policyProbeOpts());
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
    $frozen = $got < VOLUME_SMALL && volumeFrozen('https://' . $host . '/', $resolve);
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
// $keep — сохранять ли результат: неполный (программа не успела ответить) не кешируется, иначе он остаётся до
// следующего обновления
function cached(string $key, array $deps, callable $fn, ?callable $keep = null)
{
  // filemtime(__FILE__): после обновления программы прежние результаты проверок не годятся — иначе исправленная
  // проверка продолжала бы показывать старую ошибку, пока не изменится конфиг
  $sig = CACHE_VER . '.' . filemtime(__FILE__) . md5(json_encode(array_map(fn($f) => file_exists($f) ? [$f, filemtime($f), is_file($f) ? filesize($f) : 0] : $f, $deps)));
  $file = CACHE_DIR . '/' . preg_replace('/[^a-z0-9_.-]/i', '_', $key) . '.json';
  if (is_file($file)) {
    $c = json_decode(file_get_contents($file), true);
    if (($c['sig'] ?? '') === $sig) {
      return $c['data'];
    }
  }
  $data = $fn();
  if ($keep && !$keep($data)) {
    return $data;
  }
  @mkdir(CACHE_DIR, 0700, true);
  file_put_contents($file, json_encode(['sig' => $sig, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  return $data;
}

// Запускается ли программа curl: подбор, проба, диагноз и проверка на других сайтах делают запросы ею (PHP-модуль curl —
// только в мониторинге и проверке сайта). Без неё каждая проверка кончается кодом 127, и подбор выдавал это за
// блокировку. null — работает, иначе — что ответила система. Пакет зависит от curl, но его могли удалить, а 127 бывает
// и у установленного curl, которому не хватает библиотеки
function curlCmdError(): ?string
{
  $bins = ['/usr/bin/curl', '/opt/bin/curl', '/usr/lib/libcurl.so.4', '/opt/lib/libcurl.so.4'];
  return cached('curl-cmd', $bins, function () {
    [$rc, $out] = runTimeout(['sh', '-c', 'PATH=' . JOB_PATH . ' exec curl --version'], 5);
    if ($rc === 0) {
      return null;
    }
    $line = trim(strtok(trim($out), "\n") ?: '');
    return $line !== '' ? $line : "код $rc";
  });
}

// «не установлена» — системе не найти команду; иначе curl есть, но не запускается (обычно не хватает библиотеки)
function curlMissing(string $err): bool
{
  return (bool)preg_match('/not found|No such file|не найден/i', $err);
}

function curlCmdHint(string $err): string
{
  return (curlMissing($err) ? 'Программа curl не установлена' : 'Программа curl установлена, но не запускается')
    . ' — без неё подбор и проверки не могут открыть ни одного сайта (система ответила: «' . $err . '»). '
    . 'Нажмите «Установить curl» в «Проблемах» на «Обзоре» или выполните на роутере: '
    // установлен, но не запускается — простой install ответит «up to date», нужна переустановка вместе с библиотекой
    . (pkgApk() ? (curlMissing($err) ? 'apk add curl' : 'apk fix curl libcurl') : (curlMissing($err) ? 'opkg install curl' : 'opkg install --force-reinstall curl libcurl')) . '.';
}

function pkgApk(): bool
{
  return !ROOT && is_file('/usr/bin/apk') && !is_file('/bin/opkg');
}

// Ставит (или переставляет, если curl есть, но не запускается) curl. Не дольше ~2,5 минуты: wget внутри opkg ждёт
// недоступный источник пакетов бесконечно — по истечении времени процесс снимается вместе с потомками
function curlInstall(): array
{
  set_time_limit(200);
  $err = curlCmdError();
  if ($err === null) {
    return ['ok' => true, 'log' => 'curl уже работает'];
  }
  $tree = 'kt() { k=$(awk -v p="$1" \'$4 == p { print $1 }\' /proc/[0-9]*/stat 2>/dev/null); kill "$1" 2>/dev/null; for c in $k; do kt "$c"; done; }; ';
  $lim = fn(int $s, string $cmd) => '( ' . $cmd . ' ) & p=$!; i=0; while kill -0 $p 2>/dev/null; do '
    . 'if [ $i -ge ' . $s . ' ]; then kt $p; exit 124; fi; sleep 1; i=$((i+1)); done; wait $p';
  if (pkgApk()) {
    $cmd = $lim(150, 'apk add curl && apk fix curl libcurl');
  } else {
    // сначала без обновления списка: он почти всегда уже есть (пакеты ставились), а opkg update — самое долгое место;
    // если curl в списке нет — обновить список и повторить. --force-reinstall: пакет может числиться установленным,
    // а файла нет (удалили вручную) — тогда простой install ответил бы «уже установлен» и ничего не вернул
    $inst = 'opkg install --force-reinstall curl libcurl';
    // в команде только латиница: escapeshellarg при локали C выбрасывает кириллицу
    $cmd = $lim(150, $inst . ' || { opkg update >/dev/null 2>&1; ' . $inst . '; }');
  }
  [$rc, $out] = runTimeout(['sh', '-c', 'PATH=' . JOB_PATH . '; ' . $tree . $cmd], 190);
  @unlink(CACHE_DIR . '/curl-cmd.json');
  $after = curlCmdError();
  $log = trim(implode("\n", array_slice(explode("\n", trim($out)), -15)));
  if ($after === null) {
    return ['ok' => true, 'log' => $log];
  }
  $why = $rc === 124 ? 'источник пакетов не ответил за 2,5 минуты, curl не скачался. ' . (ROOT
      ? "Сервер Entware (bin.entware.net) бывает недоступен — перейдите на зеркало и повторите: sed -i 's#http://bin.entware.net/#http://mirrors.bfsu.edu.cn/entware/#' /opt/etc/opkg.conf"
      : 'Проверьте, что роутер достаёт до источника пакетов, и повторите.')
    : 'curl всё ещё не запускается (' . $after . ').';
  return ['ok' => false, 'error' => $why, 'log' => $log, 'code' => $rc];
}

// Модуль curl для PHP (php8-mod-curl): через него идут DoH, RIPEstat, GitHub, Telegram, база сообщества.
// Без него каждый такой запрос падал фатальной ошибкой «Call to undefined function curl_init()», а страница
// показывала «Cannot read properties of undefined (reading 'filter')». null — модуль работает, иначе — что ответил PHP
function phpCurlError(): ?string
{
  if (function_exists('curl_init')) {
    return null;
  }
  // почему не загрузился: PHP пишет это при старте (нет библиотеки, другая версия), а без display_startup_errors молчит
  $bin = PHP_BINARY !== '' && is_executable(PHP_BINARY) ? PHP_BINARY : 'php-cgi';
  [, $out] = runTimeout(['sh', '-c', 'exec env -i PATH=' . JOB_PATH . ' ' . escapeshellarg($bin) . ' -d display_startup_errors=1 -d display_errors=1 -m 2>&1'], 5);
  foreach (explode("\n", strip_tags($out)) as $l) {
    if (stripos($l, 'curl') !== false && preg_match('/warning|error|unable|cannot/i', $l)) {
      return trim(preg_replace('/\s+/', ' ', $l));
    }
  }
  return 'модуль curl для PHP не загружен';
}

function phpCurlHint(string $err): string
{
  if (phpCurlOld($err)) {
    return 'Модуль curl для PHP собран под более новую версию PHP, чем стоит на роутере, и не загружается — без него диагноз, подбор, '
      . 'мониторинг и проверка обновлений не работают (PHP ответил: «' . $err . '»). Нажмите «Обновить PHP» в «Проблемах» на «Обзоре» '
      . 'или выполните на роутере: ' . (pkgApk() ? PHP_UPGRADE_APK : PHP_UPGRADE_OPKG) . '.';
  }
  return 'В PHP нет модуля curl (php8-mod-curl) — без него диагноз, подбор, мониторинг и проверка обновлений не работают '
    . '(PHP ответил: «' . $err . '»). Нажмите «Установить модуль curl» в «Проблемах» на «Обзоре» или выполните на роутере: '
    . (pkgApk() ? 'apk add php8-mod-curl' : 'opkg install --force-reinstall php8-mod-curl libcurl') . '.';
}

// Модуль собран под более новый PHP, чем стоит на роутере («undefined symbol …»): так бывает, когда модуль поставили из
// свежего источника, а сам PHP не обновляли. Переустановка модуля тут не помогает — обновлять нужно PHP со всеми модулями
// и библиотеки, которые он тянет (у Entware opkg upgrade php8 их сам не обновляет)
const PHP_UPGRADE_OPKG = 'opkg update >/dev/null 2>&1; P=$(opkg list-upgradable | awk \'/^(php8|libpcre2|libxml2)/{print $1}\'); '
  . '[ -n "$P" ] || { echo \'в источнике пакетов нет более новой версии PHP\'; exit 3; }; opkg upgrade $P && opkg install php8-mod-curl libcurl';
const PHP_UPGRADE_APK = 'apk update >/dev/null 2>&1; apk add --upgrade php8-mod-curl libcurl $(apk info 2>/dev/null | grep -E \'^(php8|libpcre2|libxml2)\')';

function phpCurlOld(?string $err): bool
{
  return $err !== null && stripos($err, 'undefined symbol') !== false;
}

// Ставит (или переставляет) модуль curl для PHP. Новый php-cgi запускается на каждый запрос — перезапуск lighttpd не нужен
function phpCurlInstall(): array
{
  set_time_limit(200);
  $cmd = phpCurlOld(phpCurlError()) ? (pkgApk() ? PHP_UPGRADE_APK : PHP_UPGRADE_OPKG)
    : (pkgApk() ? 'apk add php8-mod-curl && apk fix php8-mod-curl libcurl'
    : 'opkg install --force-reinstall php8-mod-curl libcurl || { opkg update >/dev/null 2>&1; opkg install --force-reinstall php8-mod-curl libcurl; }');
  [$rc, $out] = runTimeout(['sh', '-c', 'PATH=' . JOB_PATH . '; ' . $cmd], 190);
  $log = trim(implode("\n", array_slice(explode("\n", trim($out)), -15)));
  // в этом процессе модуль уже не появится — проверяем новым php-cgi
  $bin = PHP_BINARY !== '' && is_executable(PHP_BINARY) ? PHP_BINARY : 'php-cgi';
  $ok = (bool)preg_match('/^curl$/mi', (string)runTimeout(['sh', '-c', 'exec env -i PATH=' . JOB_PATH . ' ' . escapeshellarg($bin) . ' -m 2>/dev/null'], 5)[1]);
  return $ok ? ['ok' => true, 'log' => $log]
    : ['ok' => false, 'error' => $rc === 124 ? 'источник пакетов не ответил' : ($rc === 3 ? 'в источнике пакетов нет более новой версии PHP' : 'модуль curl всё ещё не загружается'), 'log' => $log, 'code' => $rc];
}

function curlRequire(): void
{
  if (($e = phpCurlError()) !== null) {
    fail(phpCurlHint($e));
  }
  if (($e = curlCmdError()) !== null) {
    fail(curlCmdHint($e));
  }
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
  static $info = null;
  return $info ??= cached('help', [NFQWS_BIN], function () {
    $info = helpParse(runTimeout([NFQWS_BIN, '--help'], 5)[1]);
    if (!$info['full']) {
      // на слабом роутере под нагрузкой nfqws2 не всегда успевает за 5 с — тогда справка пустая или обрезана,
      // и все параметры конфига выглядели
      // бы неизвестными
      $info = helpParse(runTimeout([NFQWS_BIN, '--help'], 15)[1]);
    }
    return $info;
  }, fn($d) => $d['full']);
}

function helpParse(string $out): array
{
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
  // полная справка — есть параметры из всех разделов; без неё названия параметров не проверяются
  $full = !array_diff(['qnum', 'blob', 'lua-init', 'new', 'filter-tcp', 'payload', 'lua-desync'], array_keys($opts));
  return ['options' => $opts, 'l7' => $l7, 'payloads' => $payloads, 'full' => $full];
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
          $f = ['file' => $base, 'doc' => [], 'std' => [], 'args' => [], 'required' => [], 'nfqws1' => null, 'payload_def' => null];
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
            // Какую нагрузку функция берёт без своего payload=: payload_check(desync) — только известную (known)
            if ($f['payload_def'] === null && preg_match('/payload_check\(desync\s*(?:,\s*"([a-z_,]+)")?\)/', $lines[$j], $x)) {
              $f['payload_def'] = ($x[1] ?? '') ?: 'known';
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

  // Переменная присвоена несколько раз (VAR="$VAR --new …"): nfqws2 получит всё, а интерфейс видит и меняет
  // только последнее присваивание — кнопки, меняющие её, откажут, чтобы не потерять остальные части
  foreach (ARG_VARS as $var) {
    if (preg_match_all('/^[ \t]*' . $var . '=/m', $text) > 1) {
      $add($var, null, 'warning', "$var присвоена в конфиге несколько раз ($var=\"\$$var …\"). nfqws2 получит все части, но интерфейс видит только последнюю: "
        . 'профили из остальных частей в конструкторе не показываются, а «Применить», «Добавить профиль» и правки этой переменной откажут. Соберите всё в одно присваивание, профили — через --new.');
    }
  }

  $ad = adaptConf($text);
  if ($ad['changes']) {
    $msg = $ad['from']
      ? 'Похоже, конфиг от ' . implode(' / ', $ad['from']) . ', а здесь ' . platformName() . ' — пути и настройки нужно поправить'
      : ($ad['added'] && count($ad['changes']) === 1 ? 'В конфиге не хватает переменных: ' . implode(', ', $ad['added']) : 'Конфиг не подходит для этого роутера');
    $add(null, null, $ad['from'] ? 'error' : 'warning', $msg, ['op' => 'adapt', 'label' => 'Исправить под ' . platformName(), 'changes' => $ad['changes']]);
  }

  $help = helpInfo();
  $opts = $help['options'];
  if (!$help['full']) {
    $add(null, null, 'warning', 'nfqws2 не ответил на запрос справки (nfqws2 --help) — названия параметров не проверены. Откройте страницу ещё раз через минуту');
  }
  $lua = luaCatalog();
  $funcs = $lua['functions'];

  // Что объявлено в параметрах запуска: загруженные lua-файлы и блобы
  $loadedLua = [];
  $blobs = [];
  foreach (tokens($exp['NFQWS_BASE_ARGS']) as $t) {
    if (preg_match('/^--lua-init=@(.+)$/', $t, $m)) {
      $loadedLua[basename($m[1])] = true;
    } elseif (preg_match('/^--blob=([^:]+):(.*)$/', $t, $m)) {
      // значение — откуда блоб берётся (@файл или 0xHEX): по нему видно, TLS ли это
      $blobs[$m[1]] = $m[2];
    }
  }
  // tls_client_hello_clone:blob=ИМЯ сам создаёт блоб — копию приветствия устройства (для fake:blob=ИМЯ в том же профиле)
  foreach (ARG_VARS as $v) {
    foreach (tokens($exp[$v] ?? '') as $t) {
      if (preg_match('/^--lua-desync=tls_client_hello_clone:(?:.*:)?blob=([^:]+)/', $t, $m)) {
        $blobs[$m[1]] ??= 'clone';
      }
    }
  }

  if (trim($exp['ISP_INTERFACE']) === '') {
    $add('ISP_INTERFACE', null, 'error', 'Не задан интерфейс провайдера');
  }

  $comments = 0;
  foreach (ARG_VARS as $var) {
    $toks = tokens($raw[$var] ?? '');
    // Комментарий внутри кавычек (так написан стандартный конфиг Keenetic): от «#» до конца строки.
    // nfqws2 получает эти слова как лишние аргументы и пропускает их — одно замечание на весь конфиг
    $comment = commentTokens($raw[$var] ?? '');
    $comments += count(array_filter($comment));
    $pic = false;   // в этом профиле уже был per_instance_condition — у следующих шагов законны cond / cond_neg
    foreach ($toks as $i => $t) {
      if (isset($comment[$i])) {
        continue;
      }
      if (str_starts_with($t, '--dpi-desync')) {
        $add(null, null, 'error', 'Похоже, это стратегии nfqws первой версии (--dpi-desync…) — nfqws2 их не понимает, их нужно переписать на --lua-desync');
      }
      if (preg_match('/^\$\{?' . $var . '\}?$/', $t)) {
        continue;   // VAR="$VAR …" — об этом отдельное замечание «присвоена несколько раз»
      }
      if (!str_starts_with($t, '--')) {
        $add($var, $i, 'error', 'Ожидается параметр вида --имя=значение');
        continue;
      }
      $name = substr(explode('=', $t, 2)[0], 2);
      $val = str_contains($t, '=') ? substr($t, strpos($t, '=') + 1) : null;
      if (($name === 'new' || $name === 'name') && $val !== null && !preg_match('/^[\p{L}\p{N}_.-]{1,32}$/u', $val)) {
        $add($var, $i, 'warning', "Имя профиля «{$val}»: допустимы буквы, цифры, «_», «-», «.», до 32 знаков — другие знаки в конфиге могут сломать запуск");
      }
      if ($name === 'new') {
        $pic = false;
        if ($var !== 'NFQWS_ARGS_CUSTOM') {
          $add($var, $i, 'error', '--new можно использовать только в «Своих профилях» — init-скрипт не запустится', ['op' => 'remove', 'label' => 'Удалить --new']);
        } elseif ($i === 0 || $i === count($toks) - 1) {
          $add($var, $i, 'warning', '--new в начале или в конце создаёт пустой профиль', ['op' => 'remove', 'label' => 'Удалить лишний --new']);
        }
        continue;
      }
      if (!isset($opts[$name])) {
        if (!$help['full']) {
          continue;   // без справки «неизвестными» оказались бы все параметры
        }
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
          lintDesync($var, $i, $val, $funcs, $lua, $loadedLua, $blobs, $help, $add, $pic);
          $pic = $pic || explode(':', (string)$val, 2)[0] === 'per_instance_condition';
          break;
      }
    }
    lintCircular($var, $toks, $comment, $issues, $add);
  }
  if ($comments) {
    $add(null, null, 'info', ($comments === 1 ? 'Комментарий' : "Комментарии ($comments)") . ' внутри кавычек — nfqws2 получает их слова как лишние аргументы и пропускает. '
      . 'На работу это не влияет (так написан стандартный конфиг Keenetic), но надёжнее держать комментарии над переменной', ['op' => 'remove_comments', 'label' => $comments === 1 ? 'Удалить комментарий' : 'Удалить все']);
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
        if (isNewArg($t)) {
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

    // --payload=unknown у профиля, а функция без своего payload= берёт только известную нагрузку (known):
    // nfqws2 запускается без ошибок, а функция не срабатывает ни разу («does not pass 'known' filter» в журнале)
    $pl = null;
    foreach ($e['tokens'] as $t) {
      if (str_starts_with($t, '--payload=')) {
        $pl = explode(',', substr($t, 10));
        continue;
      }
      if ($pl === null || array_diff($pl, ['unknown', 'empty']) || !str_starts_with($t, '--lua-desync=')) {
        continue;
      }
      $parts = explode(':', substr($t, 13));
      $def = $funcs[$parts[0]]['payload_def'] ?? null;
      if ($def === null || array_intersect(explode(',', $def), ['all', 'unknown', 'empty'])
        || array_filter($parts, fn($p) => str_starts_with($p, 'payload='))) {
        continue;
      }
      $ti = null;
      foreach (tokens($raw[$var] ?? '') as $i => $rt) {
        if ($rt === $t && ($tok === null || $i >= $tok)) {
          $ti = $i;
          break;
        }
      }
      $add($var, $ti ?? $tok, 'warning', "$label: {$parts[0]} не сработает — у профиля --payload=" . implode(',', $pl) . ", а функция без своего payload= берёт только известные протоколы",
        $ti !== null ? ['op' => 'replace', 'to' => "$t:payload=all", 'label' => 'Добавить payload=all'] : null);
    }

    // tls_mod=…dupsid копирует session id из настоящего ClientHello. Профилю приходит только не-TLS нагрузка
    // (игры, свои протоколы) — копировать неоткуда: nfqws2 на каждом соединении пишет в журнал
    // «cannot apply dupsid tls mod. payload is not valid tls», остальное tls_mod при этом работает
    $pl = null;
    foreach ($e['tokens'] as $t) {
      if (str_starts_with($t, '--payload=')) {
        $pl = explode(',', substr($t, 10));
        continue;
      }
      if ($pl === null || array_intersect($pl, ['tls_client_hello', 'known', 'all']) || !str_starts_with($t, '--lua-desync=')
        || !preg_match('/:tls_mod=([^:]*)/', $t, $tm) || !in_array('dupsid', explode(',', $tm[1]), true)) {
        continue;
      }
      $rest = implode(',', array_filter(explode(',', $tm[1]), fn($x) => $x !== 'dupsid'));
      $to = str_replace(':tls_mod=' . $tm[1], $rest === '' ? '' : ':tls_mod=' . $rest, $t);
      $ti = null;
      foreach (tokens($raw[$var] ?? '') as $i => $rt) {
        if ($rt === $t && ($tok === null || $i >= $tok)) {
          $ti = $i;
          break;
        }
      }
      $add($var, $ti ?? $tok, 'info', "$label: dupsid в tls_mod не сработает — профилю приходит только --payload=" . implode(',', $pl)
        . ', а не TLS ClientHello, копировать session id неоткуда. nfqws2 на каждом соединении пишет в журнал «cannot apply dupsid tls mod»; остальное в tls_mod работает',
        $ti !== null ? ['op' => 'replace', 'to' => $to, 'label' => 'Убрать dupsid'] : null);
    }

    // tcp_ts=N — единственная маскировка шага: без временной метки TCP в пакете nfqws2 её пропускает
    // («timestamp tcp option not present»), фейк уходит как настоящий. Подбор идёт с роутера (Linux, метки есть)
    // и этого не видит, а Windows меток по умолчанию не шлёт
    foreach ($e['tokens'] as $t) {
      if (!tsOnlyFooling($t)) {
        continue;
      }
      $ti = null;
      foreach (tokens($raw[$var] ?? '') as $i => $rt) {
        if ($rt === $t && ($tok === null || $i >= $tok)) {
          $ti = $i;
          break;
        }
      }
      $add($var, $ti ?? $tok, 'info', "$label: " . explode(':', substr($t, 13))[0] . ' маскируется только через tcp_ts — это действует на устройствах с временными метками TCP. '
        . 'В Windows они по умолчанию выключены: там такой шаг не сработает, хотя подбор с роутера показывает, что сайт открывается. ' . WIN_TS_HINT);
      break;
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

  // IPv6 от провайдера есть, а nfqws2 его не перехватывает: сайты с IPv6-адресом (YouTube, Cloudflare…) идут мимо обхода
  $v6on = !in_array(strtolower(trim($exp['IPV6_ENABLED'] ?? '')), ['', '0', 'false', 'no'], true);
  if (!$v6on && ($dev = ispIpv6Route(preg_split('/\s+/', trim($exp['ISP_INTERFACE'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)))) {
    $add('IPV6_ENABLED', null, 'warning', "У провайдера есть IPv6 (маршрут через $dev), а IPV6_ENABLED выключен — сайты, которые открываются по IPv6, идут мимо nfqws2, и обход для них не работает",
      ['op' => 'set_var', 'var' => 'IPV6_ENABLED', 'value' => '1', 'label' => 'Включить IPv6']);
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

// Маскировки (fooling) nfqws первого zapret (--dpi-desync-fooling=…) и их запись в nfqws2. Значения — как у nfqws
// по умолчанию: badseq сдвигал seq на -10000 и ack на -66000, ts — метку времени на -600000
const NFQWS1_FOOLING = [
  'md5sig' => 'tcp_md5',
  'badseq' => 'tcp_seq=-10000:tcp_ack=-66000',
  'datanoack' => 'tcp_flags_unset=ack',
  'ts' => 'tcp_ts=-600000',
  'hopbyhop' => 'ip6_hopbyhop',
  'hopbyhop2' => 'ip6_hopbyhop2',
  'destopt' => 'ip6_destopt',
];

function lintDesync(string $var, int $i, string $val, array $funcs, array $lua, array $loadedLua, array $blobs, array $help, callable $add, bool $pic = false): void
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
  $allowed = array_merge(array_keys($f['args']), $f['file_keys'], ['strategy', 'final'], $pic ? ['cond', 'cond_neg'] : []);
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
  $stepKeys = array_map(fn($p) => explode('=', $p, 2)[0], $parts);   // все параметры шага: не предлагать то, что уже есть
  foreach ($parts as $p) {
    [$k, $v] = array_pad(explode('=', $p, 2), 2, null);
    $keys[$k] = $v;
    if ($k === '') {
      $add($var, $i, 'error', 'Пустой параметр — лишнее двоеточие', ['op' => 'remove_param', 'key' => '', 'label' => 'Убрать лишнее двоеточие']);
    } elseif (!in_array($k, $allowed, true)) {
      if ($k === 'cond' || $k === 'cond_neg') {
        $add($var, $i, 'warning', "«{$k}» действует только вместе с per_instance_condition — поставьте его шагом раньше в этом профиле, иначе nfqws2 параметр пропустит", ['op' => 'remove_param', 'key' => $k, 'label' => "Убрать $k"]);
      } elseif (in_array($k, $known, true)) {
        $add($var, $i, 'warning', "У функции {$fn} параметр «{$k}» не описан — возможно, он не действует", ['op' => 'remove_param', 'key' => $k, 'label' => "Убрать $k"]);
      } elseif (isset(NFQWS1_FOOLING[$k]) && $v === null) {
        // название из nfqws первого zapret: заменяем по смыслу, а не на похожее по написанию (badseq ≠ badsum)
        $to = array_values(array_filter(explode(':', NFQWS1_FOOLING[$k]), fn($x) => !in_array(explode('=', $x, 2)[0], $stepKeys, true)));
        $add($var, $i, 'warning', "«{$k}» — название маскировки из nfqws первого zapret, nfqws2 его не знает и пропускает. В nfqws2 это "
          . NFQWS1_FOOLING[$k] . (!$to ? ' — и оно в шаге уже есть' : (count($to) < count(explode(':', NFQWS1_FOOLING[$k])) ? ' (часть уже стоит в шаге)' : '')),
          $to ? ['op' => 'rename_param', 'key' => $k, 'to' => implode(':', $to), 'label' => 'Заменить на ' . implode(':', $to)] : ['op' => 'remove_param', 'key' => $k, 'label' => "Убрать $k"]);
      } else {
        $s = levenshteinBest($k, array_values(array_diff(array_unique($known), $stepKeys)));
        $add($var, $i, 'warning', "Неизвестный параметр «{$k}»" . ($s ? " — может быть, {$s}?" : ''), $s ? ['op' => 'rename_param', 'key' => $k, 'to' => $s, 'label' => "Заменить на $s"] : ['op' => 'remove_param', 'key' => $k, 'label' => "Убрать $k"]);
      }
    }
    if (in_array($k, ['blob', 'seqovl_pattern', 'pattern'], true) && $v !== null && !str_starts_with($v, '0x')
      && !isset($blobs[$v]) && !in_array($v, BUILTIN_BLOBS, true) && !in_array($v, $lua['globals'], true)) {
      $add($var, $i, 'error', "Блоб «{$v}» не объявлен — добавьте --blob={$v}:@файл в параметры запуска", ($bf = blobFiles()) ? ['op' => 'declare_blob', 'name' => $v, 'choices' => $bf, 'label' => 'Объявить блоб из файла'] : null);
    }
    if ($k === 'payload' && $v !== null) {
      foreach (explode(',', $v) as $pl) {
        if ($help['full'] && !in_array($pl, $help['payloads'], true)) {
          $add($var, $i, 'error', "Неизвестный тип payload «{$pl}»");
        }
      }
    }
    if ($k === 'strategy' && !preg_match('/^\d+$/', (string)$v)) {
      $add($var, $i, 'error', 'strategy должен быть числом');
    }
  }
  // tls_mod правит поля TLS ClientHello. Если блоб — не ClientHello (частый случай: blob=0x00000000), nfqws2
  // пишет в журнал «cannot apply tls mod» на каждое соединение и шлёт блоб как есть: параметр не действует
  if (isset($keys['tls_mod'], $keys['blob']) && blobIsTls($keys['blob'], $blobs) === false) {
    $add($var, $i, 'warning', "tls_mod не подействует: блоб «{$keys['blob']}» — не TLS ClientHello. nfqws2 отправит его как есть и на каждое соединение запишет в журнал «cannot apply tls mod»",
      ['op' => 'remove_param', 'key' => 'tls_mod', 'label' => 'Убрать tls_mod']);
  }
  foreach ($f['required'] as $r) {
    if (!array_key_exists($r, $keys)) {
      if ($r === 'blob') {
        // Без blob шаг падает с ошибкой, и nfqws2 пропускает пакет как есть — стратегия по сути ничего не делает.
        // Добавить blob = включить её: первым вариантом пустой фейк, как в стоковом конфиге nfqws2-keenetic
        $choices = array_values(array_unique(array_merge(['0x00000000'], array_keys($blobs), BUILTIN_BLOBS, array_values(array_filter($lua['globals'], fn($g) => preg_match('/^fake_default_/', $g))))));
        $add($var, $i, 'error', "Функции {$fn} нужен параметр «blob». Сейчас шаг на каждом пакете завершается ошибкой, и nfqws2 пропускает пакет без изменений — "
          . 'эта стратегия ничего не делает. С blob она начнёт работать, поэтому после исправления проверьте, что всё открывается (голос, звонки, игры — для UDP)',
          ['op' => 'add_param', 'key' => 'blob', 'choices' => $choices, 'label' => 'Добавить blob']);
      } else {
        $add($var, $i, 'error', "Функции {$fn} нужен параметр «{$r}» — без него она завершится ошибкой на каждом пакете", ['op' => 'add_param', 'key' => $r, 'choices' => [], 'label' => "Добавить $r"]);
      }
    }
  }
}

// Номера токенов-комментариев в значении переменной: от «#» до конца строки.
// Для первого токена комментария — сколько в нём слов, для остальных — 0
function commentTokens(string $val): array
{
  $comment = [];
  $n = 0;
  foreach (explode("\n", $val) as $line) {
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
  return $comment;
}

// Шаг стратегии без strategy=/final= и с параметрами по порядку — чтобы сравнивать стратегии circular
function stepSig(string $tok): string
{
  $parts = explode(':', substr($tok, strlen('--lua-desync=')));
  $fn = array_shift($parts);
  $parts = array_values(array_filter($parts, fn($p) => !in_array(explode('=', $p, 2)[0], ['strategy', 'final'], true)));
  sort($parts);
  return implode(':', array_merge([$fn], $parts));
}

// circular: одинаковые стратегии в одном профиле и варианты «Добавить blob», которые сделали бы стратегию копией соседней
function lintCircular(string $var, array $toks, array $comment, array &$issues, callable $add): void
{
  $parts = [[]];
  foreach ($toks as $i => $t) {
    if (isset($comment[$i])) {
      continue;
    }
    if (isNewArg($t)) {
      $parts[] = [];
      continue;
    }
    $parts[count($parts) - 1][$i] = $t;
  }
  foreach ($parts as $part) {
    if (!array_filter($part, fn($t) => preg_match('/^--lua-desync=circular(:|$)/', $t))) {
      continue;
    }
    $st = [];   // номер стратегии => [токен => шаг]
    foreach ($part as $i => $t) {
      if (str_starts_with($t, '--lua-desync=') && preg_match('/:strategy=(\d+)(:|$)/', $t, $m)) {
        $st[(int)$m[1]][$i] = $t;
      }
    }
    ksort($st);
    $sig = fn(array $steps) => implode(' ', array_map('stepSig', $steps));
    $sigs = array_map($sig, $st);

    // «Добавить blob»: убрать варианты, после которых стратегия совпадёт с другой
    foreach ($issues as &$x) {
      if ($x['var'] !== $var || ($x['fix']['op'] ?? '') !== 'add_param' || ($x['fix']['key'] ?? '') !== 'blob') {
        continue;
      }
      foreach ($st as $n => $steps) {
        if (!isset($steps[$x['tok']])) {
          continue;
        }
        $x['fix']['choices'] = array_values(array_filter($x['fix']['choices'], function ($c) use ($steps, $x, $n, $sigs, $sig) {
          $p = explode(':', $steps[$x['tok']]);
          array_splice($p, 1, 0, "blob=$c");
          $steps[$x['tok']] = implode(':', $p);
          $s = $sig($steps);
          foreach ($sigs as $k => $other) {
            if ($k !== $n && $other === $s) {
              return false;
            }
          }
          return true;
        }));
      }
    }
    unset($x);

    // Одинаковые стратегии: circular переключается между ними без пользы
    $seen = [];
    foreach ($sigs as $n => $s) {
      if (!isset($seen[$s])) {
        $seen[$s] = $n;
        continue;
      }
      $first = $seen[$s];
      $tok = array_key_first($st[$first]);
      $one = $st[$first][$tok];
      $fix = null;
      // Частый случай — стоковый конфиг после «Добавить blob»: у стратегии один fake с тем же блобом, что у соседней.
      // В nfqws2-keenetic у неё blob=0x00000000 (пустой фейк)
      if (count($st[$first]) === 1 && str_starts_with($one, '--lua-desync=fake:') && preg_match('/:blob=([^:]+)/', $one, $m) && $m[1] !== '0x00000000') {
        $fix = ['op' => 'set_param', 'key' => 'blob', 'value' => '0x00000000', 'label' => "Стратегии $first: blob=0x00000000"];
      }
      $add($var, $tok, 'warning', "Стратегии $first и $n в circular одинаковые — переключение между ними ничего не даёт"
        . ($fix ? ". Так бывает после «Добавить blob» на стоковом конфиге: там у стратегии $first пустой фейк blob=0x00000000" : ''), $fix);
    }
  }
}

// Интерфейс провайдера, через который есть маршрут IPv6 по умолчанию, или null. Маршрут через туннель
// (не из ISP_INTERFACE) не считается: такой трафик nfqws2 и не должен видеть.
// $routes — строки «ip -6 route show default» (для проверки без роутера)
function ispIpv6Route(array $ifaces, ?array $routes = null): ?string
{
  if (!$ifaces) {
    return null;
  }
  if ($routes === null) {
    $routes = [];
    exec('ip -6 route show default 2>/dev/null', $routes);
  }
  foreach ($routes as $r) {
    if (preg_match('/^default\b.*\bdev\s+(\S+)/', trim($r), $m) && in_array($m[1], $ifaces, true)) {
      return $m[1];
    }
  }
  return null;
}

// Похож ли блоб на TLS ClientHello: запись handshake (0x16), версия 3.x, сообщение ClientHello (0x01).
// $ref — значение blob=…: 0xHEX, имя объявленного (--blob=имя:@файл|0xHEX) или встроенного блоба.
// null — узнать нельзя (блоб из lua, файла нет): тогда не предупреждаем
function blobIsTls(string $ref, array $blobs): ?bool
{
  if (in_array($ref, BUILTIN_BLOBS, true)) {
    return $ref === 'fake_default_tls';
  }
  $src = str_starts_with($ref, '0x') ? $ref : ($blobs[$ref] ?? null);
  if (!is_string($src)) {
    return null;
  }
  if ($src === 'clone') {
    return true;   // копия настоящего ClientHello
  }
  if (str_starts_with($src, '0x')) {
    $hex = substr($src, 2);
    $head = strlen($hex) % 2 === 0 && preg_match('/^[0-9a-fA-F]*$/', $hex) ? (string)hex2bin(substr($hex, 0, 12)) : null;
  } elseif (str_starts_with($src, '@') && is_file(substr($src, 1))) {
    $head = (string)file_get_contents(substr($src, 1), false, null, 0, 6);
  } else {
    return null;
  }
  if ($head === null) {
    return null;
  }
  return strlen($head) >= 6 && $head[0] === "\x16" && $head[1] === "\x03" && $head[5] === "\x01";
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
  }, fn() => helpInfo()['full']);
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
  $isIp = listKind($name, readListLinesFromText($content)) === 'ip';
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
  // вид списка зависит и от того, как его подключает конфиг
  return cached('listlint-' . basename($path), [$path, CONF_FILE], function () use ($path) {
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
  // «\» перед переводом строки — перенос, а не аргумент (см. tokens()); длина не меняется — смещения те же
  preg_match_all('/\S+/', preg_replace('/\\\\(?=[ \t]*\r?\n)/', ' ', $m[1][0]), $tm, PREG_OFFSET_CAPTURE);
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
  if ($f['op'] === 'remove_comments') {
    // все комментарии внутри кавычек; с конца, чтобы номера оставшихся токенов не сдвигались
    foreach (ARG_VARS as $v) {
      foreach (array_reverse(array_keys(commentTokens(confRawVars($text)[$v] ?? '')), false) as $k) {
        $text = editTokenInText($text, $v, $k, null);
      }
    }
    return $text;
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
    case 'set_param':
    case 'add_param':
      [$name, $val] = array_pad(explode('=', $t, 2), 2, '');
      $parts = explode(':', $val);
      $fn = array_shift($parts);
      if ($f['op'] === 'set_param') {
        $parts = array_map(fn($p) => explode('=', $p, 2)[0] === $f['key'] ? "{$f['key']}={$f['value']}" : $p, $parts);
      } elseif ($f['op'] === 'remove_param') {
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
  // в том же запросе historyScan('auto') записывает правки, сделанные мимо интерфейса, —
  // их не отменяем (иначе список, созданный по ssh, удалился бы целиком)
  $events = array_values(array_filter($log, fn($e) => ($e['batch'] ?? '') === $batch && !empty($e['path']) && ($e['source'] ?? '') === 'интерфейс'));
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

// То же для отката к рабочему состоянию, но без автосписков (--hostlist-auto): их пополняет сам nfqws2, и откат
// стирал бы сайты, которые он добавил после рабочего состояния
function rollbackVersions(int $T): array
{
  $auto = array_filter(array_map(fn($p) => $p['autolist'], currentProfiles()));
  return array_diff_key(versionsAt($T), array_flip($auto));
}

// «Как при установке»: версии конфига и списков на момент, когда интерфейс начал вести историю (обычно — установка).
// Списки, созданные позже, не трогаем: конфиг перестанет на них ссылаться, а свои списки терять жалко.
// null — истории нет. at — время начала истории
function initialVersions(): ?array
{
  $log = histLog();
  if (!$log) {
    return null;
  }
  $T = null;
  foreach ($log as $e) {
    if (($e['file'] ?? '') === '*' && ($e['note'] ?? '') === 'История изменений начата') {
      $T = (int)$e['ts'];
      break;
    }
  }
  $T ??= (int)$log[0]['ts'] - 1;
  $v = array_filter(versionsAt($T), fn($h, $path) => $h !== null && (!is_file($path) || md5_file($path) !== $h), ARRAY_FILTER_USE_BOTH);
  return ['at' => $T, 'versions' => $v];
}

function pendingGet(): ?array
{
  $p = json_decode((string)@file_get_contents(PENDING_FILE), true);
  return is_array($p) ? $p : null;
}

function pendingSave(array $p): void
{
  putSafe(PENDING_FILE, json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function doRollback(array &$p, string $reason): void
{
  $changed = restoreVersions($p['versions'], 'откат к рабочему состоянию: ' . $reason);
  serviceRun('restart');
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
  // проверка сайтов идёт десятки секунд: если за это время откатили или подтвердили, состояние уже не наше
  $ours = function () use ($p): bool {
    $cur = pendingGet();
    return $cur && ($cur['id'] ?? '') === $p['id'] && $cur['state'] === 'checking';
  };
  foreach (uiSettings()['monitor']['sites'] as $host) {
    if (!$ours()) {
      return;
    }
    $r = probe($host);
    $before = $p['before'][$host] ?? null;
    // до перезапуска открывался, а сейчас нет — перепроверяем: стратегия может срабатывать не каждый раз,
    // и одна неудача сразу после перезапуска ещё не значит, что изменение сломало сайт
    for ($k = 0; $before === 1 && !$r['ok'] && $k < 2 && $ours(); $k++) {
      sleep(3);
      $r = probe($host);
    }
    $checks[] = ['host' => $host, 'ok' => $r['ok'], 'ms' => $r['ms'], 'reason' => $r['reason'], 'was_ok' => $before, 'regressed' => $before === 1 && !$r['ok']];
  }
  if (!$ours()) {
    return;
  }
  $p['checks'] = $checks;
  $p['running'] = $running;
  $p['state'] = 'waiting';
  pendingSave($p);
  if (!$running) {
    doRollback($p, 'nfqws2 не запустился');
    return;
  }
  // Срок ждёт оболочка со sleep, а не этот процесс: PHP занимает 10–15 МБ, и на роутере со 128 МБ три минуты
  // ожидания вместе с открытой страницей уводили память в ноль (NFQ-L130)
  $left = max(1, $p['deadline'] - time());
  exec('(sleep ' . $left . '; env -i PATH=' . JOB_PATH . ' NFQWS_UI_CLI=guard_deadline php-cgi -q -f ' . escapeshellarg(__FILE__) . ' >/dev/null 2>&1) >/dev/null 2>&1 &');
}

// Откат, если срок вышел, а изменения так и не подтвердили. $grace — запас после срока: оболочка со sleep
// откатывает ровно в срок (0), а state и cron — только если она не справилась (задание убила нехватка памяти)
function pendingExpire(int $grace): void
{
  $p = pendingGet();
  if (!$p || !in_array($p['state'], ['checking', 'waiting'], true) || time() < (int)($p['deadline'] ?? 0) + $grace) {
    return;
  }
  $l = @fopen('/tmp/nfqws-ui-pending.lock', 'c');
  if (!$l || !flock($l, LOCK_EX | LOCK_NB)) {
    return;   // откатывает другой процесс
  }
  $p = pendingGet();
  if ($p && in_array($p['state'], ['checking', 'waiting'], true) && time() >= (int)($p['deadline'] ?? 0) + $grace) {
    doRollback($p, $p['state'] === 'checking' ? 'проверка сайтов не завершилась (возможно, роутеру не хватило памяти)'
      : 'изменения не подтверждены за отведённое время');
  }
  flock($l, LOCK_UN);
}

// Состояние проверки для страницы — без списка версий и прежних итогов
function pendingView(): ?array
{
  $p = pendingGet();
  if (!$p) {
    return null;
  }
  $p['files'] = array_map('basename', array_keys($p['versions'] ?? []));
  unset($p['versions'], $p['before']);
  return $p;
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
    if (!$r['ok'] && ($sp = dnsSpoof($host))) {
      $r['reason'] = dnsSpoofReason($sp);   // по этой строке автоподбор поймёт, что стратегия ни при чём
    }
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
  putSafe(MONITOR_FILE, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  if ($changes) {
    notifyTelegram("nfqws2 на роутере:\n" . implode("\n", $changes));
  }
  return $data;
}

// Неотправленные уведомления и журнал отправок. Telegram бывает недоступен (туннель упал, адрес закрыт) —
// тогда сообщение ждёт в очереди и уходит со следующим проходом cron, но не позже чем через сутки
define('NOTIFY_FILE', UI_CONF_DIR . '/notify.json');
define('NOTIFY_KEEP', 86400);

function notifyData(): array
{
  $d = json_decode((string)@file_get_contents(NOTIFY_FILE), true);
  return (is_array($d) ? $d : []) + ['queue' => [], 'log' => []];
}

// $queue = false — только сейчас и без журнала (проверочное сообщение: ошибку сразу видит человек)
function notifyTelegram(string $text, bool $queue = true): ?string
{
  $t = uiSettings()['notify'];
  if (empty($t['tg_token']) || empty($t['tg_chat'])) {
    return 'не настроено';
  }
  if (!$queue) {
    $r = tgCall($t, 'sendMessage', ['chat_id' => $t['tg_chat'], 'text' => $text]);
    return $r['ok'] ? null : $r['error'];
  }
  $d = notifyData();
  $d['queue'][] = ['ts' => time(), 'text' => $text];
  return notifyFlush($d);
}

// Отправляет очередь по порядку. На сетевой ошибке останавливается — остальное ждёт следующего прохода;
// отказ самого Telegram (неверный токен, чат) повтором не лечится — такое сообщение снимается
function notifyFlush(?array $d = null): ?string
{
  $d = $d ?? notifyData();
  if (!$d['queue']) {
    return null;
  }
  $t = uiSettings()['notify'];
  $err = null;
  while ($d['queue']) {
    $m = $d['queue'][0];
    if (time() - $m['ts'] > NOTIFY_KEEP) {
      array_shift($d['queue']);
      $d['log'][] = ['ts' => time(), 'at' => $m['ts'], 'text' => $m['text'], 'ok' => false, 'error' => 'не ушло за сутки: ' . ($m['error'] ?? 'нет связи')];
      continue;
    }
    if (empty($t['tg_token']) || empty($t['tg_chat'])) {
      $err = 'не настроено';
      break;
    }
    $late = time() - $m['ts'] > 120;
    $text = $m['text'] . ($late ? "\n(событие в " . ldate('d.m H:i', $m['ts']) . ', сообщение задержалось: Telegram был недоступен)' : '');
    $r = tgCall($t, 'sendMessage', ['chat_id' => $t['tg_chat'], 'text' => $text]);
    if (!$r['ok'] && ($r['code'] === null || $r['code'] === 429)) {
      $err = $r['error'];
      $d['queue'][0]['error'] = $err;
      $d['queue'][0]['tries'] = ($m['tries'] ?? 0) + 1;
      break;
    }
    array_shift($d['queue']);
    $d['log'][] = ['ts' => time(), 'at' => $m['ts'], 'text' => $m['text'], 'ok' => $r['ok']] + ($r['ok'] ? [] : ['error' => $r['error']]);
    if (!$r['ok']) {
      $err = $r['error'];
    }
  }
  $d['log'] = array_slice($d['log'], -30);
  @mkdir(UI_CONF_DIR, 0755, true);
  putSafe(NOTIFY_FILE, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  return $err;
}

// Запрос к Bot API тем путём, что выбран в настройках. ok, result — ответ Telegram; иначе error словами
// и code — код ошибки Telegram (null, если до него не дошли)
function tgCall(array $t, string $method, array $params): array
{
  $opts = [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 6];
  // api.telegram.org у многих закрыт по IP — тогда идём через выбранный туннель или прокси
  if ($t['via'] === 'iface' && $t['iface'] !== '') {
    $opts[CURLOPT_INTERFACE] = $t['iface'];
    if ($ip = telegramRealIp()) {
      $opts[CURLOPT_RESOLVE] = ["api.telegram.org:443:$ip"];
    }
  } elseif ($t['via'] === 'proxy' && $t['proxy'] !== '') {
    $opts[CURLOPT_PROXY] = $t['proxy'];
  }
  $ch = curl_init('https://api.telegram.org/bot' . $t['tg_token'] . '/' . $method);
  curl_setopt_array($ch, $opts);
  $res = curl_exec($ch);
  $errno = curl_errno($ch);
  $err = curl_error($ch);
  curl_close($ch);
  $j = json_decode((string)$res, true);
  if (!empty($j['ok'])) {
    return ['ok' => true, 'result' => $j['result'] ?? null];
  }
  if (isset($j['description'])) {
    // Telegram ответил — значит, путь работает, дело в токене или ID чата
    return ['ok' => false, 'error' => (string)$j['description'], 'code' => (int)($j['error_code'] ?? 0)];
  }
  $why = [6 => 'имя api.telegram.org не находится', 5 => 'имя прокси не находится', 7 => 'нет соединения', 28 => 'нет ответа за 10 секунд',
    35 => 'соединение оборвано при установке TLS', 45 => 'интерфейс не найден, выключен или без адреса', 56 => 'соединение оборвано', 97 => 'прокси отказал'][$errno]
    ?? preg_replace('#//[^@/\s]+@#', '//••••@', $err ?: 'ошибка отправки');
  return ['ok' => false, 'error' => notifyVia($t) . ': ' . $why, 'code' => null];
}

// Чаты, из которых боту недавно писали, — чтобы не узнавать ID чата вручную. Telegram хранит сообщения сутки.
// offset не передаём: сообщения не отмечаются прочитанными и остаются тому, кто ещё опрашивает этого бота
function tgChats(): array
{
  $t = uiSettings()['notify'];
  if (empty($t['tg_token'])) {
    return ['error' => 'Сначала укажите токен бота.'];
  }
  $r = tgCall($t, 'getUpdates', ['limit' => 100, 'timeout' => 0]);
  if (!$r['ok']) {
    if ($r['code'] === 401 || $r['code'] === 404) {
      return ['error' => 'Telegram не принял токен — проверьте, что он скопирован у @BotFather целиком.'];
    }
    if ($r['code'] === 409) {
      return ['error' => stripos($r['error'], 'webhook') !== false
        ? 'У этого бота включён webhook — им пользуется другая программа, и сообщения отсюда не прочитать. Создайте для уведомлений отдельного бота у @BotFather.'
        : 'Этого бота прямо сейчас опрашивает другая программа. Создайте для уведомлений отдельного бота у @BotFather или укажите ID чата вручную.'];
    }
    return ['error' => 'Telegram: ' . $r['error']];
  }
  $chats = [];
  foreach (is_array($r['result']) ? $r['result'] : [] as $u) {
    foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post', 'my_chat_member', 'chat_member', 'chat_join_request'] as $k) {
      $c = $u[$k]['chat'] ?? null;
      if (!is_array($c) || !isset($c['id'])) {
        continue;
      }
      $name = trim((string)($c['title'] ?? trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))));
      // более позднее сообщение из того же чата заменяет раннее и уходит в конец
      unset($chats[(string)$c['id']]);
      $chats[(string)$c['id']] = ['id' => (string)$c['id'], 'type' => (string)($c['type'] ?? ''),
        'name' => preg_match('/^.{0,60}/us', $name, $m) ? $m[0] : '', 'username' => (string)($c['username'] ?? '')];
    }
  }
  return ['chats' => array_reverse(array_values($chats))];
}

// Какой бот подключён и в какой чат он пишет — чтобы по токену из точек и числу ID было видно, что это.
// getMe и getChat сообщений не забирают и другой программе, которая опрашивает бота, не мешают
function tgInfo(): array
{
  $t = uiSettings()['notify'];
  if (empty($t['tg_token'])) {
    return ['bot' => null, 'chat' => null];
  }
  $cut = fn($s) => preg_match('/^.{0,60}/us', trim((string)$s), $m) ? $m[0] : '';
  $r = tgCall($t, 'getMe', []);
  if (!$r['ok']) {
    return ['bot' => null, 'chat' => null, 'error' => $r['code'] === 401 || $r['code'] === 404
      ? 'Telegram не принял токен — бот удалён или токен заменён. Возьмите новый токен у @BotFather.'
      : 'Telegram: ' . $r['error']];
  }
  $out = ['bot' => ['name' => $cut($r['result']['first_name'] ?? ''), 'username' => (string)($r['result']['username'] ?? '')], 'chat' => null];
  if ($t['tg_chat'] === '') {
    return $out;
  }
  $c = tgCall($t, 'getChat', ['chat_id' => $t['tg_chat']]);
  if (!$c['ok']) {
    // 400 «chat not found» — боту в этот чат не писали или ID с ошибкой; 403 — бота оттуда убрали или заблокировали
    $out['chat_error'] = $c['code'] === 400 ? 'бот не видит этот чат — проверьте ID или напишите боту любое сообщение'
      : ($c['code'] === 403 ? 'бота убрали из этого чата или заблокировали' : $c['error']);
    return $out;
  }
  $c = $c['result'];
  $out['chat'] = ['name' => $cut($c['title'] ?? trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))),
    'type' => (string)($c['type'] ?? ''), 'username' => (string)($c['username'] ?? '')];
  return $out;
}

// Местный DNS может отдавать для api.telegram.org адрес, который через туннель не работает: FakeIP podkop
// (198.18.0.0/15) или заглушку. Тогда настоящий адрес спрашиваем у защищённого DNS; запасной — постоянный адрес Telegram
function telegramRealIp(): ?string
{
  $sys = array_values(array_filter(gethostbynamel('api.telegram.org') ?: [], 'isIp4'));
  if ($sys && !array_filter($sys, fn($ip) => ipInEntry(inet_pton($ip), '198.18.0.0/15') || ipInEntry(inet_pton($ip), '127.0.0.0/8') || ipInEntry(inet_pton($ip), '0.0.0.0/8'))) {
    return null;
  }
  foreach (DIAG_DOH as [, $server, $ip]) {
    $r = dohResolve($server, $ip, 'api.telegram.org');
    if ($r['ok'] && ($real = array_values(array_filter($r['ips'], 'isIp4')))) {
      return $real[0];
    }
  }
  return '149.154.167.220';
}

// Каким путём уходят уведомления — словами, без пароля прокси
function notifyVia(array $t): string
{
  if ($t['via'] === 'iface' && $t['iface'] !== '') {
    return "через интерфейс {$t['iface']}";
  }
  return $t['via'] === 'proxy' && $t['proxy'] !== '' ? 'через прокси ' . preg_replace('#^.*@#', '', preg_replace('#^\w+://#', '', $t['proxy'])) : 'напрямую';
}

function maskProxy(string $p): string
{
  return preg_replace('#://[^@/]+@#', '://••••@', $p);
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
// QUIC (UDP 443): фейки первого пакета и изменение длины дейтаграммы. Блоб fake_default_quic встроен в nfqws2 —
// набор не зависит от того, какие блобы объявлены в конфиге
const STD_QUIC = [
  ['fake QUIC ×2', ['--lua-desync=fake:blob=fake_default_quic:repeats=2']],
  ['fake QUIC ×4', ['--lua-desync=fake:blob=fake_default_quic:repeats=4']],
  ['fake QUIC ×6', ['--lua-desync=fake:blob=fake_default_quic:repeats=6']],
  ['fake QUIC ×11', ['--lua-desync=fake:blob=fake_default_quic:repeats=11']],
  ['fake QUIC ×16', ['--lua-desync=fake:blob=fake_default_quic:repeats=16']],
  ['fake QUIC ×6 autottl', ['--lua-desync=fake:blob=fake_default_quic:repeats=6:ip_autottl=-2,3-20']],
  ['fake нули ×6', ['--lua-desync=fake:blob=0x00000000000000000000000000000000:repeats=6']],
  ['udplen +2', ['--lua-desync=udplen:increment=2']],
  ['udplen +8', ['--lua-desync=udplen:increment=8']],
  ['fake QUIC ×6 + udplen +2', ['--lua-desync=fake:blob=fake_default_quic:repeats=6', '--lua-desync=udplen:increment=2']],
];

// ----- каталог стратегий по правилам blockcheck2 (zapret2, blockcheck2.d/standard) -----
// Те же функции, позиции разреза и способы испортить фейк, что перебирает blockcheck2. Взяты только варианты
// из одних шагов --lua-desync (без дополнительных --payload/--out-range). «Расширенный» — без перебора TTL и
// с основными позициями (~330 TLS), «полный» — всё (~3000 TLS): его проверяет параллельный прогон.
const CAT_FOOL = ['tcp_md5', 'badsum', 'tcp_seq=-3000', 'tcp_seq=1000000', 'tcp_ack=-66000:tcp_ts_up', 'tcp_ts=-1000', 'tcp_flags_unset=ACK', 'tcp_flags_set=SYN'];
const CAT_SPLITS_TLS = ['2', '1', 'sniext+1', 'sniext+4', 'host+1', 'midsld', '1,midsld', '1,midsld,1220', '1,sniext+1,host+1,midsld-2,midsld,midsld+2,endhost-1'];
const CAT_SPLITS_HTTP = ['method+2', 'midsld', 'method+2,midsld'];

function catalogCandidates(string $level, bool $http): array
{
  $full = $level === 'full';
  $fool = CAT_FOOL;
  foreach (range(1, 5) as $d) {
    $fool[] = "ip_autottl=-$d,3-20";
  }
  if ($full) {
    foreach (range(1, 12) as $t) {
      $fool[] = "ip_ttl=$t";
    }
  }
  $splits = $http ? CAT_SPLITS_HTTP : CAT_SPLITS_TLS;
  $fake = $http ? 'fake_default_http' : 'fake_default_tls';
  $d = '--lua-desync=';
  $out = [];
  $add = function (array $steps) use (&$out) {
    $out[implode(' ', $steps)] = $steps;
  };
  // разрез без фейков
  foreach (['multisplit', 'multidisorder'] as $f) {
    foreach ($splits as $p) {
      $add(["{$d}$f:pos=$p"]);
    }
  }
  // фейк: варианты blockcheck2 (pktws_fake_https_vary_)
  $fakes = function (string $fl) use ($d, $fake, $http): array {
    $v = [["{$d}fake:blob=$fake:$fl"], ["{$d}fake:blob=0x00000000:$fl"]];
    if (!$http) {
      $v[] = ["{$d}fake:blob=0x00000000:$fl", "{$d}fake:blob=$fake:$fl:tls_mod=rnd,dupsid"];
      $v[] = ["{$d}multisplit:blob=$fake:$fl:pos=2:nodrop"];
      $v[] = ["{$d}fake:blob=$fake:$fl:tls_mod=rnd,dupsid,padencap"];
    }
    return $v;
  };
  foreach ($fool as $fl) {
    foreach ($fakes($fl) as $v) {
      $add($v);
    }
  }
  // hostfakesplit
  foreach ($fool as $fl) {
    foreach ($full ? ['', 'disorder_after:'] : [''] as $dis) {
      foreach ($full ? ['', 'nofake1:', 'nofake2:', 'midhost=midsld:', 'nofake1:midhost=midsld:', 'nofake2:midhost=midsld:'] : ['', 'midhost=midsld:'] as $m) {
        $add(["{$d}hostfakesplit:$dis$m$fl"]);
      }
    }
  }
  // фейк + разрез
  $msplits = $full ? $splits : ($http ? $splits : ['2', 'midsld', '1,midsld']);
  foreach (['multisplit', 'multidisorder'] as $f) {
    foreach ($msplits as $p) {
      foreach ($fool as $fl) {
        foreach ($full ? $fakes($fl) : array_slice($fakes($fl), 0, 2) as $v) {
          $add(array_merge($v, ["{$d}$f:pos=$p"]));
        }
      }
    }
  }
  // fakedsplit / fakeddisorder — только в полном
  if ($full) {
    foreach (['fakedsplit', 'fakeddisorder'] as $f) {
      foreach ($http ? $splits : array_slice(CAT_SPLITS_TLS, 0, 7) + [7 => CAT_SPLITS_TLS[8]] as $p) {
        foreach ($fool as $fl) {
          $add(["{$d}$f:pos=$p:$fl"]);
        }
      }
    }
  }
  // перекрытие последовательности (seqovl)
  $add(["{$d}tcpseg:pos=0,-1:seqovl=1", "{$d}drop"]);
  foreach ($http ? ['method+2', 'method+2,midsld'] : ['10', '10,sniext+1', '10,sniext+4', '10,midsld'] as $p) {
    $add(["{$d}multisplit:pos=$p:seqovl=1"]);
  }
  foreach ($http ? [['method+1', 'method+2'], ['midsld-1', 'midsld'], ['method+1', 'method+2,midsld']]
    : [['1', '2'], ['sniext', 'sniext+1'], ['sniext+3', 'sniext+4'], ['midsld-1', 'midsld'], ['1', '2,midsld']] as [$a, $b]) {
    $add(["{$d}multidisorder:pos=$b:seqovl=$a"]);
  }
  // сегменты с повтором (только в полном)
  if ($full) {
    foreach ([1, 20, 100, 260] as $r) {
      foreach ($http ? ['0,method+2', '0,midsld'] : ['0,1', '0,midsld'] as $p) {
        $add(["{$d}tcpseg:pos=$p:ip_id=rnd:repeats=$r"]);
      }
    }
  }
  $from = 'каталог zapret2 (' . ($full ? 'полный' : 'расширенный') . ')';
  return array_map(fn($s) => ['name' => strategyName($s), 'from' => $from, 'steps' => $s, 'catalog' => true], array_values($out));
}

// Стратегии из конфига: шаги профилей, которые могут обработать это соединение; circular раскладывается на стратегии
function configCandidates(string $proto): array
{
  $quic = $proto === 'quic';
  $wantPayload = $proto === 'http' ? 'http_req' : ($quic ? 'quic_initial' : 'tls_client_hello');
  $port = $proto === 'http' ? 80 : 443;
  $out = [];
  foreach (expectedProfiles(confValues()) as $n => $e) {
    $p = buildProfile($n + 1, $e['tokens']);
    if (!inRanges($port, protoPorts($p)[$quic ? 'udp' : 'tcp'])) {
      continue;
    }
    // UDP-профиль под другой протокол (discord, stun, wireguard) к QUIC не относится
    if ($quic && ($l7 = current(preg_grep('/^--filter-l7=/', $e['tokens']))) && !in_array('quic', explode(',', substr($l7, 12)), true)) {
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

// $slots — для параллельного прогона: пакеты каждой дорожки (свой кусок проверочных портов) получают свою метку,
// по ней nfqws2 выбирает профиль (--filter-mark)
function testRules(string $mode, string $iface, int $slots = 0): void
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
  // Только соединения самого роутера: проверочные порты — обычные порты источника, с такими же ходят и устройства
  // сети (Linux, Android — примерно 3 соединения из 100). Чужое соединение сразу выходит из проверочных цепочек
  // и идёт дальше как обычно, через основной nfqws2. Отличаем по адресу, с которого соединение началось.
  $o = [];
  exec('ip -4 -o addr show dev ' . $if . ' 2>/dev/null', $o);
  if (preg_match('/inet (\d+\.\d+\.\d+\.\d+)/', implode(' ', $o), $m)) {
    foreach (['nfqws_test_post', 'nfqws_test_pre'] as $chain) {
      $cmds[] = $ipt . "-A $chain -m conntrack ! --ctorigsrc {$m[1]} -j RETURN";   // нет модуля — правило не встанет, будет как раньше
    }
  }
  if ($mode === 'queue') {
    for ($k = 0; $k < $slots; $k++) {
      [$lo, $hi] = parPorts($k);
      $cmds[] = $ipt . "-A nfqws_test_post -o $if -p tcp --sport $lo:$hi -j MARK --set-xmark " . parMark($k) . '/' . PAR_MASK;
      $cmds[] = $ipt . "-A nfqws_test_pre -i $if -p tcp --dport $lo:$hi -j MARK --set-xmark " . parMark($k) . '/' . PAR_MASK;
    }
    $cmds[] = $ipt . "-A nfqws_test_post -o $if -p tcp -m multiport --sports " . TEST_PORTS . ' -m mark ! --mark 0x40000000/0x40000000 -j NFQUEUE --queue-num ' . TEST_QNUM . ' --queue-bypass';
    $cmds[] = $ipt . "-A nfqws_test_pre -i $if -p tcp -m multiport --dports " . TEST_PORTS . ' -m connbytes --connbytes-dir=reply --connbytes-mode=packets --connbytes 1:15 -j NFQUEUE --queue-num ' . TEST_QNUM . ' --queue-bypass';
    // UDP — для зонда QUIC: первые пакеты в обе стороны, как у основного nfqws2
    $cmds[] = $ipt . "-A nfqws_test_post -o $if -p udp -m multiport --sports " . TEST_PORTS . ' -m mark ! --mark 0x40000000/0x40000000 -m connbytes --connbytes-dir=original --connbytes-mode=packets --connbytes 1:15 -j NFQUEUE --queue-num ' . TEST_QNUM . ' --queue-bypass';
    $cmds[] = $ipt . "-A nfqws_test_pre -i $if -p udp -m multiport --dports " . TEST_PORTS . ' -m connbytes --connbytes-dir=reply --connbytes-mode=packets --connbytes 1:15 -j NFQUEUE --queue-num ' . TEST_QNUM . ' --queue-bypass';
  }
  // без обхода и после очереди — мимо основного nfqws2
  foreach (['tcp', 'udp'] as $l4) {
    $cmds[] = $ipt . "-A nfqws_test_post -o $if -p $l4 -m multiport --sports " . TEST_PORTS . ' -j ACCEPT';
    $cmds[] = $ipt . "-A nfqws_test_pre -i $if -p $l4 -m multiport --dports " . TEST_PORTS . ' -j ACCEPT';
  }
  foreach ($cmds as $c) {
    exec($c . ' 2>&1');
  }
}

function curlProbe(string $host, bool $http, string $path = '/'): array
{
  $url = ($http ? 'http://' : 'https://') . $host . $path;
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
    126, 127 => 'не запускается программа curl',
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
      . ' -w "%{time_appconnect}" ' . escapeshellarg($url . (str_contains($url, '?') ? '&' : '?') . 't=' . mt_rand()) . ' 2>/dev/null', $vout, $vrc);
    if ($vrc === 28 && (float)trim($vout[0] ?? '0') > 0) {
      [$ok, $reason] = [false, REASON_FREEZE];
    }
  }
  return ['ok' => $ok, 'code' => (int)$code, 'ms' => (int)round((float)$time * 1000), 'reason' => $reason];
}

// Обрыв на объёме по адресу — без сайта и без большого файла (приём runnin4ik/dpi-detector, tcp16): PAD_REQS запросов
// HEAD по одному соединению, к каждому заголовок на PAD_BYTES байт — к серверу уходит ~48 КБ. Первые ответы пришли,
// потом тишина — обрыв. Если сервер не держит соединение (переподключения), итог не показателен.
// $sni — имя в приветствии: от него не зависит обрыв (он по адресу), но без имени не сработает hostfakesplit
const PAD_REQS = 12;
const PAD_BYTES = 4000;

function padProbe(string $ip, int $port, string $sni): array
{
  [$lo, $hi] = array_map('intval', explode(':', TEST_PORTS));
  $url = ($port === 80 ? 'http://' : 'https://') . $sni . ($port === 80 || $port === 443 ? '' : ":$port") . '/';
  // -m — на каждый запрос, а не на все: без --fail-early после обрыва curl открывал бы новые соединения
  // и ждал на каждом (до PAD_REQS × 8 с)
  $cmd = 'curl -4 -sk -I --fail-early --local-port ' . mt_rand($lo, $hi - 50) . '-' . $hi . ' --connect-timeout 4 -m 8'
    . ' --connect-to ' . escapeshellarg("$sni:$port:$ip:$port")
    . ' -A ' . escapeshellarg('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130 Safari/537.36')
    . ' -H ' . escapeshellarg('X-Pad: ' . str_repeat('a', PAD_BYTES))
    . ' -w ' . escapeshellarg('%{http_code} %{num_connects} %{time_total}\n');
  for ($i = 0; $i < PAD_REQS; $i++) {
    $cmd .= ' -o /dev/null ' . escapeshellarg("$url?n=$i");
  }
  $out = [];
  exec($cmd . ' 2>/dev/null', $out, $rc);
  $got = 0;
  $reconnect = false;
  $ms = 0;
  foreach ($out as $k => $line) {
    [$code, $conn, $time] = array_pad(explode(' ', trim($line)), 3, '0');
    if ((int)$code > 0) {
      $got++;
      $ms += (int)round((float)$time * 1000);
      $reconnect = $reconnect || ($k > 0 && (int)$conn > 0);
    }
  }
  // сервер закрывал соединение (переподключения или ответы без кода) — объём в одном соединении не набрался
  if ($reconnect || ($rc === 0 && $got < PAD_REQS)) {
    return ['ok' => false, 'code' => 0, 'ms' => $ms, 'reason' => 'сервер не держит соединение — объём не проверить', 'got' => $got];
  }
  if ($rc === 0) {
    return ['ok' => true, 'code' => 0, 'ms' => $ms, 'reason' => null];
  }
  $reason = match (true) {
    $rc === 28 && $got > 0 => REASON_FREEZE,
    $rc === 28 => 'тайм-аут',
    $rc === 7 => 'не удалось подключиться',
    $rc === 35 => 'обрыв TLS',
    $rc === 52 => 'пустой ответ',
    $rc === 56 => 'соединение сброшено',
    default => "ошибка curl $rc",
  };
  return ['ok' => false, 'code' => 0, 'ms' => $ms, 'reason' => $reason, 'got' => $got];
}

// ----- параллельный прогон: много стратегий в одном nfqws2, по дорожке на стратегию -----
// Дорожка — свой кусок проверочных портов; iptables метит её пакеты (биты 0x0F000000 свободны: podkop занимает
// 0x00100000/0x00200000, nfqws2 — 0x40000000), nfqws2 выбирает профиль по метке. curl SO_MARK ставить не умеет —
// поэтому метка по порту. Каждая дорожка сама делает до N запросов подряд и бросает после первой неудачи.
const PAR_SLOTS = 15;
const PAR_MASK = '0x0f000000';
const PAR_MIN = 24;       // меньше кандидатов — проверяем по одной, как раньше
const PAR_CONFIRM = 8;    // сколько подтверждённых по одной находок набрать: 15 соединений к одному сайту разом
                          // иногда путают оборудование провайдера, и параллельный итог расходится с проверкой по одной
const PAR_CONFIRM_TRIES = 24;   // и сколько всего перепроверок, если находки пачки не подтверждаются
const PAR_SLOT_MB = 1.5;  // память на дорожку: curl с TLS и оболочка
const PAR_RESERVE_MB = 15;      // столько памяти остаётся системе и прошивке
const PAR_RAM_PER_SLOT_MB = 24; // и не больше дорожки на столько памяти всего: 128 МБ → 5, 256 → 10, от 384 — 15

// Сколько дорожек роутер выдержит сейчас: на Keenetic со 128 МБ 15 дорожек разом уводили память в swap, и роутер
// (вместе с веб-интерфейсом прошивки) переставал отвечать. Одной свободной памяти мало: swap у Keenetic — zram,
// система выгружает в него лишнее, «свободно» снова выглядит хорошо, а процессор занят сжатием. Поэтому ещё и
// потолок по объёму памяти. Считается перед каждой пачкой; 1 — по одной стратегии
function parSlots(): int
{
  $mem = (string)@file_get_contents('/proc/meminfo');
  if (!preg_match('/^MemAvailable:\s+(\d+)/m', $mem, $a) || !preg_match('/^MemTotal:\s+(\d+)/m', $mem, $t)) {
    return PAR_SLOTS;
  }
  $byFree = (int)floor(((int)$a[1] / 1024 - PAR_RESERVE_MB) / PAR_SLOT_MB);
  $byTotal = (int)floor((int)$t[1] / 1024 / PAR_RAM_PER_SLOT_MB);
  return max(1, min(PAR_SLOTS, $byFree, $byTotal));
}

function parMark(int $k): string
{
  return sprintf('0x%08x', ($k + 1) << 24);
}

function parPorts(int $k): array
{
  $lo = (int)explode(':', TEST_PORTS)[0] + $k * 64;
  return [$lo, $lo + 63];
}

function curlReason(int $rc, int $code): string
{
  return match ($rc) {
    6 => 'имя не разрешается',
    126, 127 => 'не запускается программа curl',
    7 => 'не удалось подключиться',
    28 => $code > 0 ? REASON_FREEZE : 'тайм-аут',
    35 => 'обрыв TLS',
    52 => 'пустой ответ',
    56 => 'соединение сброшено',
    default => "ошибка curl $rc",
  };
}

// Пачка стратегий за один запуск nfqws2. Возвращает результаты в том же порядке.
function parTry(array $chunk, ?array &$nfq, array $baseArgs, array $filter, int $repeats, string $host, bool $http): array
{
  $args = $baseArgs;
  foreach (array_values($chunk) as $k => $c) {
    if ($k) {
      $args[] = '--new';
    }
    array_push($args, '--filter-mark=' . parMark($k) . '/' . PAR_MASK, ...$filter, ...$c['steps']);
  }
  $nfq = testNfqwsStart($args);
  if (!$nfq) {
    // какую-то стратегию nfqws2 не принял — ищем её делением пачки пополам
    if (count($chunk) === 1) {
      return [['ok' => 0, 'tries' => 0, 'ms' => null, 'reason' => 'nfqws2 не принял параметры']];
    }
    $half = (int)ceil(count($chunk) / 2);
    return array_merge(parTry(array_slice($chunk, 0, $half), $nfq, $baseArgs, $filter, $repeats, $host, $http),
      parTry(array_slice($chunk, $half), $nfq, $baseArgs, $filter, $repeats, $host, $http));
  }
  $sh = TEST_DIR . '/slot.sh';
  // одна дорожка: до R запросов, после первой неудачи — стоп; маленькая страница — ещё и проверка объёма отправкой
  file_put_contents($sh, implode("\n", [
    'P=$1; U=$2; R=$3; O=$4; : > "$O"; i=0',
    'while [ $i -lt $R ]; do i=$((i+1))',
    '  set -- $(curl -4 -sk -o /dev/null --local-port $P --connect-timeout 4 -A "$UA" -m 7 -r 0-' . (VOLUME_BYTES - 1) . ' -w "%{http_code} %{time_total} %{size_download}" "$U" 2>/dev/null; echo " $?")',
    '  code=$1; t=$2; size=$3; rc=$4; v=""',
    '  if [ "$rc" = 0 ] && [ "${size%.*}" -lt ' . VOLUME_SMALL . ' ]; then',
    '    a=$(curl -4 -sk -o /dev/null --local-port $P --connect-timeout 4 -A "$UA" -m 8 -H "Expect:" -H "Content-Type: application/octet-stream" --data-binary @' . TEST_DIR . '/volume.bin -w "%{time_appconnect}" "$U?t=$i$$" 2>/dev/null); vrc=$?; v="$vrc $a"',
    '  fi',
    '  echo "$rc $code $t $size $v" >> "$O"',
    '  [ "$rc" = 0 ] || break',
    '  case "$v" in "28 "*) [ "${v#28 }" != "0.000000" ] && break;; esac',   // отправка повисла после рукопожатия — заморозка
    'done', '']));
  if (!is_file(TEST_DIR . '/volume.bin') || filesize(TEST_DIR . '/volume.bin') !== VOLUME_BYTES) {
    file_put_contents(TEST_DIR . '/volume.bin', random_bytes(VOLUME_BYTES));
  }
  $url = ($http ? 'http://' : 'https://') . $host . '/';
  $cmd = 'UA=' . escapeshellarg('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130 Safari/537.36') . '; export UA; ';
  foreach (array_keys(array_values($chunk)) as $k) {
    [$lo, $hi] = parPorts($k);
    $cmd .= 'sh ' . escapeshellarg($sh) . " $lo-$hi " . escapeshellarg($url) . " $repeats " . escapeshellarg(TEST_DIR . "/slot$k.out") . ' & ';
  }
  exec($cmd . 'wait');
  testNfqwsStop($nfq);
  $nfq = null;
  $res = [];
  foreach (array_keys(array_values($chunk)) as $k) {
    $ok = 0;
    $times = [];
    $reason = null;
    $tries = 0;
    foreach (file(TEST_DIR . "/slot$k.out", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
      $f = explode(' ', trim($line));
      $tries++;
      [$rc, $code, $t] = [(int)$f[0], (int)($f[1] ?? 0), (float)($f[2] ?? 0)];
      if ($rc !== 0 || $code === 0) {
        $reason = curlReason($rc, $code);
        break;
      }
      if (isset($f[4]) && (int)$f[4] === 28 && (float)($f[5] ?? 0) > 0) {
        $reason = REASON_FREEZE;
        break;
      }
      $ok++;
      $times[] = (int)round($t * 1000);
    }
    $res[] = ['ok' => $ok, 'tries' => max(1, $tries), 'ms' => $times ? (int)round(array_sum($times) / count($times)) : null, 'reason' => $ok === $tries ? null : ($reason ?? 'нет ответа')];
  }
  return $res;
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
  $quic = ($job['proto'] ?? '') === 'quic';
  $status = ['state' => 'running', 'type' => $job['type'], 'pid' => getmypid(), 'host' => $job['host'], 'proto' => $job['proto'] ?? 'https',
    'started' => time(), 'results' => [], 'done' => 0, 'total' => 0, 'current' => null];
  $auto = !empty($job['auto']);
  if ($auto) {
    $status['auto'] = true;
  }
  // сайты, которые podkop пускает в туннель (FakeIP): проверка с роутера уходит туда же, и все стратегии
  // выглядят одинаково — проверяется туннель, а не nfqws2
  $podkop = [];
  foreach (in_array($job['type'], ['pick', 'multi', 'wide', 'cfg'], true) && !$auto ? ($job['hosts'] ?? [$job['host']]) : [] as $h) {
    $pk = podkopRoute($h);
    if ($pk && !empty($pk['proxy'])) {
      $podkop[] = $h;
    }
  }
  if ($podkop) {
    $status['podkop'] = $podkop;
  }
  $nfq = null;
  register_shutdown_function(function () use (&$nfq, $iface, &$status) {
    testNfqwsStop($nfq);
    testRules('off', $iface);
    if ($status['state'] === 'running') {
      $status['state'] = 'error';
      $status['error'] = $status['error'] ?? fatalText() ?? 'задание прервано';
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

  if ($job['type'] === 'freeze') {
    freezeJob($job, $status, $nfq, $iface, $baseArgs);
    return;
  }

  if ($job['type'] === 'wide') {
    wideJob($job, $status, $nfq, $iface, $baseArgs);
    return;
  }

  if ($job['type'] === 'multi') {
    multiJob($job, $status, $nfq, $iface, $baseArgs);
    return;
  }

  if ($job['type'] === 'cfg') {
    cfgJob($job, $status, $nfq, $iface);
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
  $qip = null;
  if ($why === null && $quic) {
    // QUIC: проверка — своим зондом на один адрес сайта. Через podkop и у сайта без HTTP/3 подбирать нечего
    $qip = $dns['ips'][0] ?? null;
    if ($dns['status'] === 'podkop') {
      $why = 'Сайт идёт через podkop — его соединения уходят в туннель, стратегии nfqws2 на них не действуют.';
    } elseif ($qip === null) {
      $why = 'У сайта нет адреса IPv4 — зонд QUIC работает по IPv4.';
    } elseif (quicAdvertised($job['host']) === false) {
      $why = 'Сайт не объявляет HTTP/3 (в ответе нет заголовка Alt-Svc с h3) — по QUIC он, похоже, не работает, и подбирать для него нечего. Браузер открывает его обычным соединением.';
    }
  }
  if ($why !== null) {
    $status['state'] = 'error';
    $status['title'] = 'Подбор не запущен';
    $status['error'] = $why;
    testSaveStatus($status);
    return;
  }
  // один запрос: curl по TCP или зонд QUIC — ответ того же вида
  $probe = fn() => $quic ? quicProbe($job['host'], $qip, true) + ['code' => 0] : curlProbe($job['host'], $http);
  $cands = [];
  if (in_array('config', $job['sets'], true)) {
    $cands = array_merge($cands, configCandidates($status['proto']));
  }
  if (in_array('std', $job['sets'], true)) {
    foreach ($http ? STD_HTTP : ($quic ? STD_QUIC : STD_TLS) as [$name, $steps]) {
      $cands[] = ['name' => $name, 'from' => 'стандартный набор', 'steps' => $steps];
    }
  }
  if (!empty($job['steps'])) {
    array_unshift($cands, ['name' => strategyName($job['steps']), 'from' => 'вставленный профиль', 'steps' => $job['steps']]);
  }
  // свои стратегии из формы подбора — первыми и в том порядке, в каком вставлены; такая же из набора ниже не повторяется
  if (!empty($job['own'])) {
    $mine = [];
    foreach ($job['own'] as $st) {
      $mine[implode(' ', $st)] ??= ['name' => strategyName($st), 'from' => 'своя стратегия', 'steps' => $st, 'rank' => -1, 'own' => true];
    }
    $cands = array_merge(array_values($mine), array_values(array_filter($cands, fn($c) => !isset($mine[implode(' ', $c['steps'])]))));
  }
  // каталог по правилам blockcheck2: расширенный или полный; то, что уже есть выше, не повторяем
  $level = $quic ? null : (in_array('full', $job['sets'], true) ? 'full' : (in_array('ext', $job['sets'], true) ? 'ext' : null));
  if ($level) {
    $have = array_flip(array_map(fn($c) => implode(' ', $c['steps']), $cands));
    foreach (catalogCandidates($level, $http) as $c) {
      if (!isset($have[implode(' ', $c['steps'])])) {
        $cands[] = $c;
      }
    }
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
  // Что сработало у абонентов того же провайдера; совпавшее с уже взятым — только отметка
  if (in_array('community', $job['sets'], true)) {
    foreach (communityCandidates($job['host'], $status['proto'], $info) as $cc) {
      $key = implode(' ', $cc['steps']);
      if (isset($byKey[$key])) {
        $cands[$byKey[$key]]['community'] = $cc['community'];
        $cands[$byKey[$key]]['rank'] = min($cands[$byKey[$key]]['rank'] ?? 2, $cc['rank']);
        continue;
      }
      $byKey[$key] = count($cands);
      $cands[] = $cc;
    }
    $status['community'] = $info;
  }
  // Что нашла проба «Обрыв на 16 КБ»: в каталоге и стандартном наборе фейков с именем нет, а обрыв снимают только они
  // — поэтому берём их только вместе со стандартным набором или каталогом (выбран один конфиг — только конфиг)
  foreach ($http || $quic || !array_intersect(['std', 'ext', 'full'], $job['sets']) ? [] : freezeCandidates($job['host']) as $fc) {
    $key = implode(' ', $fc['steps']);
    if (isset($byKey[$key])) {
      $cands[$byKey[$key]]['rank'] = min($cands[$byKey[$key]]['rank'] ?? 2, $fc['rank']);
      continue;
    }
    $byKey[$key] = count($cands);
    $cands[] = $fc;
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
    $status['error'] = $own && count($job['sets']) === 1 ? 'В истории нет стратегий, которые работали для этого сайта. Запустите обычный подбор.'
      : ($job['sets'] === ['community'] ? 'Стратегий сообщества для вашего провайдера нет'
        . (!empty($status['community']['error']) ? ': ' . $status['community']['error'] : (!empty($status['community']['asn']) ? ' (AS' . $status['community']['asn'] . ')' : '')) . '. Запустите обычный подбор.'
      : 'Нет ни одной стратегии для проверки.');
    testSaveStatus($status);
    return;
  }
  $filter = $http ? ['--filter-tcp=80', '--filter-l7=http', '--payload=http_req']
    : ($quic ? ['--filter-udp=443', '--filter-l7=quic', '--payload=quic_initial'] : ['--filter-tcp=443', '--filter-l7=tls', '--payload=tls_client_hello']);
  $repeats = max(1, min(5, (int)($job['repeats'] ?? 3)));
  $status['total'] = count($cands) + 1;
  $status['phase'] = 'baseline';
  testSaveStatus($status);

  // Без обхода — чтобы понять, заблокирован ли сайт вообще
  $status['current'] = 'без обхода';
  testSaveStatus($status);
  testRules('bypass', $iface);
  $status['baseline'] = $probe();
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
  $try = function (array $steps) use (&$nfq, $baseArgs, $filter, $repeats, $probe): array {
    $nfq = testNfqwsStart(array_merge($baseArgs, $filter, $steps));
    if (!$nfq) {
      return ['ok' => 0, 'tries' => 0, 'ms' => null, 'reason' => 'nfqws2 не принял параметры: ' . trim((string)@file_get_contents(TEST_DIR . '/nfqws.out'))];
    }
    $ok = 0;
    $times = [];
    $reason = null;
    for ($i = 0; $i < $repeats; $i++) {
      $r = $probe();
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
  $entry = fn(array $c, array $r) => ['name' => $c['name'], 'from' => $c['from'], 'steps' => $c['steps'], 'profile' => array_merge($filter, $c['steps'])]
    + (isset($c['hist']) ? ['hist' => $c['hist']] : []) + (isset($c['community']) ? ['community' => $c['community']] : []) + $r;
  if (!$auto && !$quic && count($cands) > PAR_MIN) {
    // большой набор — параллельно, пачками по PAR_SLOTS; из каталога в результатах остаётся только то, что хоть раз открыло
    // сайт (иначе статус разрастается до мегабайта), остальное — счётчиком причин
    $status['parallel'] = parSlots();
    $status['parallel_max'] = PAR_SLOTS;
    $status['failed'] = [];
    // работавшее раньше, находки пробы обрыва и сообщества — по одной и первыми: их мало, и именно они вероятнее всего
    // рабочие, а итог пачки ненадёжен (провайдер судит по адресу сайта в целом, соединения пачки влияют друг на друга:
    // king.hr — 19 из 275 «открыли» сайт в пачке и ни одна по одной)
    $solo = array_values(array_filter($cands, fn($c) => ($c['rank'] ?? 2) < 2));
    $rest = array_values(array_filter($cands, fn($c) => ($c['rank'] ?? 2) >= 2));
    foreach ($solo as $c) {
      if (is_file(TEST_DIR . '/stop')) {
        $status['state'] = 'stopped';
        break;
      }
      $status['current'] = $c['name'];
      testSaveStatus($status);
      $status['results'][] = $entry($c, $try($c['steps']));
      $status['done']++;
      testSaveStatus($status);
    }
    testRules('queue', $iface, PAR_SLOTS);
    $left = $status['state'] === 'running' ? $rest : [];
    for ($n = 0; $left; $n++) {
      if (is_file(TEST_DIR . '/stop')) {
        $status['state'] = 'stopped';
        break;
      }
      // размер пачки — по свободной памяти перед каждой: её съедают и другие программы роутера
      $slots = parSlots();
      $status['parallel'] = $slots;
      $chunk = array_splice($left, 0, $slots);
      $status['current'] = 'пачка ' . ($n + 1) . ' из ' . ($n + 1 + (int)ceil(count($left) / $slots)) . ': ' . implode(', ', array_slice(array_column($chunk, 'name'), 0, 3)) . (count($chunk) > 3 ? '…' : '');
      testSaveStatus($status);
      foreach (parTry($chunk, $nfq, $baseArgs, $filter, $repeats, $job['host'], $http) as $k => $r) {
        $c = $chunk[$k];
        if ($r['ok'] > 0 || empty($c['catalog'])) {
          $status['results'][] = $entry($c, $r) + ['par' => true];
        } else {
          $why = $r['reason'] ?? 'нет ответа';
          $status['failed'][$why] = ($status['failed'][$why] ?? 0) + 1;
        }
      }
      $status['done'] += count($chunk);
      testSaveStatus($status);
    }
    // перепроверка по одной, как в обычном подборе: итог пачки может и ошибаться. Идём от быстрых, пока не наберётся
    // PAR_CONFIRM подтверждённых (или не кончатся попытки); что открывало сайт только в пачке — рабочим не считается
    testRules('queue', $iface);
    $best = array_keys(array_filter($status['results'], fn($r) => !empty($r['par']) && $r['ok'] >= $repeats));
    usort($best, fn($a, $b) => ($status['results'][$a]['ms'] ?? PHP_INT_MAX) <=> ($status['results'][$b]['ms'] ?? PHP_INT_MAX));
    $good = 0;
    foreach ($best as $n => $i) {
      $r = &$status['results'][$i];
      if ($status['state'] === 'running' && $good < PAR_CONFIRM && $n < PAR_CONFIRM_TRIES && !is_file(TEST_DIR . '/stop')) {
        $status['phase'] = 'confirm';
        $status['current'] = 'перепроверка: ' . $r['name'];
        testSaveStatus($status);
        $r = array_merge($r, ['par_ok' => $r['ok']], $try($r['steps']), ['confirmed' => true]);
        $good += $r['ok'] === $repeats ? 1 : 0;
      } else {
        $r = array_merge($r, ['par_ok' => $r['ok'], 'ok' => 0, 'ms' => null, 'unsure' => true, 'reason' => 'открыла в пачке, по одной не проверялась']);
      }
      unset($r);
    }
    $status['phase'] = 'pick';
  } else {
    foreach ($cands as $c) {
      if (is_file(TEST_DIR . '/stop')) {
        $status['state'] = 'stopped';
        break;
      }
      $status['current'] = $c['name'];
      testSaveStatus($status);
      $status['results'][] = $entry($c, $try($c['steps']));
      $status['done']++;
      testSaveStatus($status);
      if ($auto && end($status['results'])['ok'] === $repeats) {
        break;   // автоподбору хватает первой стратегии, открывшей сайт каждый раз
      }
    }
  }
  if ($status['state'] === 'running' && !$quic && !($auto && !$cands)) {   // уточнение — оси TLS-фейков, к QUIC не подходит
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

// ----- подбор для нескольких сайтов (списка) -----
// Одна стратегия на весь список: каждый кандидат проверяется на всех заблокированных сайтах. Перебор — параллельными
// пачками, по одному запросу на сайт (быстро, но итог пачки бывает ошибочным), затем MULTI_CONFIRM лучших
// перепроверяются по одной и полностью, как в обычном подборе. Каталог zapret2 и уточнение здесь не участвуют:
// на десяти сайтах это часы.
define('MULTI_CONFIRM', 5);

function multiCandidates(array $job, array $hosts, string $proto, array &$status): array
{
  $http = $proto === 'http';
  $cands = [];
  $seen = [];
  $add = function (array $c) use (&$cands, &$seen) {
    $key = implode(' ', $c['steps']);
    if (!isset($seen[$key])) {
      $seen[$key] = true;
      $cands[] = $c;
    }
  };
  if (in_array('config', $job['sets'], true)) {
    array_map($add, configCandidates($proto));
  }
  if (in_array('std', $job['sets'], true)) {
    foreach ($http ? STD_HTTP : STD_TLS as [$name, $steps]) {
      $add(['name' => $name, 'from' => 'стандартный набор', 'steps' => $steps]);
    }
  }
  if (in_array('hist', $job['sets'], true)) {
    foreach ($hosts as $h) {
      array_map($add, picksCandidates($h, $proto, true, true));
    }
  }
  if (in_array('community', $job['sets'], true)) {
    array_map($add, communityCandidates($hosts[0], $proto, $info));
    $status['community'] = $info;
  }
  foreach ($http || !in_array('std', $job['sets'], true) ? [] : $hosts as $h) {
    array_map($add, freezeCandidates($h));
  }
  $funcs = luaCatalog()['functions'];
  return array_values(array_filter($cands, function ($c) use ($funcs) {
    foreach ($c['steps'] as $t) {
      if (!isset($funcs[explode(':', substr($t, 13))[0]])) {
        return false;
      }
    }
    return true;
  }));
}

function multiJob(array $job, array &$status, ?array &$nfq, string $iface, array $baseArgs): void
{
  $http = ($job['proto'] ?? 'https') === 'http';
  $filter = $http ? ['--filter-tcp=80', '--filter-l7=http', '--payload=http_req'] : ['--filter-tcp=443', '--filter-l7=tls', '--payload=tls_client_hello'];
  $repeats = max(1, min(5, (int)($job['repeats'] ?? 3)));
  $stop = fn() => is_file(TEST_DIR . '/stop');
  $status += ['list' => $job['list'] ?? null, 'sites' => [], 'hosts' => [], 'repeats' => $repeats];
  $status['total'] = count($job['hosts']);
  $status['phase'] = 'baseline';

  // 1. Без обхода: что открывается и так, не находится по имени или закрыто по IP — в подборе не участвует
  testRules('bypass', $iface);
  foreach ($job['hosts'] as $h) {
    if ($stop()) {
      break;
    }
    $status['current'] = "$h без обхода";
    testSaveStatus($status);
    $row = ['host' => $h];
    $dns = diagDns($h);
    if ($dns['status'] === 'nxdomain') {
      $row['skip'] = 'имя не находится';
    } else {
      $b = curlProbe($h, $http);
      if ($b['ok']) {
        $row['skip'] = 'открывается без обхода';
      } elseif ($dns['ips'] && !array_filter(diagTcp($dns['ips'], $http ? 80 : 443), fn($x) => $x['ok'])) {
        $row['skip'] = 'с адресом нет соединения — закрыт по IP, стратегия не поможет (нужен туннель)';
      } else {
        $row['baseline'] = $b['reason'] ?? 'не открывается';
        $status['hosts'][] = $h;
      }
    }
    $status['sites'][] = $row;
    $status['done']++;
  }
  $blocked = $status['hosts'];
  if ($stop()) {
    $status['state'] = 'stopped';
  } elseif (count($blocked) < 2) {
    $status['state'] = 'error';
    $status['title'] = 'Подбор не запущен';
    $status['error'] = $blocked ? "Из выбранных сайтов заблокирован только {$blocked[0]} — для одного сайта запустите обычный подбор."
      : 'Ни один из выбранных сайтов не заблокирован так, чтобы его могла открыть стратегия: они открываются без обхода, не находятся по имени или закрыты по IP.';
  }
  $cands = $status['state'] === 'running' ? multiCandidates($job, $blocked, $http ? 'http' : 'https', $status) : [];
  if ($status['state'] === 'running' && !$cands) {
    $status['state'] = 'error';
    $status['title'] = 'Подбор не запущен';
    $status['error'] = 'Нет ни одной стратегии для проверки.';
  }
  if ($status['state'] !== 'running') {
    testRules('off', $iface);
    $status['finished'] = time();
    $status['current'] = null;
    testSaveStatus($status);
    return;
  }

  // 2. Перебор: на каждом сайте все кандидаты пачками, по одному запросу
  $slots = parSlots();
  $chunks = array_chunk(array_keys($cands), $slots);
  $status['phase'] = 'scan';
  $status['parallel'] = $slots;
  $status['parallel_max'] = PAR_SLOTS;
  $status['candidates'] = count($cands);
  $status['total'] = $status['done'] + count($blocked) * count($chunks) + min(MULTI_CONFIRM, count($cands));
  $scan = array_fill(0, count($cands), []);
  testRules('queue', $iface, PAR_SLOTS);
  foreach ($blocked as $h) {
    foreach ($chunks as $n => $idx) {
      if ($stop()) {
        $status['state'] = 'stopped';
        break 2;
      }
      $status['current'] = "$h: пачка " . ($n + 1) . ' из ' . count($chunks);
      testSaveStatus($status);
      foreach (parTry(array_map(fn($i) => $cands[$i], $idx), $nfq, $baseArgs, $filter, 1, $h, $http) as $k => $r) {
        $scan[$idx[$k]][$h] = $r['ok'] > 0;
      }
      $status['done']++;
    }
  }
  $opened = fn(int $i) => count(array_filter($scan[$i]));
  $order = array_keys($cands);
  usort($order, fn($a, $b) => $opened($b) <=> $opened($a) ?: $a <=> $b);

  // 3. Перепроверка лучших по одной: на каждом сайте до $repeats запросов, после первой неудачи — дальше
  $status['phase'] = 'confirm';
  testRules('queue', $iface);
  $results = [];
  foreach ($order as $n => $i) {
    $c = $cands[$i];
    $e = ['name' => $c['name'], 'from' => $c['from'], 'steps' => $c['steps'], 'profile' => array_merge($filter, $c['steps']),
      'scan' => $opened($i), 'scan_sites' => array_keys(array_filter($scan[$i]))] + (isset($c['hist']) ? ['hist' => $c['hist']] : []);
    if ($n < MULTI_CONFIRM && $e['scan'] > 0 && $status['state'] === 'running' && !$stop()) {
      $status['current'] = 'перепроверка: ' . $c['name'];
      testSaveStatus($status);
      $per = [];
      $nfq = testNfqwsStart(array_merge($baseArgs, $filter, $c['steps']));
      foreach ($nfq ? $blocked : [] as $h) {
        $ok = 0;
        $times = [];
        $reason = null;
        for ($t = 0; $t < $repeats; $t++) {
          $r = curlProbe($h, $http);
          if (!$r['ok']) {
            $reason = $r['reason'];
            break;
          }
          $ok++;
          $times[] = $r['ms'];
        }
        $per[$h] = ['ok' => $ok, 'tries' => min($t + 1, $repeats), 'ms' => $times ? (int)round(array_sum($times) / count($times)) : null, 'reason' => $reason];
      }
      testNfqwsStop($nfq);
      $nfq = null;
      $ms = array_filter(array_column($per, 'ms'));
      $e += ['confirmed' => true, 'per' => $per, 'opened' => count(array_filter($per, fn($x) => $x['ok'] > 0 && $x['ok'] === $x['tries'])),
        'ms' => $ms ? (int)round(array_sum($ms) / count($ms)) : null];
      $status['done']++;
    }
    $results[] = $e;
  }
  // перепроверенные — первыми: больше открытых сайтов, затем быстрее; остальные — по итогу пачек
  usort($results, fn($a, $b) => empty($a['confirmed']) <=> empty($b['confirmed'])
    ?: ($b['opened'] ?? $b['scan']) <=> ($a['opened'] ?? $a['scan']) ?: ($a['ms'] ?? PHP_INT_MAX) <=> ($b['ms'] ?? PHP_INT_MAX));
  $status['results'] = $results;
  testRules('off', $iface);
  if ($status['state'] === 'running') {
    $status['state'] = 'done';
  }
  $status['finished'] = time();
  $status['current'] = null;
  testSaveStatus($status);
}

// ----- одна стратегия на нескольких сайтах -----
// Найденная подбором стратегия может открывать только свой сайт. Перед тем как ставить её на профиль со списком,
// проверяем её на других сайтах этого списка: сначала без обхода (что открывается и так — не в счёт), потом через
// отдельный nfqws2 с этой стратегией. Итог подбора, из которого запущена проверка, хранится в статусе (pick),
// чтобы его таблица не пропала со страницы.
function wideJob(array $job, array &$status, ?array &$nfq, string $iface, array $baseArgs): void
{
  $http = ($job['proto'] ?? 'https') === 'http';
  $filter = $http ? ['--filter-tcp=80', '--filter-l7=http', '--payload=http_req'] : ['--filter-tcp=443', '--filter-l7=tls', '--payload=tls_client_hello'];
  $repeats = max(1, min(5, (int)($job['repeats'] ?? 3)));
  $status += ['pick' => $job['pick'] ?? null, 'steps' => $job['steps'], 'name' => strategyName($job['steps']), 'repeats' => $repeats, 'sites' => []];
  $status['total'] = count($job['hosts']) * 2;
  $status['phase'] = 'baseline';
  testRules('bypass', $iface);
  foreach ($job['hosts'] as $h) {
    if (is_file(TEST_DIR . '/stop')) {
      break;
    }
    $status['current'] = "$h без обхода";
    testSaveStatus($status);
    $row = ['host' => $h];
    if (diagDns($h)['status'] === 'nxdomain') {
      $row['skip'] = 'имя не находится';
    } else {
      $b = curlProbe($h, $http);
      $row['baseline'] = ['ok' => $b['ok'], 'reason' => $b['reason'] ?? null];
    }
    $status['sites'][] = $row;
    $status['done']++;
  }
  $status['phase'] = 'check';
  testRules('queue', $iface);
  $nfq = is_file(TEST_DIR . '/stop') ? null : testNfqwsStart(array_merge($baseArgs, $filter, $job['steps']));
  if (!$nfq && !is_file(TEST_DIR . '/stop')) {
    $status['state'] = 'error';
    $status['error'] = 'nfqws2 не принял параметры: ' . trim((string)@file_get_contents(TEST_DIR . '/nfqws.out'));
    testSaveStatus($status);
    return;
  }
  foreach ($status['sites'] as &$row) {
    if (is_file(TEST_DIR . '/stop')) {
      $status['state'] = 'stopped';
      break;
    }
    $status['done']++;
    if (isset($row['skip'])) {
      continue;
    }
    $status['current'] = $row['host'] . ' со стратегией';
    testSaveStatus($status);
    $ok = 0;
    $times = [];
    $reason = null;
    for ($i = 0; $i < $repeats; $i++) {
      $r = curlProbe($row['host'], $http);
      if (!$r['ok']) {
        $reason = $r['reason'];
        break;   // как в подборе: первая же неудача — дальше не тратим время
      }
      $ok++;
      $times[] = $r['ms'];
    }
    $row += ['ok' => $ok, 'tries' => min($i + 1, $repeats), 'ms' => $times ? (int)round(array_sum($times) / count($times)) : null, 'reason' => $reason];
  }
  unset($row);
  testNfqwsStop($nfq);
  $nfq = null;
  testRules('off', $iface);
  if ($status['state'] === 'running') {
    $status['state'] = 'done';
  }
  $status['finished'] = time();
  $status['current'] = null;
  testSaveStatus($status);
}

// ----- проверка чужих конфигов целиком -----
// Конфиги берут в чатах и ставят заменой своего. Здесь каждый (и свой текущий для сравнения) запускается на
// проверочной очереди отдельным nfqws2, и на одних и тех же сайтах видно, что он открывает. Рабочий конфиг не меняется.
// Чужой текст в оболочку не попадает как есть: в нём допускаются только присваивания переменных и комментарии.
const CFG_MAX = 10;          // чужих конфигов за раз
const CFG_TEXT_MAX = 65536;  // байт в одном конфиге
const CFG_HOSTS_MAX = 15;
const CFG_MIN_FREE_MB = 20;  // меньше свободной памяти — второй nfqws2 с полным конфигом не запускаем
const CFG_FILE = TEST_DIR . '/cfg.json';

// null — текст безопасно читать оболочкой; иначе причина отказа
function cfgUnsafe(string $text): ?string
{
  $re = '/^[ \t]*[A-Za-z_][A-Za-z0-9_]*=(?:"[^"]*"|\'[^\']*\'|[^\s"\'#;&|<>]*)[ \t]*(?:#[^\n]*)?$/m';
  preg_match_all($re, $text, $m);
  foreach ($m[0] as $a) {
    if (str_contains($a, '`') || str_contains($a, '$(')) {
      return 'в значении переменной есть подстановка команды (` или $(…)) — такой конфиг выполнял бы команды на роутере';
    }
  }
  foreach (explode("\n", preg_replace($re, '', $text)) as $line) {
    $line = trim($line);
    if ($line !== '' && $line[0] !== '#') {
      return 'кроме переменных в нём есть команды (' . cutText($line, 60) . ') — такой конфиг выполнял бы их на роутере';
    }
  }
  return null;
}

function cutText(string $s, int $n): string
{
  return strlen($s) > $n ? preg_replace('/[\x80-\xBF]+$/', '', substr($s, 0, $n)) . '…' : $s;
}

// Чужой конфиг → текст под этот роутер, аргументы для проверочного nfqws2 и профили. Ошибки — в skip
function cfgPrepare(string $name, string $text, int $k, bool $mine = false): array
{
  $c = ['name' => $name, 'skip' => null, 'changes' => [], 'missing' => [], 'warnings' => 0];
  $text = normalizeText($text);
  if (!$mine && ($why = cfgUnsafe($text)) !== null) {
    return ['skip' => "Не проверяется: $why."] + $c;
  }
  $ad = adaptConf($text);
  $text = $ad['text'];
  $c['changes'] = $ad['changes'];
  $c['text'] = $text;
  $raw = confRawVars($text);
  $lint = lintConf($raw, $text);
  foreach ($lint['issues'] as $x) {
    if (preg_match('/^Файла (?:блоба )?нет: (\S+)/u', $x['msg'], $mm)) {
      $c['missing'][] = $mm[1];
    }
    $c['warnings'] += in_array($x['level'], ['error', 'warning'], true) ? 1 : 0;
  }
  $c['missing'] = array_values(array_unique($c['missing']));
  if (!$lint['dry_run'] || !$lint['dry_run']['ok']) {
    return ['skip' => $c['missing'] ? 'Нет файлов: ' . implode(', ', $c['missing'])
      : 'nfqws2 не принимает этот конфиг' . (!empty($lint['dry_run']['message']) ? ': ' . $lint['dry_run']['message'] : '')] + $c;
  }
  // профили и аргументы — без комментариев внутри кавычек (nfqws2 их слова всё равно пропускает); в «Применить» уходит текст как есть
  $clean = $text;
  foreach (ARG_VARS as $v) {
    foreach (array_reverse(array_keys(commentTokens(confRawVars($clean)[$v] ?? ''))) as $t) {
      $clean = editTokenInText($clean, $v, $t, null);
    }
  }
  $tmp = tempnam('/tmp', 'nfqcfg');
  file_put_contents($tmp, $clean);
  $exp = confValues($tmp);
  unlink($tmp);
  $c['profiles'] = array_values(array_column(expectedProfiles($exp), 'tokens'));
  if (!$c['profiles']) {
    return ['skip' => 'В конфиге нет ни одного профиля'] + $c;
  }
  // параметры запуска: очередь, пользователь и журнал задаёт проверка сама
  $args = array_values(array_filter(tokens($exp['NFQWS_BASE_ARGS']), fn($t) => !preg_match('/^--(qnum|daemon|pidfile|user|uid|debug)(=|$)/', $t)));
  foreach ($c['profiles'] as $n => $pt) {
    if ($n > 0) {
      $args[] = '--new';
    }
    foreach ($pt as $t) {
      if (str_starts_with($t, '--hostlist-auto-debug=')) {
        continue;
      }
      // автосписок пишется во время работы — чужой конфиг пишет в копию, а не в ваш файл
      if (preg_match('/^--hostlist-auto=(.+)$/', $t, $mm)) {
        $copy = TEST_DIR . "/cfg$k-auto.list";
        @copy($mm[1], $copy) || touch($copy);
        @chmod($copy, 0666);
        $t = "--hostlist-auto=$copy";
      }
      $args[] = $t;
    }
  }
  $c['args'] = $args;
  return $c;
}

function memFreeMb(): ?int
{
  return preg_match('/^MemAvailable:\s+(\d+)/m', (string)@file_get_contents('/proc/meminfo'), $m) ? (int)((int)$m[1] / 1024) : null;
}

const LOWMEM_TOTAL_MB = 300;   // 128 и 256 МБ (Keenetic Viva и т. п.)
const LOWMEM_RESTART_MB = 30;  // перед перезапуском с проверкой: меньше — предупредить

// Слабый роутер: каждый запрос к api.php — отдельный php-cgi на 10–15 МБ, и несколько разом съедают всю память
// (NFQ-L130). Страница тогда шлёт запросы по одному
function lowMem(): bool
{
  return preg_match('/^MemTotal:\s+(\d+)/m', (string)@file_get_contents('/proc/meminfo'), $m) === 1 && (int)$m[1] / 1024 <= LOWMEM_TOTAL_MB;
}

function cfgJob(array $job, array &$status, ?array &$nfq, string $iface): void
{
  $repeats = max(1, min(5, (int)($job['repeats'] ?? 3)));
  $stop = fn() => is_file(TEST_DIR . '/stop');
  set_time_limit(60 + count($job['configs']) * count($job['hosts']) * $repeats * 20);
  $status += ['repeats' => $repeats, 'sites' => [], 'configs' => []];
  $status['total'] = count($job['hosts']) + count($job['configs']);
  $status['phase'] = 'baseline';

  // без обхода: что открывается и так (у такого сайта конфиг может только навредить)
  testRules('bypass', $iface);
  foreach ($job['hosts'] as $h) {
    if ($stop()) {
      break;
    }
    $status['current'] = "$h без обхода";
    testSaveStatus($status);
    $b = curlProbe($h, false);
    $status['sites'][] = ['host' => $h, 'baseline' => ['ok' => $b['ok'], 'reason' => $b['reason'] ?? null]];
    $status['done']++;
  }

  $status['phase'] = 'check';
  testRules('queue', $iface);
  foreach ($job['configs'] as $k => $cfg) {
    $c = cfgPrepare($cfg['name'], $cfg['text'], $k, !empty($cfg['mine'])) + ['mine' => !empty($cfg['mine'])];
    if ($stop()) {
      $status['state'] = 'stopped';
      break;
    }
    $status['current'] = $c['name'];
    testSaveStatus($status);
    $free = memFreeMb();
    if ($c['skip'] === null && $free !== null && $free < CFG_MIN_FREE_MB) {
      $c['skip'] = "Мало свободной памяти ($free МБ) — второй nfqws2 с полным конфигом не запущен";
    }
    if ($c['skip'] === null) {
      $nfq = testNfqwsStart($c['args']);
      if (!$nfq) {
        $c['skip'] = 'nfqws2 не запустился: ' . trim((string)@file_get_contents(TEST_DIR . '/nfqws.out'));
      }
    }
    $c['per'] = [];
    foreach ($c['skip'] === null ? $job['hosts'] : [] as $h) {
      if ($stop()) {
        break;
      }
      $status['current'] = "{$c['name']}: $h";
      testSaveStatus($status);
      $ok = 0;
      $times = [];
      $reason = null;
      for ($t = 0; $t < $repeats; $t++) {
        $r = curlProbe($h, false);
        if (!$r['ok']) {
          $reason = $r['reason'];
          break;   // как в подборе: первая же неудача — дальше не тратим время
        }
        $ok++;
        $times[] = $r['ms'];
      }
      $c['per'][$h] = ['ok' => $ok, 'tries' => min($t + 1, $repeats), 'ms' => $times ? (int)round(array_sum($times) / count($times)) : null, 'reason' => $reason];
    }
    testNfqwsStop($nfq);
    $nfq = null;
    $ms = array_filter(array_column($c['per'], 'ms'));
    $c['opened'] = count(array_filter($c['per'], fn($x) => $x['ok'] > 0 && $x['ok'] === $x['tries']));
    $c['ms'] = $ms ? (int)round(array_sum($ms) / count($ms)) : null;
    unset($c['args']);
    $status['configs'][] = $c;
    $status['done']++;
    testSaveStatus($status);
  }
  testRules('off', $iface);
  foreach (glob(TEST_DIR . '/cfg*-auto.list') ?: [] as $f) {
    @unlink($f);
  }
  if ($status['state'] === 'running') {
    $status['state'] = 'done';
  }
  $status['finished'] = time();
  $status['current'] = null;
  testSaveStatus($status);
  // итог живёт до перезагрузки роутера: следующий подбор перепишет статус задания, а этот останется
  file_put_contents(CFG_FILE, json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// ----- обрыв на 16 КБ: проба по сетям -----
// Провайдер пропускает начало соединения с зарубежными хостингами и замораживает его после ~16–20 КБ.
// Перебор обычных стратегий тут не помогает; помогает фейк с именем из «белого списка» провайдера, и имя
// подходит не для сайта, а для сети (AS) целиком. Проба ходит на опорные адреса в крупных сетях, ищет обрыв
// и для каждой сети с обрывом подбирает имя. Найденное ставится профилем на список адресов этой сети.

// [подпись, адрес, путь]: файлы больше 64 КБ, которые отдаются по HTTPS кусками (проверено с роутера)
const FREEZE_REFS = [
  ['OVH', 'proof.ovh.net', '/files/1Mb.dat'],
  ['Linode (Akamai)', 'speedtest.frankfurt.linode.com', '/100MB-frankfurt.bin'],
  ['Vultr', 'fra-de-ping.vultr.com', '/vultr.com.100MB.bin'],
  ['DataPacket (CDN77)', 'fra.download.datapacket.com', '/100mb.bin'],
  ['Amazon CloudFront', 'ip-ranges.amazonaws.com', '/ip-ranges.json'],
  ['Cloudflare', 'speed.cloudflare.com', '/__down?bytes=200000'],
  ['Selectel — Россия, для сравнения', 'speedtest.selectel.ru', '/10MB'],
];
// Сети без своего сайта с большим файлом — по адресу (padProbe): [подпись, адрес, порт, AS, имя в приветствии].
// Адреса — из runnin4ik/dpi-detector, tcp16.json (MIT), по одному на сеть; у Hetzner собственные адреса
// (fsn1/hel1-speed.hetzner.com) у части провайдеров не соединяются вовсе, поэтому и они здесь
const FREEZE_IPS = [
  ['Hetzner', '91.98.156.82', 443, 24940, null],
  ['Hetzner Cloud', '5.161.249.234', 443, 213230, null],
  ['DigitalOcean', '157.230.91.62', 443, 14061, null],
  ['Contabo', '173.249.28.198', 443, 51167, null],
  ['Scaleway', '62.4.10.75', 443, 12876, null],
  ['Oracle Cloud', '132.145.253.77', 443, 31898, null],
  ['Google Cloud', '35.227.66.60', 443, 396982, null],
  ['Akamai', '23.222.76.4', 443, 20940, null],
  ['Fastly (GitHub)', '185.199.108.133', 443, 54113, 'release-assets.githubusercontent.com'],
  ['Gcore', '62.112.221.146', 443, 199524, null],
  ['Melbicom', '109.122.201.147', 443, 8849, null],
  ['M247', '146.70.158.22', 443, 9009, null],
  ['FranTech (BuyVM)', '107.189.29.143', 443, 53667, null],
];
const FREEZE_SNI = 'example.com';   // имя в приветствии, когда у адреса своего нет
// Имена для фейка — сайты, которые провайдеры обычно не трогают. Первыми — те, что снимали обрыв у многих
// (ya.ru — у автора; 300.ya.ru, hcaptcha.com, ad.adriver.ru — замеры z2k и dpi-detector, whitelist_sni.txt)
const FREEZE_NAMES = ['ya.ru', '300.ya.ru', 'hcaptcha.com', 'ad.adriver.ru', 'vk.com', 'gosuslugi.ru', 'ozon.ru', 'www.google.com'];
// приёмы, в которые подставляется имя (NAME). Первый снимает и замирание до ответа (опорные адреса Hetzner),
// второй — обрыв на объёме (king.hr, Linode, Vultr); проверено у провайдера с обоими видами
const FREEZE_TEMPLATES = [
  ['--lua-desync=hostfakesplit:repeats=4:tcp_ts=-600000:tcp_md5:host=NAME'],
  ['--lua-desync=hostfakesplit:tcp_md5:tcp_ts_up:repeats=16:host=NAME'],
  ['--lua-desync=fake:blob=fake_default_tls:tls_mod=rnd,dupsid,sni=NAME:tcp_md5:tcp_ts=-600000:repeats=16'],
];
const FREEZE_LIMIT = 900;   // секунд на подбор имён всех сетей
const FREEZE_NET_LIMIT = 150;   // и на одну сеть
define('FREEZE_FILE', UI_CONF_DIR . '/freeze.json');

function freezeJob(array $job, array &$status, ?array &$nfq, string $iface, array $baseArgs): void
{
  // сеть — по имени с большим файлом (curlProbe) или по адресу (padProbe); свои сайты — первыми
  $refs = array_map(fn($r) => ['label' => $r[0], 'host' => $r[1], 'path' => $r[2]], FREEZE_REFS);
  foreach (FREEZE_IPS as [$label, $ip, $port, $asn, $sni]) {
    $refs[] = ['label' => $label, 'host' => $ip, 'path' => null, 'pad' => ['ip' => $ip, 'port' => $port, 'sni' => $sni ?? FREEZE_SNI], 'asn' => $asn];
  }
  foreach ($job['extra'] ?? [] as $h) {
    array_unshift($refs, ['label' => $h, 'host' => $h, 'path' => '/']);
  }
  $probeNet = fn(array $n): array => isset($n['pad']) ? padProbe($n['pad']['ip'], $n['pad']['port'], $n['pad']['sni']) : curlProbe($n['host'], false, $n['path']);
  $funcs = luaCatalog()['functions'];
  $templates = array_values(array_filter(FREEZE_TEMPLATES, fn($t) => isset($funcs[explode(':', substr($t[0], 13))[0]])));
  $filter = ['--filter-tcp=443', '--filter-l7=tls', '--payload=tls_client_hello'];
  $status['nets'] = [];
  $status['total'] = count($refs);
  $status['phase'] = 'baseline';
  testSaveStatus($status);
  $try = function (array $n, array $steps) use (&$nfq, $baseArgs, $filter, $probeNet): array {
    $nfq = testNfqwsStart(array_merge($baseArgs, $filter, $steps));
    if (!$nfq) {
      return ['ok' => 0, 'tries' => 0, 'ms' => null, 'reason' => 'nfqws2 не принял параметры'];
    }
    $ok = 0;
    $ms = [];
    $reason = null;
    $tries = 0;
    // первая попытка может попасть в хвост прошлой неудачи: провайдер какое-то время помнит замёрзшее соединение
    // с этим адресом (у Hetzner так) — одна повторная через 3 секунды; засчитывается только три подряд
    for ($i = 0; $i < 3; $i++) {
      $tries++;
      $r = $probeNet($n);
      if (!$r['ok'] && $tries === 1) {
        sleep(3);
        $tries++;
        $r = $probeNet($n);
      }
      if (!$r['ok']) {
        $reason = $r['reason'];
        break;
      }
      $ok++;
      $ms[] = $r['ms'];
    }
    testNfqwsStop($nfq);
    $nfq = null;
    return ['ok' => $ok, 'tries' => $tries, 'ms' => $ms ? (int)round(array_sum($ms) / count($ms)) : null, 'reason' => $reason];
  };
  // сначала все сети без обхода: где обрыв есть
  testRules('bypass', $iface);
  foreach ($refs as $ref) {
    if (is_file(TEST_DIR . '/stop')) {
      break;
    }
    $status['current'] = $ref['label'];
    testSaveStatus($status);
    if (isset($ref['pad'])) {
      $ip = $ref['pad']['ip'];
      $asn = $ref['asn'];
    } else {
      $ip = diagDns($ref['host'])['ips'][0] ?? null;
      $asn = $ip && !(isIp4($ip) && ipReserved($ip)) ? (int)(ripe('network-info', $ip)['asns'][0] ?? 0) : 0;
    }
    $b = $ip ? $probeNet($ref) : ['ok' => false, 'code' => 0, 'ms' => 0, 'reason' => 'имя не разрешается'];
    // замирает и раньше, до ответа сервера (у Hetzner так): соединение с адресом есть, дальше тишина — лечится тем же
    $stall = !$b['ok'] && $b['reason'] === 'тайм-аут' && array_filter(diagTcp([$ip], $ref['pad']['port'] ?? 443), fn($x) => $x['ok']);
    $status['nets'][] = $ref + ['ip' => $ip, 'asn' => $asn ?: null, 'baseline' => $b,
      'freeze' => (!$b['ok'] && $b['reason'] === REASON_FREEZE) || $stall, 'stall' => (bool)$stall, 'found' => null, 'tried' => []];
    $status['done']++;
    testSaveStatus($status);
  }
  // затем для каждой сети с обрывом — имя; хватает первого, открывшего 3 раза из 3
  $frozen = array_keys(array_filter($status['nets'], fn($n) => $n['freeze']));
  $status['phase'] = 'names';
  $status['total'] += count($frozen);
  testSaveStatus($status);
  testRules('queue', $iface);
  $started = time();
  foreach ($frozen as $k) {
    $n = &$status['nets'][$k];
    // имя подбирается для сети: нашлось на другом адресе той же сети — берём его (свои сайты идут первыми)
    foreach ($status['nets'] as $o) {
      if ($n['asn'] && $o['asn'] === $n['asn'] && !empty($o['found'])) {
        $n['found'] = $o['found'] + ['via' => $o['label']];
        break;
      }
    }
    // у части сетей обрыв не снимает ни одно имя — не тратим на такую всё время пробы
    // сети различаются прежде всего именем: сначала все имена с первым приёмом, потом остальные приёмы
    $netStarted = time();
    foreach ($n['found'] ? [] : $templates as $t) {
      foreach (FREEZE_NAMES as $name) {
        if (is_file(TEST_DIR . '/stop') || time() - $started > FREEZE_LIMIT) {
          $status['state'] = is_file(TEST_DIR . '/stop') ? 'stopped' : 'time';
          break 3;
        }
        if (time() - $netStarted > FREEZE_NET_LIMIT) {
          $n['gave_up'] = true;
          break 2;
        }
        $steps = array_map(fn($x) => str_replace('NAME', $name, $x), $t);
        $status['current'] = "{$n['label']}: " . strategyName($steps) . " с именем $name";
        testSaveStatus($status);
        $r = $try($n, $steps);
        $n['tried'][] = ['name' => $name, 'steps' => $steps] + $r;
        if ($r['ok'] === 3) {
          $n['found'] = ['name' => $name, 'steps' => $steps, 'strategy' => strategyName($steps), 'ms' => $r['ms']];
          break 2;
        }
      }
    }
    unset($n);
    $status['done']++;
    testSaveStatus($status);
  }
  unset($n);
  testRules('off', $iface);
  $status['state'] = in_array($status['state'], ['stopped', 'time'], true) ? $status['state'] : 'done';
  $status['finished'] = time();
  $status['current'] = null;
  testSaveStatus($status);
  putSafe(FREEZE_FILE, json_encode(array_intersect_key($status, array_flip(['state', 'started', 'finished', 'nets'])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// Шаг без порядка параметров и без strategy=N: «hostfakesplit:tcp_md5:repeats=4» и «hostfakesplit:repeats=4:tcp_md5» — одно и то же
function stepNorm(string $tok): string
{
  $s = stepParse($tok);
  unset($s['p']['strategy']);
  ksort($s['p']);
  return stepBuild($s);
}

// Найденное пробой уже стоит в конфиге? Считается при каждом чтении итога: конфиг мог измениться после пробы.
// in_conf: profiles — профили с той же стратегией; route — через какой профиль на самом деле идёт TLS к адресу сети
function freezeInConf(array $nets): array
{
  $norm = fn(array $steps) => implode(' ', array_map('stepNorm', $steps));
  $same = [];
  foreach (configCandidates('https') as $c) {
    preg_match_all('/#(\d+)/', $c['from'], $m);
    $k = $norm($c['steps']);
    $same[$k] = array_values(array_unique(array_merge($same[$k] ?? [], array_map('intval', $m[1]))));
  }
  $profiles = null;
  $cache = new ListCache();
  foreach ($nets as &$n) {
    if (empty($n['found']['steps'])) {
      continue;
    }
    $profiles ??= currentProfiles();
    $r = matchRoute($profiles, $n['host'], array_filter([$n['ip'] ?? null]), 'tcp', 443, 'tls', $cache);
    $in = $same[$norm($n['found']['steps'])] ?? [];
    $why = '';
    // профиль с этой стратегией есть, но адрес до него не доходит — почему именно
    foreach ($r['steps'] as $st) {
      if (in_array($st['profile'], $in, true) && !$st['ok']) {
        $why = $st['why'];
        break;
      }
    }
    $n['in_conf'] = ['profiles' => $in, 'route' => $r['profile'], 'why' => $why, 'join' => $r['profile'] !== null && in_array($r['profile'], $in, true) ? null : freezeJoinable($profiles, $in, $r, $n, $cache)];
  }
  unset($n);
  return $nets;
}

// Профиль с той же стратегией, в который сеть можно влить строкой --ipset=<список сети> вместо нового профиля:
// «свой» профиль, отбирающий только по IP-спискам (у nfqws2 условия профиля складываются через «и» — со списком сайтов
// адреса сети прошли бы только для этих сайтов), до которого адрес сети доходит и который с этим адресом его взял бы
function freezeJoinable(array $profiles, array $in, array $route, array $n, ListCache $cache): ?int
{
  $reached = array_column($route['steps'], 'profile');
  foreach ($profiles as $p) {
    // список этой сети в профиле уже есть, а адрес в него не входит — вторая такая же строка не поможет
    if (!in_array($p['index'], $in, true) || !in_array($p['index'], $reached, true)
      || in_array(LISTS_DIR . "/ipset_as{$n['asn']}.list", $p['ipsets'], true)
      || ($p['source']['source'] ?? '') !== 'NFQWS_ARGS_CUSTOM' || !$p['ipsets']
      || $p['hostlists'] || $p['hostlist_domains'] || $p['autolist'] !== null) {
      continue;
    }
    // примерка: тот же профиль, будто адрес сети уже в его IP-списке
    $try = $p;
    $try['ipset_ips'][] = $n['ip'] ?? '';
    if (!empty($n['ip']) && matchRoute([$try], $n['host'], [$n['ip']], 'tcp', 443, 'tls', $cache)['profile'] === $p['index']) {
      return $p['index'];
    }
  }
  return null;
}

// Влить сеть в профиль: список адресов AS (обновляется сам) + строка --ipset в профиле; перезапуск — со страницы
function freezeJoin(int $asn, int $index): array
{
  $f = json_decode((string)@file_get_contents(FREEZE_FILE), true);
  $nets = array_values(array_filter($f['nets'] ?? [], fn($x) => (int)($x['asn'] ?? 0) === $asn && !empty($x['found']['steps']) && empty($x['found']['via'])));
  // конфиг могли поменять, пока страница была открыта, — условия проверяются заново
  if (!$nets || (freezeInConf([$nets[0]])[0]['in_conf']['join'] ?? null) !== $index) {
    fail('Профиль уже не подходит для этой сети — обновите страницу');
  }
  $a = asnFetch($asn);
  if (!$a['prefixes']) {
    fail("У AS$asn нет анонсированных префиксов");
  }
  $w = asnWrite($a, true);
  $note = "сеть AS$asn добавлена в профиль #$index: обрыв на 16 КБ";
  $new = profileAddToken((string)file_get_contents(CONF_FILE), profileByIndex($index), '--ipset=' . $w['path']);
  $lint = lintConf(confRawVars($new), $new);
  if (!$lint['dry_run'] || !$lint['dry_run']['ok']) {
    fail('nfqws2 не принимает конфиг с этим списком: ' . ($lint['dry_run']['message'] ?? 'ошибка'), 422);
  }
  if (!writeWithBackup(CONF_FILE, $new, $note)) {
    fail('Не удалось записать конфиг', 500);
  }
  return ['list' => $w['name'], 'count' => $w['count'], 'profile' => $index];
}

// Находки последней пробы для подбора: сначала найденное в сети этого сайта, затем в остальных сетях
function freezeCandidates(string $host): array
{
  $f = json_decode((string)@file_get_contents(FREEZE_FILE), true);
  $nets = array_filter($f['nets'] ?? [], fn($n) => !empty($n['found']['steps']));
  if (!$nets) {
    return [];
  }
  $asn = hostAsn($host);
  $out = [];
  foreach ($nets as $n) {
    $same = $asn && ($n['asn'] ?? null) === $asn;
    $key = implode(' ', $n['found']['steps']);
    if (isset($out[$key]) && !$same) {
      continue;
    }
    $out[$key] = ['name' => strategyName($n['found']['steps']) . ' · ' . $n['found']['name'], 'steps' => $n['found']['steps'], 'rank' => 1, 'same' => $same ? 1 : 0,
      'from' => 'проба обрыва: ' . ($same ? "сеть сайта AS$asn" : 'помогло в сети ' . $n['label'])];
  }
  usort($out, fn($a, $b) => $b['same'] <=> $a['same']);
  return array_map(fn($c) => array_diff_key($c, ['same' => 1]), $out);
}

// ----- история подборов: что и когда работало для каждого сайта -----

const PICKS_MAX = 100;       // запусков всего
const PICKS_PER_HOST = 10;   // и на один сайт
const PICKS_OTHER = 5;       // сколько чужих находок пробовать в подборе

function picksLoad(): array
{
  $f = UI_CONF_DIR . '/picks.json';
  if (is_file($f)) {
    $raw = (string)@file_get_contents($f);
    $d = json_decode($raw, true);
    if (!is_array($d) && strlen($raw) > 2) {
      @copy($f, "$f.bad");   // файл испорчен (обрыв питания при записи) — не затираем молча, оставляем для разбора
    }
    return is_array($d) ? $d : [];
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
  putSafe($f, $json);
}

function picksAdd(array $status, int $repeats): void
{
  $full = array_values(array_filter($status['results'], fn($r) => $r['ok'] === $repeats));
  usort($full, fn($a, $b) => ($a['ms'] ?? PHP_INT_MAX) <=> ($b['ms'] ?? PHP_INT_MAX));
  $rec = ['ts' => time(), 'host' => $status['host'], 'proto' => $status['proto'], 'baseline' => $status['baseline'] ?? null,
    // после параллельного прогона в results только то, что хоть раз открыло сайт, — сколько проверено, считаем по done
    'ok' => count($full), 'total' => !empty($status['parallel']) ? max(count($status['results']), $status['done'] - 1) : count($status['results']), 'best' => $full ? $full[0]['name'] : null,
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

// ----- стратегии сообщества: что сработало у абонентов того же провайдера -----
// База — отдельный репозиторий: файл на провайдера data/AS<номер>.json и сводка index.json; пополняется
// формой issue (кнопка «Поделиться»), присланное проверяет GitHub Action. Роутер качает только файл своего
// провайдера (килобайты при любом размере базы); всё из него сначала проверяется подбором.

const COMMUNITY_REPO = 'https://github.com/zemidala/nfqws2-strategies';
const COMMUNITY_RAW = 'https://raw.githubusercontent.com/zemidala/nfqws2-strategies/main/';
const COMMUNITY_FILE = '/tmp/nfqws-ui-community.json';   // в памяти: скачивается заново после перезагрузки
const COMMUNITY_INDEX = '/tmp/nfqws-ui-community-index.json';
const COMMUNITY_MAX = 8;   // сколько стратегий сообщества пробовать в одном подборе
// шаг — только --lua-desync без путей и блобов из файлов; то же проверяет и приём в репозитории
const COMMUNITY_STEP = '/^--lua-desync=[a-z][a-z0-9_]{0,40}(:[A-Za-z0-9_.,=+%-]{1,200}){0,40}$/';

// Файл из репозитория базы: [код, тело, ошибка]. Соединение не состоялось (чаще всего — DNS роутера
// на секунду пропал при перезапуске AdGuard) — ещё одна попытка через 3 секунды.
function communityFetch(string $path): array
{
  for ($try = 0; $try < 2; $try++) {
    if ($try) {
      sleep(3);
    }
    $ch = curl_init(COMMUNITY_RAW . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_NOSIGNAL => 1,
      CURLOPT_HTTPHEADER => ['User-Agent: nfqws2-ui/' . UI_VERSION]]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($code !== 0) {
      break;
    }
  }
  return [$code, (string)$res, $err];
}

// Стратегии своего провайдера: удачный ответ помним сутки, неудачу — 10 минут (как проверку обновлений).
// Файла провайдера нет (404) — значит, для него в базе пока ничего нет; это не ошибка.
function communityLoad(int $asn, bool $force = false): array
{
  $c = json_decode((string)@file_get_contents(COMMUNITY_FILE), true);
  $c = is_array($c) && ($c['asn'] ?? 0) === $asn ? $c + ['fetched' => 0, 'error' => null, 'items' => []] : ['asn' => $asn, 'fetched' => 0, 'error' => null, 'items' => [], 'checked' => 0];
  if (!$force && time() - ($c['checked'] ?? 0) < ($c['error'] ? 600 : 86400)) {
    return $c;
  }
  [$code, $res, $err] = communityFetch("data/AS$asn.json");
  $j = json_decode($res, true);
  $c['checked'] = time();
  if ($code === 404) {
    $c = ['asn' => $asn, 'checked' => time(), 'fetched' => time(), 'error' => null, 'items' => []];
  } elseif ($code === 200 && is_array($j['items'] ?? null)) {
    // данные из интернета: берём только то, что подходит по формату
    $items = [];
    foreach ($j['items'] as $x) {
      $steps = is_array($x['steps'] ?? null) ? array_values(array_filter($x['steps'], fn($t) => is_string($t) && preg_match(COMMUNITY_STEP, $t))) : [];
      if (!$steps || count($steps) !== count($x['steps']) || count($steps) > 8 || ($x['asn'] ?? null) !== $asn || !in_array($x['proto'] ?? '', ['tls', 'http'], true)) {
        continue;
      }
      $targets = [];
      foreach (array_slice(is_array($x['targets'] ?? null) ? $x['targets'] : [], 0, 30) as $t) {
        $h = is_string($t['host'] ?? null) && validHost($t['host']) && !isIp($t['host']) ? $t['host'] : null;
        $a = is_int($t['asn'] ?? null) ? $t['asn'] : null;
        if ($h || $a) {
          $targets[] = array_filter(['host' => $h, 'asn' => $a]);
        }
      }
      $str = fn($k, $n) => is_string($x[$k] ?? null) ? (preg_match('/^[^\x00-\x1f<>`$\\\\]{0,' . $n . '}/u', $x[$k], $m) ? $m[0] : '') : '';
      $items[] = ['id' => preg_replace('/[^0-9a-f]/', '', $str('id', 16)), 'asn' => $x['asn'], 'provider' => $str('provider', 80), 'proto' => $x['proto'],
        'steps' => $steps, 'targets' => $targets, 'reports' => max(0, (int)($x['reports'] ?? 1)), 'fails' => max(0, (int)($x['fails'] ?? 0)), 'last_fail' => $str('last_fail', 10),
        'issues' => array_values(array_filter(array_slice(is_array($x['issues'] ?? null) ? $x['issues'] : [], 0, 20), 'is_int')),
        'first' => $str('first', 10), 'last' => $str('last', 10), 'nfqws2' => $str('nfqws2', 30)];
    }
    $c = ['asn' => $asn, 'checked' => time(), 'fetched' => time(), 'error' => null, 'items' => $items];
  } else {
    $c['error'] = $err ?: "GitHub ответил $code";
  }
  @file_put_contents(COMMUNITY_FILE, json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  return $c;
}

// Сводка по всей базе — только для строки «во всей базе N стратегий для M провайдеров»; не удалась — без неё
function communityIndex(bool $force = false): ?array
{
  $c = json_decode((string)@file_get_contents(COMMUNITY_INDEX), true);
  if (!$force && is_array($c) && time() - ($c['checked'] ?? 0) < 86400) {
    return $c;
  }
  [$code, $res] = communityFetch('index.json');
  $j = json_decode($res, true);
  if ($code !== 200 || !is_array($j['providers'] ?? null)) {
    return is_array($c) ? $c : null;
  }
  $c = ['checked' => time(), 'total' => max(0, (int)($j['total'] ?? 0)), 'providers' => count($j['providers'])];
  @file_put_contents(COMMUNITY_INDEX, json_encode($c));
  return $c;
}

// Провайдер по внешнему адресу (RIPEstat); сам адрес никуда не сохраняется и не показывается
function providerDetect(): ?array
{
  $ip = ripe('whats-my-ip')['ip'] ?? null;
  $asn = $ip ? (int)(ripe('network-info', $ip)['asns'][0] ?? 0) : 0;
  if (!$asn) {
    return null;
  }
  $holder = (string)(ripe('as-overview', "AS$asn")['holder'] ?? '');
  $s = uiSettings();
  $s['provider_asn'] = $asn;
  if ($s['provider'] === '' && $holder !== '') {
    $s['provider'] = preg_match('/^[^\x00-\x1f"`$\\\\]{0,80}/u', $holder, $m) ? $m[0] : '';
  }
  saveUiSettings($s);
  return ['provider' => $holder, 'asn' => $asn];
}

// AS провайдера: из настроек; $detect — если не задан, определить (запрос к RIPEstat)
function myAsn(bool $detect): int
{
  $asn = (int)uiSettings()['provider_asn'];
  if (!$asn && $detect) {
    $asn = (int)(providerDetect()['asn'] ?? 0);
  }
  return $asn;
}

// Сеть сайта: по первому адресу; адрес podkop (FakeIP) или заглушка — не определить
function hostAsn(string $host): ?int
{
  $ip = diagDns($host)['ips'][0] ?? null;
  if (!$ip || (isIp4($ip) && ipReserved($ip))) {
    return null;
  }
  $a = (int)(ripe('network-info', $ip)['asns'][0] ?? 0);
  return $a ?: null;
}

// Стратегии сообщества для подбора: сначала работавшие для этого сайта, затем для его сети, затем — по числу подтверждений
function communityCandidates(string $host, string $proto, ?array &$info): array
{
  $asn = myAsn(true);
  $d = $asn ? communityLoad($asn) : ['items' => [], 'error' => null];
  $p = $proto === 'http' ? 'http' : 'tls';
  $mine = array_filter($d['items'], fn($x) => $x['asn'] === $asn && $x['proto'] === $p);
  $tAsn = $mine ? hostAsn($host) : null;
  $out = [];
  foreach ($mine as $x) {
    // «не сработала» у большинства (и хотя бы у двоих) — не пробуем; на странице сообщества она видна
    if ($x['fails'] >= 2 && $x['fails'] > $x['reports']) {
      continue;
    }
    $hosts = array_column($x['targets'], 'host');
    $nets = array_column($x['targets'], 'asn');
    $score = in_array($host, $hosts, true) ? 2 : ($tAsn && in_array($tAsn, $nets, true) ? 1 : 0);
    $fresh = communityFresh($x) ? 1 : 0;
    $out[] = ['name' => strategyName($x['steps']), 'steps' => $x['steps'], 'community' => $x['id'], 'rank' => $score && $fresh ? 1 : 2,
      'key' => [$fresh, $score, $x['reports'] - $x['fails'], $x['last']],
      'from' => 'сообщество: ' . ($score === 2 ? "открывала $host" : ($score === 1 ? "работала в сети AS$tAsn" : "AS$asn")) . ', подтвердили ' . $x['reports']
        . ($x['fails'] ? ', не сработала у ' . $x['fails'] : '') . ($fresh ? '' : ', давно не подтверждалась')];
  }
  // сначала подтверждённые недавно; среди них — тот же сайт, затем та же сеть, затем по «работает» минус «не сработала».
  // В подборе свежие с совпадением сайта или сети идут вместе с историей (rank 1), остальные — со стандартным набором
  usort($out, fn($a, $b) => $b['key'] <=> $a['key']);
  $info = ['asn' => $asn, 'count' => count($mine), 'error' => $asn ? $d['error'] : 'провайдер не определён'];
  return array_map(fn($c) => array_diff_key($c, ['key' => 1]), array_slice($out, 0, COMMUNITY_MAX));
}

// Подтверждалась ли стратегия за последние COMMUNITY_FRESH_DAYS дней: DPI меняется, старое опускаем
const COMMUNITY_FRESH_DAYS = 60;
function communityFresh(array $x): bool
{
  $t = strtotime((string)$x['last']);
  return $t !== false && time() - $t < COMMUNITY_FRESH_DAYS * 86400;
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
    putSafe(AUTO_FILE, $json);
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
  testSaveStatus(['state' => 'starting', 'type' => $job['type'], 'host' => $job['host'], 'started' => time()] + (isset($job['pick']) ? ['pick' => $job['pick']] : []));
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
    $policyOnly ??= (interceptInfo()['policy']['mode'] ?? '') === 'only' && !policyProbeEnsure();
    // «только политика», а пометить проверки роутера её меткой не вышло: мониторинг ходит мимо nfqws2,
    // сайт «не открывается» всегда, поломки стратегии за этим нет
    $skip = $policyOnly ? 'nfqws2 обрабатывает только устройства политики доступа, запросы роутера идут мимо него — мониторинг не показывает, работает ли обход'
      : ($massive ? 'не открывается больше половины сайтов мониторинга — похоже на обрыв связи, а не на блокировку'
      : (findPid() === null ? 'nfqws2 не запущен — подбирать стратегию бессмысленно'
      : (str_starts_with($last = (string)(end($mon['sites'][$h])[3] ?? ''), 'DNS роутера отдаёт') ? 'DNS роутера подменяет адрес сайта — стратегия тут не поможет, нужен защищённый DNS (DoH/DoT) в настройках роутера'
      : (str_contains($last, REASON_OTHER_SERVER) ? 'по другому адресу сайт открывается, а по адресу от DNS роутера — нет: этот сервер, похоже, закрыт по IP, стратегия тут не поможет' : null))));
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
    if (($ce = curlCmdError()) !== null) {
      autoLog($d, $h, 'skip', 'Пропущен: не запускается программа curl (' . $ce . '), подбор не смог бы проверить ни одной стратегии.');
      autoSave($d);
      return;
    }
    autoLog($d, $h, 'start', 'Перестал открываться — запущен подбор стратегии.');
    autoSave($d);
    testLaunch(['type' => 'pick', 'host' => $h, 'proto' => 'https', 'sets' => ['config', 'std', 'hist', 'other', 'community'], 'repeats' => 3, 'refine' => false, 'auto' => true]);
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
    // если сработала стратегия уже существующего профиля, а сайта просто нет в его списке — хватит записи в списке
    $list = null;
    foreach ($full as $r) {
      if ($list = autoListTarget($h, (string)($r['from'] ?? ''))) {
        $best = $r;
        break;
      }
    }
    $d['offers'][$h] = ['ts' => time(), 'name' => $best['name'], 'steps' => $best['steps'], 'from' => $best['from']] + ($list ? ['list' => $list] : []);
    autoLog($d, $h, 'found', 'Найдена рабочая стратегия: ' . $best['name'] . ($list ? " — она уже стоит в профиле #{$list['profile']}, достаточно добавить сайт в {$list['name']}." : '.'));
    autoSave($d);
    if (uiSettings()['auto']['apply']) {
      $r = $list ? autoApplyList($h, $best['steps'], $list, 'автоподбор') : ['ok' => false];
      $viaList = $r['ok'];
      if (!$r['ok']) {
        // запись в списке не помогла или не подходит — отдельный профиль
        $r = autoApply($h, $best['steps'], 'автоподбор');
      }
      $d = autoData();
      $msg = $r['ok'] ? "🔧 $h: найдена и применена стратегия «{$best['name']}» — " . ($viaList ? "сайт добавлен в {$list['name']} (профиль #{$list['profile']})." : 'отдельный профиль только для этого сайта.')
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

// Стратегия взята из профиля #N («профиль #9, #11»; «#N стратегия K» — circular, не годится), а до него сайт
// не доходит только потому, что его нет в списке сайтов профиля. Возвращает профиль и список, куда добавить сайт
function autoListTarget(string $host, string $from): ?array
{
  if (!preg_match_all('/#(\d+)(?=,|$)/', $from, $m)) {
    return null;
  }
  $profiles = currentProfiles();
  $route = matchRoute($profiles, $host, gethostbynamel($host) ?: [], 'tcp', 443, 'tls', new ListCache());
  foreach ($m[1] as $n) {
    $n = (int)$n;
    $step = current(array_filter($route['steps'], fn($x) => $x['profile'] === $n));
    $p = current(array_filter($profiles, fn($x) => $x['index'] === $n));
    if (!$step || !$p || ($step['miss'] ?? null) !== 'hostlist') {
      continue;
    }
    $lists = array_values(array_filter($p['hostlists'], fn($f) => dirname($f) === LISTS_DIR && is_file($f) && is_writable($f)));
    $pick = in_array(LISTS_DIR . '/user.list', $lists, true) ? LISTS_DIR . '/user.list' : ($lists[0] ?? null);
    if ($pick !== null) {
      return ['profile' => $n, 'name' => basename($pick)];
    }
  }
  return null;
}

// Запись сайта в список профиля: без перезапуска (nfqws2 перечитывает списки сам). Сайт должен открыться —
// иначе запись убирается, а предложение остаётся уже как «отдельный профиль»
function autoApplyList(string $host, array $steps, array $list, string $who): array
{
  $d = autoData();
  $done = function (bool $ok, string $kind, string $text) use (&$d, $host): array {
    autoLog($d, $host, $kind, $text);
    if ($ok) {
      unset($d['offers'][$host]);
    } else {
      unset($d['offers'][$host]['list']);
    }
    autoSave($d);
    return ['ok' => $ok, 'text' => $text];
  };
  $path = LISTS_DIR . '/' . basename((string)($list['name'] ?? ''));
  $n = (int)($list['profile'] ?? 0);
  // с момента подбора конфиг и списки могли измениться — условие проверяем заново
  $now = autoListTarget($host, "#$n");
  if (!$now || $now['name'] !== basename($path)) {
    return $done(false, 'fail', "В список не добавлено: профилю #$n уже мало записи в {$list['name']} — конфиг или списки изменились. Можно применить отдельным профилем.");
  }
  $old = (string)file_get_contents($path);
  set_time_limit(120);
  if (!writeWithBackup($path, ltrim(rtrim($old, "\n") . "\n$host", "\n"), "$who: $host в список профиля #$n")) {
    return $done(false, 'fail', 'В список не добавлено: не удалось записать файл.');
  }
  // nfqws2 замечает новый список при следующем соединении; первая попытка может уйти ещё по-старому
  sleep(2);
  $ok = false;
  for ($i = 0; $i < 3 && !($ok = probe($host)['ok']); $i++) {
    sleep(2);
  }
  if (!$ok) {
    writeWithBackup($path, $old, "откат ($who, $host): сайт не открылся");
    return $done(false, 'rolled', "Добавлен в {$list['name']} и убран обратно: $host так и не открылся. Можно применить отдельным профилем.");
  }
  $items = picksLoad();
  foreach ($items as &$e) {
    if ($e['host'] === $host) {
      $e['applied'] = ['ts' => time(), 'name' => strategyName($steps), 'steps' => $steps, 'target' => "список {$list['name']} профиля #$n ($who)"];
      picksSave($items);
      break;
    }
  }
  unset($e);
  monitorRun(true, [$host]);
  return $done(true, 'applied', "Сайт добавлен в {$list['name']}: теперь идёт через профиль #$n со стратегией " . strategyName($steps) . '. Сайт открывается, перезапуск не понадобился.');
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
  serviceRun('restart');
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
      serviceRun('restart');
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
// Фейк — копия настоящего ClientHello устройства (tls_client_hello_clone) вместо заготовки: отпечаток фейка
// совпадает с отпечатком настоящего трафика, и DPI не отличит его по телу. Помогает там, где заготовки «спалились»
// (замер z2k); приветствие браузера ~1900 байт nfqws2 сам режет на пакеты (проверено с Chrome 2026-10-09)
const CLONE_BLOB = 'tls_clone';
const CLONE_STEP = '--lua-desync=tls_client_hello_clone:blob=' . CLONE_BLOB . ':fallback=fake_default_tls';

// Стратегия с заменённым шагом фейка; фейку-копии нужен шаг копирования перед ним
function stepsWith(array $steps, int $fi, array $s): array
{
  $steps[$fi] = stepBuild($s);
  $steps = array_values(array_filter($steps, fn($t) => $t !== CLONE_STEP));
  if (($s['p']['blob'] ?? null) === CLONE_BLOB) {
    array_splice($steps, array_search(stepBuild($s), $steps, true), 0, [CLONE_STEP]);
  }
  return $steps;
}

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
    $name = $s['fn'] . (($s['p']['blob'] ?? null) === CLONE_BLOB ? ' (копия)' : '');
    if (isset($s['p']['repeats']) && (int)$s['p']['repeats'] > 1) {
      $name .= ' ×' . (int)$s['p']['repeats'];
    }
    $fool = array_values(array_intersect(array_keys($s['p']), FOOL_KEYS));
    return $name . ($fool && in_array($s['fn'], FAKE_FUNCS, true) ? ' ' . implode(',', array_map(fn($k) => $k === 'ip_autottl' ? 'autottl' : $k, $fool)) : '');
  }, array_values(array_filter($steps, fn($t) => $t !== CLONE_STEP))));
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
    // заготовка или копия приветствия устройства — когда заготовки у провайдера «спалились»
    if (!$http && $st['fn'] === 'fake' && str_contains((string)($st['p']['blob'] ?? ''), 'tls')) {
      $blob0 = $st['p']['blob'] === CLONE_BLOB ? 'fake_default_tls' : $st['p']['blob'];
      $axes[] = ['blob', 'Чем фейк', [
        ['заготовка', fn($s) => array_replace_recursive($s, ['p' => ['blob' => $blob0]])],
        ['копия приветствия', fn($s) => array_replace_recursive($s, ['p' => ['blob' => CLONE_BLOB]])],
      ]];
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
          $res = $try(stepsWith($steps, $fi, $s2));
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
      $steps = stepsWith($steps, $fi, $st);
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
  } elseif (!array_filter($system, fn($ip) => !ipReserved($ip)) || ipIspOwned($system[0])) {
    $status = 'fake';   // «серый» адрес или адрес из сети провайдера — заглушка
  } else {
    $status = 'differs';   // у CDN ответы разным резолверам отличаются — решит запрос к сайту
  }
  $useRef = in_array($status, ['no_system', 'podkop', 'fake'], true);
  return ['status' => $status, 'system' => $system, 'ref' => $ref, 'doh' => $doh, 'doh_answered' => $answered, 'podkop' => $podkop,
    'ips' => array_slice($useRef ? $ref : $system, 0, 3), 'alt' => $status === 'differs' ? array_slice($ref, 0, 2) : []];
}

// Адрес из сети провайдера (его AS)? Так выглядит заглушка, которую DNS провайдера отдаёт вместо заблокированного сайта.
// null — не узнать (AS провайдера не определилась, RIPEstat не ответил). Ответы RIPEstat помним сутки
function ipIspOwned(string $ip): ?bool
{
  if (!isIp4($ip) || ipReserved($ip)) {
    return null;
  }
  $my = myAsn(true);
  if (!$my) {
    return null;
  }
  $file = CACHE_DIR . '/ip-asn.json';
  $c = json_decode((string)@file_get_contents($file), true);
  $c = is_array($c) ? array_filter($c, fn($e) => time() - $e[1] < 86400) : [];
  if (!isset($c[$ip])) {
    $d = ripe('network-info', $ip, 8);
    if ($d === null) {
      return null;
    }
    $c[$ip] = [(int)($d['asns'][0] ?? 0), time()];
    @mkdir(CACHE_DIR, 0700, true);
    @file_put_contents($file, json_encode($c));
  }
  return $c[$ip][0] === $my;
}

// Сайт не открылся: не подменяет ли DNS роутера адрес. Сверка с защищённым DNS; если адреса разные — открываем сайт
// через nfqws2 по настоящему адресу. Подмена — адрес роутера из сети провайдера или по настоящему адресу сайт открывается
// (тогда дело не в стратегии). null — подмены не видно. Только для имён и только после неудачи: это 2–3 лишних запроса
function dnsSpoof(string $host, ?array $system = null): ?array
{
  if (isIp($host)) {
    return null;
  }
  $system = array_values(array_filter($system ?? (gethostbynamel($host) ?: []), 'isIp4'));
  // podkop отдаёт свои адреса (FakeIP) намеренно — это не подмена
  if (!$system || array_filter($system, fn($ip) => ipInEntry(inet_pton($ip), '198.18.0.0/15'))) {
    return null;
  }
  $ref = [];
  foreach (DIAG_DOH as [, $server, $ip]) {
    $r = dohResolve($server, $ip, $host);
    $ref = array_merge($ref, $r['ok'] ? $r['ips'] : []);
  }
  $ref = array_values(array_filter(array_unique($ref), fn($ip) => isIp4($ip) && !ipReserved($ip)));
  if (!$ref || array_intersect($system, $ref)) {
    return null;
  }
  $isp = ipIspOwned($system[0]);
  $real = probe($host, $ref[0]);
  if (!$isp && !$real['ok'] && !array_filter($system, 'ipReserved')) {
    return null;   // у CDN ответы разным DNS отличаются, а по «настоящему» адресу тоже не открылось — подмены не видно
  }
  // fake — заглушка (адрес провайдера или «серый»); иначе это другой сервер того же сайта: у больших сетей (Meta, Google)
  // разные DNS отдают разные адреса, и часть из них провайдер закрывает по IP — DNS тут не виноват
  $fake = $isp !== false || (bool)array_filter($system, 'ipReserved');
  return ['system' => array_slice($system, 0, 3), 'real' => array_slice($ref, 0, 3), 'isp' => $isp, 'fake' => $fake,
    'real_ok' => $real['ok'], 'real_code' => $real['code'], 'real_ms' => $real['ms'], 'real_reason' => $real['reason']];
}

// Строка для мониторинга и журнала
const REASON_OTHER_SERVER = 'похоже, этот сервер закрыт по IP';

function dnsSpoofReason(array $sp): string
{
  if (!($sp['fake'] ?? true)) {
    return 'адрес ' . $sp['system'][0] . ' не открывается, а ' . $sp['real'][0] . ' от защищённого DNS открывается — ' . REASON_OTHER_SERVER;
  }
  return 'DNS роутера отдаёт ' . $sp['system'][0] . ($sp['isp'] ? ' (сеть провайдера)' : '') . ' вместо ' . $sp['real'][0]
    . ($sp['real_ok'] ? ' — по настоящему адресу открывается' : '');
}

// Адреса сайта не соединяются: не выдаёт ли другой DNS адрес, по которому сайт открывается. У больших сетей
// (Meta, Google, CDN) разные DNS отдают разные адреса, и закрытым по IP бывает только часть — тогда дело в DNS,
// а не в блокировке сайта. $tried — адреса, уже проверенные. Запрос к сайту — мимо nfqws2, как ступень 3
const DIAG_DOH_MORE = [['Cloudflare', 'cloudflare-dns.com', '1.1.1.1'], ['Quad9', 'dns.quad9.net', '9.9.9.9']];

function diagOther(string $host, array $tried): array
{
  $cand = [];
  foreach (array_merge(DIAG_DOH, DIAG_DOH_MORE) as [$name, $server, $ip]) {
    $r = dohResolve($server, $ip, $host);
    foreach ($r['ok'] ? $r['ips'] : [] as $a) {
      if (isIp4($a) && !in_array($a, $tried, true) && !ipReserved($a)) {
        $cand[$a] ??= $name;
      }
    }
  }
  // Признак — соединение с адресом: блокировку по имени (DPI) на нём снимет nfqws2, а закрытый по IP адрес — нет.
  // Открылся ли сайт мимо nfqws2 — только для пояснения
  $checked = [];
  foreach (array_slice(array_keys($cand), 0, 4) as $a) {
    $up = diagTcp([$a])[0]['ok'];
    $r = $up ? diagBypass(fn() => diagDirect($host, $a)) : null;
    $checked[] = ['ip' => $a, 'from' => $cand[$a], 'tcp' => $up, 'open' => $r && $r['ok'] && !$r['isp_page']];
    if ($up) {
      break;
    }
  }
  $good = array_values(array_filter($checked, fn($c) => $c['tcp']));
  return ['checked' => $checked, 'ok' => (bool)$good, 'ip' => $good[0]['ip'] ?? null, 'from' => $good[0]['from'] ?? null, 'open' => $good[0]['open'] ?? false];
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
    // адрес от своего DNS закрыт, а по адресу от другого DNS сайт открывается
    if (!empty($r['other']['ok'])) {
      return $v('dns_other', empty($r['other']['open']));   // и по другому адресу мимо nfqws2 не открылся — нужен и обход
    }
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

// ----- QUIC (HTTP/3) -----
// curl на роутерах собран без HTTP/3, а в PHP нет openssl — первый пакет QUIC собирает сам nfqws2: с --intercept=0 он
// выполняет Lua и выходит, а в его Lua есть всё нужное шифрование (hash, aes, aes_gcm). Пакет — настоящий QUIC v1
// Initial (RFC 9000, 9001) с ClientHello на имя сайта; сервер отвечает на него своим Initial, а провайдер, который
// режет QUIC по имени, — молчанием. Отправляет пакет PHP по UDP: любой ответ значит «QUIC дошёл».
const QUIC_LUA = <<<'LUA'
local function u8(n) return string.char(n % 256) end
local function u16(n) return string.char(math.floor(n / 256) % 256, n % 256) end
local function u24(n) return string.char(math.floor(n / 65536) % 256, math.floor(n / 256) % 256, n % 256) end
local function v16(b) return u16(#b) .. b end
local function varint(n) if n < 64 then return u8(n) end return u16(0x4000 + n) end

local function hmac(key, msg)
  key = key .. string.rep("\0", 64 - #key)
  return hash("sha256", bxor(key, string.rep("\92", 64)) .. hash("sha256", bxor(key, string.rep("\54", 64)) .. msg))
end
local function expand_label(secret, label, len)
  local l = "tls13 " .. label
  return hmac(secret, u16(len) .. u8(#l) .. l .. u8(0) .. "\1"):sub(1, len)
end

local dcid, scid = brandom(8), brandom(8)

-- ClientHello TLS 1.3: имя, группы, подписи, ALPN h3, версия, ключ x25519, параметры QUIC
local function ext(t, b) return u16(t) .. v16(b) end
local tp = u8(0x0f) .. u8(#scid) .. scid .. u8(0x04) .. u8(4) .. "\128\16\0\0" .. u8(0x01) .. u8(2) .. u16(0x4000 + 13616)
local exts = ext(0, v16(u8(0) .. v16(SNI)))
  .. ext(10, v16(u16(0x001d) .. u16(0x0017)))
  .. ext(13, v16(u16(0x0403) .. u16(0x0804) .. u16(0x0401) .. u16(0x0503) .. u16(0x0805) .. u16(0x0501) .. u16(0x0806) .. u16(0x0601)))
  .. ext(16, v16(u8(2) .. "h3"))
  .. ext(43, u8(2) .. u16(0x0304))
  .. ext(45, u8(1) .. u8(1))
  .. ext(51, v16(u16(0x001d) .. v16(brandom(32))))
  .. ext(57, tp)
local body = u16(0x0303) .. brandom(32) .. u8(0) .. v16(u16(0x1301) .. u16(0x1302) .. u16(0x1303)) .. u8(1) .. u8(0) .. v16(exts)
local ch = u8(1) .. u24(#body) .. body

-- кадр CRYPTO и добивка: дейтаграмма с Initial не короче 1200 байт
local frames = u8(6) .. varint(0) .. varint(#ch) .. ch
local pn_len = 4
local need = 1200 - (1 + 4 + 1 + 8 + 1 + 8 + 1 + 2) - pn_len - 16
if #frames < need then frames = frames .. string.rep("\0", need - #frames) end

-- ключи Initial: соль QUIC v1 и идентификатор соединения
local salt = "\56\118\44\247\245\89\52\179\77\23\154\230\164\200\12\173\204\187\127\10"
local cis = expand_label(hmac(salt, dcid), "client in", 32)
local key, iv, hp = expand_label(cis, "quic key", 16), expand_label(cis, "quic iv", 12), expand_label(cis, "quic hp", 16)

local pn = "\0\0\0\0"
local header = u8(0xC3) .. "\0\0\0\1" .. u8(8) .. dcid .. u8(8) .. scid .. u8(0) .. varint(pn_len + #frames + 16) .. pn
local enc, tag = aes_gcm(true, key, iv, frames, header)   -- номер пакета 0: nonce = iv
local packet = header .. enc .. tag

-- защита заголовка
local pn_off = #header - pn_len
local mask = aes(true, hp, packet:sub(pn_off + 5, pn_off + 20))
packet = u8(bitxor(packet:byte(1), bitand(mask:byte(1), 0x0f))) .. packet:sub(2, pn_off) .. bxor(pn, mask:sub(2, 5)) .. packet:sub(pn_off + pn_len + 1)

local hex = packet:gsub(".", function(c) return string.format("%02x", c:byte()) end)
print("QUICHEX " .. hex)
LUA;
const QUIC_WAIT = 2;    // секунд на ответ
const QUIC_TRIES = 2;   // UDP теряется — один повтор

function quicPacket(string $host): ?string
{
  @mkdir(TEST_DIR, 0777, true);
  $lua = TEST_DIR . '/quic-initial.lua';
  if (!is_file($lua) || md5_file($lua) !== md5(QUIC_LUA)) {
    file_put_contents($lua, QUIC_LUA);
    @chmod($lua, 0644);
  }
  [, $out] = runTimeout([NFQWS_BIN, '--intercept=0', '--lua-init=SNI="' . $host . '"', '--lua-init=@' . $lua], 10);
  return preg_match('/^QUICHEX ([0-9a-f]+)$/m', $out, $m) ? hex2bin($m[1]) : null;
}

// Зонд QUIC. $testPort — с проверочного порта (мимо основного nfqws2 или через проверочную очередь — по правилам
// testRules), иначе — как обычный трафик роутера, через основной nfqws2
function quicProbe(string $host, string $ip, bool $testPort, ?string $pkt = null): array
{
  $pkt ??= quicPacket($host);
  if ($pkt === null) {
    return ['ok' => false, 'ms' => null, 'reason' => 'не удалось собрать пакет QUIC (nfqws2 не выполнил сценарий)'];
  }
  [$lo, $hi] = array_map('intval', explode(':', TEST_PORTS));
  $s = false;
  for ($i = 0; $i < 5 && !$s; $i++) {   // порт может быть занят — берём другой
    $ctx = stream_context_create($testPort ? ['socket' => ['bindto' => '0.0.0.0:' . mt_rand($lo, $hi)]] : []);
    $s = @stream_socket_client("udp://$ip:443", $en, $es, 3, STREAM_CLIENT_CONNECT, $ctx);
    if (!$testPort) {
      break;
    }
  }
  if (!$s) {
    return ['ok' => false, 'ms' => null, 'reason' => 'не удалось открыть соединение UDP'];
  }
  stream_set_timeout($s, QUIC_WAIT);
  $t = microtime(true);
  for ($i = 0; $i < QUIC_TRIES; $i++) {
    @fwrite($s, $pkt);
    $r = @fread($s, 4096);
    if (is_string($r) && $r !== '') {
      fclose($s);
      return ['ok' => true, 'ms' => (int)round((microtime(true) - $t) * 1000), 'reason' => null, 'bytes' => strlen($r)];
    }
  }
  fclose($s);
  return ['ok' => false, 'ms' => null, 'reason' => 'нет ответа'];
}

// Объявляет ли сайт HTTP/3: заголовок Alt-Svc с h3 в ответе по обычному HTTPS. null — узнать не удалось
function quicAdvertised(string $host): ?bool
{
  $alt = null;
  $got = false;
  $ch = curl_init('https://' . $host . '/');
  curl_setopt_array($ch, [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 8, CURLOPT_NOSIGNAL => 1, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_USERAGENT => DIAG_UA,
    CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$alt, &$got) {
      $got = true;
      if (stripos($h, 'alt-svc:') === 0) {
        $alt = (bool)preg_match('/\bh3(-\d+)?=/i', $h);
      }
      return strlen($h);
    }] + policyProbeOpts());
  curl_exec($ch);
  curl_close($ch);
  return $got ? (bool)$alt : null;
}

// Ступень диагноза: отвечает ли сайт по QUIC мимо nfqws2 и через него
function diagQuic(string $host, string $ip): array
{
  $pkt = quicPacket($host);
  $direct = diagBypass(fn() => quicProbe($host, $ip, true, $pkt));
  $pol = interceptInfo()['policy'] ?? null;
  $only = $pol && ($pol['mode'] ?? '') === 'only';   // политика Keenetic: запросы самого роутера идут мимо основного nfqws2
  $running = findPid() !== null;
  $via = $direct['ok'] || $only || !$running ? null : quicProbe($host, $ip, false, $pkt);
  $route = matchRoute(currentProfiles(), $host, [$ip], 'udp', 443, 'quic', new ListCache());
  return ['direct' => $direct, 'via' => $via, 'h3' => $direct['ok'] || !empty($via['ok']) ? true : quicAdvertised($host),
    'profile' => $route['profile'] ?? null, 'policy' => $only, 'running' => $running];
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
  // удачный ответ помним 12 часов, неудачу (GitHub недоступен, 404) — 10 минут: иначе после короткого сбоя
  // интерфейс полдня не видел бы новую версию
  if (!$force && time() - $u['checked'] < ($u['error'] ? 600 : 12 * 3600)) {
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
  $fb = null;
  if ($code === 200 && preg_match('/^v?(\d+\.\d+\.\d+)$/', (string)($j['tag_name'] ?? ''), $m)) {
    $save += ['latest' => $m[1], 'notes' => preg_match('/^.{0,4000}/su', (string)($j['body'] ?? ''), $nm) ? $nm[0] : '', 'url' => (string)($j['html_url'] ?? REPO_URL . '/releases')];
  } elseif ($fb = updateFallback()) {
    // API GitHub отказал (чаще всего 403 — лимит 60 запросов в час на внешний адрес, а за общим адресом провайдера
    // его выбирают соседи); версию и описание берём из файлов релиза — на них лимита нет
    $save += $fb;
  } else {
    $old = json_decode((string)@file_get_contents(UPDATE_FILE), true) ?: [];
    $save += array_intersect_key($old, array_flip(['latest', 'notes', 'url']))
      + ['error' => $err ?: ($code === 404 ? 'релизов не найдено' : "GitHub ответил $code")];
  }
  @file_put_contents(UPDATE_FILE, json_encode($save, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  return updateInfo();
}

function updateGet(string $url): ?string
{
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => ['User-Agent: nfqws2-ui/' . UI_VERSION]]);
  $res = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return $code === 200 && is_string($res) ? $res : null;
}

// Последняя версия без API: в sha256sums.txt последнего релиза имя пакета содержит номер,
// описание — раздел этой версии из CHANGELOG.md (так же его берёт release.yml)
function updateFallback(): ?array
{
  $sums = updateGet(REPO_URL . '/releases/latest/download/sha256sums.txt');
  if ($sums === null || !preg_match('/nfqws2-ui_(\d+\.\d+\.\d+)-\d+_/', $sums, $m)) {
    return null;
  }
  $notes = '';
  $cl = updateGet('https://raw.githubusercontent.com/zemidala/nfqws2-ui/v' . $m[1] . '/CHANGELOG.md');
  if ($cl !== null && preg_match('/^## ' . preg_quote($m[1], '/') . '\b[^\n]*\n(.*?)(?=^## |\z)/msu', $cl, $c)) {
    $notes = preg_match('/^.{0,4000}/su', trim($c[1]), $nm) ? $nm[0] : '';
  }
  return ['latest' => $m[1], 'notes' => $notes, 'url' => REPO_URL . '/releases/tag/v' . $m[1]];
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
  // Первая строка журнала — знак, что запуск состоялся: интерфейс отличит «не запустилось» от «идёт».
  $cmd = 'echo "Запуск: ' . SETUP_BIN . ' update v' . $version . '" >>' . UPDATE_LOG . '; '
    . '[ -w /sys/fs/cgroup/cgroup.procs ] && echo $$ > /sys/fs/cgroup/cgroup.procs 2>/dev/null; '
    . SETUP_BIN . ' update v' . $version . ' >>' . UPDATE_LOG . ' 2>&1; echo "[exit $?]" >>' . UPDATE_LOG;
  // setsid отвязывает установку от веб-сервера, но на Keenetic он есть только с busybox из Entware —
  // без него запускаем как есть (там веб-сервер перезапускается killall-ом и установку не задевает)
  $setsid = '';
  foreach (explode(':', JOB_PATH) as $d) {
    if (is_executable("$d/setsid")) {
      $setsid = "$d/setsid ";
      break;
    }
  }
  exec('(' . $setsid . 'env -i PATH=' . JOB_PATH . ' sh -c ' . escapeshellarg($cmd) . ' >/dev/null 2>&1 &)');
}

// ---------- обновление nfqws2 (пакет nfqws2-keenetic) ----------
// Новая версия — последний выпуск nfqws/nfqws2-keenetic на GitHub (если API отказал — индекс источника пакетов).
// Ставится файл пакета из выпуска самим opkg / apk: так же делается откат на прежнюю версию, и мёртвые
// источники Entware обновлению не мешают. Веб-сервер при этом не перезапускается — хватает фонового задания PHP.
// Перед установкой — закреплённый снимок; после — проверка сайтов мониторинга (откатывает человек, кнопкой).

const NFQ_REPO = 'nfqws/nfqws2-keenetic';
const NFQ_FEED = 'https://nfqws.github.io/nfqws2-keenetic/';
define('NFQ_UPDATE_FILE', UI_CONF_DIR . '/nfq-update.json');
const NFQ_UPDATE_LOG = '/tmp/nfqws-ui-nfq-update.log';

function nfqUpdData(): array
{
  return json_decode((string)@file_get_contents(NFQ_UPDATE_FILE), true) ?: [];
}

function nfqUpdSave(array $d): void
{
  putSafe(NFQ_UPDATE_FILE, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// «1.3.1-1», «1.3.1-r1» → «1.3.1»
function nfqVer(?string $v): ?string
{
  return $v === null ? null : preg_replace('/-r?\d+$/', '', $v);
}

// Имя файла пакета в выпуске: у пакета под конкретную архитектуру — она же, иначе «all» своей системы
function nfqAssetName(string $ver): string
{
  $arch = null;
  if (!pkgApk()) {
    exec((ROOT ? '/opt/bin/' : '') . 'opkg status nfqws2-keenetic 2>/dev/null', $out);
    foreach ($out as $l) {
      if (str_starts_with($l, 'Architecture: ')) {
        $arch = trim(substr($l, 14));
      }
    }
  }
  if ($arch && $arch !== 'all' && preg_match('/^[\w.-]+$/', $arch)) {
    return "nfqws2-keenetic_{$ver}_$arch.ipk";
  }
  return "nfqws2-keenetic_{$ver}_" . (ROOT ? 'all_entware.ipk' : (pkgApk() ? 'all_openwrt.apk' : 'all_openwrt.ipk'));
}

function nfqRunning(): bool
{
  $j = nfqUpdData()['job'] ?? null;
  return $j && !empty($j['pid']) && empty($j['done']) && file_exists('/proc/' . $j['pid']);
}

function nfqUpdateInfo(): array
{
  $d = nfqUpdData();
  $cur = nfqVer(packageVersion('nfqws2-keenetic'));
  $latest = $d['latest'] ?? null;
  $prev = $d['result']['from'] ?? null;
  return ['current' => $cur, 'latest' => $latest, 'available' => $cur && $latest && version_compare($latest, $cur, '>'),
    'notes' => $d['notes'] ?? '', 'url' => $d['url'] ?? 'https://github.com/' . NFQ_REPO . '/releases', 'date' => $d['date'] ?? null,
    'checked' => $d['checked'] ?? 0, 'error' => $d['error'] ?? null, 'installed' => $cur !== null,
    'running' => nfqRunning(), 'result' => $d['result'] ?? null,
    // вернуть можно ту версию, что стояла до последнего обновления, пока стоит та, на которую обновились
    'rollback' => $prev && $cur && ($d['result']['to'] ?? null) === $cur && empty($d['result']['rollback']) ? $prev : null];
}

function nfqUpdateCheck(bool $force): array
{
  $d = nfqUpdData();
  if (!$force && time() - ($d['checked'] ?? 0) < (!empty($d['error']) ? 600 : 12 * 3600)) {
    return nfqUpdateInfo();
  }
  $d['checked'] = time();
  unset($d['error']);
  $ch = curl_init('https://api.github.com/repos/' . NFQ_REPO . '/releases/latest');
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'User-Agent: nfqws2-ui/' . UI_VERSION]]);
  $res = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  $j = json_decode((string)$res, true);
  if ($code === 200 && preg_match('/^v?(\d+\.\d+\.\d+)$/', (string)($j['tag_name'] ?? ''), $m)) {
    $d['latest'] = $m[1];
    $d['notes'] = preg_match('/^.{0,4000}/su', (string)($j['body'] ?? ''), $nm) ? $nm[0] : '';
    $d['url'] = (string)($j['html_url'] ?? '');
    $d['date'] = strtotime((string)($j['published_at'] ?? '')) ?: null;
    $d['sums'] = [];
    foreach ($j['assets'] ?? [] as $a) {
      if (preg_match('/^sha256:([0-9a-f]{64})$/', (string)($a['digest'] ?? ''), $s)) {
        $d['sums'][(string)$a['name']] = $s[1];
      }
    }
  } elseif (($feed = updateGet(NFQ_FEED . (ROOT ? 'all' : 'openwrt') . '/Packages'))
    && preg_match('/^Package: nfqws2-keenetic\n(?:[^\n]+\n)*?Version: (\d+\.\d+\.\d+)/m', $feed, $m)) {
    // API GitHub отказал (лимит на общий адрес) — номер из индекса источника пакетов, без описания
    if (($d['latest'] ?? null) !== $m[1]) {
      $d['notes'] = '';
      $d['sums'] = [];
      $d['url'] = 'https://github.com/' . NFQ_REPO . '/releases/tag/v' . $m[1];
      $d['date'] = null;
    }
    $d['latest'] = $m[1];
  } else {
    $d['error'] = $err ?: "GitHub ответил $code";
  }
  nfqUpdSave($d);
  return nfqUpdateInfo();
}

// SHA-256 файла выпуска: из прошлой проверки или из API (выпуск по тегу); null — сверить нечем
function nfqAssetSum(string $ver, string $name): ?string
{
  $d = nfqUpdData();
  if (($d['latest'] ?? null) === $ver && !empty($d['sums'][$name])) {
    return $d['sums'][$name];
  }
  $j = json_decode((string)updateGet('https://api.github.com/repos/' . NFQ_REPO . '/releases/tags/v' . $ver), true);
  foreach ($j['assets'] ?? [] as $a) {
    if (($a['name'] ?? '') === $name && preg_match('/^sha256:([0-9a-f]{64})$/', (string)($a['digest'] ?? ''), $s)) {
      return $s[1];
    }
  }
  return null;
}

function nfqUpdateStart(string $ver, bool $rollback): void
{
  if (!preg_match('/^\d+\.\d+\.\d+$/', $ver)) {
    fail('Неверная версия');
  }
  $cur = nfqVer(packageVersion('nfqws2-keenetic'));
  if ($cur === null) {
    fail('nfqws2 установлен не пакетом nfqws2-keenetic — обновите его тем же способом, каким ставили');
  }
  if (nfqRunning() || updateRunning()) {
    fail('Обновление уже идёт');
  }
  if (($p = pendingGet()) && in_array($p['state'], ['checking', 'waiting'], true)) {
    fail('Идёт перезапуск с проверкой — дождитесь его окончания');
  }
  if ($rollback ? version_compare($ver, $cur, '>=') : version_compare($ver, $cur, '<=')) {
    fail($rollback ? "Сейчас стоит $cur — возвращать нечего" : "Уже стоит $cur");
  }
  $name = nfqAssetName($ver);
  $d = nfqUpdData();
  $d['job'] = ['from' => $cur, 'to' => $ver, 'rollback' => $rollback, 'name' => $name, 'sum' => nfqAssetSum($ver, $name),
    'snap' => $rollback ? ($d['result']['snap'] ?? null) : null, 'started' => time()];
  nfqUpdSave($d);
  file_put_contents(NFQ_UPDATE_LOG, '');
  exec('(env -i PATH=' . JOB_PATH . ' NFQWS_UI_CLI=nfqupd php-cgi -q -f ' . escapeshellarg(__FILE__) . ' >/dev/null 2>&1 &)');
}

// Суммы конфига, списков и блобов nfqws2 — чтобы при откате не затереть то, что правили после обновления
function nfqFileSums(): array
{
  $r = [];
  foreach (array_merge([CONF_FILE], glob(LISTS_DIR . '/*') ?: [], glob(CONF_DIR . '/blobs/*') ?: []) as $f) {
    if (is_file($f)) {
      $r[$f] = md5_file($f);
    }
  }
  return $r;
}

function nfqLog(string $s): void
{
  file_put_contents(NFQ_UPDATE_LOG, $s . "\n", FILE_APPEND);
}

// Команда с ограничением времени; вывод — в журнал обновления. Возвращает код выхода (null — не уложилась)
function nfqExec(string $cmd, int $timeout): ?int
{
  $p = proc_open('exec ' . $cmd . ' 2>&1', [0 => ['file', '/dev/null', 'r'], 1 => ['file', NFQ_UPDATE_LOG, 'a']], $pipes, null, ['PATH' => JOB_PATH]);
  if (!is_resource($p)) {
    return 127;
  }
  $deadline = time() + $timeout;
  do {
    $st = proc_get_status($p);
    if (!$st['running']) {
      proc_close($p);
      return $st['exitcode'];
    }
    usleep(300000);
  } while (time() < $deadline);
  proc_terminate($p, 9);
  proc_close($p);
  return null;
}

// Фоновое задание: снимок → скачать пакет → сверить сумму → поставить → nfqws2 работает? → проверить сайты
function nfqUpdateJob(): void
{
  set_time_limit(900);
  $d = nfqUpdData();
  $job = $d['job'] ?? null;
  if (!$job || !empty($job['done'])) {
    return;
  }
  $d['job']['pid'] = getmypid();
  nfqUpdSave($d);
  // задание оборвалось (fail() внутри восстановления, нехватка памяти) — окно не должно ждать вечно
  register_shutdown_function(function (): void {
    $d = nfqUpdData();
    if (!empty($d['job']) && empty($d['job']['done'])) {
      $d['job']['done'] = time();
      $d['job']['error'] = fatalText() ?? 'задание прервано';
      nfqUpdSave($d);
      nfqLog('ОШИБКА: ' . $d['job']['error']);
      nfqLog('[exit 1]');
    }
  });
  $fail = function (string $msg) use ($job): void {
    nfqLog('ОШИБКА: ' . $msg);
    $d = nfqUpdData();
    $d['job']['done'] = time();
    $d['job']['error'] = $msg;
    nfqUpdSave($d);
    nfqLog('[exit 1]');
    exit(0);
  };
  $from = $job['from'];
  $to = $job['to'];
  nfqLog(($job['rollback'] ? 'Возврат' : 'Обновление') . " nfqws2: $from → $to");
  // как было до установки — по последней проверке мониторинга
  $mon = monitorData();
  $before = [];
  foreach (uiSettings()['monitor']['sites'] as $h) {
    $hist = $mon['sites'][$h] ?? [];
    $before[$h] = $hist ? end($hist)[1] : null;
  }
  $snap = null;
  if (!$job['rollback']) {
    $snap = snapshotCreate('перед обновлением nfqws2', false, "nfqws2 $from → $to");
    if ($snap) {
      // закрепляем: при откате из этого снимка вернутся конфиг и списки
      $lock = snapLock();
      $idx = snapIndex();
      // закреплённым держим только снимок последнего обновления
      foreach ($idx as &$e) {
        if ($e['id'] === $snap['id']) {
          $e['pinned'] = true;
        } elseif (($e['reason'] ?? '') === 'перед обновлением nfqws2') {
          $e['pinned'] = false;
        }
      }
      unset($e);
      snapSaveIndex($idx);
      fclose($lock);
      nfqLog('Снимок конфига и списков: ' . $snap['id']);
    } else {
      nfqLog('Снимок сделать не удалось — продолжаю без него');
    }
  }
  $url = 'https://github.com/' . NFQ_REPO . '/releases/download/v' . $to . '/' . $job['name'];
  $file = '/tmp/' . $job['name'];
  nfqLog('Скачиваю ' . $url);
  $fh = fopen($file, 'w');
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 180,
    CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_HTTPHEADER => ['User-Agent: nfqws2-ui/' . UI_VERSION]]);
  curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  fclose($fh);
  if ($code !== 200 || filesize($file) < 1000) {
    @unlink($file);
    $fail('файл не скачался: ' . ($err ?: "HTTP $code"));
  }
  $sum = hash_file('sha256', $file);
  if ($job['sum'] && $sum !== $job['sum']) {
    @unlink($file);
    $fail("сумма файла не сходится с выпуском на GitHub ($sum)");
  }
  nfqLog('Скачано ' . round(filesize($file) / 1024) . ' КБ' . ($job['sum'] ? ', сумма SHA-256 сошлась' : ', сумму сверить не с чем (GitHub не ответил)'));
  if (pkgApk()) {
    $cmd = 'apk add --allow-untrusted ' . escapeshellarg($file);
  } else {
    $cmd = (ROOT ? '/opt/bin/' : '') . 'opkg install ' . ($job['rollback'] ? '--force-downgrade ' : '') . escapeshellarg($file);
  }
  nfqLog('$ ' . $cmd);
  $rc = nfqExec($cmd, 300);
  @unlink($file);
  if ($rc !== 0) {
    $fail($rc === null ? 'установка не уложилась в 5 минут' : "менеджер пакетов завершился с кодом $rc");
  }
  $now = nfqVer(packageVersion('nfqws2-keenetic', true));
  $postSums = nfqFileSums();   // как стало сразу после установки
  if ($now !== $to) {
    $fail("после установки стоит " . ($now ?? 'ничего') . ", а не $to");
  }
  if ($job['rollback'] && $job['snap']) {
    nfqLog('Возвращаю конфиг и списки из снимка ' . $job['snap']);
    // правленное после обновления оставляем: возвращаем только то, что с установки не менялось
    // суммы «сразу после установки» есть с 1.21.0; у обновлений раньше — по времени изменения файла
    $post = $d['result']['post'] ?? [];
    $after = (int)($d['result']['ts'] ?? 0);
    $keep = [];
    foreach (nfqFileSums() as $f => $sum) {
      if ($post ? ($post[$f] ?? null) !== $sum : ($after && filemtime($f) > $after)) {
        $keep[] = $f;
      }
    }
    if ($keep) {
      nfqLog('Оставляю как есть (менялись после обновления): ' . implode(', ', array_map('basename', $keep)));
    }
    $changed = restoreArchive(snapArchive($job['snap']), 'возврат nfqws2 ' . $to, true, $keep);
    nfqLog($changed ? 'Изменилось: ' . implode(', ', array_map('basename', $changed)) : 'Конфиг и списки и так совпадали со снимком');
  }
  // пакет перезапускает nfqws2 сам; ждём его, при необходимости запускаем
  $pid = null;
  for ($i = 0; $i < 20 && !($pid = findPid()); $i++) {
    usleep(500000);
  }
  if (!$pid || ($job['rollback'] && $job['snap'])) {
    nfqLog('Перезапускаю nfqws2');
    serviceRun('restart');
    for ($i = 0; $i < 20 && !($pid = findPid()); $i++) {
      usleep(500000);
    }
  }
  nfqLog($pid ? "nfqws2 $to работает (PID $pid)" : 'nfqws2 не запустился');
  $checks = [];
  sleep(3);   // nfqws2 только что поднялся: первые соединения сразу после запуска бывают неудачными
  foreach ($before as $h => $was) {
    $pr = probe($h);
    // открывался до установки, а сейчас нет — перепроверяем, разовый сбой не считаем поломкой
    for ($try = 0; !$pr['ok'] && $was && $try < 2; $try++) {
      sleep(3);
      $pr = probe($h);
    }
    $checks[] = ['host' => $h, 'ok' => $pr['ok'], 'was_ok' => $was, 'reason' => $pr['ok'] ? null : $pr['reason']];
    nfqLog(($pr['ok'] ? '✓ ' : '✗ ') . $h . ($pr['ok'] ? '' : ' — ' . $pr['reason']));
  }
  $d = nfqUpdData();
  $d['job']['done'] = time();
  $d['result'] = ['from' => $from, 'to' => $to, 'rollback' => $job['rollback'], 'ts' => time(), 'running' => (bool)$pid,
    'snap' => $job['rollback'] ? null : ($snap['id'] ?? null), 'checks' => $checks,
    'worse' => array_values(array_column(array_filter($checks, fn($c) => !$c['ok'] && $c['was_ok']), 'host')),
    'leftovers' => count(pkgLeftovers()), 'post' => $job['rollback'] ? [] : $postSums];
  nfqUpdSave($d);
  historyScan('auto');
  nfqLog('[exit 0]');
}

// ---------- журналы nfqws2 в /var/log ----------
// На OpenWrt /var/log — это /tmp, то есть оперативная память. Отладочный журнал (LOG_LEVEL=1) за несколько
// часов вырастает до сотен мегабайт. Считаем занятое по блокам: после обрезки файл может стать «дырявым».
const LOG_WARN = 20 << 20, LOG_BAD = 100 << 20, LOG_TRIM = 50 << 20, LOG_KEEP = 1 << 20;

function nfqLogs(): array
{
  $files = [];
  foreach (glob(LOG_DIR . '/nfqws2*.log') ?: [] as $f) {
    $st = @stat($f);
    if ($st) {
      $files[] = ['name' => basename($f), 'size' => $st['size'], 'used' => isset($st['blocks']) && $st['blocks'] >= 0 ? $st['blocks'] * 512 : $st['size']];
    }
  }
  $vars = confRawVars((string)@file_get_contents(CONF_FILE));
  $total = @disk_total_space(LOG_DIR) ?: 0;
  return ['files' => $files, 'debug' => trim((string)($vars['LOG_LEVEL'] ?? '0'), "\"' ") === '1',
    'free' => @disk_free_space(LOG_DIR) ?: 0, 'total' => $total, 'warn' => LOG_WARN, 'bad' => LOG_BAD];
}

// Обнулить или подрезать журнал на месте: тот же файл, nfqws2 продолжает в него писать без перезапуска
function nfqLogCut(string $name, int $keep = 0): void
{
  preg_match('/^nfqws2[A-Za-z0-9_.-]*\.log$/', $name) || fail('Нет такого журнала');
  $f = LOG_DIR . '/' . $name;
  is_file($f) || fail('Нет такого журнала', 404);
  $tail = '';
  if ($keep > 0 && ($h = @fopen($f, 'rb'))) {
    fseek($h, -min($keep, filesize($f)), SEEK_END);
    $tail = (string)stream_get_contents($h);
    fclose($h);
    $tail = substr($tail, (int)strpos($tail, "\n") + 1);   // первая строка обрезана посередине
  }
  $h = @fopen($f, 'r+');
  $h || fail('Не удалось открыть ' . $name);
  ftruncate($h, 0);
  rewind($h);
  if ($tail !== '') {
    fwrite($h, "--- nfqws2-ui: журнал подрезан " . ldate('d.m H:i') . ", оставлен последний " . round($keep / 1048576) . " МБ ---\n" . $tail);
  }
  fclose($h);
}

// cron, раз в 10 минут: не дать забытой отладке съесть память роутера
function nfqLogsTrim(): void
{
  foreach (nfqLogs()['files'] as $x) {
    if ($x['used'] > LOG_TRIM) {
      nfqLogCut($x['name'], LOG_KEEP);
    }
  }
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

// Подключены ли цепочки nfqws2 и не ограничен ли перехват политикой доступа Keenetic (POLICY_NAME в конфиге).
// «Только политика»: мимо nfqws2 идёт всё без её метки — в том числе запросы самого роутера, то есть мониторинг и
// «Проверить сайт»; подбор при этом работает, у него свои правила. $full — ещё и сами правила, для отчёта.
// Таблица mangle целиком (iptables -v -S), одна на запрос; $fresh — перечитать после своих правок
function mangleRules(bool $fresh = false): array
{
  static $all = null;
  if ($all === null || $fresh) {
    $all = [];
    exec('iptables -w -t mangle -v -S 2>/dev/null', $all);
  }
  return $all;
}

function interceptInfo(bool $full = false): array
{
  $refs = [];
  $rules = [];
  $policy = null;
  // вся таблица mangle одним вызовом: на mipsel каждый запуск iptables — десятки миллисекунд, а цепочек нужно четыре
  $all = mangleRules();
  $of = fn(string $c) => array_values(preg_grep('/^-[NAP] ' . $c . '( |$)/', $all));
  foreach (['nfqws_post' => 'POSTROUTING', 'nfqws_pre' => 'PREROUTING'] as $chain => $hook) {
    $out = $of($chain);
    if (!$out) {
      $refs[$chain] = null;   // цепочки нет
      continue;
    }
    $refs[$chain] = count(preg_grep('/-j ' . $chain . '\b/', $of($hook)));
    foreach ($out as $l) {
      if (!str_starts_with($l, '-A ')) {
        continue;
      }
      $rules[] = $l;
      // метка политики — с маской 0x0fffffff; служебные метки nfqws2 (0x40000000, 0x20000000) сюда не подходят
      if ($chain === 'nfqws_post' && !$policy && preg_match('/-m mark (! )?--mark (0x[0-9a-f]+)\/0x0?fffffff\b.*-j RETURN/i', $l, $m)) {
        $policy = ['mode' => $m[1] !== '' ? 'only' : 'exclude', 'mark' => $m[2],
          'passed' => preg_match('/-c (\d+) \d+/', $l, $c) ? (int)$c[1] : null];
      }
    }
  }
  if ($policy) {
    $policy['name'] = preg_match('/^\s*POLICY_NAME=["\']?([^"\'\s#]*)/m', (string)@file_get_contents(CONF_FILE), $m) && $m[1] !== '' ? $m[1] : 'nfqws';
  }
  return ['refs' => $refs, 'policy' => $policy] + ($full ? ['rules' => $rules] : []);
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

// Почему «нужен перезапуск» — для отчёта и подсказки у плашки: какой файл новее процесса или какой профиль
// в конфиге не совпадает с работающим nfqws2
function restartWhy(?array $proc, array $expected, array $profiles): array
{
  $why = [];
  if ($proc && $proc['started']) {
    foreach (array_merge([CONF_FILE], array_values(luaFiles())) as $f) {
      $m = @filemtime($f);
      if ($m > $proc['started'] + 2) {
        $why[] = basename($f) . ' изменён через ' . ($m - $proc['started']) . ' с после запуска' . ($m > time() + 60 ? ' (время файла в будущем — часы роутера?)' : '');
      }
    }
  }
  $exp = array_column($expected, 'tokens');
  $run = array_column($profiles, 'args');
  if (count($exp) !== count($run)) {
    $why[] = 'профилей в конфиге ' . count($exp) . ', у работающего nfqws2 ' . count($run);
  }
  foreach ($exp as $i => $e) {
    $r = $run[$i] ?? null;
    if ($r === null || $r === $e) {
      continue;
    }
    $a = array_values(array_diff($e, $r));
    $b = array_values(array_diff($r, $e));
    $cut = fn($x) => strlen($x) > 60 ? substr($x, 0, 57) . '...' : $x;
    $why[] = 'профиль #' . ($i + 1) . ' не совпадает' . ($a ? '; только в конфиге: ' . implode(' ', array_map($cut, array_slice($a, 0, 3))) : '')
      . ($b ? '; только в работе: ' . implode(' ', array_map($cut, array_slice($b, 0, 3))) : '') . (!$a && !$b ? '; другой порядок аргументов' : '');
    if (count($why) >= 4) {
      break;
    }
  }
  return $why;
}

function state(): array
{
  $seen = seenInfo();   // первым: до того, как состояние само создаст файлы, по которым видно «уже пользовались»
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
    'restart_why' => $pid ? restartWhy($proc, $expected, $profiles) : [],
    'pid_lost' => pidLost($pid),
    'conf_mtime' => @filemtime(CONF_FILE),
    'profiles' => $profiles,
    'lists' => listsInventory($profiles),
    'iptables' => iptablesCounters(),
    'intercept' => (function () {
      $i = interceptInfo();
      if ($i['policy']) {
        $i['policy']['probe'] = policyProbeEnsure($i['policy']);   // проверки роутера идут как от устройства политики
      }
      return $i;
    })(),
    'logs' => nfqLogs(),
    'leftovers' => array_column(pkgLeftovers(), 'name'),
    'nfq_update' => (function () { $u = nfqUpdateInfo(); return ['latest' => $u['latest'], 'available' => $u['available'], 'running' => $u['running'], 'rollback' => $u['rollback']]; })(),
    'curl' => curlCmdError(),
    'php_curl' => ($pc = phpCurlError()),
    'php_upgrade' => phpCurlOld($pc) ? (pkgApk() ? PHP_UPGRADE_APK : PHP_UPGRADE_OPKG) : null,
    'pkg_apk' => pkgApk(),
    'lint' => lintCurrent(),
    'conf_tokens' => array_map('tokens', array_intersect_key(confRawVars(file_get_contents(CONF_FILE)), array_flip(ARG_VARS))),
    // Профили по конфигу (для редактора) — могут отличаться от запущенных до перезапуска
    'conf_profiles' => $pid === null ? $profiles : loadProfiles(null, $expected, $globals),
    'subs' => uiSettings()['subs'],
    'ui' => (function () use ($seen) {
      $w = uiWeb();
      return ['version' => UI_VERSION, 'seen' => $seen, 'conf_file' => CONF_FILE, 'https_port' => $w['https_port'], 'legacy_port' => $w['legacy_port'], 'auth' => authEnabled(), 'session' => sessionLife(),
        'provider' => uiSettings()['provider'], 'platform' => ROOT ? 'Keenetic' : 'OpenWrt', 'repo' => REPO_URL, 'update' => updateInfo() + ['running' => updateRunning()]];
    })(),
    'undo' => (function () {
      $u = lastUndoable();
      return $u ? ['ts' => $u['ts'], 'files' => $u['files'], 'note' => $u['note']] : null;
    })(),
    'pending' => (function () {
      pendingExpire(60);
      return pendingView();
    })(),
    'lowmem' => lowMem(),
    'rollback' => (function () {
      $T = confirmPoint();
      return ['since' => $T, 'files' => array_values(array_unique(array_map('basename', array_keys(rollbackVersions($T)))))];
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
        $offers[] = ['host' => (string)$h, 'ts' => $o['ts'], 'name' => $o['name'], 'list' => $o['list'] ?? null];
      }
      return ['enabled' => uiSettings()['auto']['enabled'], 'offers' => $offers, 'queue' => $d['queue']];
    })(),
    'snap' => (function () {
      $idx = snapIndex();
      // count — все снимки, где бы ни лежали; local — на роутере; remote — на NAS
      $loc = array_filter($idx, fn($e) => $e['local'] ?? true);
      return ['count' => count($idx), 'last' => $idx ? end($idx)['ts'] : null, 'local' => count($loc),
        'remote' => remoteOn() ? count(array_filter($idx, fn($e) => !empty($e['remote']))) : null,
        'size' => array_sum(array_map(fn($e) => $e['size'], $loc))];
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
    } elseif (preg_match('/\$\{?' . $n . '\b/', $v)) {
      // NFQWS_ARGS_CUSTOM="$NFQWS_ARGS_CUSTOM --new …": интерфейс видит только последнее присваивание — сохранив, потерял бы остальные
      fail("$n в конфиге собирается по частям ($n=\"\$$n …\") — интерфейс видит только последнюю часть и при сохранении потерял бы остальные. "
        . 'Соберите профили в одну переменную через --new («Конфиг целиком»), тогда кнопки заработают.');
    } elseif (preg_match('/["`$\\\\]/', $v, $bad)) {
      $tok = preg_match('/\S*["`$\\\\]\S*/', $v, $tm) ? $tm[0] : $bad[0];
      fail("$n: нельзя использовать символы \" ` \$ \\ — встретилось «{$bad[0]}» в «" . (strlen($tok) > 60 ? substr($tok, 0, 57) . '...' : $tok) . '»');
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
    } elseif ($c === 'nfqws2' && $pid !== $main && !preg_match('/--qnum=' . TEST_QNUM . '\b|--dry-run|--intercept=0/', (string)@file_get_contents("/proc/$pid/cmdline"))) {
      // --dry-run — наша же проверка конфига: на медленном роутере она идёт заметное время и попадала сюда
      // --intercept=0 — сборка пакета QUIC для зонда (quicPacket): тоже наш запуск, пакеты он не трогает
      $found['ещё один nfqws2 (zapret2)'] = true;
    }
  }
  return array_keys($found);
}

function ripe(string $call, string $resource = '', int $timeout = 25): ?array
{
  $ch = curl_init("https://stat.ripe.net/data/$call/data.json?sourceapp=nfqws2-ui" . ($resource !== '' ? '&resource=' . rawurlencode($resource) : ''));
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(8, $timeout), CURLOPT_NOSIGNAL => 1]);
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

// Свои стратегии для подбора (поле «Своя стратегия»): у каждой оставляем шаги --lua-desync и говорим, что не так,
// до запуска — иначе непонятная строка выглядела бы как «не открылось». Остальные параметры профиля (фильтры,
// списки, --payload) в проверке не участвуют: подбор сам ставит фильтр под сайт и протокол.
const OWN_MAX = 10;     // стратегий за один запуск
const OWN_STEPS = 20;   // шагов в одной стратегии
function strategyCheck(array $items, string $proto): array
{
  $opts = helpInfo()['options'];
  $lua = luaCatalog();
  // блобы, объявленные в конфиге (--blob=имя:…), встроенные и заданные в lua
  preg_match_all('/--blob=([^:\s"]+):/', implode(' ', array_intersect_key(confValues(), array_flip(ARG_VARS))), $bm);
  $blobs = array_flip(array_merge($bm[1], BUILTIN_BLOBS, $lua['globals']));
  $out = [];
  foreach (array_slice($items, 0, OWN_MAX) as $tokens) {
    $steps = [];
    $errors = [];
    $skipped = [];
    foreach (array_slice(is_array($tokens) ? $tokens : [], 0, 200) as $t) {
      if (!is_string($t) || !preg_match('/^--([a-z0-9-]+)(=[^"`$\\\\\s]*)?$/', $t, $m)) {
        $errors[] = (is_string($t) ? $t : '?') . ' — не параметр nfqws2';
        continue;
      }
      if ($m[1] !== 'lua-desync') {
        // фильтры и прочее от чужого профиля — не ошибка, просто здесь не нужны
        $skipped[] = isset($opts[$m[1]]) ? $t : $t . ' (такого параметра nfqws2 на этом роутере не знает)';
        continue;
      }
      $s = stepParse($t);
      if (!isset($lua['functions'][$s['fn']])) {
        $errors[] = $t . ' — функции «' . $s['fn'] . '» нет в lua-скриптах этого роутера (другая версия nfqws2?)';
        continue;
      }
      foreach (['blob', 'seqovl_pattern', 'pattern'] as $k) {
        $v = $s['p'][$k] ?? null;
        if (is_string($v) && $v !== '' && !str_starts_with($v, '0x') && !isset($blobs[$v])) {
          $errors[] = $t . ' — блоб «' . $v . '» в вашем конфиге не объявлен (у автора стратегии он задан параметром --blob)';
          continue 2;
        }
      }
      if (count($steps) < OWN_STEPS) {
        $steps[] = $t;
      }
    }
    if (!$steps && !$errors) {
      $errors[] = 'нет ни одного шага --lua-desync';
    }
    $out[] = ['steps' => $steps, 'name' => $steps ? strategyName($steps) : '', 'errors' => $errors, 'skipped' => $skipped];
  }
  // принимает ли nfqws2 сами шаги: сначала все разом, при отказе — по одной, чтобы назвать виноватую
  $filter = $proto === 'quic' ? ['--filter-udp=443', '--filter-l7=quic'] : ($proto === 'http' ? ['--filter-tcp=80', '--filter-l7=http'] : ['--filter-tcp=443', '--filter-l7=tls']);
  $dry = function (array $list) use ($filter): array {
    $exp = confValues();
    $mine = implode(' --new ', array_map(fn($st) => implode(' ', array_merge($filter, $st)), $list));
    $exp['NFQWS_ARGS_CUSTOM'] = $mine . (trim($exp['NFQWS_ARGS_CUSTOM']) !== '' ? ' --new ' . $exp['NFQWS_ARGS_CUSTOM'] : '');
    return dryRun($exp);
  };
  $good = array_keys(array_filter($out, fn($x) => $x['steps'] && !$x['errors']));
  if ($good && !$dry(array_map(fn($i) => $out[$i]['steps'], $good))['ok']) {
    foreach ($good as $i) {
      $d = $dry([$out[$i]['steps']]);
      if (!$d['ok']) {
        $out[$i]['errors'][] = 'nfqws2 не принимает эту стратегию: ' . $d['message'];
      }
    }
  }
  return ['items' => $out, 'max' => OWN_MAX];
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
    'система: ' . $os . ' · ' . sysArch() . ' · ядро ' . php_uname('r'), 'провайдер: ' . ($s['provider'] !== '' ? $s['provider'] : 'не указан'), '', '== Сервис =='];
  if ($st['running']) {
    $L[] = 'работает' . (!empty($st['process']['started']) ? ', запущен ' . ldate('Y-m-d H:i', (int)$st['process']['started']) : '')
      . ($st['restart_needed'] || !$st['in_sync'] ? ' · конфиг изменён после запуска — нужен перезапуск' : '');
    foreach ($st['restart_why'] as $w) {
      $L[] = '  причина: ' . $w;
    }
    if (ROOT !== '') {
      $L[] = 'файл PID: ' . ($st['pid_lost'] ? 'не совпадает с работающим nfqws2 (PID ' . $st['process']['pid'] . ', в файле: ' . (trim((string)@file_get_contents(PID_FILE)) ?: 'пусто или нет файла') . ') — скрипт запуска его не видит, перезапуск не сработает' : 'совпадает');
    }
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
  // для разбора «в подборе открывается, а в мониторинге нет»: есть ли IPv6 от провайдера и ловят ли правила пакеты
  $v6 = [];
  exec('ip -6 route show default 2>/dev/null', $v6);
  $L[] = 'IPv6 от провайдера: ' . ($v6 ? 'есть (' . preg_replace('/\s+/', ' ', preg_replace('/[0-9a-f]*:[0-9a-f:]+/i', '…', $v6[0])) . ')' : 'нет');
  // политика доступа Keenetic: при «только политика» запросы самого роутера идут мимо nfqws2
  $raw = (string)file_get_contents(CONF_FILE);
  foreach (['POLICY_NAME', 'POLICY_EXCLUDE'] as $v) {
    $L[] = "$v=" . (preg_match('/^\s*' . $v . '=["\']?([^"\'\s#]*)/m', $raw, $m) ? $m[1] : '(не задано)');
  }
  $ic = interceptInfo(true);
  $pol = $ic['policy'];
  $L[] = 'политика доступа: ' . (!$pol ? 'не действует — nfqws2 обрабатывает все соединения через интерфейс провайдера'
    : '«' . $pol['name'] . '», метка ' . $pol['mark'] . ' — ' . ($pol['mode'] === 'only'
      ? 'обрабатываются ТОЛЬКО её устройства; проверки роутера (мониторинг, «Проверить сайт») '
        . (policyProbeEnsure($pol) ? 'идут как от устройства политики (цепочка ' . PROBE_CHAIN . ', порты ' . PROBE_PORTS . ')' : 'идут МИМО nfqws2 — пометить их меткой политики не удалось')
      : 'её устройства исключены из обработки')
      . ($pol['passed'] !== null ? '; мимо по этому правилу прошло пакетов: ' . $pol['passed'] : ''));
  foreach (['nfqws_post' => 'POSTROUTING', 'nfqws_pre' => 'PREROUTING'] as $chain => $hook) {
    $n = $ic['refs'][$chain];
    $L[] = "цепочка $chain: " . ($n === null ? 'НЕТ' : ($n ? "подключена к $hook" : "есть, но к $hook НЕ подключена — пакеты в неё не попадают"));
  }
  $L[] = 'правила перехвата (-c пакеты байты; счётчики обнуляются при каждой перезагрузке правил):';
  foreach ($ic['rules'] as $r) {
    $L[] = '  ' . $r;
  }
  if (!$ic['rules']) {
    $L[] = '  нет';
  }
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
  // окружение: по нему видно, почему не грузится модуль или не хватает памяти, — раньше это выясняли командами в чате
  $L[] = '';
  $L[] = '== PHP, пакеты, память ==';
  $L[] = 'PHP ' . PHP_VERSION . ' (' . (PHP_INT_SIZE * 8) . ' бит), memory_limit ' . ini_get('memory_limit') . ', модуль curl: '
    . ($st['php_curl'] === null ? 'загружен' : 'НЕТ — ' . $st['php_curl']) . ', программа curl: ' . ($st['curl'] === null ? 'работает' : 'НЕТ — ' . $st['curl']);
  $L[] = 'модули PHP: ' . implode(' ', array_map('strtolower', get_loaded_extensions()));
  $pk = [];
  foreach (['php8', 'php8-cgi', 'php8-mod-curl', 'libcurl', 'libcurl4', 'curl', 'libpcre2', 'libxml2', 'lighttpd'] as $n) {
    if (($v = packageVersion($n)) !== null || $n === 'php8-mod-curl') {
      $pk[] = $n . ' ' . ($v ?? 'не установлен');
    }
  }
  $L[] = 'пакеты: ' . implode(', ', $pk);
  $mi = (string)@file_get_contents('/proc/meminfo');
  $mb = fn($k) => preg_match('/^' . $k . ':\s+(\d+)/m', $mi, $m) ? round((int)$m[1] / 1024) . ' МБ' : '?';
  $L[] = 'память: всего ' . $mb('MemTotal') . ', доступно ' . $mb('MemAvailable') . ', подкачка свободна ' . $mb('SwapFree');
  $fs = [];
  foreach (array_unique([ROOT !== '' ? '/opt' : '/overlay', '/tmp']) as $d) {
    if (is_dir($d) && ($f = @disk_free_space($d)) !== false) {
      $fs[] = $d . ' ' . round($f / 1048576, 1) . ' МБ';
    }
  }
  $L[] = 'свободно на диске: ' . ($fs ? implode(', ', $fs) : '?');
  $php = array_slice(array_filter(explode("\n", (string)@file_get_contents(PHP_LOG))), -20);
  $L[] = '';
  $L[] = '== Ошибки PHP (последние ' . count($php) . ') ==';
  foreach ($php as $l) {
    $L[] = substr($l, 0, 300);
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
      // без знака и в float: на 32-битном PHP (Keenetic mipsel) ip2long отрицателен от 128.0.0.0, и такие сети выпадали
      $start = (float)sprintf('%u', ip2long($m[1]));
      $v4[] = [$start, $start + 2 ** (32 - (int)$m[2]) - 1, $x];
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
  return ['name' => $name, 'path' => $path, 'count' => count($a['prefixes'])];
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
  seenInfo();   // первым — как и в state()
  pendingExpire(60);   // задание проверки после перезапуска могло погибнуть — откат не должен потеряться
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
    respond(['content' => $text, 'vars' => $vars, 'mtime' => filemtime(CONF_FILE)]);

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
    respond(lintConf($raw, $text));

  case 'lint_raw':
    $text = normalizeText($str('content'));
    respond(lintConf(confRawVars($text), $text));

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
    exec('(env -i PATH=' . JOB_PATH . ' NFQWS_UI_CLI=guard php-cgi -q -f ' . escapeshellarg(__FILE__) . ' >/dev/null 2>&1 &)');
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
    $sets = array_values(array_intersect(is_array($in['sets'] ?? null) ? $in['sets'] : ['config', 'std'], ['config', 'std', 'hist', 'other', 'community', 'ext', 'full']));
    $own = array_values(array_filter(is_array($in['steps'] ?? null) ? $in['steps'] : [], fn($t) => is_string($t) && preg_match('/^--lua-desync=[^\s"`$\\\\]+$/', $t)));
    // свои стратегии из формы подбора: до OWN_MAX штук, у каждой — только шаги --lua-desync
    $ownList = [];
    foreach (array_slice(is_array($in['own'] ?? null) && $cmd === 'test_start' ? $in['own'] : [], 0, OWN_MAX) as $o) {
      $st = array_values(array_filter(is_array($o) ? $o : [], fn($t) => is_string($t) && preg_match('/^--lua-desync=[^\s"`$\\\\]+$/', $t)));
      if ($st) {
        $ownList[] = array_slice($st, 0, OWN_STEPS);
      }
    }
    curlRequire();
    testLaunch(['type' => $cmd === 'trace_start' ? 'trace' : 'pick', 'host' => $host,
      'proto' => ($in['proto'] ?? '') === 'http' ? 'http' : (($in['proto'] ?? '') === 'quic' && $cmd === 'test_start' ? 'quic' : 'https'),
      'sets' => $own || $ownList ? $sets : ($sets ?: ['config', 'std']), 'steps' => $own, 'own' => $ownList,
      'repeats' => (int)($in['repeats'] ?? 3), 'refine' => !empty($in['refine'])]);
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
    $sets = array_values(array_intersect(is_array($in['sets'] ?? null) ? $in['sets'] : [], ['config', 'std', 'hist', 'community']));
    curlRequire();
    testLaunch(['type' => 'multi', 'host' => $list ? $list['name'] : 'сайтов: ' . count($hosts), 'hosts' => $hosts, 'list' => $list,
      'proto' => ($in['proto'] ?? '') === 'http' ? 'http' : 'https', 'sets' => $sets ?: ['config', 'std'], 'repeats' => (int)($in['repeats'] ?? 3)]);
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
    $cfgs = [['name' => 'мой (сейчас)', 'text' => (string)file_get_contents(CONF_FILE), 'mine' => true]];
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
    if (count($cfgs) < 2) {
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
    testLaunch(['type' => 'cfg', 'host' => 'конфигов: ' . (count($cfgs) - 1), 'configs' => $cfgs, 'hosts' => $hosts, 'repeats' => (int)($in['repeats'] ?? 3)]);
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
    $extra = [];
    foreach (array_slice(is_array($in['hosts'] ?? null) ? $in['hosts'] : [], 0, 3) as $h) {
      $h = cleanHost((string)$h);
      if (validHost($h) && !isIp($h)) {
        $extra[] = $h;
      }
    }
    curlRequire();
    testLaunch(['type' => 'freeze', 'host' => 'опорные сети', 'sets' => [], 'extra' => $extra]);
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
    respond(['config' => configCandidates($proto), 'std' => array_map(fn($x) => ['name' => $x[0], 'steps' => $x[1]], $proto === 'http' ? STD_HTTP : STD_TLS),
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
    foreach ($idx as &$e) {
      // что отличается от текущего состояния; у снимков только на NAS хешей нет — неизвестно
      $e['local'] = $e['local'] ?? true;
      $e['remote'] = !empty($e['remote']);
      $e['partial'] = ROOT && empty($e['full']);   // Keenetic, до 1.15.4: без конфига и списков
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
      'nfqws2' => (string)packageVersion('nfqws2-keenetic'), 'ui' => UI_VERSION, 'repo' => COMMUNITY_REPO]);

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
