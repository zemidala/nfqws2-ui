<?php
defined('API_FILE') || exit;

const VOICE_SERVER = '104.29.136.182:19296';
const VOICE_PORTS = '19294-19344,50000-65535';
const VOICE_PROBE_SECS = 8;
const VOICE_CONFIRM_SECS = 25;
const VOICE_GAP_MS = 50;
const VOICE_CONFIRM = 2;
const VOICE_CONFIRM_TRIES = 4;
const VOICE_STUN = '0x000100002112a442766f6963652d636865636b21';
const VOICE_TCP_HOST = 'discord.media';
const VOICE_TCP_SNI = 'frankfurt1.discord.media';
const VOICE_TCP_PORTS = [443, 2053, 2083, 2087, 2096, 8443];
const VOICE_QUIC_NAMES = ['rutube.ru', 'lemana.pro', 'vk.com', 'ya.ru'];
define('VOICE_FILE', UI_CONF_DIR . '/voice.json');

function voiceFilter(int $port): array
{
  return ['--filter-udp=' . $port, '--filter-l7=discord,stun', '--payload=discord_ip_discovery,stun'];
}

function voiceBlobName(string $host): string
{
  return 'quic_initial_' . preg_replace('/[^a-z0-9]/', '_', $host);
}

function voiceQuicBlobs(array $baseArgs): array
{
  $have = implode(' ', $baseArgs);
  $out = [];
  foreach (VOICE_QUIC_NAMES as $host) {
    $name = voiceBlobName($host);
    if (str_contains($have, "--blob=$name:")) {
      $out[$host] = [$name, null];
      continue;
    }
    $pkt = quicPacket($host);
    if ($pkt === null) {
      continue;
    }
    $f = TEST_DIR . "/$name.bin";
    file_put_contents($f, $pkt);
    $out[$host] = [$name, "--blob=$name:@$f"];
  }
  return $out;
}

function voiceCandidates(array $baseArgs, array $quicBlobs, array $own): array
{
  $quic = array_filter($baseArgs, fn($t) => str_starts_with($t, '--blob=quic_initial:')) ? 'quic_initial' : 'fake_default_quic';
  $f = fn(string $blob, int $n, string $more = '') => "--lua-desync=fake:blob=$blob:repeats=$n$more";
  $c = [];
  foreach ($own as $steps) {
    $c[implode(' ', $steps)] ??= ['name' => strategyName($steps), 'from' => 'своя стратегия', 'steps' => $steps];
  }
  $std = [
    ['фейк QUIC ×6', [$f($quic, 6)]],
    ['фейк QUIC ×2', [$f($quic, 2)]],
    ['фейк STUN ×3', [$f(VOICE_STUN, 3)]],
    ['фейк QUIC ×11', [$f($quic, 11)]],
    ['фейк STUN ×6', [$f(VOICE_STUN, 6)]],
    ['фейки STUN и QUIC', [$f(VOICE_STUN, 2), $f($quic, 2)]],
    ['фейк QUIC ×6, TTL подбирается', [$f($quic, 6, ':ip_autottl=-2,3-20')]],
  ];
  foreach ($quicBlobs as $host => [$name]) {
    $std[] = ["фейк QUIC с именем $host ×6", [$f($name, 6)], $host];
  }
  foreach ($std as $s) {
    $c[implode(' ', $s[1])] ??= ['name' => $s[0], 'from' => 'стандартный набор', 'steps' => $s[1]] + (isset($s[2]) ? ['blob_host' => $s[2]] : []);
  }
  return array_values($c);
}

function voiceBlobInstall(string $host): array
{
  if (!in_array($host, VOICE_QUIC_NAMES, true)) {
    fail('Неизвестное имя для фейка QUIC');
  }
  $pkt = quicPacket($host);
  if ($pkt === null) {
    fail('Не удалось собрать пакет QUIC: nfqws2 не выполнил сценарий');
  }
  $name = voiceBlobName($host);
  @mkdir(CONF_DIR . '/blobs', 0755, true);
  $path = CONF_DIR . "/blobs/$name.bin";
  if (file_put_contents($path, $pkt) === false) {
    fail('Не удалось записать ' . $path);
  }
  return ['name' => $name, 'decl' => "--blob=$name:@$path"];
}

