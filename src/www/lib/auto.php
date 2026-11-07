<?php
// Server section: automatic pick when a site breaks.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ----- автоподбор при поломке: мониторинг увидел, что сайт перестал открываться, — подбираем стратегию сами -----
// Работает из прохода cron (раз в 10 минут). За проход — один сайт; найденное либо предлагается кнопкой,
// либо (режим «применять самому») ставится отдельным профилем только для этого сайта с проверкой и откатом.

define('AUTO_FILE', UI_CONF_DIR . '/auto.json');

function autoData(): array
{
  $d = json_decode((string)@file_get_contents(AUTO_FILE), true);
  return (is_array($d) ? $d : []) + ['queue' => [], 'sites' => [], 'offers' => [], 'log' => []];
}

function autoSave(array $d): void
{
  @mkdir(UI_CONF_DIR, 0755, true);
  $d['log'] = array_slice($d['log'], 0, 60);
  $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json !== false) {
    putSafe(AUTO_FILE, $json);
  }
}

function autoLog(array &$d, string $host, string $kind, string $text): void
{
  array_unshift($d['log'], ['ts' => time(), 'host' => $host, 'kind' => $kind, 'text' => $text]);
}

// Сколько проверок подряд сайт не открывается и открывался ли он до этого
function autoStreak(array $hist): array
{
  $n = 0;
  for ($i = count($hist) - 1; $i >= 0 && !$hist[$i][1]; $i--) {
    $n++;
  }
  return [$n, $i >= 0];
}

// false — another job is running or just starting: the callers' own check and this launch are not one step, and two
// requests in the same second (two tabs, a click and the auto-pick from cron) would both start a job on one test queue
function testLaunch(array $job): bool
{
  @mkdir(TEST_DIR, 0777, true);
  @chmod(TEST_DIR, 0777);
  $lock = @fopen(TEST_DIR . '/launch.lock', 'ce');   // released when the function returns
  if ($lock) {
    flock($lock, LOCK_EX);
  }
  if (testRunning()) {
    return false;
  }
  @unlink(TEST_DIR . '/stop');
  file_put_contents(TEST_DIR . '/job.json', json_encode($job));
  touch(TEST_DIR . '/trace.log');
  chmod(TEST_DIR . '/trace.log', 0666);
  testSaveStatus(['state' => 'starting', 'type' => $job['type'], 'host' => $job['host'], 'started' => time()] + (isset($job['pick']) ? ['pick' => $job['pick']] : []));
  // Чистое окружение: иначе php-cgi увидит CGI-переменные запроса и не перейдёт в режим задания
  exec('(env -i PATH=' . JOB_PATH . ' NFQWS_UI_CLI=test php-cgi -q -f ' . escapeshellarg(API_FILE) . ' >/dev/null 2>&1 &)');
  return true;
}

// A job from the page: the answer says so when another one got in first
function launchJob(array $job): void
{
  testLaunch($job) || fail('Сейчас идёт подбор или другая проверка — дождитесь окончания или остановите её');
}

function diagBusy(): bool
{
  $l = @fopen(TEST_DIR . '/diag.lock', 'c');
  if (!$l) {
    return false;
  }
  $busy = !flock($l, LOCK_EX | LOCK_NB);
  if (!$busy) {
    flock($l, LOCK_UN);
  }
  fclose($l);
  return $busy;
}

