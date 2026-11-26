<?php
defined('API_FILE') || exit;

const YT_PAGE = 'www.youtube.com';
const YT_HOSTS = ['www.youtube.com', 'redirector.googlevideo.com', 'i.ytimg.com'];
const YT_NAME_RE = '/(^|\.)(googlevideo\.com|youtube\.com|ytimg\.com|ggpht\.com)$/';
const YT_NODES_MAX = 8;
const YT_LIST_MAX = 128;
const YT_LIST_TARGETS = 24;
const YT_SPEED_SECS = 15;
const YT_SPEED_MIN_KB = 100;
const YT_GOOD = 3;
const YT_PROFILE = 'YouTube-узлы';
define('YT_FILE', UI_CONF_DIR . '/youtube.json');
define('YT_LIST', LISTS_DIR . '/youtube-nodes.list');

function ytCandidates(): array
{
  $c = [];
  $add = function (string $name, array $steps, bool $slow = false) use (&$c) {
    $c[implode(' ', $steps)] ??= ['name' => $name, 'steps' => $steps, 'slow' => $slow];
  };
  $add('multidisorder pos=midsld', ['--payload=tls_client_hello', '--lua-desync=multidisorder:pos=midsld']);
  foreach (STD_TLS as [$name, $steps]) {
    $add($name, array_merge(['--payload=tls_client_hello'], $steps));
  }
  $add('wssize + multidisorder pos=midsld', ['--lua-desync=wssize:wsize=1:scale=1', '--payload=tls_client_hello', '--lua-desync=multidisorder:pos=midsld'], true);
  $add('wssize + multisplit pos=1,midsld', ['--lua-desync=wssize:wsize=1:scale=1', '--payload=tls_client_hello', '--lua-desync=multisplit:pos=1,midsld'], true);
  return array_values($c);
}

function ytCurl(string $host, string $ip, string $args, int $secs): array
{
  [$lo, $hi] = array_map('intval', explode(':', TEST_PORTS));
  exec('curl -4 -sk ' . $args . ' --local-port ' . mt_rand($lo, $hi - 50) . '-' . $hi . ' --connect-timeout 4 -m ' . $secs
    . ' --resolve ' . escapeshellarg("$host:443:$ip")
    . ' -A ' . escapeshellarg('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130 Safari/537.36')
    . " -w '%{time_appconnect} %{http_code} %{size_download} %{time_total}' " . escapeshellarg("https://$host/") . ' 2>/dev/null', $o, $rc);
  [$hs, $code, $size, $total] = array_pad(explode(' ', trim(implode('', $o))), 4, '0');
  return ['rc' => $rc, 'hs' => (float)$hs, 'code' => (int)$code, 'size' => (int)$size, 'total' => (float)$total];
}

function ytHandshake(string $host, string $ip): array
{
  $r = ytCurl($host, $ip, '-I -o /dev/null', 6);
  if ($r['hs'] > 0) {
    return ['ok' => true, 'ms' => (int)round($r['hs'] * 1000), 'reason' => null];
  }
  return ['ok' => false, 'ms' => null, 'reason' => match ($r['rc']) {
    28 => 'нет ответа на приветствие TLS',
    7 => 'не удалось подключиться',
    35 => 'обрыв TLS',
    56 => 'соединение сброшено',
    default => "ошибка curl {$r['rc']}",
  }];
}

function ytTcpOpen(string $ip): bool
{
  [$lo, $hi] = array_map('intval', explode(':', TEST_PORTS));
  for ($i = 0; $i < 2; $i++) {
    $o = [];
    exec('curl -4 -s -o /dev/null --local-port ' . mt_rand($lo, $hi - 50) . '-' . $hi . ' --connect-timeout 4 -m 5 -w "%{time_connect}" '
      . escapeshellarg("http://$ip:443/") . ' 2>/dev/null', $o);
    if ((float)trim(implode('', $o)) > 0) {
      return true;
    }
  }
  return false;
}

