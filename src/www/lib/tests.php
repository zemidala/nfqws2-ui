<?php
// Server section: strategy tests, tracing, the zapret2 catalog.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ================= тесты стратегий и трассировка =================
//
// Тест поднимает отдельный nfqws2 на очереди TEST_QNUM. Правила iptables отправляют в неё только
// проверочные соединения самого роутера — curl ходит с локальных портов TEST_PORTS. Основной nfqws2
// и трафик сети это не затрагивает (NFQUEUE-вердикт завершает обход mangle, до nfqws_post пакет не доходит).

const TEST_DIR = '/tmp/nfqws-ui-test';
const TEST_QNUM = 301;
const TEST_PORTS = '40000:40999';

// Стандартный набор стратегий для TLS и HTTP (стратегии без payload/filter — только шаги)
const STD_TLS = [
  ['multisplit pos=1,midsld', ['--lua-desync=multisplit:pos=1,midsld']],
  ['multisplit pos=2', ['--lua-desync=multisplit:pos=2']],
  ['multidisorder pos=1,midsld', ['--lua-desync=multidisorder:pos=1,midsld']],
  ['multisplit с seqovl', ['--lua-desync=multisplit:pos=1:seqovl=1:seqovl_pattern=tls_clienthello']],
  ['fake tcp_md5 + multisplit', ['--lua-desync=fake:blob=tls_clienthello:tcp_md5', '--lua-desync=multisplit:pos=1,midsld']],
  ['fake badsum + multisplit', ['--lua-desync=fake:blob=tls_clienthello:badsum', '--lua-desync=multisplit:pos=1,midsld']],
  ['fake tcp_seq + multidisorder', ['--lua-desync=fake:blob=tls_clienthello:tcp_seq=-10000', '--lua-desync=multidisorder:pos=1,midsld']],
  ['fake autottl + multisplit', ['--lua-desync=fake:blob=tls_clienthello:tls_mod=rnd,dupsid,sni=www.google.com:ip_autottl=-2,3-20', '--lua-desync=multisplit:pos=1,midsld']],
  ['fake x6 tcp_md5 + fakedsplit', ['--lua-desync=fake:blob=tls_clienthello:repeats=6:tcp_md5', '--lua-desync=fakedsplit:pos=1:tcp_md5']],
  ['fakedsplit tcp_md5', ['--lua-desync=fakedsplit:pos=midsld:tcp_md5']],
  ['fakeddisorder tcp_md5', ['--lua-desync=fakeddisorder:pos=midsld:tcp_md5']],
  ['hostfakesplit google.com', ['--lua-desync=hostfakesplit:host=google.com:tcp_md5:tcp_ts_up']],
];
const STD_HTTP = [
  ['http_methodeol', ['--lua-desync=http_methodeol']],
  ['http_hostcase', ['--lua-desync=http_hostcase']],
  ['multisplit pos=method+2', ['--lua-desync=multisplit:pos=method+2']],
  ['fake badsum + multisplit', ['--lua-desync=fake:blob=0x00000000:badsum', '--lua-desync=multisplit:pos=method+2']],
];
// QUIC (UDP 443): фейки первого пакета и изменение длины дейтаграммы. Блоб fake_default_quic встроен в nfqws2 —
// набор не зависит от того, какие блобы объявлены в конфиге
const STD_QUIC = [
  ['fake QUIC ×2', ['--lua-desync=fake:blob=fake_default_quic:repeats=2']],
  ['fake QUIC ×4', ['--lua-desync=fake:blob=fake_default_quic:repeats=4']],
  ['fake QUIC ×6', ['--lua-desync=fake:blob=fake_default_quic:repeats=6']],
  ['fake QUIC ×11', ['--lua-desync=fake:blob=fake_default_quic:repeats=11']],
  ['fake QUIC ×16', ['--lua-desync=fake:blob=fake_default_quic:repeats=16']],
  ['fake QUIC ×6 autottl', ['--lua-desync=fake:blob=fake_default_quic:repeats=6:ip_autottl=-2,3-20']],
  ['fake нули ×6', ['--lua-desync=fake:blob=0x00000000000000000000000000000000:repeats=6']],
  ['udplen +2', ['--lua-desync=udplen:increment=2']],
  ['udplen +8', ['--lua-desync=udplen:increment=8']],
  ['fake QUIC ×6 + udplen +2', ['--lua-desync=fake:blob=fake_default_quic:repeats=6', '--lua-desync=udplen:increment=2']],
];

