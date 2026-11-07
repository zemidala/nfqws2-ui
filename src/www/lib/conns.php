<?php
// Server section: connections of a home device.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ================= device connections =================
// What a program on a home device really talks to: for N seconds the job reads the connection table
// (/proc/net/nf_conntrack, packet and byte counters in both directions) and keeps the device's connections to the
// internet. The answer to "Discord says it works but voice drops": UDP flows with no reply, or flows whose port
// nfqws2 does not see at all. Read-only: nothing on the router changes.
const CONN_FILE = '/tmp/nfqws-ui-conns.json';
const CONN_SECS = [30, 60, 120];
const CONN_MAX = 300;      // rows kept
const CONN_KEEP = 3000;    // connections tracked in memory at most (a browser or a torrent opens hundreds)
const CONN_STALL = 6;      // s: the device keeps sending, nothing comes back — the connection stalled
const CONN_LOCK = '/tmp/nfqws-ui-conns.lock';
const CONN_STOP = '/tmp/nfqws-ui-conns.stop';   // «Остановить»: recording ends early, what was recorded is annotated

function connStop(): array
{
  $d = connData();
  if (!empty($d['running']) && ($d['step'] ?? '') !== 'annotate') {
    file_put_contents(CONN_STOP, (string)($d['id'] ?? ''));
  }
  return $d;
}

// One line of nf_conntrack → [proto, src, dst, sport, dport, packets, bytes, rpackets, rbytes, state].
// The counters are there only with accounting on (nf_conntrack_acct); IPv6 lines are not read — the device is
// chosen by its IPv4 address
function connParse(string $line): ?array
{
  if (!preg_match('/^ipv4\s+\d+\s+(tcp|udp)\s+\d+\s+\d+\s+(?:([A-Z_]+)\s+)?src=(\S+) dst=(\S+) sport=(\d+) dport=(\d+) packets=(\d+) bytes=(\d+)(?: \[UNREPLIED\])? src=\S+ dst=\S+ sport=\d+ dport=\d+ packets=(\d+) bytes=(\d+)/', $line, $m)) {
    return null;
  }
  return ['proto' => $m[1], 'state' => $m[2], 'src' => $m[3], 'dst' => $m[4], 'sport' => (int)$m[5], 'dport' => (int)$m[6],
    'op' => (int)$m[7], 'ob' => (int)$m[8], 'rp' => (int)$m[9], 'rb' => (int)$m[10]];
}

function connTable(): array
{
  $out = [];
  foreach (explode("\n", (string)@file_get_contents('/proc/net/nf_conntrack')) as $l) {
    if ($l !== '' && ($c = connParse($l))) {
      $out[] = $c;
    }
  }
  return $out;
}

// An address of a home network device: 10/8, 172.16/12, 192.168/16
function ipLan(string $ip): bool
{
  if (!isIp4($ip)) {
    return false;
  }
  $b = inet_pton($ip);
  return ipInEntry($b, '10.0.0.0/8') || ipInEntry($b, '172.16.0.0/12') || ipInEntry($b, '192.168.0.0/16');
}

// A destination outside: everything not reserved; podkop's FakeIP (198.18.x) counts — it is traffic to the internet
// that goes through the tunnel, and the owner wants to see it too
function ipOutside(string $ip): bool
{
  return isIp4($ip) && (!ipReserved($ip) || ipFake($ip));
}

function ipFake(string $ip): bool
{
  return isIp4($ip) && ipInEntry(inet_pton($ip), '198.18.0.0/15');
}

// Lines in the table but no counters in them: accounting is off and nothing can be measured
function connNoAcct(): bool
{
  $acct = @file_get_contents('/proc/sys/net/netfilter/nf_conntrack_acct');
  if ($acct !== false) {
    return trim($acct) === '0';
  }
  $raw = (string)@file_get_contents('/proc/net/nf_conntrack');
  return $raw !== '' && !str_contains($raw, 'packets=');
}

// Home devices that have connections to the internet now, with names where the router knows them
function connDevices(): array
{
  $names = [];
  if (ROOT === '') {
    foreach (devicesList() as $d) {
      if (!empty($d['ip'])) {
        $names[$d['ip']] = $d['name'] ?: $d['mac'];
      }
    }
  }
  $own = array_filter(array_map('trim', explode(' ', (string)shell_exec('ip -4 -o addr show 2>/dev/null | awk \'{print $4}\' | cut -d/ -f1 | tr "\n" " "'))));
  $n = [];
  foreach (connTable() as $c) {
    if (ipLan($c['src']) && ipOutside($c['dst']) && !in_array($c['src'], $own, true)) {
      $n[$c['src']] = ($n[$c['src']] ?? 0) + 1;
    }
  }
  arsort($n);
  $out = [];
  foreach ($n as $ip => $cnt) {
    $out[] = ['ip' => $ip, 'name' => $names[$ip] ?? '', 'flows' => $cnt];
  }
  foreach ($names as $ip => $name) {
    if (!isset($n[$ip])) {
      $out[] = ['ip' => $ip, 'name' => $name, 'flows' => 0];
    }
  }
  return $out;
}

