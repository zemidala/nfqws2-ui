<?php
// Server section: packet capture of a home device (tcpdump) and the site names read from it.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ================= packet capture =================
// While «Соединения устройства» records, two tcpdump run on the interface towards the device: a full capture for
// Wireshark (.pcap to download, at most CONN_PCAP_MAX) and a small one for the names (DNS answers, the first data
// packets of the device's HTTPS and HTTP connections). A guard stops both after the recording time, so a job that died
// does not leave them running
const CONN_PCAP = '/tmp/nfqws-ui-conns.pcap';
const CONN_NAMES = '/tmp/nfqws-ui-conns-names.pcap';
// sizes in tcpdump's -C units (millions of bytes): /tmp is memory, a fast download would fill it between two checks
const CONN_PCAP_MB = 4;
const CONN_NAMES_MB = 2;

function tcpdumpBin(): ?string
{
  $p = trim((string)shell_exec('PATH=' . JOB_PATH . ' command -v tcpdump 2>/dev/null'));
  return $p !== '' ? $p : null;
}

// The interface the router reaches the device through (br-lan, br0…)
function captureIface(string $ip): ?string
{
  return preg_match('/\bdev (\S+)/', (string)shell_exec('ip -4 route get ' . escapeshellarg($ip) . ' 2>/dev/null'), $m) ? $m[1] : null;
}

function captureClear(): void
{
  @unlink(CONN_PCAP);
  @unlink(CONN_NAMES);
}

// Starts both captures; returns their pids (empty — no tcpdump or no interface)
function captureStart(string $ip, int $secs): array
{
  $bin = tcpdumpBin();
  $if = $bin ? captureIface($ip) : null;
  if (!$bin || !$if) {
    return [];
  }
  // -C … -W 1: the file never grows past its size (tcpdump starts it over), whatever comes between two checks.
  // The guard: a job that died does not stop tcpdump, and tcpdump's own -G ends only on a packet after the time — a
  // silent device gives none. It kills only a tcpdump: the pid may belong to another program by then
  $run = fn(string $file, int $snap, int $mb, string $filter) => (int)trim((string)shell_exec(escapeshellarg($bin) . ' -i ' . escapeshellarg($if)
    . " -n -U -s $snap -C $mb -W 1 -w " . escapeshellarg($file) . ' ' . escapeshellarg($filter) . ' >/dev/null 2>&1 & p=$!; '
    . '(sleep ' . ($secs + 20) . '; [ "$(cat /proc/$p/comm 2>/dev/null)" = tcpdump ] && kill $p) >/dev/null 2>&1 & echo $p'));
  // the device's own data packets only (requests, greetings): a video stream's downlink would fill the names file
  $data = '(ip[2:2] - ((ip[0] & 0xf) << 2) - ((tcp[12] & 0xf0) >> 2)) != 0';
  return array_filter(['pcap' => $run(CONN_PCAP, 0, CONN_PCAP_MB, "host $ip"),
    'names' => $run(CONN_NAMES, 1600, CONN_NAMES_MB, "host $ip and (port 53 or (src host $ip and (tcp dst port 443 or tcp dst port 80) and $data))")]);
}

// A capture near its size is stopped before tcpdump starts the file over: the start of the recording is kept.
// Returns the pids still running
function captureTrim(array $pids, array &$d): array
{
  foreach (['pcap' => [CONN_PCAP, CONN_PCAP_MB], 'names' => [CONN_NAMES, CONN_NAMES_MB]] as $k => [$file, $mb]) {
    clearstatcache(true, $file);
    if (isset($pids[$k]) && (int)@filesize($file) > $mb * 900000) {
      captureKill($pids[$k]);
      unset($pids[$k]);
      $d[$k . '_cut'] = true;
    }
  }
  return $pids;
}

function captureStop(array $pids): void
{
  foreach ($pids as $pid) {
    captureKill($pid);
  }
  if ($pids) {
    usleep(300000);   // tcpdump writes the last packets on the signal
  }
}

// kill(1): the routers' PHP has no posix extension. Only a tcpdump: called again at the job's exit, the pid may be
// another program's by then
function captureKill(int $pid): void
{
  if ($pid > 1 && trim((string)@file_get_contents("/proc/$pid/comm")) === 'tcpdump') {
    exec('kill ' . $pid . ' 2>/dev/null');
  }
}

