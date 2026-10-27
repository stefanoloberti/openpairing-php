#!/usr/bin/env bash

set -euo pipefail

archive=${1:?source archive path is required}
workspace_root=${2:-/tmp/openpairing-validation}
vendor_source=${3:?vendor source directory is required}

case "$workspace_root" in
    /tmp/openpairing-validation|/tmp/openpairing-validation/*) ;;
    *) echo "Unsafe validation workspace: $workspace_root" >&2; exit 2 ;;
esac

archive=$(readlink -f "$archive")
vendor_source=$(readlink -f "$vendor_source")
test -f "$archive"
test -d "$vendor_source/composer"
mkdir -p "$workspace_root/releases"

archive_hash=$(sha256sum "$archive" | cut -d' ' -f1)
release="$workspace_root/releases/${archive_hash:0:16}"
current="$workspace_root/current"
temporary_release="$workspace_root/.release-${archive_hash:0:16}.tmp"
if test ! -d "$release"; then
    test ! -e "$temporary_release"
    mkdir -p "$temporary_release"
    tar -xzf "$archive" -C "$temporary_release"
    # A physical copy is intentional: a vendor symlink preserves Composer's
    # old $baseDir and was the cause of a false historical-replay verdict.
    cp -a "$vendor_source" "$temporary_release/vendor"
    lock="$temporary_release/runtime-identity.lock.json"
    temporary_lock="$temporary_release/.runtime-identity-${archive_hash:0:16}.tmp"
    OPENPAIRING_IDENTITY_BOOTSTRAP=1 php "$temporary_release/tools/verify_runtime_identity.php" >"$temporary_lock"
    php -r 'json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);' "$temporary_lock"
    mv "$temporary_lock" "$lock"
    php "$temporary_release/tools/verify_runtime_identity.php" >/dev/null
    mv "$temporary_release" "$release"
fi

# Releases are physical. Only `current` is a symlink; preserving that symlink
# in a WIP copy would let laboratory writes mutate the immutable release.
test ! -L "$release"

lock="$release/runtime-identity.lock.json"
test -s "$lock"
php "$release/tools/verify_runtime_identity.php" >/dev/null
temporary="$workspace_root/.current-${archive_hash:0:16}"
ln -s "$release" "$temporary"
mv -Tf "$temporary" "$current"
test -L "$current"
test "$(readlink -f "$current")" = "$(readlink -f "$release")"

printf '%s\n' "$release"
