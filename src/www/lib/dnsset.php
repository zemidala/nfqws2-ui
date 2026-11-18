<?php
defined('API_FILE') || exit;

define('DNSSET_FILE', UI_CONF_DIR . '/dnsset.json');
const DNSSET_LOCK = '/tmp/nfqws-ui-dnsset.lock';
const DNSSET_WAIT = 180;
const DNSSET_PORT = 5053;
const DNSSET_STALE = 660;

function dnsSetNdmc(string $cmd): array
{
  $o = [];
  exec('LD_LIBRARY_PATH=/lib:/usr/lib /bin/ndmc -c ' . escapeshellarg($cmd) . ' 2>&1', $o, $rc);
  $text = trim(preg_replace('/\e\[[0-9;]*[A-Za-z]|\[K/', '', implode("\n", $o)));
  return ['ok' => $rc === 0 && !preg_match('/error\[\d+\]/i', $text), 'text' => $text, 'lines' => explode("\n", $text)];
}

function dnsSetLocked(callable $fn)
{
  $l = @fopen(DNSSET_LOCK, 'c');
  if ($l) {
    flock($l, LOCK_EX);
  }
  try {
    return $fn();
  } finally {
    if ($l) {
      flock($l, LOCK_UN);
      fclose($l);
    }
  }
}

function dnsSetDnsmasqServers(): array
{
  $s = [];
  exec('uci -q get dhcp.@dnsmasq[0].server 2>/dev/null', $s);
  return array_values(array_filter(preg_split('/\s+/', trim(implode(' ', $s))), 'strlen'));
}

function dnsSetKind(): array
{
  if (is_file('/bin/ndmc')) {
    return ['kind' => 'keenetic'];
  }
  if (!is_file('/etc/openwrt_release')) {
    return ['kind' => null, 'why' => 'other'];
  }
  if (glob('/etc/rc.d/S*podkop')) {
    return ['kind' => null, 'why' => 'podkop'];
  }
  if (glob('/etc/rc.d/S*[Aa]d[Gg]uard[Hh]ome') || glob('/etc/rc.d/S*adguardhome')) {
    return ['kind' => null, 'why' => 'adguard'];
  }
  foreach (dnsSetDnsmasqServers() as $v) {
    if ($v[0] !== '/' && !preg_match('/^127\.0\.0\.1#505\d$/', $v)) {
      return ['kind' => null, 'why' => 'dnsmasq'];
    }
  }
  return ['kind' => 'openwrt'];
}

function dnsSetKeeneticLines(): array
{
  $in = false;
  $lines = [];
  foreach (dnsSetNdmc('show running-config')['lines'] as $l) {
    $l = rtrim($l);
    if ($l === 'dns-proxy') {
      $in = true;
      continue;
    }
    if ($in && ($l === '!' || ($l !== '' && $l[0] !== ' '))) {
      break;
    }
    if ($in && preg_match('/^\s+((tls|https) upstream \S.*)$/', $l, $m)) {
      $lines[] = trim($m[1]);
    }
  }
  return $lines;
}

function dnsSetLineInfo(string $line): ?array
{
  if (preg_match('/^tls upstream (\d+\.\d+\.\d+\.\d+)(?: (\d+))?(?: sni (\S+))?(?: domain (\S+))?/', $line, $m)) {
    return ['proto' => 'DoT', 'ip' => $m[1], 'port' => $m[2] ?? '', 'host' => $m[3] ?? '', 'domain' => $m[4] ?? '',
      'del' => 'no dns-proxy tls upstream ' . $m[1] . (!empty($m[2]) ? ' ' . $m[2] : '')];
  }
  if (preg_match('#^https upstream (https://([^/:\s]+)\S*)(?: \S+)?(?: domain (\S+))?#', $line, $m)) {
    return ['proto' => 'DoH', 'url' => $m[1], 'host' => strtolower($m[2]), 'ip' => isIp4($m[2]) ? $m[2] : '', 'domain' => $m[3] ?? '',
      'del' => 'no dns-proxy https upstream ' . $m[1]];
  }
  return null;
}

