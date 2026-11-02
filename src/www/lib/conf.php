<?php
// Server section: the config file, ports, profiles.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ================= конфиг =================

function tokens(string $s): array
{
  // «\» в конце строки — перенос значения по строкам, оболочка его убирает; параметром он не является.
  // «\» с пробелами после него оболочка отдаёт nfqws2 отдельным аргументом «\» — он ничего не значит, и для
  // интерфейса это тоже перенос (иначе «\» попадал в пересобранные профили и сохранение отказывало)
  $s = trim(preg_replace('/\\\\[ \t]*\r?\n/', ' ', $s));
  return $s === '' ? [] : preg_split('/\s+/', $s);
}

// Значения переменных так, как их видит init-скрипт (через sh)
function confValues(string $file = CONF_FILE): array
{
  $names = array_merge(CONF_VARS, ['MODE_AUTO', 'NFQUEUE_NUM', 'USER']);
  $cmd = '. ' . escapeshellarg($file) . ' >/dev/null 2>&1; for v in '
    . implode(' ', array_map(fn($n) => "\"\$$n\"", $names)) . "; do printf '%s\\001' \"\$v\"; done";
  $vals = explode("\x01", shell_exec('sh -c ' . escapeshellarg($cmd)) ?? '');
  $r = [];
  foreach ($names as $i => $n) {
    $r[$n] = $vals[$i] ?? '';
  }
  return $r;
}

// Names the profiles pass as values (blob=…, seqovl_pattern=…) — to tell a declared blob that nothing uses.
// From the values the shell gives (several assignments and $VAR inside are joined, comments outside the quotes are gone);
// a comment inside the quotes — from «#» to the end of its line — does not count
function blobsUsed(): array
{
  $exp = confValues();
  $used = [];
  foreach (ARG_VARS as $v) {
    if ($v === 'NFQWS_BASE_ARGS') {
      continue;
    }
    foreach (explode("\n", $exp[$v] ?? '') as $line) {
      foreach (tokens($line) as $t) {
        if ($t[0] === '#') {
          break;
        }
        preg_match_all('/=([A-Za-z0-9_.-]+)/', $t, $m);
        foreach ($m[1] as $name) {
          $used[$name] = true;
        }
      }
    }
  }
  return array_map('strval', array_keys($used));   // PHP turns numeric keys into numbers
}

// Сырые (не раскрытые) значения из текста конфига: NAME="..." (многострочно) или NAME=value
function confRawVars(string $text): array
{
  $r = [];
  preg_match_all('/^([A-Z_][A-Z0-9_]*)=("([^"]*)"|[^\n]*)/m', $text, $m, PREG_SET_ORDER);
  foreach ($m as $x) {
    $r[$x[1]] = isset($x[3]) && $x[2] !== '' && $x[2][0] === '"' ? $x[3] : trim($x[2]);
  }
  return $r;
}

// «Оформить» в «Конфиге целиком»: меняются только пробелы и переносы. У многострочных значений в кавычках —
// параметр на строку, выравнивание под кавычку, пустая строка перед --new; строка с комментарием «#» внутри
// кавычек остаётся целой (комментарий — до конца строки). Вне кавычек — без пробелов в конце строк и без
// лишних пустых строк. Если аргументы nfqws2 после этого хоть в чём-то другие — отказ (null).
function confFormat(string $text): ?string
{
  $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $text);
  $out = preg_replace_callback('/^([A-Z_][A-Z0-9_]*)="([^"]*)"/m', function ($m) {
    $val = $m[2];
    if (!str_contains($val, "\n") || trim($val) === '') {
      return $m[0];
    }
    $items = [];
    foreach (explode("\n", preg_replace('/\\\\[ \t]*\n/', "\n", $val)) as $line) {
      $line = trim($line);
      $code = $line;
      $comment = null;
      if (preg_match('/(^|\s)(#.*)$/', $line, $c, PREG_OFFSET_CAPTURE)) {
        $code = trim(substr($line, 0, $c[2][1]));
        $comment = $c[2][0];
      }
      foreach ($code === '' ? [] : preg_split('/\s+/', $code) as $t) {
        $items[] = $t;
      }
      if ($comment !== null) {
        $items[] = $comment;
      }
    }
    $pad = str_repeat(' ', strlen($m[1]) + 2);
    $lines = [];
    foreach ($items as $t) {
      if ($lines && isNewArg($t)) {
        $lines[] = '';
      }
      $lines[] = $t;
    }
    $body = '';
    foreach ($lines as $i => $l) {
      $body .= $i === 0 ? $l : "\n" . ($l === '' ? '' : $pad . $l);
    }
    return $m[1] . '="' . $body . '"';
  }, $text);
  $out = preg_replace('/[ ]+$/m', '', $out);
  $out = preg_replace("/\n{3,}/", "\n\n", trim($out, "\n")) . "\n";
  // проверка: те же присваивания в том же порядке (переменная может быть присвоена и дважды),
  // у каждого — те же аргументы; вне присваиваний — те же строки, кроме пустых
  $sig = function (string $s): array {
    preg_match_all('/^([A-Z_][A-Z0-9_]*)=("([^"]*)"|[^\n]*)/m', $s, $m, PREG_SET_ORDER);
    $r = array_map(fn($x) => [$x[1], tokens(isset($x[3]) && ($x[2][0] ?? '') === '"' ? $x[3] : $x[2])], $m);
    $rest = preg_replace('/^[A-Z_][A-Z0-9_]*=("[^"]*"|[^\n]*)/m', '', $s);
    $r[] = array_values(array_filter(array_map('rtrim', explode("\n", $rest)), fn($l) => $l !== ''));
    return $r;
  };
  return $sig($text) === $sig($out) ? $out : null;
}

