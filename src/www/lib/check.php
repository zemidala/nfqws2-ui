<?php
// Server section: checking a site: which profile takes it and whether it opens.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ================= проверка хоста =================

function hostInEntries(string $host, array $entries): ?string
{
  $set = [];
  foreach ($entries as $e) {
    $set[strtolower(rtrim($e, '.'))] = true;
  }
  if (isset($set['^' . $host])) {
    return '^' . $host;
  }
  $parts = explode('.', $host);
  for ($k = 0; $k < count($parts); $k++) {
    $d = implode('.', array_slice($parts, $k));
    if (isset($set[$d])) {
      return $d;
    }
  }
  return null;
}

function ipInEntry(string $ipBin, string $entry): bool
{
  // no «a-b» ranges: nfqws2 takes an address or a subnet only (parse_cidr4/6) and skips a range
  [$net, $bits] = array_pad(explode('/', $entry, 2), 2, null);
  $netBin = @inet_pton($net);
  if ($netBin === false || strlen($netBin) !== strlen($ipBin)) {
    return false;
  }
  $bits = $bits === null ? strlen($ipBin) * 8 : (int)$bits;
  $bytes = intdiv($bits, 8);
  if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
    return false;
  }
  $rem = $bits % 8;
  if ($rem === 0) {
    return true;
  }
  $mask = (0xFF << (8 - $rem)) & 0xFF;
  return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
}

class ListCache
{
  private array $c = [];

  public function get(string $path): array
  {
    return $this->c[$path] ??= readListLines($path);
  }
}

function ipsIn(array $ips, array $paths, array $inline, ListCache $cache): ?array
{
  foreach ($ips as $ip) {
    $bin = inet_pton($ip);
    foreach ($inline as $e) {
      if (ipInEntry($bin, $e)) {
        return ['ip' => $ip, 'entry' => $e, 'list' => null];
      }
    }
    foreach ($paths as $path) {
      foreach ($cache->get($path) as $e) {
        if (ipInEntry($bin, $e)) {
          return ['ip' => $ip, 'entry' => $e, 'list' => basename($path)];
        }
      }
    }
  }
  return null;
}

function hostIn(string $host, array $paths, array $inline, ListCache $cache): ?array
{
  foreach ($paths as $path) {
    if ($d = hostInEntries($host, $cache->get($path))) {
      return ['entry' => $d, 'list' => basename($path)];
    }
  }
  if ($d = hostInEntries($host, $inline)) {
    return ['entry' => $d, 'list' => null];
  }
  return null;
}

// Повторяет выбор профиля nfqws2: порт → L7 → ipset → hostlist; первый подходящий побеждает
function matchRoute(array $profiles, string $host, array $ips, string $proto, int $port, string $l7, ListCache $cache): array
{
  $isIp = isIp($host);
  $steps = [];
  foreach ($profiles as $p) {
    $pp = protoPorts($p);
    $s = ['profile' => $p['index'], 'ok' => false, 'why' => ''];
    if (!empty($p['skip'])) {
      $s['why'] = 'профиль выключен';
      $s['skip'] = true;
    } elseif (!inRanges($port, $pp[$proto])) {
      $s['why'] = 'другие порты';
      $s['skip'] = true;
    } elseif ($p['l7'] !== null && !in_array($l7, $p['l7'], true)) {
      $s['why'] = 'другой протокол (' . implode(', ', $p['l7']) . ')';
      $s['skip'] = true;
    } elseif ($p['has_ip_filter'] && !($hit = ipsIn($ips, $p['ipsets'], $p['ipset_ips'], $cache))) {
      $s['why'] = 'IP не входит в IP-списки профиля';
    } elseif (($p['ipset_excludes'] || $p['ipset_exclude_ips']) && ($ex = ipsIn($ips, $p['ipset_excludes'], $p['ipset_exclude_ips'], $cache))) {
      $s['why'] = "IP {$ex['ip']} в исключениях" . ($ex['list'] ? " ({$ex['list']})" : '');
    } elseif ($p['has_host_filter'] && !($p['autolist'] && !$isIp) && ($isIp || !($hh = hostIn($host, array_merge($p['hostlists'], $p['autolist'] ? [$p['autolist']] : []), $p['hostlist_domains'], $cache)))) {
      $s['why'] = $isIp ? 'профиль работает по именам сайтов' : 'сайта нет в списках профиля';
      // единственное, чего не хватило профилю, — записи в его списке сайтов (подбор предлагает её добавить)
      $s['miss'] = $isIp ? null : 'hostlist';
    } elseif (!$isIp && ($ex = hostIn($host, $p['hostlist_excludes'], $p['hostlist_exclude_domains'], $cache))) {
      $s['why'] = "сайт в исключениях ({$ex['entry']}" . ($ex['list'] ? " в {$ex['list']}" : '') . ')';
      $s['excluded_by'] = $ex['list'];
    } else {
      $s['ok'] = true;
      if (isset($hit)) {
        $s['why'] = "IP {$hit['ip']} в " . ($hit['list'] ?? 'профиле');
      } elseif (isset($hh)) {
        $s['why'] = "есть в {$hh['list']}" . ($hh['entry'] !== $host ? " (как {$hh['entry']})" : '');
      } elseif ($p['autolist']) {
        $s['why'] = 'профиль с автосписком: стратегия включится, когда nfqws2 заметит блокировку';
      } elseif ($p['empty_hostlists']) {
        $s['why'] = 'списки сайтов профиля пусты — nfqws2 считает, что фильтра нет';
      } else {
        $s['why'] = 'профиль без списков — обрабатывает всё на этих портах';
      }
      $steps[] = $s;
      return ['proto' => $proto, 'port' => $port, 'l7' => $l7, 'profile' => $p['index'], 'steps' => $steps];
    }
    unset($hit, $hh);
    $steps[] = $s;
  }
  return ['proto' => $proto, 'port' => $port, 'l7' => $l7, 'profile' => null, 'steps' => $steps];
}

