<?php
// Server section: what the «learn» orchestrator (src/lua/nfqws-ui.lua) has learned.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// nfqws2 (user nobody) writes the file to /tmp every minute; a cron pass copies it next to nfqws2.conf, so it survives
// a reboot (the module reads /tmp first, then this copy). «Forget» goes through a command file the module applies.
const LEARN_TMP = '/tmp/nfqws2-learn.txt';
const LEARN_FORGET = '/tmp/nfqws2-learn.forget';
define('LEARN_KEEP', CONF_DIR . '/learn.txt');
const LEARN_HALF_PHP = 604800;   // = LEARN_HALF in the module: what the page shows decays the same way

// the fresher of the two copies
function learnFile(): ?string
{
  $t = @filemtime(LEARN_TMP) ?: 0;
  $k = @filemtime(LEARN_KEEP) ?: 0;
  return $t || $k ? ($t >= $k ? LEARN_TMP : LEARN_KEEP) : null;
}

// the module's line format: key, host, signature, current, count, «i,ok,fail,time;…»
function learnRead(): array
{
  $f = learnFile();
  $out = [];
  foreach ($f ? (@file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
    $p = explode("\t", $line);
    if (count($p) !== 6 || !ctype_digit_safe($p[3]) || !ctype_digit_safe($p[4])) {
      continue;
    }
    $stats = [];
    foreach ($p[5] === '' ? [] : explode(';', $p[5]) as $s) {
      $x = explode(',', $s);
      if (count($x) === 4) {
        $stats[(int)$x[0]] = ['ok' => (float)$x[1], 'fail' => (float)$x[2], 't' => (int)$x[3]];
      }
    }
    $out[] = ['key' => $p[0], 'host' => $p[1], 'sig' => $p[2], 'cur' => (int)$p[3], 'n' => (int)$p[4], 'stats' => $stats];
  }
  return $out;
}

function ctype_digit_safe(string $s): bool
{
  return $s !== '' && strspn($s, '0123456789') === strlen($s);   // the router's PHP has no ctype
}

// djb2 over 32 bits, as learn_hash() in the module; in floats: the routers' 32-bit PHP would overflow an int
function learnHash(string $s): string
{
  $h = 5381.0;
  for ($i = 0, $n = strlen($s); $i < $n; $i++) {
    $h = fmod($h * 33 + ord($s[$i]), 4294967296.0);
  }
  return sprintf('%04x%04x', (int)floor($h / 65536), (int)fmod($h, 65536));
}

// Every learn of the running profiles: the strategy=N steps after it, their signature as learn_strategies() counts it
// (the args of each step sorted, a flag as «name=»), and key= if the profile names its records itself
function learnOwners(): array
{
  $out = [];
  foreach (currentProfiles() as $p) {
    $args = $p['args'];
    foreach ($args as $i => $t) {
      if (!preg_match('/^--lua-desync=learn(:|$)/', $t)) {
        continue;
      }
      $key = preg_match('/:key=([^:]+)/', $t, $km) ? $km[1] : null;
      $steps = [];
      $parts = [];
      foreach (array_slice($args, $i + 1) as $s) {
        if (!preg_match('/^--lua-desync=(.*):strategy=(\d+)(:|$)/', $s, $sm)) {
          continue;
        }
        $n = (int)$sm[2];
        $bare = preg_replace('/:strategy=\d+/', '', substr($s, 13));
        $steps[$n][] = '--lua-desync=' . $bare;
        $x = explode(':', $bare);
        $fn = array_shift($x);
        $x = array_map(fn($a) => str_contains($a, '=') ? $a : "$a=", $x);
        sort($x, SORT_STRING);
        $parts[] = "$n:$fn:" . implode(':', $x);
      }
      sort($parts, SORT_STRING);
      $out[] = ['profile' => $p['index'], 'key' => $key, 'sig' => learnHash(implode('|', $parts)), 'steps' => $steps];
    }
  }
  return $out;
}

// The records with their strategies spelled out. A record belongs to the profile whose learn has its key= or the same
// strategies (the signature in the record) — not to a profile number: numbers shift. Counts decay to now, as the module does
function learnInfo(): array
{
  ini_set('serialize_precision', '-1');   // the routers' php.ini has 17: 0.87 would go out as 0.869999…
  $owners = learnOwners();
  $now = time();
  $items = [];
  // the same host and strategies twice — an older line under the profile number (before the keys by signature):
  // the fresher one counts
  $fresh = fn($r) => max([0, ...array_column($r['stats'], 't')]);
  $recs = [];
  foreach (learnRead() as $r) {
    $id = $r['host'] . "\t" . $r['sig'];
    if (!isset($recs[$id]) || $fresh($r) > $fresh($recs[$id])) {
      $recs[$id] = $r;
    }
  }
  foreach ($recs as $r) {
    $own = null;
    foreach ($owners as $o) {
      if ($o['key'] !== null ? $o['key'] === $r['key'] && $o['sig'] === $r['sig'] : $o['sig'] === $r['sig']) {
        $own = $o;
        break;
      }
    }
    $prof = $own['profile'] ?? null;
    $steps = $own['steps'] ?? [];
    $list = [];
    for ($i = 1; $i <= $r['n']; $i++) {
      $s = $r['stats'][$i] ?? null;
      $k = $s ? 0.5 ** (max(0, $now - $s['t']) / LEARN_HALF_PHP) : 0;
      $ok = $s ? $s['ok'] * $k : 0;
      $fail = $s ? $s['fail'] * $k : 0;
      $list[] = ['n' => $i, 'ok' => round($ok, 1), 'fail' => round($fail, 1), 'score' => round(($ok + 1) / ($ok + $fail + 2), 2),
        'last' => $s['t'] ?? null, 'steps' => $steps[$i] ?? [], 'name' => isset($steps[$i]) ? strategyName($steps[$i]) : null];
    }
    $items[] = ['key' => $r['key'], 'host' => $r['host'], 'profile' => $prof, 'cur' => $r['cur'], 'strategies' => $list];
  }
  usort($items, fn($a, $b) => strcmp($a['host'], $b['host']));
  $file = learnFile();
  return ['items' => $items, 'updated' => $file ? filemtime($file) : null, 'module' => is_file(LUA_DIR . '/nfqws-ui.lua') || is_file(learnShipped())];
}

// Forget one host of one key, or everything: the module drops it from memory on its next minute; both files lose
// the lines now, so the page shows it at once
function learnForget(?string $key, ?string $host): void
{
  $all = $key === null;
  if (!$all && (!preg_match('/^[\w.-]{1,64}$/', $key) || !preg_match('/^[\w.:-]{1,253}$/', (string)$host))) {
    fail('Неверная запись');
  }
  file_put_contents(LEARN_FORGET, ($all ? '*' : "$key\t$host") . "\n", FILE_APPEND);
  @chmod(LEARN_FORGET, 0666);   // the module (user nobody) empties it after applying
  foreach ([LEARN_TMP, LEARN_KEEP] as $f) {
    if (!is_file($f)) {
      continue;
    }
    $keep = $all ? [] : array_filter(file($f, FILE_IGNORE_NEW_LINES) ?: [], fn($l) => !str_starts_with($l, "$key\t$host\t"));
    file_put_contents($f, $keep ? implode("\n", $keep) . "\n" : '');
  }
}

// The module ships with the package (outside the nfqws2 folder, which belongs to nfqws2-keenetic) and is copied into
// the nfqws2 lua folder when a profile switches to learn: nfqws2 keeps starting even if nfqws2-ui is removed later
function learnShipped(): string
{
  return (ROOT ? '/opt/share' : '/usr/share') . '/nfqws-ui/nfqws-ui.lua';
}

// copy the shipped module into the lua folder if it is missing or older; true — the lua folder has it
function learnInstall(): bool
{
  $src = learnShipped();
  $dst = LUA_DIR . '/nfqws-ui.lua';
  if (is_file($src) && (!is_file($dst) || md5_file($src) !== md5_file($dst))) {
    @copy($src, "$dst.new") && @chmod("$dst.new", 0644) && @rename("$dst.new", $dst);
  }
  return is_file($dst);
}

// Switch the n-th circular of a variable (0-based, counted over the whole variable) to learn. The same args stay —
// learn takes everything circular takes. Learn has to see the server's answer: --in-range=-s34228 before it, and
// --payload=all for it alone (the strategies keep the payload types they had). Point edits: the rest of the text,
// comments and line breaks stay as they were. Then the --lua-init lines it needs, after zapret-auto.lua
function learnConvert(string $var, int $n): string
{
  if (!in_array($var, ARG_VARS, true) || $var === 'NFQWS_BASE_ARGS') {
    fail('Неверная переменная');
  }
  if (!learnInstall()) {
    fail('Модуль обучения не найден: переустановите nfqws2-ui');
  }
  $text = file_get_contents(CONF_FILE);
  $toks = tokens(confRawVars($text)[$var] ?? '');
  $at = null;
  $seen = 0;
  foreach ($toks as $i => $t) {
    if (preg_match('/^--lua-desync=circular(:|$)/', $t) && $seen++ === $n) {
      $at = $i;
      break;
    }
  }
  if ($at === null) {
    fail('Перебор стратегий (circular) не найден — обновите страницу');
  }
  // what came before it in the same profile: the payload types, a range of incoming packets
  $payload = null;
  $range = false;
  for ($i = $at - 1; $i >= 0 && !isNewArg($toks[$i]); $i--) {
    if ($payload === null && str_starts_with($toks[$i], '--payload=')) {
      $payload = substr($toks[$i], 10);
    }
    $range = $range || str_starts_with($toks[$i], '--in-range=');
  }
  $learn = '--lua-desync=learn' . substr($toks[$at], strlen('--lua-desync=circular'));
  $seq = array_merge($payload !== null && $payload !== 'all' ? ['--payload=all'] : [], $range ? [] : ['--in-range=-s34228'], [$learn],
    $payload !== null && $payload !== 'all' ? ["--payload=$payload"] : []);
  $indent = tokenIndent($text, $var, $at);
  $text = editTokenInText($text, $var, $at, implode($indent === null ? ' ' : "\n$indent", $seq));

  $base = tokens(confRawVars($text)['NFQWS_BASE_ARGS'] ?? '');
  $has = fn($f) => (bool)array_filter($base, fn($t) => preg_match('~^--lua-init=@\S*/' . preg_quote($f, '~') . '$~', $t));
  if (!$has('zapret-auto.lua')) {
    $text = appendTokenInText($text, 'NFQWS_BASE_ARGS', '--lua-init=@' . LUA_DIR . '/zapret-auto.lua');
  }
  if (!$has('nfqws-ui.lua')) {
    $text = appendTokenInText($text, 'NFQWS_BASE_ARGS', '--lua-init=@' . LUA_DIR . '/nfqws-ui.lua');
  }
  return $text;
}

// the spaces in front of the i-th argument of a variable when it starts its own line; null — it shares a line
function tokenIndent(string $text, string $var, int $i): ?string
{
  if (!preg_match('/^' . preg_quote($var, '/') . '="([^"]*)"/m', $text, $m, PREG_OFFSET_CAPTURE)) {
    return null;
  }
  preg_match_all('/\S+/', preg_replace('/\\\\(?=[ \t]*\r?\n)/', ' ', $m[1][0]), $tm, PREG_OFFSET_CAPTURE);
  $off = $tm[0][$i][1] ?? null;
  if ($off === null) {
    return null;
  }
  $before = substr($m[1][0], 0, $off);
  $nl = strrpos($before, "\n");
  $lead = $nl === false ? null : substr($before, $nl + 1);
  return $lead !== null && trim($lead) === '' ? $lead : null;
}

// cron pass: the fresh /tmp copy goes next to nfqws2.conf (survives a reboot); readable by nfqws2 (user nobody)
function learnKeep(): void
{
  if (is_file(LEARN_TMP) && (@filemtime(LEARN_TMP) > (@filemtime(LEARN_KEEP) ?: 0))) {
    @copy(LEARN_TMP, LEARN_KEEP . '.new') && @chmod(LEARN_KEEP . '.new', 0644) && @rename(LEARN_KEEP . '.new', LEARN_KEEP);
  }
}
