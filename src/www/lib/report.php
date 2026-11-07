<?php
// Server section: stop reason, rivals, profile sharing, the help report, lists by ASN.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ================= вторая очередь: причина остановки, другие обходчики, обмен профилями, отчёт, списки по ASN =================

// Почему nfqws2 не работает — от самой вероятной причины к общей
function stopReason(): array
{
  $log = [];
  if (!ROOT) {
    exec('logread -e nfqws2 2>/dev/null | tail -n 6', $log);
  }
  $auto = null;
  if (!ROOT && is_file(INIT_SCRIPT)) {
    exec(INIT_SCRIPT . ' enabled 2>/dev/null', $o, $rc);
    $auto = $rc === 0;
  }
  $r = ['log' => array_values(array_filter(array_map('trim', $log))), 'autostart' => $auto];
  if (!is_file(NFQWS_BIN)) {
    return $r + ['kind' => 'nobin', 'text' => 'Нет программы nfqws2 (' . NFQWS_BIN . ') — пакет nfqws2-keenetic не установлен или удалён.'];
  }
  if (!is_file(INIT_SCRIPT)) {
    return $r + ['kind' => 'nobin', 'text' => 'Нет скрипта запуска ' . INIT_SCRIPT . ' — пакет nfqws2-keenetic установлен не полностью.'];
  }
  $dry = lintCurrent()['dry_run'] ?? null;
  if ($dry && !$dry['ok']) {
    return $r + ['kind' => 'conf', 'text' => 'nfqws2 не принимает конфиг: ' . $dry['message']];
  }
  if ($auto === false) {
    return $r + ['kind' => 'disabled', 'text' => 'Сервис остановлен, и автозапуск выключен — после перезагрузки роутера он сам не поднимется.'];
  }
  return $r + ['kind' => 'manual', 'text' => 'Конфиг в порядке — сервис остановлен вручную или завершился сам. Попробуйте запустить; если не получится, ниже — последние строки журнала.'];
}

// Другие обходчики, работающие одновременно: они правят те же пакеты
function rivals(): array
{
  $names = ['nfqws' => 'zapret (nfqws)', 'tpws' => 'zapret (tpws)', 'youtubeUnblock' => 'youtubeUnblock', 'b4' => 'b4',
    'ciadpi' => 'byedpi', 'byedpi' => 'byedpi', 'dpitunnel' => 'DPITunnel', 'spoofdpi' => 'SpoofDPI', 'goodbyedpi' => 'GoodbyeDPI'];
  $main = findPid();
  $found = [];
  foreach (glob('/proc/[0-9]*/comm') ?: [] as $f) {
    $c = trim((string)@file_get_contents($f));
    $pid = (int)basename(dirname($f));
    if (isset($names[$c])) {
      $found[$names[$c]] = true;
    } elseif ($c === 'nfqws2' && $pid !== $main && !preg_match('/--qnum=' . TEST_QNUM . '\b|--dry-run|--intercept=0/', (string)@file_get_contents("/proc/$pid/cmdline"))) {
      // --dry-run — наша же проверка конфига: на медленном роутере она идёт заметное время и попадала сюда
      // --intercept=0 — сборка пакета QUIC для зонда (quicPacket): тоже наш запуск, пакеты он не трогает
      $found['ещё один nfqws2 (zapret2)'] = true;
    }
  }
  return array_keys($found);
}

function ripe(string $call, string $resource = '', int $timeout = 25): ?array
{
  $ch = curl_init("https://stat.ripe.net/data/$call/data.json?sourceapp=nfqws2-ui" . ($resource !== '' ? '&resource=' . rawurlencode($resource) : ''));
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(8, $timeout), CURLOPT_NOSIGNAL => 1]);
  $j = json_decode((string)curl_exec($ch), true);
  curl_close($ch);
  return is_array($j['data'] ?? null) ? $j['data'] : null;
}

