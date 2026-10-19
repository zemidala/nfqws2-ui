#!/bin/sh
# Пакет .apk для OpenWrt 25+ (apk-tools 3). Запускать от root там, где есть apk mkpkg (Alpine 3.23+):
# на роутере apk собран без mkpkg. build.sh зовёт этот скрипт сам — напрямую или в контейнере alpine.
#   mkapk.sh <версия> <каталог файлов> <каталог скриптов> <файл .apk>
# mkpkg записывает владельца и права файлов как есть — поэтому сначала копия, отданная root.
set -e
V=$1 SRC=$2 SCR=$3 OUT=$4
W=$(mktemp -d)
trap 'rm -rf "$W"' EXIT
cp -R "$SRC/." "$W/"
chown -R 0:0 "$W"
find "$W" -type d -exec chmod 755 {} +
find "$W" -type f -exec chmod 644 {} +
chmod 755 "$W/usr/sbin/nfqws-ui-setup" "$W/usr/sbin/nfqws-ui"
for s in post-install post-upgrade pre-deinstall post-deinstall; do
	cp "$SCR/$s" "$W.$s"
	chmod 755 "$W.$s"
done
apk mkpkg \
	--info "name:nfqws2-ui" \
	--info "version:$V-r1" \
	--info "description:Web interface for nfqws2 (nfqws2-keenetic): config editor with syntax check and fixes, list manager, strategy tests, change history, snapshots and safe restart with auto-rollback. Russian UI." \
	--info "arch:noarch" \
	--info "license:MIT" \
	--info "origin:nfqws2-ui" \
	--info "url:https://github.com/zemidala/nfqws2-ui" \
	--info "maintainer:zemidala <42742254+zemidala@users.noreply.github.com>" \
	--info "depends:nfqws2-keenetic lighttpd lighttpd-mod-cgi lighttpd-mod-rewrite lighttpd-mod-setenv php8-cgi php8-mod-session php8-mod-curl curl" \
	--script "post-install:$W.post-install" \
	--script "post-upgrade:$W.post-upgrade" \
	--script "pre-deinstall:$W.pre-deinstall" \
	--script "post-deinstall:$W.post-deinstall" \
	--files "$W" \
	--output "$OUT"
rm -f "$W".post-* "$W".pre-*