// Ограничение «16-20 КБ» (ТСПУ): к зарубежным хостингам соединение замирает после ~16–20 КБ или ~25 пакетов —
// без RST, просто перестают приходить пакеты. Маленькая страница его не покажет, поэтому, как чекер
// hyperion-cs/dpi-checkers (tcp-16-20), отправляем POST на 64 КБ в новом соединении.
const VOLUME_BYTES = 65536;
const VOLUME_SMALL = 24576;   // страница меньше — объёмом её не проверить, нужен POST
const REASON_FREEZE = 'соединение зависает на объёме (после ~16–20 КБ)';

// true — соединение замерло на объёме; false — прошло или результат не показателен
// Keenetic, «только политика»: запросы самого роутера nfqws2 не обрабатывает — мониторинг и «Проверить сайт» видели бы
// сайт без обхода. Поэтому проверочные соединения роутера получают метку политики (своя цепочка в mangle OUTPUT, только
// TCP с портов PROBE_PORTS) и идут тем же путём, что устройства из политики: через её подключение и через nfqws2.
// Прошивка время от времени перестраивает таблицы — правило проверяется перед каждой проверкой.
const PROBE_PORTS = '41000:41099';
const PROBE_CHAIN = 'nfqws_ui_out';

function policyProbeEnsure(?array $policy = null, bool $fresh = false): bool
{
  static $done = null;
  if ($done !== null && !$fresh) {
    return $done;
  }
  $policy ??= interceptInfo()['policy'];
  $ipt = 'iptables -w -t mangle ';
  if (($policy['mode'] ?? '') !== 'only' || !preg_match('/^0x[0-9a-f]+$/i', $policy['mark'])) {
    policyProbeRemove();
    return $done = false;
  }
  $rule = '-p tcp --sport ' . PROBE_PORTS . ' -j MARK --set-xmark ' . $policy['mark'] . '/0x0fffffff';
  // быстрый путь: правило уже видно в прочитанной таблице — без четырёх запусков iptables -C (на mipsel это заметно)
  $all = mangleRules();
  $mine = preg_grep('/^-A ' . PROBE_CHAIN . ' /', $all);
  if (count($mine) === 1 && preg_grep('/^-A OUTPUT .*-j ' . PROBE_CHAIN . '\b/', $all)
    && preg_match('/--sport ' . preg_quote(PROBE_PORTS, '/') . ' .*--set-xmark (0x[0-9a-f]+)\/0x0?fffffff\b/i', reset($mine), $x)
    && hexdec($x[1]) === hexdec($policy['mark'])) {
    return $done = true;
  }
  $ok = fn(string $c): bool => exec($ipt . $c . ' 2>/dev/null', $o, $rc) !== null && $rc === 0;
  if (!$ok('-C ' . PROBE_CHAIN . ' ' . $rule)) {
    exec($ipt . '-N ' . PROBE_CHAIN . ' 2>/dev/null');
    exec($ipt . '-F ' . PROBE_CHAIN . ' 2>/dev/null');
    exec($ipt . '-A ' . PROBE_CHAIN . ' ' . $rule . ' 2>/dev/null');
  }
  if (!$ok('-C OUTPUT -j ' . PROBE_CHAIN)) {
    exec($ipt . '-I OUTPUT 1 -j ' . PROBE_CHAIN . ' 2>/dev/null');
  }
  return $done = $ok('-C ' . PROBE_CHAIN . ' ' . $rule) && $ok('-C OUTPUT -j ' . PROBE_CHAIN);
}

function policyProbeRemove(): void
{
  $ipt = 'iptables -w -t mangle ';
  exec($ipt . '-S ' . PROBE_CHAIN . ' 2>/dev/null', $o, $rc);
  if ($rc !== 0) {
    return;   // цепочки нет
  }
  while (true) {
    exec($ipt . '-D OUTPUT -j ' . PROBE_CHAIN . ' 2>/dev/null', $o, $rc);
    if ($rc !== 0) {
      break;
    }
  }
  exec($ipt . '-F ' . PROBE_CHAIN . ' 2>/dev/null; ' . $ipt . '-X ' . PROBE_CHAIN . ' 2>/dev/null');
}

// Параметры curl для запроса «как от устройства политики»: порт источника из PROBE_PORTS
function policyProbeOpts(): array
{
  if (!policyProbeEnsure()) {
    return [];
  }
  [$lo, $hi] = array_map('intval', explode(':', PROBE_PORTS));
  $from = mt_rand($lo, $hi - 20);
  return [CURLOPT_LOCALPORT => $from, CURLOPT_LOCALPORTRANGE => $hi - $from + 1];
}
