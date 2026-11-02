<?php
// Server section: carrying a config over from another system.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ---------- перенос конфига с другой системы ----------
// Конфиг, скопированный с Keenetic (Entware, пути /opt/...) на OpenWrt или обратно, из zapret2 (/opt/zapret2/...)
// или от nfqws первой версии, часто не запускается: другие пути, интерфейс провайдера, нет части переменных.

function platformName(): string
{
  return ROOT ? 'Keenetic (Entware)' : 'OpenWrt';
}

// Интерфейс маршрута по умолчанию — обычно это провайдер
function wanIface(): string
{
  exec('ip -4 route show default 2>/dev/null', $out);
  foreach ($out as $l) {
    if (preg_match('/\bdev (\S+)/', $l, $m)) {
      return $m[1];
    }
  }
  return ROOT ? 'eth3' : 'wan';
}

// Сетевые интерфейсы роутера для выбора ISP_INTERFACE: адреса, маршрут по умолчанию, имя в OpenWrt (wan, lan…)
// и годится ли интерфейс в принципе (порты моста, Wi-Fi и мосты локальной сети — нет)
function netIfaces(): array
{
  $def = [];
  exec('ip -4 route show default 2>/dev/null', $rt4);
  exec('ip -6 route show default 2>/dev/null', $rt6);
  foreach ([4 => $rt4, 6 => $rt6] as $v => $rt) {
    foreach ($rt as $l) {
      if (preg_match('/\bdev (\S+)/', $l, $m)) {
        $def[$m[1]][] = "IPv$v";
      }
    }
  }
  $logical = [];
  exec('ubus call network.interface dump 2>/dev/null', $u);
  foreach (json_decode(implode("\n", $u), true)['interface'] ?? [] as $x) {
    $d = $x['l3_device'] ?? $x['device'] ?? null;
    if ($d && !in_array($x['interface'], $logical[$d] ?? [], true)) {
      $logical[$d][] = $x['interface'];
    }
  }
  $ips = [];
  exec('ip -o addr show 2>/dev/null', $ad);
  foreach ($ad as $l) {
    if (preg_match('/^\d+:\s+(\S+)\s+inet6?\s+(\S+)/', $l, $m) && !str_starts_with($m[2], 'fe80')) {
      $ips[$m[1]][] = $m[2];
    }
  }
  $res = [];
  foreach (glob('/sys/class/net/*') as $p) {
    $n = basename($p);
    if ($n === 'lo') {
      continue;
    }
    $type = (int)@file_get_contents("$p/type");
    $kind = is_dir("$p/bridge") ? 'bridge' : (is_dir("$p/phy80211") ? 'wifi' : ($type === 512 ? 'ppp' : ($type === 65534 ? 'tunnel' : 'ethernet')));
    $port = is_dir("$p/brport");
    $state = trim((string)@file_get_contents("$p/operstate"));
    $res[] = ['name' => $n, 'kind' => $kind, 'up' => in_array($state, ['up', 'unknown'], true), 'ips' => $ips[$n] ?? [],
      'default' => $def[$n] ?? [], 'logical' => $logical[$n] ?? [],
      // для nfqws2 подходит интерфейс с адресом, не мост локальной сети и не его порт
      'usable' => !$port && $kind !== 'bridge' && $kind !== 'wifi' && (!empty($ips[$n]) || isset($def[$n]))];
  }
  // сначала интерфейсы с маршрутом по умолчанию, потом подходящие, потом остальные
  usort($res, fn($a, $b) => [!$a['default'], !$a['usable'], $a['name']] <=> [!$b['default'], !$b['usable'], $b['name']]);
  return $res;
}

// Файлы, которые можно подключить в «Параметрах запуска»: lua-скрипты и блобы
function baseFiles(): array
{
  $lua = array_keys(luaFiles());
  $blobs = array_values(array_filter(array_map('basename', glob(CONF_DIR . '/blobs/*') ?: []),
    fn($f) => !preg_match('/\.(lua|gz)$/', $f) && is_file(CONF_DIR . "/blobs/$f")));
  return ['lua' => $lua, 'lua_dir' => LUA_DIR, 'blobs' => $blobs, 'blob_dir' => CONF_DIR . '/blobs'];
}

