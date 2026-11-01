<?php
// Server section: checking someone else's config.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ----- проверка чужих конфигов целиком -----
// Конфиги берут в чатах и ставят заменой своего. Здесь каждый (и свой текущий для сравнения) запускается на
// проверочной очереди отдельным nfqws2, и на одних и тех же сайтах видно, что он открывает. Рабочий конфиг не меняется.
// Чужой текст в оболочку не попадает как есть: в нём допускаются только присваивания переменных и комментарии.
const CFG_MAX = 10;          // чужих конфигов за раз
const CFG_TEXT_MAX = 65536;  // байт в одном конфиге
const CFG_HOSTS_MAX = 15;
const CFG_MIN_FREE_MB = 20;  // меньше свободной памяти — второй nfqws2 с полным конфигом не запускаем
const CFG_FILE = TEST_DIR . '/cfg.json';

// null — текст безопасно читать оболочкой; иначе причина отказа
function cfgUnsafe(string $text): ?string
{
  $re = '/^[ \t]*[A-Za-z_][A-Za-z0-9_]*=(?:"[^"]*"|\'[^\']*\'|[^\s"\'#;&|<>]*)[ \t]*(?:#[^\n]*)?$/m';
  preg_match_all($re, $text, $m);
  foreach ($m[0] as $a) {
    if (str_contains($a, '`') || str_contains($a, '$(')) {
      return 'в значении переменной есть подстановка команды (` или $(…)) — такой конфиг выполнял бы команды на роутере';
    }
  }
  foreach (explode("\n", preg_replace($re, '', $text)) as $line) {
    $line = trim($line);
    if ($line !== '' && $line[0] !== '#') {
      return 'кроме переменных в нём есть команды (' . cutText($line, 60) . ') — такой конфиг выполнял бы их на роутере';
    }
  }
  return null;
}

function cutText(string $s, int $n): string
{
  return strlen($s) > $n ? preg_replace('/[\x80-\xBF]+$/', '', substr($s, 0, $n)) . '…' : $s;
}

// Чужой конфиг → текст под этот роутер, аргументы для проверочного nfqws2 и профили. Ошибки — в skip
function cfgPrepare(string $name, string $text, int $k, bool $mine = false): array
{
  $c = ['name' => $name, 'skip' => null, 'changes' => [], 'missing' => [], 'warnings' => 0];
  $text = normalizeText($text);
  if (!$mine && ($why = cfgUnsafe($text)) !== null) {
    return ['skip' => "Не проверяется: $why."] + $c;
  }
  $ad = adaptConf($text);
  $text = $ad['text'];
  $c['changes'] = $ad['changes'];
  $c['text'] = $text;
  $raw = confRawVars($text);
  $lint = lintConf($raw, $text);
  foreach ($lint['issues'] as $x) {
    if (preg_match('/^Файла (?:блоба )?нет: (\S+)/u', $x['msg'], $mm)) {
      $c['missing'][] = $mm[1];
    }
    $c['warnings'] += in_array($x['level'], ['error', 'warning'], true) ? 1 : 0;
  }
  $c['missing'] = array_values(array_unique($c['missing']));
  if (!$lint['dry_run'] || !$lint['dry_run']['ok']) {
    return ['skip' => $c['missing'] ? 'Нет файлов: ' . implode(', ', $c['missing'])
      : 'nfqws2 не принимает этот конфиг' . (!empty($lint['dry_run']['message']) ? ': ' . $lint['dry_run']['message'] : '')] + $c;
  }
  // профили и аргументы — без комментариев внутри кавычек (nfqws2 их слова всё равно пропускает); в «Применить» уходит текст как есть
  $clean = $text;
  foreach (ARG_VARS as $v) {
    foreach (array_reverse(array_keys(commentTokens(confRawVars($clean)[$v] ?? ''))) as $t) {
      $clean = editTokenInText($clean, $v, $t, null);
    }
  }
  $tmp = tempnam('/tmp', 'nfqcfg');
  file_put_contents($tmp, $clean);
  $exp = confValues($tmp);
  unlink($tmp);
  $c['profiles'] = array_values(array_column(expectedProfiles($exp), 'tokens'));
  if (!$c['profiles']) {
    return ['skip' => 'В конфиге нет ни одного профиля'] + $c;
  }
  // параметры запуска: очередь, пользователь и журнал задаёт проверка сама
  $args = array_values(array_filter(tokens($exp['NFQWS_BASE_ARGS']), fn($t) => !preg_match('/^--(qnum|daemon|pidfile|user|uid|debug)(=|$)/', $t)));
  foreach ($c['profiles'] as $n => $pt) {
    if ($n > 0) {
      $args[] = '--new';
    }
    foreach ($pt as $t) {
      if (str_starts_with($t, '--hostlist-auto-debug=')) {
        continue;
      }
      // автосписок пишется во время работы — чужой конфиг пишет в копию, а не в ваш файл
      if (preg_match('/^--hostlist-auto=(.+)$/', $t, $mm)) {
        $copy = TEST_DIR . "/cfg$k-auto.list";
        @copy($mm[1], $copy) || touch($copy);
        @chmod($copy, 0666);
        $t = "--hostlist-auto=$copy";
      }
      $args[] = $t;
    }
  }
  $c['args'] = $args;
  return $c;
}