function dnsSetInfo(bool $jobOnly = false): array
{
  $p = dnsSetData();
  if ($jobOnly) {
    return ['job' => $p ? dnsSetView($p) : null];
  }
  $k = dnsSetKind();
  $r = $k + ['current' => [], 'installed' => null];
  if ($k['kind'] === 'keenetic') {
    foreach (dnsSetKeeneticLines() as $line) {
      if ($i = dnsSetLineInfo($line)) {
        $r['current'][] = ['line' => $line] + array_diff_key($i, ['del' => 1]);
      }
    }
  } elseif ($k['kind'] === 'openwrt') {
    $r['installed'] = is_file('/etc/init.d/https-dns-proxy');
    $o = [];
    exec('uci -q show https-dns-proxy 2>/dev/null', $o);
    foreach ($o as $l) {
      if (preg_match("#resolver_url='(https://([^/:']+)[^']*)'#", $l, $m)) {
        $r['current'][] = ['line' => $m[1], 'proto' => 'DoH', 'url' => $m[1], 'host' => strtolower($m[2]), 'ip' => isIp4($m[2]) ? $m[2] : '', 'domain' => ''];
      }
    }
  }
  if ($p) {
    $r['job'] = dnsSetView($p);
  }
  return $r;
}

function dnsSetData(): ?array
{
  $d = json_decode((string)@file_get_contents(DNSSET_FILE), true);
  return is_array($d) ? $d : null;
}

function dnsSetSave(array $d): void
{
  @mkdir(dirname(DNSSET_FILE), 0755, true);
  $tmp = DNSSET_FILE . '.tmp' . getmypid();
  file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  rename($tmp, DNSSET_FILE);
}

function dnsSetView(?array $d = null): ?array
{
  $d ??= dnsSetData();
  if (!$d) {
    return null;
  }
  return array_intersect_key($d, array_flip(['state', 'step', 'name', 'kind', 'deadline', 'error', 'finished']));
}

function dnsSetTarget(string $name): ?array
{
  foreach (dnsxList() as [$n, $ip, $dot, $doh]) {
    if ($n === $name && isIp4($ip) && ($dot !== '' || $doh !== '')) {
      return ['name' => $n, 'ip' => $ip, 'dot' => $dot, 'doh' => $doh];
    }
  }
  return null;
}

function dnsSetCli(string $mode, int $delay = 0): void
{
  exec('(' . ($delay ? "sleep $delay; " : '') . 'env -i PATH=' . JOB_PATH . ' NFQWS_UI_CLI=' . $mode . ' php-cgi -q -f ' . escapeshellarg(API_FILE) . ' >/dev/null 2>&1 &)');
}

function dnsSetStart(string $name, array $remove): void
{
  $t = dnsSetTarget($name);
  if (!$t) {
    fail('Этого сервера нет в списке проверенных');
  }
  $k = dnsSetKind();
  if (!$k['kind']) {
    fail('DNS этого роутера отсюда не поставить');
  }
  if ($k['kind'] === 'openwrt' && $t['doh'] === '') {
    fail('У этого сервера нет DoH, а на OpenWrt ставится только DoH');
  }
  $lines = $k['kind'] === 'keenetic' ? dnsSetKeeneticLines() : [];
  $remove = array_values(array_intersect(array_map('strval', $remove), $lines));
  $busy = dnsSetLocked(function () use ($name, $k, $t, $remove) {
    $cur = dnsSetData();
    if (dnsSetStale($cur)) {
      dnsSetRecover($cur);
      $cur = dnsSetData();
    }
    if ($cur && in_array($cur['state'] ?? '', ['working', 'pending'], true)) {
      return true;
    }
    dnsSetSave(['state' => 'working', 'step' => 'запуск', 'name' => $name, 'kind' => $k['kind'], 'target' => $t, 'remove' => $remove, 'started' => time()]);
    return false;
  });
  if ($busy) {
    fail('DNS роутера уже меняется — дождитесь окончания или подтвердите прошлое изменение');
  }
  dnsSetCli('dnsset');
}

