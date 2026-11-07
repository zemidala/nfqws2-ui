<?php
// Server section: picking for several sites, one strategy on many sites.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

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
  // own strategies from the form — first, in the order pasted
  foreach ($job['own'] ?? [] as $st) {
    $add(['name' => strategyName($st), 'from' => 'своя стратегия', 'steps' => $st, 'own' => true]);
  }
  if (in_array('config', $job['sets'], true)) {
    array_map($add, configCandidates($proto, in_array('ports', $job['sets'], true)));
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
      if ($pin = probePin($h, $dns, $http)) {
        $row['dns_pin'] = $pin;
      }
      $b = curlProbe($h, $http);
      if ($b['ok']) {
        $row['skip'] = $pin ? 'закрыт только подменой DNS: по настоящему адресу открывается без обхода, нужен другой DNS' : 'открывается без обхода';
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
  $nOwn = count(array_filter($cands, fn($c) => !empty($c['own'])));
  $status['total'] = $status['done'] + count($blocked) * count($chunks) + min(MULTI_CONFIRM, count($cands) - $nOwn) + $nOwn;
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
  $rank = 0;   // place among the others: own strategies are re-checked whatever the batches said, the rest — the best MULTI_CONFIRM
  foreach ($order as $i) {
    $c = $cands[$i];
    $e = ['name' => $c['name'], 'from' => $c['from'], 'steps' => $c['steps'], 'profile' => array_merge($filter, $c['steps']),
      'scan' => $opened($i), 'scan_sites' => array_keys(array_filter($scan[$i]))] + (isset($c['hist']) ? ['hist' => $c['hist']] : [])
      + (!empty($c['own']) ? ['own' => true] : []);
    $check = !empty($c['own']) || $rank++ < MULTI_CONFIRM && $e['scan'] > 0;
    if ($check && $status['state'] === 'running' && !$stop()) {
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
    $dns = diagDns($h);
    if ($dns['status'] === 'nxdomain') {
      $row['skip'] = 'имя не находится';
    } else {
      if ($pin = probePin($h, $dns, $http)) {
        $row['dns_pin'] = $pin;
      }
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
