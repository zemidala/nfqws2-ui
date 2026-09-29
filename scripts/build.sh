#!/bin/sh
# Сборка пакета nfqws2-ui для OpenWrt (.ipk) и архива для ручной установки.
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

# --- файлы пакета с подставленной версией ---
D=$STAGE/data
mkdir -p "$D/www/nfqws-ui" "$D/usr/sbin"
cp src/www/index.html src/www/app.js src/www/app.css src/www/api.php src/www/icon.svg src/www/manifest.json "$D/www/nfqws-ui/"
cp src/bin/nfqws-ui-setup src/bin/nfqws-ui "$D/usr/sbin/"
sed -i "s/?v=[0-9A-Za-z.-]*\"/?v=$VERSION\"/g" "$D/www/nfqws-ui/index.html"
sed -i "s/^const UI_VERSION = '[^']*';/const UI_VERSION = '$VERSION';/" "$D/www/nfqws-ui/api.php"
sed -i "s/^VERSION=.*/VERSION=$VERSION/" "$D/usr/sbin/nfqws-ui-setup"
grep -q "UI_VERSION = '$VERSION'" "$D/www/nfqws-ui/api.php"

# Права задаются явно: на Windows у файлов нет битов исполнения
( cd "$D" && $TAR --mode='u=rwX,go=rX' --exclude=./usr/sbin/nfqws-ui-setup --exclude=./usr/sbin/nfqws-ui -cf "$STAGE/data.tar" . \
  && $TAR --mode=0755 -rf "$STAGE/data.tar" ./usr/sbin/nfqws-ui-setup ./usr/sbin/nfqws-ui )
gzip -9n "$STAGE/data.tar"

# --- control ---
C=$STAGE/control
mkdir -p "$C"
SIZE=$(du -sk "$D" | cut -f1)
sed "s/@VERSION@/$VERSION/; s/@SIZE@/$((SIZE * 1024))/" packaging/openwrt/control > "$C/control"
cp packaging/openwrt/postinst packaging/openwrt/prerm packaging/openwrt/postrm "$C/"
( cd "$C" && $TAR --mode='u=rwX,go=rX' --exclude=./postinst --exclude=./prerm --exclude=./postrm -cf "$STAGE/control.tar" . \
  && $TAR --mode=0755 -rf "$STAGE/control.tar" ./postinst ./prerm ./postrm )
gzip -9n "$STAGE/control.tar"

echo 2.0 > "$STAGE/debian-binary"
IPK=nfqws2-ui_${VERSION}-1_all.ipk
( cd "$STAGE" && $TAR --mode=0644 -czf - ./debian-binary ./control.tar.gz ./data.tar.gz ) > "$OUT/$IPK"

# Архив файлов — для ручной установки и для систем с apk (OpenWrt 25+)
( cd "$D" && $TAR --mode='u=rwX,go=rX' -czf - . ) > "$OUT/nfqws2-ui_${VERSION}.tar.gz"

sed "s/^VERSION=.*/VERSION=v$VERSION/" install.sh > "$OUT/install.sh"

( cd "$OUT" && sha256sum *.ipk *.tar.gz install.sh > sha256sums.txt )
ls -l "$OUT"
