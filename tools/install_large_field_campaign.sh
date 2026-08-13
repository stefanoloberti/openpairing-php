#!/usr/bin/env bash

set -euo pipefail

name=${1:?Usage: install_large_field_campaign.sh NAME CORPUS SOURCE_TREE UPSTREAM SHARDS WORKERS}
shard_root=${2:?Missing shard root}
source_tree=${3:?Missing source tree}
upstream=${4:?Missing Gacrux checkout}
shards=${5:?Missing shard count}
workers=${6:?Missing worker count}
base=${OPENPAIRING_CAMPAIGN_BASE:-/srv/openpairing-validation}
bin="$base/bin"
campaign="$base/campaigns/$name"
runner="$bin/openpairing-campaign-$name.sh"

[[ "$name" =~ ^[a-z][a-z0-9-]*$ ]] || { echo "Unsafe campaign name: $name" >&2; exit 2; }
case "$base" in /srv/openpairing-validation|/srv/openpairing-validation/*) ;; *) echo "Unsafe base: $base" >&2; exit 2 ;; esac
[[ "$shards" =~ ^[1-9][0-9]*$ ]] || { echo "Invalid shard count: $shards" >&2; exit 2; }
[[ "$workers" =~ ^[1-9][0-9]*$ ]] || { echo "Invalid worker count: $workers" >&2; exit 2; }
test -d "$shard_root"
test -x "$source_tree/tools/verify_trf_pairings.php"
test -x "$source_tree/tools/corpus/classify_shard_fail_fast.sh"
test -x "$upstream/venv/bin/python"

mkdir -p -- "$bin" "$campaign/reports" "$campaign/classifications" "$campaign/quarantine"
temporary=$(mktemp "$bin/.openpairing-campaign-$name.XXXXXX")
cleanup() { rm -f -- "$temporary"; }
trap cleanup EXIT

cat >"$temporary" <<EOF
#!/usr/bin/env bash
set -euo pipefail
name=$(printf %q "$name")
shard_root=$(printf %q "$shard_root")
source_tree=$(printf %q "$source_tree")
upstream=$(printf %q "$upstream")
campaign=$(printf %q "$campaign")
shards=$shards
workers=$workers
stop_file=\"\$campaign/STOP\"

test ! -e \"\$stop_file\" || { echo \"Campaign is stopped: \$stop_file\" >&2; exit 30; }
mkdir -p -- \"\$campaign/reports\" \"\$campaign/classifications\" \"\$campaign/quarantine\"

run_shard() {
    shard=\$1
    test ! -e \"\$stop_file\" || return 30
    label=\$(printf 's%03d' \"\$shard\")
    corpus=\"\$shard_root/\$label\"
    test -d \"\$corpus\" || return 32
    report=\"\$campaign/reports/\$label.json\"
    classification=\"\$campaign/classifications/\$label.json\"
    if [[ -s \"\$report\" && -s \"\$classification\" ]]; then
        return 0
    fi
    temporary_report=\$(mktemp \"\$campaign/quarantine/.\$label.report.XXXXXX\")
    cleanup_shard() { rm -f -- \"\$temporary_report\"; }
    trap cleanup_shard RETURN
    OPENPAIRING_ARITHMETIC_BACKEND=gmp php -d opcache.enable_cli=1 -d opcache.jit=1255 \\
        -d opcache.jit_buffer_size=128M \"\$source_tree/tools/verify_trf_pairings.php\" \\
        \"\$corpus\" 0 --large-field >\"\$temporary_report\"
    \"\$source_tree/tools/corpus/classify_shard_fail_fast.sh\" \\
        \"\$temporary_report\" \"\$source_tree\" \"\$upstream\" \"\$classification\" \"\$stop_file\" \"\$corpus\" || {
            mv -f -- \"\$temporary_report\" \"\$campaign/quarantine/\$label.json\"
            trap - RETURN
            return 31
        }
    mv -f -- \"\$temporary_report\" \"\$report\"
    trap - RETURN
}
export -f run_shard
export shard_root source_tree upstream campaign shards stop_file
seq 0 \$((shards - 1)) | xargs -r -P \"\$workers\" -n 1 bash -c 'run_shard \"\$1\"' _
test ! -e \"\$stop_file\" || exit 30
printf 'OPENPAIRING_LARGE_FIELD_CAMPAIGN=PASS name=%s shards=%d at=%s\\n' \"\$name\" \"\$shards\" \"\$(date -Is)\"
EOF

chmod 0755 "$temporary"
mv -f -- "$temporary" "$runner"
printf 'LARGE_FIELD_CAMPAIGN_INSTALL_SENTINEL=PASS runner=%s source=%s shards=%s workers=%s\n' \
    "$runner" "$source_tree" "$shards" "$workers"
