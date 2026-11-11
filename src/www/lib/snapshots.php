<?php
// Server section: snapshots, copies on a NAS, change history.
// Loaded by api.php (see LIB there), never opened directly.
defined('API_FILE') || exit;

// ================= сохранность: снимки и история изменений =================
//
// Снимок — tar.gz с конфигом, списками, блобами и настройками интерфейса; делается перед каждым
// изменением через интерфейс (если с прошлого снимка что-то поменялось) и раз в сутки по cron.
// История — журнал изменений отдельных файлов с их версиями (сжатыми), включая правки вне интерфейса:
// их находит сканирование по cron и при каждом открытии интерфейса.

function uiSettings(): array
{
  $defaults = [
    // remote — копии снимков на другом устройстве (NAS) по ssh; keep_local — сколько последних оставлять на роутере
    'snapshots' => ['max_count' => 50, 'max_days' => 30, 'remote' => ['enabled' => false, 'host' => '', 'port' => 22, 'user' => '', 'dir' => '', 'keep_local' => 5]],
    'monitor' => ['enabled' => true, 'interval' => 30, 'sites' => ['rutracker.org', 'youtube.com', 'discord.com', 'x.com']],
    // via — каким путём слать в Telegram: '' — как обычно, iface — через интерфейс (туннель), proxy — через прокси
    'notify' => ['tg_token' => '', 'tg_chat' => '', 'via' => '', 'iface' => '', 'proxy' => ''],
    'auto' => ['enabled' => false, 'apply' => false, 'fails' => 2, 'pause' => 12],
    'session' => 604800,   // сколько не выходить из интерфейса без действий, секунды: см. SESSION_LIVES
    'restart_check' => true,   // «Перезапустить» checks the sites and rolls back on failure; off — a plain restart
    'provider' => '',
    'provider_asn' => 0,   // номер AS провайдера: по нему берутся стратегии сообщества
    'seen' => null,        // метки «новое»: см. seenInfo()
    'asn' => [],
    'subs' => [],
    'lint_hide' => [],     // config notes hidden by «don't show»: key => text, see lintMark()
    'dns_own' => [],       // own DNS servers for the DNS page: an address, a DoT name or a DoH link, see dnsOwnParse()
    'dns_only' => false,   // check only the own servers, without the public ones (see dnsOnly())
    // sharing strategies through the relay (lib/share.php): auto — «help the base» daily, name — the signature in
    // the base, id — this installation's random number (never leaves the relay), me — its public hash for the counter
    'share' => ['auto' => false, 'name' => '', 'id' => '', 'me' => ''],
  ];
  $s = json_decode((string)@file_get_contents(UI_SETTINGS), true);
  $s = is_array($s) ? $s : [];
  $r = array_replace_recursive($defaults, $s);
  // списки берём целиком, иначе слияние по индексам вернёт «хвост» значений по умолчанию
  if (isset($s['monitor']['sites'])) {
    $r['monitor']['sites'] = array_values($s['monitor']['sites']);
  }
  if (isset($s['subs'])) {
    $r['subs'] = array_values($s['subs']);
  }
  if (isset($s['asn'])) {
    $r['asn'] = array_values($s['asn']);
  }
  if (isset($s['dns_own'])) {
    $r['dns_own'] = array_values($s['dns_own']);
  }
  return $r;
}

// Метки «новое» у новых функций интерфейса (список функций — FEATURES в js/core/shell.js):
// since — версия, с которой человек начал пользоваться интерфейсом (метки — только у того, что новее),
// open — что из нового уже открыто, told — о какой версии уже показано сообщение «Обновлено до…».
// Хранится на роутере: открыл новое с телефона — метка пропала и на компьютере.
function seenInfo(): array
{
  $s = uiSettings();
  if (!is_array($s['seen'])) {
    // меток ещё не было. Если интерфейсом уже пользовались (эти файлы появляются за первые минуты работы) —
    // это обновление с версии без меток, 1.8.0 или раньше; иначе — новая установка, и новым ничего не считается
    $used = array_filter(['settings.json', 'update.json', 'monitor.json', 'picks.json'], fn($f) => is_file(UI_CONF_DIR . "/$f"));
    $s['seen'] = ['since' => $used ? '1.8.0' : UI_VERSION, 'open' => [], 'told' => $used ? '' : UI_VERSION];
    saveUiSettings($s);
  }
  return $s['seen'] + ['since' => UI_VERSION, 'open' => [], 'told' => ''];
}

// Запись, которая переживает отключение питания: данные сбрасываются на диск до того, как новый файл встанет на место
// старого. Без этого после обрыва на месте файла оказывается пустой или обрезанный (так потерялась история подборов).
function putSynced(string $file, string $data): bool
{
  $h = @fopen($file, 'w');
  if (!$h) {
    return false;
  }
  $ok = fwrite($h, $data) === strlen($data) && fflush($h);
  if ($ok && function_exists('fsync')) {
    @fsync($h);
  }
  fclose($h);
  return $ok;
}

