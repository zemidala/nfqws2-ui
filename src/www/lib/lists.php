<?php
// Server section: lists of sites and addresses.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ================= списки =================

function readListLines(string $path): array
{
  $src = is_file($path) ? $path : (is_file("$path.gz") ? "compress.zlib://$path.gz" : null);
  if ($src === null) {
    return [];
  }
  $out = [];
  foreach (@file($src, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    $l = trim($l);
    if ($l !== '' && $l[0] !== '#' && $l[0] !== ';') {
      $out[] = $l;
    }
  }
  return $out;
}

// Вид списка по подключению: --ipset → ip, --hostlist → host. Профили передаёт listsInventory, иначе читаем сами
function listUse(?array $profiles = null): array
{
  static $use = null;
  if ($profiles !== null || $use === null) {
    $use = [];
    foreach ($profiles ?? currentProfiles() as $p) {
      foreach (['ipsets' => 'ip', 'ipset_excludes' => 'ip', 'hostlists' => 'host', 'hostlist_excludes' => 'host'] as $key => $kind) {
        foreach ($p[$key] as $path) {
          $use[$path] ??= $kind;
        }
      }
      if ($p['autolist']) {
        $use[$p['autolist']] ??= 'host';
      }
    }
  }
  return $use;
}

// Подключён ли список к профилю. Неподключённые не проверяются: в той же папке лежат и чужие списки
// (для XKeen и т. п.) со своим форматом — проверять их незачем, а большие ещё и читать долго
function listUsed(string $path): bool
{
  foreach (array_keys(listUse()) as $p) {
    if (basename($p) === basename($path)) {
      return true;
    }
  }
  return false;
}

// Вид списка: как его подключают профили; неподключённый — по содержимому (больше половины строк —
// адреса и подсети); пустой — по имени. $lines — записи, если они уже прочитаны или ещё не сохранены
function listKind(string $name, ?array $lines = null): string
{
  $use = listUse();
  if (isset($use[LISTS_DIR . "/$name"])) {
    return $use[LISTS_DIR . "/$name"];
  }
  if ($lines === null) {
    return listMeta(LISTS_DIR . "/$name")['kind'] ?? (str_starts_with($name, 'ipset') ? 'ip' : 'host');
  }
  return listContentKind($lines) ?? (str_starts_with($name, 'ipset') ? 'ip' : 'host');
}

function listContentKind(array $lines): ?string
{
  $lines = array_slice($lines, 0, 200);
  return $lines ? (count(array_filter($lines, 'validCidr')) * 2 > count($lines) ? 'ip' : 'host') : null;
}

// Число записей и вид по содержимому — из кеша, пока у файла те же время и размер: опись списков входит в каждое
// состояние, а читать все списки целиком (бывают и на сотню тысяч строк) на слабом роутере — сотни миллисекунд
function listMeta(string $path): array
{
  $real = is_file($path) ? $path : (is_file("$path.gz") ? "$path.gz" : null);
  if ($real === null) {
    return ['entries' => 0, 'kind' => null];
  }
  return cached('listmeta-' . md5($path), [$real], function () use ($path) {
    $lines = readListLines($path);
    return ['entries' => count($lines), 'kind' => listContentKind($lines)];
  });
}

function listsInventory(array $profiles): array
{
  listUse($profiles);
  $usage = [];
  $roles = ['hostlists' => 'include', 'hostlist_excludes' => 'exclude', 'ipsets' => 'include', 'ipset_excludes' => 'exclude'];
  foreach ($profiles as $p) {
    foreach ($roles as $key => $role) {
      foreach ($p[$key] as $path) {
        $usage[$path][] = ['profile' => $p['index'], 'role' => $role];
      }
    }
    if ($p['autolist']) {
      $usage[$p['autolist']][] = ['profile' => $p['index'], 'role' => 'auto'];
    }
  }
  $paths = [];
  foreach (glob(LISTS_DIR . '/*') as $f) {
    if (is_file($f) && preg_match('/\.list(\.gz)?$/', $f)) {
      $paths[preg_replace('/\.gz$/', '', $f)] = true;
    }
  }
  foreach (array_keys($usage) as $path) {
    $paths[$path] = true;
  }
  $res = [];
  foreach (array_keys($paths) as $path) {
    $real = is_file($path) ? $path : (is_file("$path.gz") ? "$path.gz" : null);
    $name = basename($path);
    $res[] = [
      'name' => $name,
      'path' => $path,
      'kind' => listUse()[$path] ?? listKind($name),
      'exists' => $real !== null,
      'editable' => $real === $path && dirname($path) === LISTS_DIR,
      // стандартные списки nfqws2-keenetic не удаляются и не переименовываются: на них ссылается стандартный конфиг
      // (MODE_LIST, MODE_AUTO, NFQWS_ARGS_IPSET) — без файла nfqws2 после возврата к нему не запустится. Очистить можно
      'removable' => !in_array($name, PROTECTED_LISTS, true) && empty($usage[$path]), 'stock' => in_array($name, PROTECTED_LISTS, true),
      'entries' => $real ? listMeta($path)['entries'] : 0,
      'size' => $real ? filesize($real) : 0,
      'mtime' => $real ? filemtime($real) : null,
      'used' => $usage[$path] ?? [],
      'problems' => $real === $path && !empty($usage[$path]) ? listProblems($path) : ['error' => 0, 'warning' => 0, 'info' => 0],
    ];
  }
  usort($res, fn($a, $b) => [!$a['used'], $a['kind'] !== 'host', $a['name']] <=> [!$b['used'], $b['kind'] !== 'host', $b['name']]);
  return $res;
}