function confSetVar(string $text, string $name, string $value): string
{
  $quoted = preg_match('/^[A-Za-z0-9_.:,\/-]*$/', $value) && !str_contains($value, "\n") && $value !== ''
    && in_array($name, ['TCP_PORTS', 'UDP_PORTS', 'IPV6_ENABLED', 'LOG_LEVEL'], true)
    ? $value : '"' . $value . '"';
  $re = '/^' . $name . '=("[^"]*"|[^\n]*)/m';
  if (preg_match($re, $text)) {
    return preg_replace_callback($re, fn() => "$name=$quoted", $text, 1);
  }
  return rtrim($text, "\n") . "\n$name=$quoted\n";
}

// --new или --new=имя — начало следующего профиля. Имя из --new=имя переносится в профиль как --name=имя:
// для nfqws2 это одно и то же, а профиль несёт своё имя с собой (в js/core/data.js — splitParts, так же)
function isNewArg(string $a): bool
{
  return $a === '--new' || str_starts_with($a, '--new=');
}

function splitNew(array $args): array
{
  $parts = [[]];
  foreach ($args as $a) {
    if (isNewArg($a)) {
      $parts[] = strlen($a) > 6 ? ['--name=' . substr($a, 6)] : [];
    } else {
      $parts[count($parts) - 1][] = $a;
    }
  }
  return $parts;
}

function optName(string $arg): string
{
  return preg_replace('/^--([^=]+).*$/', '$1', $arg);
}

const WIN_TS_HINT = 'Включить в Windows — командная строка от имени администратора: netsh interface tcp set global timestamps=enabled';

// Шаг --lua-desync, у которого из маскировок (fooling) только tcp_ts=N
function tsOnlyFooling(string $step): bool
{
  $parts = explode(':', preg_replace('/^--lua-desync=/', '', $step));
  $ts = false;
  foreach ($parts as $p) {
    if (preg_match('/^(tcp_md5|badsum|tcp_seq=|tcp_ack=|ip_ttl=|ip6_ttl=|ip_autottl=|ip6_autottl=|tcp_flags_(set|unset)=|ip6_(hopbyhop|destopt|routing|ah)|fool=)/', $p)) {
      return false;
    }
    $ts = $ts || str_starts_with($p, 'tcp_ts=');
  }
  return $ts;
}

// Повторяет _startup_args() init-скрипта
function expectedProfiles(array $raw): array
{
  $v = array_map('tokens', $raw);
  $exp = [];
  if ($v['NFQWS_ARGS_CUSTOM']) {
    foreach (splitNew($v['NFQWS_ARGS_CUSTOM']) as $k => $p) {
      $exp[] = ['source' => 'NFQWS_ARGS_CUSTOM', 'part' => $k + 1, 'tokens' => $p];
    }
  }
  if ($v['NFQWS_ARGS_UDP']) {
    $exp[] = ['source' => 'NFQWS_ARGS_UDP', 'tokens' => $v['NFQWS_ARGS_UDP']];
  }
  if ($v['NFQWS_ARGS_QUIC']) {
    if ($v['NFQWS_ARGS_IPSET']) {
      $exp[] = ['source' => 'NFQWS_ARGS_QUIC', 'with' => 'NFQWS_ARGS_IPSET',
        'tokens' => array_merge($v['NFQWS_ARGS_QUIC'], $v['NFQWS_ARGS_IPSET'], ['--ipset-ip=0.0.0.0'])];
    }
    $exp[] = ['source' => 'NFQWS_ARGS_QUIC', 'with' => 'NFQWS_EXTRA_ARGS', 'tokens' => array_merge($v['NFQWS_ARGS_QUIC'], $v['NFQWS_EXTRA_ARGS'])];
  }
  if ($v['NFQWS_ARGS_IPSET']) {
    $exp[] = ['source' => 'NFQWS_ARGS', 'with' => 'NFQWS_ARGS_IPSET',
      'tokens' => array_merge($v['NFQWS_ARGS'], $v['NFQWS_ARGS_IPSET'], ['--ipset-ip=0.0.0.0'])];
  }
  $exp[] = ['source' => 'NFQWS_ARGS', 'with' => 'NFQWS_EXTRA_ARGS', 'tokens' => array_merge($v['NFQWS_ARGS'], $v['NFQWS_EXTRA_ARGS'])];
  return $exp;
}