// Вставленный из чата профиль: оставляем только известные параметры профиля, поправляем пути, проверяем у nfqws2
function profileCheck(array $tokens): array
{
  $opts = helpInfo()['options'];
  $clean = [];
  $rejected = [];
  $fixed = [];
  $missing = [];
  foreach ($tokens as $t) {
    if (!is_string($t) || !preg_match('/^--([a-z0-9-]+)(=[^"`$\\\\\s]*)?$/', $t, $m)) {
      $rejected[] = ['tok' => is_string($t) ? $t : '?', 'why' => 'не параметр nfqws2'];
      continue;
    }
    if (!isset($opts[$m[1]])) {
      $rejected[] = ['tok' => $t, 'why' => 'nfqws2 на этом роутере такого параметра не знает'];
      continue;
    }
    if ($opts[$m[1]]['global'] || $m[1] === 'new') {
      $rejected[] = ['tok' => $t, 'why' => 'общий параметр процесса, в профиль не входит'];
      continue;
    }
    // nfqws2 при проверке не смотрит, есть ли такая lua-функция, — смотрим сами
    if ($m[1] === 'lua-desync' && !isset(luaCatalog()['functions'][explode(':', substr($t, 13))[0]])) {
      $rejected[] = ['tok' => $t, 'why' => 'такой функции нет в lua-скриптах этого роутера (другая версия nfqws2?)'];
      continue;
    }
    if (preg_match('/^--(hostlist|hostlist-exclude|hostlist-auto|ipset|ipset-exclude)=(.+)$/', $t, $f) && !is_file($f[2])) {
      $alt = LISTS_DIR . '/' . basename($f[2]);
      if (is_file($alt)) {
        $fixed[] = ['from' => $f[2], 'to' => $alt];
        $t = "--{$f[1]}=$alt";
      } elseif ($f[1] === 'hostlist-auto') {
        $t = "--{$f[1]}=$alt";   // этот файл nfqws2 создаст сам
      } else {
        $missing[] = $f[2];
      }
    }
    $clean[] = $t;
  }
  $dry = null;
  if ($clean && !$missing) {
    $exp = confValues();
    $exp['NFQWS_ARGS_CUSTOM'] = implode(' ', $clean) . (trim($exp['NFQWS_ARGS_CUSTOM']) !== '' ? ' --new ' . $exp['NFQWS_ARGS_CUSTOM'] : '');
    $d = dryRun($exp);
    $dry = ['ok' => $d['ok'], 'message' => $d['message']];
  }
  return ['tokens' => $clean, 'rejected' => $rejected, 'fixed' => $fixed, 'missing' => $missing, 'dry_run' => $dry,
    'steps' => array_values(array_filter($clean, fn($t) => str_starts_with($t, '--lua-desync=')))];
}

// Свои стратегии для подбора (поле «Своя стратегия»): у каждой оставляем шаги --lua-desync и говорим, что не так,
// до запуска — иначе непонятная строка выглядела бы как «не открылось». Остальные параметры профиля (фильтры,
// списки, --payload) в проверке не участвуют: подбор сам ставит фильтр под сайт и протокол.
const OWN_MAX = 10;     // стратегий за один запуск
const OWN_STEPS = 20;   // шагов в одной стратегии

