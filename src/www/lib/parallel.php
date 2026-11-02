<?php
// Server section: running many strategies at once.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ----- параллельный прогон: много стратегий в одном nfqws2, по дорожке на стратегию -----
// Дорожка — свой кусок проверочных портов; iptables метит её пакеты (биты 0x0F000000 свободны: podkop занимает
// 0x00100000/0x00200000, nfqws2 — 0x40000000), nfqws2 выбирает профиль по метке. curl SO_MARK ставить не умеет —
// поэтому метка по порту. Каждая дорожка сама делает до N запросов подряд и бросает после первой неудачи.
const PAR_SLOTS = 15;
const PAR_MASK = '0x0f000000';
const PAR_MIN = 24;       // меньше кандидатов — проверяем по одной, как раньше
const PAR_CONFIRM = 8;    // сколько подтверждённых по одной находок набрать: 15 соединений к одному сайту разом
                          // иногда путают оборудование провайдера, и параллельный итог расходится с проверкой по одной
const PAR_CONFIRM_TRIES = 24;   // и сколько всего перепроверок, если находки пачки не подтверждаются
const PAR_SLOT_MB = 1.5;  // память на дорожку: curl с TLS и оболочка
const PAR_RESERVE_MB = 15;      // столько памяти остаётся системе и прошивке
const PAR_RAM_PER_SLOT_MB = 24; // и не больше дорожки на столько памяти всего: 128 МБ → 5, 256 → 10, от 384 — 15

// Сколько дорожек роутер выдержит сейчас: на Keenetic со 128 МБ 15 дорожек разом уводили память в swap, и роутер
// (вместе с веб-интерфейсом прошивки) переставал отвечать. Одной свободной памяти мало: swap у Keenetic — zram,
// система выгружает в него лишнее, «свободно» снова выглядит хорошо, а процессор занят сжатием. Поэтому ещё и
// потолок по объёму памяти. Считается перед каждой пачкой; 1 — по одной стратегии
function parSlots(): int
{
  $mem = (string)@file_get_contents('/proc/meminfo');
  if (!preg_match('/^MemAvailable:\s+(\d+)/m', $mem, $a) || !preg_match('/^MemTotal:\s+(\d+)/m', $mem, $t)) {
    return PAR_SLOTS;
  }
  $byFree = (int)floor(((int)$a[1] / 1024 - PAR_RESERVE_MB) / PAR_SLOT_MB);
  $byTotal = (int)floor((int)$t[1] / 1024 / PAR_RAM_PER_SLOT_MB);
  return max(1, min(PAR_SLOTS, $byFree, $byTotal));
}

function parMark(int $k): string
{
  return sprintf('0x%08x', ($k + 1) << 24);
}

function parPorts(int $k): array
{
  $lo = (int)explode(':', TEST_PORTS)[0] + $k * 64;
  return [$lo, $lo + 63];
}

function curlReason(int $rc, int $code): string
{
  return match ($rc) {
    6 => 'имя не разрешается',
    126, 127 => 'не запускается программа curl',
    7 => 'не удалось подключиться',
    28 => $code > 0 ? REASON_FREEZE : 'тайм-аут',
    35 => 'обрыв TLS',
    52 => 'пустой ответ',
    56 => 'соединение сброшено',
    default => "ошибка curl $rc",
  };
}