function dnsSetAsk(int $port): bool
{
  for ($i = 0; $i < 6; $i++) {
    $r = dnsUdp('127.0.0.1', bin2hex(random_bytes(5)) . '.google.com', 3.0, $port);
    if ($r['ok']) {
      return true;
    }
    sleep(2);
  }
  return false;
}

function dnsSetKeeneticPorts(array $t): array
{
  $ports = [];
  foreach (dnsSetNdmc('show dns-proxy')['lines'] as $l) {
    if (!preg_match('/^\s*dns_server\s*=\s*127\.0\.0\.1:(\d+)\s+\S+\s*#\s*(\S+)/', $l, $m)) {
      continue;
    }
    if (preg_match('/^' . preg_quote($t['ip'], '/') . '(:\d+)?$/', $m[2]) || ($t['doh'] !== '' && str_starts_with($m[2], $t['doh']))) {
      $ports[] = (int)$m[1];
    }
  }
  return array_values(array_unique($ports));
}

function dnsSetResolves(array $d): bool
{
  $t = $d['target'];
  if ($d['kind'] === 'keenetic') {
    $ports = [];
    for ($i = 0; $i < 5 && !$ports; $i++) {
      $ports = dnsSetKeeneticPorts($t);
      $ports || sleep(2);
    }
    foreach ($ports as $p) {
      if (dnsSetAsk($p)) {
        return true;
      }
    }
    return false;
  }
  if (!dnsSetAsk(DNSSET_PORT)) {
    return false;
  }
  $r = dnsUdp(dnsLocalServers()['router'], bin2hex(random_bytes(5)) . '.google.com', 3.0);
  return $r['ok'];
}

