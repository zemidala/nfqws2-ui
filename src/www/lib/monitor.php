<?php
// Server section: site monitoring and Telegram notices.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ================= мониторинг доступности =================
// Раз в interval минут (по cron-сканированию) открывает сайты с роутера и хранит последние 96 результатов.
// При смене состояния отправляет сообщение в Telegram, если задан бот.

define('MONITOR_FILE', UI_CONF_DIR . '/monitor.json');

function monitorData(): array
{
  $d = json_decode((string)@file_get_contents(MONITOR_FILE), true);
  return is_array($d) ? $d : ['last' => 0, 'sites' => []];
}

function monitorRun(bool $force, ?array $only = null): ?array
{
  $cfg = uiSettings()['monitor'];
  $data = monitorData();
  if (!$force && (!$cfg['enabled'] || time() - ($data['last'] ?? 0) < $cfg['interval'] * 60 - 30)) {
    return null;
  }
  $changes = [];
  foreach ($only !== null ? array_values(array_intersect($cfg['sites'], $only)) : $cfg['sites'] as $host) {
    $r = probe($host);
    if (!$r['ok'] && ($sp = dnsSpoof($host))) {
      $r['reason'] = dnsSpoofReason($sp);   // по этой строке автоподбор поймёт, что стратегия ни при чём
    }
    $hist = $data['sites'][$host] ?? [];
    $prevOk = $hist ? end($hist)[1] : null;
    $hist[] = [time(), $r['ok'] ? 1 : 0, $r['ms'], $r['reason']];
    $data['sites'][$host] = array_slice($hist, -96);
    if ($prevOk !== null && $prevOk !== ($r['ok'] ? 1 : 0)) {
      $changes[] = ($r['ok'] ? '✅ снова открывается: ' : '❌ перестал открываться: ') . $host . ($r['ok'] ? '' : ' — ' . $r['reason']);
    }
  }
  // сайты, убранные из мониторинга, не храним
  $data['sites'] = array_intersect_key($data['sites'], array_flip($cfg['sites']));
  if ($only === null) {
    $data['last'] = time();   // ручная проверка отдельных сайтов не сдвигает расписание
  }
  @mkdir(UI_CONF_DIR, 0755, true);
  putSafe(MONITOR_FILE, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  if ($changes) {
    notifyTelegram("nfqws2 на роутере:\n" . implode("\n", $changes));
  }
  return $data;
}

// Неотправленные уведомления и журнал отправок. Telegram бывает недоступен (туннель упал, адрес закрыт) —
// тогда сообщение ждёт в очереди и уходит со следующим проходом cron, но не позже чем через сутки
define('NOTIFY_FILE', UI_CONF_DIR . '/notify.json');
define('NOTIFY_KEEP', 86400);

function notifyData(): array
{
  $d = json_decode((string)@file_get_contents(NOTIFY_FILE), true);
  return (is_array($d) ? $d : []) + ['queue' => [], 'log' => []];
}

// $queue = false — только сейчас и без журнала (проверочное сообщение: ошибку сразу видит человек)
function notifyTelegram(string $text, bool $queue = true): ?string
{
  $t = uiSettings()['notify'];
  if (empty($t['tg_token']) || empty($t['tg_chat'])) {
    return 'не настроено';
  }
  if (!$queue) {
    $r = tgCall($t, 'sendMessage', ['chat_id' => $t['tg_chat'], 'text' => $text]);
    return $r['ok'] ? null : $r['error'];
  }
  $d = notifyData();
  $d['queue'][] = ['ts' => time(), 'text' => $text];
  return notifyFlush($d);
}

// Отправляет очередь по порядку. На сетевой ошибке останавливается — остальное ждёт следующего прохода;
// отказ самого Telegram (неверный токен, чат) повтором не лечится — такое сообщение снимается
function notifyFlush(?array $d = null): ?string
{
  $d = $d ?? notifyData();
  if (!$d['queue']) {
    return null;
  }
  $t = uiSettings()['notify'];
  $err = null;
  while ($d['queue']) {
    $m = $d['queue'][0];
    if (time() - $m['ts'] > NOTIFY_KEEP) {
      array_shift($d['queue']);
      $d['log'][] = ['ts' => time(), 'at' => $m['ts'], 'text' => $m['text'], 'ok' => false, 'error' => 'не ушло за сутки: ' . ($m['error'] ?? 'нет связи')];
      continue;
    }
    if (empty($t['tg_token']) || empty($t['tg_chat'])) {
      $err = 'не настроено';
      break;
    }
    $late = time() - $m['ts'] > 120;
    $text = $m['text'] . ($late ? "\n(событие в " . ldate('d.m H:i', $m['ts']) . ', сообщение задержалось: Telegram был недоступен)' : '');
    $r = tgCall($t, 'sendMessage', ['chat_id' => $t['tg_chat'], 'text' => $text]);
    if (!$r['ok'] && ($r['code'] === null || $r['code'] === 429)) {
      $err = $r['error'];
      $d['queue'][0]['error'] = $err;
      $d['queue'][0]['tries'] = ($m['tries'] ?? 0) + 1;
      break;
    }
    array_shift($d['queue']);
    $d['log'][] = ['ts' => time(), 'at' => $m['ts'], 'text' => $m['text'], 'ok' => $r['ok']] + ($r['ok'] ? [] : ['error' => $r['error']]);
    if (!$r['ok']) {
      $err = $r['error'];
    }
  }
  $d['log'] = array_slice($d['log'], -30);
  @mkdir(UI_CONF_DIR, 0755, true);
  putSafe(NOTIFY_FILE, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  return $err;
}

// Запрос к Bot API тем путём, что выбран в настройках. ok, result — ответ Telegram; иначе error словами
// и code — код ошибки Telegram (null, если до него не дошли)
function tgCall(array $t, string $method, array $params): array
{
  $opts = [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 6];
  // api.telegram.org у многих закрыт по IP — тогда идём через выбранный туннель или прокси
  if ($t['via'] === 'iface' && $t['iface'] !== '') {
    $opts[CURLOPT_INTERFACE] = $t['iface'];
    if ($ip = telegramRealIp()) {
      $opts[CURLOPT_RESOLVE] = ["api.telegram.org:443:$ip"];
    }
  } elseif ($t['via'] === 'proxy' && $t['proxy'] !== '') {
    $opts[CURLOPT_PROXY] = $t['proxy'];
  }
  $ch = curl_init('https://api.telegram.org/bot' . $t['tg_token'] . '/' . $method);
  curl_setopt_array($ch, $opts);
  $res = curl_exec($ch);
  $errno = curl_errno($ch);
  $err = curl_error($ch);
  curl_close($ch);
  $j = json_decode((string)$res, true);
  if (!empty($j['ok'])) {
    return ['ok' => true, 'result' => $j['result'] ?? null];
  }
  if (isset($j['description'])) {
    // Telegram ответил — значит, путь работает, дело в токене или ID чата
    return ['ok' => false, 'error' => (string)$j['description'], 'code' => (int)($j['error_code'] ?? 0)];
  }
  $why = [6 => 'имя api.telegram.org не находится', 5 => 'имя прокси не находится', 7 => 'нет соединения', 28 => 'нет ответа за 10 секунд',
    35 => 'соединение оборвано при установке TLS', 45 => 'интерфейс не найден, выключен или без адреса', 56 => 'соединение оборвано', 97 => 'прокси отказал'][$errno]
    ?? preg_replace('#//[^@/\s]+@#', '//••••@', $err ?: 'ошибка отправки');
  return ['ok' => false, 'error' => notifyVia($t) . ': ' . $why, 'code' => null];
}

// Чаты, из которых боту недавно писали, — чтобы не узнавать ID чата вручную. Telegram хранит сообщения сутки.
// offset не передаём: сообщения не отмечаются прочитанными и остаются тому, кто ещё опрашивает этого бота
function tgChats(): array
{
  $t = uiSettings()['notify'];
  if (empty($t['tg_token'])) {
    return ['error' => 'Сначала укажите токен бота.'];
  }
  $r = tgCall($t, 'getUpdates', ['limit' => 100, 'timeout' => 0]);
  if (!$r['ok']) {
    if ($r['code'] === 401 || $r['code'] === 404) {
      return ['error' => 'Telegram не принял токен — проверьте, что он скопирован у @BotFather целиком.'];
    }
    if ($r['code'] === 409) {
      return ['error' => stripos($r['error'], 'webhook') !== false
        ? 'У этого бота включён webhook — им пользуется другая программа, и сообщения отсюда не прочитать. Создайте для уведомлений отдельного бота у @BotFather.'
        : 'Этого бота прямо сейчас опрашивает другая программа. Создайте для уведомлений отдельного бота у @BotFather или укажите ID чата вручную.'];
    }
    return ['error' => 'Telegram: ' . $r['error']];
  }
  $chats = [];
  foreach (is_array($r['result']) ? $r['result'] : [] as $u) {
    foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post', 'my_chat_member', 'chat_member', 'chat_join_request'] as $k) {
      $c = $u[$k]['chat'] ?? null;
      if (!is_array($c) || !isset($c['id'])) {
        continue;
      }
      $name = trim((string)($c['title'] ?? trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))));
      // более позднее сообщение из того же чата заменяет раннее и уходит в конец
      unset($chats[(string)$c['id']]);
      $chats[(string)$c['id']] = ['id' => (string)$c['id'], 'type' => (string)($c['type'] ?? ''),
        'name' => preg_match('/^.{0,60}/us', $name, $m) ? $m[0] : '', 'username' => (string)($c['username'] ?? '')];
    }
  }
  return ['chats' => array_reverse(array_values($chats))];
}

