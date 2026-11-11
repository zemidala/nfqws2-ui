<?php
// Server section: list subscriptions, podkop.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ================= подписки на списки =================
// Список обновляется по ссылке раз в сутки (cron daily) или по кнопке. Новая версия проходит проверку:
// если больше трети строк с ошибками — обновление отклоняется.

function subsRun(?string $only = null): array
{
  $s = uiSettings();
  $report = [];
  foreach ($s['subs'] as $sub) {
    if ($only !== null && $sub['list'] !== $only) {
      continue;
    }
    $path = LISTS_DIR . '/' . $sub['list'];
    $tmp = tempnam('/tmp', 'nfqsub');
    $out = [];   // exec appends: the next failing subscription would show this one's output too
    exec('curl -fsSL -m 60 --max-filesize 10485760 -o ' . escapeshellarg($tmp) . ' ' . escapeshellarg($sub['url']) . ' 2>&1', $out, $rc);
    $sub['last'] = time();
    if ($rc !== 0) {
      $sub['status'] = 'error';
      $sub['error'] = 'не скачалось: ' . (trim(implode(' ', $out)) ?: "curl $rc");
    } else {
      $text = normalizeText((string)file_get_contents($tmp));
      $lines = count(readListLinesFromText($text));
      $errors = count(array_filter(lintList($sub['list'], $text), fn($x) => $x['level'] === 'error'));
      if ($lines === 0 || $errors > $lines / 3) {
        $sub['status'] = 'error';
        $sub['error'] = $lines === 0 ? 'пустой список' : "слишком много ошибок ($errors из $lines строк) — это не похоже на список";
      } else {
        $old = is_file($path) ? file_get_contents($path) : '';
        [$added, $removed] = lineDelta($old, $text);
        if ($old !== $text) {
          writeWithBackup($path, $text, 'обновлено по подписке: +' . count($added) . ' −' . count($removed));
        }
        $sub['status'] = 'ok';
        $sub['error'] = null;
        $sub['count'] = $lines;
        $sub['changed'] = $old !== $text ? ['added' => count($added), 'removed' => count($removed)] : null;
      }
    }
    @unlink($tmp);
    $report[] = $sub;
  }
  // the downloads take up to a minute each: what the page saved meanwhile (token, sites, other subscriptions) stays —
  // only the results of these subscriptions go into the settings read now
  $s = uiSettings();
  $byList = array_column($report, null, 'list');
  foreach ($s['subs'] as $i => $sub) {
    if (isset($byList[$sub['list']]) && $byList[$sub['list']]['url'] === $sub['url']) {
      $s['subs'][$i] = $byList[$sub['list']];
    }
  }
  saveUiSettings($s);
  return $report;
}

function readListLinesFromText(string $text): array
{
  return array_values(array_filter(array_map('listEntry', explode("\n", $text)), fn($l) => $l !== null));
}

// ================= podkop =================
// podkop (sing-box) отвечает на DNS-запросы адресами FakeIP из 198.18.0.0/15 для сайтов, которые
// пускает через прокси. Спрашиваем его DNS напрямую — так видно, куда пойдёт трафик клиентов сети.

function podkopRoute(string $host): ?array
{
  if (!is_file('/etc/init.d/podkop')) {
    return null;
  }
  exec('nslookup ' . escapeshellarg($host) . ' 127.0.0.42 2>/dev/null', $out, $rc);
  $ips = [];
  $afterName = false;
  foreach ($out as $l) {
    if (str_starts_with($l, 'Name:')) {
      $afterName = true;
    } elseif ($afterName && preg_match('/^Address:\s*([0-9.]+)$/', trim($l), $m)) {
      $ips[] = $m[1];
    }
  }
  if (!$ips) {
    return ['available' => false];
  }
  $fake = (bool)array_filter($ips, fn($ip) => ipInEntry(inet_pton($ip), '198.18.0.0/15'));
  return ['available' => true, 'proxy' => $fake, 'ips' => $ips];
}