// own strategies from a pick request («own»: [[steps], …]) — only --lua-desync steps, at most OWN_MAX × OWN_STEPS
function ownFromRequest($own): array
{
  $list = [];
  foreach (array_slice(is_array($own) ? $own : [], 0, OWN_MAX) as $o) {
    $st = array_values(array_filter(is_array($o) ? $o : [], fn($t) => is_string($t) && preg_match('/^--lua-desync=[^\s"`$\\\\]+$/', $t)));
    if ($st) {
      $list[] = array_slice($st, 0, OWN_STEPS);
    }
  }
  return $list;
}
function strategyCheck(array $items, string $proto): array
{
  $opts = helpInfo()['options'];
  $lua = luaCatalog();
  // блобы, объявленные в конфиге (--blob=имя:…), встроенные и заданные в lua
  preg_match_all('/--blob=([^:\s"]+):/', implode(' ', array_intersect_key(confValues(), array_flip(ARG_VARS))), $bm);
  $blobs = array_flip(array_merge($bm[1], BUILTIN_BLOBS, $lua['globals']));
  $out = [];
  foreach (array_slice($items, 0, OWN_MAX) as $tokens) {
    $steps = [];
    $errors = [];
    $skipped = [];
    $notes = [];
    foreach (array_slice(is_array($tokens) ? $tokens : [], 0, 200) as $t) {
      if (!is_string($t) || !preg_match('/^--([a-z0-9-]+)(=[^"`$\\\\\s]*)?$/', $t, $m)) {
        $errors[] = (is_string($t) ? $t : '?') . ' — не параметр nfqws2';
        continue;
      }
      if ($m[1] !== 'lua-desync') {
        // фильтры и прочее от чужого профиля — не ошибка, просто здесь не нужны
        $skipped[] = isset($opts[$m[1]]) ? $t : $t . ' (такого параметра nfqws2 на этом роутере не знает)';
        continue;
      }
      $s = stepParse($t);
      if (!isset($lua['functions'][$s['fn']])) {
        $errors[] = $t . ' — функции «' . $s['fn'] . '» нет в lua-скриптах этого роутера (другая версия nfqws2?)';
        continue;
      }
      foreach (['blob', 'seqovl_pattern', 'pattern'] as $k) {
        $v = $s['p'][$k] ?? null;
        if (is_string($v) && $v !== '' && !str_starts_with($v, '0x') && !isset($blobs[$v])) {
          // not in the config, but the blobs folder has its file — the test nfqws2 declares it itself (blobAutoArgs)
          if (blobFileFor($v)) {
            $notes[] = "блоб «{$v}» в конфиге не объявлен — для проверки взят файл " . basename(blobFileFor($v)) . ' из папки blobs';
            continue;
          }
          $errors[] = $t . ' — блоб «' . $v . '» в вашем конфиге не объявлен, и файла ' . $v . ' или ' . $v . '.bin в папке blobs нет (у автора стратегии он задан параметром --blob)';
          continue 2;
        }
      }
      if (count($steps) < OWN_STEPS) {
        $steps[] = $t;
      }
    }
    if (!$steps && !$errors) {
      $errors[] = 'нет ни одного шага --lua-desync';
    }
    $out[] = ['steps' => $steps, 'name' => $steps ? strategyName($steps) : '', 'errors' => $errors, 'skipped' => $skipped, 'notes' => array_values(array_unique($notes))];
  }
  // принимает ли nfqws2 сами шаги: сначала все разом, при отказе — по одной, чтобы назвать виноватую
  $filter = $proto === 'quic' ? ['--filter-udp=443', '--filter-l7=quic'] : ($proto === 'http' ? ['--filter-tcp=80', '--filter-l7=http'] : ['--filter-tcp=443', '--filter-l7=tls']);
  $dry = function (array $list) use ($filter): array {
    $exp = confValues();
    $mine = implode(' --new ', array_map(fn($st) => implode(' ', array_merge($filter, $st)), $list));
    $exp['NFQWS_ARGS_CUSTOM'] = $mine . (trim($exp['NFQWS_ARGS_CUSTOM']) !== '' ? ' --new ' . $exp['NFQWS_ARGS_CUSTOM'] : '');
    // blobs taken from the folder for the test only — declared here too, or nfqws2 refuses the strategy
    $auto = blobAutoArgs(array_merge(tokens($exp['NFQWS_BASE_ARGS']), array_merge(...array_values($list))));
    $exp['NFQWS_BASE_ARGS'] = trim($exp['NFQWS_BASE_ARGS'] . ' ' . implode(' ', $auto));
    return dryRun($exp);
  };
  $good = array_keys(array_filter($out, fn($x) => $x['steps'] && !$x['errors']));
  if ($good && !$dry(array_map(fn($i) => $out[$i]['steps'], $good))['ok']) {
    foreach ($good as $i) {
      $d = $dry([$out[$i]['steps']]);
      if (!$d['ok']) {
        $out[$i]['errors'][] = 'nfqws2 не принимает эту стратегию: ' . $d['message'];
      }
    }
  }
  return ['items' => $out, 'max' => OWN_MAX];
}

