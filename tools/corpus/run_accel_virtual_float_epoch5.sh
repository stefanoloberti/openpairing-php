#!/usr/bin/env bash
set -euo pipefail

name=accel-epoch5-virtual-float
shard_root=/srv/openpairing-validation/campaigns/accel/shards
source_tree=/srv/openpairing-validation/releases/accel-epoch5-virtual-float
upstream=/srv/chesspairings-engine-staging/releases/production-engines-20260810/gacrux
campaign=/srv/openpairing-validation/campaigns/accel-epoch5-virtual-float
shards=64
workers=20
expected_files=1000
expected_source_tree_sha256=a42e9e60024ce2526e247544f36d39a657163e56e814e9b76c7d34593a48e884
expected_harness_sha256=1c8013f591a81e5d57393340466a4c2cec1ddac6ac87b027b6dcdbc9e58d829b
expected_campaign_commit=${OPENPAIRING_CAMPAIGN_COMMIT:?Set the immutable campaign commit}
stop_file="$campaign/STOP"

assert_campaign_identity() {
    php -r '
        [$runtimePath, $campaignPath, $expectedSource, $expectedHarness, $expectedCommit, $verifier, $compatibility] = array_slice($argv, 1);
        $runtime = json_decode(file_get_contents($runtimePath), true, flags: JSON_THROW_ON_ERROR);
        $campaignIdentity = json_decode(file_get_contents($campaignPath), true, flags: JSON_THROW_ON_ERROR);
        $files = [basename($verifier) => hash_file("sha256", $verifier), basename($compatibility) => hash_file("sha256", $compatibility)];
        ksort($files, SORT_STRING);
        $context = hash_init("sha256");
        foreach ($files as $fileName => $hash) {
            hash_update($context, $fileName."\0".$hash."\n");
        }
        $observed = [
            "source" => strtolower((string) ($runtime["sourceTreeSha256"] ?? "")),
            "harness" => hash_final($context),
            "commit" => strtolower((string) ($campaignIdentity["gitCommit"] ?? "")),
            "campaignSource" => strtolower((string) ($campaignIdentity["sourceTreeSha256"] ?? "")),
            "campaignHarness" => strtolower((string) ($campaignIdentity["harnessSha256"] ?? "")),
        ];
        $expected = ["source" => $expectedSource, "harness" => $expectedHarness, "commit" => $expectedCommit];
        if (!hash_equals($expected["source"], $observed["source"])
            || !hash_equals($expected["harness"], $observed["harness"])
            || !hash_equals($expected["commit"], $observed["commit"])
            || !hash_equals($expected["source"], $observed["campaignSource"])
            || !hash_equals($expected["harness"], $observed["campaignHarness"])) {
            fwrite(STDERR, "Campaign identity mismatch: expected ".json_encode($expected).", observed ".json_encode($observed).".\n");
            exit(4);
        }
    ' "$source_tree/runtime-identity.lock.json" "$source_tree/campaign-identity.lock.json" \
        "$expected_source_tree_sha256" "$expected_harness_sha256" "$expected_campaign_commit" \
        "$source_tree/tools/verify_trf_pairings.php" "$source_tree/tools/TrfCorpusCompatibility.php"
}

test ! -e "$stop_file" || { echo "Campaign is stopped: $stop_file" >&2; exit 30; }
assert_campaign_identity
mkdir -p -- "$campaign/reports" "$campaign/classifications" "$campaign/quarantine"
campaign_runner_sha256=$(sha256sum "$0" | awk '{print $1}')