// Исправленный текст конфига, список правок и откуда, судя по путям, пришёл конфиг
function adaptConf(string $text): array
{
  $changes = [];
  $from = [];

  // 1. Пути. true — переносить всегда (та же раскладка nfqws2), false — только если такой файл здесь есть
  $rules = [
    ['~^(?:/opt)?/etc/nfqws2/~', CONF_DIR . '/', true, ROOT ? 'OpenWrt' : 'Keenetic (Entware)'],
    ['~^(?:/opt)?/etc/nfqws/(?=[\w.-]+\.list$)~', LISTS_DIR . '/', false, 'nfqws первой версии'],
    ['~^(?:/opt)?/etc/nfqws/~', CONF_DIR . '/', false, 'nfqws первой версии'],
    ['~^/opt/zapret2?/lua/~', LUA_DIR . '/', false, 'zapret2'],
    ['~^/opt/zapret2?/files/fake/~', CONF_DIR . '/blobs/', false, 'zapret2'],
    ['~^/opt/zapret2?/ipset/~', LISTS_DIR . '/', false, 'zapret2'],
  ];
  if (!ROOT) {
    $rules[] = ['~^/opt/var/log/~', '/var/log/', true, 'Keenetic (Entware)'];
  } else {
    $rules[] = ['~^/var/log/~', '/opt/var/log/', true, 'OpenWrt'];
  }
  $lines = explode("\n", $text);
  foreach ($lines as &$line) {
    if (preg_match('/^\s*#/', $line)) {
      continue;
    }
    $line = preg_replace_callback('~(?<![\w./-])/[\w.+-]+(?:/[\w.+-]+)*/?~', function ($m) use ($rules, &$changes, &$from) {
      $p = $m[0];
      foreach ($rules as [$re, $to, $always, $src]) {
        if (!preg_match($re, $p)) {
          continue;
        }
        $new = preg_replace($re, $to, $p, 1);
        if ($new === $p || (!$always && !file_exists($new))) {
          return $p;
        }
        $changes["$p → $new"] = true;
        $from[$src] = true;
        return $new;
      }
      return $p;
    }, $line);
  }
  unset($line);
  $text = implode("\n", $lines);
  $changes = array_keys($changes);

  $raw = confRawVars($text);

  // 2. Интерфейс провайдера, которого на этом роутере нет (eth3/ppp0 с Keenetic, wan/pppoe-wan с OpenWrt)
  if (isset($raw['ISP_INTERFACE']) && is_dir('/sys/class/net')) {
    $ifs = tokens($raw['ISP_INTERFACE']);
    if (!array_filter($ifs, fn($i) => is_dir("/sys/class/net/$i"))) {
      $wan = wanIface();
      $text = confSetVar($text, 'ISP_INTERFACE', $wan);
      $changes[] = ($ifs ? 'интерфейса «' . implode(' ', $ifs) . '» здесь нет' : 'интерфейс провайдера не задан') . " → $wan (маршрут по умолчанию)";
    }
  }

  // 3. lua-файлы, без которых --lua-desync не работает
  if (isset($raw['NFQWS_BASE_ARGS']) && preg_match('/--lua-desync=/', $text) && !preg_match('~--lua-init=@\S*zapret-lib\.lua~', $raw['NFQWS_BASE_ARGS'])) {
    foreach (['zapret-lib.lua', 'zapret-antidpi.lua', 'zapret-auto.lua'] as $f) {
      if (listFileExists(LUA_DIR . "/$f") && !str_contains($raw['NFQWS_BASE_ARGS'], "/$f")) {
        $text = appendTokenInText($text, 'NFQWS_BASE_ARGS', '--lua-init=@' . LUA_DIR . "/$f");
        $changes[] = "подключён $f";
      }
    }
  }

  // 4. Переменные, без которых init-скрипт не запустит nfqws2 или запустит не так (значения — как в стандартном конфиге)
  $base = [];
  foreach (['zapret-lib.lua', 'zapret-antidpi.lua', 'zapret-auto.lua'] as $f) {
    if (listFileExists(LUA_DIR . "/$f")) {
      $base[] = '--lua-init=@' . LUA_DIR . "/$f";
    }
  }
  foreach (['quic_initial', 'tls_clienthello'] as $b) {
    if (is_file(CONF_DIR . "/blobs/$b.bin")) {
      $base[] = "--blob=$b:@" . CONF_DIR . "/blobs/$b.bin";
    }
  }
  $needed = [
    'ISP_INTERFACE' => fn() => wanIface(),
    'NFQWS_BASE_ARGS' => fn() => implode("\n" . str_repeat(' ', 17), $base),
    'NFQWS_EXTRA_ARGS' => fn() => '$MODE_LIST',
    'TCP_PORTS' => fn() => '80,443',
    'UDP_PORTS' => fn() => '443',
    'IPV6_ENABLED' => fn() => '1',
    'LOG_LEVEL' => fn() => '0',
    'NFQUEUE_NUM' => fn() => '300',
    'USER' => fn() => 'nobody',
    'CONFIG_VERSION' => fn() => '1',
  ];
  if (ROOT) {
    $needed += ['POLICY_NAME' => fn() => 'nfqws', 'POLICY_EXCLUDE' => fn() => '0'];
  }
  $added = [];
  foreach ($needed as $name => $val) {
    if (!array_key_exists($name, $raw)) {
      $v = $val();
      $text = rtrim($text, "\n") . "\n" . ($added ? '' : "\n# Добавлено nfqws2-ui: этих переменных не было в конфиге\n")
        . $name . '=' . (preg_match('/^[\w.,:-]+$/', $v) && $name !== 'ISP_INTERFACE' ? $v : "\"$v\"") . "\n";
      $added[] = $name;
    }
  }
  // Режимы MODE_* нужны до строки, где на них ссылается NFQWS_EXTRA_ARGS
  $modes = [
    'MODE_LIST' => '--hostlist=' . LISTS_DIR . '/user.list',
    'MODE_ALL' => '--hostlist-exclude=' . LISTS_DIR . '/exclude.list',
    'MODE_AUTO' => '$MODE_LIST --hostlist-auto=' . LISTS_DIR . '/auto.list --hostlist-auto-debug=' . ROOT . '/var/log/nfqws2.log $MODE_ALL',
  ];
  $missingModes = array_diff_key($modes, $raw);
  if ($missingModes) {
    $block = '';
    foreach ($missingModes as $name => $v) {
      $block .= "$name=\"$v\"\n";
      $added[] = $name;
    }
    $text = preg_match('/^NFQWS_EXTRA_ARGS=/m', $text, $m, PREG_OFFSET_CAPTURE)
      ? substr($text, 0, $m[0][1]) . $block . substr($text, $m[0][1])
      : rtrim($text, "\n") . "\n$block";
  }
  if ($added) {
    $changes[] = 'добавлены переменные: ' . implode(', ', $added);
  }

  return ['text' => normalizeText($text), 'changes' => $changes, 'from' => array_keys($from), 'added' => $added];
}