// Отчёт для помощи: текст без секретов — версии, состояние, профили, списки, мониторинг, подборы
function reportText(array $sites, bool $hide, string $build): string
{
  $st = state();
  $s = uiSettings();
  $os = ROOT ? 'Keenetic (Entware)' : 'OpenWrt';
  if (!ROOT && preg_match("/DISTRIB_DESCRIPTION='([^']*)'/", (string)@file_get_contents('/etc/openwrt_release'), $m)) {
    $os = $m[1];
  }
  $L = ['Отчёт nfqws2-ui · ' . ldate('Y-m-d H:i'), '', '== Версии ==',
    'nfqws2: ' . ($st['version'] ?: 'не определена'), 'nfqws2-ui: ' . UI_VERSION . ($build !== '' ? " (сборка $build)" : ''),
    'система: ' . $os . ' · ' . sysArch() . ' · ядро ' . php_uname('r'), 'провайдер: ' . ($s['provider'] !== '' ? $s['provider'] : 'не указан'), '', '== Сервис =='];
  if ($st['running']) {
    $L[] = 'работает' . (!empty($st['process']['started']) ? ', запущен ' . ldate('Y-m-d H:i', (int)$st['process']['started']) : '')
      . ($st['restart_needed'] || !$st['in_sync'] ? ' · конфиг изменён после запуска — нужен перезапуск' : '');
    foreach ($st['restart_why'] as $w) {
      $L[] = '  причина: ' . $w;
    }
    if (ROOT !== '') {
      $L[] = 'файл PID: ' . ($st['pid_lost'] ? 'не совпадает с работающим nfqws2 (PID ' . $st['process']['pid'] . ', в файле: ' . (trim((string)@file_get_contents(PID_FILE)) ?: 'пусто или нет файла') . ') — скрипт запуска его не видит, перезапуск не сработает' : 'совпадает');
    }
  } else {
    $L[] = 'ОСТАНОВЛЕН. ' . $st['stop']['text'];
    foreach ($st['stop']['log'] as $l) {
      $L[] = '  ' . $l;
    }
  }
  $L[] = 'другие обходчики: ' . ($st['rivals'] ? implode(', ', $st['rivals']) : 'не найдены') . (is_file('/etc/init.d/podkop') ? ' · podkop установлен' : '');
  $L[] = '';
  $L[] = '== Замечания к конфигу ==';
  // the report shows notes hidden with «don't show» too: whoever helps should see them
  $hide = uiSettings()['lint_hide'];
  $issues = array_filter(lintCurrentRaw()['issues'], fn($x) => $x['level'] !== 'info');
  foreach ($issues as $x) {
    $L[] = ($x['level'] === 'error' ? 'ОШИБКА: ' : 'предупреждение: ') . $x['msg'] . ($x['level'] !== 'error' && isset($hide[lintHideKey($x)]) ? ' (скрыто в интерфейсе)' : '');
  }
  if (!$issues) {
    $L[] = 'нет';
  }
  $exp = confValues();
  $L[] = '';
  $L[] = '== Основное ==';
  foreach (['ISP_INTERFACE', 'TCP_PORTS', 'UDP_PORTS', 'IPV6_ENABLED', 'NFQWS_EXTRA_ARGS'] as $v) {
    $L[] = "$v=" . ($v === 'NFQWS_EXTRA_ARGS' ? (confRawVars((string)file_get_contents(CONF_FILE))[$v] ?? '') : $exp[$v]);
  }
  $L[] = 'параметры запуска: ' . implode(' ', tokens($exp['NFQWS_BASE_ARGS']));
  // для разбора «в подборе открывается, а в мониторинге нет»: есть ли IPv6 от провайдера и ловят ли правила пакеты
  $v6 = [];
  exec('ip -6 route show default 2>/dev/null', $v6);
  // Keenetic without IPv6 keeps "unreachable default dev lo" — that is no route at all
  $v6 = array_values(array_filter($v6, fn($l) => !preg_match('/^(unreachable|prohibit|blackhole)\b|\bdev lo\b/', trim($l))));
  $L[] = 'IPv6 от провайдера: ' . ($v6 ? 'есть (' . preg_replace('/\s+/', ' ', preg_replace('/[0-9a-f]*:[0-9a-f:]+/i', '…', $v6[0])) . ')' : 'нет');
  // политика доступа Keenetic: при «только политика» запросы самого роутера идут мимо nfqws2
  $raw = (string)file_get_contents(CONF_FILE);
  foreach (['POLICY_NAME', 'POLICY_EXCLUDE'] as $v) {
    $L[] = "$v=" . (preg_match('/^\s*' . $v . '=["\']?([^"\'\s#]*)/m', $raw, $m) ? $m[1] : '(не задано)');
  }
  $ic = interceptInfo(true);
  $pol = $ic['policy'];
  $L[] = 'политика доступа: ' . (!$pol ? 'не действует — nfqws2 обрабатывает все соединения через интерфейс провайдера'
    : '«' . $pol['name'] . '», метка ' . $pol['mark'] . ' — ' . ($pol['mode'] === 'only'
      ? 'обрабатываются ТОЛЬКО её устройства; проверки роутера (мониторинг, «Проверить сайт») '
        . (policyProbeEnsure($pol) ? 'идут как от устройства политики (цепочка ' . PROBE_CHAIN . ', порты ' . PROBE_PORTS . ')' : 'идут МИМО nfqws2 — пометить их меткой политики не удалось')
      : 'её устройства исключены из обработки')
      . ($pol['passed'] !== null ? '; мимо по этому правилу прошло пакетов: ' . $pol['passed'] : ''));
  foreach (['nfqws_post' => 'POSTROUTING', 'nfqws_pre' => 'PREROUTING'] as $chain => $hook) {
    $n = $ic['refs'][$chain];
    $L[] = "цепочка $chain: " . ($n === null ? 'НЕТ' : ($n ? "подключена к $hook" : ($ic['off']
      ? "есть, но к $hook НЕ подключена — пакеты в неё не попадают (уже " . (time() - $ic['off_since']) . ' с)'
      : "есть, но к $hook в момент чтения не подключена — вероятно, правила перестраивались")));
  }
  $hs = interceptHealState();
  $L[] = 'переходы в цепочки nfqws2 подключались заново: ' . ($hs['count'] ? 'сколько раз: ' . $hs['count'] . ', последний — ' . ldate('d.m H:i', $hs['last']) : 'нет')
    . ($ic['heal_failed'] ? ' (последняя попытка не помогла)' : '');
  if (ROOT === '') {
    // counts only: names and MACs of home devices do not belong in a report people post in chats
    $dv = devicesConf();
    $off = count(array_filter($dv['list'], fn($x) => empty($x['on'])));
    $on = count($dv['list']) - $off;
    $L[] = 'обход для устройств: ' . (!devicesActive($dv) ? 'для всей сети'
      : ($dv['new_on'] ? "для всех, кроме выключенных ($off)" : "только для включённых ($on)") . '; правила '
        . (devicesEnsure() ? 'на месте (цепочка ' . DEV_CHAIN . ', метка ' . DEV_MARK . ')' : 'НЕ стоят'));
  }
  $L[] = 'правила перехвата (-c пакеты байты; счётчики обнуляются при каждой перезагрузке правил):';
  foreach ($ic['rules'] as $r) {
    $L[] = '  ' . $r;
  }
  if (!$ic['rules']) {
    $L[] = '  нет';
  }
  $L[] = '';
  $L[] = '== Профили (в порядке проверки) ==';
  foreach ($st['conf_profiles'] as $p) {
    $L[] = '#' . $p['index'] . ' [' . ($p['source']['source'] ?? '?') . ']' . (in_array($p['state'] ?? '', ['dead', 'excludes-only'], true) ? ' (не срабатывает)' : (($p['state'] ?? '') === 'off' ? ' (выключен)' : ''));
    $L[] = '  ' . implode(' ', $p['args'] ?? []);
  }
  $L[] = '';
  $L[] = '== Списки ==';
  foreach ($st['lists'] as $l) {
    $L[] = $l['name'] . ': ' . $l['entries'] . ' записей' . ($l['used'] ? ', профили ' . implode(', ', array_unique(array_map(fn($u) => '#' . $u['profile'], $l['used']))) : ', не используется');
  }
  $L[] = '';
  $L[] = '== Мониторинг ==';
  foreach ($st['monitor']['sites'] as $m) {
    $L[] = $m['host'] . ': ' . (!$m['last'] ? 'не проверялся' : ($m['last'][1] ? 'открывается, ' . $m['last'][2] . ' мс' : 'НЕ открывается — ' . $m['last'][3]));
  }
  $L[] = '';
  $L[] = '== Последние подборы ==';
  foreach (array_slice(picksLoad(), 0, 8) as $e) {
    $L[] = ldate('m-d H:i', $e['ts']) . ' ' . $e['host'] . ': без обхода — ' . (!empty($e['baseline']['ok']) ? 'открывается' : ($e['baseline']['reason'] ?? '?'))
      . '; сработало ' . ($e['ok'] ?? 0) . ' из ' . ($e['total'] ?? 0) . ($e['best'] ?? null ? '; лучшая: ' . $e['best'] : '');
  }
  if ($sites) {
    $L[] = '';
    $L[] = '== Проверка сайтов ==';
    foreach ($sites as $h) {
      $dns = diagDns($h);
      $pr = probe($h);
      $pk = podkopRoute($h);
      $L[] = $h . ': DNS — ' . $dns['status'] . ' (' . count($dns['ips'] ?? []) . ' адр.)'
        . '; через текущий конфиг — ' . ($pr['ok'] ? 'открывается, HTTP ' . $pr['code'] . ', ' . $pr['ms'] . ' мс' : 'НЕ открывается: ' . $pr['reason'])
        . ($pk && !empty($pk['proxy']) ? '; идёт через podkop' : '');
    }
  }
  // окружение: по нему видно, почему не грузится модуль или не хватает памяти, — раньше это выясняли командами в чате
  $L[] = '';
  $L[] = '== PHP, пакеты, память ==';
  $L[] = 'PHP ' . PHP_VERSION . ' (' . (PHP_INT_SIZE * 8) . ' бит), memory_limit ' . ini_get('memory_limit') . ', модуль curl: '
    . ($st['php_curl'] === null ? 'загружен' : 'НЕТ — ' . $st['php_curl']) . ', программа curl: ' . ($st['curl'] === null ? 'работает' : 'НЕТ — ' . $st['curl']);
  $L[] = 'модули PHP: ' . implode(' ', array_map('strtolower', get_loaded_extensions()));
  $pk = [];
  foreach (['php8', 'php8-cgi', 'php8-mod-curl', 'libcurl', 'libcurl4', 'curl', 'libpcre2', 'libxml2', 'lighttpd'] as $n) {
    if (($v = packageVersion($n)) !== null || $n === 'php8-mod-curl') {
      $pk[] = $n . ' ' . ($v ?? 'не установлен');
    }
  }
  $L[] = 'пакеты: ' . implode(', ', $pk);
  $mi = (string)@file_get_contents('/proc/meminfo');
  $mb = fn($k) => preg_match('/^' . $k . ':\s+(\d+)/m', $mi, $m) ? round((int)$m[1] / 1024) . ' МБ' : '?';
  $L[] = 'память: всего ' . $mb('MemTotal') . ', доступно ' . $mb('MemAvailable') . ', подкачка свободна ' . $mb('SwapFree');
  $fs = [];
  foreach (array_unique([ROOT !== '' ? '/opt' : '/overlay', '/tmp']) as $d) {
    if (is_dir($d) && ($f = @disk_free_space($d)) !== false) {
      $fs[] = $d . ' ' . round($f / 1048576, 1) . ' МБ';
    }
  }
  $L[] = 'свободно на диске: ' . ($fs ? implode(', ', $fs) : '?');
  $php = array_slice(array_filter(explode("\n", (string)@file_get_contents(PHP_LOG))), -20);
  $L[] = '';
  $L[] = '== Ошибки PHP (последние ' . count($php) . ') ==';
  foreach ($php as $l) {
    $L[] = substr($l, 0, 300);
  }
  $text = implode("\n", $L) . "\n";
  if ($hide) {
    // имена сайтов заменяем на «сайт-N»: из мониторинга, подборов, профилей «только для сайта» и запрошенных проверок
    $hosts = array_merge(array_column($st['monitor']['sites'], 'host'), array_column(picksLoad(), 'host'), $sites);
    preg_match_all('/--hostlist-domains=(\S+)/', $text, $m);
    foreach ($m[1] as $v) {
      $hosts = array_merge($hosts, explode(',', $v));
    }
    $hosts = array_values(array_unique(array_filter($hosts)));
    usort($hosts, fn($a, $b) => strlen($b) <=> strlen($a));
    foreach ($hosts as $i => $h) {
      $text = str_replace($h, 'сайт-' . ($i + 1), $text);
    }
  }
  return $text;
}