function ytCurrentSteps(): ?array
{
  foreach (array_column(expectedProfiles(confValues()), 'tokens') as $pt) {
    if (!in_array('--name=' . YT_PROFILE, $pt, true)) {
      continue;
    }
    if (in_array('--skip', $pt, true) || array_filter($pt, fn($t) => preg_match('/^--(filter-(?!tcp=)|hostlist|ipset-exclude)/', $t))) {
      return null;
    }
    $steps = array_values(array_filter($pt, fn($t) => !preg_match('/^--(name|filter-tcp|ipset|ipset-ip)=/', $t)));
    return $steps ?: null;
  }
  return null;
}

function ytSpeed(string $ip): array
{
  $r = ytCurl(YT_PAGE, $ip, '-o /dev/null', YT_SPEED_SECS);
  $kb = (int)round($r['size'] / 1024);
  $ms = (int)round($r['total'] * 1000);
  if ($r['hs'] <= 0) {
    return ['ok' => false, 'kb' => 0, 'ms' => null, 'reason' => 'нет ответа на приветствие TLS'];
  }
  if ($r['rc'] === 0 && $r['code'] === 200 && $kb >= YT_SPEED_MIN_KB) {
    return ['ok' => true, 'kb' => $kb, 'ms' => $ms, 'reason' => null];
  }
  if ($r['rc'] === 0) {
    return ['ok' => true, 'kb' => $kb, 'ms' => null, 'reason' => 'страница слишком мала, скорость не измерить'];
  }
  if (in_array($r['rc'], [28, 56], true) && $kb > 0 && $kb < 64) {
    return ['ok' => false, 'kb' => $kb, 'ms' => $ms, 'reason' => REASON_FREEZE, 'freeze' => true];
  }
  if ($r['rc'] === 28) {
    return ['ok' => false, 'kb' => $kb, 'ms' => $ms, 'reason' => sprintf('за %d с пришло только %d КБ', YT_SPEED_SECS, $kb), 'slow' => true];
  }
  return ['ok' => false, 'kb' => $kb, 'ms' => $ms, 'reason' => "ошибка curl {$r['rc']}"];
}

function ytRecorded(): array
{
  $d = connData();
  $out = [];
  foreach ($d['flows'] ?? [] as $f) {
    if (($f['proto'] ?? '') !== 'tcp' || (int)($f['dport'] ?? 0) !== 443 || !isIp4((string)($f['dst'] ?? '')) || ipReserved($f['dst']) || ytFakeIp($f['dst'])) {
      continue;
    }
    $name = null;
    foreach ((array)($f['names'] ?? []) as $n) {
      if (preg_match(YT_NAME_RE, (string)$n)) {
        $name = (string)$n;
        break;
      }
    }
    if ($name !== null) {
      $out[$f['dst']] ??= ['host' => $name, 'ip' => $f['dst'], 'from' => 'record', 'stalled' => !empty($f['noans']) || !empty($f['stalled'])];
    }
  }
  usort($out, fn($a, $b) => $b['stalled'] <=> $a['stalled']);
  return ['nodes' => array_slice(array_values($out), 0, YT_NODES_MAX), 'recorded' => !empty($d['started']) ? (int)$d['started'] : null, 'device' => $d['ip'] ?? null];
}

