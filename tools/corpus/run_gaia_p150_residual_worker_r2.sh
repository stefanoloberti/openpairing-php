#!/usr/bin/env bash

set -Eeuo pipefail

base=/home/gaia/openpairing-worker
source_tree="$base/releases/86ec9d9-r2"
shard_root="$base/campaigns/p150/shards"
campaign="$base/work/p150-epoch4-86ec9d9-gaia-r2"
reports="$campaign/quarantine/reports"
attestations="$campaign/quarantine/attestations"
rejected="$campaign/quarantine/rejected"
stop_file="$campaign/STOP"
first_shard=108
last_shard=111
workers=4
expected_source=a42e9e60024ce2526e247544f36d39a657163e56e814e9b76c7d34593a48e884
expected_harness=a617aa2677cdf3d0ad204caa73b18d6dceceb61b1d2d65ba95f9669a8ab06c4c
expected_commit=86ec9d9c003d68468906bddf12c69fbcaaf750ac
expected_engine=464088b49fec2854eaff5923f777c6c3f05d6256677d0d43efa0c6a509d9c8f2
php_root="$base/php/root"
php_binary="$php_root/usr/bin/php8.3"
php_extension_dir="$php_root/usr/lib/php/20230831"

php_run() {
    "$php_binary" -n \
        -d "extension=$php_extension_dir/gmp.so" \
        -d "zend_extension=$php_extension_dir/opcache.so" \
        "$@"
}

assert_campaign_identity() {
    php_run -r '
        [$runtimePath, $campaignPath, $expectedSource, $expectedHarness, $expectedCommit, $expectedEngine, $verifier, $compatibility] = array_slice($argv, 1);
        $runtime = json_decode(file_get_contents($runtimePath), true, flags: JSON_THROW_ON_ERROR);
        $campaign = json_decode(file_get_contents($campaignPath), true, flags: JSON_THROW_ON_ERROR);
        $files = [basename($verifier) => hash_file("sha256", $verifier), basename($compatibility) => hash_file("sha256", $compatibility)];
        ksort($files, SORT_STRING);
        $context = hash_init("sha256");
        foreach ($files as $name => $hash) {
            hash_update($context, $name."\0".$hash."\n");
        }
        $observedHarness = hash_final($context);
        $enginePath = dirname($runtimePath)."/src/Engine/Dutch/DutchPairingEngine.php";
        if (!is_file($enginePath)) { fwrite(STDERR, "Engine path is unavailable in the immutable release.\n"); exit(4); }
        $observedEngine = hash_file("sha256", $enginePath);
        $observed = [
            "source" => strtolower((string) ($runtime["sourceTreeSha256"] ?? "")),
            "harness" => $observedHarness,
            "commit" => strtolower((string) ($campaign["gitCommit"] ?? "")),
            "campaignSource" => strtolower((string) ($campaign["sourceTreeSha256"] ?? "")),
            "campaignHarness" => strtolower((string) ($campaign["harnessSha256"] ?? "")),
            "engine" => strtolower($observedEngine),
        ];
        $expected = ["source" => $expectedSource, "harness" => $expectedHarness, "commit" => $expectedCommit, "engine" => $expectedEngine];
        if (!hash_equals($expected["source"], $observed["source"])
            || !hash_equals($expected["harness"], $observed["harness"])
            || !hash_equals($expected["commit"], $observed["commit"])
            || !hash_equals($expected["source"], $observed["campaignSource"])
            || !hash_equals($expected["harness"], $observed["campaignHarness"])
            || !hash_equals($expected["engine"], $observed["engine"])) {
            fwrite(STDERR, "Campaign identity mismatch: expected ".json_encode($expected).", observed ".json_encode($observed).".\n");
            exit(4);
        }
    ' "$source_tree/runtime-identity.lock.json" "$source_tree/campaign-identity.lock.json" \
        "$expected_source" "$expected_harness" "$expected_commit" "$expected_engine" \
        "$source_tree/tools/verify_trf_pairings.php" "$source_tree/tools/TrfCorpusCompatibility.php"
}

