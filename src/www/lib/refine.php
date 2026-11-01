<?php
// Server section: refining numbers: the second phase of a pick.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ----- уточнение чисел: вторая фаза подбора -----
// Берём стратегию с фейком, которая открыла сайт не каждый раз (или не открыла вовсе), и перебираем у её
// фейка по одной оси за раз: чем портить, сколько раз слать, какое имя подставлять.

const FAKE_FUNCS = ['fake', 'fakedsplit', 'fakeddisorder', 'hostfakesplit'];
const FOOL_KEYS = ['tcp_ts', 'tcp_md5', 'badsum', 'tcp_seq', 'tcp_ack', 'ip_ttl', 'ip_autottl'];
const REFINE_FOOL = [
  ['tcp_ts', ['tcp_ts' => '-600000']], ['tcp_md5', ['tcp_md5' => true]], ['badsum', ['badsum' => true]],
  ['tcp_seq', ['tcp_seq' => '-10000']], ['autottl', ['ip_autottl' => '-2,3-20']], ['tcp_ts + tcp_md5', ['tcp_ts' => '-600000', 'tcp_md5' => true]],
];
const REFINE_REPEATS = [1, 2, 4, 6, 8, 12, 16, 20];
const REFINE_NAMES = ['ya.ru', 'www.google.com', ''];   // пустое — случайное имя
const REFINE_LIMIT = 300;   // секунд на всю фазу
// Фейк — копия настоящего ClientHello устройства (tls_client_hello_clone) вместо заготовки: отпечаток фейка
// совпадает с отпечатком настоящего трафика, и DPI не отличит его по телу. Помогает там, где заготовки «спалились»
// (замер z2k); приветствие браузера ~1900 байт nfqws2 сам режет на пакеты (проверено с Chrome 2026-10-09)
const CLONE_BLOB = 'tls_clone';
const CLONE_STEP = '--lua-desync=tls_client_hello_clone:blob=' . CLONE_BLOB . ':fallback=fake_default_tls';

// Стратегия с заменённым шагом фейка; фейку-копии нужен шаг копирования перед ним
function stepsWith(array $steps, int $fi, array $s): array
{
  $steps[$fi] = stepBuild($s);
  $steps = array_values(array_filter($steps, fn($t) => $t !== CLONE_STEP));
  if (($s['p']['blob'] ?? null) === CLONE_BLOB) {
    array_splice($steps, array_search(stepBuild($s), $steps, true), 0, [CLONE_STEP]);
  }
  return $steps;
}

// «--lua-desync=fake:blob=x:repeats=6:tcp_md5» → функция и параметры по порядку
function stepParse(string $tok): array
{
  $parts = explode(':', substr($tok, 13));
  $s = ['fn' => array_shift($parts), 'p' => []];
  foreach ($parts as $part) {
    [$k, $v] = array_pad(explode('=', $part, 2), 2, true);
    $s['p'][$k] = $v;
  }
  return $s;
}

function stepBuild(array $s): string
{
  $out = '--lua-desync=' . $s['fn'];
  foreach ($s['p'] as $k => $v) {
    $out .= ':' . $k . ($v === true ? '' : '=' . $v);
  }
  return $out;
}

// Номер шага с фейком, у которого есть что уточнять
function stepFakeIndex(array $steps): ?int
{
  foreach ($steps as $i => $t) {
    if (in_array(stepParse($t)['fn'], FAKE_FUNCS, true)) {
      return $i;
    }
  }
  return null;
}

// Короткое имя стратегии: «fake ×18 tcp_ts + multisplit»
function strategyName(array $steps): string
{
  return implode(' + ', array_map(function ($t) {
    $s = stepParse($t);
    $name = $s['fn'] . (($s['p']['blob'] ?? null) === CLONE_BLOB ? ' (копия)' : '');
    if (isset($s['p']['repeats']) && (int)$s['p']['repeats'] > 1) {
      $name .= ' ×' . (int)$s['p']['repeats'];
    }
    $fool = array_values(array_intersect(array_keys($s['p']), FOOL_KEYS));
    return $name . ($fool && in_array($s['fn'], FAKE_FUNCS, true) ? ' ' . implode(',', array_map(fn($k) => $k === 'ip_autottl' ? 'autottl' : $k, $fool)) : '');
  }, array_values(array_filter($steps, fn($t) => $t !== CLONE_STEP))));
}