// ----- names from the capture -----
// [address => [names]]: the names the device put in its TLS greetings (SNI) and HTTP requests (Host) first, then the
// ones it asked for in DNS; at most PCAP_NAMES_IP per address — a CDN address serves many sites. A device with its
// own DNS (DoH in the browser) still sends SNI
const PCAP_NAMES_IP = 3;

function pcapNames(string $file, string $dev): array
{
  $fh = @fopen($file, 'rb');
  if (!$fh) {
    return [];
  }
  $g = fread($fh, 24);
  $magic = strlen($g) === 24 ? bin2hex(substr($g, 0, 4)) : '';
  $le = in_array($magic, ['d4c3b2a1', '4d3cb2a1'], true);
  if (!$le && !in_array($magic, ['a1b2c3d4', 'a1b23c4d'], true)) {
    fclose($fh);
    return [];
  }
  $u32 = fn(string $s) => unpack($le ? 'V' : 'N', $s)[1];
  $link = $u32(substr($g, 20, 4)) & 0xffff;
  $dns = [];
  $sni = [];
  $bufs = [];
  $seqs = [];
  $done = [];
  $add = function (array &$map, string $ip, string $name): void {
    if (!in_array($name, $map[$ip] ?? [], true)) {
      $map[$ip][] = $name;
    }
  };
  while (($hd = fread($fh, 16)) !== false && strlen($hd) === 16) {
    $len = $u32(substr($hd, 8, 4));
    if ($len < 0 || $len > 262144) {
      break;
    }
    $p = $len ? (string)fread($fh, $len) : '';
    if (strlen($p) < $len) {
      break;
    }
    $ip = pcapIp4($p, $link);
    if ($ip === null) {
      continue;
    }
    [$proto, $src, $dst, $l4, $end] = $ip;
    if ($proto === 17 && $l4 + 8 <= $end && $dst === $dev && unpack('n', substr($p, $l4, 2))[1] === 53) {
      pcapDns(substr($p, $l4 + 8, $end - $l4 - 8), $dns);
    } elseif ($proto === 6 && $src === $dev && $l4 + 20 <= $end) {
      [, $sport, $dport] = unpack('n2', substr($p, $l4, 4)) + [0, 0, 0];
      $pl = substr($p, $l4 + (ord($p[$l4 + 12]) >> 4) * 4, max(0, $end - $l4 - (ord($p[$l4 + 12]) >> 4) * 4));
      $k = "$dst:$dport:$sport";
      if ($pl === '' || isset($done[$k])) {
        continue;
      }
      if ($dport === 80) {
        if (preg_match('/\r\nHost:\s*([A-Za-z0-9.-]+)/i', $pl, $m)) {
          $add($sni, $dst, strtolower($m[1]));
        }
        $done[$k] = true;
      } elseif ($dport === 443) {
        // a big greeting (Chrome's) spans packets, and the name may sit in a later one: collect up to the record's
        // length. A blocked connection repeats its packets — a sequence number already taken is skipped (kept as the
        // raw 4 bytes: on a 32-bit PHP the number would turn negative)
        $seq = substr($p, $l4 + 4, 4);
        if (isset($seqs[$k][$seq])) {
          continue;
        }
        $seqs[$k][$seq] = true;
        $b = ($bufs[$k] ?? '') . $pl;
        if ($b[0] !== "\x16" || strlen($b) < 5) {
          $done[$k] = true;
          unset($bufs[$k], $seqs[$k]);
          continue;
        }
        if (strlen($b) < min(5 + unpack('n', substr($b, 3, 2))[1], 16384)) {
          $bufs[$k] = $b;
          continue;
        }
        if ($name = tlsSni($b)) {
          $add($sni, $dst, $name);
        }
        $done[$k] = true;
        unset($bufs[$k], $seqs[$k]);
      }
    }
  }
  fclose($fh);
  foreach ($bufs as $k => $b) {   // the rest of a greeting did not come: what came may still hold the name
    if ($name = tlsSni($b)) {
      $add($sni, explode(':', $k)[0], $name);
    }
  }
  foreach ($dns as $ip => $names) {
    foreach ($names as $name) {
      $add($sni, $ip, $name);
    }
  }
  return array_map(fn($x) => array_slice($x, 0, PCAP_NAMES_IP), $sni);
}