// Проход cron: перепроверить только что упавшие сайты, поставить сломавшиеся в очередь, запустить подбор для одного
function autoTick(): void
{
  $s = uiSettings();
  $a = $s['auto'];
  if (!$a['enabled'] || !$s['monitor']['enabled']) {
    return;
  }
  $mon = monitorData();
  // быстрая перепроверка: не ждём полного интервала, чтобы отличить сбой от поломки
  $again = [];
  foreach ($s['monitor']['sites'] as $h) {
    [$n, $was] = autoStreak($mon['sites'][$h] ?? []);
    $lastTs = $n ? end($mon['sites'][$h])[0] : 0;
    if ($n > 0 && $n < $a['fails'] && $was && time() - $lastTs >= 300) {
      $again[] = $h;
    }
  }
  if ($again) {
    $mon = monitorRun(true, $again) ?? $mon;
  }
  $d = autoData();
  $down = [];
  foreach ($s['monitor']['sites'] as $h) {
    $hist = $mon['sites'][$h] ?? [];
    if ($hist && !end($hist)[1]) {
      $down[] = $h;
    }
  }
  $d['queue'] = array_values(array_intersect($d['queue'], $down));   // пока ждал очереди — поднялся
  $massive = count($s['monitor']['sites']) >= 3 && count($down) * 2 > count($s['monitor']['sites']);
  $changed = false;
  foreach ($down as $h) {
    [$n, $was] = autoStreak($mon['sites'][$h]);
    if ($n < $a['fails'] || !$was || in_array($h, $d['queue'], true) || time() - ($d['sites'][$h]['last'] ?? 0) < $a['pause'] * 3600) {
      continue;
    }
    $policyOnly ??= (interceptInfo()['policy']['mode'] ?? '') === 'only' && !policyProbeEnsure();
    // «только политика», а пометить проверки роутера её меткой не вышло: мониторинг ходит мимо nfqws2,
    // сайт «не открывается» всегда, поломки стратегии за этим нет
    $skip = $policyOnly ? 'nfqws2 обрабатывает только устройства политики доступа, запросы роутера идут мимо него — мониторинг не показывает, работает ли обход'
      : ($massive ? 'не открывается больше половины сайтов мониторинга — похоже на обрыв связи, а не на блокировку'
      : (findPid() === null ? 'nfqws2 не запущен — подбирать стратегию бессмысленно'
      : (str_starts_with($last = (string)(end($mon['sites'][$h])[3] ?? ''), 'DNS роутера отдаёт') ? 'DNS роутера подменяет адрес сайта — стратегия тут не поможет, нужен защищённый DNS (DoH/DoT) в настройках роутера'
      : (str_contains($last, REASON_OTHER_SERVER) ? 'по другому адресу сайт открывается, а по адресу от DNS роутера — нет: этот сервер, похоже, закрыт по IP, стратегия тут не поможет' : null))));
    if ($skip !== null) {
      // связь или сервис вернутся — попробуем на следующем проходе; в журнал пишем один раз
      if (($d['log'][0]['host'] ?? '') !== $h || ($d['log'][0]['kind'] ?? '') !== 'skip') {
        autoLog($d, $h, 'skip', 'Пропущен: ' . $skip . '.');
        $changed = true;
      }
      continue;
    }
    $pk = podkopRoute($h);
    if ($pk && !empty($pk['proxy'])) {
      $d['sites'][$h]['last'] = time();
      autoLog($d, $h, 'skip', 'Пропущен: сайт идёт через podkop, стратегии nfqws2 на него не действуют.');
      $changed = true;
      continue;
    }
    $d['queue'][] = $h;
    $changed = true;
  }
  $p = pendingGet();
  if ($d['queue'] && !testRunning() && !diagBusy() && !($p && in_array($p['state'], ['checking', 'waiting'], true))) {
    $h = array_shift($d['queue']);
    $d['sites'][$h]['last'] = time();
    if (($ce = curlCmdError()) !== null) {
      autoLog($d, $h, 'skip', 'Пропущен: не запускается программа curl (' . $ce . '), подбор не смог бы проверить ни одной стратегии.');
      autoSave($d);
      return;
    }
    if (!testLaunch(['type' => 'pick', 'host' => $h, 'proto' => 'https', 'sets' => ['config', 'std', 'hist', 'other', 'community'], 'repeats' => 3, 'refine' => false, 'auto' => true])) {
      array_unshift($d['queue'], $h);   // a pick from the page got in first: this site waits for the next pass
      autoSave($d);
      return;
    }
    autoLog($d, $h, 'start', 'Перестал открываться — запущен подбор стратегии.');
    autoSave($d);
    return;
  }
  if ($changed) {
    autoSave($d);
  }
}

