<?php
// Server section: the 16 KB cut-off probe.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ----- обрыв на 16 КБ: проба по сетям -----
// Провайдер пропускает начало соединения с зарубежными хостингами и замораживает его после ~16–20 КБ.
// Перебор обычных стратегий тут не помогает; помогает фейк с именем из «белого списка» провайдера, и имя
// подходит не для сайта, а для сети (AS) целиком. Проба ходит на опорные адреса в крупных сетях, ищет обрыв
// и для каждой сети с обрывом подбирает имя. Найденное ставится профилем на список адресов этой сети.

// [подпись, адрес, путь]: файлы больше 64 КБ, которые отдаются по HTTPS кусками (проверено с роутера)
const FREEZE_REFS = [
  ['OVH', 'proof.ovh.net', '/files/1Mb.dat'],
  ['Linode (Akamai)', 'speedtest.frankfurt.linode.com', '/100MB-frankfurt.bin'],
  ['Vultr', 'fra-de-ping.vultr.com', '/vultr.com.100MB.bin'],
  ['DataPacket (CDN77)', 'fra.download.datapacket.com', '/100mb.bin'],
  ['Amazon CloudFront', 'ip-ranges.amazonaws.com', '/ip-ranges.json'],
  ['Cloudflare', 'speed.cloudflare.com', '/__down?bytes=200000'],
  ['Selectel — Россия, для сравнения', 'speedtest.selectel.ru', '/10MB'],
];
// Сети без своего сайта с большим файлом — по адресу (padProbe): [подпись, адрес, порт, AS, имя в приветствии].
// Адреса — из runnin4ik/dpi-detector, tcp16.json (MIT), по одному на сеть; у Hetzner собственные адреса
// (fsn1/hel1-speed.hetzner.com) у части провайдеров не соединяются вовсе, поэтому и они здесь
const FREEZE_IPS = [
  ['Hetzner', '91.98.156.82', 443, 24940, null],
  ['Hetzner Cloud', '5.161.249.234', 443, 213230, null],
  ['DigitalOcean', '157.230.91.62', 443, 14061, null],
  ['Contabo', '173.249.28.198', 443, 51167, null],
  ['Scaleway', '62.4.10.75', 443, 12876, null],
  ['Oracle Cloud', '132.145.253.77', 443, 31898, null],
  ['Google Cloud', '35.227.66.60', 443, 396982, null],
  ['Akamai', '23.222.76.4', 443, 20940, null],
  ['Fastly (GitHub)', '185.199.108.133', 443, 54113, 'release-assets.githubusercontent.com'],
  ['Gcore', '62.112.221.146', 443, 199524, null],
  ['Melbicom', '109.122.201.147', 443, 8849, null],
  ['M247', '146.70.158.22', 443, 9009, null],
  ['FranTech (BuyVM)', '107.189.29.143', 443, 53667, null],
];
const FREEZE_SNI = 'example.com';   // имя в приветствии, когда у адреса своего нет
// Имена для фейка — сайты, которые провайдеры обычно не трогают. Первыми — те, что снимали обрыв у многих
// (ya.ru — у автора; 300.ya.ru, hcaptcha.com, ad.adriver.ru — замеры z2k и dpi-detector, whitelist_sni.txt)
const FREEZE_NAMES = ['ya.ru', '300.ya.ru', 'hcaptcha.com', 'ad.adriver.ru', 'vk.com', 'gosuslugi.ru', 'ozon.ru', 'www.google.com'];
// приёмы, в которые подставляется имя (NAME). Первый снимает и замирание до ответа (опорные адреса Hetzner),
// второй — обрыв на объёме (king.hr, Linode, Vultr); проверено у провайдера с обоими видами
const FREEZE_TEMPLATES = [
  ['--lua-desync=hostfakesplit:repeats=4:tcp_ts=-600000:tcp_md5:host=NAME'],
  ['--lua-desync=hostfakesplit:tcp_md5:tcp_ts_up:repeats=16:host=NAME'],
  ['--lua-desync=fake:blob=fake_default_tls:tls_mod=rnd,dupsid,sni=NAME:tcp_md5:tcp_ts=-600000:repeats=16'],
];
const FREEZE_LIMIT = 900;   // секунд на подбор имён всех сетей
const FREEZE_NET_LIMIT = 150;   // и на одну сеть
define('FREEZE_FILE', UI_CONF_DIR . '/freeze.json');