function connData(): array
{
  $d = json_decode((string)@file_get_contents(CONN_FILE), true);
  // the job writes the file every 2 s while recording and after each network lookup — 40 s of silence is a dead job
  if (is_array($d) && !empty($d['running']) && time() - (int)@filemtime(CONN_FILE) > 40) {
    $d['running'] = false;
    $d['died'] = true;
  }
  return is_array($d) ? $d : [];
}

// $names — capture the device's packets (tcpdump) for the site names and a .pcap
function connStart(string $ip, int $secs, bool $names = false): array
{
  ipLan($ip) || fail('Нужен адрес устройства домашней сети');
  in_array($secs, CONN_SECS, true) || fail('Неверная длительность');
  // two quick clicks or two tabs: only one passes
  $lock = fopen(CONN_LOCK, 'c');
  if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fail('Запись уже запускается');
  }
  !empty(connData()['running']) && fail('Запись уже идёт');
  captureClear();   // the last recording's .pcap lies in memory (/tmp): it goes with the recording it belongs to
  $d = ['running' => true, 'id' => bin2hex(random_bytes(6)), 'ip' => $ip, 'secs' => $secs, 'started' => time(), 'flows' => [], 'noacct' => connNoAcct(), 'names' => $names];
  file_put_contents(CONN_FILE, json_encode($d));
  exec('(env -i PATH=' . JOB_PATH . ' NFQWS_UI_CLI=conns php-cgi -q -f ' . escapeshellarg(API_FILE) . ' >/dev/null 2>&1 &)');
  flock($lock, LOCK_UN);
  return $d;
}

// A row = (protocol, address, port): a program opens many connections to one server. Every connection is followed
// on its own — whether it got any answer, whether it stalled (the device kept sending, nothing came back for
// CONN_STALL s, and no answer came later) — and the row counts them, so one working connection does not hide the
// failed ones
function connRows(array $conns, int $t0): array
{
  $rows = [];
  foreach ($conns as $c) {
    $k = $c['proto'] . '|' . $c['dst'] . '|' . $c['dport'];
    $r = $rows[$k] ?? ['proto' => $c['proto'], 'dst' => $c['dst'], 'dport' => $c['dport'], 'first' => $c['first'] - $t0,
      'n' => 0, 'noans' => 0, 'stalled' => 0, 'op' => 0, 'ob' => 0, 'rp' => 0, 'rb' => 0, 'stall' => 0];
    $r['n']++;
    foreach (['op', 'ob', 'rp', 'rb'] as $x) {
      $r[$x] += $c[$x];
    }
    if (!$c['rp']) {
      $r['noans']++;
    } elseif ($c['stall'] !== null) {
      $r['stalled']++;
      $r['stall'] = max($r['stall'], $c['stall']);
    }
    $rows[$k] = $r;
  }
  uasort($rows, fn($a, $b) => $b['ob'] + $b['rb'] <=> $a['ob'] + $a['rb']);
  return array_slice($rows, 0, CONN_MAX, true);
}

