<?php
// Server section: the extended DNS check.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ================= extended DNS check =================
// About fifty public DNS servers at once: the best time of plain DNS (UDP 53), DoT and DoH, how a closed one fails
// (SYN DROP — TCP did not connect, the address is closed; TLS DROP — TCP connected and the TLS handshake broke, cut by
// name; TIMEOUT — no UDP answer), who really answered on UDP 53, and how many of five blocked sites a server hands a
// stub or nothing for. A background job (NFQWS_UI_CLI=dnsx): UDP one server after another, DoT and DoH in parallel
// batches through curl_multi (a small batch on a weak router). The router's DNS settings are never changed.
const DNSX_FILE = '/tmp/nfqws-ui-dnsx.json';
const DNSX_STOP = '/tmp/nfqws-ui-dnsx.stop';
const DNSX_SITES = ['rutracker.org', 'nnmclub.to', 'linkedin.com', 'x.com', 'instagram.com'];
// [name, IPv4 for UDP 53 or '', DoT host or '', DoH URL or '']. Addresses from the providers' public pages; a server
// that went away just shows «no answer»
const DNSX = [
  ['Cloudflare', '1.1.1.1', 'cloudflare-dns.com', 'https://cloudflare-dns.com/dns-query'],
  ['Cloudflare #2', '1.0.0.1', 'cloudflare-dns.com', 'https://cloudflare-dns.com/dns-query'],
  ['Cloudflare (защита)', '1.1.1.2', 'security.cloudflare-dns.com', 'https://security.cloudflare-dns.com/dns-query'],
  ['Cloudflare (семейный)', '1.1.1.3', 'family.cloudflare-dns.com', 'https://family.cloudflare-dns.com/dns-query'],
  ['Google', '8.8.8.8', 'dns.google', 'https://dns.google/dns-query'],
  ['Google #2', '8.8.4.4', 'dns.google', ''],
  ['Quad9', '9.9.9.9', 'dns.quad9.net', 'https://dns.quad9.net/dns-query'],
  ['Quad9 #2', '149.112.112.112', 'dns.quad9.net', ''],
  ['Quad9 (без фильтра)', '9.9.9.10', 'dns10.quad9.net', 'https://dns10.quad9.net/dns-query'],
  ['Quad9 (ECS)', '9.9.9.11', 'dns11.quad9.net', 'https://dns11.quad9.net/dns-query'],
  ['AdGuard', '94.140.14.14', 'dns.adguard-dns.com', 'https://dns.adguard-dns.com/dns-query'],
  ['AdGuard #2', '94.140.15.15', 'dns.adguard-dns.com', ''],
  ['AdGuard (семейный)', '94.140.14.15', 'family.adguard-dns.com', 'https://family.adguard-dns.com/dns-query'],
  ['AdGuard (без фильтра)', '94.140.14.140', 'unfiltered.adguard-dns.com', 'https://unfiltered.adguard-dns.com/dns-query'],
  ['Яндекс', '77.88.8.8', 'common.dot.dns.yandex.net', 'https://common.dot.dns.yandex.net/dns-query'],
  ['Яндекс #2', '77.88.8.1', 'common.dot.dns.yandex.net', ''],
  ['Яндекс (безопасный)', '77.88.8.88', 'safe.dot.dns.yandex.net', 'https://safe.dot.dns.yandex.net/dns-query'],
  ['Яндекс (семейный)', '77.88.8.7', 'family.dot.dns.yandex.net', 'https://family.dot.dns.yandex.net/dns-query'],
  ['OpenDNS', '208.67.222.222', '', 'https://doh.opendns.com/dns-query'],
  ['OpenDNS #2', '208.67.220.220', '', ''],
  ['CleanBrowsing', '185.228.168.9', 'security-filter-dns.cleanbrowsing.org', 'https://doh.cleanbrowsing.org/doh/security-filter/'],
  ['CleanBrowsing (семейный)', '185.228.168.168', 'family-filter-dns.cleanbrowsing.org', 'https://doh.cleanbrowsing.org/doh/family-filter/'],
  ['Comodo', '8.26.56.26', '', ''],
  ['Comodo #2', '8.20.247.20', '', ''],
  ['Control D', '76.76.2.0', 'p0.freedns.controld.com', 'https://freedns.controld.com/p0'],
  ['Control D #2', '76.76.10.0', 'p0.freedns.controld.com', ''],
  ['Alternate DNS', '76.76.19.19', '', ''],
  ['DNS.SB', '185.222.222.222', 'dot.sb', 'https://doh.dns.sb/dns-query'],
  ['DNS.SB #2', '45.11.45.11', 'dot.sb', ''],
  ['DNS.Watch', '84.200.69.80', '', ''],
  ['DNS.Watch #2', '84.200.70.40', '', ''],
  ['Level 3', '4.2.2.1', '', ''],
  ['Level 3 #2', '4.2.2.2', '', ''],
  ['NextDNS', '45.90.28.0', 'dns.nextdns.io', 'https://dns.nextdns.io/'],
  ['NextDNS #2', '45.90.30.0', 'dns.nextdns.io', ''],
  ['Mullvad', '', 'dns.mullvad.net', 'https://dns.mullvad.net/dns-query'],
  ['UncensoredDNS', '91.239.100.100', 'anycast.uncensoreddns.org', 'https://anycast.uncensoreddns.org/dns-query'],
  ['DNS4EU', '86.54.11.100', 'unfiltered.joindns4.eu', 'https://unfiltered.joindns4.eu/dns-query'],
  ['Wikimedia DNS', '', 'wikimedia-dns.org', 'https://wikimedia-dns.org/dns-query'],
  ['LibreDNS', '', 'dot.libredns.gr', 'https://doh.libredns.gr/dns-query'],
  ['Digitale Gesellschaft', '185.95.218.42', 'dns.digitale-gesellschaft.ch', 'https://dns.digitale-gesellschaft.ch/dns-query'],
  ['dnsforge', '176.9.93.198', 'dnsforge.de', 'https://dnsforge.de/dns-query'],
  ['DNS4all', '194.0.5.3', 'dot.dns4all.eu', 'https://doh.dns4all.eu/dns-query'],
  ['Hurricane Electric', '74.82.42.42', 'ordns.he.net', 'https://ordns.he.net/dns-query'],
  ['Alibaba', '223.5.5.5', 'dns.alidns.com', 'https://dns.alidns.com/dns-query'],
  ['Alibaba #2', '223.6.6.6', 'dns.alidns.com', ''],
  ['DNSPod', '119.29.29.29', 'dot.pub', 'https://doh.pub/dns-query'],
  ['Comss.one', '83.220.169.155', 'dns.comss.one', 'https://dns.comss.one/dns-query'],
  ['Comss.one #2', '212.109.195.93', 'dns.comss.one', ''],
  ['XboxDNS', '111.88.96.50', 'xbox-dns.ru', 'https://xbox-dns.ru/dns-query'],
  ['XboxDNS #2', '111.88.96.51', 'xbox-dns.ru', ''],
  ['MSK-IX', '62.76.76.62', '', ''],
  ['MSK-IX #2', '62.76.62.76', '', ''],
  ['НСДИ', '195.208.4.1', '', ''],
  ['НСДИ #2', '195.208.5.1', '', ''],
];