function dnsSetJob(): void
{
  set_time_limit(600);
  $d = dnsSetData();
  if (!$d || ($d['state'] ?? '') !== 'working') {
    return;
  }
  $step = function (string $s) use (&$d) { $d['step'] = $s; dnsSetSave($d); };
  $t = $d['target'];
  $undo = [];
  $ok = true;
  $err = '';
  $installed = true;
  if ($d['kind'] === 'keenetic') {
    $before = dnsSetKeeneticLines();
    $d['before'] = $before;
    $step('меняю серверы Keenetic');
    $cmds = [];
    $have = array_filter(array_map('dnsSetLineInfo', $before));
    if ($t['dot'] !== '' && !array_filter($have, fn($i) => $i['proto'] === 'DoT' && $i['ip'] === $t['ip'] && $i['domain'] === '')) {
      $cmds[] = 'dns-proxy tls upstream ' . $t['ip'] . ' 853 sni ' . $t['dot'];
    }
    if ($t['doh'] !== '' && !array_filter($have, fn($i) => $i['proto'] === 'DoH' && $i['url'] === $t['doh'] && $i['domain'] === '')) {
      $cmds[] = 'dns-proxy https upstream ' . $t['doh'] . ' dnsm';
    }
    foreach ($d['remove'] as $line) {
      if ($i = dnsSetLineInfo($line)) {
        $cmds[] = $i['del'];
      }
    }
    foreach ($cmds as $c) {
      $r = dnsSetNdmc($c);
      if (!$r['ok']) {
        [$ok, $err] = [false, 'Keenetic не принял команду «' . $c . '»: ' . $r['text']];
        break;
      }
    }
    $undo = dnsSetKeeneticUndo($before);
  } else {
    $installed = is_file('/etc/init.d/https-dns-proxy');
    $o = [];
    exec('uci -q export https-dns-proxy 2>/dev/null', $o);
    $backup = implode("\n", $o);
    $o = [];
    exec('uci -q export dhcp 2>/dev/null', $o);
    $dhcp = implode("\n", $o);
    $enabled = (bool)glob('/etc/rc.d/S*https-dns-proxy');
    $undo[] = ['uci', 'dhcp', $dhcp];
    if (!$installed || !$enabled) {
      $undo[] = ['sh', '/etc/init.d/https-dns-proxy stop; /etc/init.d/https-dns-proxy disable'];
    }
    if ($installed) {
      $undo[] = ['uci', 'https-dns-proxy', $backup];
      if ($enabled) {
        $undo[] = ['sh', '/etc/init.d/https-dns-proxy restart'];
      }
    }
    $undo[] = ['sh', '/etc/init.d/dnsmasq restart'];
    $d['undo'] = $undo;
    $d['pkg_new'] = !$installed;
    if (!$installed) {
      $step('ставлю пакет https-dns-proxy');
      $pm = is_file('/usr/bin/apk') || is_file('/sbin/apk') ? 'apk update >/dev/null 2>&1; apk add https-dns-proxy' : 'opkg update >/dev/null 2>&1; opkg install https-dns-proxy';
      $out = [];
      exec($pm . ' 2>&1', $out, $rc);
      if (!is_file('/etc/init.d/https-dns-proxy')) {
        [$ok, $err] = [false, 'Пакет https-dns-proxy не установился: ' . trim(implode(' ', array_slice($out, -3)))];
      }
    }
    if ($ok) {
      $step('настраиваю https-dns-proxy');
      $keep = array_filter(dnsSetDnsmasqServers(), fn($v) => $v[0] === '/' && !preg_match('/^\/[^\/]+\/$/', $v));
      $sh = 'while uci -q delete https-dns-proxy.@https-dns-proxy[0]; do :; done; '
        . 'uci add https-dns-proxy https-dns-proxy >/dev/null; '
        . 'uci set https-dns-proxy.@https-dns-proxy[-1].resolver_url=' . escapeshellarg($t['doh']) . '; '
        . 'uci set https-dns-proxy.@https-dns-proxy[-1].bootstrap_dns=' . escapeshellarg($t['ip']) . '; '
        . 'uci set https-dns-proxy.@https-dns-proxy[-1].listen_addr=127.0.0.1; '
        . 'uci set https-dns-proxy.@https-dns-proxy[-1].listen_port=' . DNSSET_PORT . '; '
        . "uci -q get https-dns-proxy.config >/dev/null || uci set https-dns-proxy.config=main; uci set https-dns-proxy.config.dnsmasq_config_update='-'; "
        . 'uci commit https-dns-proxy; /etc/init.d/https-dns-proxy enable; /etc/init.d/https-dns-proxy restart; '
        . 'uci -q delete dhcp.@dnsmasq[0].server; ';
      foreach ([...$keep, '127.0.0.1#' . DNSSET_PORT] as $v) {
        $sh .= 'uci add_list dhcp.@dnsmasq[0].server=' . escapeshellarg($v) . '; ';
      }
      $sh .= "uci set dhcp.@dnsmasq[0].noresolv='1'; uci commit dhcp; /etc/init.d/dnsmasq restart";
      exec($sh . ' >/dev/null 2>&1');
      sleep(3);
    }
  }
  if ($ok) {
    $step('проверяю, что новый сервер отвечает');
    if (!dnsSetResolves($d)) {
      [$ok, $err] = [false, 'После изменения сервер не ответил — всё возвращено как было.'];
    }
  }
  if (!$ok) {
    dnsSetUndo($undo);
    dnsSetSave(['state' => 'failed', 'name' => $d['name'], 'kind' => $d['kind'], 'error' => $err . dnsSetPkgNote(['pkg_new' => !$installed]), 'finished' => time()]);
    return;
  }
  dnsSetSave(['state' => 'pending', 'name' => $d['name'], 'kind' => $d['kind'], 'undo' => $undo, 'deadline' => time() + DNSSET_WAIT, 'started' => $d['started'],
    'pkg_new' => !$installed]);
  dnsSetCli('dnsset_expire', DNSSET_WAIT + 2);
}