function connJob(): void
{
  set_time_limit(0);
  $d = connData();
  $ip = (string)($d['ip'] ?? '');
  $id = (string)($d['id'] ?? '');
  $secs = (int)($d['secs'] ?? 60);
  if (!ipLan($ip) || $id === '') {
    return;
  }
  $t0 = time();
  $conns = [];
  $rows = [];
  // a newer recording took the file over (this job was given up as dead): stop, do not overwrite it
  $mine = function () use ($id) {
    return (json_decode((string)@file_get_contents(CONN_FILE), true)['id'] ?? '') === $id;
  };
  $save = function () use (&$d, &$rows, $mine) {
    if (!$mine()) {
      exit(0);
    }
    $d['flows'] = array_values($rows);
    file_put_contents(CONN_FILE, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  };
  @unlink(CONN_STOP);
  // a recording taken over by a newer one exits in $save: its tcpdump stops by itself at the end of its time
  $cap = !empty($d['names']) ? captureStart($ip, $secs) : [];
  $d['capture'] = (bool)$cap;
  register_shutdown_function(function () use (&$cap) {
    captureStop($cap);   // also when a newer recording took over and $save exits
  });
  while (time() - $t0 < $secs) {
    $cap = captureTrim($cap, $d);
    if (@file_get_contents(CONN_STOP) === $id) {
      @unlink(CONN_STOP);
      $d['stopped'] = true;
      break;
    }
    $now = time();
    foreach (connTable() as $c) {
      if ($c['src'] !== $ip || !ipOutside($c['dst'])) {
        continue;
      }
      $ck = $c['proto'] . '|' . $c['dst'] . '|' . $c['dport'] . '|' . $c['sport'];
      $p = $conns[$ck] ?? null;
      if (!$p && count($conns) >= CONN_KEEP) {
        continue;
      }
      $n = $c + ['first' => $p['first'] ?? $now, 'quiet' => null, 'stall' => null];
      if ($p) {
        if ($c['rb'] > $p['rb']) {
          $n['quiet'] = null;   // an answer came: whatever stalled before has recovered
          $n['stall'] = null;
        } elseif ($c['ob'] > $p['ob']) {
          $n['quiet'] = $p['quiet'] ?? $now;   // sending, nothing back
          $n['stall'] = $now - $n['quiet'] >= CONN_STALL ? $c['rb'] : $p['stall'];
        } else {
          $n['quiet'] = null;   // idle both ways is not a stall
          $n['stall'] = $p['stall'];
        }
      }
      $conns[$ck] = $n;
    }
    $rows = connRows($conns, $t0);
    $d['elapsed'] = $now - $t0;
    $save();
    sleep(2);
  }
  // after the recording: does nfqws2 see the port, which profile takes the address, whose network it is
  $d['step'] = 'annotate';
  captureStop($cap);
  $cap = [];
  if ($d['capture']) {
    $byIp = pcapNames(CONN_NAMES, $ip);
    @unlink(CONN_NAMES);
    foreach ($rows as &$f) {
      if (isset($byIp[$f['dst']])) {
        $f['names'] = $byIp[$f['dst']];   // the first one — from the device's own greeting when it had one
      }
    }
    unset($f);
    clearstatcache(true, CONN_PCAP);
    $d['pcap'] = (int)@filesize(CONN_PCAP);
  }
  $v = confValues();
  $ports = ['tcp' => parsePorts(str_replace(':', '-', $v['TCP_PORTS'])) ?? [], 'udp' => parsePorts(str_replace(':', '-', $v['UDP_PORTS'])) ?? []];
  $profiles = currentProfiles();
  $cache = new ListCache();
  $names = 0;
  $tLook = time();
  foreach ($rows as &$f) {
    if ($f['podkop'] = ipFake($f['dst'])) {
      continue;   // goes into the tunnel; nfqws2 and the networks of FakeIP addresses do not apply
    }
    $f['seen'] = inRanges($f['dport'], $ports[$f['proto']]);
    $l7 = $f['proto'] === 'tcp' ? ($f['dport'] === 443 ? 'tls' : ($f['dport'] === 80 ? 'http' : 'unknown')) : ($f['dport'] === 443 ? 'quic' : 'unknown');
    // a profile by address only: for HTTPS and QUIC the site name (SNI) decides, and the table has no names
    if ($f['seen'] && $l7 === 'unknown') {
      // the table does not say what protocol a UDP flow carries; voice programs are recognised by nfqws2 as discord
      // or stun, and profiles often filter by that (--filter-l7=discord,stun)
      foreach ($f['proto'] === 'udp' ? ['unknown', 'discord', 'stun'] : ['unknown'] as $try) {
        if ($p = matchRoute($profiles, $f['dst'], [$f['dst']], $f['proto'], $f['dport'], $try, $cache)['profile'] ?? null) {
          $f['profile'] = $p;
          if ($try !== 'unknown') {
            $f['l7'] = $try;
          }
          break;
        }
      }
    }
    // whose network: at most 30 lookups and 60 s (RIPEstat may be slow), progress written after each
    if ($names++ < 30 && time() - $tLook < 60 && ($a = ipAsn($f['dst']))) {
      $f['asn'] = $a;
      $f['as_name'] = asName($a);
      $save();
    }
  }
  unset($f);
  $d['offload'] = ROOT === '' && trim((string)shell_exec('uci -q get firewall.@defaults[0].flow_offloading 2>/dev/null')) === '1';
  $d['running'] = false;
  $d['finished'] = time();
  unset($d['step']);
  $save();
}