// ================= порты =================

function parsePorts(?string $s): ?array
{
  if ($s === null) {
    return null;
  }
  $r = [];
  foreach (explode(',', $s) as $p) {
    $p = trim($p);
    if ($p === '*') {
      $r[] = [0, 65535];
    } elseif (preg_match('/^(\d+)-(\d+)$/', $p, $m)) {
      $r[] = [(int)$m[1], (int)$m[2]];
    } elseif (preg_match('/^\d+$/', $p)) {
      $r[] = [(int)$p, (int)$p];
    }
  }
  return normRanges($r);
}

function normRanges(array $r): array
{
  usort($r, fn($a, $b) => $a[0] - $b[0]);
  $out = [];
  foreach ($r as $x) {
    $n = count($out);
    if ($n && $x[0] <= $out[$n - 1][1] + 1) {
      $out[$n - 1][1] = max($out[$n - 1][1], $x[1]);
    } else {
      $out[] = $x;
    }
  }
  return $out;
}

function intersectRanges(array $a, array $b): array
{
  $out = [];
  foreach ($a as $x) {
    foreach ($b as $y) {
      $lo = max($x[0], $y[0]);
      $hi = min($x[1], $y[1]);
      if ($lo <= $hi) {
        $out[] = [$lo, $hi];
      }
    }
  }
  return normRanges($out);
}

function subtractRanges(array $a, array $b): array
{
  foreach ($b as $y) {
    $next = [];
    foreach ($a as $x) {
      if ($y[1] < $x[0] || $y[0] > $x[1]) {
        $next[] = $x;
        continue;
      }
      if ($y[0] > $x[0]) {
        $next[] = [$x[0], $y[0] - 1];
      }
      if ($y[1] < $x[1]) {
        $next[] = [$y[1] + 1, $x[1]];
      }
    }
    $a = $next;
  }
  return $a;
}

function rangesStr(array $r): string
{
  return implode(',', array_map(fn($x) => $x[0] === $x[1] ? (string)$x[0] : "$x[0]-$x[1]", $r));
}

function inRanges(int $port, array $r): bool
{
  foreach ($r as $x) {
    if ($port >= $x[0] && $port <= $x[1]) {
      return true;
    }
  }
  return false;
}

// ================= профили =================

function buildProfile(int $index, array $args): array
{
  $p = ['index' => $index, 'args' => $args, 'tcp' => null, 'udp' => null, 'l7' => null,
    'hostlists' => [], 'hostlist_excludes' => [], 'hostlist_domains' => [], 'hostlist_exclude_domains' => [],
    'ipsets' => [], 'ipset_excludes' => [], 'ipset_ips' => [], 'ipset_exclude_ips' => [],
    'autolist' => null, 'payloads' => [], 'desync' => [], 'other' => [], 'name' => null];
  foreach ($args as $a) {
    $name = optName($a);
    $val = str_contains($a, '=') ? substr($a, strpos($a, '=') + 1) : '';
    switch ($name) {
      case 'filter-tcp': $p['tcp'] = parsePorts($val); break;
      case 'filter-udp': $p['udp'] = parsePorts($val); break;
      case 'filter-l7': $p['l7'] = explode(',', $val); break;
      case 'hostlist': $p['hostlists'][] = $val; break;
      case 'hostlist-exclude': $p['hostlist_excludes'][] = $val; break;
      case 'hostlist-domains': array_push($p['hostlist_domains'], ...explode(',', $val)); break;
      case 'hostlist-exclude-domains': array_push($p['hostlist_exclude_domains'], ...explode(',', $val)); break;
      case 'hostlist-auto': $p['autolist'] = $val; break;
      case 'ipset': $p['ipsets'][] = $val; break;
      case 'ipset-exclude': $p['ipset_excludes'][] = $val; break;
      case 'ipset-ip': array_push($p['ipset_ips'], ...explode(',', $val)); break;
      case 'ipset-exclude-ip': array_push($p['ipset_exclude_ips'], ...explode(',', $val)); break;
      case 'payload': $p['payloads'][] = $val; break;
      case 'name': $p['name'] = $val !== '' ? $val : null; break;
      case 'lua-desync':
        $fn = explode(':', $val, 2);
        $p['desync'][] = ['fn' => $fn[0], 'params' => $fn[1] ?? '', 'payload' => end($p['payloads']) ?: null];
        break;
      default: $p['other'][] = $a;
    }
  }
  $p['has_host_filter'] = $p['hostlists'] || $p['hostlist_domains'] || $p['autolist'];
  $p['has_ip_filter'] = $p['ipsets'] || $p['ipset_ips'];
  $p['has_excludes'] = $p['hostlist_excludes'] || $p['hostlist_exclude_domains'] || $p['ipset_excludes'] || $p['ipset_exclude_ips'];
  return $p;
}