// A temporary file of its own for every write: cron and a page request saving the same file at once would otherwise
// share one, and one could rename the other's half-written copy into place (settings.json — the token, the sites)
function putSafe(string $path, string $data): bool
{
  // what a write cut off by a power loss left behind (older than a minute: a write going on now is not touched)
  foreach (glob("$path.tmp*") ?: [] as $old) {
    if (time() - (int)@filemtime($old) > 60) {
      @unlink($old);
    }
  }
  $tmp = "$path.tmp" . getmypid() . bin2hex(random_bytes(3));
  if (putSynced($tmp, $data) && rename($tmp, $path)) {
    return true;
  }
  @unlink($tmp);
  return false;
}

function saveUiSettings(array $s): void
{
  @mkdir(UI_CONF_DIR, 0755, true);
  putSafe(UI_SETTINGS, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

// Файлы, версии которых ведёт история: конфиг и списки (кроме огромных)
function trackedFiles(): array
{
  $r = [CONF_FILE];
  foreach (glob(LISTS_DIR . '/*.list') as $f) {
    if (is_file($f) && filesize($f) <= 2 * 1048576) {
      $r[] = $f;
    }
  }
  return $r;
}

function snapIndex(): array
{
  $i = json_decode((string)@file_get_contents(SNAP_DIR . '/index.json'), true);
  return is_array($i) ? $i : [];
}

function snapSaveIndex(array $idx): void
{
  putSafe(SNAP_DIR . '/index.json', json_encode(array_values($idx), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function snapFileOf(string $id): string
{
  if (!preg_match('/^\d{8}-\d{6}(-\d+)?$/', $id)) {
    fail('Нет такого снимка', 404);
  }
  return SNAP_DIR . "/$id.tar.gz";
}

function snapHashes(): array
{
  $h = [];
  foreach (array_merge(trackedFiles(), glob(CONF_DIR . '/blobs/*') ?: []) as $f) {
    $h[substr($f, 1)] = md5_file($f);
  }
  ksort($h);
  return $h;
}

function snapPaths(): array
{
  // пути от корня, как на диске: в Entware (Keenetic) — opt/etc/…
  $p = [substr(CONF_FILE, 1), substr(LISTS_DIR, 1), substr(CONF_DIR, 1) . '/blobs'];
  // настройки интерфейса — кроме ключа роутера для NAS: закрытый ключ не должен уходить ни на NAS, ни в скачанный архив
  foreach (glob(UI_CONF_DIR . '/*') ?: [] as $f) {
    if (is_file($f) && !preg_match('/^(remote_key(\.pub)?|known_hosts)$|\.(tmp|bad)$/', basename($f))) {
      $p[] = substr($f, 1);
    }
  }
  return array_values(array_filter($p, fn($x) => file_exists('/' . $x)));
}

// Создаёт снимок. $ifChanged — не создавать, если с прошлого снимка ничего не поменялось
function snapshotCreate(string $reason, bool $ifChanged = true, string $note = ''): ?array
{
  @mkdir(SNAP_DIR, 0700, true);
  $idx = snapIndex();
  $hashes = snapHashes();
  // full — снимок с конфигом и списками; на Keenetic до 1.15.4 в снимки попадали только настройки интерфейса
  if ($ifChanged && $idx && end($idx)['files'] === $hashes && (!ROOT || !empty(end($idx)['full']))) {
    return null;
  }
  $id = ldate('Ymd-His');
  for ($n = 2; is_file(SNAP_DIR . "/$id.tar.gz"); $n++) {
    $id = ldate('Ymd-His') . "-$n";
  }
  $file = SNAP_DIR . "/$id.tar.gz";
  exec('tar -czf ' . escapeshellarg($file) . ' -C / ' . implode(' ', array_map('escapeshellarg', snapPaths())) . ' 2>&1', $out, $rc);
  if ($rc !== 0 || !is_file($file)) {
    @unlink($file);
    return null;
  }
  $entry = ['id' => $id, 'ts' => time(), 'reason' => $reason, 'note' => $note, 'size' => filesize($file), 'files' => $hashes, 'full' => true];
  $lock = snapLock();
  $idx = snapIndex();   // перечитываем под замком: отправка на NAS могла поменять отметки
  $idx[] = $entry;
  snapPrune($idx);
  snapSaveIndex($idx);
  fclose($lock);
  remoteKick();
  return $entry;
}

// Замок на index.json: снимок создаёт запрос интерфейса или cron, а отметки «на NAS» ставит фоновая отправка
function snapLock()
{
  @mkdir(SNAP_DIR, 0700, true);
  $l = fopen(SNAP_DIR . '/.lock', 'c');
  flock($l, LOCK_EX);
  return $l;
}

// Без копий на NAS: хранить по сроку и числу, пять последних и закреплённые — всегда.
// С копиями на NAS: на роутере — keep_local последних, закреплённые и ещё не отправленные; остальные
// остаются в списке как «только на NAS» (там хранится всё — удалить или перезаписать их с роутера нельзя).
function snapPrune(array &$idx): void
{
  $cfg = uiSettings()['snapshots'];
  $remote = remoteOn();
  $minTs = time() - 86400 * max(1, (int)$cfg['max_days']);
  $local = array_values(array_filter($idx, fn($e) => ($e['local'] ?? true)));
  $n = count($local);
  $pos = array_flip(array_column($local, 'id'));
  $keepN = $remote ? max(1, (int)$cfg['remote']['keep_local']) : 5;
  $keep = [];
  foreach ($idx as $e) {
    if (!($e['local'] ?? true)) {
      $keep[] = $e;
      continue;
    }
    $i = $pos[$e['id']];
    $newest = $i >= $n - $keepN;
    if ($newest || !empty($e['pinned'])) {
      $keep[] = $e;
    } elseif ($remote) {
      if (empty($e['remote'])) {
        $keep[] = $e;   // ещё не дошёл до NAS
        continue;
      }
      @unlink(SNAP_DIR . "/{$e['id']}.tar.gz");
      $e['local'] = false;
      unset($e['files']);   // хеши файлов нужны только для «отличается от текущего», а список на NAS растёт без конца
      $keep[] = $e;
    } elseif ($i >= $n - max(5, (int)$cfg['max_count']) && $e['ts'] >= $minTs) {
      $keep[] = $e;
    } else {
      @unlink(SNAP_DIR . "/{$e['id']}.tar.gz");
      if (!empty($e['remote'])) {
        $e['local'] = false;
        unset($e['files']);
        $keep[] = $e;
      }
    }
  }
  $idx = $keep;
}

// ---------- копии снимков на NAS (или любом устройстве с ssh) ----------
// Роутер сам отправляет каждый новый снимок по ssh своим ключом. На той стороне ключ ограничен строкой
// в authorized_keys (command=…,restrict): им можно только положить новый снимок, прочитать и перечислить
// снимки в одной папке — ни оболочки, ни удаления, ни перезаписи. Скрипт той стороны — remoteScript().

define('REMOTE_KEY', UI_CONF_DIR . '/remote_key');
define('REMOTE_STATE', UI_CONF_DIR . '/remote.json');

function remoteCfg(): array
{
  return uiSettings()['snapshots']['remote'];
}

// dropbear (OpenWrt) или OpenSSH (Entware): у них разные ключи и параметры
function remoteTool(): ?string
{
  foreach (['dbclient' => 'dropbear', 'ssh' => 'openssh'] as $bin => $kind) {
    exec('command -v ' . $bin . ' 2>/dev/null', $o, $rc);
    if ($rc === 0) {
      // на OpenWrt ssh — это сам dropbear
      return $kind === 'openssh' && is_link(trim($o[0] ?? '')) && strpos((string)readlink(trim($o[0])), 'dropbear') !== false ? 'dropbear' : $kind;
    }
    $o = [];
  }
  return null;
}

function remoteOn(): bool
{
  $c = remoteCfg();
  return !empty($c['enabled']) && $c['host'] !== '' && $c['user'] !== '' && is_file(REMOTE_KEY);
}

function remotePubKey(): string
{
  if (!is_file(REMOTE_KEY)) {
    return '';
  }
  $cmd = remoteTool() === 'openssh' ? 'cat ' . escapeshellarg(REMOTE_KEY . '.pub') : 'dropbearkey -y -f ' . escapeshellarg(REMOTE_KEY);
  exec($cmd . ' 2>/dev/null', $o);
  foreach ($o as $l) {
    if (preg_match('/^ssh-\S+ \S+/', $l)) {
      return preg_replace('/ \S+$/', '', trim($l)) . ' nfqws2-ui';
    }
  }
  return '';
}

function remoteKeyCreate(): string
{
  @mkdir(UI_CONF_DIR, 0700, true);
  if (!is_file(REMOTE_KEY)) {
    $tool = remoteTool();
    $tool || fail('На роутере нет ssh-клиента (dbclient или ssh)');
    $cmd = $tool === 'openssh' ? 'ssh-keygen -q -t ed25519 -N "" -C nfqws2-ui -f ' . escapeshellarg(REMOTE_KEY) : 'dropbearkey -t ed25519 -f ' . escapeshellarg(REMOTE_KEY);
    exec($cmd . ' 2>&1', $o, $rc);
    ($rc === 0 && is_file(REMOTE_KEY)) || fail('Не удалось создать ключ: ' . implode(' ', $o));
    @chmod(REMOTE_KEY, 0600);
  }
  return remotePubKey();
}

// Without the key nothing can be sent, so sending is switched off too: the page then says so instead of an error
function remoteKeyDelete(): void
{
  foreach ([REMOTE_KEY, REMOTE_KEY . '.pub'] as $f) {
    (!is_file($f) || @unlink($f)) || fail('Не удалось удалить ' . $f);
  }
  $s = uiSettings();
  if (!empty($s['snapshots']['remote']['enabled'])) {
    $s['snapshots']['remote']['enabled'] = false;
    saveUiSettings($s);
  }
}

// Одна команда на той стороне: ping | list | put <имя> | get <имя>. $in — файл на вход, $out — куда писать вывод
function remoteCall(string $op, string $name = '', ?string $in = null, ?string $out = null, int $timeout = 30): array
{
  $c = remoteCfg();
  $tool = remoteTool();
  if (!$tool) {
    return [false, 'на роутере нет ssh-клиента'];
  }
  $target = escapeshellarg($c['user'] . '@' . $c['host']);
  $key = escapeshellarg(REMOTE_KEY);
  $port = (int)$c['port'];
  $remote = escapeshellarg(trim("$op $name"));
  // ключ хоста запоминается при первом подключении (в UI_CONF_DIR/.ssh) и дальше сверяется
  $cmd = $tool === 'openssh'
    ? "ssh -i $key -p $port -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=" . escapeshellarg(UI_CONF_DIR . '/known_hosts') . " -o ConnectTimeout=10 $target $remote"
    : "dbclient -y -i $key -p $port $target $remote";
  $p = proc_open('exec ' . $cmd, [0 => $in ? ['file', $in, 'r'] : ['file', '/dev/null', 'r'], 1 => $out ? ['file', $out, 'w'] : ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['HOME' => UI_CONF_DIR, 'PATH' => JOB_PATH]);
  if (!is_resource($p)) {
    return [false, 'не удалось запустить ssh'];
  }
  $text = '';
  $err = '';
  foreach ($pipes as $pp) {
    stream_set_blocking($pp, false);
  }
  $deadline = time() + $timeout;
  do {
    $st = proc_get_status($p);
    if (isset($pipes[1])) {
      $text .= (string)stream_get_contents($pipes[1]);
    }
    $err .= (string)stream_get_contents($pipes[2]);
    if (!$st['running']) {
      break;
    }
    usleep(100000);
  } while (time() < $deadline);
  if ($st['running']) {
    proc_terminate($p, 9);
    proc_close($p);
    return [false, 'нет ответа за ' . $timeout . ' с'];
  }
  if (isset($pipes[1])) {
    $text .= (string)stream_get_contents($pipes[1]);
  }
  $err .= (string)stream_get_contents($pipes[2]);
  foreach ($pipes as $pp) {
    fclose($pp);
  }
  proc_close($p);
  $rc = $st['exitcode'];
  $err = trim(preg_replace('/^.*(Warning|Host .* is not in the trusted hosts file|Fingerprint|Do you want to continue|Permanently added).*$/mi', '', $err));
  return [$rc === 0, $rc === 0 ? $text : ($err !== '' ? $err : "код $rc")];
}

function remoteState(array $patch = []): array
{
  $s = json_decode((string)@file_get_contents(REMOTE_STATE), true);
  $s = is_array($s) ? $s : [];
  if ($patch) {
    $s = array_merge($s, $patch);
    @file_put_contents(REMOTE_STATE, json_encode($s, JSON_UNESCAPED_UNICODE));
  }
  return $s;
}

// Отправить всё, что ещё не на NAS, и список снимков; после — убрать с роутера лишнее
function remotePush(): array
{
  if (!remoteOn()) {
    return ['sent' => 0, 'error' => 'отправка на NAS выключена'];
  }
  // the folder appears with the first snapshot: a copy turned on before it would get no lock file (flock(false) — fatal)
  @mkdir(SNAP_DIR, 0755, true);
  $l = @fopen(SNAP_DIR . '/.push.lock', 'c');
  if (!$l) {
    return ['sent' => 0, 'error' => 'не удалось открыть ' . SNAP_DIR . '/.push.lock'];
  }
  if (!flock($l, LOCK_EX | LOCK_NB)) {
    return ['sent' => 0, 'error' => null, 'busy' => true];   // уже идёт фоновая отправка
  }
  remoteState(['try' => time()]);
  $sent = 0;
  $error = null;
  foreach (snapIndex() as $e) {
    $f = SNAP_DIR . "/{$e['id']}.tar.gz";
    if (!empty($e['remote']) || !($e['local'] ?? true) || !is_file($f)) {
      continue;
    }
    [$ok, $msg] = remoteCall('put', "{$e['id']}.tar.gz", $f, null, 120);
    if (!$ok && strpos($msg, 'уже есть') === false) {
      $error = trim($msg);
      break;
    }
    $sent++;
    $lock = snapLock();
    $idx = snapIndex();
    foreach ($idx as &$x) {
      if ($x['id'] === $e['id']) {
        $x['remote'] = true;
      }
    }
    unset($x);
    snapSaveIndex($idx);
    fclose($lock);
  }
  if (!$error) {
    $lock = snapLock();
    $idx = snapIndex();
    snapPrune($idx);
    snapSaveIndex($idx);
    fclose($lock);
    [$ok, $msg] = remoteCall('put', 'index.json', SNAP_DIR . '/index.json');
    $error = $ok ? null : 'список снимков: ' . trim($msg);
  }
  remoteState($error ? ['error' => $error, 'error_ts' => time()] : ['ok' => time(), 'error' => null]);
  flock($l, LOCK_UN);
  fclose($l);
  return ['sent' => $sent, 'error' => $error];
}

// Отправка в фоне — запрос интерфейса не ждёт ssh
function remoteKick(): void
{
  if (remoteOn() && getenv('NFQWS_UI_CLI') !== 'push') {
    exec('(env -i PATH=' . JOB_PATH . ' NFQWS_UI_CLI=push php-cgi -q -f ' . escapeshellarg(API_FILE) . ' >/dev/null 2>&1 &)');
  }
}

// Архив снимка на роутере; если он только на NAS — скачать во временную папку
function snapArchive(string $id): string
{
  $file = snapFileOf($id);
  if (is_file($file)) {
    return $file;
  }
  $known = array_filter(snapIndex(), fn($e) => $e['id'] === $id);
  ($known && remoteOn()) || fail('Нет такого снимка', 404);
  $tmp = "/tmp/nfqws-ui-snap-$id.tar.gz";
  if (!is_file($tmp) || !filesize($tmp)) {
    [$ok, $msg] = remoteCall('get', "$id.tar.gz", null, $tmp, 120);
    exec('tar -tzf ' . escapeshellarg($tmp) . ' >/dev/null 2>&1', $o, $rc);
    if (!$ok || $rc !== 0) {
      @unlink($tmp);
      fail('Не удалось взять снимок с NAS: ' . ($ok ? 'архив повреждён' : $msg), 502);
    }
  }
  return $tmp;
}

// Whether a snapshot can be read: a file on the router, or a NAS copy while sending to the NAS is on
function snapAvailable(string $id): bool
{
  if (is_file(snapFileOf($id))) {
    return true;
  }
  return remoteOn() && (bool)array_filter(snapIndex(), fn($e) => $e['id'] === $id && !empty($e['remote']));
}

// Скрипт той стороны: кладётся в домашнюю папку пользователя, вызывается только из authorized_keys
function remoteScript(): string
{
  return <<<'SH'
#!/bin/sh
# nfqws2-ui: приём снимков с роутера. Вызывается только из ~/.ssh/authorized_keys (command=...),
# поэтому ключом роутера нельзя ничего, кроме: положить НОВЫЙ снимок, прочитать и перечислить снимки в одной папке.
dir=$1
set -f
set -- $SSH_ORIGINAL_COMMAND
set +f
op=$1 name=$2
case "$op" in ping|list) ;; put|get)
  case "$name" in
    index.json|[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9].tar.gz) ;;
    [0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9]-[0-9].tar.gz) ;;
    [0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9].tar.gz) ;;
    *) echo "имя не подходит: $name" >&2; exit 2 ;;
  esac ;;
  *) echo "неизвестная команда: $op" >&2; exit 2 ;;
