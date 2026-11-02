<?php
// Server section: pick history, community strategies.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ----- история подборов: что и когда работало для каждого сайта -----

const PICKS_MAX = 100;       // запусков всего
const PICKS_PER_HOST = 10;   // и на один сайт
const PICKS_OTHER = 5;       // сколько чужих находок пробовать в подборе

function picksLoad(): array
{
  $f = UI_CONF_DIR . '/picks.json';
  if (is_file($f)) {
    $raw = (string)@file_get_contents($f);
    $d = json_decode($raw, true);
    if (!is_array($d) && strlen($raw) > 2) {
      @copy($f, "$f.bad");   // файл испорчен (обрыв питания при записи) — не затираем молча, оставляем для разбора
    }
    return is_array($d) ? $d : [];
  }
  // записи прежней короткой истории — без подробностей
  return json_decode((string)@file_get_contents(UI_CONF_DIR . '/tests.json'), true) ?: [];
}

function picksSave(array $items): void
{
  @mkdir(UI_CONF_DIR, 0755, true);
  $f = UI_CONF_DIR . '/picks.json';
  $json = json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    return;
  }
  putSafe($f, $json);
}

function picksAdd(array $status, int $repeats): void
{
  $full = array_values(array_filter($status['results'], fn($r) => $r['ok'] === $repeats));
  usort($full, fn($a, $b) => ($a['ms'] ?? PHP_INT_MAX) <=> ($b['ms'] ?? PHP_INT_MAX));
  $rec = ['ts' => time(), 'host' => $status['host'], 'proto' => $status['proto'], 'baseline' => $status['baseline'] ?? null,
    // после параллельного прогона в results только то, что хоть раз открыло сайт, — сколько проверено, считаем по done
    'ok' => count($full), 'total' => !empty($status['parallel']) ? max(count($status['results']), $status['done'] - 1) : count($status['results']), 'best' => $full ? $full[0]['name'] : null,
    'dur' => time() - $status['started'], 'state' => $status['state'], 'repeats' => $repeats, 'auto' => !empty($status['auto']),
    'tls12' => !empty($status['tls12']),   // checked the way old devices connect (TLS 1.2)
    // параметры храним только у того, что открыло сайт хоть раз: остальное заново не понадобится
    'results' => array_map(fn($r) => array_filter(['name' => $r['name'], 'from' => $r['from'], 'ok' => $r['ok'], 'tries' => $r['tries'], 'ms' => $r['ms'],
      'reason' => $r['ok'] === $r['tries'] ? null : ($r['reason'] ?? null), 'steps' => $r['ok'] > 0 ? $r['steps'] : null, 'refined' => $r['refined'] ?? null],
      fn($v) => $v !== null), $status['results'])];
  $out = [$rec];
  $n = 1;
  foreach (picksLoad() as $e) {
    if ($e['host'] === $rec['host'] && ++$n > PICKS_PER_HOST) {
      continue;
    }
    $out[] = $e;
  }
  picksSave(array_slice($out, 0, PICKS_MAX));
}

// Стратегии из истории для подбора: работавшие для этого сайта (rank 0) и помогавшие другим (rank 1)
function picksCandidates(string $host, string $proto, bool $own, bool $other): array
{
  $mine = [];
  $rest = [];
  foreach (picksLoad() as $e) {
    if (($e['proto'] ?? 'https') !== $proto || !empty($e['baseline']['ok'])) {
      continue;
    }
    foreach ($e['results'] ?? [] as $r) {
      if (empty($r['steps']) || $r['ok'] < ($e['repeats'] ?? 1)) {
        continue;
      }
      $key = implode(' ', $r['steps']);
      if ($e['host'] === $host) {
        $mine[$key] ??= ['name' => $r['name'], 'from' => 'история подборов', 'steps' => $r['steps'], 'hist' => $e['ts'], 'rank' => 0];
      } else {
        $rest[$key] ??= ['name' => $r['name'], 'from' => 'история: помогла ' . $e['host'], 'steps' => $r['steps'], 'rank' => 1];
      }
    }
  }
  $rest = array_slice(array_diff_key($rest, $mine), 0, PICKS_OTHER);
  return array_merge($own ? array_values($mine) : [], $other ? array_values($rest) : []);
}

// ----- стратегии сообщества: что сработало у абонентов того же провайдера -----
// База — отдельный репозиторий: файл на провайдера data/AS<номер>.json и сводка index.json; пополняется
// формой issue (кнопка «Поделиться»), присланное проверяет GitHub Action. Роутер качает только файл своего
// провайдера (килобайты при любом размере базы); всё из него сначала проверяется подбором.

