<?php
// Server section: common helpers: answers, files, settings, sessions, network.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ================= общее =================

function fail(string $msg, int $code = 400): void
{
  respond(['error' => $msg], $code);
}

function respond(array $data, int $code = 200): void
{
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  // недопустимый UTF-8 (имя из DNS, сообщение системы) раньше давал пустой ответ — теперь заменяется на «�»
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false) {
    error_log('respond: ' . json_last_error_msg());
    http_response_code(500);
    $json = json_encode(['error' => 'Ответ не удалось собрать: ' . json_last_error_msg()], JSON_UNESCAPED_UNICODE);
  }
  echo $json;
  exit();
}

function normalizeText(string $s): string
{
  $s = str_replace(["\r\n", "\r"], "\n", $s);
  $s = preg_replace("/\n{3,}/", "\n\n", $s);
  if ($s !== '' && substr($s, -1) !== "\n") {
    $s .= "\n";
  }
  return $s;
}

// Настройки веб-сервера интерфейса: порты, HTTPS, вход. Пишет nfqws-ui-setup.
function uiWeb(): array
{
  $w = json_decode((string)@file_get_contents(UI_WEB), true);
  return (is_array($w) ? $w : []) + ['port' => null, 'https_port' => null, 'legacy_port' => null, 'auth' => true];
}

// Вход по паролю root включён всегда, если его явно не выключили через nfqws-ui-setup
function authEnabled(): bool
{
  return uiWeb()['auth'] !== false;
}

// Сколько держать вход без действий (выбор в «О программе»)
define('SESSION_LIVES', [3600, 86400, 604800, 2592000, 31536000]);
define('SESS_DIR', '/tmp/nfqws-ui-sess');

function sessionLife(): int
{
  $v = (int)uiSettings()['session'];
  return in_array($v, SESSION_LIVES, true) ? $v : 604800;
}

// Вход только под учётной записью с uid 0 и только с паролем: без пароля интерфейс не пускает.
// Обычно это root; на Asuswrt-Merlin у неё имя администратора роутера (admin или своё)
function authenticate(string $username, string $password): bool
{
  if ($password === '' || !preg_match('/^[A-Za-z0-9._-]{1,32}$/', $username)) {
    return false;
  }
  // На Keenetic это root из Entware (/opt/etc/passwd, /opt/etc/shadow), а не учётная запись прошивки
  $passwd = ROOT . '/etc/passwd';
  $pw = preg_grep('/^' . preg_quote($username, '/') . ':[^:]*:0:/', @file($passwd) ?: []);
  if (!$pw) {
    return false;
  }
  $hash = explode(':', array_pop($pw))[1] ?? '';
  if (strlen($hash) < 13) {
    $shadows = [ROOT . '/etc/shadow'];
    // Merlin: Entware ссылается на системные passwd/shadow, а /opt/etc/shadow может не быть.
    // На Keenetic /opt/etc/passwd — свой файл Entware, туда системный shadow не попадает
    if (ROOT !== '' && is_link($passwd) && @readlink($passwd) === '/etc/passwd') {
      $shadows[] = '/etc/shadow';
    }
    $hash = '';
    foreach ($shadows as $f) {
      $sh = preg_grep('/^' . preg_quote($username, '/') . ':/', @file($f) ?: []);
      if ($sh) {
        $hash = explode(':', array_pop($sh))[1] ?? '';
        break;
      }
    }
  }
  if (strlen($hash) < 13) {
    return false;
  }
  return hash_equals($hash, (string)crypt($password, $hash));
}

// Защита от перебора: не больше 5 неудачных попыток за 10 минут с одного адреса
const LOGIN_FAILS = '/tmp/nfqws-ui-login.json';
function loginBlocked(string $ip, bool $failed = false): bool
{
  $f = json_decode((string)@file_get_contents(LOGIN_FAILS), true) ?: [];
  $now = time();
  $f = array_filter(array_map(fn($l) => array_values(array_filter($l, fn($t) => $t > $now - 600)), $f));
  if ($failed) {
    $f[$ip][] = $now;
    @file_put_contents(LOGIN_FAILS, json_encode($f), LOCK_EX);
  }
  return count($f[$ip] ?? []) >= 5;
}

function isIp(string $s): bool
{
  // В сборке PHP для OpenWrt нет модуля filter
  return @inet_pton($s) !== false;
}

function cleanHost(string $h): string
{
  $h = strtolower(trim($h));
  $h = preg_replace('#^[a-z]+://#', '', $h);
  $h = explode('/', $h)[0];
  $h = preg_replace('/:\d+$/', '', $h);
  $h = preg_replace('/^\*\./', '', $h);
  return rtrim($h, '.');
}

function validHost(string $h): bool
{
  return $h !== '' && (isIp($h) || preg_match('/^(?=.{1,253}$)([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?\.)*[a-z0-9-]{1,63}$/', $h));
}
