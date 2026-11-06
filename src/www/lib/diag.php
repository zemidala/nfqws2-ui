<?php
// Server section: blocking diagnosis, QUIC.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ================= диагноз блокировки =================
// Ступени: имя (DNS) → соединение (TCP) → запрос мимо nfqws2 → «по имени или по адресу» → открытый HTTP →
// запрос через nfqws2. Каждая ступень — отдельный запрос интерфейса: виден ход, нет долгих ответов.
// diagVerdict сводит результаты ступеней в один диагноз.

// Защищённый DNS для сверки: адреса серверов заданы явно, чтобы не зависеть от проверяемого резолвера
const DIAG_DOH = [['Яндекс', 'common.dot.dns.yandex.net', '77.88.8.8'], ['Google', 'dns.google', '8.8.8.8']];
// Заведомо разрешённое имя: с ним проверяем, режут соединение по имени сайта или по адресу
const DIAG_SNI = 'ya.ru';
const DIAG_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130 Safari/537.36';
// Признаки страницы-заглушки провайдера: в адресе перенаправления и в тексте короткой страницы
const DIAG_REDIRECT_MARKS = ['warning.rt.ru', 'lawfilter', 'zapret-info', 'rkn.gov.ru', 'eais.rkn', 'blocked', 'blackhole', 'access-denied'];
const DIAG_PAGE_MARKS = ['доступ к ресурсу ограничен', 'доступ ограничен', 'ресурс заблокирован', 'единый реестр', 'eais.rkn.gov.ru', 'rkn.gov.ru', 'warning.rt.ru', 'по решению суда', 'роскомнадзор'];

// Запрос A-записи в двоичном формате DNS (для DoH по RFC 8484)
function dnsWireQuery(string $host): string
{
  $q = "\x00\x00\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00";
  foreach (explode('.', $host) as $label) {
    $q .= chr(strlen($label)) . $label;
  }
  return $q . "\x00\x00\x01\x00\x01";
}

// Ответ DNS → код ответа и адреса IPv4
function dnsWireParse(string $r): ?array
{
  $n = strlen($r);
  if ($n < 12) {
    return null;
  }
  $h = unpack('nid/nflags/nqd/nan', $r);
  $pos = 12;
  $skipName = function () use ($r, $n, &$pos): void {
    while ($pos < $n) {
      $len = ord($r[$pos]);
      if ($len === 0) {
        $pos++;
        return;
      }
      if (($len & 0xC0) === 0xC0) {
        $pos += 2;
        return;
      }
      $pos += $len + 1;
    }
  };
  for ($i = 0; $i < $h['qd']; $i++) {
    $skipName();
    $pos += 4;
  }
  $ips = [];
  for ($i = 0; $i < $h['an'] && $pos + 10 <= $n; $i++) {
    $skipName();
    if ($pos + 10 > $n) {
      break;
    }
    $a = unpack('ntype/nclass/Nttl/nlen', substr($r, $pos, 10));
    $pos += 10;
    if ($a['type'] === 1 && $a['len'] === 4 && $pos + 4 <= $n) {
      $ips[] = inet_ntop(substr($r, $pos, 4));
    }
    $pos += $a['len'];
  }
  return ['rcode' => $h['flags'] & 15, 'ips' => $ips];
}

function dohResolve(string $server, string $ip, string $host): array
{
  $ch = curl_init("https://$server/dns-query?dns=" . rtrim(strtr(base64_encode(dnsWireQuery($host)), '+/', '-_'), '='));
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_NOSIGNAL => 1,
    CURLOPT_RESOLVE => ["$server:443:$ip"],
    CURLOPT_HTTPHEADER => ['accept: application/dns-message'],
  ]);
  $t = microtime(true);
  $body = curl_exec($ch);
  $err = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $ms = (int)round((microtime(true) - $t) * 1000);
  $ans = is_string($body) && $code === 200 ? dnsWireParse($body) : null;
  if (!$ans) {
    return ['ok' => false, 'ips' => [], 'ms' => $ms, 'error' => $err ?: "HTTP $code"];
  }
  return ['ok' => true, 'ips' => $ans['ips'], 'rcode' => $ans['rcode'], 'ms' => $ms];
}

