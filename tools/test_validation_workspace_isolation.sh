#!/usr/bin/env bash

set -euo pipefail

archive=${1:?source archive path is required}
vendor_source=${2:?vendor source directory is required}
workspace=/tmp/openpairing-validation/isolation-negative-test
rm -rf "$workspace"
trap 'rm -rf /tmp/openpairing-validation/isolation-negative-test' EXIT

bash "$(dirname "$0")/prepare_validation_workspace.sh" "$archive" "$workspace" "$vendor_source" >/dev/null
release=$(readlink -f "$workspace/current")
test -d "$release"
test ! -L "$release"
test -L "$workspace/current"
before=$(php "$release/tools/verify_runtime_identity.php" | sha256sum | cut -d' ' -f1)

cp -aL "$workspace/current" "$workspace/laboratory"
test ! -L "$workspace/laboratory"
printf '\n// isolation-negative-test\n' >>"$workspace/laboratory/src/Version.php"

after=$(php "$release/tools/verify_runtime_identity.php" | sha256sum | cut -d' ' -f1)
test "$before" = "$after"
if php "$workspace/laboratory/tools/verify_runtime_identity.php" >/dev/null 2>&1; then
  echo 'Laboratory mutation unexpectedly retained the old identity lock.' >&2
  exit 3
fi

printf 'WORKSPACE_ISOLATION_SENTINEL=PASS release=%s\n' "$release"
