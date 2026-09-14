#!/usr/bin/env bash

set -Eeuo pipefail

source_runner=${1:?Usage: install_gaia_p150_residual_worker.sh RUNNER UNIT}
source_unit=${2:?Missing systemd user unit}
base=/home/gaia/openpairing-worker
bin="$base/bin"
runner="$bin/run_gaia_p150_residual_worker.sh"
unit_dir=/home/gaia/.config/systemd/user
unit="$unit_dir/openpairing-cross-p150-gaia.service"

test "$(id -un)" = gaia
test -s "$source_runner"
test -s "$source_unit"
test -d "$base/releases/86ec9d9-r2"
test -x "$base/php/root/usr/bin/php8.3"
test "$(find "$base/campaigns/p150/shards" -mindepth 1 -maxdepth 1 -type d \( -name 's108' -o -name 's109' -o -name 's110' -o -name 's111' \) | wc -l)" -eq 4
for shard in $(seq 108 111); do
    test -d "$base/campaigns/p150/shards/$(printf 's%03d' "$shard")"
done

install -d -m 0755 "$bin" "$base/work" "$unit_dir"
temporary_runner=$(mktemp "$bin/.run_gaia_p150_residual_worker.XXXXXX")
temporary_unit=$(mktemp "$unit_dir/.openpairing-cross-p150-gaia.XXXXXX")
cleanup() { rm -f -- "$temporary_runner" "$temporary_unit"; }
trap cleanup EXIT
install -m 0755 "$source_runner" "$temporary_runner"
install -m 0644 "$source_unit" "$temporary_unit"
bash -n "$temporary_runner"
grep -Fxq 'ExecStart=/home/gaia/openpairing-worker/bin/run_gaia_p150_residual_worker.sh' "$temporary_unit"
mv -f -- "$temporary_runner" "$runner"
mv -f -- "$temporary_unit" "$unit"
trap - EXIT
systemctl --user daemon-reload
systemctl --user enable openpairing-cross-p150-gaia.service
printf 'OPENPAIRING_GAIA_INSTALL=PASS runner_sha256=%s unit_sha256=%s\n' \
    "$(sha256sum "$runner" | awk '{print $1}')" "$(sha256sum "$unit" | awk '{print $1}')"