write_stop() {
    local reason=$1
    local shard=${2:-preflight}
    local temporary
    temporary=$(mktemp "$campaign/.stop.XXXXXX")
    printf 'OPENPAIRING_GAIA_R2_STOP=FAIL reason=%s shard=%s at=%s\n' "$reason" "$shard" "$(date -Is)" >"$temporary"
    mv -f -- "$temporary" "$stop_file"
}

run_shard() {
    local shard=$1
    local label corpus report attestation temporary_report temporary_attestation
    test ! -e "$stop_file" || return 30
    assert_campaign_identity || return 34
    label=$(printf 's%03d' "$shard")
    corpus="$shard_root/$label"
    report="$reports/$label.json"
    attestation="$attestations/$label.json"
    test -d "$corpus" || { write_stop missing-corpus "$label"; return 32; }
    if [[ -s "$report" && -s "$attestation" ]]; then
        return 0
    fi
    if [[ -e "$report" || -e "$attestation" ]]; then
        write_stop incomplete-final-pair "$label"
        return 35
    fi
    temporary_report=$(mktemp "$campaign/quarantine/.$label.report.XXXXXX")
    temporary_attestation=$(mktemp "$campaign/quarantine/.$label.attestation.XXXXXX")
    cleanup_shard() { rm -f -- "$temporary_report" "$temporary_attestation"; }
    trap cleanup_shard RETURN
    if ! OPENPAIRING_BIGINT_BACKEND=gmp OPENPAIRING_ARITHMETIC_BACKEND=gmp \
        php_run -d opcache.enable_cli=1 -d opcache.jit=1255 -d opcache.jit_buffer_size=128M \
        "$source_tree/tools/verify_trf_pairings.php" "$corpus" 0 --large-field >"$temporary_report"; then
        write_stop verifier-infrastructure-error "$label"
        return 33
    fi
    if ! php_run -r '
        [$reportPath, $attestationPath, $label, $expectedSource, $expectedHarness, $expectedCommit, $expectedEngine] = array_slice($argv, 1);
        $report = json_decode(file_get_contents($reportPath), true, flags: JSON_THROW_ON_ERROR);
        $runtime = is_array($report["runtimeIdentity"] ?? null) ? $report["runtimeIdentity"] : [];
        $harness = is_array($report["harnessIdentity"] ?? null) ? $report["harnessIdentity"] : [];
        $corpus = is_array($report["corpusIdentity"] ?? null) ? $report["corpusIdentity"] : [];
        $counts = is_array($report["counts"] ?? null) ? $report["counts"] : [];
        $diagnostics = array_sum(array_map("intval", is_array($report["diagnostics"] ?? null) ? $report["diagnostics"] : []));
        $fatal = (int) ($counts["largeField.candidateNoMatching"] ?? 0)
            + (int) ($counts["roundsEngineNoSolution"] ?? 0)
            + (int) ($counts["roundsEngineResourceLimit"] ?? 0);
        $enginePath = $runtime["loadedEnginePath"] ?? null;
        if (($report["productionOnly"] ?? false) !== true
            || ($report["largeFieldAudit"] ?? false) !== true
            || ($runtime["sourceTreeSha256"] ?? "") !== $expectedSource
            || ($harness["sha256"] ?? "") !== $expectedHarness
            || ($runtime["arithmeticBackend"] ?? "") !== "gmp"
            || !is_string($enginePath) || !is_file($enginePath)
            || !hash_equals($expectedEngine, hash_file("sha256", $enginePath))
            || (int) ($counts["filesSeen"] ?? 0) < 1
            || (int) ($counts["filesSeen"] ?? 0) !== (int) ($counts["decoded"] ?? -1)
            || (int) ($counts["decoded"] ?? 0) !== (int) ($counts["tournamentsVerified"] ?? -1)
            || (int) ($counts["roundsAttempted"] ?? 0) < 1
            || $diagnostics !== 0 || $fatal !== 0) {
            fwrite(STDERR, "Shard report identity, denominator or fatal-counter check failed.\n");
            exit(4);
        }
        unset(
            $report["root"],
            $report["elapsedSeconds"],
            $report["runtimeIdentity"]["projectRoot"],
            $report["runtimeIdentity"]["autoloadPsr4Roots"],
            $report["runtimeIdentity"]["expectedEnginePath"],
            $report["runtimeIdentity"]["loadedEnginePath"],
        );
        $attestation = [
            "schema" => "openpairing.cross-worker-provenance/1",
            "recordedAt" => date(DATE_ATOM),
            "acceptance" => "quarantine-only",
            "primaryAcceptor" => "doppia-3090",
            "shard" => $label,
            "host" => gethostname(),
            "campaignCommit" => $expectedCommit,
            "reportSha256" => hash_file("sha256", $reportPath),
            "canonicalSemanticSha256" => hash("sha256", json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            "runtimeIdentity" => $runtime,
            "harnessIdentity" => $harness,
            "corpusIdentity" => $corpus,
        ];
        file_put_contents($attestationPath, json_encode($attestation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", LOCK_EX);
    ' "$temporary_report" "$temporary_attestation" "$label" "$expected_source" "$expected_harness" "$expected_commit" "$expected_engine"; then
        mkdir -p -- "$rejected/$label"
        mv -- "$temporary_report" "$rejected/$label/report.json"
        test ! -s "$temporary_attestation" || mv -- "$temporary_attestation" "$rejected/$label/attestation.json"
        write_stop report-validation-failed "$label"
        trap - RETURN
        return 37
    fi
    mv -- "$temporary_report" "$report"
    mv -- "$temporary_attestation" "$attestation"
    trap - RETURN
    printf 'OPENPAIRING_GAIA_R2_SHARD=QUARANTINED shard=%s report=%s attestation=%s at=%s\n' \
        "$label" "$report" "$attestation" "$(date -Is)"
}

test -x "$php_binary"
test -s "$php_extension_dir/gmp.so"
test -s "$php_extension_dir/opcache.so"
php_run -r 'exit(PHP_VERSION_ID >= 80300 && extension_loaded("gmp") && extension_loaded("Zend OPcache") ? 0 : 1);'
assert_campaign_identity
test ! -e "$stop_file" || { echo "Campaign is stopped: $stop_file" >&2; exit 30; }
mkdir -p -- "$reports" "$attestations" "$rejected"
for shard in $(seq "$first_shard" "$last_shard"); do
    test -d "$shard_root/$(printf 's%03d' "$shard")" || { write_stop missing-corpus "s$(printf '%03d' "$shard")"; exit 32; }
done
if [[ "${1:-}" == --preflight ]]; then
    printf 'OPENPAIRING_GAIA_R2_PREFLIGHT=PASS first=s%03d last=s%03d workers=%d at=%s\n' \
        "$first_shard" "$last_shard" "$workers" "$(date -Is)"
    exit 0
fi
export -f php_run assert_campaign_identity write_stop run_shard
export base source_tree shard_root campaign reports attestations rejected stop_file php_root php_binary php_extension_dir
export expected_source expected_harness expected_commit expected_engine
seq "$first_shard" "$last_shard" | xargs -r -P "$workers" -n 1 bash -c 'run_shard "$1"' _
test ! -e "$stop_file" || exit 30
test "$(find "$reports" -maxdepth 1 -type f -name 's*.json' -size +0c | wc -l)" -eq $((last_shard - first_shard + 1))
test "$(find "$attestations" -maxdepth 1 -type f -name 's*.json' -size +0c | wc -l)" -eq $((last_shard - first_shard + 1))
printf 'OPENPAIRING_GAIA_R2_PARTITION=QUARANTINED_PASS first=s%03d last=s%03d workers=%d at=%s\n' \
    "$first_shard" "$last_shard" "$workers" "$(date -Is)"