const COMMUNITY_REPO = 'https://github.com/zemidala/nfqws2-strategies';
const COMMUNITY_RAW = 'https://raw.githubusercontent.com/zemidala/nfqws2-strategies/main/';
const COMMUNITY_FILE = '/tmp/nfqws-ui-community.json';   // в памяти: скачивается заново после перезагрузки
const COMMUNITY_INDEX = '/tmp/nfqws-ui-community-index.json';
const COMMUNITY_MAX = 8;   // сколько стратегий сообщества пробовать в одном подборе
// шаг — только --lua-desync без путей и блобов из файлов; то же проверяет и приём в репозитории
const COMMUNITY_STEP = '/^--lua-desync=[a-z][a-z0-9_]{0,40}(:[A-Za-z0-9_.,=+%-]{1,200}){0,40}$/';

// Файл из репозитория базы: [код, тело, ошибка]. Соединение не состоялось (чаще всего — DNS роутера
// на секунду пропал при перезапуске AdGuard) — ещё одна попытка через 3 секунды.
function communityFetch(string $path): array
{
  for ($try = 0; $try < 2; $try++) {
    if ($try) {
      sleep(3);
    }
    $ch = curl_init(COMMUNITY_RAW . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_NOSIGNAL => 1,
      CURLOPT_HTTPHEADER => ['User-Agent: nfqws2-ui/' . UI_VERSION]]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($code !== 0) {
      break;
    }
  }
  return [$code, (string)$res, $err];
}