function isIp4(string $s): bool
{
  $b = @inet_pton($s);
  return $b !== false && strlen($b) === 4;
}

// Адрес, по которому сайт в интернете жить не может: такой ответ DNS — заглушка
function ipReserved(string $ip): bool
{
  $b = inet_pton($ip);
  foreach (['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.168.0.0/16', '224.0.0.0/3'] as $net) {
    if (ipInEntry($b, $net)) {
      return true;
    }
  }
  return false;
}

// Ступень 1: ответ резолвера роутера против защищённого DNS
function diagDns(string $host): array
{
  $system = array_values(array_filter(gethostbynamel($host) ?: [], 'isIp4'));
  $doh = [];
  $ref = [];
  $answered = 0;
  foreach (DIAG_DOH as [$name, $server, $ip]) {
    $r = dohResolve($server, $ip, $host);
    $doh[] = ['name' => $name] + $r;
    if ($r['ok']) {
      $answered++;
      $ref = array_merge($ref, $r['ips']);
    }
  }
  $ref = array_values(array_unique($ref));
  $podkop = is_file('/etc/init.d/podkop') && (bool)array_filter($system, fn($ip) => ipInEntry(inet_pton($ip), '198.18.0.0/15'));
  if (!$ref) {
    // защищённый DNS адреса не дал: либо имени нет, либо сверить не с чем (нет связи с DoH, локальное имя)
    $status = $system ? 'unverified' : 'nxdomain';
  } elseif (!$system) {
    $status = 'no_system';
  } elseif ($podkop) {
    $status = 'podkop';
  } elseif (array_intersect($system, $ref)) {
    $status = 'ok';
  } elseif (!array_filter($system, fn($ip) => !ipReserved($ip)) || ipIspOwned($system[0])) {
    $status = 'fake';   // «серый» адрес или адрес из сети провайдера — заглушка
  } else {
    $status = 'differs';   // у CDN ответы разным резолверам отличаются — решит запрос к сайту
  }
  $useRef = in_array($status, ['no_system', 'podkop', 'fake'], true);
  return ['status' => $status, 'system' => $system, 'ref' => $ref, 'doh' => $doh, 'doh_answered' => $answered, 'podkop' => $podkop,
    'ips' => array_slice($useRef ? $ref : $system, 0, 3), 'alt' => $status === 'differs' ? array_slice($ref, 0, 2) : []];
}

// Адрес из сети провайдера (его AS)? Так выглядит заглушка, которую DNS провайдера отдаёт вместо заблокированного сайта.
// null — не узнать (AS провайдера не определилась, RIPEstat не ответил). Ответы RIPEstat помним сутки
function ipIspOwned(string $ip): ?bool
{
  if (!isIp4($ip) || ipReserved($ip)) {
    return null;
  }
  $my = myAsn(true);
  if (!$my) {
    return null;
  }
  $a = ipAsn($ip);
  return $a === null ? null : $a === $my;
}

// Network (AS number) of an address via RIPEstat, remembered for a day; 0 — not announced, null — RIPEstat did not answer
function ipAsn(string $ip): ?int
{
  $file = CACHE_DIR . '/ip-asn.json';
  $c = json_decode((string)@file_get_contents($file), true);
  $c = is_array($c) ? array_filter($c, fn($e) => time() - $e[1] < 86400) : [];
  if (!isset($c[$ip])) {
    $d = ripe('network-info', $ip, 8);
    if ($d === null) {
      return null;
    }
    $c[$ip] = [(int)($d['asns'][0] ?? 0), time()];
    @mkdir(CACHE_DIR, 0700, true);
    @file_put_contents($file, json_encode($c));
  }
  return $c[$ip][0];
}