esac
mkdir -p "$dir" || exit 1
umask 077
case "$op" in
  ping) echo ok ;;
  list) cd "$dir" && for f in [0-9]*.tar.gz; do [ -f "$f" ] && echo "$f $(wc -c < "$f")"; done; exit 0 ;;
  get) cat "$dir/$name" ;;
  put)
    if [ "$name" != index.json ] && [ -e "$dir/$name" ]; then cat > /dev/null; echo "уже есть: $name" >&2; exit 3; fi
    cat > "$dir/.$name.part" && mv -f "$dir/.$name.part" "$dir/$name" ;;
esac
SH;
}

// Одна команда для той стороны: положить скрипт и разрешить ключ роутера только для него
function remoteSetupCmd(string $dir, string $pub): string
{
  $line = 'command="sh .nfqws-ui-store.sh ' . $dir . '",restrict ' . $pub;
  return "umask 077 && mkdir -p ~/.ssh && cat > ~/.nfqws-ui-store.sh <<'NFQWSUI'\n" . remoteScript() . "\nNFQWSUI\n"
    . "grep -qF " . escapeshellarg($pub) . " ~/.ssh/authorized_keys 2>/dev/null || echo " . escapeshellarg($line) . " >> ~/.ssh/authorized_keys";
}

// Содержимое одного файла из снимка
function snapReadFile(string $id, string $rel): ?string
{
  $file = snapArchive($id);
  if (!is_file($file) || !preg_match('#^(opt/)?etc/nfqws2/(nfqws2\.conf|lists/[A-Za-z0-9_.-]+|blobs/[A-Za-z0-9_.-]+)$#', $rel)) {
    return null;
  }
  $out = shell_exec('tar -xzOf ' . escapeshellarg($file) . ' ' . escapeshellarg($rel) . ' 2>/dev/null');
  return $out === null ? null : $out;
}