function protoPorts(array $p): array
{
  if ($p['tcp'] === null && $p['udp'] === null) {
    return ['tcp' => [[0, 65535]], 'udp' => [[0, 65535]]];
  }
  return ['tcp' => $p['tcp'] ?? [], 'udp' => $p['udp'] ?? []];
}

// Какие порты профиля забирают более ранние профили без списков
function analyzeShadowing(array &$profiles): void
{
  foreach ($profiles as $j => &$pj) {
    $pp = protoPorts($pj);
    $remaining = $pp;
    $taken = [];
    for ($i = 0; $i < $j; $i++) {
      $pi = $profiles[$i];
      if ($pi['has_host_filter'] || $pi['has_ip_filter']) {
        continue;
      }
      if ($pi['l7'] !== null && ($pj['l7'] === null || array_diff($pj['l7'], $pi['l7']))) {
        continue;
      }
      $ppi = protoPorts($pi);
      foreach (['tcp', 'udp'] as $proto) {
        $x = intersectRanges($remaining[$proto], $ppi[$proto]);
        if ($x) {
          $taken[] = ['by' => $pi['index'], 'proto' => $proto, 'ports' => rangesStr($x), 'excludes' => $pi['has_excludes']];
          $remaining[$proto] = subtractRanges($remaining[$proto], $x);
        }
      }
    }
    $pj['taken'] = $taken;
    $pj['remaining'] = ['tcp' => rangesStr($remaining['tcp']), 'udp' => rangesStr($remaining['udp'])];
    $all = !$remaining['tcp'] && !$remaining['udp'];
    $pj['state'] = !$taken ? 'active' : ($all ? (array_filter($taken, fn($t) => $t['excludes']) ? 'excludes-only' : 'dead') : 'partial');
    $pj['ports'] = ['tcp' => rangesStr($pp['tcp']), 'udp' => rangesStr($pp['udp'])];
  }
  unset($pj);
}

function loadProfiles(?int $pid, array $expected, array &$globals): array
{
  if ($pid === null) {
    $argsList = array_column($expected, 'tokens');
  } else {
    $argsList = [];
    foreach (splitNew(runningArgs($pid)) as $part) {
      $prof = [];
      foreach ($part as $a) {
        if (in_array(optName($a), GLOBAL_OPTS, true)) {
          $globals[] = $a;
        } else {
          $prof[] = $a;
        }
      }
      $argsList[] = $prof;
    }
  }
  $profiles = [];
  $used = [];
  foreach ($argsList as $i => $args) {
    $p = buildProfile($i + 1, $args);
    // По документации: если все файлы хостлистов профиля пусты, фильтра по хостам нет
    $p['empty_hostlists'] = false;
    if ($p['hostlists'] && !$p['hostlist_domains'] && !$p['autolist']
      && !array_filter($p['hostlists'], fn($f) => readListLines($f))) {
      $p['has_host_filter'] = false;
      $p['empty_hostlists'] = true;
    }
    $p['source'] = null;
    foreach ($expected as $k => $e) {
      if (!isset($used[$k]) && $e['tokens'] === $args) {
        $p['source'] = array_diff_key($e, ['tokens' => 1]);
        $used[$k] = true;
        break;
      }
    }
    $profiles[] = $p;
  }
  analyzeShadowing($profiles);
  return $profiles;
}
