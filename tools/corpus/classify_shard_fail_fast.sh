#!/usr/bin/env bash

set -euo pipefail

report=${1:?Usage: classify_shard_fail_fast.sh REPORT SOURCE_TREE UPSTREAM OUTPUT STOP_FILE [CORPUS_ROOT]}
source_tree=${2:?Missing source tree}
upstream=${3:?Missing Gacrux checkout}
output=${4:?Missing classification output}
stop_file=${5:?Missing persistent stop file}
corpus_root=${6:-}

test -s "$report" || { echo "Missing shard report: $report" >&2; exit 2; }
test -f "$source_tree/tools/oracle/gacrux_quality_classifier.py" || {
    echo "Missing classifier in source tree: $source_tree" >&2
    exit 2
}
test -d "$upstream" || { echo "Missing Gacrux checkout: $upstream" >&2; exit 2; }

mkdir -p -- "$(dirname -- "$output")" "$(dirname -- "$stop_file")"
temporary_output=$(mktemp "$(dirname -- "$output")/.classification.XXXXXX")
temporary_stop=$(mktemp "$(dirname -- "$stop_file")/.stop.XXXXXX")
cleanup() {
    rm -f -- "$temporary_output" "$temporary_stop"
}
trap cleanup EXIT

read -r required fatal evaluated < <(php -r '
    $report = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    $counts = $report["counts"] ?? [];
    $required = (int) ($counts["largeField.candidateDeviation"] ?? 0)
        + (int) ($counts["largeField.candidateColourVariant"] ?? 0);
    $fatal = (int) ($counts["largeField.candidateNoMatching"] ?? 0)
        + (int) ($counts["roundsEngineNoSolution"] ?? 0)
        + (int) ($counts["roundsEngineResourceLimit"] ?? 0);
    $evaluated = min(
        (int) ($counts["filesSeen"] ?? 0),
        (int) ($counts["decoded"] ?? 0),
        (int) ($counts["roundsAttempted"] ?? 0),
    );
    printf("%d %d %d\n", $required, $fatal, $evaluated);
' "$report")

stop() {
    local reason=$1
    local exit_code=$2
    printf 'OPENPAIRING_SHARD_STOP=FAIL reason=%s report=%s at=%s\n' \
        "$reason" "$report" "$(date -Is)" >"$temporary_stop"
    mv -f -- "$temporary_stop" "$stop_file"
    echo "Shard gate stopped the campaign: $reason ($report)" >&2
    exit "$exit_code"
}

if (( evaluated < 1 )); then
    stop "zero-evaluated-cases" 19
fi
if (( fatal > 0 )); then
    stop "engine-fatal-count-$fatal" 20
fi

if (( required == 0 )); then
    php -r '
        echo json_encode([
            "schema" => "openpairing.gacrux-quality-classification/2",
            "sampleSet" => "large-field",
            "sourceReport" => realpath($argv[1]),
            "counts" => [],
            "results" => [],
            "classified" => 0,
            "sentinel" => "PASS_NO_ORACLE_CASES",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    ' "$report" >"$temporary_output"
    mv -f -- "$temporary_output" "$output"
    printf 'OPENPAIRING_SHARD_CLASSIFICATION=PASS classified=0 report=%s\n' "$report"
    exit 0
fi

classifier=(
    "$source_tree/tools/oracle/gacrux_quality_classifier.py"
    --upstream "$upstream"
    --report "$report"
    --output "$temporary_output"
    --sample-set large-field
)
if [[ -n "$corpus_root" ]]; then
    classifier+=(--root "$corpus_root")
fi

if ! "$upstream/venv/bin/python" "${classifier[@]}"; then
    stop "classifier-infrastructure-error" 21
fi

read -r classified inferior infrastructure < <(php -r '
    $result = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    $counts = $result["counts"] ?? [];
    printf("%d %d %d\n",
        count($result["results"] ?? []),
        (int) ($counts["inferior-to-checker-optimum"] ?? 0),
        (int) ($counts["infrastructure-error"] ?? 0),
    );
' "$temporary_output")

if (( classified != required )); then
    stop "classification-count-${classified}-expected-${required}" 22
fi
if (( infrastructure > 0 )); then
    mv -f -- "$temporary_output" "$output"
    stop "oracle-infrastructure-errors-$infrastructure" 23
fi
if (( inferior > 0 )); then
    mv -f -- "$temporary_output" "$output"
    stop "inferior-to-checker-optimum-$inferior" 24
fi

mv -f -- "$temporary_output" "$output"
printf 'OPENPAIRING_SHARD_CLASSIFICATION=PASS classified=%d report=%s\n' "$classified" "$report"
