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

// The records with their strategies spelled out: the key «learn_P_I» is profile P, instance I; the strategy number N is
// the --lua-desync steps with strategy=N in that profile. Counts decay to now, as the module does
function learnInfo(): array
{
  ini_set('serialize_precision', '-1');   // the routers' php.ini has 17: 0.87 would go out as 0.869999…
  $profiles = [];
  foreach (currentProfiles() as $p) {
    $profiles[$p['index']] = $p;
  }
  $now = time();
  $items = [];
  foreach (learnRead() as $r) {
    $prof = preg_match('/_(\d+)_\d+$/', $r['key'], $m) ? (int)$m[1] : null;
    $steps = [];
    foreach ($prof && isset($profiles[$prof]) ? $profiles[$prof]['args'] : [] as $t) {
      if (preg_match('/^--lua-desync=.*:strategy=(\d+)(:|$)/', $t, $sm)) {
        $steps[(int)$sm[1]][] = preg_replace('/:strategy=\d+/', '', $t);
      }
    }
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
  return ['items' => $items, 'updated' => $file ? filemtime($file) : null, 'module' => is_file(LUA_DIR . '/nfqws-ui.lua')];
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

// cron pass: the fresh /tmp copy goes next to nfqws2.conf (survives a reboot); readable by nfqws2 (user nobody)
function learnKeep(): void
{
  if (is_file(LEARN_TMP) && (@filemtime(LEARN_TMP) > (@filemtime(LEARN_KEEP) ?: 0))) {
    @copy(LEARN_TMP, LEARN_KEEP . '.new') && @chmod(LEARN_KEEP . '.new', 0644) && @rename(LEARN_KEEP . '.new', LEARN_KEEP);
  }
}
