#!/usr/bin/env bash

set -euo pipefail

archive=${1:?archive path is required}
release_name=${2:?release name is required}
base=/srv/chesspairings-production-staging
releases="$base/releases"
current="$base/current"
source_release=$(readlink -f "$current")
target="$releases/$release_name"
engine="$target/engines/openpairing-php"

case "$target" in
    "$releases"/*) ;;
    *) echo "Unsafe release target: $target" >&2; exit 2 ;;
esac

test -f "$archive"
test -d "$source_release"
test ! -e "$target"

mkdir -p "$target"
cp -a "$source_release"/. "$target"/
if test -e "$engine"; then
    case "$engine" in
        "$target"/engines/openpairing-php) ;;
        *) echo "Unsafe inherited engine target: $engine" >&2; exit 2 ;;
    esac
    rm -rf -- "$engine"
fi
mkdir -p "$engine"
tar -xzf "$archive" -C "$engine"

cd "$engine"
identity_temporary=.runtime-identity-${release_name}.tmp
OPENPAIRING_IDENTITY_BOOTSTRAP=1 php tools/verify_runtime_identity.php >"$identity_temporary"
php -r 'json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);' "$identity_temporary"
mv "$identity_temporary" runtime-identity.lock.json
php tools/verify_runtime_identity.php >/dev/null
php vendor/phpunit/phpunit/phpunit --exclude-group oracle
php vendor/phpstan/phpstan/phpstan analyse --memory-limit=512M
php -l bin/openpairing.php
php -l tools/scan_trf_corpus.php
php -l tools/TrfCorpusCompatibility.php
php -l tools/verify_trf_pairings.php
php bin/openpairing.php --help >/dev/null

chown -R www-data:www-data "$engine"
temporary_link="$base/.current-$release_name"
ln -s "$target" "$temporary_link"
mv -Tf "$temporary_link" "$current"

printf '%s\n' "$target"