// ----- каталог стратегий по правилам blockcheck2 (zapret2, blockcheck2.d/standard) -----
// Те же функции, позиции разреза и способы испортить фейк, что перебирает blockcheck2. Взяты только варианты
// из одних шагов --lua-desync (без дополнительных --payload/--out-range). «Расширенный» — без перебора TTL и
// с основными позициями (~330 TLS), «полный» — всё (~3000 TLS): его проверяет параллельный прогон.
const CAT_FOOL = ['tcp_md5', 'badsum', 'tcp_seq=-3000', 'tcp_seq=1000000', 'tcp_ack=-66000:tcp_ts_up', 'tcp_ts=-1000', 'tcp_flags_unset=ACK', 'tcp_flags_set=SYN'];
const CAT_SPLITS_TLS = ['2', '1', 'sniext+1', 'sniext+4', 'host+1', 'midsld', '1,midsld', '1,midsld,1220', '1,sniext+1,host+1,midsld-2,midsld,midsld+2,endhost-1'];
const CAT_SPLITS_HTTP = ['method+2', 'midsld', 'method+2,midsld'];

function catalogCandidates(string $level, bool $http): array
{
  $full = $level === 'full';
  $fool = CAT_FOOL;
  foreach (range(1, 5) as $d) {
    $fool[] = "ip_autottl=-$d,3-20";
  }
  if ($full) {
    foreach (range(1, 12) as $t) {
      $fool[] = "ip_ttl=$t";
    }
  }
  $splits = $http ? CAT_SPLITS_HTTP : CAT_SPLITS_TLS;
  $fake = $http ? 'fake_default_http' : 'fake_default_tls';
  $d = '--lua-desync=';
  $out = [];
  $add = function (array $steps) use (&$out) {
    $out[implode(' ', $steps)] = $steps;
  };
  // разрез без фейков
  foreach (['multisplit', 'multidisorder'] as $f) {
    foreach ($splits as $p) {
      $add(["{$d}$f:pos=$p"]);
    }
  }
  // фейк: варианты blockcheck2 (pktws_fake_https_vary_)
  $fakes = function (string $fl) use ($d, $fake, $http): array {
    $v = [["{$d}fake:blob=$fake:$fl"], ["{$d}fake:blob=0x00000000:$fl"]];
    if (!$http) {
      $v[] = ["{$d}fake:blob=0x00000000:$fl", "{$d}fake:blob=$fake:$fl:tls_mod=rnd,dupsid"];
      $v[] = ["{$d}multisplit:blob=$fake:$fl:pos=2:nodrop"];
      $v[] = ["{$d}fake:blob=$fake:$fl:tls_mod=rnd,dupsid,padencap"];
    }
    return $v;
  };
  foreach ($fool as $fl) {
    foreach ($fakes($fl) as $v) {
      $add($v);
    }
  }
  // hostfakesplit
  foreach ($fool as $fl) {
    foreach ($full ? ['', 'disorder_after:'] : [''] as $dis) {
      foreach ($full ? ['', 'nofake1:', 'nofake2:', 'midhost=midsld:', 'nofake1:midhost=midsld:', 'nofake2:midhost=midsld:'] : ['', 'midhost=midsld:'] as $m) {
        $add(["{$d}hostfakesplit:$dis$m$fl"]);
      }
    }
  }
  // фейк + разрез
  $msplits = $full ? $splits : ($http ? $splits : ['2', 'midsld', '1,midsld']);
  foreach (['multisplit', 'multidisorder'] as $f) {
    foreach ($msplits as $p) {
      foreach ($fool as $fl) {
        foreach ($full ? $fakes($fl) : array_slice($fakes($fl), 0, 2) as $v) {
          $add(array_merge($v, ["{$d}$f:pos=$p"]));
        }
      }
    }
  }
  // fakedsplit / fakeddisorder — только в полном
  if ($full) {
    foreach (['fakedsplit', 'fakeddisorder'] as $f) {
      foreach ($http ? $splits : array_slice(CAT_SPLITS_TLS, 0, 7) + [7 => CAT_SPLITS_TLS[8]] as $p) {
        foreach ($fool as $fl) {
          $add(["{$d}$f:pos=$p:$fl"]);
        }
      }
    }
  }
  // перекрытие последовательности (seqovl)
  $add(["{$d}tcpseg:pos=0,-1:seqovl=1", "{$d}drop"]);
  foreach ($http ? ['method+2', 'method+2,midsld'] : ['10', '10,sniext+1', '10,sniext+4', '10,midsld'] as $p) {
    $add(["{$d}multisplit:pos=$p:seqovl=1"]);
  }
  foreach ($http ? [['method+1', 'method+2'], ['midsld-1', 'midsld'], ['method+1', 'method+2,midsld']]
    : [['1', '2'], ['sniext', 'sniext+1'], ['sniext+3', 'sniext+4'], ['midsld-1', 'midsld'], ['1', '2,midsld']] as [$a, $b]) {
    $add(["{$d}multidisorder:pos=$b:seqovl=$a"]);
  }
  // сегменты с повтором (только в полном)
  if ($full) {
    foreach ([1, 20, 100, 260] as $r) {
      foreach ($http ? ['0,method+2', '0,midsld'] : ['0,1', '0,midsld'] as $p) {
        $add(["{$d}tcpseg:pos=$p:ip_id=rnd:repeats=$r"]);
      }
    }
  }
  $from = 'каталог zapret2 (' . ($full ? 'полный' : 'расширенный') . ')';
  return array_map(fn($s) => ['name' => strategyName($s), 'from' => $from, 'steps' => $s, 'catalog' => true], array_values($out));
}

