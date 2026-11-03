<?php
// Server section: sharing strategies with the community base without a GitHub account.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// The router sends to a relay (zemidala/nfqws2-strategies, relay/): it keeps the submission in the base's «inbox»
// branch, the base's Action checks it. A strategy from one router waits; a second router makes it live.
// What goes: provider AS, protocol, strategy, the site's network (AS); the site's name only if it is in the base's
// known.txt (widely known sites). This installation's random number goes to the relay only, the base sees a hash.
// The relay does not answer — «Поделиться» offers the GitHub form, as before.
// /tmp/nfqws-ui-relay (root only) — another address for a check before a release: a test relay or a local one
define('COMMUNITY_RELAY', is_file('/tmp/nfqws-ui-relay') ? rtrim(trim((string)file_get_contents('/tmp/nfqws-ui-relay')), '/') : 'https://nfqws2-strategies.zemidala.workers.dev');
define('SHARE_STATE', UI_CONF_DIR . '/share.json');   // queue for «help the base», first-seen days, the last send
const SHARE_KNOWN_FILE = '/tmp/nfqws-ui-known.json';
const SHARE_STATS_FILE = '/tmp/nfqws-ui-share-stats.json';
const SHARE_LEARN_OK = 5;        // a learned strategy goes to the base after 5 successes,
const SHARE_LEARN_SCORE = 0.8;   // at least 80 % sure, seen so on two different days
const SHARE_DAILY_MAX = 10;      // records per day (the base takes 30 from one installation)

function shareRelay(): bool
{
  return COMMUNITY_RELAY !== '';
}

// this installation's number: random, made once
function shareId(): string
{
  $s = uiSettings();
  if (!preg_match('/^[0-9a-f]{32}$/', (string)$s['share']['id'])) {
    $s['share']['id'] = bin2hex(random_bytes(16));
    saveUiSettings($s);
  }
  return $s['share']['id'];
}

// the base's known.txt: names of sites that may go with a strategy (and their subdomains); a day in /tmp
function shareKnown(): array
{
  $c = json_decode((string)@file_get_contents(SHARE_KNOWN_FILE), true);
  if (is_array($c) && time() - ($c['checked'] ?? 0) < 86400) {
    return $c['names'];
  }
  [$code, $res] = communityFetch('known.txt');
  if ($code !== 200) {
    return is_array($c) ? $c['names'] : [];
  }
  $names = [];
  foreach (explode("\n", $res) as $l) {
    $l = strtolower(trim($l));
    if ($l !== '' && $l[0] !== '#' && validHost($l)) {
      $names[] = $l;
    }
  }
  @file_put_contents(SHARE_KNOWN_FILE, json_encode(['checked' => time(), 'names' => $names]));
  return $names;
}

function shareHostKnown(string $host): bool
{
  foreach (shareKnown() as $k) {
    if ($host === $k || str_ends_with($host, ".$k")) {
      return true;
    }
  }
  return false;
}

// One record as the relay takes it; null — the provider is unknown (nothing to file it under)
function shareItem(string $kind, array $steps, string $proto, string $host, ?int $net = null): ?array
{
  $asn = myAsn(true);
  if (!$asn || !in_array($proto, ['tls', 'http'], true)) {
    return null;
  }
  $host = validHost($host) && !isIp($host) ? strtolower($host) : '';
  return ['kind' => $kind, 'asn' => $asn, 'provider' => uiSettings()['provider'], 'proto' => $proto, 'steps' => array_values($steps),
    'net' => $net ?? ($host ? hostAsn($host) : null), 'host' => $host && shareHostKnown($host) ? $host : '',
    'nfqws2' => (string)packageVersion('nfqws2-keenetic'), 'ui' => UI_VERSION];
}

// for a command: an error goes to the page
function shareSend(array $items): array
{
  if (!shareRelay()) {
    fail('Приёмник базы ещё не настроен — отправьте через форму на GitHub');
  }
  $r = shareSendRaw($items);
  if (isset($r['error'])) {
    fail($r['error']);
  }
  return $r;
}