function voicePort(): int
{
  static $used = [];
  [$lo, $hi] = array_map('intval', explode(':', TEST_PORTS));
  do {
    $p = mt_rand($lo, $hi);
  } while (isset($used[$p]) && count($used) < $hi - $lo);
  $used[$p] = true;
  return $p;
}

function voiceProbe(string $ip, int $port, int $secs): array
{
  $s = false;
  for ($i = 0; $i < 5 && !$s; $i++) {
    $s = @stream_socket_client("udp://$ip:$port", $en, $es, 3, STREAM_CLIENT_CONNECT,
      stream_context_create(['socket' => ['bindto' => '0.0.0.0:' . voicePort()]]));
  }
  if (!$s) {
    return ['ok' => false, 'reason' => 'не удалось открыть соединение UDP', 'sent' => 0, 'got' => 0, 'kb' => 0, 'ms' => null];
  }
  stream_set_blocking($s, false);
  $pkt = pack('nnN', 1, 70, mt_rand(1, 0x7fffffff)) . str_repeat("\0", 64) . pack('n', 0);
  $t0 = microtime(true);
  $next = $t0;
  $sent = $got = $bytes = 0;
  $first = $last = null;
  $read = function () use ($s, $t0, &$got, &$bytes, &$first, &$last) {
    while (($r = @fread($s, 512)) !== false && $r !== '') {
      $got++;
      $bytes += strlen($r) + 28;
      $last = microtime(true) - $t0;
      $first ??= $last;
    }
  };
  while (($now = microtime(true)) - $t0 < $secs) {
    if ($now >= $next) {
      @fwrite($s, $pkt);
      $sent++;
      $next += VOICE_GAP_MS / 1000;
    }
    $read();
    usleep(5000);
  }
  usleep(400000);
  $read();
  fclose($s);
  $r = ['sent' => $sent, 'got' => $got, 'kb' => (int)round($bytes / 1024), 'ms' => $first === null ? null : (int)round($first * 1000), 'secs' => $secs];
  if ($got === 0) {
    return $r + ['ok' => false, 'reason' => 'нет ответа'];
  }
  if ($last < $secs - 1.5) {
    return $r + ['ok' => false, 'reason' => sprintf('замер через %s с, после %d ответов', str_replace('.', ',', (string)round($last, 1)), $got), 'froze_ms' => (int)round($last * 1000)];
  }
  return $r + ['ok' => true, 'reason' => null];
}

function voicePortCovered(int $port, string $var = 'UDP_PORTS'): bool
{
  foreach (explode(',', (string)(confValues()[$var] ?? '')) as $r) {
    $ab = array_map('intval', preg_split('/[:-]/', trim($r)));
    if (trim($r) !== '' && $port >= $ab[0] && $port <= ($ab[1] ?? $ab[0])) {
      return true;
    }
  }
  return false;
}

