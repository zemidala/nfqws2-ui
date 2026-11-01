<?php
// Server section: checking list entries.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ---------- проверка списков ----------

function validCidr(string $s): bool
{
  [$ip, $bits] = array_pad(explode('/', $s, 2), 2, null);
  $bin = @inet_pton($ip);
  if ($bin === false) {
    return false;
  }
  return $bits === null || (preg_match('/^\d+$/', $bits) && (int)$bits <= strlen($bin) * 8);
}

// Замечания по строкам списка: line — номер строки с 0; fix — чем заменить (строка) или null — удалить
function lintList(string $name, string $content): array
{
  $isIp = listKind($name, readListLinesFromText($content)) === 'ip';
  $lines = explode("\n", $content);
  $issues = [];
  $seen = [];
  $domains = [];
  foreach ($lines as $i => $rawLine) {
    $t = trim($rawLine);
    if ($t === '' || $t[0] === '#') {
      continue;
    }
    $key = strtolower($t);
    if ($isIp) {
      if (!validCidr($t)) {
        $issues[] = ['line' => $i, 'level' => 'error', 'msg' => 'Не IP-адрес и не подсеть'];
        continue;
      }
    } else {
      $d = ltrim($key, '^');
      if (str_contains($d, '*')) {
        $fix = preg_replace('/^\*\.?/', '', $d);
        $issues[] = ['line' => $i, 'level' => 'error', 'fix' => validHost($fix) ? $fix : null,
          'msg' => '«*» nfqws2 не поддерживает — запись не работает. Поддомены учитываются автоматически' . (validHost($fix) ? ", достаточно «{$fix}»" : '')];
        continue;
      }
      if (preg_match('#^[a-z]+://#', $d) || str_contains($d, '/')) {
        $issues[] = ['line' => $i, 'level' => 'error', 'fix' => cleanHost($d), 'msg' => 'Нужно только имя сайта, без http:// и пути'];
        continue;
      }
      if (!validHost($d)) {
        $issues[] = ['line' => $i, 'level' => 'error', 'msg' => 'Некорректное имя сайта'];
        continue;
      }
      if ($key[0] !== '^') {
        $domains[$d] = $domains[$d] ?? $i;
      }
    }
    if (isset($seen[$key])) {
      $issues[] = ['line' => $i, 'level' => 'warning', 'fix' => null, 'msg' => 'Повтор строки ' . ($seen[$key] + 1)];
      continue;
    }
    $seen[$key] = $i;
  }
  if (!$isIp) {
    foreach ($domains as $d => $i) {
      $parts = explode('.', $d);
      for ($k = 1; $k < count($parts) - 1; $k++) {
        $parent = implode('.', array_slice($parts, $k));
        if (isset($domains[$parent])) {
          $issues[] = ['line' => $i, 'level' => 'info', 'fix' => null, 'msg' => "Лишняя: уже покрыта записью «{$parent}» (строка " . ($domains[$parent] + 1) . ')'];
          break;
        }
      }
    }
  }
  usort($issues, fn($a, $b) => $a['line'] - $b['line']);
  return $issues;
}

function listProblems(string $path): array
{
  if (!is_file($path) || filesize($path) > 1048576) {
    return ['error' => 0, 'warning' => 0, 'info' => 0];
  }
  // вид списка зависит и от того, как его подключает конфиг
  return cached('listlint-' . basename($path), [$path, CONF_FILE], function () use ($path) {
    $c = ['error' => 0, 'warning' => 0, 'info' => 0];
    foreach (lintList(basename($path), file_get_contents($path)) as $x) {
      $c[$x['level']]++;
    }
    return $c;
  });
}