// Проверяет архив и раскладывает его поверх текущих файлов (файлы, которых нет в архиве, не трогает).
// Пути в архиве — etc/… (OpenWrt) или opt/etc/… (Keenetic); файлы ложатся туда, где они у этой системы,
// так что копию с одного роутера можно восстановить и на другом.
// $nfqOnly — только конфиг, списки и блобы nfqws2, без настроек интерфейса (откат пакета nfqws2);
// $keep — пути файлов (как на роутере), которые не трогать
// Lists and blobs that exist now but are absent from a snapshot (by snapshot paths, as snapHashes keys them).
// Autolists are left out: nfqws2 fills them itself.
function snapExtra(array $snapFiles, ?array $cur = null): array
{
  static $auto = null;
  $dirs = [substr(LISTS_DIR, 1) . '/', substr(CONF_DIR, 1) . '/blobs/'];
  $auto ??= array_map(fn($p) => substr($p, 1), array_filter(array_map(fn($p) => $p['autolist'], currentProfiles())));
  $extra = [];
  // $cur — current files to compare (the snapshot list passes its hashes); by default every *.list and blob on disk,
  // big lists included: restoring compares with the archive's full file list
  $cur ??= array_flip(array_map(fn($f) => substr($f, 1), array_merge(glob(LISTS_DIR . '/*.list') ?: [], glob(CONF_DIR . '/blobs/*') ?: [])));
  foreach (array_keys($cur) as $f) {
    $inDir = array_filter($dirs, fn($d) => str_starts_with($f, $d));
    if ($inDir && !isset($snapFiles[$f]) && !in_array($f, $auto, true)) {
      $extra[] = $f;
    }
  }
  return $extra;
}

