<?php
// Server section: updating the interface and nfqws2, nfqws2 logs.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

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
  exec('(env -i PATH=' . JOB_PATH . ' NFQWS_UI_CLI=nfqupd php-cgi -q -f ' . escapeshellarg(API_FILE) . ' >/dev/null 2>&1 &)');
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
  // The snapshot may have been deleted by hand: then only the package goes back (fail() in snapArchive would
  // abort the job after the package is already installed)
  if ($job['rollback'] && $job['snap'] && !snapAvailable($job['snap'])) {
    nfqLog('Снимка ' . $job['snap'] . ' больше нет — конфиг и списки остаются как есть');
  } elseif ($job['rollback'] && $job['snap']) {
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
    $changed = restoreArchive(snapArchive($job['snap']), 'возврат nfqws2 ' . $to, true, $keep)['changed'];
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
  learnTmpOk();   // the package's ./tmp left /tmp 0755: «learn» (user nobody) could not write there
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
