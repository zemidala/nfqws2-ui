<?php
// Server section: the DNS check.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ================= DNS servers check =================
// Which DNS servers answer from the router and how: plain DNS (UDP 53), DoT (TLS on 853), DoH. Plain DNS may be
// intercepted on the way: whoami.akamai.net returns the address of whoever really asked, so an answer from the
// provider's network to a question sent to Google means interception. Then blocked sites: what the router's DNS and
// the provider's DNS return against DoH. A background job (NFQWS_UI_CLI=dns); progress and result in DNS_FILE.
// The router's DNS settings are never changed here — the page only shows and advises.
const DNS_FILE = '/tmp/nfqws-ui-dns.json';
const DNS_JOB_MAX = 120;   // no progress written for this long — the job is dead
// [name, IPv4, DoT/DoH host name, networks its resolvers ask from (seen in whoami); empty — not checked]
const DNS_SERVERS = [
  ['Cloudflare', '1.1.1.1', 'cloudflare-dns.com', [13335]],
  ['Google', '8.8.8.8', 'dns.google', [15169]],
  ['Quad9', '9.9.9.9', 'dns.quad9.net', []],   // asks upstream from partner networks too
  ['AdGuard DNS', '94.140.14.14', 'dns.adguard-dns.com', []],
  ['Яндекс', '77.88.8.8', 'common.dot.dns.yandex.net', [13238]],
];
// a public server that answers from the same address as the router's own DNS and this fast is the home network's
// resolver answering in its place (a router or AdGuard catching port 53); a fast answer alone proves nothing —
// Yandex and Cloudflare have nodes in Russia that answer in 2–4 ms
const DNS_FAST_MS = 5;
const DNS_SITES = ['rutracker.org', 'nnmclub.to', 'linkedin.com', 'x.com', 'instagram.com', 'facebook.com'];
const DNS_SITES_MAX = 10;   // plus monitoring sites up to this many

// One A query over UDP 53 straight from PHP (no dig/nslookup on the routers)
function dnsUdp(string $server, string $host, float $timeout = 2.0): array
{
  $id = random_bytes(2);
  $q = $id . substr(dnsWireQuery($host), 2);
  $t = microtime(true);
  $s = @stream_socket_client("udp://$server:53", $en, $es, $timeout);
  if (!$s) {
    return ['ok' => false, 'ips' => [], 'ms' => 0, 'error' => $es ?: 'нет сокета'];
  }
  stream_set_timeout($s, (int)$timeout, (int)(($timeout - (int)$timeout) * 1e6));
  fwrite($s, $q);
  $r = '';
  while (microtime(true) - $t < $timeout) {
    $r = (string)fread($s, 1500);
    if ($r === '' || substr($r, 0, 2) === $id) {
      break;   // timeout, or our answer (a stray older answer would carry another id)
    }
  }
  fclose($s);
  $ms = (int)round((microtime(true) - $t) * 1000);
  $ans = $r !== '' && substr($r, 0, 2) === $id ? dnsWireParse($r) : null;
  return $ans ? ['ok' => true, 'ips' => $ans['ips'], 'rcode' => $ans['rcode'], 'ms' => $ms] : ['ok' => false, 'ips' => [], 'ms' => $ms, 'error' => 'нет ответа'];
}

// DoT: PHP on the routers has no openssl, so only the TLS handshake on 853 is checked (curl, certificate verified).
// A provider that blocks DoT drops or resets exactly this
function dotProbe(string $name, string $ip): array
{
  $ch = curl_init("https://$name:853/");
  curl_setopt_array($ch, [CURLOPT_CONNECT_ONLY => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 5, CURLOPT_NOSIGNAL => 1,
    CURLOPT_RESOLVE => ["$name:853:$ip"]]);
  $ok = curl_exec($ch) !== false;
  $err = curl_error($ch);
  $no = curl_errno($ch);
  $ms = (int)round(curl_getinfo($ch, CURLINFO_APPCONNECT_TIME) * 1000);
  curl_close($ch);
  // why: refused — rejected at once (a firewall rule), timeout — dropped, tls — the handshake broke (or a wrong certificate)
  $why = $no === 7 ? 'refused' : ($no === 28 ? 'timeout' : 'tls');
  return $ok ? ['ok' => true, 'ms' => $ms] : ['ok' => false, 'ms' => 0, 'why' => $why, 'error' => $err];
}

