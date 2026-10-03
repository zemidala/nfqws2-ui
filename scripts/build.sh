#!/bin/sh
# Сборка пакетов nfqws2-ui: .ipk для OpenWrt, .ipk для Entware (Keenetic) и архив для ручной установки.
# Результат — в dist/. Нужны GNU tar и gzip (Linux, WSL, Git Bash).
set -e
cd "$(dirname "$0")/.."
VERSION=${1:-$(cat VERSION)}
VERSION=${VERSION#v}
OUT=dist
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
export SOURCE_DATE_EPOCH=${SOURCE_DATE_EPOCH:-$(git log -1 --format=%ct 2>/dev/null || date +%s)}
TAR="tar --owner=0 --group=0 --numeric-owner --sort=name --mtime=@$SOURCE_DATE_EPOCH"

rm -rf "$OUT"; mkdir -p "$OUT"

# ?v= — версия и хеш файлов: браузер не возьмёт старые app.js/app.css из кеша даже при той же версии
HASH=$(cat src/www/app.js src/www/app.css | sha256sum | cut -c1-8)

# pack <система> <каталог интерфейса> <каталог программ> <имя .ipk>
# Собирает $STAGE/<система>/data с подставленной версией и пакет из него
pack() {
	S=$STAGE/$1; D=$S/data; W=$D$2; B=$D$3
	mkdir -p "$W/docs" "$B"
	cp README.md CHANGELOG.md "$W/docs/"
	cp src/www/index.html src/www/app.js src/www/app.css src/www/api.php src/www/icon.svg src/www/manifest.json "$W/"
	cp src/bin/nfqws-ui-setup src/bin/nfqws-ui "$B/"
	sed -i "s/?v=[0-9A-Za-z.-]*\"/?v=$VERSION-$HASH\"/g" "$W/index.html"
	sed -i "s/^const UI_VERSION = '[^']*';/const UI_VERSION = '$VERSION';/" "$W/api.php"
	sed -i "s/^VERSION=.*/VERSION=$VERSION/" "$B/nfqws-ui-setup"
	grep -q "UI_VERSION = '$VERSION'" "$W/api.php"

	# Права задаются явно: на Windows у файлов нет битов исполнения
	( cd "$D" && $TAR --mode='u=rwX,go=rX' --exclude=".$3/nfqws-ui-setup" --exclude=".$3/nfqws-ui" -cf "$S/data.tar" . \
	  && $TAR --mode=0755 -rf "$S/data.tar" ".$3/nfqws-ui-setup" ".$3/nfqws-ui" )
	gzip -9n "$S/data.tar"

	C=$S/control
	mkdir -p "$C"
	SIZE=$(du -sk "$D" | cut -f1)
	sed "s/@VERSION@/$VERSION/; s/@SIZE@/$((SIZE * 1024))/" "packaging/$1/control" > "$C/control"
	cp "packaging/$1/postinst" "packaging/$1/prerm" "packaging/$1/postrm" "$C/"
	( cd "$C" && $TAR --mode='u=rwX,go=rX' --exclude=./postinst --exclude=./prerm --exclude=./postrm -cf "$S/control.tar" . \
	  && $TAR --mode=0755 -rf "$S/control.tar" ./postinst ./prerm ./postrm )
	gzip -9n "$S/control.tar"

	echo 2.0 > "$S/debian-binary"
	( cd "$S" && $TAR --mode=0644 -czf - ./debian-binary ./control.tar.gz ./data.tar.gz ) > "$OUT/$4"
}

pack openwrt /www/nfqws-ui /usr/sbin "nfqws2-ui_${VERSION}-1_all.ipk"
# В Entware всё лежит под /opt
pack entware /opt/share/www/nfqws-ui /opt/sbin "nfqws2-ui_${VERSION}-1_all_entware.ipk"

# Архив файлов — для ручной установки и для систем с apk (OpenWrt 25+)
( cd "$STAGE/openwrt/data" && $TAR --mode='u=rwX,go=rX' -czf - . ) > "$OUT/nfqws2-ui_${VERSION}.tar.gz"

sed "s/^VERSION=.*/VERSION=v$VERSION/" install.sh > "$OUT/install.sh"

( cd "$OUT" && sha256sum *.ipk *.tar.gz install.sh > sha256sums.txt )
ls -l "$OUT"