// Сайт не открылся: не подменяет ли DNS роутера адрес. Сверка с защищённым DNS; если адреса разные — открываем сайт
// через nfqws2 по настоящему адресу. Подмена — адрес роутера из сети провайдера или по настоящему адресу сайт открывается
// (тогда дело не в стратегии). null — подмены не видно. Только для имён и только после неудачи: это 2–3 лишних запроса
function dnsSpoof(string $host, ?array $system = null): ?array
{
  if (isIp($host)) {
    return null;
  }
  $system = array_values(array_filter($system ?? (gethostbynamel($host) ?: []), 'isIp4'));
  // podkop отдаёт свои адреса (FakeIP) намеренно — это не подмена
  if (!$system || array_filter($system, fn($ip) => ipInEntry(inet_pton($ip), '198.18.0.0/15'))) {
    return null;
  }
  $ref = [];
  foreach (DIAG_DOH as [, $server, $ip]) {
    $r = dohResolve($server, $ip, $host);
    $ref = array_merge($ref, $r['ok'] ? $r['ips'] : []);
  }
  $ref = array_values(array_filter(array_unique($ref), fn($ip) => isIp4($ip) && !ipReserved($ip)));
  if (!$ref || array_intersect($system, $ref)) {
    return null;
  }
  $isp = ipIspOwned($system[0]);
  $real = probe($host, $ref[0]);
  if (!$isp && !$real['ok'] && !array_filter($system, 'ipReserved')) {
    return null;   // у CDN ответы разным DNS отличаются, а по «настоящему» адресу тоже не открылось — подмены не видно
  }
  // fake — заглушка (адрес провайдера или «серый»); иначе это другой сервер того же сайта: у больших сетей (Meta, Google)
  // разные DNS отдают разные адреса, и часть из них провайдер закрывает по IP — DNS тут не виноват
  $fake = $isp !== false || (bool)array_filter($system, 'ipReserved');
  return ['system' => array_slice($system, 0, 3), 'real' => array_slice($ref, 0, 3), 'isp' => $isp, 'fake' => $fake,
    'real_ok' => $real['ok'], 'real_code' => $real['code'], 'real_ms' => $real['ms'], 'real_reason' => $real['reason']];
}

// Строка для мониторинга и журнала
const REASON_OTHER_SERVER = 'похоже, этот сервер закрыт по IP';

function dnsSpoofReason(array $sp): string
{
  if (!($sp['fake'] ?? true)) {
    return 'адрес ' . $sp['system'][0] . ' не открывается, а ' . $sp['real'][0] . ' от защищённого DNS открывается — ' . REASON_OTHER_SERVER;
  }
  return 'DNS роутера отдаёт ' . $sp['system'][0] . ($sp['isp'] ? ' (сеть провайдера)' : '') . ' вместо ' . $sp['real'][0]
    . ($sp['real_ok'] ? ' — по настоящему адресу открывается' : '');
}

// Адреса сайта не соединяются: не выдаёт ли другой DNS адрес, по которому сайт открывается. У больших сетей
// (Meta, Google, CDN) разные DNS отдают разные адреса, и закрытым по IP бывает только часть — тогда дело в DNS,
// а не в блокировке сайта. $tried — адреса, уже проверенные. Запрос к сайту — мимо nfqws2, как ступень 3
const DIAG_DOH_MORE = [['Cloudflare', 'cloudflare-dns.com', '1.1.1.1'], ['Quad9', 'dns.quad9.net', '9.9.9.9']];

function diagOther(string $host, array $tried): array
{
  $cand = [];
  foreach (array_merge(DIAG_DOH, DIAG_DOH_MORE) as [$name, $server, $ip]) {
    $r = dohResolve($server, $ip, $host);
    foreach ($r['ok'] ? $r['ips'] : [] as $a) {
      if (isIp4($a) && !in_array($a, $tried, true) && !ipReserved($a)) {
        $cand[$a] ??= $name;
      }
    }
  }
  // Признак — соединение с адресом: блокировку по имени (DPI) на нём снимет nfqws2, а закрытый по IP адрес — нет.
  // Открылся ли сайт мимо nfqws2 — только для пояснения
  $checked = [];
  foreach (array_slice(array_keys($cand), 0, 4) as $a) {
    $up = diagTcp([$a])[0]['ok'];
    $r = $up ? diagBypass(fn() => diagDirect($host, $a)) : null;
    $checked[] = ['ip' => $a, 'from' => $cand[$a], 'tcp' => $up, 'open' => $r && $r['ok'] && !$r['isp_page']];
    if ($up) {
      break;
    }
  }
  $good = array_values(array_filter($checked, fn($c) => $c['tcp']));
  return ['checked' => $checked, 'ok' => (bool)$good, 'ip' => $good[0]['ip'] ?? null, 'from' => $good[0]['from'] ?? null, 'open' => $good[0]['open'] ?? false];
}

