<?php
// Server section: nfqws2 reference, config checks, the result cache.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ================= справочник и проверка синтаксиса =================

// Результат $fn кэшируется в /tmp, пока не изменятся файлы-зависимости
// $keep — сохранять ли результат: неполный (программа не успела ответить) не кешируется, иначе он остаётся до
// следующего обновления
function cached(string $key, array $deps, callable $fn, ?callable $keep = null)
{
  // uiCodeTime(): после обновления программы прежние результаты проверок не годятся — иначе исправленная
  // проверка продолжала бы показывать старую ошибку, пока не изменится конфиг
  $sig = CACHE_VER . '.' . uiCodeTime() . md5(json_encode(array_map(fn($f) => file_exists($f) ? [$f, filemtime($f), is_file($f) ? filesize($f) : 0] : $f, $deps)));
  $file = CACHE_DIR . '/' . preg_replace('/[^a-z0-9_.-]/i', '_', $key) . '.json';
  if (is_file($file)) {
    $c = json_decode(file_get_contents($file), true);
    if (($c['sig'] ?? '') === $sig) {
      return $c['data'];
    }
  }
  $data = $fn();
  if ($keep && !$keep($data)) {
    return $data;
  }
  @mkdir(CACHE_DIR, 0700, true);
  file_put_contents($file, json_encode(['sig' => $sig, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  return $data;
}

// Запускается ли программа curl: подбор, проба, диагноз и проверка на других сайтах делают запросы ею (PHP-модуль curl —
// только в мониторинге и проверке сайта). Без неё каждая проверка кончается кодом 127, и подбор выдавал это за
// блокировку. null — работает, иначе — что ответила система. Пакет зависит от curl, но его могли удалить, а 127 бывает
// и у установленного curl, которому не хватает библиотеки
function curlCmdError(): ?string
{
  $bins = ['/usr/bin/curl', '/opt/bin/curl', '/usr/lib/libcurl.so.4', '/opt/lib/libcurl.so.4'];
  return cached('curl-cmd', $bins, function () {
    [$rc, $out] = runTimeout(['sh', '-c', 'PATH=' . JOB_PATH . ' exec curl --version'], 5);
    if ($rc === 0) {
      return null;
    }
    $line = trim(strtok(trim($out), "\n") ?: '');
    return $line !== '' ? $line : "код $rc";
  });
}

// «не установлена» — системе не найти команду; иначе curl есть, но не запускается (обычно не хватает библиотеки)
function curlMissing(string $err): bool
{
  return (bool)preg_match('/not found|No such file|не найден/i', $err);
}

function curlCmdHint(string $err): string
{
  return (curlMissing($err) ? 'Программа curl не установлена' : 'Программа curl установлена, но не запускается')
    . ' — без неё подбор и проверки не могут открыть ни одного сайта (система ответила: «' . $err . '»). '
    . 'Нажмите «Установить curl» в «Проблемах» на «Обзоре» или выполните на роутере: '
    // установлен, но не запускается — простой install ответит «up to date», нужна переустановка вместе с библиотекой
    . (pkgApk() ? (curlMissing($err) ? 'apk add curl' : 'apk fix curl libcurl') : (curlMissing($err) ? 'opkg install curl' : opkgReinstallHint('curl libcurl'))) . '.';
}

// opkg install --force-reinstall removes the package before downloading it (even with --download-only): with an
// unreachable feed the package is just gone, and without libpcre2 wget and grep stop too. Download first, then reinstall
// from that copy. $pkgs is a fixed latin list of package names
function opkgReinstall(string $pkgs): string
{
  return 'D=/tmp/nfqws-ui-pkg; rm -rf $D; mkdir -p $D && cd $D && { opkg download ' . $pkgs . ' || { opkg update >/dev/null 2>&1; '
    . 'opkg download ' . $pkgs . '; }; } && opkg --cache $D install --force-reinstall ' . $pkgs . '; r=$?; cd /; rm -rf $D; exit $r';
}

// the same for a person typing it by hand
function opkgReinstallHint(string $pkgs): string
{
  return 'cd /tmp && opkg download ' . $pkgs . ' && opkg --cache /tmp install --force-reinstall ' . $pkgs;
}

function pkgApk(): bool
{
  return !ROOT && is_file('/usr/bin/apk') && !is_file('/bin/opkg');
}

// A package command for sh -c, cut off after $sec seconds with all its children (opkg runs wget, which may hang on a
// dead mirror); exit code 124 — cut off
function pkgTimed(string $cmd, int $sec): string
{
  $tree = 'kt() { k=$(awk -v p="$1" \'$4 == p { print $1 }\' /proc/[0-9]*/stat 2>/dev/null); kill "$1" 2>/dev/null; for c in $k; do kt "$c"; done; }; ';
  return 'PATH=' . JOB_PATH . '; ' . $tree . '( ' . $cmd . ' ) & p=$!; i=0; while kill -0 $p 2>/dev/null; do '
    . 'if [ $i -ge ' . $sec . ' ]; then kt $p; exit 124; fi; sleep 1; i=$((i+1)); done; wait $p';
}

// Ставит (или переставляет, если curl есть, но не запускается) curl. Не дольше ~2,5 минуты: wget внутри opkg ждёт
// недоступный источник пакетов бесконечно — по истечении времени процесс снимается вместе с потомками
function curlInstall(): array
{
  set_time_limit(200);
  $err = curlCmdError();
  if ($err === null) {
    return ['ok' => true, 'log' => 'curl уже работает'];
  }
  if (pkgApk()) {
    $cmd = 'apk add curl && apk fix curl libcurl';
  } else {
    // сначала без обновления списка: он почти всегда уже есть (пакеты ставились), а opkg update — самое долгое место;
    // если curl в списке нет — обновить список и повторить. --force-reinstall: пакет может числиться установленным,
    // а файла нет (удалили вручную) — тогда простой install ответил бы «уже установлен» и ничего не вернул.
    // В команде только латиница: escapeshellarg при локали C выбрасывает кириллицу
    $cmd = opkgReinstall('curl libcurl');
  }
  [$rc, $out] = runTimeout(['sh', '-c', pkgTimed($cmd, 150)], 190);
  @unlink(CACHE_DIR . '/curl-cmd.json');
  $after = curlCmdError();
  $log = trim(implode("\n", array_slice(explode("\n", trim($out)), -15)));
  if ($after === null) {
    return ['ok' => true, 'log' => $log];
  }
  $why = $rc === 124 ? 'источник пакетов не ответил за 2,5 минуты, curl не скачался. ' . (ROOT
      ? "Сервер Entware (bin.entware.net) бывает недоступен — перейдите на зеркало и повторите: sed -i 's#http://bin.entware.net/#http://mirrors.bfsu.edu.cn/entware/#' /opt/etc/opkg.conf"
      : 'Проверьте, что роутер достаёт до источника пакетов, и повторите.')
    : 'curl всё ещё не запускается (' . $after . ').';
  return ['ok' => false, 'error' => $why, 'log' => $log, 'code' => $rc];
}

// Модуль curl для PHP (php8-mod-curl): через него идут DoH, RIPEstat, GitHub, Telegram, база сообщества.
// Без него каждый такой запрос падал фатальной ошибкой «Call to undefined function curl_init()», а страница
// показывала «Cannot read properties of undefined (reading 'filter')». null — модуль работает, иначе — что ответил PHP
function phpCurlError(): ?string
{
  if (function_exists('curl_init')) {
    return null;
  }
  // почему не загрузился: PHP пишет это при старте (нет библиотеки, другая версия), а без display_startup_errors молчит
  $bin = PHP_BINARY !== '' && is_executable(PHP_BINARY) ? PHP_BINARY : 'php-cgi';
  [, $out] = runTimeout(['sh', '-c', 'exec env -i PATH=' . JOB_PATH . ' ' . escapeshellarg($bin) . ' -d display_startup_errors=1 -d display_errors=1 -m 2>&1'], 5);
  foreach (explode("\n", strip_tags($out)) as $l) {
    if (stripos($l, 'curl') !== false && preg_match('/warning|error|unable|cannot/i', $l)) {
      return trim(preg_replace('/\s+/', ' ', $l));
    }
  }
  return 'модуль curl для PHP не загружен';
}

function phpCurlHint(string $err): string
{
  if (phpCurlOld($err)) {
    return 'Модуль curl для PHP собран под более новую версию PHP, чем стоит на роутере, и не загружается — без него диагноз, подбор, '
      . 'мониторинг и проверка обновлений не работают (PHP ответил: «' . $err . '»). Нажмите «Обновить PHP» в «Проблемах» на «Обзоре» '
      . 'или выполните на роутере: ' . (pkgApk() ? PHP_UPGRADE_APK : PHP_UPGRADE_OPKG) . '.';
  }
  return 'В PHP нет модуля curl (php8-mod-curl) — без него диагноз, подбор, мониторинг и проверка обновлений не работают '
    . '(PHP ответил: «' . $err . '»). Нажмите «Установить модуль curl» в «Проблемах» на «Обзоре» или выполните на роутере: '
    . (pkgApk() ? 'apk add php8-mod-curl' : opkgReinstallHint('php8-mod-curl libcurl')) . '.';
}

// Модуль собран под более новый PHP, чем стоит на роутере («undefined symbol …»): так бывает, когда модуль поставили из
// свежего источника, а сам PHP не обновляли. Переустановка модуля тут не помогает — обновлять нужно PHP со всеми модулями
// и библиотеки, которые он тянет (у Entware opkg upgrade php8 их сам не обновляет)
const PHP_UPGRADE_OPKG = 'opkg update >/dev/null 2>&1; P=$(opkg list-upgradable | awk \'/^(php8|libpcre2|libxml2)/{print $1}\'); '
  . '[ -n "$P" ] || { echo \'в источнике пакетов нет более новой версии PHP\'; exit 3; }; '
  . 'D=/tmp/nfqws-ui-pkg; rm -rf $D; mkdir -p $D && cd $D && opkg download $P && opkg --cache $D upgrade $P '
  . '&& opkg install php8-mod-curl libcurl; r=$?; cd /; rm -rf $D; exit $r';
const PHP_UPGRADE_APK = 'apk update >/dev/null 2>&1; apk add --upgrade php8-mod-curl libcurl $(apk info 2>/dev/null | grep -E \'^(php8|libpcre2|libxml2)\')';

function phpCurlOld(?string $err): bool
{
  // "Unable to initialize module" is PHP's module API mismatch message (module built for another PHP version)
  return $err !== null && preg_match('/undefined symbol|unable to initialize module/i', $err) === 1;
}

// Ставит (или переставляет) модуль curl для PHP. Новый php-cgi запускается на каждый запрос — перезапуск lighttpd не нужен
function phpCurlInstall(): array
{
  set_time_limit(200);
  $cmd = phpCurlOld(phpCurlError()) ? (pkgApk() ? PHP_UPGRADE_APK : PHP_UPGRADE_OPKG)
    : (pkgApk() ? 'apk add php8-mod-curl && apk fix php8-mod-curl libcurl'
    : opkgReinstall('php8-mod-curl libcurl'));
  [$rc, $out] = runTimeout(['sh', '-c', 'PATH=' . JOB_PATH . '; ' . $cmd], 190);
  $log = trim(implode("\n", array_slice(explode("\n", trim($out)), -15)));
  // в этом процессе модуль уже не появится — проверяем новым php-cgi
  $bin = PHP_BINARY !== '' && is_executable(PHP_BINARY) ? PHP_BINARY : 'php-cgi';
  $ok = (bool)preg_match('/^curl$/mi', (string)runTimeout(['sh', '-c', 'exec env -i PATH=' . JOB_PATH . ' ' . escapeshellarg($bin) . ' -m 2>/dev/null'], 5)[1]);
  return $ok ? ['ok' => true, 'log' => $log]
    : ['ok' => false, 'error' => $rc === 124 ? 'источник пакетов не ответил' : ($rc === 3 ? 'в источнике пакетов нет более новой версии PHP' : 'модуль curl всё ещё не загружается'), 'log' => $log, 'code' => $rc];
}

function curlRequire(): void
{
  if (($e = phpCurlError()) !== null) {
    fail(phpCurlHint($e));
  }
  if (($e = curlCmdError()) !== null) {
    fail(curlCmdHint($e));
  }
}

// Запуск без shell-подстановок, с ограничением по времени (в busybox нет timeout)
function runTimeout(array $argv, int $sec = 10): array
{
  $p = proc_open(implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1', [1 => ['pipe', 'w']], $pipes);
  if (!is_resource($p)) {
    return [-1, 'не удалось запустить'];
  }
  stream_set_blocking($pipes[1], false);
  $out = '';
  $end = microtime(true) + $sec;
  while (true) {
    $out .= (string)fread($pipes[1], 65536);
    $st = proc_get_status($p);
    if (!$st['running']) {
      $out .= (string)stream_get_contents($pipes[1]);
      fclose($pipes[1]);
      proc_close($p);
      return [$st['exitcode'], $out];
    }
    if (microtime(true) > $end) {
      proc_terminate($p, 9);
      fclose($pipes[1]);
      proc_close($p);
      return [-1, $out . "\nпревышено время ожидания"];
    }
    usleep(20000);
  }
}

// Параметры nfqws2 из его собственной справки
function helpInfo(): array
{
  static $info = null;
  return $info ??= cached('help', [NFQWS_BIN], function () {
    $info = helpParse(runTimeout([NFQWS_BIN, '--help'], 5)[1]);
    if (!$info['full']) {
      // на слабом роутере под нагрузкой nfqws2 не всегда успевает за 5 с — тогда справка пустая или обрезана,
      // и все параметры конфига выглядели
      // бы неизвестными
      $info = helpParse(runTimeout([NFQWS_BIN, '--help'], 15)[1]);
    }
    return $info;
  }, fn($d) => $d['full']);
}

function helpParse(string $out): array
{
  $opts = [];
  $l7 = [];
  $payloads = [];
  $section = 'GLOBAL';
  foreach (explode("\n", $out) as $line) {
    if (preg_match('/^([A-Z][A-Z -]+):\s*$/', trim($line), $m)) {
      $section = $m[1];
      continue;
    }
    if (!preg_match('/^\s*--([a-z0-9-]+)(\S*)\s*(?:;\s*(.*))?$/', $line, $m)) {
      continue;
    }
    $desc = trim($m[3] ?? '');
    $opts[$m[1]] = [
      'syntax' => '--' . $m[1] . $m[2],
      'desc' => $desc,
      'global' => in_array($section, ['GLOBAL', 'DESYNC ENGINE INIT'], true) || str_contains($desc, 'global parameter'),
      'value' => $m[2] !== '' && $m[2][0] !== '[',
    ];
    if (preg_match('/:\s*([a-z0-9_ ]+)$/', $desc, $x)) {
      if ($m[1] === 'filter-l7') {
        $l7 = preg_split('/\s+/', trim($x[1]));
      } elseif ($m[1] === 'payload') {
        $payloads = preg_split('/\s+/', trim($x[1]));
      }
    }
  }
  // полная справка — есть параметры из всех разделов; без неё названия параметров не проверяются
  $full = !array_diff(['qnum', 'blob', 'lua-init', 'new', 'filter-tcp', 'payload', 'lua-desync'], array_keys($opts));
  return ['options' => $opts, 'l7' => $l7, 'payloads' => $payloads, 'full' => $full];
}

// Функции и их параметры — из документации в самих lua-скриптах
// Names from a doc line «arg : name1, name2 - text»; [] if the line is not an arg description
function luaArgNames(string $doc): array
{
  if (!preg_match('/^arg\s*:\s*([a-z][a-z0-9_]*(?:\s*,\s*[a-z][a-z0-9_]*)*)/', $doc, $x)) {
    return [];
  }
  return preg_split('/\s*,\s*/', $x[1]);
}

function luaCatalog(): array
{
  $files = luaFiles();
  return cached('lua', array_values($files), function () use ($files) {
    $std = [];
    $funcs = [];
    $globals = [];
    $gfuncs = [];
    $fileKeys = [];
    foreach ($files as $base => $file) {
      $lines = file(str_ends_with($file, '.gz') ? "compress.zlib://$file" : $file, FILE_IGNORE_NEW_LINES) ?: [];
      $group = null;
      $inBlock = false;
      $doc = [];
      foreach ($lines as $i => $line) {
        if (str_starts_with($line, '--[[')) {
          $inBlock = true;
          continue;
        }
        if ($inBlock) {
          if (str_starts_with($line, ']]')) {
            $inBlock = false;
            $group = null;
          } elseif (preg_match('/^standard (.+?)\s*:\s*$/', $line, $m)) {
            $group = str_replace(' ', '_', $m[1]);
          } elseif ($group && preg_match('/^\*\s+(?:\w+\s*:\s*)?([a-z][a-z0-9_]*)(\S*)\s*(?:-\s*(.*))?$/', $line, $m)) {
            $std[$group][$m[1]] = trim($m[1] . $m[2] . (isset($m[3]) ? ' — ' . $m[3] : ''));
          }
          continue;
        }
        if (preg_match('/^--\s?(.*)$/', $line, $m)) {
          $doc[] = $m[1];
          foreach (luaArgNames($m[1]) as $k) {
            $fileKeys[$base][$k] = true;
          }
          continue;
        }
        // every top-level function: fool=<name> calls any of them
        if (preg_match('/^function ([a-z_][a-z0-9_]*)\s*\(/i', $line, $m)) {
          $gfuncs[] = $m[1];
        }
        if (preg_match('/^function ([a-z_][a-z0-9_]*)\(ctx,\s*desync\)/', $line, $m)) {
          $f = ['file' => $base, 'doc' => [], 'std' => [], 'args' => [], 'required' => [], 'nfqws1' => null, 'payload_def' => null];
          foreach ($doc as $d) {
            if (preg_match('/^standard args\s*:\s*(.+)$/', $d, $x)) {
              // «direction, payload, reconstruct. FOOLING AND REPEATS ...» — берём только имена групп
              preg_match_all('/(?:^|,)\s*([a-z_]+)/', $x[1], $g);
              $f['std'] = $g[1];
              if (preg_match('/\.\s*(.+)$/', $x[1], $note)) {
                $f['doc'][] = $note[1];
              }
            } elseif ($names = luaArgNames($d)) {
              // «arg : nofake1, nofake2 - …» describes several args at once
              foreach ($names as $k) {
                $f['args'][$k] = trim(preg_replace('/^arg\s*:\s*/', '', $d));
              }
            } elseif (preg_match('/^nfqws1\s*:\s*(.+)$/', $d, $x)) {
              $f['nfqws1'] = trim($x[1], ' "');
            } elseif (trim($d) !== '') {
              $f['doc'][] = $d;
            }
          }
          for ($j = $i + 1; $j < count($lines) && $lines[$j] !== 'end'; $j++) {
            // args the function reads but does not describe (newer zapret2 has some): desync.arg.x / desync.arg["x"]
            $code = preg_replace('/--.*$/', '', $lines[$j]);
            if (preg_match_all('/\bdesync\.arg(?:\.([a-z][a-z0-9_]*)|\[\s*["\']([a-z][a-z0-9_]*)["\']\s*\])/', $code, $x)) {
              foreach (array_filter(array_merge($x[1], $x[2])) as $k) {
                $f['args'][$k] ??= "$k — читается в коде функции, в её описании нет";
              }
            }
            if (preg_match_all("/'([a-z0-9_]+)' arg required/", $lines[$j], $x)) {
              array_push($f['required'], ...$x[1]);
            }
            // Какую нагрузку функция берёт без своего payload=: payload_check(desync) — только известную (known)
            if ($f['payload_def'] === null && preg_match('/payload_check\(desync\s*(?:,\s*"([a-z_,]+)")?\)/', $lines[$j], $x)) {
              $f['payload_def'] = ($x[1] ?? '') ?: 'known';
            }
          }
          $f['required'] = array_values(array_unique($f['required']));
          $funcs[$m[1]] = $f;
        } elseif (preg_match('/^([a-z_][a-z0-9_]*)\s*=/i', $line, $m)) {
          $globals[] = $m[1];
        }
        $doc = [];
      }
    }
    // Оркестраторы (zapret-auto.lua) принимают параметры детекторов и генераторов ключей того же файла
    foreach ($funcs as &$f) {
      $f['file_keys'] = array_keys($fileKeys[$f['file']] ?? []);
    }
    unset($f);
    // learn (nfqws-ui.lua) calls zapret-auto's detectors and hostkey with its own args, exactly like circular
    if (isset($funcs['learn'], $funcs['circular'])) {
      $funcs['learn']['args'] += $funcs['circular']['args'];
      $funcs['learn']['file_keys'] = array_values(array_unique(array_merge($funcs['learn']['file_keys'], $funcs['circular']['file_keys'])));
    }
    return ['functions' => $funcs, 'std' => $std, 'globals' => array_values(array_unique($globals)), 'gfuncs' => array_values(array_unique($gfuncs))];
  });
}

function levenshteinBest(string $word, array $candidates, int $max = 2): ?string
{
  $best = null;
  $bestD = $max + 1;
  foreach ($candidates as $c) {
    $d = levenshtein($word, $c);
    if ($d < $bestD) {
      $best = $c;
      $bestD = $d;
    }
  }
  return $best;
}

function validPortFilter(string $v): bool
{
  foreach (explode(',', $v) as $p) {
    $p = ltrim($p, '~');
    if ($p === '*') {
      continue;
    }
    if (!preg_match('/^(\d+)(?:-(\d+))?$/', $p, $m) || (int)$m[1] > 65535 || (isset($m[2]) && ((int)$m[2] > 65535 || (int)$m[2] < (int)$m[1]))) {
      return false;
    }
  }
  return true;
}

function listFileExists(string $path): bool
{
  return is_file($path) || is_file($path . '.gz');
}

// Lua-скрипты nfqws2-keenetic кладёт сжатыми (zapret-lib.lua.gz), а в --lua-init пишется имя без .gz:
// nfqws2 сам берёт сжатый файл, если обычного нет. Возвращает имя.lua => настоящий файл.
function luaFiles(): array
{
  $res = [];
  foreach (glob(LUA_DIR . '/*.lua.gz') ?: [] as $f) {
    $res[basename($f, '.gz')] = $f;
  }
  foreach (glob(LUA_DIR . '/*.lua') ?: [] as $f) {
    $res[basename($f)] = $f;
  }
  ksort($res);
  return $res;
}

// Проверка переменных с аргументами. $raw — значения как в форме (для привязки к номеру аргумента),
// $text — полный текст кандидата конфига. Возвращает список замечаний.