// Какой бот подключён и в какой чат он пишет — чтобы по токену из точек и числу ID было видно, что это.
// getMe и getChat сообщений не забирают и другой программе, которая опрашивает бота, не мешают
function tgInfo(): array
{
  $t = uiSettings()['notify'];
  if (empty($t['tg_token'])) {
    return ['bot' => null, 'chat' => null];
  }
  $cut = fn($s) => preg_match('/^.{0,60}/us', trim((string)$s), $m) ? $m[0] : '';
  $r = tgCall($t, 'getMe', []);
  if (!$r['ok']) {
    return ['bot' => null, 'chat' => null, 'error' => $r['code'] === 401 || $r['code'] === 404
      ? 'Telegram не принял токен — бот удалён или токен заменён. Возьмите новый токен у @BotFather.'
      : 'Telegram: ' . $r['error']];
  }
  $out = ['bot' => ['name' => $cut($r['result']['first_name'] ?? ''), 'username' => (string)($r['result']['username'] ?? '')], 'chat' => null];
  if ($t['tg_chat'] === '') {
    return $out;
  }
  $c = tgCall($t, 'getChat', ['chat_id' => $t['tg_chat']]);
  if (!$c['ok']) {
    // 400 «chat not found» — боту в этот чат не писали или ID с ошибкой; 403 — бота оттуда убрали или заблокировали
    $out['chat_error'] = $c['code'] === 400 ? 'бот не видит этот чат — проверьте ID или напишите боту любое сообщение'
      : ($c['code'] === 403 ? 'бота убрали из этого чата или заблокировали' : $c['error']);
    return $out;
  }
  $c = $c['result'];
  $out['chat'] = ['name' => $cut($c['title'] ?? trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))),
    'type' => (string)($c['type'] ?? ''), 'username' => (string)($c['username'] ?? '')];
  return $out;
}

