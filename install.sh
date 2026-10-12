#!/bin/sh
# Установщик nfqws2-ui для OpenWrt и Keenetic (Entware).
#   wget -qO- https://github.com/zemidala/nfqws2-ui/releases/latest/download/install.sh | sh
# Конкретная версия:  ... | sh -s -- v1.0.0
# https://github.com/zemidala/nfqws2-ui
set -e

REPO=zemidala/nfqws2-ui
VERSION=latest
[ -n "$1" ] && VERSION=$1

say() { printf '%s\n' "$*"; }
die() { printf '\nОшибка: %s\n' "$*" >&2; exit 1; }

fetch() { # url [файл]
	if command -v wget >/dev/null 2>&1; then
		wget -q -O "${2:--}" "$1"
	else
		curl -fsSL -o "${2:--}" "$1"
	fi
}

# opkg с ограничением времени: wget внутри opkg ждёт недоступный источник бесконечно, а следующий запуск
# упирается в «Could not lock opkg.lock». $1 — секунд, $2 — файл для вывода («-» — на экран), дальше — аргументы
# opkg. Код 124 — не дождались
opkg_limited() {
	s=$1 log=$2
	shift 2
	if [ "$log" = - ]; then opkg "$@" & else opkg "$@" >"$log" 2>&1 & fi
	p=$! i=0
	while kill -0 $p 2>/dev/null; do
		if [ $i -ge "$s" ]; then
			kill_tree $p
			wait $p 2>/dev/null || :   # set -e: код убитого процесса не должен завершить установщик
			return 124
		fi
		sleep 1
		i=$((i + 1))
	done
	wait $p
}

# Процесс и всех его потомков (opkg запускает wget, который иначе остался бы висеть). Потомков — по номеру
# родителя в /proc: сначала список, потом kill, иначе осиротевшие перейдут к init
kill_tree() {
	kids=$(awk -v p="$1" '$4 == p { print $1 }' /proc/[0-9]*/stat 2>/dev/null)
	kill "$1" 2>/dev/null
	for c in $kids; do kill_tree "$c"; done
}

