<?php
// Server section: the state the page shows.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ================= состояние =================

function iptablesCounters(): array
{
  // из той же прочитанной таблицы mangle (iptables -v -S), без своих запусков iptables
  $rows = [];
  $all = mangleRules();
  foreach (['nfqws_post' => 'out', 'nfqws_pre' => 'in'] as $chain => $dir) {
    foreach (preg_grep('/^-A ' . $chain . ' .*-j NFQUEUE\b/', $all) as $line) {
      if (!preg_match('/ -c (\d+) (\d+)/', $line, $c)) {
        continue;
      }
      $proto = preg_match('/ -p (\S+)/', $line, $pm) ? $pm[1] : 'all';
      $rows[] = ['dir' => $dir, 'proto' => ['6' => 'TCP', '17' => 'UDP', 'tcp' => 'TCP', 'udp' => 'UDP'][$proto] ?? $proto,
        'what' => match (true) {
          (bool)preg_match('/--tcp-flags \S+ FIN\b/', $line) => 'FIN',
          (bool)preg_match('/--tcp-flags \S+ RST\b/', $line) => 'RST',
          (bool)preg_match('/--tcp-flags \S+ SYN,ACK\b/', $line) => 'SYN+ACK',
          default => 'data',
        },
        'pkts' => (int)$c[1], 'bytes' => (int)$c[2]];
    }
  }
  return $rows;
}

// Подключены ли цепочки nfqws2 и не ограничен ли перехват политикой доступа Keenetic (POLICY_NAME в конфиге).
// «Только политика»: мимо nfqws2 идёт всё без её метки — в том числе запросы самого роутера, то есть мониторинг и
// «Проверить сайт»; подбор при этом работает, у него свои правила. $full — ещё и сами правила, для отчёта.
// Таблица mangle целиком (iptables -v -S), одна на запрос; $fresh — перечитать после своих правок
function mangleRules(bool $fresh = false): array
{
  static $all = null;
  if ($all === null || $fresh) {
    $all = [];
    exec('iptables -w -t mangle -v -S 2>/dev/null', $all);
  }
  return $all;
}

// Сколько секунд цепочки должны быть «не подключены», чтобы это считалось поломкой, а не перестройкой правил
define('INTERCEPT_OFF_GRACE', 20);
define('INTERCEPT_OFF_FILE', '/tmp/nfqws-ui-intercept-off');

function interceptInfo(bool $full = false): array
{
  static $memo = null;
  if ($memo === null) {
    $memo = interceptRead(mangleRules());
    // Скрипт nfqws2-keenetic при каждом вызове сначала очищает свои цепочки, потом добавляет правила заново, а прошивка
    // Keenetic зовёт его при любой перестройке межсетевого экрана (PPPoE — часто). Чтение в этот момент видит пустые
    // цепочки без перехода — одно такое чтение ничего не значит: перечитываем и считаем поломкой, только если держится.
    // в файле: «с какого времени» и «когда видели последний раз»; отметка старше минуты не в счёт — между
    // чтениями правила могли быть в порядке
    [$since, $seen] = array_map('intval', explode(' ', (string)@file_get_contents(INTERCEPT_OFF_FILE) . ' 0 0'));
    if (time() - $seen > 60) {
      $since = 0;
    }
    if ($memo['broken'] && !($since && time() - $since >= INTERCEPT_OFF_GRACE)) {
      usleep(1500000);
      $memo = interceptRead(mangleRules(true));
    }
    $off = $memo['unhooked'];
    if (!$off) {
      is_file(INTERCEPT_OFF_FILE) && @unlink(INTERCEPT_OFF_FILE);
      $since = 0;
    } else {
      $since = $since ?: time();
      @file_put_contents(INTERCEPT_OFF_FILE, $since . ' ' . time());
    }
    // off — цепочки не подключены дольше INTERCEPT_OFF_GRACE; refs остаются сырыми, для отчёта
    $memo['off'] = $off && time() - $since >= INTERCEPT_OFF_GRACE;
    $memo['off_since'] = $since ?: null;
    // the init script puts the jumps back only from the firmware hook, and that hook gives up after five failed
    // attempts or when the PID file is gone — then nothing returns them until the next rebuild. Ask it ourselves
    if ($memo['off'] && interceptHeal()) {
      @unlink(INTERCEPT_OFF_FILE);
      $memo = interceptRead(mangleRules(true)) + ['off' => false, 'off_since' => null];
    }
    $h = interceptHealState();
    // healed — the jumps were put back in the last 24 h (a note for the owner: why the bypass stopped for a while)
    $memo['healed'] = $h['last'] && time() - $h['last'] < 86400 ? ['count' => $h['count'], 'last' => $h['last']] : null;
    $memo['heal_failed'] = $memo['off'] && $h['tried'] >= $since;
  }
  $r = $memo;
  unset($r['broken'], $r['unhooked']);
  if (!$full) {
    unset($r['rules']);
  }
  return $r;
}

