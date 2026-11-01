<?php
// Builds src/www/lib/map.php: which server sections (lib/*.php) a request needs, so that api.php does not compile all
// of them on every request (php-cgi starts anew each time; on a weak router reading ~500 KB of PHP costs ~0.4 s).
//
// How: every function, constant and class of the sections is a unit; a unit refers to every identifier in its body
// and to every string literal equal to a unit name (callables passed as strings). From the code a request runs —
// the part of api.php every POST goes through, then one command's case — all reachable units are collected, and the
// sections holding them are loaded. A loaded section evaluates all its top-level constants, so their references are
// followed too. The result is conservative: a section too many costs time, a section too few is impossible as long
// as calls are written by name. Requests other than a plain POST (cron, background jobs, download, upload) load all.
//
// php scripts/libmap.php [src/www]        — writes lib/map.php
// php scripts/libmap.php [src/www] check  — exits 1 if lib/map.php is not up to date
$www = rtrim($argv[1] ?? __DIR__ . '/../src/www', '/\\');
$check = ($argv[2] ?? '') === 'check';
$api = file_get_contents("$www/api.php");
if (!preg_match("/const LIB = \[(.*?)\];/s", $api, $m)) {
  fwrite(STDERR, "no LIB in api.php\n");
  exit(2);
}
$libs = array_map(fn($s) => trim($s, " '\n"), explode(',', $m[1]));

// identifiers and name-like string literals of a token run
function refsOf(array $toks, int $from, int $to): array
{
  $r = [];
  for ($i = $from; $i < $to; $i++) {
    $t = $toks[$i];
    if (!is_array($t)) continue;
    if ($t[0] === T_STRING || $t[0] === T_NAME_FULLY_QUALIFIED) {
      $r[ltrim($t[1], '\\')] = true;
    } elseif ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
      // a callable by name is a bare call argument: array_map('tokens', …), usort($a, 'cmp'); array keys and values
      // ($p['state'], 'state' => …, ['a', 'b']) are data
      $s = substr($t[1], 1, -1);
      if (preg_match('/^[A-Za-z_]\w*$/', $s) && in_array(near($toks, $i, -1), ['(', ','], true) && in_array(near($toks, $i, 1), [',', ')'], true)
        && !inArrayLiteral($toks, $i)) {
        $r[$s] = true;
      }
    }
  }
  return $r;
}

