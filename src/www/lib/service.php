<?php
// Server section: the nfqws2 process: find, start, stop, restart.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

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
// The init script rebuilds its chains on start: put the device rules back right after it
function serviceRun(string $action): array
{
  $r = serviceRunRaw($action);
  if ($action !== 'stop') {
    devicesEnsure(true);
  }
  return $r;
}

function serviceRunRaw(string $action): array
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
  return $memo[$pkg] = packageVersionRead($pkg, $fresh);
}

// Версии установленных пакетов прямо из файла статуса opkg: запуск opkg на mipsel — десятки миллисекунд на каждый пакет,
// а файл читается один раз (и из кеша, пока не изменился). null — файла нет, спрашиваем opkg
function opkgVersions(bool $fresh = false): ?array
{
  static $v = null;
  $f = ROOT ? '/opt/lib/opkg/status' : '/usr/lib/opkg/status';
  if (!is_file($f)) {
    return null;
  }
  if ($v === null || $fresh) {
    $v = cached('opkg-versions', [$f], function () use ($f) {
      $out = [];
      foreach (preg_split('/\n\s*\n/', (string)file_get_contents($f)) as $b) {
        if (preg_match('/^Package: (\S+)/m', $b, $p) && preg_match('/^Version: (\S+)/m', $b, $ver)
          && preg_match('/^Status: .*\binstalled\b/m', $b) && !preg_match('/^Status: .*\bnot-installed\b/m', $b)) {
          $out[$p[1]] = $ver[1];
        }
      }
      return $out;
    });
  }
  return $v;
}

function packageVersionRead(string $pkg, bool $fresh = false): ?string
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
  if (($all = opkgVersions($fresh)) !== null) {
    return $all[$pkg] ?? null;
  }
  exec((ROOT ? '/opt/bin/' : '') . 'opkg status ' . escapeshellarg($pkg) . ' 2>/dev/null', $out);
  foreach ($out as $l) {
    if (str_starts_with($l, 'Version: ')) {
      return substr($l, 9);
    }
  }
  return null;
}