// ----- список IP по номеру автономной системы (RIPEstat) -----

function asnResolve(string $q): array
{
  $q = trim($q);
  if (preg_match('/^(?:AS)?(\d{1,10})$/i', $q, $m)) {
    return ['asn' => (int)$m[1], 'via' => null];
  }
  $host = cleanHost($q);
  if (!validHost($host)) {
    fail('Введите номер AS (например AS24940) или имя сайта');
  }
  $ip = isIp($host) ? $host : (diagDns($host)['ips'][0] ?? null);
  if (!$ip) {
    fail("Не удалось узнать адрес $host");
  }
  if (isIp4($ip) && ipReserved($ip)) {
    fail("Адрес $host — служебный ($ip): сайт идёт через podkop или DNS отдаёт заглушку. Укажите номер AS.");
  }
  $ni = ripe('network-info', $ip);
  if (empty($ni['asns'][0])) {
    fail("RIPEstat не знает, чьей сети принадлежит $ip");
  }
  return ['asn' => (int)$ni['asns'][0], 'via' => "$host → $ip → {$ni['prefix']}"];
}

function asnFetch(int $asn): array
{
  $ov = ripe('as-overview', "AS$asn");
  $pf = ripe('announced-prefixes', "AS$asn");
  if ($pf === null) {
    fail('RIPEstat не ответил — попробуйте позже');
  }
  $v6 = strtolower(trim(confValues()['IPV6_ENABLED'] ?? '')) === 'true' || (confValues()['IPV6_ENABLED'] ?? '') === '1';
  $v4 = [];
  $six = [];
  foreach ($pf['prefixes'] ?? [] as $p) {
    $x = (string)($p['prefix'] ?? '');
    if (preg_match('#^(\d+\.\d+\.\d+\.\d+)/(\d+)$#', $x, $m) && isIp4($m[1]) && (int)$m[2] <= 32) {
      // без знака и в float: на 32-битном PHP (Keenetic mipsel) ip2long отрицателен от 128.0.0.0, и такие сети выпадали
      $start = (float)sprintf('%u', ip2long($m[1]));
      $v4[] = [$start, $start + 2 ** (32 - (int)$m[2]) - 1, $x];
    } elseif ($v6 && preg_match('#^[0-9a-f:]+/\d+$#i', $x)) {
      $six[$x] = true;
    }
  }
  // вложенные префиксы убираем: остаются только самые широкие
  usort($v4, fn($a, $b) => [$a[0], $b[1]] <=> [$b[0], $a[1]]);
  $out = [];
  $end = -1;
  foreach ($v4 as [$s, $e, $x]) {
    if ($e > $end) {
      $out[] = $x;
      $end = $e;
    }
  }
  // the holder's name goes into the list's «#» line: a line break from RIPEstat would make the rest a list entry
  return ['asn' => $asn, 'holder' => trim(preg_replace('/[\x00-\x1f]+/', ' ', (string)($ov['holder'] ?? ''))), 'prefixes' => array_merge($out, array_keys($six)), 'v6' => $v6];
}

