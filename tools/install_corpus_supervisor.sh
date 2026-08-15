#!/usr/bin/env bash

set -euo pipefail

base=${1:-/srv/openpairing-validation}
service_name=${2:-openpairing-full-corpus}
bin="$base/bin"
runner="$bin/$service_name.sh"
unit="/etc/systemd/system/$service_name.service"

case "$base" in
    /srv/openpairing-validation|/srv/openpairing-validation/*) ;;
    *) echo "Unsafe campaign base: $base" >&2; exit 2 ;;
esac
[[ "$service_name" =~ ^openpairing-[a-z0-9-]+$ ]] || {
    echo "Unsafe service name: $service_name" >&2
    exit 2
}
test -d "$bin"

if (( $# > 2 )); then
    stages=("${@:3}")
else
    stages=(
        openpairing-campaign-p30.sh
        openpairing-classify-p30.sh
        openpairing-campaign-p50.sh
        openpairing-classify-p50.sh
        openpairing-campaign-p80.sh
        openpairing-classify-p80.sh
        openpairing-campaign-accel.sh
        openpairing-classify-accel.sh
    )
fi
(( ${#stages[@]} > 0 )) || { echo "At least one campaign stage is required" >&2; exit 2; }
for stage in "${stages[@]}"; do
    [[ "$stage" =~ ^openpairing-[a-z0-9-]+\.sh$ ]] || {
        echo "Unsafe campaign stage: $stage" >&2
        exit 2
    }
    test -x "$bin/$stage" || {
        echo "Missing executable campaign stage: $bin/$stage" >&2
        exit 3
    }
done

temporary_runner=$(mktemp "$bin/.${service_name}.XXXXXX")
temporary_unit=$(mktemp "/etc/systemd/system/.${service_name}.XXXXXX")
cleanup() {
    rm -f -- "$temporary_runner" "$temporary_unit"
}
trap cleanup EXIT

{
    echo '#!/usr/bin/env bash'
    echo 'set -euo pipefail'
    printf 'base=%q\n' "$bin"
    echo 'for stage in \'
    for index in "${!stages[@]}"; do
        suffix=' \'
        [[ "$index" -eq $((${#stages[@]} - 1)) ]] && suffix='; do'
        printf '  %q%s\n' "${stages[$index]}" "$suffix"
    done
    cat <<'EOF'
  printf 'OPENPAIRING_STAGE_START=%s at=%s\n' "$stage" "$(date -Is)"
  "$base/$stage"
  printf 'OPENPAIRING_STAGE_PASS=%s at=%s\n' "$stage" "$(date -Is)"
done
printf 'OPENPAIRING_FULL_CORPUS_SENTINEL=PASS at=%s\n' "$(date -Is)"
EOF
} >"$temporary_runner"
chmod 0755 "$temporary_runner"
mv -f -- "$temporary_runner" "$runner"

cat >"$temporary_unit" <<EOF
[Unit]
Description=OpenPairing fail-closed persistent full-corpus campaign
After=network.target

[Service]
Type=simple
ExecStart=$runner
Restart=no
TimeoutStartSec=infinity

[Install]
WantedBy=multi-user.target
EOF
mv -f -- "$temporary_unit" "$unit"

systemctl daemon-reload
systemctl enable "$service_name.service"
systemctl restart --no-block "$service_name.service"
systemctl show "$service_name.service" -p ActiveState -p SubState -p Result --no-pager
printf 'CORPUS_SUPERVISOR_INSTALL_SENTINEL=PASS service=%s base=%s\n' "$service_name" "$base"