// $prune: also delete lists and blobs that the archive does not have, so the result is exactly the snapshot.
// Done only for directories the archive carries; the "before restore" snapshot keeps what is deleted.
function restoreArchive(string $archive, string $reason, bool $nfqOnly = false, array $keep = [], bool $prune = false): array
{
  exec('tar -tzf ' . escapeshellarg($archive) . ' 2>&1', $list, $rc);
  if ($rc !== 0 || !$list) {
    fail('Это не архив tar.gz или он повреждён');
  }
  $allowed = '#^(opt/?|(opt/)?(etc/?|etc/nfqws2/?|etc/nfqws2/(nfqws2\.conf|lists/?|blobs/?|lists/[A-Za-z0-9_.-]+|blobs/[A-Za-z0-9_.-]+)|etc/nfqws-ui/?|etc/nfqws-ui/[A-Za-z0-9_.-]+))$#';
  $conf = false;
  foreach ($list as $e) {
    if (!preg_match($allowed, $e)) {
      fail("В архиве лишний файл: $e — восстановление отменено");
    }
    $conf = $conf || preg_match('#^(opt/)?etc/nfqws2/nfqws2\.conf$#', $e);
  }
  // our snapshots hold plain files and folders only. A link (etc/nfqws2/lists → /etc) would let tar write through it
  // outside the temporary folder while unpacking, and a linked file would be copied into a readable list
  exec('tar -tvzf ' . escapeshellarg($archive) . ' 2>/dev/null', $tv);
  if (preg_grep('/^[lh]/', $tv)) {
    fail('В архиве есть ссылки на другие файлы — восстановление отменено');
  }
  if (!$conf) {
    fail('В архиве нет конфига nfqws2 — только настройки интерфейса. Так собирались копии на Keenetic до версии 1.15.4: конфиг, списки и блобы в них не попадали. Восстановить из такой копии нечего — сделайте новый снимок.');
  }
  historyScan('auto');
  snapshotCreate('перед восстановлением', false, $reason);
  $tmp = '/tmp/nfqws-ui-restore-' . bin2hex(random_bytes(4));
  mkdir($tmp, 0700);
  exec('tar -xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($tmp) . ' 2>&1', $o, $rc);
  if ($rc !== 0) {
    // a half-unpacked archive (/tmp is memory and may run out) would restore some files and report the rest as unchanged
    exec('rm -rf ' . escapeshellarg($tmp));
    fail('Архив не распаковался: ' . (trim(implode(' ', array_slice($o, -3))) ?: "tar $rc") . '. Ничего не изменено.');
  }
  $changed = [];
  foreach ($list as $e) {
    $src = "$tmp/$e";
    if (!is_file($src) || ($nfqOnly && !preg_match('#^(opt/)?etc/nfqws2/#', $e))) {
      continue;
    }
    $dst = ROOT . '/' . preg_replace('#^opt/#', '', $e);
    if (in_array($dst, $keep, true) || (is_file($dst) && md5_file($dst) === md5_file($src))) {
      continue;
    }
    @mkdir(dirname($dst), 0755, true);
    copy($src, $dst);
    $changed[] = $e;
  }
  exec('rm -rf ' . escapeshellarg($tmp));
  $removed = [];
  if ($prune) {
    $have = [];
    foreach ($list as $e) {
      $have[substr(ROOT . '/' . preg_replace('#^opt/#', '', $e), 1)] = 1;
    }
    $carried = array_filter([substr(LISTS_DIR, 1) . '/', substr(CONF_DIR, 1) . '/blobs/'], fn($d) => isset($have[$d])
      || array_filter(array_keys($have), fn($k) => str_starts_with($k, $d)));
    foreach (snapExtra($have) as $f) {
      if (array_filter($carried, fn($d) => str_starts_with($f, $d)) && !in_array("/$f", $keep, true) && @unlink("/$f")) {
        $removed[] = $f;
      }
    }
  }
  historyScan('восстановление', $reason);
  return ['changed' => $changed, 'removed' => $removed];
}

// ---------- история изменений ----------

function histState(): ?array
{
  $s = json_decode((string)@file_get_contents(HIST_DIR . '/state.json'), true);
  return is_array($s) ? $s : null;
}

function histObjPut(string $content): string
{
  $h = md5($content);
  $f = HIST_DIR . "/obj/$h.gz";
  if (!is_file($f)) {
    @mkdir(HIST_DIR . '/obj', 0700, true);
    file_put_contents($f, gzencode($content, 6));
  }
  return $h;
}

function histObjGet(?string $h): ?string
{
  if ($h === null || !preg_match('/^[0-9a-f]{32}$/', $h)) {
    return null;
  }
  $c = @file_get_contents(HIST_DIR . "/obj/$h.gz");
  return $c === false ? null : gzdecode($c);
}

function lineDelta(string $a, string $b): array
{
  $la = array_count_values(array_filter(array_map('trim', explode("\n", $a)), 'strlen'));
  $lb = array_count_values(array_filter(array_map('trim', explode("\n", $b)), 'strlen'));
  $added = [];
  $removed = [];
  foreach ($lb as $l => $n) {
    if (($la[$l] ?? 0) < $n) {
      $added[] = $l;
    }
  }
  foreach ($la as $l => $n) {
    if (($lb[$l] ?? 0) < $n) {
      $removed[] = $l;
    }
  }
  return [$added, $removed];
}

// Сверяет файлы с последним известным состоянием и записывает изменения в журнал.
// $source === 'auto' — изменение сделано вне интерфейса: различаем обновление пакета и всё остальное
function historyScan(string $source, string $note = ''): int
{
  @mkdir(HIST_DIR, 0700, true);
  $state = histState();
  $cur = [];
  foreach (trackedFiles() as $f) {
    $cur[$f] = md5_file($f);
  }
  if ($state === null) {
    // Первый запуск: запоминаем текущие версии как исходные
    foreach ($cur as $f => $h) {
      histObjPut(file_get_contents($f));
    }
    file_put_contents(HIST_DIR . '/state.json', json_encode($cur, JSON_UNESCAPED_SLASHES));
    histAppend(['file' => '*', 'source' => 'интерфейс', 'note' => 'История изменений начата', 'from' => null, 'to' => null]);
    return 0;
  }
  $n = 0;
  foreach (array_unique(array_merge(array_keys($state), array_keys($cur))) as $f) {
    $old = $state[$f] ?? null;
    $new = $cur[$f] ?? null;
    if ($old === $new) {
      continue;
    }
    $newText = $new ? file_get_contents($f) : '';
    if ($new) {
      histObjPut($newText);
    }
    [$added, $removed] = lineDelta((string)histObjGet($old), $newText);
    $src = $source;
    $ts = time();
    if ($source === 'auto') {
      $ts = $new ? filemtime($f) : time();
      $src = 'вне интерфейса';
      foreach (glob(dirname($f) . '/*-opkg') as $o) {
        if (abs(filemtime($o) - $ts) < 30) {
          $src = 'обновление пакета';
          break;
        }
      }
    }
    histAppend(['ts' => $ts, 'file' => basename($f), 'path' => $f, 'source' => $src, 'note' => $note,
      'from' => $old, 'to' => $new, 'added' => array_slice($added, 0, 20), 'removed' => array_slice($removed, 0, 20),
      'n_added' => count($added), 'n_removed' => count($removed)]);
    $n++;
  }
  file_put_contents(HIST_DIR . '/state.json', json_encode($cur, JSON_UNESCAPED_SLASHES));
  return $n;
}

function histAppend(array $e): void
{
  // batch — один запрос к API; undo_of — это событие отменяет другой batch
  $e = ['id' => bin2hex(random_bytes(6)), 'ts' => $e['ts'] ?? time(), 'batch' => REQUEST_BATCH] + $e;
  if (!empty($GLOBALS['UNDO_OF'])) {
    $e['undo_of'] = $GLOBALS['UNDO_OF'];
  }
  file_put_contents(HIST_DIR . '/log.jsonl', json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
}

function histLog(): array
{
  $r = [];
  foreach (@file(HIST_DIR . '/log.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
    $e = json_decode($l, true);
    if (is_array($e)) {
      $r[] = $e;
    }
  }
  return $r;
}

// Запись файла через интерфейс: сначала фиксируем чужие правки и делаем снимок, потом пишем
function writeWithBackup(string $path, string $content, string $note = ''): bool
{
  $content = normalizeText($content);
  if (is_file($path) && file_get_contents($path) === $content) {
    return true;
  }
  beforeChange($note);
  $tmp = $path . '.tmp-ui';
  if (!putSynced($tmp, $content)) {
    return false;
  }
  if (is_file($path)) {
    // сохраняем владельца/права (auto.list, например, пишет nobody)
    @chmod($tmp, fileperms($path) & 0777);
    @chown($tmp, fileowner($path));
  }
  if (!rename($tmp, $path)) {
    return false;
  }
  historyScan('интерфейс', $note);
  return true;
}

function beforeChange(string $note): void
{
  historyScan('auto');
  // История хранит каждую версию файла, а снимок — точка отката всего набора:
  // при серии быстрых правок достаточно одного снимка перед первой
  $idx = snapIndex();
  if (!$idx || time() - end($idx)['ts'] > 900) {
    snapshotCreate('перед изменением', true, $note);
  }
}

// Версии файла для диалога «История»: из журнала, шаблоны пакета и ручные .bak
function historyOf(string $path): array
{
  $base = basename($path);
  $dir = dirname($path);
  $r = [];
  foreach (array_reverse(histLog()) as $e) {
    if (($e['path'] ?? null) === $path && $e['to']) {
      $r[] = ['id' => 'h:' . $e['to'], 'kind' => 'history', 'name' => $e['note'] ?: $e['source'], 'source' => $e['source'], 'mtime' => $e['ts'], 'size' => null];
    }
  }
  foreach (scandir($dir) as $f) {
    $p = "$dir/$f";
    if (!is_file($p) || $f === $base) {
      continue;
    }
    if (in_array($f, [$base . '-opkg', $base . '-old', $base . '.apk-new'], true)) {
      $kind = 'template';
    } elseif (preg_match('/^' . preg_quote($base, '/') . '\.(bak[\w.-]*|working|orig)$/', $f)) {
      $kind = 'bak';
    } else {
      continue;
    }
    $r[] = ['id' => 'file:' . $f, 'kind' => $kind, 'name' => $f, 'mtime' => filemtime($p), 'size' => filesize($p)];
  }
  return $r;
}

function historyContent(string $path, string $id): ?string
{
  if (str_starts_with($id, 'h:')) {
    return histObjGet(substr($id, 2));
  }
  foreach (historyOf($path) as $item) {
    if ($item['id'] === $id) {
      return file_get_contents(dirname($path) . '/' . $item['name']);
    }
  }
  return null;
}

// Новые стандартные версии файлов, которые менеджер пакетов положил рядом с изменёнными вручную
// при обновлении nfqws2: opkg — «имя-opkg», apk — «имя.apk-new». nfqws2 их не читает.
function pkgLeftovers(bool $full = false): array
{
  $r = [];
  foreach ([CONF_DIR => '', LISTS_DIR => 'lists/'] as $dir => $pre) {
    foreach (@scandir($dir) ?: [] as $f) {
      if (!preg_match('/^(.+?)(-opkg|\.apk-new)$/', $f, $m) || !is_file("$dir/$f")) {
        continue;
      }
      $e = ['name' => $pre . $f, 'base' => $m[1], 'mtime' => filemtime("$dir/$f")];
      if ($full) {
        $own = "$dir/{$m[1]}";
        $e['size'] = filesize("$dir/$f");
        $e['own'] = is_file($own);
        $e['same'] = $e['own'] && md5_file($own) === md5_file("$dir/$f");
      }
      $r[] = $e;
    }
  }
  return $r;
}

function pkgLeftoversDelete(array $names): int
{
  $known = array_column(pkgLeftovers(), 'name');
  $n = 0;
  foreach ($names as $name) {
    if (is_string($name) && in_array($name, $known, true) && @unlink(CONF_DIR . '/' . $name)) {
      $n++;
    }
  }
  return $n;
}

// Путь к редактируемому файлу по имени: nfqws2.conf или список
// A list only: deleting, renaming or moving lines must never reach nfqws2.conf, which editablePath also gives
function listPath(string $name): string
{
  $name === 'nfqws2.conf' && fail('Это не список');
  return editablePath($name);
}

function editablePath(string $name): string
{
  if ($name === 'nfqws2.conf') {
    return CONF_FILE;
  }
  if (!preg_match('/^[A-Za-z0-9_.-]+\.list$/', $name)) {
    fail('Недопустимое имя файла');
  }
  return LISTS_DIR . '/' . $name;
}