// Putting the jumps back: «<init script> firewall_iptables» — the same the firmware hook calls; it rebuilds the
// chains and adds a missing jump, safe to repeat. Not more often than once a minute; only while nfqws2 runs.
// The file: «how many times it helped, when it last helped, when it was last tried»
define('INTERCEPT_FIX_FILE', '/tmp/nfqws-ui-intercept-fixed');

function interceptHealState(): array
{
  [$count, $last, $tried] = array_map('intval', explode(' ', (string)@file_get_contents(INTERCEPT_FIX_FILE) . ' 0 0 0'));
  return ['count' => $count, 'last' => $last, 'tried' => $tried];
}

function interceptHeal(): bool
{
  $h = interceptHealState();
  if (time() - $h['tried'] < 60 || !findPid()) {
    return false;
  }
  exec(INIT_SCRIPT . ' firewall_iptables >/dev/null 2>&1');
  $ok = !interceptRead(mangleRules(true))['unhooked'];
  @file_put_contents(INTERCEPT_FIX_FILE, ($h['count'] + ($ok ? 1 : 0)) . ' ' . ($ok ? time() : $h['last']) . ' ' . time());
  return $ok;
}

// cron pass: one reading could hit a rebuild, so the jumps must stay missing for INTERCEPT_OFF_GRACE first
function interceptCron(): void
{
  if (!findPid()) {
    return;
  }
  for ($i = 0; $i * 6 < INTERCEPT_OFF_GRACE; $i++) {
    if (!interceptRead(mangleRules(true))['unhooked']) {
      return;
    }
    sleep(6);
  }
  if (interceptRead(mangleRules(true))['unhooked'] && interceptHeal()) {
    @unlink(INTERCEPT_OFF_FILE);
  }
}

function interceptRead(array $all): array
{
  $refs = [];
  $rules = [];
  $policy = null;
  $empty = false;
  $of = fn(string $c) => array_values(preg_grep('/^-[NAP] ' . $c . '( |$)/', $all));
  foreach (['nfqws_post' => 'POSTROUTING', 'nfqws_pre' => 'PREROUTING'] as $chain => $hook) {
    $out = $of($chain);
    if (!$out) {
      $refs[$chain] = null;   // цепочки нет
      continue;
    }
    $refs[$chain] = count(preg_grep('/-j ' . $chain . '\b/', $of($hook)));
    $empty = $empty || !preg_grep('/^-A /', $out);
    foreach ($out as $l) {
      if (!str_starts_with($l, '-A ')) {
        continue;
      }
      $rules[] = $l;
      // метка политики — с маской 0x0fffffff; служебные метки nfqws2 (0x40000000, 0x20000000) сюда не подходят
      if ($chain === 'nfqws_post' && !$policy && preg_match('/-m mark (! )?--mark (0x[0-9a-f]+)\/0x0?fffffff\b.*-j RETURN/i', $l, $m)) {
        $policy = ['mode' => $m[1] !== '' ? 'only' : 'exclude', 'mark' => $m[2],
          'passed' => preg_match('/-c (\d+) \d+/', $l, $c) ? (int)$c[1] : null];
      }
    }
  }
  if ($policy) {
    $policy['name'] = preg_match('/^\s*POLICY_NAME=["\']?([^"\'\s#]*)/m', (string)@file_get_contents(CONF_FILE), $m) && $m[1] !== '' ? $m[1] : 'nfqws';
  }
  // цепочка есть, но без перехода или пустая — так бывает и посреди перестройки правил
  $unhooked = in_array(0, $refs, true);
  return ['refs' => $refs, 'policy' => $policy, 'rules' => $rules, 'broken' => $unhooked || $empty, 'unhooked' => $unhooked];
}

function restartNeeded(?array $proc): bool
{
  if (!$proc || !$proc['started']) {
    return false;
  }
  foreach (array_merge([CONF_FILE], array_values(luaFiles())) as $f) {
    // btime точен примерно до секунды
    if (@filemtime($f) > $proc['started'] + 2) {
      return true;
    }
  }
  return false;
}

