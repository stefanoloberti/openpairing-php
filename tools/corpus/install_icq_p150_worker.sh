#!/usr/bin/env bash

set -Eeuo pipefail

source_runner=${1:?Usage: install_icq_p150_worker.sh RUNNER UNIT}
source_unit=${2:?Missing systemd unit}
base=/srv/openpairing-icq-worker
bin="$base/bin"
runner="$bin/run_icq_p150_worker.sh"
unit=/etc/systemd/system/openpairing-cross-p150-icq.service

test "$(id -u)" -eq 0 || { echo 'Installer must run as root.' >&2; exit 2; }
test -s "$source_runner"
test -s "$source_unit"
test -d "$base/releases/86ec9d9-r2"
test "$(find "$base/campaigns/p150/shards" -mindepth 1 -maxdepth 1 -type d -name 's1??' | wc -l)" -eq 16
for shard in $(seq 112 127); do
    test -d "$base/campaigns/p150/shards/$(printf 's%03d' "$shard")"
done

install -d -o stefano -g stefano -m 0755 "$bin" "$base/work"
temporary_runner=$(mktemp "$bin/.run_icq_p150_worker.XXXXXX")
temporary_unit=$(mktemp /etc/systemd/system/.openpairing-cross-p150-icq.XXXXXX)
cleanup() { rm -f -- "$temporary_runner" "$temporary_unit"; }
trap cleanup EXIT
install -o stefano -g stefano -m 0755 "$source_runner" "$temporary_runner"
install -o root -g root -m 0644 "$source_unit" "$temporary_unit"
bash -n "$temporary_runner"
grep -Fxq 'ExecStart=/srv/openpairing-icq-worker/bin/run_icq_p150_worker.sh' "$temporary_unit"
mv -f -- "$temporary_runner" "$runner"
mv -f -- "$temporary_unit" "$unit"
trap - EXIT
systemctl daemon-reload
systemctl enable openpairing-cross-p150-icq.service
printf 'OPENPAIRING_ICQ_INSTALL=PASS runner_sha256=%s unit_sha256=%s\n' \
    "$(sha256sum "$runner" | awk '{print $1}')" "$(sha256sum "$unit" | awk '{print $1}')"