function dnsxData(): array
{
  $d = json_decode((string)@file_get_contents(DNSX_FILE), true);
  if (is_array($d) && !empty($d['running']) && time() - (int)@filemtime(DNSX_FILE) > DNS_JOB_MAX) {
    $d['running'] = false;
    $d['died'] = true;
  }
  return is_array($d) ? $d : [];
}

// Every server as a row before it is checked: the page shows the whole table at once and fills it in. «wait» names the
// measurements still to come (udp — plain DNS, who answered and stubs; dot, doh — where the server has them)
function dnsxPending(): array
{
  return array_map(fn($s) => ['name' => $s[0], 'ip' => $s[1], 'dot' => null, 'doh' => null, 'udp' => null, 'sub' => null,
    'wait' => array_filter(['udp' => true, 'dot' => $s[2] !== '', 'doh' => $s[3] !== ''])], DNSX);   // udp: not reached yet
}

// A fresh state of the extended check, before the job writes anything
function dnsxQueued(bool $queued): array
{
  return ['running' => true, 'queued' => $queued, 'started' => time(), 'total' => count(DNSX), 'done' => 0, 'step' => 'udp', 'servers' => dnsxPending()];
}

function dnsxStart(): void
{
  if (!empty(dnsxData()['running'])) {
    fail('Расширенная проверка DNS уже идёт');
  }
  @unlink(DNSX_STOP);
  file_put_contents(DNSX_FILE, json_encode(dnsxQueued(false), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  exec('(env -i PATH=' . JOB_PATH . ' NFQWS_UI_CLI=dnsx php-cgi -q -f ' . escapeshellarg(API_FILE) . ' >/dev/null 2>&1 &)');
}

// One TLS probe for curl_multi: DoT — connect and handshake on 853; DoH — a real query for ya.ru
function dnsxHandle(string $kind, string $target, string $ip)
{
  if ($kind === 'dot') {
    $ch = curl_init("https://$target:853/");
    curl_setopt_array($ch, [CURLOPT_CONNECT_ONLY => true, CURLOPT_RESOLVE => ["$target:853:$ip"]]);
  } else {
    $host = (string)parse_url($target, PHP_URL_HOST);
    $sep = str_contains($target, '?') ? '&' : '?';
    $ch = curl_init($target . $sep . 'dns=' . rtrim(strtr(base64_encode(dnsWireQuery('ya.ru')), '+/', '-_'), '='));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_RESOLVE => ["$host:443:$ip"], CURLOPT_HTTPHEADER => ['accept: application/dns-message']]);
  }
  curl_setopt_array($ch, [CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 5, CURLOPT_NOSIGNAL => 1]);
  return $ch;
}