function freezeJob(array $job, array &$status, ?array &$nfq, string $iface, array $baseArgs): void
{
  // сеть — по имени с большим файлом (curlProbe) или по адресу (padProbe); свои сайты — первыми
  // «only own»: just the own sites and addresses, without the reference networks
  $only = !empty($job['only']);
  $refs = $only ? [] : array_map(fn($r) => ['label' => $r[0], 'host' => $r[1], 'path' => $r[2]], FREEZE_REFS);
  foreach ($only ? [] : FREEZE_IPS as [$label, $ip, $port, $asn, $sni]) {
    $refs[] = ['label' => $label, 'host' => $ip, 'path' => null, 'pad' => ['ip' => $ip, 'port' => $port, 'sni' => $sni ?? FREEZE_SNI], 'asn' => $asn];
  }
  foreach ($job['extra'] ?? [] as $h) {
    array_unshift($refs, ['label' => $h, 'host' => $h, 'path' => '/']);
  }
  // own addresses (a VPS, a game server): the same probe as the reference addresses; the network — by RIPEstat
  foreach (array_reverse($job['ips'] ?? []) as $a) {
    $label = $a['ip'] . ($a['port'] !== 443 ? ':' . $a['port'] : '');
    array_unshift($refs, ['label' => $label, 'host' => $a['ip'], 'path' => null, 'pad' => ['ip' => $a['ip'], 'port' => $a['port'], 'sni' => FREEZE_SNI], 'asn' => null, 'own' => true]);
  }
  // one row per address: an own address equal to a reference one stays as own (rows are keyed by host)
  $seenHost = [];
  $refs = array_values(array_filter($refs, function ($r) use (&$seenHost) {
    $k = $r['host'] . ':' . ($r['pad']['port'] ?? 443);
    return !isset($seenHost[$k]) && ($seenHost[$k] = true);
  }));
  $probeNet = fn(array $n): array => isset($n['pad']) ? padProbe($n['pad']['ip'], $n['pad']['port'], $n['pad']['sni']) : curlProbe($n['host'], false, $n['path']);
  $funcs = luaCatalog()['functions'];
  $templates = array_values(array_filter(FREEZE_TEMPLATES, fn($t) => isset($funcs[explode(':', substr($t[0], 13))[0]])));
  $filterOf = fn(array $n): array => ['--filter-tcp=' . ($n['pad']['port'] ?? 443), '--filter-l7=tls', '--payload=tls_client_hello'];
  $status['nets'] = [];
  $status['total'] = count($refs);
  $status['phase'] = 'baseline';
  testSaveStatus($status);
  $try = function (array $n, array $steps) use (&$nfq, $baseArgs, $filterOf, $probeNet): array {
    $nfq = testNfqwsStart(array_merge($baseArgs, $filterOf($n), $steps));
    if (!$nfq) {
      return ['ok' => 0, 'tries' => 0, 'ms' => null, 'reason' => 'nfqws2 не принял параметры'];
    }
    $ok = 0;
    $ms = [];
    $reason = null;
    $tries = 0;
    // первая попытка может попасть в хвост прошлой неудачи: провайдер какое-то время помнит замёрзшее соединение
    // с этим адресом (у Hetzner так) — одна повторная через 3 секунды; засчитывается только три подряд
    for ($i = 0; $i < 3; $i++) {
      $tries++;
      $r = $probeNet($n);
      if (!$r['ok'] && $tries === 1) {
        sleep(3);
        $tries++;
        $r = $probeNet($n);
      }
      if (!$r['ok']) {
        $reason = $r['reason'];
        break;
      }
      $ok++;
      $ms[] = $r['ms'];
    }
    testNfqwsStop($nfq);
    $nfq = null;
    return ['ok' => $ok, 'tries' => $tries, 'ms' => $ms ? (int)round(array_sum($ms) / count($ms)) : null, 'reason' => $reason];
  };
  // сначала все сети без обхода: где обрыв есть
  testRules('bypass', $iface);
  foreach ($refs as $ref) {
    if (is_file(TEST_DIR . '/stop')) {
      break;
    }
    $status['current'] = $ref['label'];
    testSaveStatus($status);
    if (isset($ref['pad'])) {
      $ip = $ref['pad']['ip'];
      $asn = $ref['asn'] ?? (int)(ripe('network-info', $ip)['asns'][0] ?? 0);
    } else {
      $ip = diagDns($ref['host'])['ips'][0] ?? null;
      $asn = $ip && !(isIp4($ip) && ipReserved($ip)) ? (int)(ripe('network-info', $ip)['asns'][0] ?? 0) : 0;
    }
    $b = $ip ? $probeNet($ref) : ['ok' => false, 'code' => 0, 'ms' => 0, 'reason' => 'имя не разрешается'];
    // замирает и раньше, до ответа сервера (у Hetzner так): соединение с адресом есть, дальше тишина — лечится тем же
    $stall = !$b['ok'] && $b['reason'] === 'тайм-аут' && array_filter(diagTcp([$ip], $ref['pad']['port'] ?? 443), fn($x) => $x['ok']);
    // own address that does not even connect: closed by IP — no strategy helps, say so instead of «не проверить»
    $noTcp = !empty($ref['own']) && !$b['ok'] && !$stall && !array_filter(diagTcp([$ip], $ref['pad']['port']), fn($x) => $x['ok']);
    // array_merge, not «+»: an own address comes with 'asn' => null, the found one must replace it
    $status['nets'][] = array_merge($ref, ['ip' => $ip, 'asn' => $asn ?: null]) + ['baseline' => $b, 'no_tcp' => $noTcp,
      'freeze' => (!$b['ok'] && $b['reason'] === REASON_FREEZE) || $stall, 'stall' => (bool)$stall, 'found' => null, 'tried' => []];
    $status['done']++;
    testSaveStatus($status);
  }
  // затем для каждой сети с обрывом — имя; хватает первого, открывшего 3 раза из 3
  $frozen = array_keys(array_filter($status['nets'], fn($n) => $n['freeze']));
  $status['phase'] = 'names';
  $status['total'] += count($frozen);
  testSaveStatus($status);
  testRules('queue', $iface);
  $started = time();
  foreach ($frozen as $k) {
    $n = &$status['nets'][$k];
    // имя подбирается для сети: нашлось на другом адресе той же сети — берём его (свои сайты идут первыми)
    foreach ($status['nets'] as $o) {
      if ($n['asn'] && $o['asn'] === $n['asn'] && !empty($o['found'])) {
        $n['found'] = $o['found'] + ['via' => $o['label']];
        break;
      }
    }
    // у части сетей обрыв не снимает ни одно имя — не тратим на такую всё время пробы
    // сети различаются прежде всего именем: сначала все имена с первым приёмом, потом остальные приёмы
    $netStarted = time();
    foreach ($n['found'] ? [] : $templates as $t) {
      foreach (FREEZE_NAMES as $name) {
        if (is_file(TEST_DIR . '/stop') || time() - $started > FREEZE_LIMIT) {
          $status['state'] = is_file(TEST_DIR . '/stop') ? 'stopped' : 'time';
          break 3;
        }
        if (time() - $netStarted > FREEZE_NET_LIMIT) {
          $n['gave_up'] = true;
          break 2;
        }
        $steps = array_map(fn($x) => str_replace('NAME', $name, $x), $t);
        $status['current'] = "{$n['label']}: " . strategyName($steps) . " с именем $name";
        testSaveStatus($status);
        $r = $try($n, $steps);
        $n['tried'][] = ['name' => $name, 'steps' => $steps] + $r;
        if ($r['ok'] === 3) {
          $n['found'] = ['name' => $name, 'steps' => $steps, 'strategy' => strategyName($steps), 'ms' => $r['ms']];
          break 2;
        }
      }
    }
    unset($n);
    $status['done']++;
    testSaveStatus($status);
  }
  unset($n);
  testRules('off', $iface);
  $status['state'] = in_array($status['state'], ['stopped', 'time'], true) ? $status['state'] : 'done';
  $status['finished'] = time();
  $status['current'] = null;
  testSaveStatus($status);
  putSafe(FREEZE_FILE, json_encode(array_intersect_key($status, array_flip(['state', 'started', 'finished', 'nets'])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// Шаг без порядка параметров и без strategy=N: «hostfakesplit:tcp_md5:repeats=4» и «hostfakesplit:repeats=4:tcp_md5» — одно и то же
function stepNorm(string $tok): string
{
  $s = stepParse($tok);
  unset($s['p']['strategy']);
  ksort($s['p']);
  return stepBuild($s);
}

// Найденное пробой уже стоит в конфиге? Считается при каждом чтении итога: конфиг мог измениться после пробы.
// in_conf: profiles — профили с той же стратегией; route — через какой профиль на самом деле идёт TLS к адресу сети
function freezeInConf(array $nets): array
{
  $norm = fn(array $steps) => implode(' ', array_map('stepNorm', $steps));
  $same = [];
  foreach (configCandidates('https') as $c) {
    preg_match_all('/#(\d+)/', $c['from'], $m);
    $k = $norm($c['steps']);
    $same[$k] = array_values(array_unique(array_merge($same[$k] ?? [], array_map('intval', $m[1]))));
  }
  $profiles = null;
  $cache = new ListCache();
  foreach ($nets as &$n) {
    if (empty($n['found']['steps'])) {
      continue;
    }
    $profiles ??= currentProfiles();
    $r = matchRoute($profiles, $n['host'], array_filter([$n['ip'] ?? null]), 'tcp', 443, 'tls', $cache);
    $in = $same[$norm($n['found']['steps'])] ?? [];
    $why = '';
    // профиль с этой стратегией есть, но адрес до него не доходит — почему именно
    foreach ($r['steps'] as $st) {
      if (in_array($st['profile'], $in, true) && !$st['ok']) {
        $why = $st['why'];
        break;
      }
    }
    $n['in_conf'] = ['profiles' => $in, 'route' => $r['profile'], 'why' => $why, 'join' => $r['profile'] !== null && in_array($r['profile'], $in, true) ? null : freezeJoinable($profiles, $in, $r, $n, $cache)];
  }
  unset($n);
  return $nets;
}

// Профиль с той же стратегией, в который сеть можно влить строкой --ipset=<список сети> вместо нового профиля:
// «свой» профиль, отбирающий только по IP-спискам (у nfqws2 условия профиля складываются через «и» — со списком сайтов
// адреса сети прошли бы только для этих сайтов), до которого адрес сети доходит и который с этим адресом его взял бы
function freezeJoinable(array $profiles, array $in, array $route, array $n, ListCache $cache): ?int
{
  $reached = array_column($route['steps'], 'profile');
  foreach ($profiles as $p) {
    // список этой сети в профиле уже есть, а адрес в него не входит — вторая такая же строка не поможет
    if (!in_array($p['index'], $in, true) || !in_array($p['index'], $reached, true)
      || in_array(LISTS_DIR . "/ipset_as{$n['asn']}.list", $p['ipsets'], true)
      || ($p['source']['source'] ?? '') !== 'NFQWS_ARGS_CUSTOM' || !$p['ipsets']
      || $p['hostlists'] || $p['hostlist_domains'] || $p['autolist'] !== null) {
      continue;
    }
    // примерка: тот же профиль, будто адрес сети уже в его IP-списке
    $try = $p;
    $try['ipset_ips'][] = $n['ip'] ?? '';
    if (!empty($n['ip']) && matchRoute([$try], $n['host'], [$n['ip']], 'tcp', 443, 'tls', $cache)['profile'] === $p['index']) {
      return $p['index'];
    }
  }
  return null;
}

// Влить сеть в профиль: список адресов AS (обновляется сам) + строка --ipset в профиле; перезапуск — со страницы
function freezeJoin(int $asn, int $index): array
{
  $f = json_decode((string)@file_get_contents(FREEZE_FILE), true);
  $nets = array_values(array_filter($f['nets'] ?? [], fn($x) => (int)($x['asn'] ?? 0) === $asn && !empty($x['found']['steps']) && empty($x['found']['via'])));
  // конфиг могли поменять, пока страница была открыта, — условия проверяются заново
  if (!$nets || (freezeInConf([$nets[0]])[0]['in_conf']['join'] ?? null) !== $index) {
    fail('Профиль уже не подходит для этой сети — обновите страницу');
  }
  $a = asnFetch($asn);
  if (!$a['prefixes']) {
    fail("У AS$asn нет анонсированных префиксов");
  }
  $w = asnWrite($a, true);
  $note = "сеть AS$asn добавлена в профиль #$index: обрыв на 16 КБ";
  $new = profileAddToken((string)file_get_contents(CONF_FILE), profileByIndex($index), '--ipset=' . $w['path']);
  $lint = lintConf(confRawVars($new), $new);
  if (!$lint['dry_run'] || !$lint['dry_run']['ok']) {
    fail('nfqws2 не принимает конфиг с этим списком: ' . ($lint['dry_run']['message'] ?? 'ошибка'), 422);
  }
  if (!writeWithBackup(CONF_FILE, $new, $note)) {
    fail('Не удалось записать конфиг', 500);
  }
  return ['list' => $w['name'], 'count' => $w['count'], 'profile' => $index];
}

// Находки последней пробы для подбора: сначала найденное в сети этого сайта, затем в остальных сетях
function freezeCandidates(string $host): array
{
  $f = json_decode((string)@file_get_contents(FREEZE_FILE), true);
  $nets = array_filter($f['nets'] ?? [], fn($n) => !empty($n['found']['steps']));
  if (!$nets) {
    return [];
  }
  $asn = hostAsn($host);
  $out = [];
  foreach ($nets as $n) {
    $same = $asn && ($n['asn'] ?? null) === $asn;
    $key = implode(' ', $n['found']['steps']);
    if (isset($out[$key]) && !$same) {
      continue;
    }
    $out[$key] = ['name' => strategyName($n['found']['steps']) . ' · ' . $n['found']['name'], 'steps' => $n['found']['steps'], 'rank' => 1, 'same' => $same ? 1 : 0,
      'from' => 'проба обрыва: ' . ($same ? "сеть сайта AS$asn" : 'помогло в сети ' . $n['label'])];
  }
  usort($out, fn($a, $b) => $b['same'] <=> $a['same']);
  return array_map(fn($c) => array_diff_key($c, ['same' => 1]), $out);
}