// Местный DNS может отдавать для api.telegram.org адрес, который через туннель не работает: FakeIP podkop
// (198.18.0.0/15) или заглушку. Тогда настоящий адрес спрашиваем у защищённого DNS; запасной — постоянный адрес Telegram
function telegramRealIp(): ?string
{
  $sys = array_values(array_filter(gethostbynamel('api.telegram.org') ?: [], 'isIp4'));
  if ($sys && !array_filter($sys, fn($ip) => ipInEntry(inet_pton($ip), '198.18.0.0/15') || ipInEntry(inet_pton($ip), '127.0.0.0/8') || ipInEntry(inet_pton($ip), '0.0.0.0/8'))) {
    return null;
  }
  foreach (DIAG_DOH as [, $server, $ip]) {
    $r = dohResolve($server, $ip, 'api.telegram.org');
    if ($r['ok'] && ($real = array_values(array_filter($r['ips'], 'isIp4')))) {
      return $real[0];
    }
  }
  return '149.154.167.220';
}

// Каким путём уходят уведомления — словами, без пароля прокси
function notifyVia(array $t): string
{
  if ($t['via'] === 'iface' && $t['iface'] !== '') {
    return "через интерфейс {$t['iface']}";
  }
  return $t['via'] === 'proxy' && $t['proxy'] !== '' ? 'через прокси ' . preg_replace('#^.*@#', '', preg_replace('#^\w+://#', '', $t['proxy'])) : 'напрямую';
}

function maskProxy(string $p): string
{
  return preg_replace('#://[^@/]+@#', '://••••@', $p);
}