// Стратегии из конфига: шаги профилей, которые могут обработать это соединение; circular раскладывается на стратегии
// $anyPort: also profiles of the same protocol for other ports (2053, 8443, a game's port) — the probe still goes to
// 443/80, their strategies are tried on it (NFQ-L231); the label names the profile's own ports
function configCandidates(string $proto, bool $anyPort = false): array
{
  $quic = $proto === 'quic';
  $wantPayload = $proto === 'http' ? 'http_req' : ($quic ? 'quic_initial' : 'tls_client_hello');
  $port = $proto === 'http' ? 80 : 443;
  $out = [];
  foreach (expectedProfiles(confValues()) as $n => $e) {
    $p = buildProfile($n + 1, $e['tokens']);
    $ports = protoPorts($p)[$quic ? 'udp' : 'tcp'];
    $other = !inRanges($port, $ports);
    if ($other && (!$anyPort || $quic || !$ports)) {
      continue;
    }
    // another port: only a profile for this protocol — its --filter-l7 (if any) names tls or http
    if ($other && ($l7 = current(preg_grep('/^--filter-l7=/', $e['tokens']))) && !in_array($proto === 'http' ? 'http' : 'tls', explode(',', substr($l7, 12)), true)) {
      continue;
    }
    // UDP-профиль под другой протокол (discord, stun, wireguard) к QUIC не относится
    if ($quic && ($l7 = current(preg_grep('/^--filter-l7=/', $e['tokens']))) && !in_array('quic', explode(',', substr($l7, 12)), true)) {
      continue;
    }
    $payload = null;
    $steps = [];
    foreach ($e['tokens'] as $t) {
      if (str_starts_with($t, '--payload=')) {
        $payload = explode(',', substr($t, 10));
      } elseif (str_starts_with($t, '--lua-desync=') && ($payload === null || in_array($wantPayload, $payload, true) || in_array('all', $payload, true) || in_array('known', $payload, true))) {
        $steps[] = $t;
      }
    }
    if (!$steps) {
      continue;
    }
    $strategies = [];
    foreach ($steps as $t) {
      // orchestrators: circular and learn (nfqws-ui.lua) only choose among the strategy=N steps
      if (preg_match('/^--lua-desync=(circular|learn)(:|$)/', $t)) {
        continue;
      }
      $n2 = preg_match('/:strategy=(\d+)/', $t, $m) ? (int)$m[1] : 0;
      $strategies[$n2][] = preg_replace('/:strategy=\d+/', '', $t);
    }
    ksort($strategies);
    foreach ($strategies as $sn => $st) {
      $key = implode(' ', $st);
      $from = '#' . ($n + 1) . ($sn ? " стратегия $sn" : '') . ($other ? ' (порты ' . implode(',', array_map(fn($r) => $r[0] === $r[1] ? $r[0] : "$r[0]-$r[1]", $ports)) . ')' : '');
      if (isset($out[$key])) {
        // одна и та же стратегия в нескольких профилях (общая переменная) — тестируем один раз
        $out[$key]['from'] .= ', ' . $from;
        continue;
      }
      $fns = implode(' + ', array_map(fn($t) => explode(':', substr($t, 13))[0], $st));
      $out[$key] = ['name' => $fns, 'from' => 'профиль ' . $from, 'steps' => $st];
    }
  }
  return array_values($out);
}