// Пачка стратегий за один запуск nfqws2. Возвращает результаты в том же порядке.
function parTry(array $chunk, ?array &$nfq, array $baseArgs, array $filter, int $repeats, string $host, bool $http): array
{
  $args = $baseArgs;
  foreach (array_values($chunk) as $k => $c) {
    if ($k) {
      $args[] = '--new';
    }
    array_push($args, '--filter-mark=' . parMark($k) . '/' . PAR_MASK, ...$filter, ...$c['steps']);
  }
  $nfq = testNfqwsStart($args);
  if (!$nfq) {
    // какую-то стратегию nfqws2 не принял — ищем её делением пачки пополам
    if (count($chunk) === 1) {
      return [['ok' => 0, 'tries' => 0, 'ms' => null, 'reason' => 'nfqws2 не принял параметры']];
    }
    $half = (int)ceil(count($chunk) / 2);
    return array_merge(parTry(array_slice($chunk, 0, $half), $nfq, $baseArgs, $filter, $repeats, $host, $http),
      parTry(array_slice($chunk, $half), $nfq, $baseArgs, $filter, $repeats, $host, $http));
  }
  $sh = TEST_DIR . '/slot.sh';
  // одна дорожка: до R запросов, после первой неудачи — стоп; маленькая страница — ещё и проверка объёма отправкой
  file_put_contents($sh, implode("\n", [
    'P=$1; U=$2; R=$3; O=$4; : > "$O"; i=0',
    'while [ $i -lt $R ]; do i=$((i+1))',
    '  set -- $(curl -4 -sk' . probeTlsOpt() . ' -o /dev/null --local-port $P --connect-timeout 4 -A "$UA" -m 7 -r 0-' . (VOLUME_BYTES - 1) . ' -w "%{http_code} %{time_total} %{size_download}" "$U" 2>/dev/null; echo " $?")',
    '  code=$1; t=$2; size=$3; rc=$4; v=""',
    '  if [ "$rc" = 0 ] && [ "${size%.*}" -lt ' . VOLUME_SMALL . ' ]; then',
    '    a=$(curl -4 -sk' . probeTlsOpt() . ' -o /dev/null --local-port $P --connect-timeout 4 -A "$UA" -m 8 -H "Expect:" -H "Content-Type: application/octet-stream" --data-binary @' . TEST_DIR . '/volume.bin -w "%{time_appconnect}" "$U?t=$i$$" 2>/dev/null); vrc=$?; v="$vrc $a"',
    '  fi',
    '  echo "$rc $code $t $size $v" >> "$O"',
    '  [ "$rc" = 0 ] || break',
    '  case "$v" in "28 "*) [ "${v#28 }" != "0.000000" ] && break;; esac',   // отправка повисла после рукопожатия — заморозка
    'done', '']));
  if (!is_file(TEST_DIR . '/volume.bin') || filesize(TEST_DIR . '/volume.bin') !== VOLUME_BYTES) {
    file_put_contents(TEST_DIR . '/volume.bin', random_bytes(VOLUME_BYTES));
  }
  $url = ($http ? 'http://' : 'https://') . $host . '/';
  $cmd = 'UA=' . escapeshellarg('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130 Safari/537.36') . '; export UA; ';
  foreach (array_keys(array_values($chunk)) as $k) {
    [$lo, $hi] = parPorts($k);
    $cmd .= 'sh ' . escapeshellarg($sh) . " $lo-$hi " . escapeshellarg($url) . " $repeats " . escapeshellarg(TEST_DIR . "/slot$k.out") . ' & ';
  }
  exec($cmd . 'wait');
  testNfqwsStop($nfq);
  $nfq = null;
  $res = [];
  foreach (array_keys(array_values($chunk)) as $k) {
    $ok = 0;
    $times = [];
    $reason = null;
    $tries = 0;
    foreach (file(TEST_DIR . "/slot$k.out", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
      $f = explode(' ', trim($line));
      $tries++;
      [$rc, $code, $t] = [(int)$f[0], (int)($f[1] ?? 0), (float)($f[2] ?? 0)];
      if ($rc !== 0 || $code === 0) {
        $reason = curlReason($rc, $code);
        break;
      }
      if (isset($f[4]) && (int)$f[4] === 28 && (float)($f[5] ?? 0) > 0) {
        $reason = REASON_FREEZE;
        break;
      }
      $ok++;
      $times[] = (int)round($t * 1000);
    }
    $res[] = ['ok' => $ok, 'tries' => max(1, $tries), 'ms' => $times ? (int)round(array_sum($times) / count($times)) : null, 'reason' => $ok === $tries ? null : ($reason ?? 'нет ответа')];
  }
  return $res;
}

// A blob the strategy names but the config does not declare, while the blobs folder has a file for it (the name itself
// or name.bin) — the test nfqws2 gets «--blob=name:@file», so a strategy can be tried before the blob goes into the config
function blobFileFor(string $name): ?string
{
  if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $name)) {
    return null;
  }
  foreach ([$name, "$name.bin"] as $f) {
    if (is_file(CONF_DIR . "/blobs/$f")) {
      return CONF_DIR . "/blobs/$f";
    }
  }
  return null;
}