function asnWrite(array $a, bool $auto): array
{
  $name = "ipset_as{$a['asn']}.list";
  $path = LISTS_DIR . '/' . $name;
  $text = "# AS{$a['asn']} {$a['holder']} — префиксы по данным RIPEstat, " . ldate('Y-m-d') . "\n" . implode("\n", $a['prefixes']) . "\n";
  if (!writeWithBackup($path, $text, "список по AS{$a['asn']}: " . count($a['prefixes']) . ' префиксов')) {
    fail('Не удалось записать список', 500);
  }
  $s = uiSettings();
  $s['asn'] = array_values(array_filter($s['asn'], fn($x) => $x['asn'] !== $a['asn']));
  $s['asn'][] = ['asn' => $a['asn'], 'holder' => $a['holder'], 'list' => $name, 'auto' => $auto, 'last' => time(), 'count' => count($a['prefixes']), 'error' => null];
  saveUiSettings($s);
  return ['name' => $name, 'path' => $path, 'count' => count($a['prefixes'])];
}

// Раз в сутки: обновить списки по ASN. Пустой или резко похудевший ответ не принимаем.
function asnRun(): void
{
  $s = uiSettings();
  foreach ($s['asn'] as $i => $x) {
    if (empty($x['auto']) || !is_file(LISTS_DIR . '/' . $x['list'])) {
      continue;
    }
    $pf = ripe('announced-prefixes', "AS{$x['asn']}");
    $err = null;
    if ($pf === null) {
      $err = 'RIPEstat не ответил';
    } else {
      $a = asnFetch((int)$x['asn']);
      if (!$a['prefixes'] || count($a['prefixes']) * 3 < (int)$x['count']) {
        $err = 'ответ подозрительно мал (' . count($a['prefixes']) . ' вместо ' . $x['count'] . ') — список не тронут';
      } else {
        asnWrite($a, true);
        continue;
      }
    }
    $s = uiSettings();
    foreach ($s['asn'] as $k => $y) {
      if ($y['asn'] === $x['asn']) {
        $s['asn'][$k]['error'] = $err;
        $s['asn'][$k]['last'] = time();
      }
    }
    saveUiSettings($s);
  }
}