// Конец автоподбора: записать итог, предложить или применить найденное, сообщить в Telegram
function autoFinish(): void
{
  $job = json_decode((string)@file_get_contents(TEST_DIR . '/job.json'), true);
  $st = testStatus();
  if (empty($job['auto']) || ($st['host'] ?? '') !== $job['host'] || in_array($st['state'] ?? '', ['running', 'starting'], true)) {
    return;
  }
  $h = $job['host'];
  $d = autoData();
  $msg = null;
  $full = array_values(array_filter($st['results'] ?? [], fn($r) => $r['ok'] > 0 && $r['ok'] === ($st['repeats'] ?? 3)));
  if ($st['state'] === 'error') {
    autoLog($d, $h, 'none', 'Подбор не запущен: ' . ($st['error'] ?? 'ошибка'));
    $msg = "🔎 $h: подбор не поможет. " . ($st['error'] ?? '');
  } elseif ($st['state'] === 'stopped') {
    autoLog($d, $h, 'none', 'Подбор остановлен вручную.');
  } elseif (!empty($st['baseline']['ok'])) {
    autoLog($d, $h, 'none', 'Без обхода сайт открывается — блокировки нет, мешает скорее нынешняя стратегия. Посмотрите диагноз сайта.');
    $msg = "🔎 $h: без обхода открывается — похоже, мешает нынешняя стратегия.";
  } elseif (!$full) {
    autoLog($d, $h, 'none', 'Ни одна стратегия не помогла (' . count($st['results'] ?? []) . ' проверено).');
    $msg = "🔎 $h: рабочая стратегия не нашлась.";
  } else {
    $best = $full[0];
    // если сработала стратегия уже существующего профиля, а сайта просто нет в его списке — хватит записи в списке
    $list = null;
    foreach ($full as $r) {
      if ($list = autoListTarget($h, (string)($r['from'] ?? ''))) {
        $best = $r;
        break;
      }
    }
    $d['offers'][$h] = ['ts' => time(), 'name' => $best['name'], 'steps' => $best['steps'], 'from' => $best['from']] + ($list ? ['list' => $list] : []);
    autoLog($d, $h, 'found', 'Найдена рабочая стратегия: ' . $best['name'] . ($list ? " — она уже стоит в профиле #{$list['profile']}, достаточно добавить сайт в {$list['name']}." : '.'));
    autoSave($d);
    if (uiSettings()['auto']['apply']) {
      $r = $list ? autoApplyList($h, $best['steps'], $list, 'автоподбор') : ['ok' => false];
      $viaList = $r['ok'];
      if (!$r['ok']) {
        // запись в списке не помогла или не подходит — отдельный профиль
        $r = autoApply($h, $best['steps'], 'автоподбор');
      }
      $d = autoData();
      $msg = $r['ok'] ? "🔧 $h: найдена и применена стратегия «{$best['name']}» — " . ($viaList ? "сайт добавлен в {$list['name']} (профиль #{$list['profile']})." : 'отдельный профиль только для этого сайта.')
        : "🔎 $h: найдена стратегия «{$best['name']}», но применить не удалось — {$r['text']}";
    } else {
      $msg = "🔎 $h: найдена рабочая стратегия «{$best['name']}». Применить можно в интерфейсе: «Автоподбор».";
    }
  }
  autoSave($d);
  if ($msg !== null) {
    notifyTelegram("nfqws2 на роутере:\n" . $msg);
  }
}