// Holder of an AS ("CLOUDFLARENET"), remembered for a month
function asName(int $asn): string
{
  if ($asn <= 0) {
    return '';
  }
  $file = CACHE_DIR . '/as-name.json';
  $c = json_decode((string)@file_get_contents($file), true);
  $c = is_array($c) ? $c : [];
  if (!isset($c[$asn]) || time() - $c[$asn][1] > 30 * 86400) {
    $d = ripe('as-overview', "AS$asn", 8);
    if ($d === null) {
      return '';
    }
    // "CLOUDFLARENET - Cloudflare, Inc." → "CLOUDFLARENET"; a hyphen inside the handle (ROSTELECOM-AS) stays
    $c[$asn] = [preg_replace('/\s+-\s+.*$/', '', (string)($d['holder'] ?? '')), time()];
    @mkdir(CACHE_DIR, 0700, true);
    @file_put_contents($file, json_encode($c, JSON_UNESCAPED_UNICODE));
  }
  return $c[$asn][0];
}

// Plain DNS to a server and who really answered: the resolver's outgoing address and its network. $expect — networks of
// a public server (null for the router's and the provider's own DNS, which are not checked for interception).
// Intercepted: the answer came from the provider's network, from another network than the server's, or from the
// router's own resolver's network ($routerAsn) and very fast
function dnsPlain(string $server, ?array $expect = null, int $my = 0, ?int $routerAsn = null): array
{
  $r = dnsUdp($server, 'ya.ru');
  if (!$r['ok']) {
    return ['st' => 'none', 'ms' => $r['ms']];
  }
  $who = dnsUdp($server, 'whoami.akamai.net')['ips'][0] ?? null;
  $asn = $who ? ipAsn($who) : null;
  $out = ['st' => 'ok', 'ms' => $r['ms'], 'who' => $who, 'asn' => $asn, 'as_name' => $asn ? asName($asn) : ''];
  if ($expect !== null) {
    // same network as the router's own resolver (a big resolver asks from many addresses, so not the same address)
    $local = $asn && $asn === $routerAsn && $r['ms'] < DNS_FAST_MS && (!$expect || !in_array($asn, $expect, true));
    $why = $asn && $my && $asn === $my ? 'isp' : ($local ? 'local' : ($asn && $expect && !in_array($asn, $expect, true) ? 'other' : null));
    if ($why) {
      $out['st'] = 'intercepted';
      $out['why'] = $why;
    }
  }
  return $out;
}

// The router's resolver and the provider's DNS (OpenWrt keeps the latter in resolv.conf.auto)
function dnsLocalServers(): array
{
  $read = function (string $f): array {
    preg_match_all('/^\s*nameserver\s+(\d+\.\d+\.\d+\.\d+)/m', (string)@file_get_contents($f), $m);
    return $m[1];
  };
  $router = $read('/etc/resolv.conf')[0] ?? '127.0.0.1';
  $isp = null;
  foreach (['/tmp/resolv.conf.d/resolv.conf.auto', '/tmp/resolv.conf.auto'] as $f) {
    foreach ($read($f) as $ip) {
      if (!ipReserved($ip)) {
        $isp = $ip;
        break 2;
      }
    }
  }
  return ['router' => $router, 'isp' => $isp];
}

// What a resolver returned for a blocked site, against the DoH reference
function dnsVerdict(array $ips, array $ref): string
{
  $ips = array_values(array_filter($ips, 'isIp4'));
  if (!$ips) {
    return 'none';
  }
  if (array_filter($ips, fn($ip) => ipInEntry(inet_pton($ip), '198.18.0.0/15'))) {
    return 'podkop';   // podkop's own addresses (FakeIP) on purpose
  }
  if (array_intersect($ips, $ref)) {
    return 'ok';
  }
  if (array_filter($ips, 'ipReserved') || ipIspOwned($ips[0])) {
    return 'fake';
  }
  return $ref ? 'other' : 'unknown';   // big networks hand out different addresses to different resolvers
}