function voiceJob(array $job, array &$status, ?array &$nfq, string $iface, array $baseArgs): void
{
  [$ip, $port] = explode(':', $job['server']);
  $port = (int)$port;
  $status['server'] = $job['server'];
  $status['pick'] = !empty($job['pick']);
  $own = $job['own'] ?? [];
  $status['total'] = 2 + ($status['pick'] ? count(voiceCandidates($baseArgs, array_combine(VOICE_QUIC_NAMES, array_map(fn($h) => [voiceBlobName($h)], VOICE_QUIC_NAMES)), $own)) + VOICE_CONFIRM : 0);
  $finish = function (string $verdict) use (&$status) {
    $status['total'] = $status['done'];
    $stopped = is_file(TEST_DIR . '/stop');
    $status['verdict'] = $stopped ? 'stopped' : $verdict;
    $status['state'] = $stopped ? 'stopped' : 'done';
    $status['finished'] = time();
    $status['current'] = null;
    testSaveStatus($status);
    putSafe(VOICE_FILE, json_encode(array_intersect_key($status, array_flip(['state', 'started', 'finished', 'server', 'verdict', 'harm', 'baseline', 'config', 'results', 'tcp', 'udp_need', 'tcp_need'])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  };

  $tcpIp = voiceTcpIp();
  $tcp = ['ip' => $tcpIp, 'ports' => []];

  $status['phase'] = 'baseline';
  $status['current'] = 'голос без обхода';
  testSaveStatus($status);
  testRules('bypass', $iface);
  $status['baseline'] = voiceProbe($ip, $port, VOICE_PROBE_SECS);
  foreach ($tcpIp ? VOICE_TCP_PORTS : [] as $p) {
    $tcp['ports'][$p] = ['base' => voiceTcpProbe($tcpIp, $p)];
  }
  $status['done'] = 1;

  $status['phase'] = 'config';
  $status['current'] = 'голос через ваш конфиг';
  testSaveStatus($status);
  testRules('queue', $iface);
  $udpCovered = voicePortCovered($port);
  foreach ($tcp['ports'] as $p => &$t) {
    $t['covered'] = voicePortCovered($p, 'TCP_PORTS');
  }
  unset($t);
  if ($udpCovered || array_filter($tcp['ports'], fn($t) => $t['covered'])) {
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
  }
  $status['config'] = $udpCovered ? voiceProbe($ip, $port, VOICE_PROBE_SECS)
    : ['ok' => false, 'uncovered' => true, 'got' => 0, 'reason' => "порт $port не входит в UDP_PORTS, nfqws2 голос не обрабатывает"];
  foreach ($tcp['ports'] as $p => &$t) {
    $t['conf'] = $t['covered'] ? voiceTcpProbe($tcpIp, $p) : $t['base'];
  }
  unset($t);
  testNfqwsStop($nfq);
  $nfq = null;
  $tcp['closed'] = array_keys(array_filter($tcp['ports'], fn($t) => !$t['conf']));
  $status['tcp'] = $tcp;
  $status['done'] = 2;
  $udpOk = $status['config']['ok'] || ($status['baseline']['ok'] && !empty($status['config']['uncovered']));
  $status['udp_need'] = !$udpOk;
  $status['tcp_need'] = (bool)$tcp['closed'];
  if ($udpOk && !$tcp['closed']) {
    $finish($status['baseline']['ok'] ? 'open' : 'works');
    return;
  }
  $status['harm'] = !$udpOk && $status['baseline']['ok'];
  if (!$status['pick'] || is_file(TEST_DIR . '/stop')) {
    $finish($udpOk ? 'tcp_blocked' : ($status['harm'] ? 'harm' : ($status['baseline']['got'] === 0 && empty($status['config']['got']) ? 'unreachable' : 'blocked')));
    return;
  }
  if ($tcp['closed']) {
    $status['total'] = ($udpOk ? $status['done'] : $status['total']) + count(STD_TLS);
    voiceTcpPick($status, $nfq, $baseArgs, $tcpIp, $tcp['closed']);
  }
  if ($udpOk) {
    $finish(!empty($status['tcp']['best']) ? 'found' : 'tcp_none');
    return;
  }

  $status['phase'] = 'pick';
  $status['current'] = 'собираю фейки QUIC';
  testSaveStatus($status);
  $blobs = voiceQuicBlobs($baseArgs);
  $baseArgs = array_merge($baseArgs, array_values(array_filter(array_column($blobs, 1))));
  $cands = voiceCandidates($baseArgs, $blobs, $own);
  $status['total'] = $status['done'] + count($cands) + VOICE_CONFIRM;
  $status['phase'] = 'pick';
  $status['results'] = [];
  foreach ($cands as $c) {
    if (is_file(TEST_DIR . '/stop')) {
      break;
    }
    $status['current'] = $c['name'];
    testSaveStatus($status);
    $nfq = testNfqwsStart(array_merge($baseArgs, voiceFilter($port), $c['steps']));
    $r = $nfq ? voiceProbe($ip, $port, VOICE_PROBE_SECS) : ['ok' => false, 'reason' => 'nfqws2 не принял параметры: ' . trim((string)@file_get_contents(TEST_DIR . '/nfqws.out'))];
    testNfqwsStop($nfq);
    $nfq = null;
    $status['results'][] = $c + $r;
    $status['done']++;
    testSaveStatus($status);
  }
  $status['phase'] = 'confirm';
  $good = $tries = 0;
  foreach ($status['results'] as &$r) {
    if (!$r['ok'] || $good >= VOICE_CONFIRM || $tries >= VOICE_CONFIRM_TRIES || is_file(TEST_DIR . '/stop')) {
      continue;
    }
    $tries++;
    $status['current'] = 'долгая проверка: ' . $r['name'];
    $status['total'] = max($status['total'], $status['done'] + 1);
    testSaveStatus($status);
    $nfq = testNfqwsStart(array_merge($baseArgs, voiceFilter($port), $r['steps']));
    $long = $nfq ? voiceProbe($ip, $port, VOICE_CONFIRM_SECS) : ['ok' => false, 'reason' => 'nfqws2 не принял параметры'];
    testNfqwsStop($nfq);
    $nfq = null;
    $status['done']++;
    $r['long'] = $long;
    if ($long['ok']) {
      $good++;
    } else {
      $r['ok'] = false;
      $r['reason'] = 'в долгой проверке ' . $long['reason'];
    }
  }
  unset($r);
  $status['total'] = $status['done'];
  $finish(array_filter($status['results'], fn($r) => $r['ok'] && isset($r['long'])) ? 'found' : 'none');
}

function voiceTcpIp(): ?string
{
  $d = diagDns(VOICE_TCP_HOST);
  foreach ($d['status'] === 'podkop' ? [] : $d['ips'] ?? [] as $ip) {
    if (isIp4($ip) && !ipReserved($ip) && !str_starts_with($ip, '198.18.') && !str_starts_with($ip, '198.19.')) {
      return $ip;
    }
  }
  return null;
}

function voiceTcpProbe(string $ip, int $port): bool
{
  [$lo, $hi] = array_map('intval', explode(':', TEST_PORTS));
  $h = VOICE_TCP_SNI;
  exec('curl -4 -sk -o /dev/null --local-port ' . mt_rand($lo, $hi - 50) . '-' . $hi . " --connect-timeout 4 -m 6 --resolve $h:$port:" . escapeshellarg($ip)
    . " -w '%{time_appconnect}' " . escapeshellarg("https://$h:$port/") . ' 2>/dev/null', $o, $rc);
  return (float)trim(implode('', $o)) > 0;
}

function voiceTcpPick(array &$status, ?array &$nfq, array $baseArgs, string $ip, array $closed): void
{
  $status['phase'] = 'tcp';
  $status['tcp']['results'] = [];
  $port = $closed[0];
  foreach (STD_TLS as [$name, $steps]) {
    if (is_file(TEST_DIR . '/stop')) {
      return;
    }
    $status['current'] = "подключение (TCP $port): $name";
    testSaveStatus($status);
    $nfq = testNfqwsStart(array_merge($baseArgs, ['--filter-tcp=' . implode(',', $closed), '--filter-l7=tls', '--payload=tls_client_hello'], $steps));
    $ok = false;
    if ($nfq) {
      $ok = true;
      foreach ($closed as $p) {
        for ($i = 0; $i < 2 && $ok; $i++) {
          $ok = voiceTcpProbe($ip, $p);
        }
      }
    }
    testNfqwsStop($nfq);
    $nfq = null;
    $status['tcp']['results'][] = ['name' => $name, 'steps' => $steps, 'ok' => $ok];
    $status['done']++;
    if ($ok) {
      $status['tcp']['best'] = ['name' => $name, 'steps' => $steps];
      $status['total'] -= count(STD_TLS) - count($status['tcp']['results']);
      testSaveStatus($status);
      return;
    }
  }
}

function voiceLast(): array
{
  $s = testStatus();
  $f = json_decode((string)@file_get_contents(VOICE_FILE), true);
  if (($s['type'] ?? '') === 'voice') {
    if (($s['state'] ?? '') === 'starting' && !testRunning()) {
      return ['state' => 'error', 'error' => 'Проверка не запустилась. Повторите; если не выходит — пришлите «Отчёт для помощи».', 'type' => 'voice', 'default_server' => VOICE_SERVER];
    }
    if (in_array($s['state'] ?? '', ['running', 'starting'], true)
      || (($s['state'] ?? '') === 'error' && (int)($s['started'] ?? 0) > (int)($f['started'] ?? 0))) {
      return $s + ['default_server' => VOICE_SERVER];
    }
  }
  return (is_array($f) ? $f : ['state' => 'idle']) + ['type' => 'voice', 'default_server' => VOICE_SERVER];
}
