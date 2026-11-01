<?php
// Server section: excluding a site from a profile that breaks it.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ---------- исключение из профиля: сайт не заблокирован, а профиль его ломает ----------
// Общий профиль (например, «весь HTTPS») обрабатывает и сайты, которые провайдер не трогает; у части из них фейки
// ломают соединение. «Проверить сайт» после неудачи открывает сайт мимо nfqws2 и, если он открылся, предлагает
// исключить его из профиля: по имени, домену, адресу или всей сети (ASN — для игр, которые ходят по адресу).

const EXCLUDE_IP_LIST = 'ipset_exclude_ui.list';

// Мимо nfqws2, по первому IPv4-адресу. null — проверить нельзя (идёт подбор, нет адреса)
function probeDirect(string $host, array $ips): ?array
{
  $ip = array_values(array_filter($ips, fn($x) => isIp4($x) && !ipReserved($x)))[0] ?? null;
  if (!$ip || testRunning()) {
    return null;
  }
  $r = diagBypass(fn() => diagDirect($host, $ip));
  return ['ok' => $r['ok'] && !$r['isp_page'], 'code' => $r['code'], 'ms' => $r['ms'], 'kind' => $r['kind'], 'ip' => $ip];
}

function profileByIndex(int $index): array
{
  foreach (currentProfiles() as $p) {
    if ($p['index'] === $index) {
      return $p;
    }
  }
  fail("Профиля #$index нет — обновите страницу");
}

// Список, который можно править из интерфейса (лежит в папке списков)
function ownList(string $path): bool
{
  return dirname($path) === LISTS_DIR && preg_match('/^[A-Za-z0-9_.-]+\.list$/', basename($path));
}

function regDomain(string $host): ?string
{
  $l = explode('.', $host);
  return count($l) > 2 ? implode('.', array_slice($l, -2)) : null;
}

// Что можно исключить и что для этого изменится. Каждый вариант: mode, title, hint, files [{name, add|remove}], token
function excludeOptions(string $host, int $index): array
{
  if (!validHost($host)) {
    fail('Некорректное имя сайта');
  }
  $p = profileByIndex($index);
  if (!$p['source']) {
    fail('Запущенный nfqws2 не совпадает с конфигом — перезапустите его и проверьте сайт ещё раз');
  }
  $isIp = isIp($host);
  $ips = $isIp ? [$host] : array_values(array_filter(gethostbynamel($host) ?: [], 'isIp4'));
  $cache = new ListCache();
  $opts = [];
  // Имена: в первый свой список исключений профиля; если его нет — exclude.list и строка в профиле
  if (!$isIp) {
    $exPath = array_values(array_filter($p['hostlist_excludes'], 'ownList'))[0] ?? null;
    $token = $exPath ? null : '--hostlist-exclude=' . LISTS_DIR . '/exclude.list';
    $exName = basename($exPath ?? 'exclude.list');
    // сайт попал в профиль из его списка сайтов — проще убрать запись
    $hh = hostIn($host, $p['hostlists'], [], $cache);
    if ($hh && $hh['entry'] === $host && ownList(LISTS_DIR . '/' . $hh['list']) && in_array(LISTS_DIR . '/' . $hh['list'], $p['hostlists'], true)) {
      $also = array_map(fn($x) => '#' . $x['index'], array_filter(currentProfiles(), fn($x) => $x['index'] !== $index && in_array(LISTS_DIR . '/' . $hh['list'], $x['hostlists'], true)));
      $opts[] = ['mode' => 'unlist', 'title' => "Убрать $host из {$hh['list']}", 'hint' => 'Сайт попадает в профиль из этого списка — запись не нужна.'
        . ($also ? ' Список подключён и к ' . (count($also) > 1 ? 'профилям ' : 'профилю ') . implode(', ', $also) . ' — там сайта тоже не станет.' : ''),
        'files' => [['name' => $hh['list'], 'remove' => [$host]]], 'token' => null];
    }
    $opts[] = ['mode' => 'name', 'title' => "Только $host", 'hint' => "Имя в $exName. Другие сайты" . (regDomain($host) ? ' ' . regDomain($host) : '') . ' остаются в профиле.',
      'files' => [['name' => $exName, 'add' => [$host]]], 'token' => $token];
    if ($d = regDomain($host)) {
      $opts[] = ['mode' => 'domain', 'title' => "Весь $d", 'hint' => 'Вместе со всеми поддоменами.', 'files' => [['name' => $exName, 'add' => [$d]]], 'token' => $token];
    }
  }
  // Адреса: общий список исключений интерфейса; профиль подключает его через --ipset-exclude
  $ipPath = LISTS_DIR . '/' . EXCLUDE_IP_LIST;
  $ipToken = in_array($ipPath, $p['ipset_excludes'], true) ? null : "--ipset-exclude=$ipPath";
  if ($ips) {
    $shown = array_slice($ips, 0, 8);
    $opts[] = ['mode' => 'ip', 'title' => 'По адресу ' . implode(', ', array_slice($shown, 0, 3)) . (count($shown) > 3 ? ' и ещё ' . (count($shown) - 3) : ''),
      'hint' => 'Для программ и игр, которые подключаются по адресу, без имени.', 'files' => [['name' => EXCLUDE_IP_LIST, 'add' => $shown]], 'token' => $ipToken];
    $asn = (int)ipAsn($ips[0]);   // cached for a day, like the name below for a month
    if ($asn) {
      $holder = asName($asn);
      $asPath = LISTS_DIR . "/ipset_as$asn.list";
      $opts[] = ['mode' => 'asn', 'asn' => $asn, 'title' => "Вся сеть AS$asn" . ($holder !== '' ? " ($holder)" : ''),
        'hint' => 'Если у сервера много адресов. Широко: мимо профиля пойдёт всё в этой сети. Список префиксов обновляется раз в сутки.',
        'files' => [['name' => "ipset_as$asn.list", 'add' => ['префиксы AS' . $asn . ' из RIPEstat']]],
        'token' => in_array($asPath, $p['ipset_excludes'], true) ? null : "--ipset-exclude=$asPath"];
    }
  }
  return ['host' => $host, 'profile' => $index, 'name' => $p['name'], 'options' => $opts];
}

