#!/usr/bin/env bash

set -Eeuo pipefail

base=/home/gaia/openpairing-worker
php_home="$base/php"
target="$php_home/root"
version=8.3.6-0ubuntu0.24.04.11
packages=(php8.3-common php8.3-opcache php8.3-readline php8.3-cli php8.3-gmp)

test "$(id -un)" = gaia
test ! -e "$target" || { echo "PHP runtime target already exists: $target" >&2; exit 3; }
install -d -m 0755 "$php_home"
temporary=$(mktemp -d "$php_home/.bootstrap.XXXXXX")
cleanup() { rm -rf -- "$temporary"; }
trap cleanup EXIT
install -d -m 0755 "$temporary/root"
cd "$temporary"
for package in "${packages[@]}"; do
    apt-get download "$package=$version"
done
for archive in ./*.deb; do
    dpkg-deb -x "$archive" "$temporary/root"
done

binary="$temporary/root/usr/bin/php8.3"
extension_dir="$temporary/root/usr/lib/php/20230831"
test -x "$binary"
test -s "$extension_dir/gmp.so"
test -s "$extension_dir/opcache.so"
if ldd "$binary" | grep -Fq 'not found'; then
    ldd "$binary" >&2
    echo 'The isolated PHP binary has unresolved shared-library dependencies.' >&2
    exit 4
fi
"$binary" -n \
    -d "extension=$extension_dir/gmp.so" \
    -d "zend_extension=$extension_dir/opcache.so" \
    -r 'exit(PHP_VERSION_ID === 80306 && extension_loaded("gmp") && extension_loaded("Zend OPcache") ? 0 : 1);'

mv -- "$temporary/root" "$target"
trap - EXIT
rm -rf -- "$temporary"
printf 'OPENPAIRING_GAIA_PHP_BOOTSTRAP=PASS version=%s binary_sha256=%s gmp_sha256=%s opcache_sha256=%s\n' \
    "$version" \
    "$(sha256sum "$target/usr/bin/php8.3" | awk '{print $1}')" \
    "$(sha256sum "$target/usr/lib/php/20230831/gmp.so" | awk '{print $1}')" \
    "$(sha256sum "$target/usr/lib/php/20230831/opcache.so" | awk '{print $1}')"
