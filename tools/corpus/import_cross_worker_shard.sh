#!/usr/bin/env bash

set -Eeuo pipefail

report=${1:?Usage: import_cross_worker_shard.sh REPORT ATTESTATION SHARD CORPUS_ROOT SOURCE_TREE UPSTREAM CAMPAIGN EXPECTED_HOST EXPECTED_SOURCE_SHA256 EXPECTED_HARNESS_SHA256 EXPECTED_COMMIT}
attestation=${2:?Missing provenance attestation}
shard=${3:?Missing shard label}
corpus_root=${4:?Missing primary corpus root}
source_tree=${5:?Missing immutable source tree}
upstream=${6:?Missing Gacrux checkout}
campaign=${7:?Missing primary campaign root}
expected_host=${8:?Missing expected source host}
expected_source=${9:?Missing expected source-tree SHA-256}
expected_harness=${10:?Missing expected harness SHA-256}
expected_commit=${11:?Missing expected campaign commit}
importer_dir=$(cd -- "$(dirname -- "$0")" && pwd -P)
validator="$importer_dir/validate_cross_worker_import.php"

[[ "$shard" =~ ^s[0-9]{3}$ ]] || { echo "Unsafe shard label: $shard" >&2; exit 2; }
case "$campaign" in /srv/openpairing-validation/campaigns/*) ;; *) echo "Unsafe campaign root: $campaign" >&2; exit 2 ;; esac
test -s "$report"
test -s "$attestation"
test -d "$corpus_root"
test -x "$validator"
test -x "$source_tree/tools/corpus/classify_shard_fail_fast.sh"
test -x "$upstream/venv/bin/python"

stop_file="$campaign/STOP"
final_report="$campaign/reports/$shard.json"
final_classification="$campaign/classifications/$shard.json"
final_attestation="$campaign/provenance/$shard.json"
final_validation="$campaign/validations/$shard.json"
import_root="$campaign/quarantine/imports"
mkdir -p -- "$campaign/reports" "$campaign/classifications" "$campaign/provenance" \
    "$campaign/validations" "$import_root"

exec 9>"$import_root/.$shard.lock"
flock -n 9 || { echo "Cross-worker import already active: $shard" >&2; exit 8; }
test ! -e "$stop_file" || { echo "Primary campaign is stopped: $stop_file" >&2; exit 30; }
if [[ -s "$final_report" && -s "$final_classification" ]]; then
    echo "Primary shard already accepted: $shard" >&2
    exit 10
fi
if [[ -e "$final_report" || -e "$final_classification" ]]; then
    echo "Primary shard has an incomplete final pair: $shard" >&2
    exit 11
fi
if pgrep -af "verify_trf_pairings.php.*$corpus_root" >/dev/null; then
    echo "Primary verifier is already computing $shard; import refused." >&2
    exit 12
fi

temporary=$(mktemp -d "$import_root/.$shard.XXXXXX")
cleanup() { rm -rf -- "$temporary"; }
trap cleanup EXIT
temporary_report="$temporary/report.json"
temporary_attestation="$temporary/attestation.json"
temporary_validation="$temporary/validation.json"
temporary_classification="$temporary/classification.json"
install -m 0644 -- "$report" "$temporary_report"
install -m 0644 -- "$attestation" "$temporary_attestation"

if ! OPENPAIRING_IMPORT_AUTOLOAD="$source_tree/vendor/autoload.php" "$validator" \
    "$temporary_report" "$temporary_attestation" "$corpus_root" "$shard" \
    "$expected_host" "$expected_source" "$expected_harness" "$expected_commit" \
    >"$temporary_validation"; then
    echo "Cross-worker integrity validation failed without stopping the primary campaign: $shard" >&2
    exit 13
fi

if ! "$source_tree/tools/corpus/classify_shard_fail_fast.sh" \
    "$temporary_report" "$source_tree" "$upstream" "$temporary_classification" \
    "$stop_file" "$corpus_root"; then
    rejected="$import_root/rejected-$shard-$(date +%Y%m%dT%H%M%S)-$$"
    mv -- "$temporary" "$rejected"
    trap - EXIT
    echo "Cross-worker shard failed the primary semantic gate and was preserved: $rejected" >&2
    exit 14
fi

test ! -e "$stop_file" || { echo "Primary campaign stopped during import: $stop_file" >&2; exit 30; }
if [[ -e "$final_report" || -e "$final_classification" ]]; then
    echo "Primary shard appeared during classification; import refused: $shard" >&2
    exit 15
fi
if pgrep -af "verify_trf_pairings.php.*$corpus_root" >/dev/null; then
    echo "Primary verifier started $shard during classification; import refused." >&2
    exit 16
fi

temporary_final_classification=$(mktemp "$campaign/classifications/.$shard.XXXXXX")
temporary_final_attestation=$(mktemp "$campaign/provenance/.$shard.XXXXXX")
temporary_final_validation=$(mktemp "$campaign/validations/.$shard.XXXXXX")
cleanup_final() {
    rm -f -- "$temporary_final_classification" "$temporary_final_attestation" "$temporary_final_validation"
}
trap 'cleanup_final; cleanup' EXIT
install -m 0644 -- "$temporary_attestation" "$temporary_final_attestation"
install -m 0644 -- "$temporary_validation" "$temporary_final_validation"
php -r '
    [$classificationPath, $finalReport, $finalAttestation, $finalValidation, $gate, $importer] = array_slice($argv, 1);
    $classification = json_decode(file_get_contents($classificationPath), true, flags: JSON_THROW_ON_ERROR);
    $validation = json_decode(file_get_contents($finalValidation), true, flags: JSON_THROW_ON_ERROR);
    $classification["sourceReport"] = $finalReport;
    $classification["shardGateSha256"] = hash_file("sha256", $gate);
    $classification["crossWorkerImport"] = [
        "schema" => "openpairing.cross-worker-import/1",
        "acceptedAt" => date(DATE_ATOM),
        "sourceHost" => $validation["sourceHost"],
        "reportSha256" => $validation["reportSha256"],
        "attestationSha256" => hash_file("sha256", $finalAttestation),
        "validationSha256" => hash_file("sha256", $finalValidation),
        "canonicalSemanticSha256" => $validation["canonicalSemanticSha256"],
        "importerSha256" => hash_file("sha256", $importer),
        "primaryAcceptor" => gethostname(),
    ];
    echo json_encode($classification, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
' "$temporary_classification" "$final_report" "$final_attestation" "$final_validation" \
    "$source_tree/tools/corpus/classify_shard_fail_fast.sh" "$0" \
    >"$temporary_final_classification"

mv -- "$temporary_final_attestation" "$final_attestation"
mv -- "$temporary_final_validation" "$final_validation"
mv -- "$temporary_report" "$final_report"
mv -- "$temporary_final_classification" "$final_classification"
rm -rf -- "$temporary"
trap - EXIT
printf 'OPENPAIRING_CROSS_WORKER_IMPORT=PASS shard=%s report=%s classification=%s at=%s\n' \
    "$shard" "$final_report" "$final_classification" "$(date -Is)"