function dnsSetKeeneticUndo(array $before): array
{
  $undo = [];
  $after = dnsSetKeeneticLines();
  foreach (array_diff($after, $before) as $line) {
    if ($i = dnsSetLineInfo($line)) {
      $undo[] = ['ndmc', $i['del']];
    }
  }
  foreach (array_diff($before, $after) as $line) {
    $undo[] = ['ndmc', 'dns-proxy ' . $line];
  }
  return $undo;
}

function dnsSetStale(?array $d): bool
{
  return $d && ($d['state'] ?? '') === 'working' && time() - (int)($d['started'] ?? 0) > DNSSET_STALE;
}

function dnsSetRecover(array $d): void
{
  dnsSetUndo(isset($d['before']) ? dnsSetKeeneticUndo($d['before']) : ($d['undo'] ?? []));
  dnsSetSave(['state' => 'failed', 'name' => $d['name'] ?? '', 'kind' => $d['kind'] ?? '', 'error' => 'Изменение прервалось на середине (перезагрузка или нехватка памяти) — DNS роутера возвращён как был.' . dnsSetPkgNote($d), 'finished' => time()]);
}

function dnsSetUndo(array $undo): void
{
  foreach ($undo as $u) {
    if ($u[0] === 'ndmc') {
      dnsSetNdmc($u[1]);
    } elseif ($u[0] === 'uci' && in_array($u[1], ['dhcp', 'https-dns-proxy'], true)) {
      $f = '/tmp/nfqws-ui-dnsset.uci';
      file_put_contents($f, $u[2] . "\n");
      exec('uci -q import ' . $u[1] . ' < ' . escapeshellarg($f) . ' 2>/dev/null; uci commit ' . $u[1]);
      @unlink($f);
    } elseif ($u[0] === 'sh') {
      exec($u[1] . ' >/dev/null 2>&1');
    }
  }
}

function dnsSetExpire(): void
{
  $d = dnsSetData();
  if (!dnsSetStale($d) && (!$d || ($d['state'] ?? '') !== 'pending' || time() < (int)$d['deadline'])) {
    return;
  }
  dnsSetLocked(function () {
    $d = dnsSetData();
    if (dnsSetStale($d)) {
      dnsSetRecover($d);
    } elseif ($d && ($d['state'] ?? '') === 'pending' && time() >= (int)$d['deadline']) {
      dnsSetUndo($d['undo'] ?? []);
      dnsSetSave(['state' => 'rolled', 'name' => $d['name'], 'kind' => $d['kind'], 'error' => 'Изменение не подтвердили за 3 минуты — DNS роутера возвращён как был.' . dnsSetPkgNote($d), 'finished' => time()]);
    }
  });
}

function dnsSetConfirm(): void
{
  $err = dnsSetLocked(function () {
    $d = dnsSetData();
    if (!$d || ($d['state'] ?? '') !== 'pending' || time() >= (int)$d['deadline']) {
      return 'Нечего подтверждать: изменение уже откачено или подтверждено';
    }
    if ($d['kind'] === 'keenetic') {
      $r = dnsSetNdmc('system configuration save');
      if (!$r['ok']) {
        return 'Keenetic не сохранил настройки: ' . $r['text'];
      }
    }
    dnsSetSave(['state' => 'done', 'name' => $d['name'], 'kind' => $d['kind'], 'finished' => time()]);
    return null;
  });
  if ($err) {
    fail($err);
  }
}

function dnsSetRollback(): void
{
  $err = dnsSetLocked(function () {
    $d = dnsSetData();
    if (!$d || ($d['state'] ?? '') !== 'pending') {
      return 'Нечего откатывать';
    }
    dnsSetUndo($d['undo'] ?? []);
    dnsSetSave(['state' => 'rolled', 'name' => $d['name'], 'kind' => $d['kind'], 'error' => 'DNS роутера возвращён как был.' . dnsSetPkgNote($d), 'finished' => time()]);
    return null;
  });
  if ($err) {
    fail($err);
  }
}

function dnsSetPkgNote(array $d): string
{
  return !empty($d['pkg_new']) && ($d['kind'] ?? 'openwrt') === 'openwrt' ? ' Пакет https-dns-proxy остался установленным, но выключен.' : '';
}
