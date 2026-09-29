#!/bin/sh
# Установщик nfqws2-ui для OpenWrt.
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

[ "$(id -u)" = 0 ] || die "запускайте от root"

# --- где запускаемся ---
if [ ! -f /etc/openwrt_release ]; then
	if [ -d /opt/etc ] && { [ -e /proc/ndm ] || [ -f /opt/etc/entware_release ]; }; then
		die "Keenetic/Entware пока не поддерживается. Для Keenetic есть тема для nfqws-keenetic-web: https://github.com/Twinkle264/Keenetic-NFQWS-Web-Theme"
	fi
	die "нужен OpenWrt (/etc/openwrt_release не найден)"
fi
. /etc/openwrt_release
say "Система: ${DISTRIB_DESCRIPTION:-OpenWrt} (${DISTRIB_ARCH:-?})"

if [ ! -x /usr/bin/nfqws2 ] || [ ! -f /etc/nfqws2/nfqws2.conf ]; then
	die "nfqws2 не найден. Сначала установите nfqws2-keenetic: https://github.com/nfqws/nfqws2-keenetic"
fi

if command -v opkg >/dev/null 2>&1; then
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
if [ "$PM" = opkg ]; then FILE=nfqws2-ui_${V}-1_all.ipk; else FILE=nfqws2-ui_${V}.tar.gz; fi

TMP=/tmp/nfqws2-ui-install.$$
trap 'rm -rf "$TMP"' EXIT INT TERM
mkdir -p "$TMP"
say "Скачиваю nfqws2-ui $VERSION…"
fetch "$BASE/$FILE" "$TMP/$FILE" || die "не удалось скачать $BASE/$FILE"
fetch "$BASE/sha256sums.txt" "$TMP/sha256sums.txt" || die "не удалось скачать sha256sums.txt"
( cd "$TMP" && grep " $FILE\$" sha256sums.txt > sum.txt && sha256sum -c sum.txt >/dev/null 2>&1 ) || die "контрольная сумма $FILE не совпала — файл повреждён или подменён"

# --- установка ---
if [ "$PM" = opkg ]; then
	say "Обновляю список пакетов и ставлю зависимости (lighttpd, php8)…"
	opkg update >/dev/null || die "opkg update не прошёл — проверьте интернет на роутере"
	opkg install "$TMP/$FILE" || die "opkg install не прошёл (см. выше)"
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