// The outcome of a finished probe: time, or how it failed (syn — no TCP connection; tls — TCP connected, TLS or the
// answer broke; bad — the server answered with something that is not a DNS answer)
function dnsxResult(string $kind, $ch, int $errno): array
{
  $connect = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
  if ($errno === 0) {
    if ($kind === 'dot') {
      return ['ok' => true, 'ms' => (int)round(curl_getinfo($ch, CURLINFO_APPCONNECT_TIME) * 1000)];
    }
    $body = curl_multi_getcontent($ch);
    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && is_string($body) && dnsWireParse($body)) {
      return ['ok' => true, 'ms' => (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000)];
    }
    return ['ok' => false, 'why' => 'bad'];
  }
  if ($connect <= 0) {
    return ['ok' => false, 'why' => 'syn'];   // TCP did not connect: the address is closed
  }
  if ($errno === 60 || $errno === 51) {
    return ['ok' => false, 'why' => 'cert'];   // the handshake went through but the certificate does not fit the name
  }
  if (curl_getinfo($ch, CURLINFO_APPCONNECT_TIME) > 0) {
    // TLS was set up and the answer then failed: slow (timeout) or broken, but not cut at the handshake
    return ['ok' => false, 'why' => $errno === 28 ? 'slow' : 'bad'];
  }
  return ['ok' => false, 'why' => 'tls'];   // TCP connected, the TLS handshake broke: cut by name
}

// Run [key => [kind, target, ip]] probes, at most $par at a time; returns key => result. $tick(key, result) is called
// as each one finishes (to save progress); when it returns false the rest are dropped (the user pressed «Остановить»)
function dnsxMulti(array $jobs, int $par, ?callable $tick = null, $mh = null): array
{
  $out = [];
  $own = $mh === null;
  $mh = $mh ?? curl_multi_init();
  $queue = $jobs;
  $live = [];
  $add = function () use (&$queue, &$live, $mh, $par) {
    while (count($live) < $par && $queue) {
      $key = array_key_first($queue);
      [$kind, $target, $ip] = $queue[$key];
      unset($queue[$key]);
      $ch = dnsxHandle($kind, $target, $ip);
      curl_multi_add_handle($mh, $ch);
      $live[(int)$ch] = [$key, $kind, $ch];
    }
  };
  $add();
  do {
    curl_multi_exec($mh, $running);
    while ($info = curl_multi_info_read($mh)) {
      [$key, $kind, $ch] = $live[(int)$info['handle']];
      $out[$key] = dnsxResult($kind, $ch, (int)$info['result']);
      curl_multi_remove_handle($mh, $ch);
      curl_close($ch);
      unset($live[(int)$info['handle']]);
      if ($tick && $tick($key, $out[$key]) === false) {
        $queue = [];   // stop: let the ones in flight finish, start no more
      }
      $add();
    }
    if ($live) {
      curl_multi_select($mh, 0.2);
    }
  } while ($live || $queue);
  if ($own) {
    curl_multi_close($mh);
  }
  return $out;
}

