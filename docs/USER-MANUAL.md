# OpenPairingPHP user manual

Version covered: 0.9.0-rc7. Language: English.

OpenPairingPHP is an offline individual pairing and standings engine. It is
not a complete Tournament Handler Program and is not FIDE approved. The
arbiter remains responsible for reviewing and publishing every pairing.

## Installation

The ready-to-run archive requires 64-bit PHP 8.3 or later. Enable `ext-gmp`
for medium and large fields; the pure-PHP exact fallback is functionally
equivalent but slower.

1. Verify the archive against its adjacent `.sha256` file.
2. Extract `openpairing-php-0.9.0-rc7-runtime.zip` into a new directory.
3. From that directory run `php bin/openpairing.php --version`.
4. Run the positive and negative checks in `docs/PTC.md` before tournament
   use.

The package is stateless. It does not install a service, use a database or
make network requests. Keep the input TRF/JSON and output JSON together as the
recoverable tournament record.

## Pair the next round

TRF26 input must identify an individual Dutch or Burstein tournament in
record `192`. A completed report may omit conditional records `142` and `152`
only when their values can be inferred unambiguously. Unsupported records or
inconsistent scores/results fail closed.

```console
php bin/openpairing.php tournament.trf > pairing.json
```

Canonical JSON is also accepted:

```console
php bin/openpairing.php --input-format=json tournament.json > pairing.json
```

For Baku acceleration, prefer an imported TRF26 record `250`. To calculate a
schedule explicitly instead:

```console
php bin/openpairing.php --baku tournament.trf > pairing.json
```

Never publish output when the command exits non-zero, reports a violation or
lists an unpaired player. Pairing output identifies White and Black by
canonical player ID and includes the selected PAB, diagnostics, exact quality
vector, trace and algorithm version.

## Calculate standings

The input must contain record `202` or `212`, or the tie-break list must be
provided explicitly:

```console
php bin/openpairing.php --standings tournament.trf > standings.json
php bin/openpairing.php --standings --tie-breaks=DE/P,BH/C1/P,SB/C1 tournament.trf > standings.json
```

The result contains competition ranks, unresolved tied groups and exact
rational values. Rating-based methods reject unrated-player inputs instead of
inventing a local policy.

## Pairing Test Checker

The PTC replays every populated round and, when declared, the final standings:

```console
php bin/openpairing-ptc.php completed.trf > ptc-report.json
```

Exit `0` means every observed case is exact. Exit `1` means a completed check
found a discrepancy. The distributed colour-variant fixture must exit `1`;
this proves that the checker does not pass every input. See `docs/PTC.md` and
`docs/schema/ptc-report-v1.schema.json`.

## Random Tournament Generator

Generate deterministic acceptance inputs and immediately check them:

```console
php bin/openpairing-rtg.php --players=80 --rounds=9 --seed=20260815 > generated.trf
php bin/openpairing-ptc.php generated.trf > generated-report.json
```

Use `--system=burstein`, `--top-colour=B`, `--draw-rate=<0..100>` and `--baku`
only when those parameters are part of the intended test. The same options and
seed must produce byte-identical TRF output. See `docs/RTG.md`.

## Exit codes and incident handling

- `0`: successful, complete and internally validated output;
- `1`: a checker discrepancy, no-solution, resource limit or validation
  violation;
- `2`: infrastructure/internal failure;
- `3`: invalid, oversized, unsupported or ambiguous input.

On any non-zero exit, preserve input, standard output, standard error, package
version and SHA-256. Do not edit the failed input in place. Correct the source
tournament in a copy, rerun, and retain both artifacts for audit.

## Declared scope

The package supports individual Dutch and Burstein pairings, individual
standings/tie-breaks and the documented TRF26 subset. Round Robin is accepted
for standings only. Team tournaments, team tie-breaks, national-rating records
and the unsupported TRF26 records listed in `docs/TRF-2026.md` are outside
this release. Exact limits and open corpus gates are in `README.md`,
`docs/RELEASE.md` and `docs/FIDE-SUBMISSION-READINESS.md`.