function ytListIps(): array
{
  $ips = [];
  foreach (@file(YT_LIST, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $ip = preg_replace('~/32$~', '', trim(preg_replace('/#.*$/', '', $line)));
    if (isIp4($ip)) {
      $ips[] = $ip;
    }
  }
  return array_values(array_unique($ips));
}

function ytForeign(): ?string
{
  $text = (string)@file_get_contents(CONF_FILE);
  if (str_contains($text, 'yt_cdn_observe')) {
    return 'yt_cdn_observe';
  }
  if (str_contains($text, 'youtube-cdn-ip.list')) {
    return 'youtube-cdn-ip.list';
  }
  return null;
}

function ytProfileTokens(array $steps): array
{
  return array_merge(['--name=' . YT_PROFILE, '--filter-tcp=443', '--ipset=' . YT_LIST, '--ipset-ip=0.0.0.0/32'], $steps);
}

function ytFakeIp(string $ip): bool
{
  return str_starts_with($ip, '198.18.') || str_starts_with($ip, '198.19.');
}

function ytTargets(): array
{
  $t = [];
  foreach (YT_HOSTS as $h) {
    $d = diagDns($h);
    if ($d['status'] === 'podkop' || array_filter($d['ips'] ?? [], 'ytFakeIp')) {
      return ['podkop' => true, 'list' => []];
    }
    foreach ($d['ips'] ?? [] as $ip) {
      if (isIp4($ip) && !ipReserved($ip)) {
        $t[$ip] ??= ['host' => $h, 'ip' => $ip, 'from' => 'fixed'];
        break;
      }
    }
  }
  foreach (ytRecorded()['nodes'] as $n) {
    $t[$n['ip']] ??= $n;
  }
  foreach (array_slice(ytListIps(), 0, YT_LIST_TARGETS) as $ip) {
    $t[$ip] ??= ['host' => 'redirector.googlevideo.com', 'ip' => $ip, 'from' => 'list'];
  }
  return ['podkop' => false, 'list' => array_values($t)];
}

function ytJob(array $job, array &$status, ?array &$nfq, string $iface, array $baseArgs): void
{
  $status['pick'] = !empty($job['pick']);
  $status['foreign'] = ytForeign();
  $finish = function (string $verdict) use (&$status) {
    $status['total'] = $status['done'];
    $stopped = is_file(TEST_DIR . '/stop');
    $status['verdict'] = $stopped ? 'stopped' : $verdict;
    $status['state'] = $stopped ? 'stopped' : 'done';
    $status['finished'] = time();
    $status['current'] = null;
    testSaveStatus($status);
    putSafe(YT_FILE, json_encode(array_intersect_key($status, array_flip(['state', 'started', 'finished', 'verdict', 'targets', 'speed', 'results', 'best', 'blocked', 'stale', 'unreach', 'foreign', 'profile', 'harm', 'pick'])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  };

  $status['phase'] = 'targets';
  $status['current'] = 'ищу адреса YouTube';
  $status['total'] = 3;
  testSaveStatus($status);
  $tg = ytTargets();
  if ($tg['podkop']) {
    $status['done'] = $status['total'];
    $finish('tunnel');
    return;
  }
  $targets = $tg['list'];
  if (!$targets) {
    $status['done'] = $status['total'];
    $finish('unresolved');
    return;
  }
  $page = current(array_filter($targets, fn($t) => $t['host'] === YT_PAGE)) ?: null;

  $status['phase'] = 'baseline';
  $status['current'] = 'YouTube без обхода';
  $status['done'] = 1;
  testSaveStatus($status);
  testRules('bypass', $iface);
  $stop = fn() => is_file(TEST_DIR . '/stop');
  foreach ($targets as $i => &$t) {
    if ($stop()) {
      break;
    }
    $status['current'] = sprintf('без обхода: %s (%d из %d)', $t['host'], $i + 1, count($targets));
    testSaveStatus($status);
    $t['base'] = ytHandshake($t['host'], $t['ip']);
  }
  unset($t);
  if ($stop()) {
    $finish('stopped');
    return;
  }

  $status['phase'] = 'config';
  $status['current'] = 'YouTube через ваш конфиг';
  $status['done'] = 2;
  testSaveStatus($status);
  testRules('queue', $iface);
  $args = $baseArgs;
  foreach (array_column(expectedProfiles(confValues()), 'tokens') as $k => $pt) {
    if ($k > 0) {
      $args[] = '--new';
    }
    array_push($args, ...$pt);
  }
  $nfq = testNfqwsStart($args);
  if (!$nfq) {
    $status['state'] = 'error';
    $status['error'] = 'Не удалось запустить проверочный nfqws2 с копией вашего конфига: ' . trim((string)@file_get_contents(TEST_DIR . '/nfqws.out'));
    testSaveStatus($status);
    return;
  }
  foreach ($targets as $i => &$t) {
    if ($stop()) {
      break;
    }
    $status['current'] = sprintf('через ваш конфиг: %s (%d из %d)', $t['host'], $i + 1, count($targets));
    testSaveStatus($status);
    $t['conf'] = ytHandshake($t['host'], $t['ip']);
  }
  unset($t);
  $pageConf = $page && !$stop() ? current(array_filter($targets, fn($t) => $t['ip'] === $page['ip']))['conf'] ?? null : null;
  $status['speed'] = $pageConf && $pageConf['ok'] ? ['conf' => ytSpeed($page['ip'])] : null;
  testNfqwsStop($nfq);
  $nfq = null;
  if ($stop()) {
    $finish('stopped');
    return;
  }
  $status['done'] = 3;

  foreach ($targets as &$t) {
    if (!$t['base']['ok'] && !$t['conf']['ok'] && !$stop()) {
      $status['current'] = "проверяю, открывается ли TCP: {$t['host']} ({$t['ip']})";
      testSaveStatus($status);
      $t['tcp'] = ytTcpOpen($t['ip']);
    }
  }
  unset($t);
  $listed = ytListIps();
  $status['stale'] = array_values(array_map(fn($t) => $t['ip'], array_filter($targets, fn($t) => in_array($t['ip'], $listed, true) && $t['base']['ok'])));
  $status['unreach'] = array_values(array_map(fn($t) => $t['ip'], array_filter($targets, fn($t) => ($t['tcp'] ?? true) === false)));
  $blocked = array_values(array_filter($targets, fn($t) => !$t['conf']['ok'] && ($t['tcp'] ?? true) !== false));
  $status['targets'] = $targets;
  $status['blocked'] = array_column($blocked, 'ip');
  $status['harm'] = $blocked && !array_filter($blocked, fn($t) => !$t['base']['ok']);
  $freeze = !empty($status['speed']['conf']['freeze']);

  if (!$blocked) {
    $allBase = !array_filter($targets, fn($t) => !$t['base']['ok'] && ($t['tcp'] ?? true) !== false);
    $anyConf = (bool)array_filter($targets, fn($t) => $t['conf']['ok']);
    $finish($freeze ? 'freeze' : (!$anyConf ? 'unreach' : ($allBase ? 'open' : 'works')));
    return;
  }
  if (!$status['pick'] || $status['foreign'] || is_file(TEST_DIR . '/stop')) {
    $finish('blocked');
    return;
  }

  $cands = $status['harm'] ? [['name' => 'pass, без обхода', 'steps' => ['--lua-desync=pass'], 'slow' => false]] : ytCandidates();
  $current = $status['harm'] ? null : ytCurrentSteps();
  if ($current) {
    $cands = array_merge([['name' => 'настройка профиля «' . YT_PROFILE . '»', 'steps' => $current, 'slow' => false, 'current' => true]],
      array_values(array_filter($cands, fn($c) => $c['steps'] !== $current)));
  }
  $status['phase'] = 'pick';
  $status['results'] = [];
  $status['total'] = $status['done'] + count($cands) + 1;
  testSaveStatus($status);
  $first = $blocked[0];
  $fast = 0;
  foreach ($cands as $c) {
    if (is_file(TEST_DIR . '/stop') || ($c['slow'] && $fast > 0) || $fast >= YT_GOOD) {
      continue;
    }
    $status['current'] = $c['name'];
    testSaveStatus($status);
    $nfq = testNfqwsStart(array_merge($baseArgs, ['--filter-tcp=443'], $c['steps']));
    $r = ['ok' => false, 'reason' => 'nfqws2 не принял параметры: ' . trim((string)@file_get_contents(TEST_DIR . '/nfqws.out'))];
    if ($nfq) {
      $hs = ytHandshake($first['host'], $first['ip']);
      $r = ['ok' => $hs['ok'], 'ms' => $hs['ms'], 'reason' => $hs['reason']];
      foreach (array_slice($blocked, 1) as $b) {
        if (!$r['ok']) {
          break;
        }
        $h = ytHandshake($b['host'], $b['ip']);
        if (!$h['ok']) {
          $r = ['ok' => false, 'reason' => "{$b['host']} ({$b['ip']}): {$h['reason']}"];
        }
      }
      if ($r['ok'] && $page) {
        $r['speed'] = ytSpeed($page['ip']);
        if (!$r['speed']['ok']) {
          $r['ok'] = false;
          $r['reason'] = 'приветствие проходит, но страница YouTube: ' . $r['speed']['reason'];
        }
      }
    }
    testNfqwsStop($nfq);
    $nfq = null;
    $status['results'][] = $c + $r;
    $status['done']++;
    if ($r['ok'] && !$c['slow']) {
      $fast++;
    }
    testSaveStatus($status);
  }
  $good = array_values(array_filter($status['results'], fn($r) => $r['ok']));
  if (!$good) {
    $finish('none');
    return;
  }
  $fastOnes = array_values(array_filter($good, fn($r) => !$r['slow'])) ?: $good;
  $ms = array_filter(array_map(fn($r) => $r['speed']['ms'] ?? null, $fastOnes));
  $limit = $ms ? min($ms) * 1.5 : PHP_INT_MAX;
  $order = array_merge(array_filter($good, fn($r) => !empty($r['current'])), array_filter($fastOnes, fn($r) => ($r['speed']['ms'] ?? PHP_INT_MAX) <= $limit), $fastOnes, $good);
  $seen = [];
  $order = array_values(array_filter($order, function ($r) use (&$seen) {
    $k = implode(' ', $r['steps']);
    return !isset($seen[$k]) && ($seen[$k] = true);
  }));
  $status['phase'] = 'confirm';
  $status['total'] = $status['done'] + min(2, count($order));
  $best = null;
  foreach (array_slice($order, 0, 2) as $cand) {
    if ($stop()) {
      $finish('stopped');
      return;
    }
    $status['current'] = 'повторная проверка: ' . $cand['name'];
    testSaveStatus($status);
    $nfq = testNfqwsStart(array_merge($baseArgs, ['--filter-tcp=443'], $cand['steps']));
    $confirm = (bool)$nfq;
    foreach ($nfq ? $blocked : [] as $b) {
      $confirm = $confirm && ytHandshake($b['host'], $b['ip'])['ok'];
    }
    $keep = [];
    foreach ($confirm && !$status['harm'] ? array_diff($listed, $status['stale'], $status['blocked'], $status['unreach']) : [] as $ip) {
      if (!$stop() && ytHandshake('redirector.googlevideo.com', $ip)['ok']) {
        $keep[] = $ip;
      }
    }
    $again = $confirm && $page ? ytSpeed($page['ip']) : null;
    testNfqwsStop($nfq);
    $nfq = null;
    $status['done']++;
    if ($confirm && !($again && !$again['ok'])) {
      $best = $cand;
      break;
    }
  }
  if (!$best) {
    $finish('none');
    return;
  }
  $best['confirm_speed'] = $again;
  $status['best'] = $best;
  $status['profile'] = ['tokens' => ytProfileTokens($best['steps']), 'list' => YT_LIST, 'ips' => array_slice(array_values(array_unique(array_merge($status['blocked'], $keep))), 0, YT_LIST_MAX)];
  $finish('found');
}

function ytLast(): array
{
  $s = testStatus();
  $f = json_decode((string)@file_get_contents(YT_FILE), true);
  $extra = ['recorded' => ytRecorded(), 'listed' => ytListIps(), 'foreign' => ytForeign()];
  if (($s['type'] ?? '') === 'youtube') {
    if (($s['state'] ?? '') === 'starting' && !testRunning()) {
      return ['state' => 'error', 'error' => 'Проверка не запустилась. Повторите; если не выходит — пришлите «Отчёт для помощи».', 'type' => 'youtube'] + $extra;
    }
    if (in_array($s['state'] ?? '', ['running', 'starting'], true)
      || (($s['state'] ?? '') === 'error' && (int)($s['started'] ?? 0) > (int)($f['started'] ?? 0))) {
      return $s + $extra;
    }
  }
  return array_merge(is_array($f) ? $f : ['state' => 'idle'], ['type' => 'youtube'], $extra);
}