// DoT with a real DNS query, the way DNS scanners time it: one TLS connection, two length-prefixed queries, the second
// one timed (no handshake in it). PHP on the routers has no TLS of its own, so it goes through the openssl program;
// without it (Keenetic) — null, and the page keeps the connection time
function dotQuery(string $name, string $ip): ?array
{
  static $bin = false;
  if ($bin === false) {
    $bin = trim((string)shell_exec('command -v openssl 2>/dev/null')) ?: null;
  }
  if (!$bin) {
    return null;
  }
  $p = proc_open([$bin, 's_client', '-connect', "$ip:853", '-servername', $name, '-quiet', '-verify_return_error', '-verify_hostname', $name],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $io);
  if (!is_resource($p)) {
    return null;
  }
  stream_set_blocking($io[1], false);
  $ask = function () use ($io) {
    $q = random_bytes(2) . substr(dnsWireQuery('ya.ru'), 2);
    fwrite($io[0], pack('n', strlen($q)) . $q);
    fflush($io[0]);
    $t = microtime(true);
    $buf = '';
    while (microtime(true) - $t < 4) {
      $r = [$io[1]];
      $w = $e = null;
      if (@stream_select($r, $w, $e, 0, 200000)) {
        $chunk = fread($io[1], 4096);
        if ($chunk === '' || $chunk === false) {
          if (feof($io[1])) {
            return null;
          }
          continue;
        }
        $buf .= $chunk;
        if (strlen($buf) >= 2 && strlen($buf) >= 2 + unpack('n', $buf)[1]) {
          return dnsWireParse(substr($buf, 2, unpack('n', $buf)[1])) ? (int)round((microtime(true) - $t) * 1000) : null;
        }
      }
    }
    return null;
  };
  $first = $ask();   // with the handshake in it
  $second = $first !== null ? $ask() : null;
  fclose($io[0]);
  fclose($io[1]);
  proc_terminate($p);
  proc_close($p);
  return $first === null ? ['ok' => false] : ['ok' => true, 'ms' => $second ?? $first];
}

// Who answered UDP 53 in a server's place: the provider's own resolver, the national one (RIPN — MSK-IX/НСДИ, which
// providers put behind port 53), or the home network's resolver answering fast
function dnsxIntercept(array $udp, string $name, int $my, ?int $routerAsn): ?string
{
  $asn = $udp['asn'] ?? null;
  if (!$asn) {
    return null;
  }
  if ($my && $asn === $my) {
    return 'isp';
  }
  if (preg_match('/^RIPN/i', (string)($udp['as_name'] ?? '')) && !preg_match('/^(MSK-IX|НСДИ)/u', $name)) {
    return 'ripn';
  }
  if ($routerAsn && $asn === $routerAsn && ($udp['ms'] ?? 99) < DNS_FAST_MS) {
    return 'local';
  }
  return null;
}