function blobAutoArgs(array $args): array
{
  $declared = [];
  foreach ($args as $a) {
    if (preg_match('/^--blob=([^:]+):/', $a, $m)) {
      $declared[$m[1]] = true;
    }
  }
  $add = [];
  foreach ($args as $a) {
    if (!str_starts_with($a, '--lua-desync=')) {
      continue;
    }
    preg_match_all('/:(?:blob|seqovl_pattern|pattern)=([A-Za-z0-9_.-]+)/', $a, $m);
    foreach ($m[1] as $name) {
      // built-in blobs (fake_default_tls…) stay built-in even when a file of that name lies in the folder
      if (!isset($declared[$name]) && !in_array($name, BUILTIN_BLOBS, true) && ($f = blobFileFor($name))) {
        $declared[$name] = true;
        $add[] = "--blob=$name:@$f";
      }
    }
  }
  return $add;
}

// Запускает отдельный nfqws2 на тестовой очереди; возвращает процесс или null
function testNfqwsStart(array $args, ?string $debugFile = null): ?array
{
  $argv = array_merge([NFQWS_BIN, '--qnum=' . TEST_QNUM, '--user=nobody'], $debugFile ? ['--debug=@' . $debugFile] : [], blobAutoArgs($args), $args);
  $p = proc_open(implode(' ', array_map('escapeshellarg', $argv)) . ' >' . TEST_DIR . '/nfqws.out 2>&1', [], $pipes);
  if (!is_resource($p)) {
    return null;
  }
  usleep(600000);
  $st = proc_get_status($p);
  if (!$st['running']) {
    proc_close($p);
    return null;
  }
  return ['proc' => $p, 'pid' => $st['pid']];
}

function testNfqwsStop(?array $h): void
{
  if (!$h) {
    return;
  }
  // proc_open запускает через sh: убиваем и оболочку, и сам nfqws2
  exec('pkill -f ' . escapeshellarg('nfqws2 --qnum=' . TEST_QNUM) . ' 2>/dev/null');
  proc_terminate($h['proc'], 15);
  proc_close($h['proc']);
}

function testStatus(): array
{
  $s = json_decode((string)@file_get_contents(TEST_DIR . '/status.json'), true);
  return is_array($s) ? $s : ['state' => 'idle'];
}