# Источники пакетов, которые не отвечают
dead_feeds() {
	cat $R/etc/opkg.conf $R/etc/opkg/*.conf 2>/dev/null | awk '$1 ~ /^src/ { print $3 }' | sort -u | while read -r u; do
		wget -q -T 10 -O /dev/null "$u/Packages.gz" 2>/dev/null || say "  $u"
	done
}

feed_hint() {
	d=$(dead_feeds)
	[ -n "$d" ] && say "Не отвечают источники пакетов:" && say "$d"
	if [ -n "$R" ] && cat $R/etc/opkg.conf $R/etc/opkg/*.conf 2>/dev/null | grep -q '^src.*bin\.entware\.net'; then
		say "Сервер Entware (bin.entware.net) бывает недоступен. Можно перейти на зеркало и повторить установку:"
		say "  sed -i 's#http://bin.entware.net/#http://mirrors.bfsu.edu.cn/entware/#' $R/etc/opkg.conf"
	fi
	[ -n "$d" ] && say "Ненужный источник можно убрать из ${R:-}/etc/opkg/ или /etc/opkg/customfeeds.conf."
	return 0
}

[ "$(id -u)" = 0 ] || die "запускайте от root"

# --- где запускаемся ---
# R — корень пакетов: «/» на OpenWrt, «/opt» в Entware (Keenetic)
if [ -f /etc/openwrt_release ]; then
	R=
	. /etc/openwrt_release
	say "Система: ${DISTRIB_DESCRIPTION:-OpenWrt} (${DISTRIB_ARCH:-?})"
elif [ -x /opt/bin/opkg ]; then
	R=/opt
	PATH=/opt/sbin:/opt/bin:/opt/usr/sbin:/opt/usr/bin:/usr/sbin:/usr/bin:/sbin:/bin
	export PATH
	# uname на mipsel отвечает «mips» — архитектуру пакетов знает opkg (строка с наибольшим приоритетом)
	ARCH=$(opkg print-architecture 2>/dev/null | awk '$1 == "arch" && $2 != "all" && $2 != "noarch" && $3 + 0 >= p { p = $3 + 0; a = $2 } END { print a }')
	say "Система: Keenetic / Entware (${ARCH:-$(uname -m)})"
else
	die "нужен OpenWrt или Keenetic с Entware (не найдены /etc/openwrt_release и /opt/bin/opkg)"
fi

if [ ! -x $R/usr/bin/nfqws2 ] || [ ! -f $R/etc/nfqws2/nfqws2.conf ]; then
	die "nfqws2 не найден. Сначала установите nfqws2-keenetic: https://github.com/nfqws/nfqws2-keenetic"
fi

if [ -n "$R" ]; then
	PM=opkg
elif command -v opkg >/dev/null 2>&1; then
	PM=opkg
elif command -v apk >/dev/null 2>&1; then
	PM=apk
	say "Внимание: OpenWrt с apk (25.x) ещё не проверялся, установка без пакета — файлами."
else
	die "не найден менеджер пакетов opkg или apk"
fi

# --- версия и файлы ---
if [ "$VERSION" = latest ]; then
	VERSION=$(fetch "https://api.github.com/repos/$REPO/releases/latest" | sed -n 's/.*"tag_name" *: *"\([^"]*\)".*/\1/p' | head -n1)
	[ -n "$VERSION" ] || die "не удалось узнать последнюю версию (нет доступа к api.github.com?). Укажите версию: sh -s -- v1.0.0"
fi
case "$VERSION" in v*) ;; *) VERSION=v$VERSION ;; esac
V=${VERSION#v}
BASE="https://github.com/$REPO/releases/download/$VERSION"
if [ -n "$R" ]; then
	FILE=nfqws2-ui_${V}-1_all_entware.ipk
elif [ "$PM" = opkg ]; then
	FILE=nfqws2-ui_${V}-1_all.ipk
else
	FILE=nfqws2-ui_${V}.tar.gz
fi

TMP=/tmp/nfqws2-ui-install.$$
trap 'rm -rf "$TMP"' EXIT INT TERM
mkdir -p "$TMP"
say "Скачиваю nfqws2-ui $VERSION…"
fetch "$BASE/$FILE" "$TMP/$FILE" || {
	[ -n "$R" ] && die "не удалось скачать $BASE/$FILE (пакет для Keenetic есть начиная с версии 1.4.0)"
	die "не удалось скачать $BASE/$FILE"
}
fetch "$BASE/sha256sums.txt" "$TMP/sha256sums.txt" || die "не удалось скачать sha256sums.txt"
( cd "$TMP" && grep " $FILE\$" sha256sums.txt > sum.txt && sha256sum -c sum.txt >/dev/null 2>&1 ) || die "контрольная сумма $FILE не совпала — файл повреждён или подменён"

# --- установка ---
if [ "$PM" = opkg ]; then
	# Другой opkg (часто — повисший от прошлой попытки) держит блокировку: наш упал бы с «Could not lock opkg.lock»
	if busy=$(ps w 2>/dev/null | grep -E '[o]pkg .*(install|update|upgrade|remove)|[w]get.*opkg'); then
		say "$busy"
		die "уже работает другой opkg (выше). Дождитесь, пока он закончит, или остановите его: kill <число из первой колонки> — и запустите установку снова"
	fi
	say "Обновляю список пакетов…"
	# Один недоступный источник (часто — от удалённого пакета) даёт ошибку всего opkg update, хотя нужные списки
	# скачались. Не останавливаемся: если зависимостей действительно не найти, остановит opkg install ниже.
	rc=0
	opkg_limited 120 "$TMP/opkg-update.log" update || rc=$?   # set -e: ненулевой код разбираем сами
	case $rc in
	0) ;;
	124)
		say "Внимание: список пакетов не обновился за 2 минуты — продолжаю с тем, что уже скачано."
		feed_hint ;;
	*)
		say "Внимание: часть источников пакетов недоступна — продолжаю с тем, что скачалось:"
		grep -o 'https\{0,1\}://[^ ,]*Packages\.gz' "$TMP/opkg-update.log" | sort -u | sed 's/^/  /'
		say "  (ненужный источник можно убрать из ${R:-}/etc/opkg/ или /etc/opkg/customfeeds.conf)" ;;
	esac
	say "Ставлю nfqws2-ui и зависимости (lighttpd, php8)…"
	# NFQWS_UI_FORCE=1 (nfqws-ui update --force) — поставить заново ту же версию
	rc=0
	opkg_limited 600 - install ${NFQWS_UI_FORCE:+--force-reinstall} "$TMP/$FILE" || rc=$?
	case $rc in
	0) ;;
	124)
		feed_hint
		die "установка не закончилась за 10 минут и остановлена — скорее всего, не отвечает источник пакетов (см. выше)" ;;
	*) die "opkg install не прошёл (см. выше)" ;;
	esac
else
	apk update >/dev/null || die "apk update не прошёл"
	apk add lighttpd lighttpd-mod-cgi lighttpd-mod-rewrite lighttpd-mod-setenv php8-cgi php8-mod-session php8-mod-curl curl || die "не удалось поставить зависимости"
	tar -xzf "$TMP/$FILE" -C /
	chmod 755 /usr/sbin/nfqws-ui-setup /usr/sbin/nfqws-ui
	mkdir -p /etc/nfqws-ui && chmod 700 /etc/nfqws-ui
	touch /etc/nfqws-ui/.manual
	/usr/sbin/nfqws-ui-setup apply
	say ""
	say "nfqws2-ui установлен. Вход — логин root и его пароль. Удаление: nfqws-ui-setup uninstall"
fi