// $slots — для параллельного прогона: пакеты каждой дорожки (свой кусок проверочных портов) получают свою метку,
// по ней nfqws2 выбирает профиль (--filter-mark)
function testRules(string $mode, string $iface, int $slots = 0): void
{
  $ipt = 'iptables -w -t mangle ';
  // снять старое
  foreach (['POSTROUTING' => 'nfqws_test_post', 'PREROUTING' => 'nfqws_test_pre'] as $hook => $chain) {
    while (true) {
      exec($ipt . "-D $hook -j $chain 2>/dev/null", $o, $rc);
      if ($rc !== 0) {
        break;
      }
    }
    exec($ipt . "-F $chain 2>/dev/null; " . $ipt . "-X $chain 2>/dev/null");
  }
  if ($mode === 'off') {
    return;
  }
  $if = escapeshellarg($iface);
  $cmds = [
    $ipt . '-N nfqws_test_post', $ipt . '-N nfqws_test_pre',
    $ipt . '-I POSTROUTING 1 -j nfqws_test_post', $ipt . '-I PREROUTING 1 -j nfqws_test_pre',
  ];
  // Только соединения самого роутера: проверочные порты — обычные порты источника, с такими же ходят и устройства
  // сети (Linux, Android — примерно 3 соединения из 100). Чужое соединение сразу выходит из проверочных цепочек
  // и идёт дальше как обычно, через основной nfqws2. Отличаем по адресу, с которого соединение началось.
  $o = [];
  exec('ip -4 -o addr show dev ' . $if . ' 2>/dev/null', $o);
  if (preg_match('/inet (\d+\.\d+\.\d+\.\d+)/', implode(' ', $o), $m)) {
    foreach (['nfqws_test_post', 'nfqws_test_pre'] as $chain) {
      $cmds[] = $ipt . "-A $chain -m conntrack ! --ctorigsrc {$m[1]} -j RETURN";   // нет модуля — правило не встанет, будет как раньше
    }
  }
  if ($mode === 'queue') {
    for ($k = 0; $k < $slots; $k++) {
      [$lo, $hi] = parPorts($k);
      $cmds[] = $ipt . "-A nfqws_test_post -o $if -p tcp --sport $lo:$hi -j MARK --set-xmark " . parMark($k) . '/' . PAR_MASK;
      $cmds[] = $ipt . "-A nfqws_test_pre -i $if -p tcp --dport $lo:$hi -j MARK --set-xmark " . parMark($k) . '/' . PAR_MASK;
    }
    $cmds[] = $ipt . "-A nfqws_test_post -o $if -p tcp -m multiport --sports " . TEST_PORTS . ' -m mark ! --mark 0x40000000/0x40000000 -j NFQUEUE --queue-num ' . TEST_QNUM . ' --queue-bypass';
    $cmds[] = $ipt . "-A nfqws_test_pre -i $if -p tcp -m multiport --dports " . TEST_PORTS . ' -m connbytes --connbytes-dir=reply --connbytes-mode=packets --connbytes 1:15 -j NFQUEUE --queue-num ' . TEST_QNUM . ' --queue-bypass';
    // UDP — для зонда QUIC: первые пакеты в обе стороны, как у основного nfqws2
    $cmds[] = $ipt . "-A nfqws_test_post -o $if -p udp -m multiport --sports " . TEST_PORTS . ' -m mark ! --mark 0x40000000/0x40000000 -m connbytes --connbytes-dir=original --connbytes-mode=packets --connbytes 1:15 -j NFQUEUE --queue-num ' . TEST_QNUM . ' --queue-bypass';
    $cmds[] = $ipt . "-A nfqws_test_pre -i $if -p udp -m multiport --dports " . TEST_PORTS . ' -m connbytes --connbytes-dir=reply --connbytes-mode=packets --connbytes 1:15 -j NFQUEUE --queue-num ' . TEST_QNUM . ' --queue-bypass';
  }
  // без обхода и после очереди — мимо основного nfqws2
  foreach (['tcp', 'udp'] as $l4) {
    $cmds[] = $ipt . "-A nfqws_test_post -o $if -p $l4 -m multiport --sports " . TEST_PORTS . ' -j ACCEPT';
    $cmds[] = $ipt . "-A nfqws_test_pre -i $if -p $l4 -m multiport --dports " . TEST_PORTS . ' -j ACCEPT';
  }
  foreach ($cmds as $c) {
    exec($c . ' 2>&1');
  }
}