function testSaveStatus(array $s): void
{
  file_put_contents(TEST_DIR . '/status.json.tmp', json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  rename(TEST_DIR . '/status.json.tmp', TEST_DIR . '/status.json');
}

function testRunning(): bool
{
  $s = testStatus();
  return ($s['state'] ?? '') === 'running' && !empty($s['pid']) && file_exists('/proc/' . $s['pid']);
}

// Фоновое задание (запускается через cron-режим CLI): подбор стратегии или трассировка
function testJob(): void
{
  $job = json_decode((string)@file_get_contents(TEST_DIR . '/job.json'), true);
  if (!is_array($job)) {
    return;
  }
  $exp = confValues();
  $iface = explode(' ', trim($exp['ISP_INTERFACE']))[0] ?: 'eth1';
  $baseArgs = tokens($exp['NFQWS_BASE_ARGS']);
  $http = ($job['proto'] ?? 'https') === 'http';
  $quic = ($job['proto'] ?? '') === 'quic';
  $status = ['state' => 'running', 'type' => $job['type'], 'pid' => getmypid(), 'host' => $job['host'], 'proto' => $job['proto'] ?? 'https',
    'started' => time(), 'results' => [], 'done' => 0, 'total' => 0, 'current' => null];
  $auto = !empty($job['auto']);
  if ($auto) {
    $status['auto'] = true;
  }
  // «as old devices»: every probe of this job over TLS 1.2 only (see probeTlsOpt); HTTPS picks only
  if (!empty($job['tls12']) && !$http && !$quic && in_array($job['type'], ['pick', 'multi'], true)) {
    $GLOBALS['PROBE_TLS12'] = true;
    $status['tls12'] = true;
  }
  // сайты, которые podkop пускает в туннель (FakeIP): проверка с роутера уходит туда же, и все стратегии
  // выглядят одинаково — проверяется туннель, а не nfqws2
  $podkop = [];
  foreach (in_array($job['type'], ['pick', 'multi', 'wide', 'cfg'], true) && !$auto ? ($job['hosts'] ?? [$job['host']]) : [] as $h) {
    $pk = podkopRoute($h);
    if ($pk && !empty($pk['proxy'])) {
      $podkop[] = $h;
    }
  }
  if ($podkop) {
    $status['podkop'] = $podkop;
  }
  $nfq = null;
  register_shutdown_function(function () use (&$nfq, $iface, &$status) {
    testNfqwsStop($nfq);
    testRules('off', $iface);
    if ($status['state'] === 'running') {
      $status['state'] = 'error';
      $status['error'] = $status['error'] ?? fatalText() ?? 'задание прервано';
      testSaveStatus($status);
    }
  });
  set_time_limit(900);

  if ($job['type'] === 'trace') {
    // Трассировка: один запрос через копию текущей конфигурации с подробным журналом
    $status['total'] = 1;
    testSaveStatus($status);
    $args = $baseArgs;
    foreach (array_column(expectedProfiles($exp), 'tokens') as $k => $pt) {
      if ($k > 0) {
        $args[] = '--new';
      }
      array_push($args, ...$pt);
    }
    $log = TEST_DIR . '/trace.log';
    @unlink($log);
    // QUIC: the same probe as the pick — the first QUIC packet (made by nfqws2 itself) to one address of the site
    $qip = $pkt = null;
    if ($quic) {
      $dns = diagDns($job['host']);
      $qip = $dns['ips'][0] ?? null;
      $why = $dns['status'] === 'nxdomain' ? 'имя сайта не находится' : ($dns['status'] === 'podkop' ? 'сайт идёт через podkop — QUIC уходит в туннель, мимо nfqws2'
        : ($qip === null ? 'у сайта нет адреса IPv4 — зонд QUIC работает по IPv4' : null));
      $pkt = $why === null ? quicPacket($job['host']) : null;
      if ($why === null && $pkt === null) {
        $why = 'не удалось собрать пакет QUIC (nfqws2 не выполнил сценарий)';
      }
      if ($why !== null) {
        $status['state'] = 'error';
        $status['error'] = 'Трассировка QUIC не запущена: ' . $why;
        testSaveStatus($status);
        return;
      }
    }
    testRules('queue', $iface);
    $nfq = testNfqwsStart($args, $log);
    if (!$nfq) {
      $status['state'] = 'error';
      $status['error'] = 'не удалось запустить nfqws2: ' . trim((string)@file_get_contents(TEST_DIR . '/nfqws.out'));
      testSaveStatus($status);
      return;
    }
    $r = $quic ? quicProbe($job['host'], $qip, true, $pkt) + ['code' => 0] : curlProbe($job['host'], $http);
    usleep(300000);
    testNfqwsStop($nfq);
    $nfq = null;
    testRules('off', $iface);
    $lines = @file($log, FILE_IGNORE_NEW_LINES) ?: [];
    $status['result'] = $r;
    $status['trace'] = array_slice($lines, 0, 1500);
    $status['trace_total'] = count($lines);
    $status['done'] = 1;
    $status['state'] = 'done';
    $status['finished'] = time();
    testSaveStatus($status);
    return;
  }

  if ($job['type'] === 'freeze') {
    freezeJob($job, $status, $nfq, $iface, $baseArgs);
    return;
  }

  if ($job['type'] === 'wide') {
    wideJob($job, $status, $nfq, $iface, $baseArgs);
    return;
  }

  if ($job['type'] === 'multi') {
    multiJob($job, $status, $nfq, $iface, $baseArgs);
    return;
  }

  if ($job['type'] === 'cfg') {
    cfgJob($job, $status, $nfq, $iface);
    return;
  }

  // Подбор стратегии. Сначала — имеет ли он смысл: если имя не находится или адрес не отвечает вовсе,
  // перебор стратегий только зря займёт несколько минут.
  $dns = diagDns($job['host']);
  $why = null;
  if ($dns['status'] === 'nxdomain') {
    $why = 'Имя сайта не находится — ни у роутера, ни у защищённого DNS. Проверьте написание.';
  } elseif ($dns['ips'] && !array_filter(diagTcp($dns['ips'], $http ? 80 : 443), fn($x) => $x['ok'])) {
    $why = 'С адресом сайта нет соединения (' . implode(', ', $dns['ips']) . ') — похоже на блокировку по IP. Стратегии nfqws2 меняют пакеты, а не маршрут, и тут не помогут: нужен туннель (podkop, VPN).';
  }
  $qip = null;
  if ($why === null && $quic) {
    // QUIC: проверка — своим зондом на один адрес сайта. Через podkop и у сайта без HTTP/3 подбирать нечего
    $qip = $dns['ips'][0] ?? null;
    if ($dns['status'] === 'podkop') {
      $why = 'Сайт идёт через podkop — его соединения уходят в туннель, стратегии nfqws2 на них не действуют.';
    } elseif ($qip === null) {
      $why = 'У сайта нет адреса IPv4 — зонд QUIC работает по IPv4.';
    } elseif (quicAdvertised($job['host']) === false) {
      $why = 'Сайт не объявляет HTTP/3 (в ответе нет заголовка Alt-Svc с h3) — по QUIC он, похоже, не работает, и подбирать для него нечего. Браузер открывает его обычным соединением.';
    }
  }
  if ($why !== null) {
    $status['state'] = 'error';
    $status['title'] = 'Подбор не запущен';
    $status['error'] = $why;
    testSaveStatus($status);
    return;
  }
  // один запрос: curl по TCP или зонд QUIC — ответ того же вида
  $probe = fn() => $quic ? quicProbe($job['host'], $qip, true) + ['code' => 0] : curlProbe($job['host'], $http);
  $cands = [];
  if (in_array('config', $job['sets'], true)) {
    $cands = array_merge($cands, configCandidates($status['proto']));
  }
  if (in_array('std', $job['sets'], true)) {
    foreach ($http ? STD_HTTP : ($quic ? STD_QUIC : STD_TLS) as [$name, $steps]) {
      $cands[] = ['name' => $name, 'from' => 'стандартный набор', 'steps' => $steps];
    }
  }
  if (!empty($job['steps'])) {
    array_unshift($cands, ['name' => strategyName($job['steps']), 'from' => 'вставленный профиль', 'steps' => $job['steps']]);
  }
  // свои стратегии из формы подбора — первыми и в том порядке, в каком вставлены; такая же из набора ниже не повторяется
  if (!empty($job['own'])) {
    $mine = [];
    foreach ($job['own'] as $st) {
      $mine[implode(' ', $st)] ??= ['name' => strategyName($st), 'from' => 'своя стратегия', 'steps' => $st, 'rank' => -1, 'own' => true];
    }
    $cands = array_merge(array_values($mine), array_values(array_filter($cands, fn($c) => !isset($mine[implode(' ', $c['steps'])]))));
  }
  // каталог по правилам blockcheck2: расширенный или полный; то, что уже есть выше, не повторяем
  $level = $quic ? null : (in_array('full', $job['sets'], true) ? 'full' : (in_array('ext', $job['sets'], true) ? 'ext' : null));
  if ($level) {
    $have = array_flip(array_map(fn($c) => implode(' ', $c['steps']), $cands));
    foreach (catalogCandidates($level, $http) as $c) {
      if (!isset($have[implode(' ', $c['steps'])])) {
        $cands[] = $c;
      }
    }
  }
  // То, что уже работало: совпавшие с набором стратегии помечаем, остальные добавляем
  $own = in_array('hist', $job['sets'], true);
  $byKey = [];
  foreach ($cands as $i => $c) {
    $byKey[implode(' ', $c['steps'])] = $i;
  }
  foreach (picksCandidates($job['host'], $status['proto'], $own, in_array('other', $job['sets'], true)) as $hc) {
    $key = implode(' ', $hc['steps']);
    if (isset($byKey[$key])) {
      if ($hc['rank'] === 0) {
        $cands[$byKey[$key]] += ['hist' => $hc['hist'], 'rank' => 0];
      }
      continue;
    }
    // стратегия из истории, которая стоит и в конфиге, — показываем, в каком она профиле
    foreach (configCandidates($status['proto']) as $cc) {
      if (implode(' ', $cc['steps']) === $key) {
        $hc['from'] = $cc['from'];
      }
    }
    $byKey[$key] = count($cands);
    $cands[] = $hc;
  }
  // Что сработало у абонентов того же провайдера; совпавшее с уже взятым — только отметка
  if (in_array('community', $job['sets'], true)) {
    foreach (communityCandidates($job['host'], $status['proto'], $info) as $cc) {
      $key = implode(' ', $cc['steps']);
      if (isset($byKey[$key])) {
        $cands[$byKey[$key]]['community'] = $cc['community'];
        $cands[$byKey[$key]]['rank'] = min($cands[$byKey[$key]]['rank'] ?? 2, $cc['rank']);
        continue;
      }
      $byKey[$key] = count($cands);
      $cands[] = $cc;
    }
    $status['community'] = $info;
  }
  // Что нашла проба «Обрыв на 16 КБ»: в каталоге и стандартном наборе фейков с именем нет, а обрыв снимают только они
  // — поэтому берём их только вместе со стандартным набором или каталогом (выбран один конфиг — только конфиг)
  foreach ($http || $quic || !array_intersect(['std', 'ext', 'full'], $job['sets']) ? [] : freezeCandidates($job['host']) as $fc) {
    $key = implode(' ', $fc['steps']);
    if (isset($byKey[$key])) {
      $cands[$byKey[$key]]['rank'] = min($cands[$byKey[$key]]['rank'] ?? 2, $fc['rank']);
      continue;
    }
    $byKey[$key] = count($cands);
    $cands[] = $fc;
  }
  // Функции, которых нет в подключённых lua-скриптах, пропускаем
  $funcs = luaCatalog()['functions'];
  $cands = array_values(array_filter($cands, function ($c) use ($funcs) {
    foreach ($c['steps'] as $t) {
      if (!isset($funcs[explode(':', substr($t, 13))[0]])) {
        return false;
      }
    }
    return true;
  }));
  if (!$cands) {
    $status['state'] = 'error';
    $status['title'] = 'Подбор не запущен';
    $status['error'] = $own && count($job['sets']) === 1 ? 'В истории нет стратегий, которые работали для этого сайта. Запустите обычный подбор.'
      : ($job['sets'] === ['community'] ? 'Стратегий сообщества для вашего провайдера нет'
        . (!empty($status['community']['error']) ? ': ' . $status['community']['error'] : (!empty($status['community']['asn']) ? ' (AS' . $status['community']['asn'] . ')' : '')) . '. Запустите обычный подбор.'
      : 'Нет ни одной стратегии для проверки.');
    testSaveStatus($status);
    return;
  }
  $filter = $http ? ['--filter-tcp=80', '--filter-l7=http', '--payload=http_req']
    : ($quic ? ['--filter-udp=443', '--filter-l7=quic', '--payload=quic_initial'] : ['--filter-tcp=443', '--filter-l7=tls', '--payload=tls_client_hello']);
  $repeats = max(1, min(5, (int)($job['repeats'] ?? 3)));
  $status['total'] = count($cands) + 1;
  $status['phase'] = 'baseline';
  testSaveStatus($status);

  // Без обхода — чтобы понять, заблокирован ли сайт вообще
  $status['current'] = 'без обхода';
  testSaveStatus($status);
  testRules('bypass', $iface);
  $status['baseline'] = $probe();
  $status['done'] = 1;
  if ($auto && $status['baseline']['ok']) {
    $cands = [];   // блокировки нет — перебирать нечего
  }
  $cands = orderCandidates($cands, $status['baseline']['reason'] ?? null);
  // работавшее для этого сайта — первым, затем помогавшее другим сайтам
  usort($cands, fn($a, $b) => ($a['rank'] ?? 2) <=> ($b['rank'] ?? 2));
  $status['phase'] = 'pick';
  testSaveStatus($status);

  testRules('queue', $iface);
  // Одна стратегия: отдельный nfqws2 и до $repeats запросов
  $try = function (array $steps) use (&$nfq, $baseArgs, $filter, $repeats, $probe): array {
    $nfq = testNfqwsStart(array_merge($baseArgs, $filter, $steps));
    if (!$nfq) {
      return ['ok' => 0, 'tries' => 0, 'ms' => null, 'reason' => 'nfqws2 не принял параметры: ' . trim((string)@file_get_contents(TEST_DIR . '/nfqws.out'))];
    }
    $ok = 0;
    $times = [];
    $reason = null;
    for ($i = 0; $i < $repeats; $i++) {
      $r = $probe();
      if ($r['ok']) {
        $ok++;
        $times[] = $r['ms'];
      } else {
        $reason = $r['reason'];
        if ($i === 0) {
          break;  // первая попытка не прошла — дальше не тратим время
        }
      }
    }
    testNfqwsStop($nfq);
    $nfq = null;
    return ['ok' => $ok, 'tries' => $i < $repeats ? $i + 1 : $repeats, 'ms' => $times ? (int)round(array_sum($times) / count($times)) : null, 'reason' => $reason];
  };
  $entry = fn(array $c, array $r) => ['name' => $c['name'], 'from' => $c['from'], 'steps' => $c['steps'], 'profile' => array_merge($filter, $c['steps'])]
    + (isset($c['hist']) ? ['hist' => $c['hist']] : []) + (isset($c['community']) ? ['community' => $c['community']] : []) + $r;
  if (!$auto && !$quic && count($cands) > PAR_MIN) {
    // большой набор — параллельно, пачками по PAR_SLOTS; из каталога в результатах остаётся только то, что хоть раз открыло
    // сайт (иначе статус разрастается до мегабайта), остальное — счётчиком причин
    $status['parallel'] = parSlots();
    $status['parallel_max'] = PAR_SLOTS;
    $status['failed'] = [];
    // работавшее раньше, находки пробы обрыва и сообщества — по одной и первыми: их мало, и именно они вероятнее всего
    // рабочие, а итог пачки ненадёжен (провайдер судит по адресу сайта в целом, соединения пачки влияют друг на друга:
    // king.hr — 19 из 275 «открыли» сайт в пачке и ни одна по одной)
    $solo = array_values(array_filter($cands, fn($c) => ($c['rank'] ?? 2) < 2));
    $rest = array_values(array_filter($cands, fn($c) => ($c['rank'] ?? 2) >= 2));
    foreach ($solo as $c) {
      if (is_file(TEST_DIR . '/stop')) {
        $status['state'] = 'stopped';
        break;
      }
      $status['current'] = $c['name'];
      testSaveStatus($status);
      $status['results'][] = $entry($c, $try($c['steps']));
      $status['done']++;
      testSaveStatus($status);
    }
    testRules('queue', $iface, PAR_SLOTS);
    $left = $status['state'] === 'running' ? $rest : [];
    for ($n = 0; $left; $n++) {
      if (is_file(TEST_DIR . '/stop')) {
        $status['state'] = 'stopped';
        break;
      }
      // размер пачки — по свободной памяти перед каждой: её съедают и другие программы роутера
      $slots = parSlots();
      $status['parallel'] = $slots;
      $chunk = array_splice($left, 0, $slots);
      $status['current'] = 'пачка ' . ($n + 1) . ' из ' . ($n + 1 + (int)ceil(count($left) / $slots)) . ': ' . implode(', ', array_slice(array_column($chunk, 'name'), 0, 3)) . (count($chunk) > 3 ? '…' : '');
      testSaveStatus($status);
      foreach (parTry($chunk, $nfq, $baseArgs, $filter, $repeats, $job['host'], $http) as $k => $r) {
        $c = $chunk[$k];
        if ($r['ok'] > 0 || empty($c['catalog'])) {
          $status['results'][] = $entry($c, $r) + ['par' => true];
        } else {
          $why = $r['reason'] ?? 'нет ответа';
          $status['failed'][$why] = ($status['failed'][$why] ?? 0) + 1;
        }
      }
      $status['done'] += count($chunk);
      testSaveStatus($status);
    }
    // перепроверка по одной, как в обычном подборе: итог пачки может и ошибаться. Идём от быстрых, пока не наберётся
    // PAR_CONFIRM подтверждённых (или не кончатся попытки); что открывало сайт только в пачке — рабочим не считается
    testRules('queue', $iface);
    $best = array_keys(array_filter($status['results'], fn($r) => !empty($r['par']) && $r['ok'] >= $repeats));
    usort($best, fn($a, $b) => ($status['results'][$a]['ms'] ?? PHP_INT_MAX) <=> ($status['results'][$b]['ms'] ?? PHP_INT_MAX));
    $good = 0;
    foreach ($best as $n => $i) {
      $r = &$status['results'][$i];
      if ($status['state'] === 'running' && $good < PAR_CONFIRM && $n < PAR_CONFIRM_TRIES && !is_file(TEST_DIR . '/stop')) {
        $status['phase'] = 'confirm';
        $status['current'] = 'перепроверка: ' . $r['name'];
        testSaveStatus($status);
        $r = array_merge($r, ['par_ok' => $r['ok']], $try($r['steps']), ['confirmed' => true]);
        $good += $r['ok'] === $repeats ? 1 : 0;
      } else {
        $r = array_merge($r, ['par_ok' => $r['ok'], 'ok' => 0, 'ms' => null, 'unsure' => true, 'reason' => 'открыла в пачке, по одной не проверялась']);
      }
      unset($r);
    }
    $status['phase'] = 'pick';
  } else {
    foreach ($cands as $c) {
      if (is_file(TEST_DIR . '/stop')) {
        $status['state'] = 'stopped';
        break;
      }
      $status['current'] = $c['name'];
      testSaveStatus($status);
      $status['results'][] = $entry($c, $try($c['steps']));
      $status['done']++;
      testSaveStatus($status);
      if ($auto && end($status['results'])['ok'] === $repeats) {
        break;   // автоподбору хватает первой стратегии, открывшей сайт каждый раз
      }
    }
  }
  if ($status['state'] === 'running' && !$quic && !($auto && !$cands)) {   // уточнение — оси TLS-фейков, к QUIC не подходит
    testRefine($status, $try, $repeats, $http, !empty($job['refine']), $filter);
  }
  testRules('off', $iface);
  if ($status['state'] === 'running') {
    $status['state'] = 'done';
  }
  $status['repeats'] = $repeats;
  $status['finished'] = time();
  $status['current'] = null;
  testSaveStatus($status);
  picksAdd($status, $repeats);
}
