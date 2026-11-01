<?php
// Server section: bypass only for chosen devices.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ---------- devices: bypass only for chosen devices of the home network (OpenWrt) ----------
// A device is known by its MAC. Our own chain in mangle PREROUTING (first in it, before nfqws_pre) gives a NEW
// connection from the LAN of a device without bypass our connmark bit, and every packet of such a connection — out
// from the LAN and replies in from the WAN — the packet mark nfqws2 itself treats as «already processed»: all its
// NFQUEUE rules carry «-m mark ! --mark MARK_PROCESSED», nfqws_pre returns on it. The packet mark set in PREROUTING
// stays on a forwarded packet up to POSTROUTING. Nothing is put into nfqws2's own chains, so its restarts and
// firewall rebuilds (which flush them) do not undo the choice. The router's own traffic never enters PREROUTING
// from the LAN, so its checks always go through nfqws2.
// Keenetic has the same in firmware (access policy, POLICY_NAME) — nothing is set up there.
const DEV_CHAIN = 'nfqws_ui_dev';
const DEV_MARK = '0x800000';
const DEV_SIG = '/tmp/nfqws-ui-dev.sig';
const DEV_TRY = '/tmp/nfqws-ui-dev.try';   // last failed attempt: do not hammer iptables on every state() poll
const DEV_MAX = 256;

function devicesConf(): array
{
  $d = (array)(uiSettings()['devices'] ?? []);
  return ['new_on' => ($d['new_on'] ?? true) !== false, 'list' => is_array($d['list'] ?? null) ? $d['list'] : []];
}

function devicesActive(array $d): bool
{
  return !$d['new_on'] || array_filter($d['list'], fn($x) => empty($x['on']));
}