// Вставляет аргумент в профиль: в свою часть NFQWS_ARGS_CUSTOM — перед первым --payload/--lua-desync (рядом с фильтрами),
// в составной профиль — в конец переменной, которая есть только у него (NFQWS_ARGS_IPSET, NFQWS_EXTRA_ARGS)
function profileAddToken(string $text, array $p, string $tok): string
{
  $src = $p['source'];
  $var = $src['source'];
  if ($var !== 'NFQWS_ARGS_CUSTOM') {
    return appendTokenInText($text, $src['with'] ?? $var, $tok);
  }
  $toks = tokens(confRawVars($text)[$var] ?? '');
  $part = 1;
  $at = null;
  $last = null;
  foreach ($toks as $i => $t) {
    if (isNewArg($t)) {
      $part++;
      continue;
    }
    if ($part !== $src['part']) {
      continue;
    }
    $last = $i;
    if ($at === null && in_array(optName($t), ['payload', 'lua-desync'], true)) {
      $at = $i;
    }
  }
  if ($last === null) {
    fail('Профиль в конфиге не найден — обновите страницу');
  }
  // отступ строки, где стоит аргумент: новая строка выглядит так же, как соседние
  preg_match('/^' . $var . '="([^"]*)"/m', $text, $m, PREG_OFFSET_CAPTURE);
  preg_match_all('/\S+/', preg_replace('/\\\\(?=[ \t]*\r?\n)/', ' ', $m[1][0]), $tm, PREG_OFFSET_CAPTURE);
  $i = $at ?? $last;
  $before = substr($m[1][0], 0, $tm[0][$i][1]);
  $nl = strrpos($before, "\n");
  $indent = $nl === false ? '' : substr($before, $nl + 1);
  $sep = $nl === false || trim($indent) !== '' ? ' ' : "\n" . $indent;
  $orig = $toks[$i];
  return editTokenInText($text, $var, $i, $at !== null ? $tok . $sep . $orig : $orig . $sep . $tok);
}