function lintConf(array $raw, string $text): array
{
  $issues = [];
  // $at — where a whole-config note points in the text ({var, tok}): the note itself stays without var, so its
  // «don't show» key does not change, but a click in the list still puts the cursor there
  $add = function (?string $var, ?int $tok, string $level, string $msg, ?array $fix = null, ?array $at = null) use (&$issues) {
    foreach ($issues as $x) {
      if ($x['var'] === $var && $x['tok'] === $tok && $x['msg'] === $msg) {
        return;
      }
    }
    $issues[] = ['var' => $var, 'tok' => $tok, 'level' => $level, 'msg' => $msg] + ($fix ? ['fix' => $fix] : []) + ($at ? ['at' => $at] : []);
  };

  $tmp = tempnam('/tmp', 'nfqlint');
  file_put_contents($tmp, $text);
  exec('sh -n ' . escapeshellarg($tmp) . ' 2>&1', $shOut, $rc);
  if ($rc !== 0) {
    unlink($tmp);
    $add(null, null, 'error', 'Ошибка синтаксиса конфига: ' . preg_replace('#^/tmp/\S+: #', '', implode(' ', $shOut)));
    return ['issues' => $issues, 'dry_run' => null];
  }
  $exp = confValues($tmp);
  unlink($tmp);

  // Переменная присвоена несколько раз (VAR="$VAR --new …"): nfqws2 получит всё, а интерфейс видит и меняет
  // только последнее присваивание — кнопки, меняющие её, откажут, чтобы не потерять остальные части
  foreach (ARG_VARS as $var) {
    if (preg_match_all('/^[ \t]*' . $var . '=/m', $text) > 1) {
      $add($var, null, 'warning', "$var присвоена в конфиге несколько раз ($var=\"\$$var …\"). nfqws2 получит все части, но интерфейс видит только последнюю: "
        . 'профили из остальных частей в конструкторе не показываются, а «Применить», «Добавить профиль» и правки этой переменной откажут. Соберите всё в одно присваивание, профили — через --new.');
    }
  }

  $ad = adaptConf($text);
  if ($ad['changes']) {
    $msg = $ad['from']
      ? 'Похоже, конфиг от ' . implode(' / ', $ad['from']) . ', а здесь ' . platformName() . ' — пути и настройки нужно поправить'
      : ($ad['added'] && count($ad['changes']) === 1 ? 'В конфиге не хватает переменных: ' . implode(', ', $ad['added']) : 'Конфиг не подходит для этого роутера');
    $add(null, null, $ad['from'] ? 'error' : 'warning', $msg, ['op' => 'adapt', 'label' => 'Исправить под ' . platformName(), 'changes' => $ad['changes']]);
  }

  $help = helpInfo();
  $opts = $help['options'];
  if (!$help['full']) {
    $add(null, null, 'warning', 'nfqws2 не ответил на запрос справки (nfqws2 --help) — названия параметров не проверены. Откройте страницу ещё раз через минуту');
  }
  $lua = luaCatalog();
  $funcs = $lua['functions'];

  // Что объявлено в параметрах запуска: загруженные lua-файлы и блобы
  $loadedLua = [];
  $blobs = [];
  foreach (tokens($exp['NFQWS_BASE_ARGS']) as $t) {
    if (preg_match('/^--lua-init=@(.+)$/', $t, $m)) {
      $loadedLua[basename($m[1])] = true;
    } elseif (preg_match('/^--blob=([^:]+):(.*)$/', $t, $m)) {
      // значение — откуда блоб берётся (@файл или 0xHEX): по нему видно, TLS ли это
      $blobs[$m[1]] = $m[2];
    }
  }
  // tls_client_hello_clone:blob=ИМЯ сам создаёт блоб — копию приветствия устройства (для fake:blob=ИМЯ в том же профиле)
  foreach (ARG_VARS as $v) {
    foreach (tokens($exp[$v] ?? '') as $t) {
      if (preg_match('/^--lua-desync=tls_client_hello_clone:(?:.*:)?blob=([^:]+)/', $t, $m)) {
        $blobs[$m[1]] ??= 'clone';
      }
    }
  }

  if (trim($exp['ISP_INTERFACE']) === '') {
    $add('ISP_INTERFACE', null, 'error', 'Не задан интерфейс провайдера');
  }

  $comments = 0;
  $commentAt = null;   // the first comment — the single note points there
  foreach (ARG_VARS as $var) {
    $toks = tokens($raw[$var] ?? '');
    // Комментарий внутри кавычек (так написан стандартный конфиг Keenetic): от «#» до конца строки.
    // nfqws2 получает эти слова как лишние аргументы и пропускает их — одно замечание на весь конфиг
    $comment = commentTokens($raw[$var] ?? '');
    $comments += count(array_filter($comment));
    if (!$commentAt && $comment) {
      $commentAt = ['var' => $var, 'tok' => array_key_first($comment)];
    }
    $pic = false;   // в этом профиле уже был per_instance_condition — у следующих шагов законны cond / cond_neg
    foreach ($toks as $i => $t) {
      if (isset($comment[$i])) {
        continue;
      }
      if (str_starts_with($t, '--dpi-desync')) {
        $add(null, null, 'error', 'Похоже, это стратегии nfqws первой версии (--dpi-desync…) — nfqws2 их не понимает, их нужно переписать на --lua-desync', null, ['var' => $var, 'tok' => $i]);
      }
      if (preg_match('/^\$\{?' . $var . '\}?$/', $t)) {
        continue;   // VAR="$VAR …" — об этом отдельное замечание «присвоена несколько раз»
      }
      if (!str_starts_with($t, '--')) {
        $add($var, $i, 'error', 'Ожидается параметр вида --имя=значение');
        continue;
      }
      $name = substr(explode('=', $t, 2)[0], 2);
      $val = str_contains($t, '=') ? substr($t, strpos($t, '=') + 1) : null;
      if (($name === 'new' || $name === 'name') && $val !== null && !preg_match('/^[\p{L}\p{N}_.-]{1,32}$/u', $val)) {
        $add($var, $i, 'warning', "Имя профиля «{$val}»: допустимы буквы, цифры, «_», «-», «.», до 32 знаков — другие знаки в конфиге могут сломать запуск");
      }
      if ($name === 'new') {
        $pic = false;
        if ($var !== 'NFQWS_ARGS_CUSTOM') {
          $add($var, $i, 'error', '--new можно использовать только в «Своих профилях» — init-скрипт не запустится', ['op' => 'remove', 'label' => 'Удалить --new']);
        } elseif ($i === 0 || $i === count($toks) - 1) {
          $add($var, $i, 'warning', '--new в начале или в конце создаёт пустой профиль', ['op' => 'remove', 'label' => 'Удалить лишний --new']);
        }
        continue;
      }
      if (!isset($opts[$name])) {
        if (!$help['full']) {
          continue;   // без справки «неизвестными» оказались бы все параметры
        }
        $pref = array_values(array_filter(array_keys($opts), fn($o) => str_starts_with($o, $name)));
        if (count($pref) === 1) {
          $add($var, $i, 'warning', "Сокращённое имя — nfqws2 поймёт его как --{$pref[0]}, но лучше написать полностью", ['op' => 'replace', 'to' => '--' . $pref[0] . ($val !== null ? '=' . $val : ''), 'label' => "Написать --{$pref[0]}"]);
          $name = $pref[0];
        } else {
          $s = levenshteinBest($name, array_keys($opts));
          $add($var, $i, 'error', "Неизвестный параметр --$name" . ($s ? " — может быть, --$s?" : ''), $s ? ['op' => 'replace', 'to' => "--$s" . ($val !== null ? '=' . $val : ''), 'label' => "Заменить на --$s"] : ['op' => 'remove', 'label' => 'Удалить аргумент']);
          continue;
        }
      }
      $o = $opts[$name];
      if ($var === 'NFQWS_BASE_ARGS' && !$o['global']) {
        $add($var, $i, 'warning', 'Это параметр профиля — в параметрах запуска он попадёт в первый профиль');
      } elseif ($var !== 'NFQWS_BASE_ARGS' && $o['global']) {
        $add($var, $i, 'warning', 'Это общий параметр процесса — его место в «Параметрах запуска»', ['op' => 'move', 'to_var' => 'NFQWS_BASE_ARGS', 'label' => 'Перенести в параметры запуска']);
      }
      if ($o['value'] && ($val === null || $val === '')) {
        $add($var, $i, 'error', "Параметру нужно значение: {$o['syntax']}");
        continue;
      }
      switch ($name) {
        case 'filter-tcp':
        case 'filter-udp':
          if (!validPortFilter($val)) {
            $add($var, $i, 'error', 'Неверный список портов: нужны числа 1–65535, диапазоны через «-», разделитель «,»');
          }
          break;
        case 'filter-l7':
          foreach (explode(',', $val) as $p) {
            if (!in_array($p, $help['l7'], true)) {
              $s = levenshteinBest($p, $help['l7']);
              $add($var, $i, 'error', "Неизвестный протокол «{$p}»" . ($s ? " — может быть, {$s}?" : '') . ' Допустимы: ' . implode(', ', $help['l7']), $s ? ['op' => 'replace', 'to' => "--$name=" . implode(',', array_map(fn($x) => $x === $p ? $s : $x, explode(',', $val))), 'label' => "Заменить на $s"] : null);
            }
          }
          break;
        case 'payload':
        case 'payload-disable':
          foreach (explode(',', (string)$val) as $p) {
            if ($p !== '' && !in_array($p, $help['payloads'], true)) {
              $s = levenshteinBest($p, $help['payloads'], 3);
              $add($var, $i, 'error', "Неизвестный тип payload «{$p}»" . ($s ? " — может быть, {$s}?" : ''), $s ? ['op' => 'replace', 'to' => "--$name=" . implode(',', array_map(fn($x) => $x === $p ? $s : $x, explode(',', $val))), 'label' => "Заменить на $s"] : null);
            }
          }
          break;
        case 'hostlist':
        case 'hostlist-exclude':
        case 'ipset':
        case 'ipset-exclude':
          if (!listFileExists($val)) {
            $add($var, $i, 'error', "Файла нет: $val — nfqws2 не запустится", ['op' => 'remove', 'label' => 'Убрать ссылку на файл']);
          } elseif (in_array($name, ['hostlist', 'ipset'], true) && dirname($val) === LISTS_DIR && !readListLines($val)) {
            $add($var, $i, 'warning', 'Список пуст' . ($name === 'hostlist' ? ' — если все списки сайтов профиля пусты, nfqws2 считает, что фильтра нет, и профиль ловит весь трафик' : ''));
          }
          break;
        case 'lua-init':
          if (str_starts_with($val, '@') && !listFileExists(substr($val, 1))) {
            $add($var, $i, 'error', 'Файла нет: ' . substr($val, 1));
          }
          break;
        case 'blob':
          if (preg_match('/^[^:]+:\+?\d*@(.+)$/', $val, $m) && !is_file($m[1])) {
            $add($var, $i, 'error', 'Файла блоба нет: ' . $m[1]);
          } elseif (!preg_match('/^[^:]+:(\+?\d*@.+|0x[0-9a-fA-F]+)$/', $val)) {
            $add($var, $i, 'error', 'Формат: --blob=имя:@файл или --blob=имя:0xHEX');
          }
          break;
        case 'lua-desync':
          lintDesync($var, $i, $val, $funcs, $lua, $loadedLua, $blobs, $help, $add, $pic);
          $pic = $pic || explode(':', (string)$val, 2)[0] === 'per_instance_condition';
          break;
      }
    }
    lintCircular($var, $toks, $comment, $issues, $add);
  }
  if ($comments) {
    $add(null, null, 'info', ($comments === 1 ? 'Комментарий' : "Комментарии ($comments)") . ' внутри кавычек — nfqws2 получает их слова как лишние аргументы и пропускает. '
      . 'На работу это не влияет (так написан стандартный конфиг Keenetic), но надёжнее держать комментарии над переменной', ['op' => 'remove_comments', 'label' => $comments === 1 ? 'Удалить комментарий' : 'Удалить все'], $commentAt);
  }

  // Проверки на уровне профилей
  $expected = expectedProfiles($exp);
  $iptPorts = [
    'tcp' => parsePorts(str_replace(':', '-', $exp['TCP_PORTS'])) ?? [],
    'udp' => parsePorts(str_replace(':', '-', $exp['UDP_PORTS'])) ?? [],
  ];
  $usedPorts = [];
  foreach ($expected as $n => $e) {
    $var = $e['source'];
    $tok = null;
    if ($var === 'NFQWS_ARGS_CUSTOM') {
      // номер первого аргумента этой части
      $parts = 0;
      foreach (tokens($raw[$var] ?? '') as $i => $t) {
        if ($parts === $e['part'] - 1) {
          $tok = $i;
          break;
        }
        if (isNewArg($t)) {
          $parts++;
        }
      }
    }
    $label = "Профиль #" . ($n + 1);
    $hasDesync = false;
    $hasFilter = false;
    $inCircular = false;
    $strategies = [];
    foreach ($e['tokens'] as $t) {
      if (str_starts_with($t, '--lua-desync=')) {
        $hasDesync = true;
        $fn = explode(':', substr($t, 13))[0];
        if ($fn === 'circular') {
          $inCircular = true;
        }
        if (preg_match('/:strategy=(\d+)/', $t, $m)) {
          $strategies[] = (int)$m[1];
        }
      }
      if (preg_match('/^--filter-(tcp|udp|l7|l3|icmp|ipp)=/', $t)) {
        $hasFilter = true;
      }
    }
    if (!$hasDesync) {
      $add($var, $tok, 'warning', "$label: нет ни одного --lua-desync — профиль ничего не делает, но перехватывает свой трафик у профилей ниже");
    }
    if (!$hasFilter) {
      $add($var, $tok, 'warning', "$label: нет фильтров --filter-tcp/udp/l7 — профиль подходит для любого трафика");
    }
    if ($strategies) {
      $u = array_values(array_unique($strategies));
      sort($u);
      if (!$inCircular) {
        $add($var, $tok, 'warning', "$label: strategy=N используется без --lua-desync=circular — номера стратегий ни на что не влияют");
      } elseif ($u !== range(1, count($u))) {
        $add($var, $tok, 'error', "$label: номера strategy должны идти подряд с 1 (сейчас: " . implode(', ', $u) . ')');
      }
    }

    // --payload=unknown у профиля, а функция без своего payload= берёт только известную нагрузку (known):
    // nfqws2 запускается без ошибок, а функция не срабатывает ни разу («does not pass 'known' filter» в журнале)
    $pl = null;
    foreach ($e['tokens'] as $t) {
      if (str_starts_with($t, '--payload=')) {
        $pl = explode(',', substr($t, 10));
        continue;
      }
      if ($pl === null || array_diff($pl, ['unknown', 'empty']) || !str_starts_with($t, '--lua-desync=')) {
        continue;
      }
      $parts = explode(':', substr($t, 13));
      $def = $funcs[$parts[0]]['payload_def'] ?? null;
      if ($def === null || array_intersect(explode(',', $def), ['all', 'unknown', 'empty'])
        || array_filter($parts, fn($p) => str_starts_with($p, 'payload='))) {
        continue;
      }
      $ti = null;
      foreach (tokens($raw[$var] ?? '') as $i => $rt) {
        if ($rt === $t && ($tok === null || $i >= $tok)) {
          $ti = $i;
          break;
        }
      }
      $add($var, $ti ?? $tok, 'warning', "$label: {$parts[0]} не сработает — у профиля --payload=" . implode(',', $pl) . ", а функция без своего payload= берёт только известные протоколы",
        $ti !== null ? ['op' => 'replace', 'to' => "$t:payload=all", 'label' => 'Добавить payload=all'] : null);
    }

    // tls_mod=…dupsid копирует session id из настоящего ClientHello. Профилю приходит только не-TLS нагрузка
    // (игры, свои протоколы) — копировать неоткуда: nfqws2 на каждом соединении пишет в журнал
    // «cannot apply dupsid tls mod. payload is not valid tls», остальное tls_mod при этом работает
    $pl = null;
    foreach ($e['tokens'] as $t) {
      if (str_starts_with($t, '--payload=')) {
        $pl = explode(',', substr($t, 10));
        continue;
      }
      if ($pl === null || array_intersect($pl, ['tls_client_hello', 'known', 'all']) || !str_starts_with($t, '--lua-desync=')
        || !preg_match('/:tls_mod=([^:]*)/', $t, $tm) || !in_array('dupsid', explode(',', $tm[1]), true)) {
        continue;
      }
      $rest = implode(',', array_filter(explode(',', $tm[1]), fn($x) => $x !== 'dupsid'));
      $to = str_replace(':tls_mod=' . $tm[1], $rest === '' ? '' : ':tls_mod=' . $rest, $t);
      $ti = null;
      foreach (tokens($raw[$var] ?? '') as $i => $rt) {
        if ($rt === $t && ($tok === null || $i >= $tok)) {
          $ti = $i;
          break;
        }
      }
      $add($var, $ti ?? $tok, 'info', "$label: dupsid в tls_mod не сработает — профилю приходит только --payload=" . implode(',', $pl)
        . ', а не TLS ClientHello, копировать session id неоткуда. nfqws2 на каждом соединении пишет в журнал «cannot apply dupsid tls mod»; остальное в tls_mod работает',
        $ti !== null ? ['op' => 'replace', 'to' => $to, 'label' => 'Убрать dupsid'] : null);
    }

    // tcp_ts=N — единственная маскировка шага: без временной метки TCP в пакете nfqws2 её пропускает
    // («timestamp tcp option not present»), фейк уходит как настоящий. Подбор идёт с роутера (Linux, метки есть)
    // и этого не видит, а Windows меток по умолчанию не шлёт
    foreach ($e['tokens'] as $t) {
      if (!tsOnlyFooling($t)) {
        continue;
      }
      $ti = null;
      foreach (tokens($raw[$var] ?? '') as $i => $rt) {
        if ($rt === $t && ($tok === null || $i >= $tok)) {
          $ti = $i;
          break;
        }
      }
      $add($var, $ti ?? $tok, 'info', "$label: " . explode(':', substr($t, 13))[0] . ' маскируется только через tcp_ts — это действует на устройствах с временными метками TCP. '
        . 'В Windows они по умолчанию выключены: там такой шаг не сработает, хотя подбор с роутера показывает, что сайт открывается. ' . WIN_TS_HINT);
      break;
    }

    // Порты профиля должны попадать в правила iptables (TCP_PORTS / UDP_PORTS), иначе пакеты до nfqws2 не дойдут
    $hasPortFilter = false;
    foreach ($e['tokens'] as $t) {
      if (!preg_match('/^--filter-(tcp|udp)=(.+)$/', $t, $m)) {
        continue;
      }
      $hasPortFilter = true;
      $want = parsePorts(implode(',', array_filter(explode(',', $m[2]), fn($p) => $p !== '' && $p[0] !== '~'))) ?? [];
      $usedPorts[$m[1]] = normRanges(array_merge($usedPorts[$m[1]] ?? [], $want));
      $missing = subtractRanges($want, $iptPorts[$m[1]]);
      if ($missing) {
        $name = strtoupper($m[1]);
        $rawToks = tokens($raw[$var] ?? '');
        $ti = null;
        foreach ($rawToks as $i => $rt) {
          if ($rt === $t && ($tok === null || $i >= $tok)) {
            $ti = $i;
            break;
          }
        }
        $add($var, $ti ?? $tok, 'warning', "$label: $name " . rangesStr($missing) . " нет в «{$name}-портах» — такие пакеты не попадают в nfqws2 и профиль на них не сработает", ['op' => 'set_var', 'var' => $name . '_PORTS', 'value' => iptPortsStr(normRanges(array_merge($iptPorts[$m[1]], $missing))), 'label' => "Добавить в {$name}-порты"]);
      }
    }
    if (!$hasPortFilter) {
      $usedPorts['tcp'] = [[0, 65535]];
      $usedPorts['udp'] = [[0, 65535]];
    }
  }
  foreach (['tcp' => 'TCP_PORTS', 'udp' => 'UDP_PORTS'] as $proto => $v) {
    $extra = subtractRanges($iptPorts[$proto], $usedPorts[$proto] ?? []);
    if ($extra) {
      $add($v, null, 'info', strtoupper($proto) . ' ' . rangesStr($extra) . ' отправляются в nfqws2, но ни один профиль их не обрабатывает — лишняя нагрузка', ($left = subtractRanges($iptPorts[$proto], $extra)) ? ['op' => 'set_var', 'var' => $v, 'value' => iptPortsStr($left), 'label' => 'Убрать лишние порты'] : null);
    }
  }

  // IPv6 от провайдера есть, а nfqws2 его не перехватывает: сайты с IPv6-адресом (YouTube, Cloudflare…) идут мимо обхода
  $v6on = !in_array(strtolower(trim($exp['IPV6_ENABLED'] ?? '')), ['', '0', 'false', 'no'], true);
  if (!$v6on && ($dev = ispIpv6Route(preg_split('/\s+/', trim($exp['ISP_INTERFACE'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)))) {
    $add('IPV6_ENABLED', null, 'warning', "У провайдера есть IPv6 (маршрут через $dev), а IPV6_ENABLED выключен — сайты, которые открываются по IPv6, идут мимо nfqws2, и обход для них не работает",
      ['op' => 'set_var', 'var' => 'IPV6_ENABLED', 'value' => '1', 'label' => 'Включить IPv6']);
  }

  // Окончательная проверка — самим nfqws2
  $dry = dryRun($exp);
  if (!$dry['ok']) {
    $mapped = false;
    foreach ($dry['needles'] as $needle) {
      foreach (ARG_VARS as $var) {
        foreach (tokens($raw[$var] ?? '') as $i => $t) {
          if (str_contains($t, $needle)) {
            $add($var, $i, 'error', 'nfqws2: ' . $dry['message']);
            $mapped = true;
            break 3;
          }
        }
      }
    }
    if (!$mapped) {
      $add(null, null, 'error', 'nfqws2 отклонил параметры: ' . $dry['message']);
    }
  }
  return ['issues' => $issues, 'dry_run' => ['ok' => $dry['ok'], 'message' => $dry['message']]];
}

// Маскировки (fooling) nfqws первого zapret (--dpi-desync-fooling=…) и их запись в nfqws2. Значения — как у nfqws
// по умолчанию: badseq сдвигал seq на -10000 и ack на -66000, ts — метку времени на -600000
const NFQWS1_FOOLING = [
  'md5sig' => 'tcp_md5',
  'badseq' => 'tcp_seq=-10000:tcp_ack=-66000',
  'datanoack' => 'tcp_flags_unset=ack',
  'ts' => 'tcp_ts=-600000',
  'hopbyhop' => 'ip6_hopbyhop',
  'hopbyhop2' => 'ip6_hopbyhop2',
  'destopt' => 'ip6_destopt',
];

function lintDesync(string $var, int $i, string $val, array $funcs, array $lua, array $loadedLua, array $blobs, array $help, callable $add, bool $pic = false): void
{
  $parts = explode(':', $val);
  $fn = array_shift($parts);
  if (!isset($funcs[$fn])) {
    $s = levenshteinBest($fn, array_keys($funcs));
    $add($var, $i, 'error', "Нет такой lua-функции «{$fn}»" . ($s ? " — может быть, {$s}?" : ''), $s ? ['op' => 'replace', 'to' => '--lua-desync=' . implode(':', array_merge([$s], $parts)), 'label' => "Заменить на $s"] : null);
    return;
  }
  $f = $funcs[$fn];
  if (!isset($loadedLua[$f['file']])) {
    $add($var, $i, 'error', "Функция {$fn} из {$f['file']}, а этот файл не подключён через --lua-init",
      listFileExists(LUA_DIR . "/{$f['file']}") ? ['op' => 'append', 'var' => 'NFQWS_BASE_ARGS', 'tok' => '--lua-init=@' . LUA_DIR . "/{$f['file']}", 'label' => "Подключить {$f['file']}"] : null);
  }
  $allowed = array_merge(array_keys($f['args']), $f['file_keys'], ['strategy', 'final'], $pic ? ['cond', 'cond_neg'] : []);
  foreach ($f['std'] as $g) {
    $allowed = array_merge($allowed, array_keys($lua['std'][$g] ?? []));
  }
  $known = $allowed;
  foreach ($lua['std'] as $g) {
    $known = array_merge($known, array_keys($g));
  }
  foreach ($funcs as $ff) {
    $known = array_merge($known, array_keys($ff['args']), $ff['file_keys']);
  }
  $keys = [];
  $stepKeys = array_map(fn($p) => explode('=', $p, 2)[0], $parts);   // все параметры шага: не предлагать то, что уже есть
  foreach ($parts as $p) {
    [$k, $v] = array_pad(explode('=', $p, 2), 2, null);
    $keys[$k] = $v;
    if ($k === '') {
      $add($var, $i, 'error', 'Пустой параметр — лишнее двоеточие', ['op' => 'remove_param', 'key' => '', 'label' => 'Убрать лишнее двоеточие']);
    } elseif (!in_array($k, $allowed, true)) {
      if ($k === 'cond' || $k === 'cond_neg') {
        $add($var, $i, 'warning', "«{$k}» действует только вместе с per_instance_condition — поставьте его шагом раньше в этом профиле, иначе nfqws2 параметр пропустит", ['op' => 'remove_param', 'key' => $k, 'label' => "Убрать $k"]);
      } elseif (in_array($k, $known, true)) {
        $add($var, $i, 'warning', "У функции {$fn} параметр «{$k}» не описан — возможно, он не действует", ['op' => 'remove_param', 'key' => $k, 'label' => "Убрать $k"]);
      } elseif (isset(NFQWS1_FOOLING[$k]) && $v === null) {
        // название из nfqws первого zapret: заменяем по смыслу, а не на похожее по написанию (badseq ≠ badsum)
        $to = array_values(array_filter(explode(':', NFQWS1_FOOLING[$k]), fn($x) => !in_array(explode('=', $x, 2)[0], $stepKeys, true)));
        $add($var, $i, 'warning', "«{$k}» — название маскировки из nfqws первого zapret, nfqws2 его не знает и пропускает. В nfqws2 это "
          . NFQWS1_FOOLING[$k] . (!$to ? ' — и оно в шаге уже есть' : (count($to) < count(explode(':', NFQWS1_FOOLING[$k])) ? ' (часть уже стоит в шаге)' : '')),
          $to ? ['op' => 'rename_param', 'key' => $k, 'to' => implode(':', $to), 'label' => 'Заменить на ' . implode(':', $to)] : ['op' => 'remove_param', 'key' => $k, 'label' => "Убрать $k"]);
      } else {
        $s = levenshteinBest($k, array_values(array_diff(array_unique($known), $stepKeys)));
        $add($var, $i, 'warning', "Неизвестный параметр «{$k}»" . ($s ? " — может быть, {$s}?" : ''), $s ? ['op' => 'rename_param', 'key' => $k, 'to' => $s, 'label' => "Заменить на $s"] : ['op' => 'remove_param', 'key' => $k, 'label' => "Убрать $k"]);
      }
    }
    if (in_array($k, ['blob', 'seqovl_pattern', 'pattern'], true) && $v !== null && !str_starts_with($v, '0x')
      && !isset($blobs[$v]) && !in_array($v, BUILTIN_BLOBS, true) && !in_array($v, $lua['globals'], true)) {
      $add($var, $i, 'error', "Блоб «{$v}» не объявлен — добавьте --blob={$v}:@файл в параметры запуска", ($bf = blobFiles()) ? ['op' => 'declare_blob', 'name' => $v, 'choices' => $bf, 'label' => 'Объявить блоб из файла'] : null);
    }
    if ($k === 'payload' && $v !== null) {
      foreach (explode(',', $v) as $pl) {
        if ($help['full'] && !in_array($pl, $help['payloads'], true)) {
          $add($var, $i, 'error', "Неизвестный тип payload «{$pl}»");
        }
      }
    }
    if ($k === 'strategy' && !preg_match('/^\d+$/', (string)$v)) {
      $add($var, $i, 'error', 'strategy должен быть числом');
    }
  }
  // tls_mod правит поля TLS ClientHello. Если блоб — не ClientHello (частый случай: blob=0x00000000), nfqws2
  // пишет в журнал «cannot apply tls mod» на каждое соединение и шлёт блоб как есть: параметр не действует
  // fool=<name> calls a Lua function by that name on every fake; a missing one is an error each time (fool=ts is a typical slip)
  if (isset($keys['fool']) && $keys['fool'] !== '' && isset($lua['gfuncs']) && !in_array($keys['fool'], $lua['gfuncs'], true)) {
    $add($var, $i, 'error', "fool={$keys['fool']}: такой lua-функции нет. fool — имя своей функции маскировки, а не вид маскировки; "
      . 'nfqws2 на каждом фейке пишет ошибку, и шаг не работает. Маскировки задаются отдельными параметрами: tcp_md5, tcp_ts=…, badsum',
      ['op' => 'remove_param', 'key' => 'fool', 'label' => 'Убрать fool']);
  }
  if (isset($keys['tls_mod'], $keys['blob']) && blobIsTls($keys['blob'], $blobs) === false) {
    $add($var, $i, 'warning', "tls_mod не подействует: блоб «{$keys['blob']}» — не TLS ClientHello. nfqws2 отправит его как есть и на каждое соединение запишет в журнал «cannot apply tls mod»",
      ['op' => 'remove_param', 'key' => 'tls_mod', 'label' => 'Убрать tls_mod']);
  }
  foreach ($f['required'] as $r) {
    if (!array_key_exists($r, $keys)) {
      if ($r === 'blob') {
        // Без blob шаг падает с ошибкой, и nfqws2 пропускает пакет как есть — стратегия по сути ничего не делает.
        // Добавить blob = включить её: первым вариантом пустой фейк, как в стоковом конфиге nfqws2-keenetic
        $choices = array_values(array_unique(array_merge(['0x00000000'], array_keys($blobs), BUILTIN_BLOBS, array_values(array_filter($lua['globals'], fn($g) => preg_match('/^fake_default_/', $g))))));
        $add($var, $i, 'error', "Функции {$fn} нужен параметр «blob». Сейчас шаг на каждом пакете завершается ошибкой, и nfqws2 пропускает пакет без изменений — "
          . 'эта стратегия ничего не делает. С blob она начнёт работать, поэтому после исправления проверьте, что всё открывается (голос, звонки, игры — для UDP)',
          ['op' => 'add_param', 'key' => 'blob', 'choices' => $choices, 'label' => 'Добавить blob']);
      } else {
        $add($var, $i, 'error', "Функции {$fn} нужен параметр «{$r}» — без него она завершится ошибкой на каждом пакете", ['op' => 'add_param', 'key' => $r, 'choices' => [], 'label' => "Добавить $r"]);
      }
    }
  }
}

// Номера токенов-комментариев в значении переменной: от «#» до конца строки.
// Для первого токена комментария — сколько в нём слов, для остальных — 0
function commentTokens(string $val): array
{
  $comment = [];
  $n = 0;
  foreach (explode("\n", $val) as $line) {
    $lt = tokens($line);
    foreach ($lt as $k => $t) {
      if ($t[0] === '#') {
        $comment[$n + $k] = count($lt) - $k;
        for ($j = $k + 1; $j < count($lt); $j++) {
          $comment[$n + $j] = 0;
        }
        break;
      }
    }
    $n += count($lt);
  }
  return $comment;
}

// Шаг стратегии без strategy=/final= и с параметрами по порядку — чтобы сравнивать стратегии circular
function stepSig(string $tok): string
{
  $parts = explode(':', substr($tok, strlen('--lua-desync=')));
  $fn = array_shift($parts);
  $parts = array_values(array_filter($parts, fn($p) => !in_array(explode('=', $p, 2)[0], ['strategy', 'final'], true)));
  sort($parts);
  return implode(':', array_merge([$fn], $parts));
}

// circular: одинаковые стратегии в одном профиле и варианты «Добавить blob», которые сделали бы стратегию копией соседней
function lintCircular(string $var, array $toks, array $comment, array &$issues, callable $add): void
{
  $parts = [[]];
  foreach ($toks as $i => $t) {
    if (isset($comment[$i])) {
      continue;
    }
    if (isNewArg($t)) {
      $parts[] = [];
      continue;
    }
    $parts[count($parts) - 1][$i] = $t;
  }
  foreach ($parts as $part) {
    if (!array_filter($part, fn($t) => preg_match('/^--lua-desync=circular(:|$)/', $t))) {
      continue;
    }
    $st = [];   // номер стратегии => [токен => шаг]
    foreach ($part as $i => $t) {
      if (str_starts_with($t, '--lua-desync=') && preg_match('/:strategy=(\d+)(:|$)/', $t, $m)) {
        $st[(int)$m[1]][$i] = $t;
      }
    }
    ksort($st);
    $sig = fn(array $steps) => implode(' ', array_map('stepSig', $steps));
    $sigs = array_map($sig, $st);

    // «Добавить blob»: убрать варианты, после которых стратегия совпадёт с другой
    foreach ($issues as &$x) {
      if ($x['var'] !== $var || ($x['fix']['op'] ?? '') !== 'add_param' || ($x['fix']['key'] ?? '') !== 'blob') {
        continue;
      }
      foreach ($st as $n => $steps) {
        if (!isset($steps[$x['tok']])) {
          continue;
        }
        $x['fix']['choices'] = array_values(array_filter($x['fix']['choices'], function ($c) use ($steps, $x, $n, $sigs, $sig) {
          $p = explode(':', $steps[$x['tok']]);
          array_splice($p, 1, 0, "blob=$c");
          $steps[$x['tok']] = implode(':', $p);
          $s = $sig($steps);
          foreach ($sigs as $k => $other) {
            if ($k !== $n && $other === $s) {
              return false;
            }
          }
          return true;
        }));
      }
    }
    unset($x);

    // Одинаковые стратегии: circular переключается между ними без пользы
    $seen = [];
    foreach ($sigs as $n => $s) {
      if (!isset($seen[$s])) {
        $seen[$s] = $n;
        continue;
      }
      $first = $seen[$s];
      $tok = array_key_first($st[$first]);
      $one = $st[$first][$tok];
      $fix = null;
      // Частый случай — стоковый конфиг после «Добавить blob»: у стратегии один fake с тем же блобом, что у соседней.
      // В nfqws2-keenetic у неё blob=0x00000000 (пустой фейк)
      if (count($st[$first]) === 1 && str_starts_with($one, '--lua-desync=fake:') && preg_match('/:blob=([^:]+)/', $one, $m) && $m[1] !== '0x00000000') {
        $fix = ['op' => 'set_param', 'key' => 'blob', 'value' => '0x00000000', 'label' => "Стратегии $first: blob=0x00000000"];
      }
      $add($var, $tok, 'warning', "Стратегии $first и $n в circular одинаковые — переключение между ними ничего не даёт"
        . ($fix ? ". Так бывает после «Добавить blob» на стоковом конфиге: там у стратегии $first пустой фейк blob=0x00000000" : ''), $fix);
    }
  }
}

// Интерфейс провайдера, через который есть маршрут IPv6 по умолчанию, или null. Маршрут через туннель
// (не из ISP_INTERFACE) не считается: такой трафик nfqws2 и не должен видеть.
// $routes — строки «ip -6 route show default» (для проверки без роутера)
function ispIpv6Route(array $ifaces, ?array $routes = null): ?string
{
  if (!$ifaces) {
    return null;
  }
  if ($routes === null) {
    $routes = [];
    exec('ip -6 route show default 2>/dev/null', $routes);
  }
  foreach ($routes as $r) {
    if (preg_match('/^default\b.*\bdev\s+(\S+)/', trim($r), $m) && in_array($m[1], $ifaces, true)) {
      return $m[1];
    }
  }
  return null;
}

// Похож ли блоб на TLS ClientHello: запись handshake (0x16), версия 3.x, сообщение ClientHello (0x01).
// $ref — значение blob=…: 0xHEX, имя объявленного (--blob=имя:@файл|0xHEX) или встроенного блоба.
// null — узнать нельзя (блоб из lua, файла нет): тогда не предупреждаем
function blobIsTls(string $ref, array $blobs): ?bool
{
  if (in_array($ref, BUILTIN_BLOBS, true)) {
    return $ref === 'fake_default_tls';
  }
  $src = str_starts_with($ref, '0x') ? $ref : ($blobs[$ref] ?? null);
  if (!is_string($src)) {
    return null;
  }
  if ($src === 'clone') {
    return true;   // копия настоящего ClientHello
  }
  if (str_starts_with($src, '0x')) {
    $hex = substr($src, 2);
    $head = strlen($hex) % 2 === 0 && preg_match('/^[0-9a-fA-F]*$/', $hex) ? (string)hex2bin(substr($hex, 0, 12)) : null;
  } elseif (str_starts_with($src, '@') && is_file(substr($src, 1))) {
    $head = (string)file_get_contents(substr($src, 1), false, null, 0, 6);
  } else {
    return null;
  }
  if ($head === null) {
    return null;
  }
  return strlen($head) >= 6 && $head[0] === "\x16" && $head[1] === "\x03" && $head[5] === "\x01";
}

// Запускает nfqws2 --dry-run с теми же аргументами, что собрал бы init-скрипт
function dryRun(array $exp): array
{
  $args = [NFQWS_BIN, '--dry-run', '--user=' . ($exp['USER'] ?: 'nobody'), '--qnum=' . ($exp['NFQUEUE_NUM'] ?: '300')];
  if (!str_contains($exp['NFQWS_BASE_ARGS'], '--fastpath-workaround=')) {
    $args[] = '--fastpath-workaround=auto';
  }
  array_push($args, ...tokens($exp['NFQWS_BASE_ARGS']));
  $profiles = array_column(expectedProfiles($exp), 'tokens');
  foreach ($profiles as $k => $p) {
    if ($k > 0) {
      $args[] = '--new';
    }
    array_push($args, ...$p);
  }
  [$rc, $out] = runTimeout($args, 10);
  if ($rc === 0) {
    return ['ok' => true, 'message' => null, 'needles' => []];
  }
  $lines = array_values(array_filter(array_map('trim', explode("\n", $out)),
    fn($l) => $l !== '' && !preg_match('/^(github version|we have|Running as|loading|Loaded|LUA v)/', $l)));
  $msg = $lines[0] ?? "код выхода $rc";
  $needles = [];
  if (preg_match_all("/'([^']+)'/", $msg, $m)) {
    $needles = $m[1];
  }
  if (preg_match('/:\s*(\S+)\s*$/', $msg, $m)) {
    $needles[] = $m[1];
  }
  return ['ok' => false, 'message' => $msg, 'needles' => $needles];
}

function lintCurrent(): array
{
  return lintMark(lintCurrentRaw());
}

// every note, hidden ones included (the help report)
function lintCurrentRaw(): array
{
  $deps = array_merge([CONF_FILE, LISTS_DIR, NFQWS_BIN], array_values(luaFiles()));
  return cached('lint-current', $deps, function () {
    $text = file_get_contents(CONF_FILE);
    return lintConf(confRawVars($text), $text);
  }, fn() => helpInfo()['full']);
}

// Hidden lint notes: the owner said «don't show» for a warning or tip. Key — the variable and the exact text, so the
// same note on several tokens hides together, and a changed situation (other text) shows again. Errors never hide.
function lintHideKey(array $i): string
{
  return substr(md5(($i['var'] ?? '') . "\n" . $i['msg']), 0, 12);
}

function lintMark(array $lint): array
{
  // hidden notes leave «issues» altogether, so counters, «Problems» and editor marks skip them without extra code
  $hide = uiSettings()['lint_hide'];
  $shown = [];
  foreach ($lint['issues'] ?? [] as $i) {
    if ($i['level'] !== 'error') {
      $i['key'] = lintHideKey($i);
      if (isset($hide[$i['key']])) {
        continue;
      }
    }
    $shown[] = $i;
  }
  $lint['issues'] = $shown;
  $lint['hidden'] = (object)$hide;   // key => text; an object even when empty
  return $lint;
}

// Router load for the top bar: CPU from the difference of two /proc/stat readings (the previous one is kept in /tmp),
// memory from MemAvailable. Three small /proc reads — cheap enough for every state request.
function routerLoad(): array
{
  $cpu = null;
  if (preg_match('/^cpu\s+(.+)$/m', (string)@file_get_contents('/proc/stat'), $m)) {
    // user nice system idle iowait irq softirq steal; guest time is already inside user and nice
    $v = array_slice(array_map('intval', preg_split('/\s+/', trim($m[1]))), 0, 8);
    $idle = ($v[3] ?? 0) + ($v[4] ?? 0);
    $total = array_sum($v);
    $f = '/tmp/nfqws-ui-cpu';
    [$pt, $pi, $ptime, $last] = array_pad(array_map('floatval', explode(' ', (string)@file_get_contents($f))), 4, -1);
    $age = $ptime > 0 ? microtime(true) - $ptime : -1;
    if ($age >= 1 && $age < 600 && $total > $pt) {
      $cpu = (int)round(100 * (1 - ($idle - $pi) / ($total - $pt)));
      @file_put_contents($f, "$total $idle " . microtime(true) . " $cpu");
    } else {
      // a base younger than a second (two requests in a row) measures a handful of ticks: give the last figure instead
      $cpu = $age >= 0 && $age < 1 && $last >= 0 ? (int)$last : null;
      if ($age < 0 || $age >= 600) {
        @file_put_contents($f, "$total $idle " . microtime(true) . ' -1');
      }
    }
  }
  $mem = (string)@file_get_contents('/proc/meminfo');
  $kb = fn($k) => preg_match('/^' . $k . ':\s+(\d+)/m', $mem, $x) ? (int)$x[1] : null;
  $la = explode(' ', (string)@file_get_contents('/proc/loadavg'));
  return ['cpu' => $cpu === null ? null : max(0, min(100, $cpu)), 'mem_total' => $kb('MemTotal'), 'mem_free' => $kb('MemAvailable'),
    'la' => isset($la[2]) ? array_map('floatval', array_slice($la, 0, 3)) : null, 'uptime' => (int)(float)@file_get_contents('/proc/uptime')];
}