run_shard() {
    shard=$1
    test ! -e "$stop_file" || return 30
    assert_campaign_identity || return 34
    label=$(printf 's%03d' "$shard")
    corpus="$shard_root/$label"
    test -d "$corpus" || return 32
    report="$campaign/reports/$label.json"
    classification="$campaign/classifications/$label.json"
    if [[ -s "$report" && -s "$classification" ]]; then
        return 0
    fi
    temporary_report=$(mktemp "$campaign/quarantine/.$label.report.XXXXXX")
    temporary_classification=$(mktemp "$campaign/quarantine/.$label.classification.XXXXXX")
    cleanup_shard() { rm -f -- "$temporary_report" "$temporary_classification"; }
    trap cleanup_shard RETURN
    if ! OPENPAIRING_BIGINT_BACKEND=gmp OPENPAIRING_ARITHMETIC_BACKEND=gmp \
        php -d opcache.enable_cli=1 -d opcache.jit=1255 -d opcache.jit_buffer_size=128M \
        "$source_tree/tools/verify_trf_pairings.php" "$corpus" 0 --large-field >"$temporary_report"; then
        temporary_stop=$(mktemp "$campaign/.stop.XXXXXX")
        printf 'OPENPAIRING_SHARD_STOP=FAIL reason=verifier-infrastructure-error shard=%s at=%s\n' \
            "$label" "$(date -Is)" >"$temporary_stop"
        mv -f -- "$temporary_stop" "$stop_file"
        return 33
    fi
    if ! php -r '
        [$reportPath, $expectedSource, $expectedHarness] = array_slice($argv, 1);
        $report = json_decode(file_get_contents($reportPath), true, flags: JSON_THROW_ON_ERROR);
        $runtime = $report["runtimeIdentity"] ?? [];
        $harness = $report["harnessIdentity"] ?? [];
        if (($report["productionOnly"] ?? false) !== true
            || ($report["largeFieldAudit"] ?? false) !== true
            || !hash_equals($expectedSource, strtolower((string) ($runtime["sourceTreeSha256"] ?? "")))
            || !hash_equals($expectedHarness, strtolower((string) ($harness["sha256"] ?? "")))
            || ($runtime["arithmeticBackend"] ?? "") !== "gmp") {
            fwrite(STDERR, "Shard report identity/backend mismatch.\n");
            exit(4);
        }
    ' "$temporary_report" "$expected_source_tree_sha256" "$expected_harness_sha256"; then
        temporary_stop=$(mktemp "$campaign/.stop.XXXXXX")
        printf 'OPENPAIRING_SHARD_STOP=FAIL reason=report-identity-backend-mismatch shard=%s at=%s\n' \
            "$label" "$(date -Is)" >"$temporary_stop"
        mv -f -- "$temporary_stop" "$stop_file"
        return 37
    fi
    "$source_tree/tools/corpus/classify_shard_fail_fast.sh" \
        "$temporary_report" "$source_tree" "$upstream" "$temporary_classification" "$stop_file" "$corpus" || {
            mv -f -- "$temporary_report" "$campaign/quarantine/$label.json"
            if test -s "$temporary_classification"; then
                mv -f -- "$temporary_classification" "$campaign/quarantine/$label-classification.json"
            fi
            trap - RETURN
            return 31
        }
    mv -f -- "$temporary_report" "$report"
    php -r '
        $classification = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
        $classification["sourceReport"] = realpath($argv[2]);
        $classification["shardGateSha256"] = hash_file("sha256", $argv[3]);
        $classification["campaignRunnerSha256"] = $argv[4];
        echo json_encode($classification, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    ' "$temporary_classification" "$report" \
        "$source_tree/tools/corpus/classify_shard_fail_fast.sh" \
        "$campaign_runner_sha256" >"$temporary_classification.final"
    mv -f -- "$temporary_classification.final" "$classification"
    rm -f -- "$temporary_classification"
    trap - RETURN
}
export -f run_shard
export -f assert_campaign_identity
export shard_root source_tree upstream campaign shards stop_file expected_source_tree_sha256 expected_harness_sha256 expected_campaign_commit campaign_runner_sha256
seq 0 $((shards - 1)) | xargs -r -P "$workers" -n 1 bash -c 'run_shard "$1"' _
test ! -e "$stop_file" || exit 30
test "$(find "$campaign/reports" -maxdepth 1 -name 's*.json' -size +0c | wc -l)" -eq "$shards"
php "/srv/openpairing-validation/bin/aggregate_shards.php" "$campaign/reports" "$shards" \
    "$expected_files" >"$campaign/aggregate.json.tmp"
mv -f -- "$campaign/aggregate.json.tmp" "$campaign/aggregate.json"
printf 'OPENPAIRING_LARGE_FIELD_CAMPAIGN=PASS name=%s shards=%d at=%s\n' "$name" "$shards" "$(date -Is)"