// Стратегия взята из профиля #N («профиль #9, #11»; «#N стратегия K» — circular, не годится), а до него сайт
// не доходит только потому, что его нет в списке сайтов профиля. Возвращает профиль и список, куда добавить сайт
function autoListTarget(string $host, string $from): ?array
{
  if (!preg_match_all('/#(\d+)(?=,|$)/', $from, $m)) {
    return null;
  }
  $profiles = currentProfiles();
  $route = matchRoute($profiles, $host, gethostbynamel($host) ?: [], 'tcp', 443, 'tls', new ListCache());
  foreach ($m[1] as $n) {
    $n = (int)$n;
    $step = current(array_filter($route['steps'], fn($x) => $x['profile'] === $n));
    $p = current(array_filter($profiles, fn($x) => $x['index'] === $n));
    if (!$step || !$p || ($step['miss'] ?? null) !== 'hostlist') {
      continue;
    }
    $lists = array_values(array_filter($p['hostlists'], fn($f) => dirname($f) === LISTS_DIR && is_file($f) && is_writable($f)));
    $pick = in_array(LISTS_DIR . '/user.list', $lists, true) ? LISTS_DIR . '/user.list' : ($lists[0] ?? null);
    if ($pick !== null) {
      return ['profile' => $n, 'name' => basename($pick)];
    }
  }
  return null;
}

// Запись сайта в список профиля: без перезапуска (nfqws2 перечитывает списки сам). Сайт должен открыться —
// иначе запись убирается, а предложение остаётся уже как «отдельный профиль»
function autoApplyList(string $host, array $steps, array $list, string $who): array
{
  $d = autoData();
  $done = function (bool $ok, string $kind, string $text) use (&$d, $host): array {
    autoLog($d, $host, $kind, $text);
    if ($ok) {
      unset($d['offers'][$host]);
    } else {
      unset($d['offers'][$host]['list']);
    }
    autoSave($d);
    return ['ok' => $ok, 'text' => $text];
  };
  $path = LISTS_DIR . '/' . basename((string)($list['name'] ?? ''));
  $n = (int)($list['profile'] ?? 0);
  // с момента подбора конфиг и списки могли измениться — условие проверяем заново
  $now = autoListTarget($host, "#$n");
  if (!$now || $now['name'] !== basename($path)) {
    return $done(false, 'fail', "В список не добавлено: профилю #$n уже мало записи в {$list['name']} — конфиг или списки изменились. Можно применить отдельным профилем.");
  }
  $old = (string)file_get_contents($path);
  set_time_limit(120);
  if (!writeWithBackup($path, ltrim(rtrim($old, "\n") . "\n$host", "\n"), "$who: $host в список профиля #$n")) {
    return $done(false, 'fail', 'В список не добавлено: не удалось записать файл.');
  }
  // nfqws2 замечает новый список при следующем соединении; первая попытка может уйти ещё по-старому
  sleep(2);
  $ok = false;
  for ($i = 0; $i < 3 && !($ok = probe($host)['ok']); $i++) {
    sleep(2);
  }
  if (!$ok) {
    writeWithBackup($path, $old, "откат ($who, $host): сайт не открылся");
    return $done(false, 'rolled', "Добавлен в {$list['name']} и убран обратно: $host так и не открылся. Можно применить отдельным профилем.");
  }
  $items = picksLoad();
  foreach ($items as &$e) {
    if ($e['host'] === $host) {
      $e['applied'] = ['ts' => time(), 'name' => strategyName($steps), 'steps' => $steps, 'target' => "список {$list['name']} профиля #$n ($who)"];
      picksSave($items);
      break;
    }
  }
  unset($e);
  monitorRun(true, [$host]);
  return $done(true, 'applied', "Сайт добавлен в {$list['name']}: теперь идёт через профиль #$n со стратегией " . strategyName($steps) . '. Сайт открывается, перезапуск не понадобился.');
}