// A pick «as old devices» (smart TVs that speak only TLS 1.2): every probe of the job is limited to TLS 1.2 — DPI often
// treats a TLS 1.2 greeting differently, and a strategy found over TLS 1.3 may not open the site for them
function probeTlsOpt(): string
{
  return !empty($GLOBALS['PROBE_TLS12']) ? ' --tlsv1.2 --tls-max 1.2' : '';
}

function curlProbe(string $host, bool $http, string $path = '/'): array
{
  $url = ($http ? 'http://' : 'https://') . $host . $path;
  // Начальный порт — случайный: curl берёт первый свободный, и соединения подряд шли бы с одного и того же
  // порта к тому же серверу — на следующее может повлиять остаток прошлого (conntrack, состояние у провайдера).
  $curl = function () {
    [$lo, $hi] = array_map('intval', explode(':', TEST_PORTS));
    return 'curl -4 -sk' . probeTlsOpt() . ' -o /dev/null --local-port ' . mt_rand($lo, $hi - 50) . '-' . $hi
      . ' --connect-timeout 4 -A ' . escapeshellarg('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130 Safari/537.36');
  };
  $out = [];
  exec($curl() . ' -m 7 -r 0-' . (VOLUME_BYTES - 1) . ' -w "%{http_code} %{time_total} %{size_download}" ' . escapeshellarg($url) . ' 2>/dev/null', $out, $rc);
  [$code, $time, $size] = array_pad(explode(' ', trim($out[0] ?? '')), 3, '0');
  $ok = $rc === 0 && (int)$code > 0;
  $reason = $ok ? null : (match ($rc) {
    6 => 'имя не разрешается',
    126, 127 => 'не запускается программа curl',
    7 => 'не удалось подключиться',
    28 => (int)$code > 0 ? REASON_FREEZE : 'тайм-аут',
    35 => 'обрыв TLS',
    52 => 'пустой ответ',
    56 => 'соединение сброшено',
    default => "ошибка curl $rc",
  });
  // маленькая страница — проверяем объём, как чекер tcp-16-20 (см. volumeFrozen)
  if ($ok && (int)$size < VOLUME_SMALL) {
    $body = TEST_DIR . '/volume.bin';
    if (!is_file($body) || filesize($body) !== VOLUME_BYTES) {
      file_put_contents($body, random_bytes(VOLUME_BYTES));
    }
    $vout = [];
    exec($curl() . ' -m 8 -H "Expect:" -H "Content-Type: application/octet-stream" --data-binary @' . escapeshellarg($body)
      . ' -w "%{time_appconnect}" ' . escapeshellarg($url . (str_contains($url, '?') ? '&' : '?') . 't=' . mt_rand()) . ' 2>/dev/null', $vout, $vrc);
    if ($vrc === 28 && (float)trim($vout[0] ?? '0') > 0) {
      [$ok, $reason] = [false, REASON_FREEZE];
    }
  }
  return ['ok' => $ok, 'code' => (int)$code, 'ms' => (int)round((float)$time * 1000), 'reason' => $reason];
}

