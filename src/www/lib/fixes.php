<?php
// Server section: fixes, undo, restart with a check and rollback, duplicates.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

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
  // The restored files are the working state: mark it, so the next rollback does not count this rollback as a
  // change and bring back the failed config. The process start time alone can read a second before the restore
  // entries in the history, and then versionsAt() took the rolled-back version as «from».
  @mkdir(UI_CONF_DIR, 0755, true);
  putSafe(CONFIRM_FILE, json_encode(['ts' => time() + 1]));
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
  exec('(sleep ' . $left . '; env -i PATH=' . JOB_PATH . ' NFQWS_UI_CLI=guard_deadline php-cgi -q -f ' . escapeshellarg(API_FILE) . ' >/dev/null 2>&1) >/dev/null 2>&1 &');
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