// Отдельный профиль «только для этого сайта» в начале своих профилей (если такой уже есть — меняем его стратегию),
// перезапуск и проверка: сайт должен открыться, остальные сайты мониторинга — не сломаться. Иначе — откат.
function autoApply(string $host, array $steps, string $who): array
{
  $d = autoData();
  $done = function (bool $ok, string $kind, string $text) use (&$d, $host): array {
    autoLog($d, $host, $kind, $text);
    if ($ok) {
      unset($d['offers'][$host]);
    }
    autoSave($d);
    return ['ok' => $ok, 'text' => $text];
  };
  $p = pendingGet();
  if ($p && in_array($p['state'], ['checking', 'waiting'], true)) {
    return $done(false, 'fail', 'Не применено: идёт перезапуск с проверкой — дождитесь его конца.');
  }
  $old = (string)file_get_contents(CONF_FILE);
  $custom = confRawVars($old)['NFQWS_ARGS_CUSTOM'] ?? '';
  if (preg_match('/["`$\\\\]/', $custom)) {
    return $done(false, 'fail', 'Не применено: в «своих профилях» есть подстановки оболочки — такой конфиг правится только вручную.');
  }
  $part = array_merge(['--filter-tcp=443', '--filter-l7=tls', "--hostlist-domains=$host", '--payload=tls_client_hello'], $steps);
  $parts = array_values(array_filter(splitNew(tokens($custom))));
  $found = false;
  foreach ($parts as $i => $pt) {
    if (in_array("--hostlist-domains=$host", $pt, true) && in_array('--filter-tcp=443', $pt, true)) {
      $parts[$i] = $part;
      $found = true;
      break;
    }
  }
  if (!$found) {
    array_unshift($parts, $part);
  }
  $new = confSetVar($old, 'NFQWS_ARGS_CUSTOM', implode("\n--new\n", array_map(fn($pt) => implode("\n", $pt), $parts)));
  $lint = lintConf(confRawVars($new), $new);
  if (!$lint['dry_run'] || !$lint['dry_run']['ok']) {
    return $done(false, 'fail', 'Не применено: nfqws2 не принимает такой конфиг — ' . ($lint['dry_run']['message'] ?? 'проверка не прошла'));
  }
  $mon = monitorData();
  $others = [];
  foreach (uiSettings()['monitor']['sites'] as $h) {
    $hist = $mon['sites'][$h] ?? [];
    if ($h !== $host && $hist && end($hist)[1]) {
      $others[] = $h;
    }
  }
  set_time_limit(300);
  if (!writeWithBackup(CONF_FILE, $new, "$who: профиль только для $host")) {
    return $done(false, 'fail', 'Не применено: не удалось записать конфиг.');
  }
  serviceRun('restart');
  sleep(5);
  $why = null;
  if (findPid() === null) {
    $why = 'nfqws2 не запустился';
  } else {
    $twice = function (string $h): bool {
      return probe($h)['ok'] || (sleep(2) === 0 && probe($h)['ok']);
    };
    if (!$twice($host)) {
      $why = "$host так и не открылся";
    } else {
      foreach ($others as $h) {
        if (!$twice($h)) {
          $why = "перестал открываться $h";
          break;
        }
      }
    }
  }
  if ($why !== null) {
    writeWithBackup(CONF_FILE, $old, "откат ($who, $host): $why");
    // после отката сервис обязан работать: ждём запуска, при неудаче пробуем ещё раз
    $up = false;
    for ($try = 0; $try < 2 && !$up; $try++) {
      serviceRun('restart');
      for ($i = 0; $i < 10 && !($up = findPid() !== null); $i++) {
        sleep(1);
      }
    }
    return $done(false, 'rolled', "Применено и откачено: $why. Конфиг возвращён как был"
      . ($up ? ', nfqws2 работает.' : ', но nfqws2 после отката не запустился — проверьте сервис.'));
  }
  // отметка в истории подборов и свежая запись в мониторинге
  $items = picksLoad();
  foreach ($items as &$e) {
    if ($e['host'] === $host) {
      $e['applied'] = ['ts' => time(), 'name' => strategyName($steps), 'steps' => $steps, 'target' => "отдельный профиль для $host ($who)"];
      picksSave($items);
      break;
    }
  }
  unset($e);
  monitorRun(true, [$host]);
  return $done(true, 'applied', ($found ? 'Стратегия профиля для этого сайта заменена' : 'Создан отдельный профиль только для этого сайта') . ': ' . strategyName($steps) . '. Сайт открывается, остальные не пострадали.');
}