// Стратегии своего провайдера: удачный ответ помним сутки, неудачу — 10 минут (как проверку обновлений).
// Файла провайдера нет (404) — значит, для него в базе пока ничего нет; это не ошибка.
function communityLoad(int $asn, bool $force = false): array
{
  $c = json_decode((string)@file_get_contents(COMMUNITY_FILE), true);
  $c = is_array($c) && ($c['asn'] ?? 0) === $asn ? $c + ['fetched' => 0, 'error' => null, 'items' => []] : ['asn' => $asn, 'fetched' => 0, 'error' => null, 'items' => [], 'checked' => 0];
  if (!$force && time() - ($c['checked'] ?? 0) < ($c['error'] ? 600 : 86400)) {
    return $c;
  }
  [$code, $res, $err] = communityFetch("data/AS$asn.json");
  $j = json_decode($res, true);
  $c['checked'] = time();
  if ($code === 404) {
    $c = ['asn' => $asn, 'checked' => time(), 'fetched' => time(), 'error' => null, 'items' => []];
  } elseif ($code === 200 && is_array($j['items'] ?? null)) {
    // данные из интернета: берём только то, что подходит по формату
    $items = [];
    foreach ($j['items'] as $x) {
      $steps = is_array($x['steps'] ?? null) ? array_values(array_filter($x['steps'], fn($t) => is_string($t) && preg_match(COMMUNITY_STEP, $t))) : [];
      if (!$steps || count($steps) !== count($x['steps']) || count($steps) > 8 || ($x['asn'] ?? null) !== $asn || !in_array($x['proto'] ?? '', ['tls', 'http'], true)) {
        continue;
      }
      $targets = [];
      foreach (array_slice(is_array($x['targets'] ?? null) ? $x['targets'] : [], 0, 30) as $t) {
        $h = is_string($t['host'] ?? null) && validHost($t['host']) && !isIp($t['host']) ? $t['host'] : null;
        $a = is_int($t['asn'] ?? null) ? $t['asn'] : null;
        if ($h || $a) {
          $targets[] = array_filter(['host' => $h, 'asn' => $a]);
        }
      }
      $str = fn($k, $n) => is_string($x[$k] ?? null) ? (preg_match('/^[^\x00-\x1f<>`$\\\\]{0,' . $n . '}/u', $x[$k], $m) ? $m[0] : '') : '';
      $items[] = ['id' => preg_replace('/[^0-9a-f]/', '', $str('id', 16)), 'asn' => $x['asn'], 'provider' => $str('provider', 80), 'proto' => $x['proto'],
        'steps' => $steps, 'targets' => $targets, 'reports' => max(0, (int)($x['reports'] ?? 1)), 'fails' => max(0, (int)($x['fails'] ?? 0)), 'last_fail' => $str('last_fail', 10),
        'issues' => array_values(array_filter(array_slice(is_array($x['issues'] ?? null) ? $x['issues'] : [], 0, 20), 'is_int')),
        'first' => $str('first', 10), 'last' => $str('last', 10), 'nfqws2' => $str('nfqws2', 30)];
    }
    $c = ['asn' => $asn, 'checked' => time(), 'fetched' => time(), 'error' => null, 'items' => $items];
  } else {
    $c['error'] = $err ?: "GitHub ответил $code";
  }
  @file_put_contents(COMMUNITY_FILE, json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  return $c;
}

// Сводка по всей базе — только для строки «во всей базе N стратегий для M провайдеров»; не удалась — без неё
function communityIndex(bool $force = false): ?array
{
  $c = json_decode((string)@file_get_contents(COMMUNITY_INDEX), true);
  if (!$force && is_array($c) && time() - ($c['checked'] ?? 0) < 86400) {
    return $c;
  }
  [$code, $res] = communityFetch('index.json');
  $j = json_decode($res, true);
  if ($code !== 200 || !is_array($j['providers'] ?? null)) {
    return is_array($c) ? $c : null;
  }
  $c = ['checked' => time(), 'total' => max(0, (int)($j['total'] ?? 0)), 'providers' => count($j['providers'])];
  @file_put_contents(COMMUNITY_INDEX, json_encode($c));
  return $c;
}

// Провайдер по внешнему адресу (RIPEstat); сам адрес никуда не сохраняется и не показывается
function providerDetect(): ?array
{
  $ip = ripe('whats-my-ip')['ip'] ?? null;
  $asn = $ip ? (int)(ripe('network-info', $ip)['asns'][0] ?? 0) : 0;
  if (!$asn) {
    return null;
  }
  $holder = (string)(ripe('as-overview', "AS$asn")['holder'] ?? '');
  $s = uiSettings();
  $s['provider_asn'] = $asn;
  if ($s['provider'] === '' && $holder !== '') {
    $s['provider'] = preg_match('/^[^\x00-\x1f"`$\\\\]{0,80}/u', $holder, $m) ? $m[0] : '';
  }
  saveUiSettings($s);
  return ['provider' => $holder, 'asn' => $asn];
}

// AS провайдера: из настроек; $detect — если не задан, определить (запрос к RIPEstat)
function myAsn(bool $detect): int
{
  static $tried = false;   // a failed detection is not repeated within one request or job (two RIPEstat calls each)
  $asn = (int)uiSettings()['provider_asn'];
  if (!$asn && $detect && !$tried) {
    $tried = true;
    $asn = (int)(providerDetect()['asn'] ?? 0);
  }
  return $asn;
}

// Сеть сайта: по первому адресу; адрес podkop (FakeIP) или заглушка — не определить
function hostAsn(string $host): ?int
{
  $ip = diagDns($host)['ips'][0] ?? null;
  if (!$ip || (isIp4($ip) && ipReserved($ip))) {
    return null;
  }
  $a = (int)(ripe('network-info', $ip)['asns'][0] ?? 0);
  return $a ?: null;
}

// Стратегии сообщества для подбора: сначала работавшие для этого сайта, затем для его сети, затем — по числу подтверждений
function communityCandidates(string $host, string $proto, ?array &$info): array
{
  $asn = myAsn(true);
  $d = $asn ? communityLoad($asn) : ['items' => [], 'error' => null];
  $p = $proto === 'http' ? 'http' : 'tls';
  $mine = array_filter($d['items'], fn($x) => $x['asn'] === $asn && $x['proto'] === $p);
  $tAsn = $mine ? hostAsn($host) : null;
  $out = [];
  foreach ($mine as $x) {
    // «не сработала» у большинства (и хотя бы у двоих) — не пробуем; на странице сообщества она видна
    if ($x['fails'] >= 2 && $x['fails'] > $x['reports']) {
      continue;
    }
    $hosts = array_column($x['targets'], 'host');
    $nets = array_column($x['targets'], 'asn');
    $score = in_array($host, $hosts, true) ? 2 : ($tAsn && in_array($tAsn, $nets, true) ? 1 : 0);
    $fresh = communityFresh($x) ? 1 : 0;
    $out[] = ['name' => strategyName($x['steps']), 'steps' => $x['steps'], 'community' => $x['id'], 'rank' => $score && $fresh ? 1 : 2,
      'key' => [$fresh, $score, $x['reports'] - $x['fails'], $x['last']],
      'from' => 'сообщество: ' . ($score === 2 ? "открывала $host" : ($score === 1 ? "работала в сети AS$tAsn" : "AS$asn")) . ', подтвердили ' . $x['reports']
        . ($x['fails'] ? ', не сработала у ' . $x['fails'] : '') . ($fresh ? '' : ', давно не подтверждалась')];
  }
  // сначала подтверждённые недавно; среди них — тот же сайт, затем та же сеть, затем по «работает» минус «не сработала».
  // В подборе свежие с совпадением сайта или сети идут вместе с историей (rank 1), остальные — со стандартным набором
  usort($out, fn($a, $b) => $b['key'] <=> $a['key']);
  $info = ['asn' => $asn, 'count' => count($mine), 'error' => $asn ? $d['error'] : 'провайдер не определён'];
  return array_map(fn($c) => array_diff_key($c, ['key' => 1]), array_slice($out, 0, COMMUNITY_MAX));
}

// Подтверждалась ли стратегия за последние COMMUNITY_FRESH_DAYS дней: DPI меняется, старое опускаем
const COMMUNITY_FRESH_DAYS = 60;
function communityFresh(array $x): bool
{
  $t = strtotime((string)$x['last']);
  return $t !== false && time() - $t < COMMUNITY_FRESH_DAYS * 86400;
}