function dnsData(): array
{
  $d = json_decode((string)@file_get_contents(DNS_FILE), true);
  // dead = no progress written for DNS_JOB_MAX (the job saves after every server and site), not "started long ago":
  // with RIPEstat or DoH slow a living job may well run longer
  if (is_array($d) && !empty($d['running']) && time() - (int)@filemtime(DNS_FILE) > DNS_JOB_MAX) {
    $d['running'] = false;
    $d['died'] = true;
  }
  return is_array($d) ? $d : [];
}

// One «Проверить» on the page: the quick check (own DNS, five servers, sites), then the extended one in the same job
function dnsStart(): void
{
  if (!empty(dnsData()['running']) || !empty(dnsxData()['running'])) {
    fail('Проверка DNS уже идёт');
  }
  @unlink(DNSX_STOP);
  file_put_contents(DNS_FILE, json_encode(['running' => true, 'started' => time(), 'servers' => [], 'sites' => []]));
  file_put_contents(DNSX_FILE, json_encode(dnsxQueued(true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  exec('(env -i PATH=' . JOB_PATH . ' NFQWS_UI_CLI=dns php-cgi -q -f ' . escapeshellarg(API_FILE) . ' >/dev/null 2>&1 &)');
}

function dnsJob(): void
{
  set_time_limit(0);
  $d = ['running' => true, 'started' => time(), 'servers' => [], 'sites' => [], 'step' => 'servers'];
  $save = function () use (&$d) { file_put_contents(DNS_FILE, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); };
  $save();
  $loc = dnsLocalServers();
  $my = myAsn(true);   // once per job: when it is not saved yet, detecting goes to RIPEstat
  $d['isp_asn'] = $my ?: null;
  $d['isp_name'] = $my ? asName($my) : '';
  $router = dnsPlain($loc['router']);
  $d['servers'][] = ['name' => 'DNS роутера', 'ip' => $loc['router'], 'kind' => 'router', 'udp' => $router];
  $save();
  if ($loc['isp']) {
    $d['servers'][] = ['name' => 'DNS провайдера', 'ip' => $loc['isp'], 'kind' => 'isp', 'udp' => dnsPlain($loc['isp'])];
    $save();
  }
  foreach (DNS_SERVERS as [$name, $ip, $host, $expect]) {
    if (is_file(DNSX_STOP)) {
      break;
    }
    $doh = dohResolve($host, $ip, 'ya.ru');
    $d['servers'][] = ['name' => $name, 'ip' => $ip, 'host' => $host, 'kind' => 'public', 'udp' => dnsPlain($ip, $expect, $my, $router['asn'] ?? null),
      'dot' => dotProbe($host, $ip), 'doh' => $doh['ok'] && $doh['ips'] ? ['ok' => true, 'ms' => $doh['ms']] : ['ok' => false, 'ms' => 0, 'error' => $doh['error'] ?? 'пустой ответ']];
    $save();
  }
  $d['step'] = 'sites';
  $sites = DNS_SITES;
  foreach ((array)(uiSettings()['monitor']['sites'] ?? []) as $h) {
    if (count($sites) >= DNS_SITES_MAX) {
      break;
    }
    if (!isIp($h) && !in_array($h, $sites, true)) {
      $sites[] = $h;
    }
  }
  foreach ($sites as $host) {
    if (is_file(DNSX_STOP)) {
      break;
    }
    $ref = [];
    foreach (DIAG_DOH_MORE as [, $server, $ip]) {   // Cloudflare and Quad9: answers not filtered for Russia
      $r = dohResolve($server, $ip, $host);
      $ref = array_merge($ref, $r['ok'] ? $r['ips'] : []);
    }
    $ref = array_values(array_unique(array_filter($ref, fn($ip) => isIp4($ip) && !ipReserved($ip))));
    $sys = array_values(array_filter(gethostbynamel($host) ?: [], 'isIp4'));
    $row = ['host' => $host, 'ref' => array_slice($ref, 0, 2), 'router' => ['st' => dnsVerdict($sys, $ref), 'ips' => array_slice($sys, 0, 2)]];
    if ($loc['isp']) {
      $ips = dnsUdp($loc['isp'], $host)['ips'];
      $row['isp'] = ['st' => dnsVerdict($ips, $ref), 'ips' => array_slice($ips, 0, 2)];
    }
    $d['sites'][] = $row;
    $save();
  }
  $d['running'] = false;
  $d['finished'] = time();
  $save();
}