// the nearest meaningful token before (-1) or after (+1) $i
function near(array $toks, int $i, int $dir): string
{
  for ($i += $dir; isset($toks[$i]); $i += $dir) {
    if (is_array($toks[$i]) && in_array($toks[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
    return is_array($toks[$i]) ? $toks[$i][1] : $toks[$i];
  }
  return '';
}

// is the token directly inside [ … ] (an array literal) rather than inside a call's ( … )
function inArrayLiteral(array $toks, int $i): bool
{
  $depth = 0;
  for ($i--; $i >= 0; $i--) {
    $s = is_array($toks[$i]) ? $toks[$i][1] : $toks[$i];
    if ($s === ')' || $s === ']') $depth++;
    elseif ($s === '(' || $s === '[') {
      if ($depth-- === 0) return $s === '[';
    }
  }
  return false;
}

// end of a braced block that starts at or after $i
function blockEnd(array $toks, int $i): int
{
  $depth = 0;
  $n = count($toks);
  for (; $i < $n; $i++) {
    $s = is_array($toks[$i]) ? $toks[$i][1] : $toks[$i];
    if ($s === '{' || (is_array($toks[$i]) && in_array($toks[$i][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
      $depth++;
    } elseif ($s === '}' && --$depth === 0) {
      return $i;
    }
  }
  return $n - 1;
}

function nextName(array $toks, int $i): ?string
{
  for ($i++; isset($toks[$i]); $i++) {
    if (is_array($toks[$i]) && $toks[$i][0] === T_WHITESPACE) continue;
    if (is_array($toks[$i]) && $toks[$i][0] === T_STRING) return $toks[$i][1];
    if (is_array($toks[$i]) && $toks[$i][0] === T_CONSTANT_ENCAPSED_STRING) return substr($toks[$i][1], 1, -1);
    if ($toks[$i] === '(') continue;
    return null;
  }
  return null;
}

// units: name => [lib, refs, isConst]
$units = [];
$constsOf = [];   // lib => names of its top-level constants
foreach ($libs as $lib) {
  $toks = token_get_all(file_get_contents("$www/lib/$lib.php"));
  $n = count($toks);
  for ($i = 0; $i < $n; $i++) {
    $t = $toks[$i];
    if (!is_array($t)) continue;
    if ($t[0] === T_FUNCTION || $t[0] === T_CLASS) {
      $name = nextName($toks, $i);
      $end = blockEnd($toks, $i);
      if ($name !== null) $units[$name] = [$lib, refsOf($toks, $i + 1, $end + 1), false];
      $i = $end;
    } elseif ($t[0] === T_CONST || ($t[0] === T_STRING && $t[1] === 'define')) {
      $name = nextName($toks, $i);
      for ($e = $i; $e < $n && $toks[$e] !== ';'; $e++);
      if ($name !== null) {
        $refs = refsOf($toks, $i + 1, $e);
        unset($refs[$name]);
        $units[$name] = [$lib, $refs, true];
        $constsOf[$lib][] = $name;
      }
      $i = $e;
    }
  }
}

// the sections that hold everything reachable from $roots (plus what their top-level constants need)
function sections(array $roots, array $units, array $constsOf, array $have = []): array
{
  // two kinds of visits: «used» — the request may run this unit, follow everything it names; «loaded» — a constant
  // evaluated only because its section was loaded, follow only the constants its value names
  $used = [];
  $loaded = [];
  $libs = $have;
  $stack = [];
  foreach ($roots as $r => $_) $stack[] = [$r, true];
  foreach ($have as $l => $_) foreach ($constsOf[$l] ?? [] as $c) $stack[] = [$c, false];
  while ($stack) {
    [$x, $use] = array_pop($stack);
    if (!isset($units[$x]) || isset($used[$x]) || (!$use && isset($loaded[$x]))) continue;
    if ($use) $used[$x] = true; else $loaded[$x] = true;
    [$lib, $refs, $isConst] = $units[$x];
    if (!isset($libs[$lib])) {
      $libs[$lib] = true;
      foreach ($constsOf[$lib] ?? [] as $c) $stack[] = [$c, false];
    }
    foreach ($refs as $y => $_) {
      if ($use) $stack[] = [$y, true];
      elseif (isset($units[$y]) && $units[$y][2]) $stack[] = [$y, false];
    }
  }
  return $libs;
}

$toks = token_get_all($api);
$text = fn(int $a, int $b) => substr($api, $a, $b - $a);
$at = function (string $needle) use ($api): int {
  $p = strpos($api, $needle);
  if ($p === false) {
    fwrite(STDERR, "api.php: not found: $needle\n");
    exit(2);
  }
  return $p;
};
$idsIn = function (string $src): array {
  $t = token_get_all('<?php ' . $src);
  return refsOf($t, 0, count($t));
};

// every POST runs: the header (its own functions included) and the command part up to the switch, without the
// download and upload branches (those load all sections)
$cronAt = $at('// ================= запуск по расписанию =================');
$cmdAt = $at('// ================= команды =================');
$dlAt = $at("if ((\$_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset(\$_GET['download']))");
$postAt = $at("if ((\$_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')");
$swAt = $at("switch (\$cmd) {");
$defAt = $at("\n  default:");
// (the LIB list itself names sections, not functions — «state», «diag» there are file names)
$baseRefs = $idsIn(str_replace($m[0], '', $text(0, $cronAt))) + $idsIn($text($cmdAt, $dlAt)) + $idsIn($text($postAt, $swAt)) + $idsIn(substr($api, $defAt));
$base = sections($baseRefs, $units, $constsOf);

// cases: a chunk runs from its «case» to the next one; a chunk that does not end the request falls into the next
preg_match_all("/\n  case '([a-z0-9_]+)':/", $api, $cm, PREG_OFFSET_CAPTURE, $swAt);
$chunks = [];
$k = count($cm[0]);
for ($i = 0; $i < $k; $i++) {
  $a = $cm[0][$i][1];
  $b = $i + 1 < $k ? $cm[0][$i + 1][1] : $defAt;
  $chunks[] = [$cm[1][$i][0], $text($a, $b)];
}
$cmds = [];
for ($i = count($chunks) - 1, $next = []; $i >= 0; $i--) {
  [$name, $src] = $chunks[$i];
  $own = $idsIn($src);
  // ends the request: a statement at the case's own level that responds, fails, exits or breaks
  $ends = preg_match('/\n    (respond|fail)\(|\n    (exit|break)\b/', $src);
  $refs = $ends ? $own : $own + $next;
  $cmds[$name] = $refs;
  $next = $refs;
}
$order = array_flip($libs);
$sorted = function (array $set) use ($order): array {
  $l = array_keys($set);
  usort($l, fn($a, $b) => $order[$a] <=> $order[$b]);
  return $l;
};
$out = ['base' => $sorted($base), 'cmd' => []];
foreach (array_reverse($cmds, true) as $name => $refs) {
  $all = sections($refs, $units, $constsOf, $base);
  $out['cmd'][$name] = $sorted(array_diff_key($all, $base));
}
ksort($out['cmd']);

$php = "<?php\n// Generated by scripts/libmap.php — do not edit. Which server sections a request needs: «base» for every POST,\n"
  . "// then the command's own. A command missing here loads all sections.\ndefined('API_FILE') || exit;\n\nreturn "
  . var_export($out, true) . ";\n";
$php = preg_replace("/=> \n\s+array \(/", '=> array (', $php);
$file = "$www/lib/map.php";
if ($check) {
  if (!is_file($file) || file_get_contents($file) !== $php) {
    fwrite(STDERR, "lib/map.php is out of date: run php scripts/libmap.php\n");
    exit(1);
  }
  exit(0);
}
file_put_contents($file, $php);
// a short report: the share of section code each command loads
$size = [];
foreach ($libs as $l) $size[$l] = filesize("$www/lib/$l.php");
$total = array_sum($size);
$pct = fn(array $l) => round(array_sum(array_map(fn($x) => $size[$x], $l)) * 100 / $total);
printf("base: %d%% (%s)\n", $pct($out['base']), implode(',', $out['base']));
$by = [];
foreach ($out['cmd'] as $c => $l) $by[$c] = $pct(array_merge($out['base'], $l));
arsort($by);
echo count($by), " commands; median ", array_values($by)[intdiv(count($by), 2)], "%, max ", max($by), "%\n";
foreach (array_slice($argv, 3) ?: ['pending_get', 'load', 'state', 'session', 'conf_get', 'list_get', 'dns_get'] as $c) {
  printf("  %-14s %3d%%  %s\n", $c, $by[$c] ?? -1, implode(',', $out['cmd'][$c] ?? []));
}