function memFreeMb(): ?int
{
  return preg_match('/^MemAvailable:\s+(\d+)/m', (string)@file_get_contents('/proc/meminfo'), $m) ? (int)((int)$m[1] / 1024) : null;
}

const LOWMEM_TOTAL_MB = 300;   // 128 и 256 МБ (Keenetic Viva и т. п.)
const LOWMEM_RESTART_MB = 30;  // перед перезапуском с проверкой: меньше — предупредить

// Слабый роутер: каждый запрос к api.php — отдельный php-cgi на 10–15 МБ, и несколько разом съедают всю память
// (NFQ-L130). Страница тогда шлёт запросы по одному
function lowMem(): bool
{
  return preg_match('/^MemTotal:\s+(\d+)/m', (string)@file_get_contents('/proc/meminfo'), $m) === 1 && (int)$m[1] / 1024 <= LOWMEM_TOTAL_MB;
}

function cfgJob(array $job, array &$status, ?array &$nfq, string $iface): void
{
  $repeats = max(1, min(5, (int)($job['repeats'] ?? 3)));
  $stop = fn() => is_file(TEST_DIR . '/stop');
  set_time_limit(60 + count($job['configs']) * count($job['hosts']) * $repeats * 20);
  $status += ['repeats' => $repeats, 'sites' => [], 'configs' => []];
  $status['total'] = count($job['hosts']) + count($job['configs']);
  $status['phase'] = 'baseline';

  // без обхода: что открывается и так (у такого сайта конфиг может только навредить)
  testRules('bypass', $iface);
  foreach ($job['hosts'] as $h) {
    if ($stop()) {
      break;
    }
    $status['current'] = "$h без обхода";
    testSaveStatus($status);
    $b = curlProbe($h, false);
    $status['sites'][] = ['host' => $h, 'baseline' => ['ok' => $b['ok'], 'reason' => $b['reason'] ?? null]];
    $status['done']++;
  }

  $status['phase'] = 'check';
  testRules('queue', $iface);
  foreach ($job['configs'] as $k => $cfg) {
    $c = cfgPrepare($cfg['name'], $cfg['text'], $k, !empty($cfg['mine'])) + ['mine' => !empty($cfg['mine'])];
    if ($stop()) {
      $status['state'] = 'stopped';
      break;
    }
    $status['current'] = $c['name'];
    testSaveStatus($status);
    $free = memFreeMb();
    if ($c['skip'] === null && $free !== null && $free < CFG_MIN_FREE_MB) {
      $c['skip'] = "Мало свободной памяти ($free МБ) — второй nfqws2 с полным конфигом не запущен";
    }
    if ($c['skip'] === null) {
      $nfq = testNfqwsStart($c['args']);
      if (!$nfq) {
        $c['skip'] = 'nfqws2 не запустился: ' . trim((string)@file_get_contents(TEST_DIR . '/nfqws.out'));
      }
    }
    $c['per'] = [];
    foreach ($c['skip'] === null ? $job['hosts'] : [] as $h) {
      if ($stop()) {
        break;
      }
      $status['current'] = "{$c['name']}: $h";
      testSaveStatus($status);
      $ok = 0;
      $times = [];
      $reason = null;
      for ($t = 0; $t < $repeats; $t++) {
        $r = curlProbe($h, false);
        if (!$r['ok']) {
          $reason = $r['reason'];
          break;   // как в подборе: первая же неудача — дальше не тратим время
        }
        $ok++;
        $times[] = $r['ms'];
      }
      $c['per'][$h] = ['ok' => $ok, 'tries' => min($t + 1, $repeats), 'ms' => $times ? (int)round(array_sum($times) / count($times)) : null, 'reason' => $reason];
    }
    testNfqwsStop($nfq);
    $nfq = null;
    $ms = array_filter(array_column($c['per'], 'ms'));
    $c['opened'] = count(array_filter($c['per'], fn($x) => $x['ok'] > 0 && $x['ok'] === $x['tries']));
    $c['ms'] = $ms ? (int)round(array_sum($ms) / count($ms)) : null;
    unset($c['args']);
    $status['configs'][] = $c;
    $status['done']++;
    testSaveStatus($status);
  }
  testRules('off', $iface);
  foreach (glob(TEST_DIR . '/cfg*-auto.list') ?: [] as $f) {
    @unlink($f);
  }
  if ($status['state'] === 'running') {
    $status['state'] = 'done';
  }
  $status['finished'] = time();
  $status['current'] = null;
  testSaveStatus($status);
  // итог живёт до перезагрузки роутера: следующий подбор перепишет статус задания, а этот останется
  file_put_contents(CFG_FILE, json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