// Обрыв на объёме по адресу — без сайта и без большого файла (приём runnin4ik/dpi-detector, tcp16): PAD_REQS запросов
// HEAD по одному соединению, к каждому заголовок на PAD_BYTES байт — к серверу уходит ~48 КБ. Первые ответы пришли,
// потом тишина — обрыв. Если сервер не держит соединение (переподключения), итог не показателен.
// $sni — имя в приветствии: от него не зависит обрыв (он по адресу), но без имени не сработает hostfakesplit
const PAD_REQS = 12;
const PAD_BYTES = 4000;

function padProbe(string $ip, int $port, string $sni): array
{
  [$lo, $hi] = array_map('intval', explode(':', TEST_PORTS));
  $url = ($port === 80 ? 'http://' : 'https://') . $sni . ($port === 80 || $port === 443 ? '' : ":$port") . '/';
  // -m — на каждый запрос, а не на все: без --fail-early после обрыва curl открывал бы новые соединения
  // и ждал на каждом (до PAD_REQS × 8 с)
  $cmd = 'curl -4 -sk -I --fail-early --local-port ' . mt_rand($lo, $hi - 50) . '-' . $hi . ' --connect-timeout 4 -m 8'
    . ' --connect-to ' . escapeshellarg("$sni:$port:$ip:$port")
    . ' -A ' . escapeshellarg('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130 Safari/537.36')
    . ' -H ' . escapeshellarg('X-Pad: ' . str_repeat('a', PAD_BYTES))
    . ' -w ' . escapeshellarg('%{http_code} %{num_connects} %{time_total}\n');
  for ($i = 0; $i < PAD_REQS; $i++) {
    $cmd .= ' -o /dev/null ' . escapeshellarg("$url?n=$i");
  }
  $out = [];
  exec($cmd . ' 2>/dev/null', $out, $rc);
  $got = 0;
  $reconnect = false;
  $ms = 0;
  foreach ($out as $k => $line) {
    [$code, $conn, $time] = array_pad(explode(' ', trim($line)), 3, '0');
    if ((int)$code > 0) {
      $got++;
      $ms += (int)round((float)$time * 1000);
      $reconnect = $reconnect || ($k > 0 && (int)$conn > 0);
    }
  }
  // сервер закрывал соединение (переподключения или ответы без кода) — объём в одном соединении не набрался
  if ($reconnect || ($rc === 0 && $got < PAD_REQS)) {
    return ['ok' => false, 'code' => 0, 'ms' => $ms, 'reason' => 'сервер не держит соединение — объём не проверить', 'got' => $got];
  }
  if ($rc === 0) {
    return ['ok' => true, 'code' => 0, 'ms' => $ms, 'reason' => null];
  }
  $reason = match (true) {
    $rc === 28 && $got > 0 => REASON_FREEZE,
    $rc === 28 => 'тайм-аут',
    $rc === 7 => 'не удалось подключиться',
    $rc === 35 => 'обрыв TLS',
    $rc === 52 => 'пустой ответ',
    $rc === 56 => 'соединение сброшено',
    default => "ошибка curl $rc",
  };
  return ['ok' => false, 'code' => 0, 'ms' => $ms, 'reason' => $reason, 'got' => $got];
}