// Known devices: DHCP leases, static hosts, neighbours that are online now, and the saved list
function devicesList(): array
{
  $d = devicesConf();
  $by = [];
  $put = function (string $mac, array $v) use (&$by) {
    $mac = strtolower($mac);
    if (preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) {
      $by[$mac] = array_filter($v, fn($x) => $x !== '' && $x !== null) + ($by[$mac] ?? []) + ['mac' => $mac];
    }
  };
  foreach (@file('/tmp/dhcp.leases', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    $f = preg_split('/\s+/', trim($l));
    if (count($f) >= 4) {
      $put($f[1], ['ip' => $f[2], 'name' => $f[3] === '*' ? '' : $f[3]]);
    }
  }
  exec('uci -q show dhcp 2>/dev/null', $uci);
  $host = [];
  foreach ($uci as $l) {
    if (preg_match("/^dhcp\.(@host\[\d+\]|\w+)\.(mac|name|ip)='([^']*)'/", $l, $m)) {
      $host[$m[1]][$m[2]] = $m[3];
    }
  }
  foreach ($host as $h) {
    foreach (preg_split('/\s+/', trim($h['mac'] ?? '')) as $mac) {
      $put($mac, ['name' => $h['name'] ?? '', 'ip' => $h['ip'] ?? '']);
    }
  }
  exec('ip neigh show 2>/dev/null', $nb);
  $online = [];
  foreach ($nb as $l) {
    // «IP dev IF lladdr MAC [router] [ref N] [used …] [probes N] STATE»; STALE — was here a while ago, still listed
    if (preg_match('/^(\S+) .*\blladdr (\S+)\b.* (\w+)$/', trim($l), $m) && $m[3] !== 'FAILED') {
      if (in_array($m[3], ['REACHABLE', 'DELAY', 'PROBE'], true)) {
        $online[strtolower($m[2])] = true;
      }
      $put($m[2], str_contains($m[1], ':') ? [] : ['ip' => $m[1]]);
    }
  }
  foreach ($d['list'] as $mac => $x) {
    $put($mac, ['name' => $x['name'] ?? '']);
  }
  $items = [];
  foreach ($by as $mac => $v) {
    $saved = $d['list'][$mac] ?? null;
    $items[] = ['mac' => $mac, 'name' => $v['name'] ?? '', 'ip' => $v['ip'] ?? '', 'online' => isset($online[$mac]),
      'on' => $saved ? !empty($saved['on']) : $d['new_on'], 'saved' => (bool)$saved, 'manual' => !empty($saved['manual'])];
  }
  usort($items, fn($a, $b) => [!$a['online'], strtolower($a['name'] ?: '~' . $a['mac'])] <=> [!$b['online'], strtolower($b['name'] ?: '~' . $b['mac'])]);
  return $items;
}

// nfqws2's «already processed» packet mark (MARK_PROCESSED in its init script)
function devicesProcessedMark(): string
{
  return preg_match('/^MARK_PROCESSED="?(0x[0-9a-f]+)\//mi', (string)@file_get_contents(INIT_SCRIPT), $m) ? $m[1] : '0x40000000';
}

function devicesCmds(): array
{
  $vars = confRawVars((string)@file_get_contents(CONF_FILE));
  $cmds = ['iptables'];
  if (($vars['IPV6_ENABLED'] ?? '0') === '1' && trim((string)shell_exec('command -v ip6tables 2>/dev/null')) !== '') {
    $cmds[] = 'ip6tables';
  }
  return [$cmds, tokens($vars['ISP_INTERFACE'] ?? '')];
}

function devicesRemove(): void
{
  foreach (['iptables', 'ip6tables'] as $c) {
    $ipt = "$c -w -t mangle ";
    // the rules of the first version lived in nfqws2's chains: drop them too
    foreach (['nfqws_post', 'nfqws_pre'] as $ch) {
      for ($i = 0; $i < 5 && exec("$ipt-D $ch -m connmark --mark " . DEV_MARK . '/' . DEV_MARK . ' -j RETURN 2>/dev/null', $o, $rc) !== false && $rc === 0; $i++);
    }
    exec($ipt . '-S ' . DEV_CHAIN . ' 2>/dev/null', $o, $rc);
    if ($rc === 0) {
      for ($i = 0; $i < 5 && exec("$ipt-D PREROUTING -j " . DEV_CHAIN . ' 2>/dev/null', $o, $rc2) !== false && $rc2 === 0; $i++);
      exec($ipt . '-F ' . DEV_CHAIN . " 2>/dev/null; $ipt-X " . DEV_CHAIN . ' 2>/dev/null');
    }
  }
  @unlink(DEV_SIG);
  @unlink(DEV_TRY);
  mangleRules(true);
}

function devicesRules(array $d, array $wan): array
{
  $m = DEV_MARK . '/' . DEV_MARK;
  $p = devicesProcessedMark();
  $mark = "-m connmark --mark $m -j MARK --set-xmark $p/$p";
  $r = [$mark];   // packets of connections chosen before, both directions
  foreach ($wan as $i) {
    $r[] = '-i ' . escapeshellarg($i) . ' -j RETURN';   // only connections started from the home network are chosen
  }
  $r[] = '-m conntrack ! --ctstate NEW -j RETURN';
  foreach ($d['list'] as $mac => $x) {
    if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac) || !empty($x['on']) === $d['new_on']) {
      continue;   // new_on: mark devices switched off; otherwise let devices switched on through and mark the rest
    }
    $r[] = "-m mac --mac-source $mac -j " . ($d['new_on'] ? "CONNMARK --set-xmark $m" : 'RETURN');
  }
  if (!$d['new_on']) {
    $r[] = "-j CONNMARK --set-xmark $m";
  }
  $r[] = $mark;   // the first packet of a connection just chosen
  return $r;
}