// POST to the relay: {queued} or {error} (never stops the request: the daily pass goes on). The answer has «me» —
// this installation's public hash, kept for the counter
function shareSendRaw(array $items): array
{
  $s = uiSettings();
  $ch = curl_init(COMMUNITY_RELAY . '/v1/submit');
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_NOSIGNAL => 1,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: nfqws2-ui/' . UI_VERSION],
    CURLOPT_POSTFIELDS => json_encode(['id' => shareId(), 'name' => (string)$s['share']['name'], 'items' => array_values($items)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
  $res = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  $j = json_decode((string)$res, true);
  if ($code !== 200 || empty($j['ok'])) {
    return ['error' => 'Приёмник базы не ответил: ' . ($err ?: (is_array($j) && isset($j['error']) ? (string)$j['error'] : "код $code"))];
  }
  if (is_string($j['me'] ?? null) && preg_match('/^r[0-9a-f]{12}$/', $j['me']) && $j['me'] !== uiSettings()['share']['me']) {
    $s = uiSettings();
    $s['share']['me'] = $j['me'];
    saveUiSettings($s);
    @unlink(SHARE_STATS_FILE);
  }
  return ['queued' => (int)($j['queued'] ?? 0)];
}

// The counter: stats/<me>.json of the base — how many strategies of this router are live and waiting, how many
// routers confirmed them, at how many providers. Six hours in /tmp
function shareStats(bool $force = false): ?array
{
  $me = (string)uiSettings()['share']['me'];
  if (!preg_match('/^r[0-9a-f]{12}$/', $me)) {
    return null;
  }
  $c = json_decode((string)@file_get_contents(SHARE_STATS_FILE), true);
  if (!$force && is_array($c) && ($c['me'] ?? '') === $me && time() - ($c['checked'] ?? 0) < 21600) {
    return $c['stats'];
  }
  [$code, $res] = communityFetch("stats/$me.json");
  $j = json_decode($res, true);
  $stats = $code === 200 && is_array($j) ? ['live' => (int)($j['live'] ?? 0), 'waiting' => (int)($j['waiting'] ?? 0),
    'confirmed' => (int)($j['confirmed'] ?? 0), 'providers' => (int)($j['providers'] ?? 0)] : ($code === 404 ? ['live' => 0, 'waiting' => 0, 'confirmed' => 0, 'providers' => 0] : null);
  if ($stats === null) {
    return is_array($c) ? $c['stats'] : null;
  }
  @file_put_contents(SHARE_STATS_FILE, json_encode(['me' => $me, 'checked' => time(), 'stats' => $stats]));
  return $stats;
}

function shareState(): array
{
  $s = json_decode((string)@file_get_contents(SHARE_STATE), true);
  return (is_array($s) ? $s : []) + ['seen' => [], 'queue' => [], 'last' => null];
}

function shareStateSave(array $s): void
{
  putSafe(SHARE_STATE, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// after a pick: strategies from the base that opened the site every time — «works» marks for the next daily send
function shareQueueWorks(array $status): void
{
  if (!shareRelay() || !uiSettings()['share']['auto']) {
    return;
  }
  $proto = ($status['proto'] ?? '') === 'http' ? 'http' : 'tls';
  $st = shareState();
  foreach ($status['results'] ?? [] as $x) {
    if (!empty($x['community']) && ($x['ok'] ?? 0) > 0 && $x['ok'] === ($x['tries'] ?? $x['ok']) && !empty($x['steps'])) {
      $st['queue'][] = ['kind' => 'works', 'steps' => $x['steps'], 'proto' => $proto, 'host' => (string)($status['host'] ?? '')];
    }
  }
  $st['queue'] = array_slice($st['queue'], -SHARE_DAILY_MAX);
  shareStateSave($st);
}

// Learned well enough to share: 5+ successes, 80 %+ sure, on two different days (first seen so on an earlier day).
// The protocol — from the profile's --filter-l7: a profile for tls only or http only
function shareLearned(array &$st): array
{
  $owners = [];
  foreach (learnOwners() as $o) {
    $owners[$o['sig']] = $o;
  }
  $profiles = [];
  foreach (currentProfiles() as $p) {
    $profiles[$p['index']] = $p['args'];
  }
  $today = ldate('Y-m-d');
  $now = time();
  $out = [];
  $seen = [];
  foreach (learnRead() as $r) {
    $o = $owners[$r['sig']] ?? null;
    $s = $r['stats'][$r['cur']] ?? null;
    if (!$o || !$s || empty($o['steps'][$r['cur']])) {
      continue;
    }
    $k = 0.5 ** (max(0, $now - $s['t']) / LEARN_HALF_PHP);
    $ok = $s['ok'] * $k;
    $score = ($ok + 1) / ($ok + $s['fail'] * $k + 2);
    if ($ok < SHARE_LEARN_OK || $score < SHARE_LEARN_SCORE) {
      continue;
    }
    $l7 = '';
    foreach ($profiles[$o['profile']] ?? [] as $t) {
      if (str_starts_with($t, '--filter-l7=')) {
        $l7 = substr($t, 12);
      }
    }
    $proto = $l7 === 'tls' ? 'tls' : ($l7 === 'http' ? 'http' : null);
    if (!$proto) {
      continue;
    }
    $key = $r['host'] . '|' . $r['sig'] . '|' . $r['cur'];
    $seen[$key] = $st['seen'][$key] ?? $today;
    if ($seen[$key] < $today && !isset($st['sent'][$key])) {
      $out[$key] = ['kind' => 'share', 'steps' => $o['steps'][$r['cur']], 'proto' => $proto, 'host' => $r['host']];
    }
  }
  $st['seen'] = $seen;   // what stopped qualifying starts its two days again
  return $out;
}

// Daily, only with «help the base»: learned strategies and the queued «works» marks, at most SHARE_DAILY_MAX
function shareDaily(): void
{
  if (!shareRelay() || !uiSettings()['share']['auto']) {
    return;
  }
  $st = shareState();
  $st['sent'] ??= [];
  $learned = shareLearned($st);
  $items = [];
  $keys = [];
  foreach (array_merge(array_slice($learned, 0, SHARE_DAILY_MAX, true), $st['queue']) as $key => $x) {
    if (count($items) >= SHARE_DAILY_MAX) {
      break;
    }
    $it = shareItem($x['kind'], $x['steps'], $x['proto'], $x['host']);
    if ($it) {
      $items[] = $it;
      if (is_string($key)) {
        $keys[] = $key;
      }
    }
  }
  if (!$items) {
    shareStateSave($st);
    return;
  }
  $r = shareSendRaw($items);
  if (isset($r['error'])) {
    $st['last'] = ['t' => time(), 'error' => $r['error']];   // the queue stays for tomorrow
  } else {
    foreach ($keys as $k) {
      $st['sent'][$k] = ldate('Y-m-d');   // a learned strategy goes once; a new one for the same site — again
    }
    $st['queue'] = [];
    $st['last'] = ['t' => time(), 'learned' => count($keys), 'works' => count($items) - count($keys)];
  }
  shareStateSave($st);
}