function dnsxJob(): void
{
  set_time_limit(0);
  $d = dnsxQueued(false);
  unset($d['queued']);
  $save = function () use (&$d) { file_put_contents(DNSX_FILE, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); };
  $stopped = fn() => is_file(DNSX_STOP);
  $save();
  $my = myAsn(true);
  $loc = dnsLocalServers();
  $routerAsn = dnsPlain($loc['router'])['asn'] ?? null;
  // the real addresses of the blocked sites, from Cloudflare and Quad9 over DoH (not filtered for Russia)
  $ref = [];
  foreach (DNSX_SITES as $host) {
    $r = [];
    foreach (DIAG_DOH_MORE as [, $server, $ip]) {
      $x = dohResolve($server, $ip, $host);
      $r = array_merge($r, $x['ok'] ? $x['ips'] : []);
    }
    $ref[$host] = array_values(array_unique(array_filter($r, fn($ip) => isIp4($ip) && !ipReserved($ip))));
  }
  // UDP 53: best of three, who answered, and the five sites
  foreach (DNSX as $i => [$name, $ip, $dot, $doh]) {
    if ($stopped()) {
      break;
    }
    $row = ['name' => $name, 'ip' => $ip, 'dot' => null, 'doh' => null, 'udp' => null, 'sub' => null,
      'wait' => array_filter(['dot' => $dot !== '', 'doh' => $doh !== ''])];
    if ($ip !== '') {
      $best = null;
      for ($k = 0; $k < 3; $k++) {
        $r = dnsUdp($ip, 'ya.ru', 1.5);
        if (!$r['ok']) {
          break;   // one silence is enough: two more would only add three seconds per dead server
        }
        $best = $best === null ? $r['ms'] : min($best, $r['ms']);
      }
      if ($best === null) {
        $row['udp'] = ['ok' => false, 'why' => 'timeout'];
      } else {
        $who = dnsUdp($ip, 'whoami.akamai.net', 1.5)['ips'][0] ?? null;
        $asn = $who ? ipAsn($who) : null;
        $row['udp'] = ['ok' => true, 'ms' => $best, 'who' => $who, 'asn' => $asn, 'as_name' => $asn ? asName($asn) : ''];
        $row['udp']['intercept'] = dnsxIntercept($row['udp'], $name, (int)$my, $routerAsn);
        $bad = 0;
        $asked = 0;
        foreach (DNSX_SITES as $host) {
          if (!$ref[$host]) {
            continue;
          }
          $asked++;
          $v = dnsVerdict(dnsUdp($ip, $host, 1.5)['ips'], $ref[$host]);
          $bad += in_array($v, ['fake', 'none'], true) ? 1 : 0;
        }
        $row['sub'] = $asked ? ['bad' => $bad, 'of' => $asked] : null;
      }
    }
    $d['servers'][$i] = $row;
    $d['done'] = $i + 1;
    $save();
  }
  // DoT and DoH: two rounds in parallel batches, the best time of the two
  if (!$stopped()) {
    $d['step'] = 'tls';
    $save();
    $ips = [];   // host → address, found over DoH (the router's own DNS may hand out stubs)
    $jobs = [];
    foreach (DNSX as $i => [, , $dot, $doh]) {
      foreach (['dot' => $dot, 'doh' => $doh] as $kind => $t) {
        if ($t === '') {
          continue;
        }
        $host = $kind === 'dot' ? $t : (string)parse_url($t, PHP_URL_HOST);
        if (!array_key_exists($host, $ips)) {
          $x = dohResolve('cloudflare-dns.com', '1.1.1.1', $host);
          $ips[$host] = array_values(array_filter($x['ips'] ?? [], 'isIp4'))[0] ?? (gethostbyname($host) !== $host ? gethostbyname($host) : null);
        }
        // first the server's own address (routers set DoT/DoH by address + name, and the name's address may be another
        // one the provider treats differently); if that fails, the name's address in the second round
        $own = DNSX[$i][1];
        if ($own === '' && $ips[$host] === null) {
          $d['servers'][$i][$kind] = ['ok' => false, 'why' => 'name'];
          unset($d['servers'][$i]['wait'][$kind]);
          continue;
        }
        $jobs["$i:$kind"] = [$kind, $t, $own !== '' ? $own : $ips[$host], $ips[$host]];
      }
    }
    // a few at a time: many TLS handshakes at once load the router's CPU and inflate every time.
    // One multi handle for both rounds keeps the connections: the second DoH query goes over the open one, so its time
    // is the query itself, without the handshake — the way DNS scanners measure
    $par = lowMem() ? 3 : 6;
    $mh = curl_multi_init();
    curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, 120);
    for ($round = 0; $round < 2 && !$stopped(); $round++) {
      $batch = [];
      foreach ($jobs as $key => [$kind, $t, $ip, $alt]) {
        [$i] = explode(':', $key);
        $prev = $d['servers'][$i][$kind] ?? null;
        $batch[$key] = [$kind, $t, $round && $prev && !$prev['ok'] && $alt ? $alt : $ip];
      }
      // each result goes into the file as it comes (at most every 2 s): a slow round on a weak router must not look dead
      $saved = 0;
      dnsxMulti($batch, $par, function ($key, $r) use (&$d, &$saved, $save, $stopped) {
        [$i, $kind] = explode(':', $key);
        $prev = $d['servers'][$i][$kind] ?? null;
        $d['servers'][$i][$kind] = !$prev || !$prev['ok'] ? ($r['ok'] || !$prev ? $r : $prev) : ($r['ok'] ? ['ok' => true, 'ms' => min($prev['ms'], $r['ms'])] : $prev);
        unset($d['servers'][$i]['wait'][$kind]);   // the first answer is shown; the second round may only make it faster
        if (microtime(true) - $saved > 2) {
          $save();
          $saved = microtime(true);
        }
        return !$stopped();
      }, $mh);
      $save();
    }
    curl_multi_close($mh);
    // DoT as a real query (openssl); where the handshake failed there is nothing to ask
    foreach ($d['servers'] as $i => $row) {
      if ($stopped() || empty($row['dot']['ok'])) {
        continue;
      }
      $q = dotQuery(DNSX[$i][2], DNSX[$i][1] !== '' ? DNSX[$i][1] : ($ips[DNSX[$i][2]] ?? ''));
      if ($q === null) {
        break;   // no openssl here: the handshake time stays for all
      }
      $d['servers'][$i]['dot'] = $q['ok'] ? ['ok' => true, 'ms' => $q['ms'], 'q' => true] : ['ok' => false, 'why' => 'bad'];
      $save();
    }
  }
  // stopped: servers never asked go away (as before), unfinished measurements stay empty
  $d['servers'] = array_values(array_filter(array_map(function ($row) {
    if (!empty($row['wait']['udp'])) {
      return null;
    }
    unset($row['wait']);
    return $row;
  }, $d['servers'])));
  $d['running'] = false;
  $d['stopped'] = $stopped();
  $d['finished'] = time();
  @unlink(DNSX_STOP);
  $save();
}