// Ступень 2: устанавливается ли соединение с адресами сайта
function diagTcp(array $ips, int $port = 443): array
{
  $out = [];
  foreach ($ips as $ip) {
    $t = microtime(true);
    $f = @fsockopen($ip, $port, $errno, $errstr, 4);
    $ms = (int)round((microtime(true) - $t) * 1000);
    if ($f) {
      fclose($f);
    }
    $out[] = ['ip' => $ip, 'ok' => (bool)$f, 'ms' => $ms, 'error' => $f ? null : ($errno === 110 || $ms >= 3900 ? 'нет ответа' : ($errno === 111 ? 'соединение отклонено' : ($errstr ?: "ошибка $errno")))];
  }
  return $out;
}

// Запросы «мимо nfqws2»: правила подбора пропускают проверочные соединения роутера мимо основной очереди.
// Правила общие с подбором стратегии, поэтому одновременно с ним диагноз не работает.
function diagBypass(callable $fn)
{
  if (testRunning()) {
    fail('Сейчас идёт подбор стратегии — диагноз использует те же проверочные правила. Дождитесь окончания подбора.');
  }
  @mkdir(TEST_DIR, 0777, true);
  $lock = fopen(TEST_DIR . '/diag.lock', 'c');
  flock($lock, LOCK_EX);
  $iface = explode(' ', trim(confValues()['ISP_INTERFACE']))[0] ?: 'eth1';
  testRules('bypass', $iface);
  try {
    return $fn();
  } finally {
    testRules('off', $iface);
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}

// Один запрос curl с проверочного порта. Возвращает код выхода, код HTTP, времена, текст ошибки и её вид.
function diagCurl(string $args, int $max): array
{
  [$lo, $hi] = array_map('intval', explode(':', TEST_PORTS));
  $w = '%{http_code}|%{time_connect}|%{time_appconnect}|%{time_total}|%{size_download}|%{redirect_url}|%{errormsg}';
  $out = [];
  exec('curl -4 -sk' . probeTlsOpt() . ' --local-port ' . mt_rand($lo, $hi - 50) . '-' . $hi . ' --connect-timeout 5 -m ' . $max . ' -A ' . escapeshellarg(DIAG_UA)
    . ' -w ' . escapeshellarg($w) . ' ' . $args . ' 2>/dev/null', $out, $rc);
  $f = array_pad(explode('|', (string)end($out), 7), 7, '');
  $code = (int)$f[0];
  $tls = (float)$f[2] > 0;
  $msg = strtolower($f[6]);
  if ($rc === 0 && $code > 0) {
    $kind = 'ok';
  } elseif ($rc === 6) {
    $kind = 'dns';
  } elseif ($rc === 7) {
    $kind = 'connect';
  } elseif ($rc === 28) {
    $kind = $code > 0 ? 'freeze' : ($tls ? 'stall' : 'timeout');
  } elseif ($rc === 35) {
    $kind = str_contains($msg, 'alert') ? 'alert' : (str_contains($msg, 'reset') ? 'reset' : 'tls');
  } elseif ($rc === 56) {
    $kind = 'reset';
  } elseif ($rc === 52) {
    $kind = 'empty';
  } else {
    $kind = 'other';
  }
  return ['ok' => $kind === 'ok', 'kind' => $kind, 'rc' => $rc, 'code' => $code, 'tls' => $tls, 'ms' => (int)round((float)$f[3] * 1000),
    'size' => (int)$f[4], 'redirect' => $f[5], 'error' => $f[6]];
}

// Похоже ли на заглушку провайдера: код 451, перенаправление на страницу блокировки или её текст
function diagIspPage(string $host, array $r, string $bodyFile): bool
{
  if ($r['code'] === 451) {
    return true;
  }
  $to = strtolower((string)parse_url($r['redirect'], PHP_URL_HOST));
  if ($to !== '' && $to !== $host && !str_ends_with($to, '.' . $host) && !str_ends_with($host, '.' . $to)) {
    foreach (DIAG_REDIRECT_MARKS as $m) {
      if (str_contains(strtolower($r['redirect']), $m)) {
        return true;
      }
    }
  }
  // настоящие сайты длиннее; на длинной странице такие слова встречаются и в обычном тексте
  if ($r['ok'] && $r['size'] > 0 && $r['size'] < 8192) {
    $body = (string)@file_get_contents($bodyFile, false, null, 0, 8192);
    // в сборке PHP для роутера нет ни iconv, ни mbstring
    if (function_exists('iconv') && preg_match('/charset=["\']?windows-1251/i', $body)) {
      $body = (string)@iconv('windows-1251', 'utf-8//IGNORE', $body);
    }
    static $lower = null;
    $lower ??= array_combine(preg_split('//u', 'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ', -1, PREG_SPLIT_NO_EMPTY), preg_split('//u', 'абвгдеёжзийклмнопрстуфхцчшщъыьэюя', -1, PREG_SPLIT_NO_EMPTY));
    $body = strtolower(strtr($body, $lower));
    foreach (DIAG_PAGE_MARKS as $m) {
      if (str_contains($body, $m)) {
        return true;
      }
    }
  }
  return false;
}

// Ступень 3: запрос сайта мимо nfqws2, с привязкой к адресу. Маленькая страница дополнительно проверяется
// на заморозку объёмом, как в проверке сайта.
function diagDirect(string $host, string $ip): array
{
  $body = TEST_DIR . '/diag.body';
  @unlink($body);
  $pin = '--resolve ' . escapeshellarg("$host:443:$ip");
  $r = diagCurl($pin . ' -r 0-' . (VOLUME_BYTES - 1) . ' -o ' . escapeshellarg($body) . ' ' . escapeshellarg("https://$host/"), 8);
  $r['ip'] = $ip;
  $r['isp_page'] = diagIspPage($host, $r, $body);
  @unlink($body);
  if ($r['ok'] && !$r['isp_page'] && $r['size'] < VOLUME_SMALL) {
    $blob = TEST_DIR . '/volume.bin';
    if (!is_file($blob) || filesize($blob) !== VOLUME_BYTES) {
      file_put_contents($blob, random_bytes(VOLUME_BYTES));
    }
    $v = diagCurl($pin . ' -H "Expect:" -H "Content-Type: application/octet-stream" --data-binary @' . escapeshellarg($blob)
      . ' -o /dev/null ' . escapeshellarg("https://$host/?t=" . mt_rand()), 8);
    if ($v['rc'] === 28 && $v['tls']) {
      $r['ok'] = false;
      $r['kind'] = 'freeze';
    }
  }
  return $r;
}

// Ступень 4: по имени режут или по адресу. Две пробы:
//  — адрес сайта с чужим (разрешённым) именем: если сервер ответил хоть чем-то, путь до адреса открыт;
//  — чужой адрес с именем сайта: если соединение рвётся и там, режут само имя.
function diagSni(string $host, string $ip): array
{
  $res = ['foreign_name' => null, 'foreign_addr' => null, 'by_name' => false];
  if ($host === DIAG_SNI) {
    return $res;
  }
  $a = diagCurl('--connect-to ' . escapeshellarg(DIAG_SNI . ":443:$ip:443") . ' -o /dev/null ' . escapeshellarg('https://' . DIAG_SNI . '/'), 6);
  // TLS-alert прислал сам сервер — значит, наш запрос до него дошёл
  $reached = $a['ok'] || $a['tls'] || $a['kind'] === 'alert';
  $res['foreign_name'] = ['reached' => $reached, 'kind' => $a['kind']];
  $other = array_values(array_filter(gethostbynamel(DIAG_SNI) ?: [], 'isIp4'))[0] ?? null;
  if ($other) {
    $b = diagCurl('--connect-to ' . escapeshellarg("$host:443:$other:443") . ' -o /dev/null ' . escapeshellarg("https://$host/"), 6);
    $blocked = in_array($b['kind'], ['reset', 'timeout', 'tls', 'empty'], true);
    $res['foreign_addr'] = ['blocked' => $blocked, 'kind' => $b['kind']];
  }
  $res['by_name'] = $reached || !empty($res['foreign_addr']['blocked']);
  return $res;
}

// Ступень 5: открытый HTTP на 80-м порту — заглушка провайдера и «закрыт весь адрес или только HTTPS»
function diagHttp(string $host, string $ip): array
{
  $body = TEST_DIR . '/diag.body';
  @unlink($body);
  $r = diagCurl('--resolve ' . escapeshellarg("$host:80:$ip") . ' -r 0-8191 -o ' . escapeshellarg($body) . ' ' . escapeshellarg("http://$host/"), 6);
  $r['isp_page'] = diagIspPage($host, $r, $body);
  @unlink($body);
  return $r;
}

// Ступень 6: как сайт открывается сейчас — через основной nfqws2 и с обычным DNS роутера
function diagVia(string $host): array
{
  $r = probe($host);
  $ips = gethostbynamel($host) ?: [];
  $route = matchRoute(currentProfiles(), $host, $ips, 'tcp', 443, 'tls', new ListCache());
  return $r + ['profile' => $route['profile'] ?? null, 'running' => findPid() !== null];
}

// Сводит результаты ступеней в диагноз. pick — имеет ли смысл подбирать стратегию.
function diagVerdict(array $r): array
{
  $dns = $r['dns'] ?? [];
  $direct = $r['direct'] ?? null;
  $via = $r['via'] ?? null;
  $tcpOk = (bool)array_filter($r['tcp'] ?? [], fn($x) => !empty($x['ok']));
  $v = fn(string $code, bool $pick = false) => ['code' => $code, 'pick' => $pick, 'profile' => $via['profile'] ?? null];
  $status = $dns['status'] ?? '';
  if ($status === 'nxdomain') {
    return $v('nxdomain');
  }
  if ($status === 'podkop') {
    return $v(!empty($via['ok']) ? 'podkop_ok' : 'podkop_fail');
  }
  // роутер получил не тот адрес: по своему не открывается, по адресу из защищённого DNS — открывается
  if (in_array($status, ['fake', 'no_system'], true) || ($status === 'differs' && !empty($r['alt_ok']))) {
    return $v($status === 'no_system' ? 'dns_noanswer' : 'dns_fake');
  }
  if ($direct && !empty($direct['isp_page']) || !empty($r['http']['isp_page'])) {
    return $v(!empty($via['ok']) ? 'fixed' : 'isp_page', empty($via['ok']));
  }
  if ($direct && !empty($direct['ok'])) {
    return $v(!$via || !empty($via['ok']) ? 'ok' : 'broken_by_nfqws');
  }
  if (!empty($via['ok'])) {
    return $v('fixed');
  }
  if (($direct['kind'] ?? '') === 'freeze') {
    return $v('freeze', true);   // подбор с уточнением иногда снимает и заморозку
  }
  if (!$tcpOk) {
    // адрес от своего DNS закрыт, а по адресу от другого DNS сайт открывается
    if (!empty($r['other']['ok'])) {
      return $v('dns_other', empty($r['other']['open']));   // и по другому адресу мимо nfqws2 не открылся — нужен и обход
    }
    return $v(!empty($r['http']['ok']) ? 'port_block' : 'ip_block');
  }
  if (!empty($r['sni']['by_name'])) {
    return $v('sni_block', true);
  }
  if (($direct['kind'] ?? '') === 'alert') {
    return $v('site_error');
  }
  if (in_array($direct['kind'] ?? '', ['reset', 'timeout', 'tls', 'stall', 'empty'], true)) {
    return $v('tls_block', true);
  }
  return $v('unknown', true);
}

// ----- QUIC (HTTP/3) -----
// curl на роутерах собран без HTTP/3, а в PHP нет openssl — первый пакет QUIC собирает сам nfqws2: с --intercept=0 он
// выполняет Lua и выходит, а в его Lua есть всё нужное шифрование (hash, aes, aes_gcm). Пакет — настоящий QUIC v1
// Initial (RFC 9000, 9001) с ClientHello на имя сайта; сервер отвечает на него своим Initial, а провайдер, который
// режет QUIC по имени, — молчанием. Отправляет пакет PHP по UDP: любой ответ значит «QUIC дошёл».
const QUIC_LUA = <<<'LUA'
local function u8(n) return string.char(n % 256) end
local function u16(n) return string.char(math.floor(n / 256) % 256, n % 256) end
local function u24(n) return string.char(math.floor(n / 65536) % 256, math.floor(n / 256) % 256, n % 256) end
local function v16(b) return u16(#b) .. b end
local function varint(n) if n < 64 then return u8(n) end return u16(0x4000 + n) end

local function hmac(key, msg)
  key = key .. string.rep("\0", 64 - #key)
  return hash("sha256", bxor(key, string.rep("\92", 64)) .. hash("sha256", bxor(key, string.rep("\54", 64)) .. msg))
end
local function expand_label(secret, label, len)
  local l = "tls13 " .. label
  return hmac(secret, u16(len) .. u8(#l) .. l .. u8(0) .. "\1"):sub(1, len)
end

local dcid, scid = brandom(8), brandom(8)

-- ClientHello TLS 1.3: имя, группы, подписи, ALPN h3, версия, ключ x25519, параметры QUIC
local function ext(t, b) return u16(t) .. v16(b) end
local tp = u8(0x0f) .. u8(#scid) .. scid .. u8(0x04) .. u8(4) .. "\128\16\0\0" .. u8(0x01) .. u8(2) .. u16(0x4000 + 13616)
local exts = ext(0, v16(u8(0) .. v16(SNI)))
  .. ext(10, v16(u16(0x001d) .. u16(0x0017)))
  .. ext(13, v16(u16(0x0403) .. u16(0x0804) .. u16(0x0401) .. u16(0x0503) .. u16(0x0805) .. u16(0x0501) .. u16(0x0806) .. u16(0x0601)))
  .. ext(16, v16(u8(2) .. "h3"))
  .. ext(43, u8(2) .. u16(0x0304))
  .. ext(45, u8(1) .. u8(1))
  .. ext(51, v16(u16(0x001d) .. v16(brandom(32))))
  .. ext(57, tp)
local body = u16(0x0303) .. brandom(32) .. u8(0) .. v16(u16(0x1301) .. u16(0x1302) .. u16(0x1303)) .. u8(1) .. u8(0) .. v16(exts)
local ch = u8(1) .. u24(#body) .. body

-- кадр CRYPTO и добивка: дейтаграмма с Initial не короче 1200 байт
local frames = u8(6) .. varint(0) .. varint(#ch) .. ch
local pn_len = 4
local need = 1200 - (1 + 4 + 1 + 8 + 1 + 8 + 1 + 2) - pn_len - 16
if #frames < need then frames = frames .. string.rep("\0", need - #frames) end

-- ключи Initial: соль QUIC v1 и идентификатор соединения
local salt = "\56\118\44\247\245\89\52\179\77\23\154\230\164\200\12\173\204\187\127\10"
local cis = expand_label(hmac(salt, dcid), "client in", 32)
local key, iv, hp = expand_label(cis, "quic key", 16), expand_label(cis, "quic iv", 12), expand_label(cis, "quic hp", 16)

local pn = "\0\0\0\0"
local header = u8(0xC3) .. "\0\0\0\1" .. u8(8) .. dcid .. u8(8) .. scid .. u8(0) .. varint(pn_len + #frames + 16) .. pn
local enc, tag = aes_gcm(true, key, iv, frames, header)   -- номер пакета 0: nonce = iv
local packet = header .. enc .. tag

-- защита заголовка
local pn_off = #header - pn_len
local mask = aes(true, hp, packet:sub(pn_off + 5, pn_off + 20))
packet = u8(bitxor(packet:byte(1), bitand(mask:byte(1), 0x0f))) .. packet:sub(2, pn_off) .. bxor(pn, mask:sub(2, 5)) .. packet:sub(pn_off + pn_len + 1)

local hex = packet:gsub(".", function(c) return string.format("%02x", c:byte()) end)
print("QUICHEX " .. hex)
LUA;
const QUIC_WAIT = 2;    // секунд на ответ
const QUIC_TRIES = 2;   // UDP теряется — один повтор

function quicPacket(string $host): ?string
{
  @mkdir(TEST_DIR, 0777, true);
  $lua = TEST_DIR . '/quic-initial.lua';
  if (!is_file($lua) || md5_file($lua) !== md5(QUIC_LUA)) {
    file_put_contents($lua, QUIC_LUA);
    @chmod($lua, 0644);
  }
  [, $out] = runTimeout([NFQWS_BIN, '--intercept=0', '--lua-init=SNI="' . $host . '"', '--lua-init=@' . $lua], 10);
  return preg_match('/^QUICHEX ([0-9a-f]+)$/m', $out, $m) ? hex2bin($m[1]) : null;
}

// Зонд QUIC. $testPort — с проверочного порта (мимо основного nfqws2 или через проверочную очередь — по правилам
// testRules), иначе — как обычный трафик роутера, через основной nfqws2
function quicProbe(string $host, string $ip, bool $testPort, ?string $pkt = null): array
{
  $pkt ??= quicPacket($host);
  if ($pkt === null) {
    return ['ok' => false, 'ms' => null, 'reason' => 'не удалось собрать пакет QUIC (nfqws2 не выполнил сценарий)'];
  }
  [$lo, $hi] = array_map('intval', explode(':', TEST_PORTS));
  $s = false;
  for ($i = 0; $i < 5 && !$s; $i++) {   // порт может быть занят — берём другой
    $ctx = stream_context_create($testPort ? ['socket' => ['bindto' => '0.0.0.0:' . mt_rand($lo, $hi)]] : []);
    $s = @stream_socket_client("udp://$ip:443", $en, $es, 3, STREAM_CLIENT_CONNECT, $ctx);
    if (!$testPort) {
      break;
    }
  }
  if (!$s) {
    return ['ok' => false, 'ms' => null, 'reason' => 'не удалось открыть соединение UDP'];
  }
  stream_set_timeout($s, QUIC_WAIT);
  $t = microtime(true);
  for ($i = 0; $i < QUIC_TRIES; $i++) {
    @fwrite($s, $pkt);
    $r = @fread($s, 4096);
    if (is_string($r) && $r !== '') {
      fclose($s);
      return ['ok' => true, 'ms' => (int)round((microtime(true) - $t) * 1000), 'reason' => null, 'bytes' => strlen($r)];
    }
  }
  fclose($s);
  return ['ok' => false, 'ms' => null, 'reason' => 'нет ответа'];
}

// Объявляет ли сайт HTTP/3: заголовок Alt-Svc с h3 в ответе по обычному HTTPS. null — узнать не удалось
function quicAdvertised(string $host): ?bool
{
  $alt = null;
  $got = false;
  $ch = curl_init('https://' . $host . '/');
  curl_setopt_array($ch, [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 8, CURLOPT_NOSIGNAL => 1, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_USERAGENT => DIAG_UA,
    CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$alt, &$got) {
      $got = true;
      if (stripos($h, 'alt-svc:') === 0) {
        $alt = (bool)preg_match('/\bh3(-\d+)?=/i', $h);
      }
      return strlen($h);
    }] + policyProbeOpts());
  curl_exec($ch);
  curl_close($ch);
  return $got ? (bool)$alt : null;
}

// Ступень диагноза: отвечает ли сайт по QUIC мимо nfqws2 и через него
function diagQuic(string $host, string $ip): array
{
  $pkt = quicPacket($host);
  $direct = diagBypass(fn() => quicProbe($host, $ip, true, $pkt));
  $pol = interceptInfo()['policy'] ?? null;
  $only = $pol && ($pol['mode'] ?? '') === 'only';   // политика Keenetic: запросы самого роутера идут мимо основного nfqws2
  $running = findPid() !== null;
  $via = $direct['ok'] || $only || !$running ? null : quicProbe($host, $ip, false, $pkt);
  $route = matchRoute(currentProfiles(), $host, [$ip], 'udp', 443, 'quic', new ListCache());
  return ['direct' => $direct, 'via' => $via, 'h3' => $direct['ok'] || !empty($via['ok']) ? true : quicAdvertised($host),
    'profile' => $route['profile'] ?? null, 'policy' => $only, 'running' => $running];
}