function devicesApply(): bool
{
  $d = devicesConf();
  if (ROOT !== '' || !devicesActive($d)) {
    devicesRemove();
    return true;
  }
  [$cmds, $wan] = devicesCmds();
  $rules = devicesRules($d, $wan);
  $ok = true;
  foreach ($cmds as $c) {
    $ipt = "$c -w -t mangle ";
    exec($ipt . '-N ' . DEV_CHAIN . ' 2>/dev/null');
    exec($ipt . '-F ' . DEV_CHAIN . ' 2>/dev/null');
    foreach ($rules as $r) {
      exec($ipt . '-A ' . DEV_CHAIN . " $r 2>/dev/null", $o, $rc);
      $ok = $ok && $rc === 0;
    }
    exec($ipt . '-C PREROUTING -j ' . DEV_CHAIN . ' 2>/dev/null', $o, $rc);
    if ($rc !== 0) {
      exec($ipt . '-I PREROUTING 1 -j ' . DEV_CHAIN . ' 2>/dev/null', $o, $rc);
      $ok = $ok && $rc === 0;
    }
    // the first version hooked into nfqws2's chains — not needed any more
    foreach (['nfqws_post', 'nfqws_pre'] as $ch) {
      exec("$ipt-D $ch -m connmark --mark " . DEV_MARK . '/' . DEV_MARK . ' -j RETURN 2>/dev/null');
    }
  }
  @file_put_contents(DEV_SIG, devicesSig());
  $ok ? @unlink(DEV_TRY) : @touch(DEV_TRY);
  mangleRules(true);
  return $ok;
}

function devicesSig(): string
{
  return md5(json_encode([devicesConf(), devicesCmds(), devicesProcessedMark()]));
}

// Are our chain and its PREROUTING jump in place, for IPv4 and (when on) IPv6?
function devicesPlaced(array $cmds, bool $fresh): bool
{
  foreach ($cmds as $c) {
    $all = $c === 'iptables' ? mangleRules($fresh) : (function () { exec('ip6tables -w -t mangle -S 2>/dev/null', $o); return $o; })();
    if (!preg_grep('/^-A PREROUTING( .*)? -j ' . DEV_CHAIN . '\b/', $all) || !preg_grep('/^-A ' . DEV_CHAIN . ' /', $all)) {
      return false;
    }
  }
  return true;
}

// Rules in place? Restores them after a reboot, an outside flush or a change of the choice.
// Returns null when bypass is for the whole network (nothing to keep).
function devicesEnsure(bool $fresh = false): ?bool
{
  static $memo = 'none';
  if ($memo !== 'none' && !$fresh) {
    return $memo;
  }
  $d = devicesConf();
  if (ROOT !== '' || !devicesActive($d)) {
    if (is_file(DEV_SIG)) {
      devicesRemove();
    }
    return $memo = null;
  }
  [$cmds] = devicesCmds();
  if (devicesPlaced($cmds, $fresh) && @file_get_contents(DEV_SIG) === devicesSig()) {
    return $memo = true;
  }
  // a failed attempt less than a minute ago: report, do not retry on every poll
  if (!$fresh && @filemtime(DEV_TRY) > time() - 60) {
    return $memo = false;
  }
  devicesApply();
  return $memo = devicesPlaced($cmds, true);
}

function volumeFrozen(string $url, array $resolve = []): bool
{
  $ch = curl_init($url . (str_contains($url, '?') ? '&' : '?') . 't=' . mt_rand());
  curl_setopt_array($ch, [
    CURLOPT_RESOLVE => $resolve,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => random_bytes(VOLUME_BYTES),   // случайные данные — чтобы не сжимались
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    CURLOPT_HTTPHEADER => ['Expect:', 'Content-Type: application/octet-stream'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_NOSIGNAL => 1,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130 Safari/537.36',
  ] + policyProbeOpts());
  curl_exec($ch);
  // рукопожатие прошло (APPCONNECT), а ответа на 64 КБ нет. Если TLS не установился, CONNECT_TIME тоже 0 —
  // по нему «TCP или TLS» не различить
  $frozen = curl_errno($ch) === 28 && curl_getinfo($ch, CURLINFO_APPCONNECT_TIME) > 0;
  curl_close($ch);
  return $frozen;
}

// Каким профилем пойдёт сайт по HTTPS, QUIC и HTTP при этих адресах
function checkRoutes(array $profiles, string $host, array $ips, ListCache $cache): array
{
  return [
    'https' => matchRoute($profiles, $host, $ips, 'tcp', 443, 'tls', $cache),
    'quic' => matchRoute($profiles, $host, $ips, 'udp', 443, 'quic', $cache),
    'http' => matchRoute($profiles, $host, $ips, 'tcp', 80, 'http', $cache),
  ];
}