// Почему «нужен перезапуск» — для отчёта и подсказки у плашки: какой файл новее процесса или какой профиль
// в конфиге не совпадает с работающим nfqws2
function restartWhy(?array $proc, array $expected, array $profiles): array
{
  $why = [];
  if ($proc && $proc['started']) {
    foreach (array_merge([CONF_FILE], array_values(luaFiles())) as $f) {
      $m = @filemtime($f);
      if ($m > $proc['started'] + 2) {
        $why[] = basename($f) . ' изменён через ' . ($m - $proc['started']) . ' с после запуска' . ($m > time() + 60 ? ' (время файла в будущем — часы роутера?)' : '');
      }
    }
  }
  $exp = array_column($expected, 'tokens');
  $run = array_column($profiles, 'args');
  if (count($exp) !== count($run)) {
    $why[] = 'профилей в конфиге ' . count($exp) . ', у работающего nfqws2 ' . count($run);
  }
  foreach ($exp as $i => $e) {
    $r = $run[$i] ?? null;
    if ($r === null || $r === $e) {
      continue;
    }
    $a = array_values(array_diff($e, $r));
    $b = array_values(array_diff($r, $e));
    $cut = fn($x) => strlen($x) > 60 ? substr($x, 0, 57) . '...' : $x;
    $why[] = 'профиль #' . ($i + 1) . ' не совпадает' . ($a ? '; только в конфиге: ' . implode(' ', array_map($cut, array_slice($a, 0, 3))) : '')
      . ($b ? '; только в работе: ' . implode(' ', array_map($cut, array_slice($b, 0, 3))) : '') . (!$a && !$b ? '; другой порядок аргументов' : '');
    if (count($why) >= 4) {
      break;
    }
  }
  return $why;
}

function state(): array
{
  $seen = seenInfo();   // первым: до того, как состояние само создаст файлы, по которым видно «уже пользовались»
  historyScan('auto');
  $pid = findPid();
  $proc = $pid ? processInfo($pid) : null;
  $expected = expectedProfiles(confValues());
  $globals = [];
  $profiles = loadProfiles($pid, $expected, $globals);
  return [
    'now' => time(),
    'running' => $pid !== null,
    'stop' => $pid === null ? stopReason() : null,
    'rivals' => rivals(),
    'process' => $proc,
    'version' => packageVersion('nfqws2-keenetic'),
    'globals' => $globals,
    'in_sync' => array_column($expected, 'tokens') === array_column($profiles, 'args'),
    'restart_needed' => restartNeeded($proc),
    'restart_why' => $pid ? restartWhy($proc, $expected, $profiles) : [],
    'pid_lost' => pidLost($pid),
    'conf_mtime' => @filemtime(CONF_FILE),
    'profiles' => $profiles,
    'lists' => listsInventory($profiles),
    'iptables' => iptablesCounters(),
    'intercept' => (function () {
      $i = interceptInfo();
      if ($i['policy']) {
        $i['policy']['probe'] = policyProbeEnsure($i['policy']);   // проверки роутера идут как от устройства политики
      }
      return $i;
    })(),
    'logs' => nfqLogs(),
    'devices' => devicesEnsure(),   // null — bypass for the whole network; false — rules could not be put in place
    'learn_tmp_locked' => !learnTmpOk() && learnOwners(),   // a «learn» profile whose module cannot write to /tmp
    'leftovers' => array_column(pkgLeftovers(), 'name'),
    'nfq_update' => (function () { $u = nfqUpdateInfo(); return ['latest' => $u['latest'], 'available' => $u['available'], 'running' => $u['running'], 'rollback' => $u['rollback']]; })(),
    'curl' => curlCmdError(),
    'php_curl' => ($pc = phpCurlError()),
    'php_upgrade' => phpCurlOld($pc) ? (pkgApk() ? PHP_UPGRADE_APK : PHP_UPGRADE_OPKG) : null,
    'pkg_apk' => pkgApk(),
    'lint' => lintCurrent(),
    'load' => routerLoad(),
    'conf_tokens' => array_map('tokens', array_intersect_key(confRawVars(file_get_contents(CONF_FILE)), array_flip(ARG_VARS))),
    // Профили по конфигу (для редактора) — могут отличаться от запущенных до перезапуска
    'conf_profiles' => $pid === null ? $profiles : loadProfiles(null, $expected, $globals),
    'subs' => uiSettings()['subs'],
    'ui' => (function () use ($seen) {
      $w = uiWeb();
      return ['version' => UI_VERSION, 'seen' => $seen, 'conf_file' => CONF_FILE, 'https_port' => $w['https_port'], 'legacy_port' => $w['legacy_port'], 'auth' => authEnabled(), 'session' => sessionLife(), 'restart_check' => uiSettings()['restart_check'] !== false,
        'provider' => uiSettings()['provider'], 'platform' => ROOT ? 'Keenetic' : 'OpenWrt', 'repo' => REPO_URL, 'update' => updateInfo() + ['running' => updateRunning()]];
    })(),
    'undo' => (function () {
      $u = lastUndoable();
      return $u ? ['ts' => $u['ts'], 'files' => $u['files'], 'note' => $u['note']] : null;
    })(),
    'pending' => (function () {
      pendingExpire(60);
      return pendingView();
    })(),
    'lowmem' => lowMem(),
    'rollback' => (function () {
      $T = confirmPoint();
      return ['since' => $T, 'files' => array_values(array_unique(array_map('basename', array_keys(rollbackVersions($T)))))];
    })(),
    'monitor' => (function () {
      $cfg = uiSettings()['monitor'];
      $d = monitorData();
      $sites = [];
      foreach ($cfg['sites'] as $h) {
        $hist = $d['sites'][$h] ?? [];
        $sites[] = ['host' => $h, 'last' => $hist ? end($hist) : null, 'recent' => array_map(fn($x) => $x[1] ? max(1, (int)$x[2]) : 0, array_slice($hist, -24))];   // response time, 0 = failed (an older page reads it as true/false)
      }
      return ['enabled' => $cfg['enabled'], 'interval' => $cfg['interval'], 'last' => $d['last'] ?? 0, 'sites' => $sites];
    })(),
    'auto' => (function () {
      $d = autoData();
      $offers = [];
      foreach ($d['offers'] as $h => $o) {
        $offers[] = ['host' => (string)$h, 'ts' => $o['ts'], 'name' => $o['name'], 'list' => $o['list'] ?? null];
      }
      return ['enabled' => uiSettings()['auto']['enabled'], 'offers' => $offers, 'queue' => $d['queue']];
    })(),
    'snap' => (function () {
      $idx = snapIndex();
      // count — все снимки, где бы ни лежали; local — на роутере; remote — на NAS
      $loc = array_filter($idx, fn($e) => $e['local'] ?? true);
      return ['count' => count($idx), 'last' => $idx ? end($idx)['ts'] : null, 'local' => count($loc),
        'remote' => remoteOn() ? count(array_filter($idx, fn($e) => !empty($e['remote']))) : null,
        'size' => array_sum(array_map(fn($e) => $e['size'], $loc))];
    })(),
  ];
}