// An IPv4 packet in a captured frame → [protocol, source, destination, start of its payload, end]; null — not IPv4
function pcapIp4(string $p, int $link): ?array
{
  $n = strlen($p);
  if ($link === 1) {   // Ethernet, with VLAN tags
    $o = 14;
    $et = $n >= 14 ? unpack('n', substr($p, 12, 2))[1] : 0;
    while (($et === 0x8100 || $et === 0x88a8) && $o + 4 <= $n) {
      $et = unpack('n', substr($p, $o + 2, 2))[1];
      $o += 4;
    }
  } elseif ($link === 113) {   // Linux cooked (-i any)
    $o = 16;
    $et = $n >= 16 ? unpack('n', substr($p, 14, 2))[1] : 0;
  } else {
    return null;
  }
  if ($et !== 0x0800 || $o + 20 > $n) {
    return null;
  }
  $ihl = (ord($p[$o]) & 15) * 4;
  $end = min($n, $o + unpack('n', substr($p, $o + 2, 2))[1]);
  return [ord($p[$o + 9]), inet_ntop(substr($p, $o + 12, 4)), inet_ntop(substr($p, $o + 16, 4)), $o + $ihl, $end];
}

// A DNS answer: its addresses get the name that was asked (not the CNAME at the end of the chain — the asked name is
// what the program uses and what a list or a pick needs); $map — [address => [names]]
function pcapDns(string $m, array &$map): void
{
  if (strlen($m) < 12 || !(ord($m[2]) & 0x80) || unpack('n', substr($m, 4, 2))[1] < 1) {
    return;
  }
  $labels = [];
  for ($o = 12; $o < strlen($m) && ($l = ord($m[$o])) > 0 && $l < 64; $o += $l + 1) {
    $labels[] = substr($m, $o + 1, $l);
  }
  $q = strtolower(implode('.', $labels));
  if ($q === '' || !preg_match('/^[a-z0-9._-]+$/', $q)) {
    return;
  }
  foreach (dnsWireParse($m)['ips'] ?? [] as $ip) {
    if (!in_array($q, $map[$ip] ?? [], true)) {
      $map[$ip][] = $q;
    }
  }
}

// The name in a TLS ClientHello (server_name extension); null — not a ClientHello, no name, or cut short
function tlsSni(string $b): ?string
{
  $n = strlen($b);
  $u16 = fn(int $i) => $i + 2 <= $n ? unpack('n', substr($b, $i, 2))[1] : -1;
  if ($n < 44 || ord($b[5]) !== 1) {
    return null;
  }
  $o = 9 + 2 + 32;   // record 5, handshake 4, version 2, random 32
  $o += 1 + ord($b[$o] ?? "\0");
  $o += 2 + max(0, $u16($o));
  $o += 1 + ($o < $n ? ord($b[$o]) : 0);
  $end = min($n, $o + 2 + max(0, $u16($o)));
  for ($o += 2; $o + 4 <= $end; $o += 4 + $u16($o + 2)) {
    if ($u16($o) === 0) {
      $len = $u16($o + 7);
      $name = $len > 0 ? strtolower(substr($b, $o + 9, $len)) : '';
      return preg_match('/^[a-z0-9.-]+\.[a-z0-9-]+$/', $name) ? $name : null;
    }
    if ($u16($o + 2) < 0) {
      break;
    }
  }
  return null;
}

// ----- tcpdump from the packages -----
// OpenWrt: tcpdump-mini (opkg and apk) — capturing to a file needs nothing more; Entware has only tcpdump
function tcpdumpInstall(): array
{
  set_time_limit(200);
  if (tcpdumpBin()) {
    return ['ok' => true, 'log' => 'tcpdump уже установлен'];
  }
  [$rc, $out] = runTimeout(['sh', '-c', pkgTimed(pkgApk() ? 'apk add tcpdump-mini' : opkgReinstall(ROOT === '' ? 'tcpdump-mini' : 'tcpdump'), 150)], 190);
  $log = trim(implode("\n", array_slice(explode("\n", trim($out)), -15)));
  if (tcpdumpBin()) {
    return ['ok' => true, 'log' => $log];
  }
  return ['ok' => false, 'error' => $rc === 124 ? 'источник пакетов не ответил за 2,5 минуты, tcpdump не скачался. Проверьте, что роутер достаёт до источника пакетов, и повторите.'
    : 'tcpdump не установился.', 'log' => $log, 'code' => $rc];
}