// Порядок перебора — по тому, как соединение рвётся без обхода: при сбросе чаще помогают фейки,
// при молчании — разрезание. Так первая рабочая стратегия находится быстрее.
function orderCandidates(array $cands, ?string $reason): array
{
  $fakeFirst = in_array($reason, ['соединение сброшено', 'обрыв TLS'], true) ? 0 : ($reason === 'тайм-аут' ? 1 : null);
  if ($fakeFirst === null) {
    return $cands;
  }
  usort($cands, fn($a, $b) => (stepFakeIndex($a['steps']) === null ? 1 - $fakeFirst : $fakeFirst) <=> (stepFakeIndex($b['steps']) === null ? 1 - $fakeFirst : $fakeFirst));
  return $cands;
}

function testRefine(array &$status, callable $try, int $repeats, bool $http, bool $force, array $filter): void
{
  $full = fn($r) => $r['ok'] === $repeats;
  $fakes = array_values(array_filter($status['results'], fn($r) => $r['tries'] > 0 && stepFakeIndex($r['steps']) !== null));
  $working = array_filter($status['results'], $full);
  if (!$fakes || ($working && !$force)) {
    return;
  }
  // Основа для уточнения. Есть рабочая (галочка «полегче») — самая быстрая из рабочих. Иначе — та, что
  // продвинулась дальше всех: открывала сайт хоть раз, затем дошла до загрузки данных, затем до обрыва
  // шифрования; молчание в ответ — хуже всего. Если первая основа ничего не дала, пробуем вторую, с другой функцией.
  $stage = fn($r) => ($r['reason'] ?? '') === REASON_FREEZE ? 2 : (in_array($r['reason'] ?? '', ['обрыв TLS', 'соединение сброшено'], true) ? 1 : 0);
  usort($fakes, fn($a, $b) => [$b['ok'], $stage($b)] <=> [$a['ok'], $stage($a)]);
  $fast = array_values(array_filter($fakes, $full));
  usort($fast, fn($a, $b) => ($a['ms'] ?? PHP_INT_MAX) <=> ($b['ms'] ?? PHP_INT_MAX));
  $bases = [];
  if ($working && $fast) {
    $bases = [$fast[0]];
  } else {
    foreach ($fakes as $r) {
      $fn = stepParse($r['steps'][stepFakeIndex($r['steps'])])['fn'];
      if (!isset($bases[$fn]) && count($bases) < 2) {
        $bases[$fn] = $r;
      }
    }
    $bases = array_values($bases);
  }

  $withFool = function (array $s, array $set): array {
    $s['p'] = array_diff_key($s['p'], array_flip(FOOL_KEYS)) + $set;
    return $s;
  };
  $withRepeats = function (array $s, int $n): array {
    unset($s['p']['repeats']);
    if ($n > 1) {
      $s['p']['repeats'] = (string)$n;
    }
    return $s;
  };
  $withName = function (array $s, string $name): array {
    if ($s['fn'] === 'hostfakesplit') {
      unset($s['p']['host']);
      if ($name !== '') {
        $s['p']['host'] = $name;
      }
    } else {
      $s['p']['tls_mod'] = $name !== '' ? 'rnd,dupsid,sni=' . $name : 'rnd,rndsni,dupsid';
    }
    return $s;
  };

  $status['phase'] = 'refine';
  $deadline = time() + REFINE_LIMIT;
  set_time_limit(REFINE_LIMIT + 120);
  $tried = [];
  foreach ($bases as $base) {
    $steps = $base['steps'];
    $fi = stepFakeIndex($steps);
    $st = stepParse($steps[$fi]);
    $cur = ['ok' => $base['ok'], 'tries' => $base['tries'], 'ms' => $base['ms'], 'reason' => $base['reason'] ?? null];
    $axes = [
      ['fool', 'Чем портить фейк', array_map(fn($x) => [$x[0], fn($s) => $withFool($s, $x[1])], REFINE_FOOL)],
      ['repeats', 'Сколько фейков слать', array_map(fn($n) => [(string)$n, fn($s) => $withRepeats($s, $n)], REFINE_REPEATS)],
    ];
    // имя в фейке есть только у TLS: у hostfakesplit и у fake с заготовкой ClientHello
    if (!$http && ($st['fn'] === 'hostfakesplit' || ($st['fn'] === 'fake' && str_contains((string)($st['p']['blob'] ?? ''), 'tls')))) {
      $axes[] = ['name', 'Имя в фейке', array_map(fn($n) => [$n === '' ? 'случайное' : $n, fn($s) => $withName($s, $n)], REFINE_NAMES)];
    }
    // заготовка или копия приветствия устройства — когда заготовки у провайдера «спалились»
    if (!$http && $st['fn'] === 'fake' && str_contains((string)($st['p']['blob'] ?? ''), 'tls')) {
      $blob0 = $st['p']['blob'] === CLONE_BLOB ? 'fake_default_tls' : $st['p']['blob'];
      $axes[] = ['blob', 'Чем фейк', [
        ['заготовка', fn($s) => array_replace_recursive($s, ['p' => ['blob' => $blob0]])],
        ['копия приветствия', fn($s) => array_replace_recursive($s, ['p' => ['blob' => CLONE_BLOB]])],
      ]];
    }
    $status['refine'] = ['base' => $base['name'], 'from' => $base['from'], 'axes' => [], 'state' => 'running', 'tried' => $tried];
    $status['total'] += array_sum(array_map(fn($a) => count($a[2]), $axes));
    $improved = false;
    foreach ($axes as $ai => [$id, $title, $variants]) {
      $items = [];
      $mut = [];
      foreach ($variants as [$label, $apply]) {
        $s2 = $apply($st);
        if (stepBuild($s2) === stepBuild($st)) {
          $res = $cur;   // этот набор уже проверен
          $status['done']++;
        } else {
          if (is_file(TEST_DIR . '/stop')) {
            $status['state'] = 'stopped';
            $status['refine']['state'] = 'stopped';
            break 3;
          }
          if (time() >= $deadline) {
            $status['refine']['state'] = 'time';
            break 3;
          }
          $status['current'] = "уточнение · $title: $label";
          testSaveStatus($status);
          $res = $try(stepsWith($steps, $fi, $s2));
          $status['done']++;
        }
        $mut[] = $s2;
        $items[] = ['label' => $label, 'ok' => $res['ok'], 'tries' => $res['tries'], 'ms' => $res['ms'], 'reason' => $res['reason'] ?? null];
        $status['refine']['axes'][$ai] = ['id' => $id, 'title' => $title, 'items' => $items];
        testSaveStatus($status);
      }
      // лучший вариант оси — где больше удач; при равенстве берём первый по списку (меньше фейков, привычнее имя):
      // разница во времени в десятки миллисекунд — шум, по ней выбирать нельзя
      $best = null;
      foreach ($items as $i => $it) {
        if ($it['ok'] > 0 && ($best === null || $it['ok'] > $items[$best]['ok'])) {
          $best = $i;
        }
      }
      if ($best !== null && ($items[$best]['ok'] > $cur['ok'] || ($items[$best]['ok'] === $cur['ok'] && $full($items[$best])))) {
        $improved = $improved || stepBuild($mut[$best]) !== stepBuild($st);
        $st = $mut[$best];
        $cur = ['ok' => $items[$best]['ok'], 'tries' => $items[$best]['tries'], 'ms' => $items[$best]['ms'], 'reason' => $items[$best]['reason']];
        $status['refine']['axes'][$ai]['items'][$best]['picked'] = true;
      }
    }
    $status['refine']['state'] = 'done';
    if ($improved && $cur['ok'] >= $base['ok'] && $cur['ok'] > 0) {
      $steps = stepsWith($steps, $fi, $st);
      $name = strategyName($steps);
      $status['results'][] = ['name' => $name, 'from' => 'уточнение: ' . $base['name'], 'steps' => $steps, 'profile' => array_merge($filter, $steps), 'refined' => true] + $cur;
      $status['refine']['final'] = $name;
      break;
    }
    $tried[] = $base['name'];
  }
  testSaveStatus($status);
}