// Кандидат конфига: текущий файл с подставленными значениями из формы
function candidateFromVars(array $vars): array
{
  $text = file_get_contents(CONF_FILE);
  foreach ($vars as $n => $v) {
    if (!in_array($n, CONF_VARS, true) || !is_string($v)) {
      fail("Неизвестная переменная $n");
    }
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    if ($n === 'NFQWS_EXTRA_ARGS') {
      if (!in_array($v, ['$MODE_LIST', '$MODE_ALL', '$MODE_AUTO'], true)) {
        fail('Недопустимый режим');
      }
    } elseif (preg_match('/\$\{?' . $n . '\b/', $v)) {
      // NFQWS_ARGS_CUSTOM="$NFQWS_ARGS_CUSTOM --new …": интерфейс видит только последнее присваивание — сохранив, потерял бы остальные
      fail("$n в конфиге собирается по частям ($n=\"\$$n …\") — интерфейс видит только последнюю часть и при сохранении потерял бы остальные. "
        . 'Соберите профили в одну переменную через --new («Конфиг целиком»), тогда кнопки заработают.');
    } elseif (preg_match('/["`$\\\\]/', $v, $bad)) {
      $tok = preg_match('/\S*["`$\\\\]\S*/', $v, $tm) ? $tm[0] : $bad[0];
      fail("$n: нельзя использовать символы \" ` \$ \\ — встретилось «{$bad[0]}» в «" . (strlen($tok) > 60 ? substr($tok, 0, 57) . '...' : $tok) . '»');
    }
    $text = confSetVar($text, $n, $v);
  }
  return [$text, array_merge(confRawVars($text), $vars)];
}

function saveConf(string $text, array $raw, bool $force, string $note = 'правка конфига'): void
{
  $lint = lintConf($raw, $text);
  $errors = array_filter($lint['issues'], fn($x) => $x['level'] === 'error');
  // Отказ nfqws2 или ошибка sh — сервис не запустится, такое не сохраняем даже с force
  $fatal = !$lint['dry_run'] || !$lint['dry_run']['ok'];
  if ($errors && (!$force || $fatal)) {
    respond(['error' => 'В конфиге есть ошибки', 'issues' => lintMark($lint)['issues'], 'fatal' => $fatal, 'dry_run' => $lint['dry_run']], 422);
  }
  if (!writeWithBackup(CONF_FILE, $text, $note)) {
    fail('Не удалось записать конфиг', 500);
  }
  respond(['ok' => true, 'issues' => lintMark($lint)['issues']]);
}

function currentProfiles(): array
{
  $pid = findPid();
  $globals = [];
  return loadProfiles($pid, expectedProfiles(confValues()), $globals);
}