function excludeApply(string $host, int $index, string $mode): array
{
  $all = excludeOptions($host, $index);
  $o = array_values(array_filter($all['options'], fn($x) => $x['mode'] === $mode))[0] ?? null;
  if (!$o) {
    fail('Такого варианта нет — обновите страницу');
  }
  $note = "исключение из профиля #$index: " . $o['title'];
  $files = [];
  if ($mode === 'asn') {
    $a = asnFetch($o['asn']);
    if (!$a['prefixes']) {
      fail("У AS{$o['asn']} нет анонсированных префиксов");
    }
    $files[] = asnWrite($a, true)['name'];
  } else {
    foreach ($o['files'] as $f) {
      $path = LISTS_DIR . '/' . $f['name'];
      $text = is_file($path) ? rtrim((string)file_get_contents($path), "\n") : ($f['name'] === EXCLUDE_IP_LIST ? '# Исключены из профилей кнопкой «Исключить» (nfqws2-ui): сайт не заблокирован, а профиль его ломает' : '');
      $lines = $text === '' ? [] : explode("\n", $text);
      $has = array_map(fn($l) => strtolower(trim($l)), $lines);
      if (!empty($f['remove'])) {
        $lines = array_values(array_filter($lines, fn($l) => !in_array(strtolower(trim($l)), $f['remove'], true)));
      }
      foreach ($f['add'] ?? [] as $x) {
        if (!in_array(strtolower($x), $has, true)) {
          $lines[] = $x;
        }
      }
      if (!writeWithBackup($path, implode("\n", $lines) . "\n", $note)) {
        fail("Не удалось записать {$f['name']}", 500);
      }
      $files[] = $f['name'];
    }
  }
  if ($o['token']) {
    $new = profileAddToken((string)file_get_contents(CONF_FILE), profileByIndex($index), $o['token']);
    $lint = lintConf(confRawVars($new), $new);
    if (!$lint['dry_run'] || !$lint['dry_run']['ok']) {
      fail('nfqws2 не принимает конфиг с исключением: ' . ($lint['dry_run']['message'] ?? 'ошибка'), 422);
    }
    if (!writeWithBackup(CONF_FILE, $new, $note)) {
      fail('Не удалось записать конфиг', 500);
    }
    $files[] = 'nfqws2.conf';
  }
  return ['files' => $files, 'title' => $o['title'], 'token' => $o['token']];
}

// $ip — открыть по этому адресу, а не по ответу DNS роутера (проверка «по настоящему адресу» при подмене DNS)
function probe(string $host, ?string $ip = null): array
{
  $ch = curl_init('https://' . $host . '/');
  $code = null;
  $got = 0;
  $resolve = $ip !== null ? ["$host:443:$ip"] : [];
  curl_setopt_array($ch, [
    CURLOPT_RESOLVE => $resolve,
    // по IPv4, как подбор и диагноз: маршрут через профили считается для IPv4, а при IPv6 от провайдера запрос ушёл бы
    // по нему — мимо стратегии, если nfqws2 не настроен на IPv6 (в подборе сайт открывается, здесь — «тайм-аут»)
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_NOSIGNAL => 1,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130 Safari/537.36',
    CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$code) {
      if (preg_match('#^HTTP/[\d.]+\s+(\d+)#', $h, $m)) {
        $code = (int)$m[1];
      }
      return strlen($h);
    },
    CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$got) {
      $got += strlen($chunk);
      return $got > VOLUME_BYTES ? 0 : strlen($chunk);   // хватит: объём уже прошёл
    },
  ] + policyProbeOpts());
  $t = microtime(true);
  curl_exec($ch);
  $errno = curl_errno($ch);
  $err = curl_error($ch);
  curl_close($ch);
  $ms = (int)round((microtime(true) - $t) * 1000);
  // ответ пришёл, но тело встало — это заморозка, а не рабочий сайт
  if ($code !== null && $errno === 28) {
    return ['ok' => false, 'code' => $code, 'ms' => $ms, 'reason' => REASON_FREEZE];
  }
  if ($code !== null) {
    $frozen = $got < VOLUME_SMALL && volumeFrozen('https://' . $host . '/', $resolve);
    return ['ok' => !$frozen, 'code' => $code, 'ms' => $ms, 'reason' => $frozen ? REASON_FREEZE : null];
  }
  $reason = match ($errno) {
    6 => 'имя не разрешается (DNS)',
    7 => 'не удалось подключиться',
    28 => 'нет ответа (тайм-аут) — похоже на блокировку',
    35 => 'обрыв при установке TLS — похоже на блокировку DPI',
    52 => 'сервер ничего не ответил',
    56 => 'соединение сброшено — похоже на блокировку DPI',
    default => $err ?: "ошибка $errno",
  };
  return ['ok' => false, 'code' => null, 'ms' => $ms, 'reason' => $reason];
}
